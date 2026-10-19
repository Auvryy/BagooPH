<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnAdjustment;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnParcel;
use App\Models\CodAccount;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\BuyerAccessService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class BuyerReturnMoneyService
{
    public const ACTIONS = ['approve-repayment', 'offer-recovery', 'receive-recovery', 'offer-funding', 'receive-funding',
        'record-repayment', 'confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt', 'close-zero-return'];

    public function __construct(private readonly BuyerReturnAmounts $amounts) {}

    public function cashEvidence(Order $order): array
    {
        $cash = $order->codAccount;
        $event = $cash?->events()->latest('sequence')->first();
        $state = $event?->target_state ?? [];
        $collected = $cash?->events()->whereIn('event_type', ['rider_collection', 'counter_collection'])->first();
        $valid = $order->payment_method === 'cod' && $order->payment_status === 'paid' && $cash && $cash->reconciled_at
            && $cash->delivery_id === $order->delivery?->id && $cash->product_subtotal_cents === CodMoney::cents($order->subtotal)
            && $cash->shipping_cents === CodMoney::cents($order->shipping_fee) && $cash->discount_cents === CodMoney::cents($order->voucher_discount)
            && $cash->expected_cents === CodMoney::cents($order->total_amount) && $collected?->amount_cents === $cash->expected_cents
            && $event?->event_type === 'platform_reconciled' && ($state['reconciled_reference'] ?? null) === $event->reference
            && ($state['reconciled_cents'] ?? null) === $cash->expected_cents && ($state['collected_cents'] ?? null) === $cash->expected_cents
            && array_key_exists('pending', $state) && ! $state['pending'] && ! collect($state['discrepancies'] ?? [])->contains(fn ($d) => ! $d['resolved_by'])
            && array_sum($state['excess'] ?? []) === 0 && app(CodCashService::class)->atStage($state, 'platform') === $cash->expected_cents
            && app(CodCashService::class)->atStage($state, 'rider') === 0 && app(CodCashService::class)->atStage($state, 'hub') === 0;

        return ['verified' => (bool) $valid, 'cod_account_id' => $cash?->id, 'reconciliation_event_id' => $valid ? $event->id : null,
            'reconciled_cents' => $valid ? $cash->expected_cents : 0];
    }

    public function backing(Order $order): array
    {
        $source = $this->amounts->original($order);
        $cash = $this->cashEvidence($order);
        $payment = app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement);
        $basis = $this->amounts->sellerBasis($order, $source['original_seller_cents']);
        $events = BuyerReturnMoneyEvent::where('order_id', $order->id)->get();
        $incoming = $events->whereIn('event_type', ['receive-recovery', 'receive-funding'])->sum('amount_cents');
        $outgoing = $events->where('event_type', 'record-repayment')->sum('amount_cents');
        $unpaid = $payment ? 0 : $basis['payable_seller_cents'];
        $available = $cash['reconciled_cents'] + $incoming - ($payment?->amount_cents ?? 0) - $unpaid - $source['shipping_cents'] - $outgoing;

        return $cash + ['seller_paid_cents' => $payment?->amount_cents ?? 0, 'protected_seller_cents' => $unpaid,
            'protected_shipping_cents' => $source['shipping_cents'], 'received_cents' => $incoming,
            'outgoing_cents' => $outgoing, 'available_cents' => $available];
    }

    public function debt(BuyerReturnCase $case): array
    {
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
        $received = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->where('event_type', 'receive-recovery')->sum('amount_cents');
        $offered = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->where('event_type', 'offer-recovery')->sum('amount_cents');

        return ['recovery_due_cents' => $adjustment?->recovery_due_cents ?? 0, 'recovery_received_cents' => (int) $received,
            'recovery_offered_cents' => (int) $offered, 'recovery_remaining_cents' => ($adjustment?->recovery_due_cents ?? 0) - $received];
    }

    public function actions(User $actor, BuyerReturnCase $case): array
    {
        $actions = [];
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
        if ($actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at) {
            if (! $adjustment && in_array($case->status, ['seller_received', 'condition_review'], true)) {
                $actions[] = 'approve-repayment';
            }
            if ($adjustment) {
                array_push($actions, 'receive-recovery', 'receive-funding');
                if ($case->status === 'repayment_ready') {
                    array_push($actions, ...($adjustment->buyer_cents === 0 ? ['close-zero-return'] : ['offer-funding', 'record-repayment']));
                }
                if ($case->status === 'repayment_disputed') {
                    $actions[] = 'resolve-nonreceipt';
                }
            }
        }
        if ($actor->isSeller() && $actor->id === $case->seller_id && $actor->isKycApproved() && $actor->email_verified_at
            && $adjustment && $this->debt($case)['recovery_due_cents'] > $this->debt($case)['recovery_offered_cents']) {
            $actions[] = 'offer-recovery';
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

    public function command(User $actor, BuyerReturnCase $case, string $action, array $input, ?UploadedFile $proof = null): BuyerReturnMoneyEvent
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);
        $needsProof = in_array($action, ['offer-recovery', 'receive-recovery', 'offer-funding', 'receive-funding', 'record-repayment', 'resolve-nonreceipt'], true);
        $offer = in_array($action, ['offer-recovery', 'offer-funding'], true);
        $receipt = in_array($action, ['receive-recovery', 'receive-funding'], true);
        $withSource = $receipt || in_array($action, ['confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt'], true);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        if (is_string($input['cash_reference'] ?? null)) {
            $input['cash_reference'] = Str::upper(trim($input['cash_reference']));
        }
        $rules = ['request_token' => ['required', 'uuid'], 'expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'proof' => [$needsProof ? 'required' : 'prohibited', 'nullable', 'file', 'max:5120'],
            'amount' => [$offer ? 'required' : 'prohibited', 'string', 'regex:'.CodMoney::RULE],
            'cash_reference' => [$offer || $action === 'record-repayment' ? 'required' : 'prohibited', 'string', 'min:3', 'max:120', 'regex:/\A[A-Z0-9][A-Z0-9 ._:\/\-]*\z/'],
            'source_event_id' => [$withSource ? 'required' : 'prohibited', new AsciiPositiveInteger, 'integer'],
            'recipient_id' => [$offer ? 'required' : 'prohibited', new AsciiPositiveInteger, 'integer'],
            'payment_confirmed' => $needsProof ? ['required', 'accepted'] : ['prohibited']];
        foreach (['buyer_id', 'seller_id', 'status', 'order_id', 'amount_cents', 'buyer_cents', 'seller_cents', 'platform_cents', 'created_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $data = Validator::make(array_replace($input, ['proof' => $proof]), $rules)->validate();
        unset($data['proof']);
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        if (isset($data['source_event_id'])) {
            $data['source_event_id'] = (int) $data['source_event_id'];
        }
        if (isset($data['recipient_id'])) {
            $data['recipient_id'] = (int) $data['recipient_id'];
        }
        if ($needsProof) {
            $data['payment_confirmed'] = true;
        }
        $info = $proof ? app(BuyerReturnMoneyFiles::class)->inspect($proof) : null;
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$case->id, $actor->id, $action, $data, $info['proof_hash'] ?? null], JSON_THROW_ON_ERROR));
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $case, $action, $data, $proof, $info, $token, $hash, $offer, $receipt, $withSource, &$paths) {
                app(AccountRestrictionService::class)->lockGuard();
                $order = Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
                Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
                User::whereIn('id', array_filter([$actor->id, $case->buyer_id, $case->seller_id, $data['recipient_id'] ?? null]))->orderBy('id')->lockForUpdate()->get();
                CodAccount::where('order_id', $order->id)->lockForUpdate()->first();
                SellerSettlement::where('order_id', $order->id)->lockForUpdate()->first();
                $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
                $actor = app(BuyerReturnService::class)->current($actor);
                app(BuyerReturnService::class)->owned($actor, $case);
                abort_unless($actor->isBuyer() || $actor->isSeller() || $actor->isAdmin(), 403);
                if ($prior = BuyerReturnMoneyEvent::where('request_token_hash', $token)->first()) {
                    abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $hash), 409,
                        'This request already saved different payment details. Reload the return.');

                    return $prior;
                }
                abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists(), 409,
                    'This request belongs to another saved return action. Reload before making a new decision.');
                abort_unless($case->version === $data['expected_version'], 409, 'This return changed. Reload its saved payment history.');
                abort_unless(in_array($action, $this->actions($actor, $case), true), 403, 'This payment action is unavailable for your account and the current return.');
                $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
                $source = $withSource ? BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->whereKey($data['source_event_id'])->firstOrFail() : null;
                $amount = 0;
                $recipient = $case->buyer_id;
                $context = [];
                if ($action === 'approve-repayment') {
                    $parcel = BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
                    app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
                    app(BuyerReturnCustodyService::class)->assertFinishedRoute($case, $parcel, afterSellerReceipt: true);
                    $sellerReceipt = $case->events()->where('event_type', 'return-seller-receive')->latest('id')->first();
                    abort_unless($parcel->status === 'seller_received' && $parcel->custody === ['kind' => 'seller', 'user_id' => $case->seller_id]
                        && $sellerReceipt?->actor_id === $case->seller_id && $sellerReceipt->actor_role === 'seller'
                        && $case->evidence()->where('buyer_return_event_id', $sellerReceipt->id)->where('purpose', 'seller-receive')->exists(), 409,
                        'Actual seller receipt through the complete return route is required.');
                    $basis = $this->amounts->forCase($case);
                    $payment = app(SellerSettlementService::class)->verifiedPayment($order->sellerSettlement);
                    $adjustment = BuyerReturnAdjustment::create($basis + ['buyer_return_case_id' => $case->id, 'order_id' => $order->id,
                        'seller_payment_event_id' => $payment?->id, 'recovery_due_cents' => $payment ? $basis['seller_cents'] : 0,
                        'actor_id' => $actor->id, 'created_at' => now()]);
                    $context = ['adjustment_id' => $adjustment->id, 'seller_receipt_event_id' => $sellerReceipt->id,
                        'condition_review_resolved' => $case->status === 'condition_review'];
                } elseif ($offer) {
                    $amount = CodMoney::cents($data['amount']);
                    $receiver = User::findOrFail($data['recipient_id']);
                    abort_unless($receiver->isAdmin() && $receiver->canAccessPortal() && $receiver->email_verified_at
                        && $receiver->id !== $actor->id, 403, 'Select the distinct current platform admin who actually received this transfer.');
                    $recipient = $receiver->id;
                    if ($action === 'offer-recovery') {
                        $debt = $this->debt($case);
                        abort_unless($amount > 0 && $amount <= $debt['recovery_due_cents'] - $debt['recovery_offered_cents'], 422,
                            'Offer only the remaining original seller recovery amount.');
                    } else {
                        $prior = BuyerReturnMoneyEvent::where('order_id', $order->id)->where('event_type', 'offer-funding')->sum('amount_cents');
                        abort_unless($amount > 0 && $amount + $prior <= CodMoney::cents($order->total_amount) + CodMoney::cents($order->voucher_discount), 422,
                            'Record positive actual funding within this original order’s total obligations.');
                    }
                    abort_if(BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists(), 409, 'That cash reference is already recorded. Use the actual distinct transfer reference.');
                } elseif ($receipt) {
                    $expected = $action === 'receive-recovery' ? 'offer-recovery' : 'offer-funding';
                    abort_unless($source->event_type === $expected && $source->actor_id !== $actor->id && $source->recipient_id === $actor->id
                        && ! BuyerReturnMoneyEvent::where('consumed_source_id', $source->id)->exists(), 409,
                        'A distinct receiving admin must confirm an unreceived original transfer offer.');
                    if ($expected === 'offer-recovery') {
                        abort_unless($source->actor_id === $case->seller_id, 409);
                    }
                    $amount = $source->amount_cents;
                    $recipient = $actor->id;
                    $context = ['source_reference' => $source->cash_reference, 'received_from_id' => $source->actor_id];
                } elseif ($action === 'record-repayment') {
                    $backing = $this->backing($order->fresh());
                    $amount = $adjustment->buyer_cents;
                    abort_unless($backing['verified'] && $backing['available_cents'] >= $amount && $amount > 0, 409,
                        'Repayment needs reconciled original COD and enough actual received money after protecting shipping and remaining seller proceeds. Offers and debts do not count as received cash.');
                    abort_if(BuyerReturnMoneyEvent::where('repayment_case_id', $case->id)->exists()
                        || BuyerReturnMoneyEvent::where('cash_reference', $data['cash_reference'])->exists(), 409,
                        'This repayment or cash reference is already recorded. Review its original evidence instead of paying again.');
                    $context = ['backing' => $backing, 'adjustment_id' => $adjustment->id, 'original_buyer_id' => $case->buyer_id];
                } elseif ($action === 'close-zero-return') {
                    abort_unless($adjustment->buyer_cents === 0, 409, 'Only a genuinely zero merchandise repayment may close without a transfer.');
                    $context = ['adjustment_id' => $adjustment->id, 'no_cash_transfer' => true];
                } else {
                    abort_unless($source->event_type === 'record-repayment' && $source->recipient_id === $case->buyer_id
                        && $source->amount_cents === $adjustment->buyer_cents, 409, 'Select this return’s original recorded buyer repayment.');
                    $context = ['payment_reference' => $source->cash_reference, 'original_buyer_id' => $case->buyer_id];
                }
                if ($proof) {
                    $paths[] = $path = app(BuyerReturnMoneyFiles::class)->store($proof, $info);
                }
                $before = $case->status;
                $case->status = match ($action) {
                    'approve-repayment' => 'repayment_ready', 'record-repayment', 'resolve-nonreceipt' => 'payment_recorded',
                    'confirm-receipt' => 'repaid', 'report-nonreceipt' => 'repayment_disputed', default => $case->status,
                    'close-zero-return' => 'closed_no_repayment',
                };
                $case->version++;
                $case->save();
                $event = BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()),
                    'buyer_return_case_id' => $case->id, 'order_id' => $order->id, 'actor_id' => $actor->id, 'actor_role' => $actor->role,
                    'event_type' => $action, 'sequence' => $case->version, 'amount_cents' => $amount, 'recipient_id' => $recipient,
                    'source_event_id' => $source?->id, 'consumed_source_id' => $receipt ? $source->id : null,
                    'repayment_case_id' => $action === 'record-repayment' ? $case->id : null, 'cash_reference' => $data['cash_reference'] ?? null,
                    'reason' => $data['reason'], 'proof_path' => $path ?? null, 'proof_hash' => $info['proof_hash'] ?? null,
                    'proof_mime' => $info['proof_mime'] ?? null, 'proof_bytes' => $info['proof_bytes'] ?? null,
                    'request_token_hash' => $token, 'request_fingerprint' => $hash, 'context' => $context, 'created_at' => now()]);
                app(BuyerReturnService::class)->append($case, $actor, 'money-'.$action, $before, $data['reason'], $token, $hash,
                    ['money_event_id' => $event->id, 'amount_cents' => $amount, 'recipient_id' => $recipient]);
                if ($offer) {
                    app(NotificationDeliveryService::class)->record($recipient, 'buyer-return', 'buyer-return-money:'.$event->id,
                        ['title' => 'A return transfer needs your receipt', 'body' => 'Check the actual incoming transfer for '.$case->reference.' before confirming it.',
                            'order_id' => $case->order_id, 'return_id' => $case->id, 'target' => 'buyer-return']);
                }

                return $event;
            }, 3);
            app(BuyerReturnMoneyFiles::class)->discardUnused($paths);

            return $result;
        } catch (Throwable $error) {
            app(BuyerReturnMoneyFiles::class)->discardUnused($paths);
            throw $error;
        }
    }

    public function presentation(User $actor, BuyerReturnCase $case): ?array
    {
        if (! $actor->isBuyer() && ! $actor->isSeller() && ! $actor->isAdmin()) {
            return null;
        }
        $adjustment = BuyerReturnAdjustment::where('buyer_return_case_id', $case->id)->first();
        $events = BuyerReturnMoneyEvent::where('buyer_return_case_id', $case->id)->orderBy('sequence')->get();
        $history = $events->filter(fn ($event) => $actor->isAdmin() || ($actor->isSeller()
            ? ! in_array($event->event_type, ['offer-funding', 'receive-funding'], true)
            : in_array($event->event_type, ['approve-repayment', 'record-repayment', 'confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt', 'close-zero-return'], true)));
        $payload = ['adjustment' => $adjustment?->only(['gross_cents', 'commission_cents', 'shop_discount_cents', 'platform_discount_cents', 'buyer_cents', 'seller_cents', 'platform_cents']),
            'actions' => $this->actions($actor, $case),
            'history' => $history->map(fn ($e) => $e->only(['id', 'reference', 'event_type', 'amount_cents', 'actor_role', 'cash_reference', 'reason', 'source_event_id', 'created_at'])
                + ['proof_url' => $e->proof_path && (! $actor->isBuyer() || in_array($e->event_type, ['record-repayment', 'resolve-nonreceipt'], true))
                    ? '/buyer-returns/'.$case->id.'/money/'.$e->id.'/proof' : null])->values()->all()];
        if (! $actor->isBuyer()) {
            $payload += $this->debt($case);
            $payload['receivers'] = User::where('role', 'admin')->whereNotNull('email_verified_at')->orderBy('id')->get()
                ->filter(fn ($u) => $u->canAccessPortal() && $u->id !== $actor->id)->map(fn ($u) => $u->only(['id', 'name']))->values()->all();
        }
        if ($actor->isAdmin() && $actor->canAccessPortal() && $adjustment) {
            $payload['backing'] = $this->backing($case->order);
            $payload['pending_offers'] = $events->filter(fn ($e) => in_array($e->event_type, ['offer-recovery', 'offer-funding'], true)
                && $e->actor_id !== $actor->id && $e->recipient_id === $actor->id && ! $events->contains('consumed_source_id', $e->id))
                ->map(fn ($e) => $e->only(['id', 'event_type', 'amount_cents', 'cash_reference']))->values()->all();
        }

        return $payload;
    }
}
