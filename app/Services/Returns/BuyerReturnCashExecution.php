<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\BuyerAccessService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class BuyerReturnCashExecution
{
    private const OFFERS = ['offer-recovery', 'offer-compensation', 'offer-funding'];

    private const RECEIPTS = ['receive-recovery', 'receive-compensation', 'receive-funding'];

    public function __construct(private readonly BuyerReturnAmounts $amounts) {}

    private function companyPayer(User $actor, BuyerReturnCase $case): bool
    {
        return $actor->isLogistics() && $actor->isKycApproved() && $actor->email_verified_at
            && LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->where('user_id', $actor->id)->exists();
    }

    private function party(User $actor, BuyerReturnCase $case): User
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        if (! $this->companyPayer($actor, $case)) {
            app(BuyerReturnService::class)->owned($actor, $case);
        }

        return $actor;
    }

    public function plan(BuyerReturnCase $case): BuyerReturnRepaymentPlan
    {
        abort_unless(Schema::hasTable('buyer_return_repayment_plans') && $case->order->payment_method === 'cod', 409,
            'This cash workflow requires the approved original COD repayment plan.');
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->firstOrFail();
        $this->amounts->adjustmentFinancials($case->order);
        $basis = $this->amounts->successorForCase($case);
        abort_unless($plan->snapshot['source_snapshot']['units'] === $basis['unit_snapshot']
            && $plan->snapshot['source_snapshot']['shipping_units'] === $basis['shipping_unit_snapshot']
            && $plan->snapshot['buyer_refund_cents'] === $basis['buyer_cents'] + $basis['buyer_paid_shipping_cents'], 409,
            'The approved original units or paid-shipping allocation changed. Review the retained evidence.');

        return $plan;
    }

    public function funding(BuyerReturnCase $case): array
    {
        $plan = $this->plan($case)->snapshot;
        $events = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->get();
        $result = [];
        foreach (['recovery' => 'seller_recovery_due_cents', 'compensation' => 'company_funding_due_cents'] as $kind => $field) {
            $result[$kind.'_due_cents'] = $plan[$field];
            $result[$kind.'_offered_cents'] = (int) $events->where('event_type', 'offer-'.$kind)->sum('amount_cents');
            $result[$kind.'_received_cents'] = (int) $events->where('event_type', 'receive-'.$kind)->sum('amount_cents');
            $result[$kind.'_remaining_cents'] = $plan[$field] - $result[$kind.'_received_cents'];
        }
        $result['advance_received_cents'] = (int) $events->where('event_type', 'receive-funding')->sum('amount_cents');
        $required = $plan['fault'] === 'seller' ? 'recovery' : 'compensation';
        $result['required_backing_cents'] = $result[$required.'_due_cents'];
        $result['received_backing_cents'] = $result[$required.'_received_cents'] + $result['advance_received_cents'];

        return $result;
    }

    public function backing(Order $order): array
    {
        $source = $this->amounts->successorOriginal($order);
        $cash = app(BuyerReturnMoneyService::class)->cashEvidence($order);
        $payment = app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement);
        $basis = $this->amounts->sellerBasis($order, $source['original_seller_cents']);
        $events = BuyerReturnMoneyEvent::where('order_id', $order->id)->get();
        $incoming = (int) $events->whereIn('event_type', self::RECEIPTS)->sum('amount_cents');
        $outgoing = (int) $events->where('event_type', 'record-repayment')->sum('amount_cents');
        $unpaid = $payment ? 0 : $basis['payable_seller_cents'];

        return $cash + ['seller_paid_cents' => $payment?->amount_cents ?? 0, 'protected_seller_cents' => $unpaid,
            'protected_shipping_cents' => $source['shipping_cents'], 'received_cents' => $incoming, 'outgoing_cents' => $outgoing,
            'available_cents' => $cash['reconciled_cents'] + $incoming - ($payment?->amount_cents ?? 0) - $unpaid - $source['shipping_cents'] - $outgoing];
    }

    public function actions(User $actor, BuyerReturnCase $case): array
    {
        $actor = $this->party($actor, $case);
        abort_unless($case->order->payment_method === 'cod' && ($case->order->shipping_quote['refund_policy_version'] ?? 1) === 2, 409);
        $approved = Schema::hasTable('buyer_return_repayment_plans') && BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->exists();
        $actions = [];
        if ($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at) {
            if (! $approved && in_array($case->status, ['seller_received', 'condition_review'], true)) {
                return ['approve-repayment'];
            }
            if ($approved) {
                $actions = self::RECEIPTS;
                if ($case->status === 'repayment_ready') {
                    array_push($actions, ...($this->plan($case)->snapshot['buyer_refund_cents'] === 0
                        ? ['close-zero-return'] : ['offer-funding', 'record-repayment']));
                }
                if ($case->status === 'repayment_disputed') {
                    $actions[] = 'resolve-nonreceipt';
                }
            }
        }
        if ($approved) {
            $funding = $this->funding($case);
            if ($actor->isSeller() && $actor->id === $case->seller_id && $actor->isKycApproved() && $actor->email_verified_at
                && $funding['recovery_due_cents'] > $funding['recovery_offered_cents']) {
                $actions[] = 'offer-recovery';
            }
            if ($this->companyPayer($actor, $case) && $funding['compensation_due_cents'] > $funding['compensation_offered_cents']) {
                $actions[] = 'offer-compensation';
            }
        }
        if ($actor->isBuyer() && $actor->id === $case->buyer_id && app(BuyerAccessService::class)->canAccessExistingOrders($actor)) {
            if (in_array($case->status, ['payment_recorded', 'repayment_disputed'], true)) {
                $actions[] = 'confirm-receipt';
            }
            if ($case->status === 'payment_recorded') {
                $actions[] = 'report-nonreceipt';
            }
        }

        return $actions;
    }

    public function command(User $actor, BuyerReturnCase $snapshot, string $action, array $input, ?UploadedFile $proof = null): BuyerReturnMoneyEvent
    {
        abort_unless(Order::whereKey($snapshot->order_id)->where('payment_method', 'cod')->exists(), 409,
            'Use the original virtual-payment workflow for Wallet orders.');
        if ($action === 'approve-repayment') {
            abort_if($proof, 422, 'Plan approval does not record a cash transfer.');

            return app(BuyerReturnPlanApproval::class)->approve($actor, $snapshot, $input)->approval;
        }
        abort_unless(in_array($action, [...self::OFFERS, ...self::RECEIPTS, 'record-repayment', 'confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt', 'close-zero-return'], true), 404);
        $offer = in_array($action, self::OFFERS, true);
        $receipt = in_array($action, self::RECEIPTS, true);
        $withSource = $receipt || in_array($action, ['confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt'], true);
        $needsProof = $offer || $receipt || in_array($action, ['record-repayment', 'resolve-nonreceipt'], true);
        foreach (['reason', 'cash_reference'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        if (isset($input['cash_reference'])) {
            $input['cash_reference'] = Str::upper($input['cash_reference']);
        }
        $data = Validator::make(array_replace($input, ['proof' => $proof]), ['request_token' => ['required', 'uuid'],
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'proof' => [$needsProof ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120'],
            'amount' => [$offer ? 'required' : 'prohibited', 'string', 'regex:'.CodMoney::RULE],
            'cash_reference' => [$offer || $action === 'record-repayment' ? 'required' : 'prohibited', 'string', 'min:3', 'max:120', 'regex:/\A[A-Z0-9][A-Z0-9 ._:\/\-]*\z/'],
            'source_event_id' => [$withSource ? 'required' : 'prohibited', new AsciiPositiveInteger, 'integer'],
            'recipient_id' => [$offer ? 'required' : 'prohibited', new AsciiPositiveInteger, 'integer'],
            'payment_confirmed' => $needsProof ? ['required', 'accepted'] : ['prohibited'],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'order_id' => ['prohibited'], 'status' => ['prohibited'],
            'amount_cents' => ['prohibited'], 'buyer_cents' => ['prohibited'], 'created_at' => ['prohibited']])->validate();
        unset($data['proof']);
        foreach (['expected_version', 'recipient_id', 'source_event_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        $data['request_token'] = strtolower($data['request_token']);
        if ($needsProof) {
            $data['payment_confirmed'] = true;
        }
        $files = app(BuyerReturnMoneyFiles::class);
        $info = $proof ? $files->inspect($proof) : null;
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$snapshot->id, $actor->id, $action, $data, $info['proof_hash'] ?? null], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $snapshot, $action, $data, $proof, $info, $token, $hash, $offer, $receipt, $withSource, $files, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', array_filter([$actor->id, $snapshot->buyer_id, $snapshot->seller_id, $data['recipient_id'] ?? null]))->orderBy('id')->lockForUpdate()->get();
                CodAccount::where('order_id', $order->id)->lockForUpdate()->first();
                SellerSettlement::where('order_id', $order->id)->lockForUpdate()->first();
                $case = BuyerReturnCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
                $case->load('order', 'items', 'parcel');
                LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->lockForUpdate()->first();
                $actor = $this->party($actor, $case);
                if ($prior = BuyerReturnMoneyEvent::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $hash), 409,
                        'This request already saved different transfer evidence.');

                    return $prior;
                }
                abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists(), 409, 'This request belongs to another return action.');
                abort_unless($case->version === $data['expected_version'] && in_array($action, $this->actions($actor, $case), true), 409,
                    'This return changed or the action is unavailable. Review its actual current record.');
                $approved = $this->plan($case);
                $plan = $approved->snapshot;
                $source = $withSource ? BuyerReturnMoneyEvent::whereKey($data['source_event_id'])->where('buyer_return_case_id', $case->id)->where('order_id', $order->id)->firstOrFail() : null;
                $amount = 0;
                $recipient = $case->buyer_id;
                $context = ['repayment_plan_reference' => $approved->reference];
                if ($offer) {
                    $receiver = User::findOrFail($data['recipient_id']);
                    abort_unless($receiver->isAdmin() && $receiver->canAccessPortal() && $receiver->email_verified_at && $receiver->id !== $actor->id, 403,
                        'Name the distinct current admin who actually received this transfer.');
                    $amount = CodMoney::cents($data['amount']);
                    $recipient = $receiver->id;
                    $funding = $this->funding($case);
                    $maximum = match ($action) {
                        'offer-recovery' => $funding['recovery_due_cents'] - $funding['recovery_offered_cents'],
                        'offer-compensation' => $funding['compensation_due_cents'] - $funding['compensation_offered_cents'],
                        default => CodMoney::cents($order->total_amount) + CodMoney::cents($order->voucher_discount)
                            - BuyerReturnMoneyEvent::where('order_id', $order->id)->where('event_type', 'offer-funding')->sum('amount_cents'),
                    };
                    abort_unless($amount > 0 && $amount <= $maximum, 422, 'Offer only a positive actual transfer within its remaining original obligation.');
                    abort_if(BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists(), 409, 'Retain the original unique actual transfer reference.');
                    if ($action === 'offer-compensation') {
                        $context['source_company_id'] = $plan['liable_company_id'];
                    }
                } elseif ($receipt) {
                    $expected = str_replace('receive-', 'offer-', $action);
                    abort_unless($source->event_type === $expected && $source->recipient_id === $actor->id && $source->actor_id !== $actor->id
                        && ! BuyerReturnMoneyEvent::where('consumed_source_id', $source->id)->exists()
                        && ($source->context['repayment_plan_reference'] ?? null) === $approved->reference, 409,
                        'Confirm only the actual named incoming original offer once.');
                    abort_if($action === 'receive-recovery' && $source->actor_id !== $case->seller_id, 409);
                    abort_if($action === 'receive-compensation' && ($source->actor_role !== 'logistics'
                        || ($source->context['source_company_id'] ?? null) !== $plan['liable_company_id']), 409);
                    $amount = $source->amount_cents;
                    $recipient = $actor->id;
                    $context += ['source_reference' => $source->cash_reference, 'received_from_id' => $source->actor_id];
                } elseif ($action === 'record-repayment') {
                    $backing = $this->backing($order->fresh());
                    $funding = $this->funding($case);
                    $amount = $plan['buyer_refund_cents'];
                    abort_unless($amount > 0 && $backing['verified'] && $backing['available_cents'] >= $amount
                        && $funding['received_backing_cents'] >= $funding['required_backing_cents'], 409,
                        'Reconcile original COD and confirm actual liable-party or advance backing while protecting seller proceeds and earned shipping. Offers and debts are not money received.');
                    abort_if(BuyerReturnMoneyEvent::where('repayment_case_id', $case->id)->exists()
                        || BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists(), 409, 'Retain the original repayment; do not pay or reuse a transfer reference twice.');
                    $context += ['backing' => $backing, 'funding' => $funding, 'original_buyer_id' => $case->buyer_id];
                } elseif ($action === 'close-zero-return') {
                    abort_unless($plan['buyer_refund_cents'] === 0, 409, 'Only a genuinely zero buyer repayment can close without an actual transfer.');
                    $context['no_cash_transfer'] = true;
                } else {
                    abort_unless($source->event_type === 'record-repayment' && $source->recipient_id === $case->buyer_id
                        && $source->amount_cents === $plan['buyer_refund_cents'], 409, 'Select this case’s original actual buyer repayment.');
                    $context += ['payment_reference' => $source->cash_reference, 'original_buyer_id' => $case->buyer_id, 'no_second_payment' => true];
                }
                if ($proof) {
                    $paths[] = $path = $files->store($proof, $info);
                }
                $before = $case->status;
                $case->update(['status' => match ($action) {
                    'record-repayment', 'resolve-nonreceipt' => 'payment_recorded', 'confirm-receipt' => 'repaid',
                    'report-nonreceipt' => 'repayment_disputed', 'close-zero-return' => 'closed_no_repayment', default => $case->status,
                }, 'version' => $case->version + 1]);
                $event = BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()),
                    'buyer_return_case_id' => $case->id, 'order_id' => $order->id, 'actor_id' => $actor->id, 'actor_role' => $actor->role,
                    'event_type' => $action, 'sequence' => $case->version, 'amount_cents' => $amount, 'recipient_id' => $recipient,
                    'source_event_id' => $source?->id, 'consumed_source_id' => $receipt ? $source->id : null,
                    'repayment_case_id' => $action === 'record-repayment' ? $case->id : null, 'cash_reference' => $data['cash_reference'] ?? null,
                    'reason' => $data['reason'], 'proof_path' => $path ?? null, 'proof_hash' => $info['proof_hash'] ?? null,
                    'proof_mime' => $info['proof_mime'] ?? null, 'proof_bytes' => $info['proof_bytes'] ?? null,
                    'request_token_hash' => $token, 'request_fingerprint' => $hash, 'context' => $context, 'created_at' => now()]);
                app(BuyerReturnService::class)->append($case, $actor, 'money-'.$action, $before, $data['reason'], $token, $hash,
                    ['money_event_id' => $event->id, 'amount_cents' => $amount, 'recipient_id' => $recipient, 'repayment_plan_reference' => $approved->reference]);
                if ($offer) {
                    app(NotificationDeliveryService::class)->record($recipient, 'buyer-return', 'buyer-return-money:'.$event->id,
                        ['title' => 'A return transfer needs your receipt', 'body' => 'Check the actual original transfer for '.$case->reference.' before confirming it.',
                            'order_id' => $case->order_id, 'return_id' => $case->id, 'target' => 'buyer-return']);
                }

                return $event;
            }, 3);
            $files->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            $files->discardUnused($paths);
            throw $error;
        }
    }
}
