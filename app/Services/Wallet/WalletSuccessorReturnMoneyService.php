<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletPayment;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Finance\CodMoney;
use App\Services\Returns\BuyerReturnPlanApproval;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class WalletSuccessorReturnMoneyService
{
    public const ACTIONS = ['approve-repayment', 'reserve-wallet-original', 'fund-wallet-liability', 'advance-wallet-funding', 'refund-wallet'];

    private function party(User $actor, BuyerReturnCase $case): User
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        $owner = $actor->isLogistics() && $actor->isKycApproved() && $actor->email_verified_at
            && LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->where('user_id', $actor->id)->exists();
        if (! $owner) {
            app(BuyerReturnService::class)->owned($actor, $case);
        }

        return $actor;
    }

    public function actions(User $actor, BuyerReturnCase $case): array
    {
        $actor = $this->party($actor, $case);
        abort_unless($case->order->payment_method === 'wallet' && ($case->order->shipping_quote['refund_policy_version'] ?? 1) === 2, 409);
        $plan = Schema::hasTable('buyer_return_repayment_plans') ? BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->first() : null;
        $admin = $actor->isAdmin() && $actor->canAccessPortal() && $actor->email_verified_at;
        if (! $plan) {
            return $admin && in_array($case->status, ['seller_received', 'condition_review'], true) ? ['approve-repayment'] : [];
        }
        $state = app(WalletReturnReserve::class)->summary($plan);
        $actions = [];
        if ($admin && $case->status === 'repayment_ready') {
            if ($state['original_reserved_cents'] < $state['original_required_cents']) {
                $actions[] = 'reserve-wallet-original';
            }
            if ($state['liability_due_cents'] > $state['liable_received_cents'] + $state['advance_outstanding_cents']) {
                $actions[] = 'advance-wallet-funding';
            }
            $actions[] = 'refund-wallet';
        }
        $seller = $plan->snapshot['fault'] === 'seller';
        $liable = $seller ? $actor->isSeller() && $actor->id === $case->seller_id
            : $actor->isLogistics() && LogisticsCompany::whereKey($plan->snapshot['liable_company_id'])->where('user_id', $actor->id)->exists();
        if ($liable && $actor->isKycApproved() && $actor->email_verified_at && $state['liability_remaining_cents'] > 0
            && in_array($case->status, ['repayment_ready', 'repaid', 'closed_no_repayment'], true)) {
            try {
                if (app(WalletFundingCredits::class)->available($actor, $plan->reference) > 0) {
                    $actions[] = 'fund-wallet-liability';
                }
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        }

        return $actions;
    }

    public function command(User $actor, BuyerReturnCase $snapshot, string $action, array $input, ?UploadedFile $proof = null): BuyerReturnMoneyEvent
    {
        abort_unless(in_array($action, self::ACTIONS, true), 404);
        abort_unless(Order::whereKey($snapshot->order_id)->where('payment_method', 'wallet')->exists(), 409, 'This reserve workflow uses actual original virtual payments.');
        abort_if($proof, 422, 'Virtual funding uses its actual balanced ledger reference, not a cash receipt.');
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $amountAction = in_array($action, ['fund-wallet-liability', 'advance-wallet-funding'], true);
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'review_token' => [$action === 'approve-repayment' ? 'required' : 'prohibited', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'amount' => [$amountAction ? 'required' : 'prohibited', 'string', 'regex:'.CodMoney::RULE],
            'wallet_reserve_confirmed' => $action === 'reserve-wallet-original' ? ['required', 'accepted'] : ['prohibited'],
            'wallet_funding_confirmed' => $action === 'fund-wallet-liability' ? ['required', 'accepted'] : ['prohibited'],
            'demo_advance_confirmed' => $action === 'advance-wallet-funding' ? ['required', 'accepted'] : ['prohibited'],
            'wallet_refund_confirmed' => $action === 'refund-wallet' ? ['required', 'accepted'] : ['prohibited'],
            'proof' => ['prohibited'], 'cash_reference' => ['prohibited'], 'payment_confirmed' => ['prohibited'], 'recipient_id' => ['prohibited'],
            'buyer_id' => ['prohibited'], 'seller_id' => ['prohibited'], 'status' => ['prohibited'], 'amount_cents' => ['prohibited']])->validate();
        if ($action === 'approve-repayment') {
            return app(BuyerReturnPlanApproval::class)->approve($actor, $snapshot, $data)->approval;
        }
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        foreach (['wallet_reserve_confirmed', 'wallet_funding_confirmed', 'demo_advance_confirmed', 'wallet_refund_confirmed'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = true;
            }
        }
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$snapshot->id, $actor->id, $action, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $snapshot, $action, $data, $token, $hash) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $snapshot->buyer_id, $snapshot->seller_id])->orderBy('id')->lockForUpdate()->get();
            WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $case = BuyerReturnCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $case->load('order', 'items', 'parcel');
            LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->lockForUpdate()->first();
            $actor = $this->party($actor, $case);
            if ($prior = BuyerReturnMoneyEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->buyer_return_case_id === $case->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $hash), 409,
                    'Retain the exact original virtual funding or refund request.');

                return $prior;
            }
            abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists(), 409, 'This token belongs to another return action.');
            abort_unless($case->version === $data['expected_version'] && in_array($action, $this->actions($actor, $case), true), 409,
                'This return changed. Review its actual original virtual reserve before continuing.');
            $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
            $reserve = app(WalletReturnReserve::class);
            $amount = isset($data['amount']) ? CodMoney::cents($data['amount']) : null;
            $transaction = match ($action) {
                'reserve-wallet-original' => $reserve->original($actor, $case, $data['reason'], $data['request_token']),
                'fund-wallet-liability' => $reserve->liability($actor, $case, $amount, $data['reason'], $data['request_token']),
                'advance-wallet-funding' => $reserve->advance($actor, $case, $amount, $data['reason'], $data['request_token']),
                'refund-wallet' => $reserve->refund($actor, $case, $data['reason'], $data['request_token']),
            };
            $refund = $action === 'refund-wallet';
            $before = $case->status;
            $case->update(['version' => $case->version + 1,
                'status' => $refund ? ($transaction->amount_cents === 0 ? 'closed_no_repayment' : 'repaid') : $case->status]);
            $recipient = $refund ? WalletAccount::where('user_id', $case->buyer_id)->where('kind', 'user')->first()
                : WalletAccount::where('account_key', 'platform:return_reserve')->first();
            $context = ['repayment_plan_reference' => $plan->reference, 'wallet_transaction_id' => $transaction->id, 'wallet_reference' => $transaction->reference,
                'recipient_kind' => $refund ? 'buyer_wallet' : 'platform_refund_reserve', 'recipient_wallet_account_id' => $recipient?->id,
                'beneficiary_id' => $case->buyer_id, 'funding' => $reserve->summary($plan), 'demo_only' => true];
            $event = BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()),
                'buyer_return_case_id' => $case->id, 'order_id' => $case->order_id, 'actor_id' => $actor->id, 'actor_role' => $actor->role,
                'event_type' => $action, 'sequence' => $case->version, 'amount_cents' => $transaction->amount_cents,
                'recipient_id' => $case->buyer_id, 'repayment_case_id' => $refund ? $case->id : null,
                'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $hash, 'context' => $context, 'created_at' => now()]);
            app(BuyerReturnService::class)->append($case, $actor, 'money-'.$action, $before, $data['reason'], $token, $hash,
                ['money_event_id' => $event->id, 'wallet_reference' => $transaction->reference, 'amount_cents' => $transaction->amount_cents,
                    'recipient_kind' => $context['recipient_kind'], 'repayment_plan_reference' => $plan->reference, 'demo_only' => true]);

            return $event;
        }, 3);
    }
}
