<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletPayment;
use App\Models\WalletRefundCreditRequest;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentRefusalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WalletLossCreditService
{
    private function lock(User $actor, ShipmentFinancialPlan $original, ?int $ownerId = null): ShipmentFinancialPlan
    {
        app(AccountRestrictionService::class)->lockGuard();
        $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
        Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
        User::whereIn('id', array_filter([$actor->id, $ownerId, $original->buyer_id, $original->seller_id]))->orderBy('id')->lockForUpdate()->get();
        WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet'
            && ($order->shipping_quote['refund_policy_version'] ?? 1) === 2
            && app(WalletPaymentService::class)->paid($order), 409, 'Retain the original active reviewed loss and actual virtual payment.');
        LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
        LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
        if ($plan->buyer_return_case_id) {
            $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
            $case->parcel()->lockForUpdate()->firstOrFail();
        }

        return $plan;
    }

    private function owner(User $original, ShipmentFinancialPlan $plan): User
    {
        $owner = User::findOrFail($original->id);
        abort_unless($owner->isLogistics() && $owner->isKycApproved() && $owner->email_verified_at && $owner->closed_at === null
            && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $owner->id)->exists(), 403,
            'Only the original liable company owner can request its own demonstration funding credits.');

        return $owner;
    }

    private function currentSource(ShipmentFinancialPlan $plan): void
    {
        if (! app(WalletLossReserve::class)->summary($plan)['resolved']) {
            app(ShipmentLossSource::class)->assertApproved($plan);
        }
    }

    public function maximum(User $actor, ShipmentFinancialPlan $plan): int
    {
        $plan = ShipmentFinancialPlan::findOrFail($plan->id);
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet', 409);
        $owner = $this->owner($actor, $plan);
        if (! $plan->active_order_id && ! $plan->active_return_case_id) {
            return 0;
        }
        $state = app(WalletLossReserve::class)->summary($plan);
        $granted = (int) WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)->where('authority_reference', $plan->reference)
            ->whereNull('repayment_plan_id')->where('status', 'approved')->sum('amount_cents');
        $balance = app(WalletLedger::class)->balance($owner);
        abort_unless($balance !== null, 409, 'Review the actual owned Wallet journal before requesting additional loss funding.');

        $available = app(WalletFundingCredits::class)->available($owner, $plan->reference);

        return max(0, min($state['company_remaining_cents'] - $available, $state['company_due_cents'] - $granted,
            WalletCreditService::MAX_TOP_UP_CENTS, WalletCreditService::MAX_GRANTED_BALANCE_CENTS - $balance));
    }

    public function request(User $actor, ShipmentFinancialPlan $original, array $input): WalletRefundCreditRequest
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'amount' => ['required', 'string', 'regex:'.CodMoney::RULE],
            'actor_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'amount_cents' => ['prohibited'], 'status' => ['prohibited'], 'proof' => ['prohibited']])->validate();
        $data['expected_version'] = (int) $data['expected_version'];
        $data['request_token'] = strtolower($data['request_token']);
        $amount = CodMoney::cents($data['amount']);
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode(['loss-credit-request', $original->id, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $amount, $token, $fingerprint) {
            $plan = $this->lock($actor, $original);
            $owner = $this->owner($actor, $plan);
            if ($prior = WalletRefundCreditRequest::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->repayment_plan_id === null
                    && $prior->actor_id === $owner->id && hash_equals($prior->request_fingerprint, $fingerprint), 409);

                return $prior;
            }
            abort_unless($plan->active_order_id || $plan->active_return_case_id, 409, 'Retain the original voided loss; new funding requires an active reviewed authority.');
            app(ShipmentRefusalReview::class)->unused($token);
            $this->currentSource($plan);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === $data['expected_version'] && $amount > 0 && $amount <= $this->maximum($owner, $plan), 409,
                'Request only the current original company need after available credits and previous demonstration grants.');
            $active = hash('sha256', $plan->reference.':'.$owner->id);
            abort_if(WalletRefundCreditRequest::where('active_key', $active)->exists(), 409, 'Your original loss-funding request still needs independent review.');
            $request = WalletRefundCreditRequest::create(['authority_reference' => $plan->reference, 'shipment_financial_plan_id' => $plan->id,
                'repayment_plan_id' => null, 'actor_id' => $owner->id, 'active_key' => $active, 'amount_cents' => $amount,
                'reason' => $data['reason'], 'status' => 'pending', 'version' => 1, 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint]);
            ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $owner->id, 'event_type' => 'loss-credit-requested',
                'sequence' => $version + 1, 'amount_cents' => 0, 'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'funding_credit_request_id' => $request->id, 'requested_cents' => $amount,
                    'no_money_moved' => true, 'demo_only' => true], 'created_at' => now()]);

            return $request;
        }, 3);
    }

    public function review(User $actor, WalletRefundCreditRequest $original, array $input): WalletRefundCreditRequest
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'demo_credit_confirmed' => ($input['decision'] ?? null) === 'approved' ? ['required', 'accepted'] : ['prohibited'],
            'amount' => ['prohibited'], 'actor_id' => ['prohibited'], 'recipient_id' => ['prohibited'], 'proof' => ['prohibited']])->validate();
        $data['version'] = (int) $data['version'];
        $data['request_token'] = strtolower($data['request_token']);
        if (isset($data['demo_credit_confirmed'])) {
            $data['demo_credit_confirmed'] = true;
        }
        $token = hash('sha256', $data['request_token']);
        $fingerprint = hash('sha256', json_encode(['loss-credit-review', $original->id, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $token, $fingerprint) {
            $source = ShipmentFinancialPlan::findOrFail($original->shipment_financial_plan_id);
            $plan = $this->lock($actor, $source, $original->actor_id);
            $admin = app(WalletCreditService::class)->admin($actor);
            $request = WalletRefundCreditRequest::whereKey($original->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->shipment_financial_plan_id === $plan->id && $request->repayment_plan_id === null
                && $request->authority_reference === $plan->reference && $request->actor_id !== $admin->id
                && ! in_array($admin->id, $plan->snapshot['source_actor_ids'], true), 403, 'An independent current admin must review the original company request.');
            if ($prior = WalletRefundCreditRequest::where('review_token_hash', $token)->first()) {
                abort_unless($prior->id === $request->id && $prior->reviewer_id === $admin->id && hash_equals($prior->review_fingerprint, $fingerprint), 409);

                return $prior;
            }
            abort_unless($plan->active_order_id || $plan->active_return_case_id, 409, 'Retain the original voided loss; new funding requires an active reviewed authority.');
            app(ShipmentRefusalReview::class)->unused($token);
            abort_unless($request->status === 'pending' && $request->version === $data['version'], 409, 'Review the current original pending funding request.');
            $transaction = null;
            if ($data['decision'] === 'approved') {
                $this->currentSource($plan);
                $owner = $this->owner(User::findOrFail($request->actor_id), $plan);
                abort_unless($request->amount_cents > 0 && $request->amount_cents <= $this->maximum($owner, $plan), 409,
                    'The original remaining need changed. Reject this request before requesting its current amount.');
                $ledger = app(WalletLedger::class);
                $transaction = $ledger->post('loss-funding-credit:'.$request->id, 'demo_loss_funding_credit', $request->amount_cents,
                    [$ledger->user($owner)->id => $request->amount_cents, $ledger->platform('demo_issuance')->id => -$request->amount_cents],
                    $admin, $data['reason'], ['shipment_financial_plan_reference' => $plan->reference, 'funding_credit_request_id' => $request->id,
                        'source_actor_id' => $owner->id, 'demo_only' => true], orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $data['request_token']);
            }
            $request->update(['status' => $data['decision'], 'version' => $request->version + 1, 'active_key' => null, 'reviewer_id' => $admin->id,
                'review_reason' => $data['reason'], 'reviewed_at' => now(), 'wallet_transaction_id' => $transaction?->id,
                'review_token_hash' => $token, 'review_fingerprint' => $fingerprint]);
            ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id,
                'event_type' => 'loss-credit-'.$data['decision'], 'sequence' => (int) $plan->events()->max('sequence') + 1,
                'amount_cents' => $transaction?->amount_cents ?? 0, 'recipient_id' => $request->actor_id, 'wallet_transaction_id' => $transaction?->id,
                'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'funding_credit_request_id' => $request->id,
                    'wallet_reference' => $transaction?->reference, 'separate_owned_transfer_required' => true, 'demo_only' => true], 'created_at' => now()]);

            return $request;
        }, 3);
    }
}
