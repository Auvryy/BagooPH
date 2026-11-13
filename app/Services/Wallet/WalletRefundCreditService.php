<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Finance\CodMoney;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class WalletRefundCreditService
{
    private function lock(User $actor, BuyerReturnCase $snapshot, ?int $sourceActor = null): array
    {
        abort_unless(Schema::hasTable('wallet_refund_credit_requests'), 409, 'The retained demonstration funding-request workflow is not yet available.');
        app(AccountRestrictionService::class)->lockGuard();
        $order = Order::whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
        Delivery::whereKey($snapshot->delivery_id)->lockForUpdate()->firstOrFail();
        User::whereIn('id', array_filter([$actor->id, $sourceActor, $snapshot->buyer_id, $snapshot->seller_id]))->orderBy('id')->lockForUpdate()->get();
        WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $case = BuyerReturnCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
        $case->load('order', 'items', 'parcel');
        LogisticsCompany::whereKey($case->parcel?->logistics_company_id)->lockForUpdate()->first();
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
        abort_unless($case->order->payment_method === 'wallet' && ($case->order->shipping_quote['refund_policy_version'] ?? 1) === 2
            && app(WalletPaymentService::class)->paid($case->order), 409, 'Demonstration funding credits require the original approved virtual obligation.');
        app(BuyerReturnAmounts::class)->adjustmentFinancials($case->order);

        return [$case, $plan];
    }

    private function liable(User $actor, BuyerReturnCase $case, BuyerReturnRepaymentPlan $plan): User
    {
        $actor = app(BuyerReturnService::class)->current($actor);
        $seller = $plan->snapshot['fault'] === 'seller';
        abort_unless($actor->email_verified_at && $actor->isKycApproved()
            && ($seller ? $actor->isSeller() && $actor->id === $case->seller_id
                : $actor->isLogistics() && LogisticsCompany::whereKey($plan->snapshot['liable_company_id'])->where('user_id', $actor->id)->exists()), 403,
            'Only the original liable account can request its own demonstration funding credits.');

        return $actor;
    }

    public function maximum(User $actor, BuyerReturnCase $case, BuyerReturnRepaymentPlan $plan): int
    {
        $this->liable($actor, $case, $plan);
        $state = app(WalletReturnReserve::class)->summary($plan);
        $granted = (int) WalletRefundCreditRequest::where('authority_reference', $plan->reference)->where('status', 'approved')->sum('amount_cents');
        $balance = app(WalletLedger::class)->balance($actor);
        abort_unless($balance !== null, 409, 'Review the actual owned Wallet journal before requesting additional demonstration funding.');

        return max(0, min($state['liability_remaining_cents'] - $balance, $state['liability_due_cents'] - $granted, WalletCreditService::MAX_TOP_UP_CENTS));
    }

    private function unused(string $token): void
    {
        abort_if(BuyerReturnEvent::where('request_token_hash', $token)->exists() || BuyerReturnMoneyEvent::where('request_token_hash', $token)->exists()
            || ShipmentFinancialPlan::where('request_token_hash', $token)->exists() || ShipmentFinancialEvent::where('request_token_hash', $token)->exists()
            || WalletRefundCreditRequest::where('request_token_hash', $token)->orWhere('review_token_hash', $token)->exists()
            || WalletTransaction::where('request_token_hash', $token)->exists(), 409, 'This request token belongs to another original action.');
    }

    public function request(User $actor, BuyerReturnCase $snapshot, array $input): WalletRefundCreditRequest
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'amount' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'actor_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'amount_cents' => ['prohibited'], 'status' => ['prohibited'], 'proof' => ['prohibited']])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $data['reason'] = trim($data['reason']);
        $data['request_token'] = strtolower($data['request_token']);
        $amount = CodMoney::cents($data['amount']);
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$snapshot->id, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $snapshot, $data, $amount, $token, $hash) {
            [$case, $plan] = $this->lock($actor, $snapshot);
            $actor = $this->liable($actor, $case, $plan);
            if ($prior = WalletRefundCreditRequest::where('request_token_hash', $token)->first()) {
                abort_unless($prior->repayment_plan_id === $plan->id && $prior->actor_id === $actor->id && hash_equals($prior->request_fingerprint, $hash), 409);

                return $prior;
            }
            $this->unused($token);
            abort_unless($case->version === $data['expected_version'] && $amount > 0 && $amount <= $this->maximum($actor, $case, $plan), 409,
                'Request only the current unfunded virtual liability after existing credits and prior demonstration grants.');
            $active = hash('sha256', $plan->reference.':'.$actor->id);
            abort_if(WalletRefundCreditRequest::where('active_key', $active)->exists(), 409, 'Your original funding-credit request is still awaiting independent review.');
            $request = WalletRefundCreditRequest::create(['authority_reference' => $plan->reference, 'repayment_plan_id' => $plan->id,
                'actor_id' => $actor->id, 'active_key' => $active, 'amount_cents' => $amount, 'reason' => $data['reason'], 'status' => 'pending', 'version' => 1,
                'request_token_hash' => $token, 'request_fingerprint' => $hash]);
            $before = $case->status;
            $case->update(['version' => $case->version + 1]);
            app(BuyerReturnService::class)->append($case, $actor, 'wallet-credit-requested', $before, $data['reason'], $token, $hash,
                ['funding_credit_request_id' => $request->id, 'requested_cents' => $amount, 'source_actor_id' => $actor->id, 'no_money_moved' => true, 'demo_only' => true]);

            return $request;
        }, 3);
    }

    public function review(User $actor, WalletRefundCreditRequest $snapshot, array $input): WalletRefundCreditRequest
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'demo_credit_confirmed' => ($input['decision'] ?? null) === 'approved' ? ['required', 'accepted'] : ['prohibited'],
            'amount' => ['prohibited'], 'actor_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'proof' => ['prohibited']])->validate();
        $data['version'] = (int) $data['version'];
        $data['reason'] = trim($data['reason']);
        $data['request_token'] = strtolower($data['request_token']);
        if (isset($data['demo_credit_confirmed'])) {
            $data['demo_credit_confirmed'] = true;
        }
        $token = hash('sha256', $data['request_token']);
        $hash = hash('sha256', json_encode([$snapshot->id, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $snapshot, $data, $token, $hash) {
            $source = BuyerReturnRepaymentPlan::findOrFail($snapshot->repayment_plan_id);
            [$case, $plan] = $this->lock($actor, BuyerReturnCase::findOrFail($source->buyer_return_case_id), $snapshot->actor_id);
            $actor = app(WalletCreditService::class)->admin($actor);
            $request = WalletRefundCreditRequest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->repayment_plan_id === $plan->id && $request->authority_reference === $plan->reference && $request->actor_id !== $actor->id, 403);
            if ($prior = WalletRefundCreditRequest::where('review_token_hash', $token)->first()) {
                abort_unless($prior->id === $request->id && $prior->reviewer_id === $actor->id && hash_equals($prior->review_fingerprint, $hash), 409);

                return $prior;
            }
            $this->unused($token);
            abort_unless($request->status === 'pending' && $request->version === $data['version'], 409, 'Review the original current pending funding request.');
            $transaction = null;
            if ($data['decision'] === 'approved') {
                $recipient = $this->liable(User::findOrFail($request->actor_id), $case, $plan);
                abort_unless($request->amount_cents > 0 && $request->amount_cents <= $this->maximum($recipient, $case, $plan), 409,
                    'The original unfunded need changed. Reject this request and have its owner submit the current amount.');
                $ledger = app(WalletLedger::class);
                $balance = $ledger->balance($recipient);
                abort_unless($balance !== null && $balance + $request->amount_cents <= WalletCreditService::MAX_GRANTED_BALANCE_CENTS, 409,
                    'The actual owned demonstration Wallet balance cannot exceed its existing limit.');
                $transaction = $ledger->post('return-funding-credit:'.$request->id, 'demo_return_funding_credit', $request->amount_cents,
                    [$ledger->user($recipient)->id => $request->amount_cents, $ledger->platform('demo_issuance')->id => -$request->amount_cents], $actor, $data['reason'],
                    ['repayment_plan_reference' => $plan->reference, 'funding_credit_request_id' => $request->id, 'source_actor_id' => $recipient->id, 'demo_only' => true],
                    orderId: $case->order_id, returnId: $case->id, token: $data['request_token']);
            }
            $request->update(['status' => $data['decision'], 'version' => $request->version + 1, 'active_key' => null,
                'reviewer_id' => $actor->id, 'review_reason' => $data['reason'], 'reviewed_at' => now(),
                'wallet_transaction_id' => $transaction?->id, 'review_token_hash' => $token, 'review_fingerprint' => $hash]);
            $before = $case->status;
            $case->update(['version' => $case->version + 1]);
            if ($transaction) {
                BuyerReturnMoneyEvent::create(['reference' => 'BRM-'.Str::upper((string) Str::uuid()), 'buyer_return_case_id' => $case->id,
                    'order_id' => $case->order_id, 'actor_id' => $actor->id, 'actor_role' => 'admin', 'event_type' => 'wallet-funding-credit', 'sequence' => $case->version,
                    'amount_cents' => $transaction->amount_cents, 'recipient_id' => $request->actor_id, 'reason' => $data['reason'],
                    'request_token_hash' => $token, 'request_fingerprint' => $hash,
                    'context' => ['wallet_transaction_id' => $transaction->id, 'wallet_reference' => $transaction->reference, 'funding_credit_request_id' => $request->id,
                        'recipient_kind' => 'liable_user_wallet', 'repayment_plan_reference' => $plan->reference, 'demo_only' => true], 'created_at' => now()]);
            }
            app(BuyerReturnService::class)->append($case, $actor, 'wallet-credit-'.$data['decision'], $before, $data['reason'], $token, $hash,
                ['funding_credit_request_id' => $request->id, 'source_actor_id' => $request->actor_id,
                    'wallet_reference' => $transaction?->reference, 'amount_cents' => $transaction?->amount_cents ?? 0, 'demo_only' => true]);

            return $request;
        }, 3);
    }
}
