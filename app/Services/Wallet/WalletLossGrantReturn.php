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
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentRefusalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Voluntary return of unused issuance; never liability payment or parcel release. */
class WalletLossGrantReturn
{
    private function owner(User $actor, ShipmentFinancialPlan $plan): User
    {
        $owner = User::findOrFail($actor->id);
        abort_unless($owner->isLogistics() && $owner->email_verified_at && $owner->isKycApproved() && $owner->closed_at === null
            && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $owner->id)->exists(), 403,
            'Only the original company owner can consent to returning its own unused reviewed credits.');

        return $owner;
    }

    public function sources(User $actor, ShipmentFinancialPlan $plan): array
    {
        $owner = $this->owner($actor, $plan);
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet', 409);
        app(ShipmentLossSource::class)->assertQuarantined($plan);
        $balances = app(WalletFundingCredits::class)->grantBalances($owner);
        $sources = [];
        foreach (WalletRefundCreditRequest::where('shipment_financial_plan_id', $plan->id)->whereNull('repayment_plan_id')
            ->where('authority_reference', $plan->reference)->where('actor_id', $owner->id)->where('status', 'approved')->get() as $grant) {
            $remaining = $balances[$grant->id] ?? 0;
            $event = $plan->events()->where('event_type', 'loss-credit-approved')->where('wallet_transaction_id', $grant->wallet_transaction_id)
                ->where('actor_id', $grant->reviewer_id)->where('recipient_id', $owner->id)
                ->where('context->funding_credit_request_id', $grant->id)->first();
            if ($remaining > 0 && $event && $event->amount_cents === $grant->amount_cents) {
                $sources[] = ['id' => $event->id, 'label' => $grant->reference, 'amount_cents' => $remaining];
            }
        }

        return $sources;
    }

    public function execute(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentFinancialEvent
    {
        $allowed = ['expected_version', 'request_token', 'source_event_id', 'reason', 'unused_credit_return_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, $rules + ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => ['required', 'uuid'], 'source_event_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'reason' => ['required', new ApplicationText('notes', 10, 1000)], 'unused_credit_return_confirmed' => ['required', 'accepted']])->validate();
        $uuid = strtolower($data['request_token']);
        $token = hash('sha256', $uuid);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], (int) $data['source_event_id'],
            $data['reason'], 'return-unused-credit'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $uuid, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $original->buyer_id, $original->seller_id])->orderBy('id')->lockForUpdate()->get();
            WalletPayment::where('order_id', $order->id)->lockForUpdate()->first();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
            LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
            if ($plan->buyer_return_case_id) {
                $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                $case->parcel()->lockForUpdate()->firstOrFail();
            }
            $owner = $this->owner($actor, $plan);
            abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet', 409);
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $owner->id
                    && $prior->event_type === 'loss-return-unused-credit' && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'Retain the exact original unused-credit return request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'] && app(WalletPaymentService::class)->paid($order, allowRefunded: true), 409,
                'Retain the current original financial history and actual original virtual payment.');
            $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
            $source = $plan->events()->whereKey($data['source_event_id'])->where('event_type', 'loss-credit-approved')->first();
            $grant = $source ? WalletRefundCreditRequest::whereKey($source->context['funding_credit_request_id'] ?? 0)->lockForUpdate()->first() : null;
            abort_unless($grant && $grant->status === 'approved' && $grant->actor_id === $owner->id
                && $grant->shipment_financial_plan_id === $plan->id && $grant->repayment_plan_id === null && $grant->authority_reference === $plan->reference
                && $source->actor_id === $grant->reviewer_id && $source->recipient_id === $owner->id
                && $source->wallet_transaction_id === $grant->wallet_transaction_id && $source->amount_cents === $grant->amount_cents, 409,
                'Select the actual original independent approval of this company’s own reviewed grant.');
            $amount = app(WalletFundingCredits::class)->grantBalances($owner)[$grant->id] ?? 0;
            abort_unless($amount > 0, 409, 'This original grant has no unused credits to return. Retain any actual contributed funding separately.');
            $ledger = app(WalletLedger::class);
            $transaction = $ledger->post('loss-credit-return:'.$grant->id.':'.$token, 'demo_loss_credit_return', $amount,
                [$ledger->user($owner)->id => -$amount, $ledger->platform('demo_issuance')->id => $amount], $owner, $data['reason'],
                ['shipment_financial_plan_reference' => $plan->reference, 'funding_credit_request_id' => $grant->id, 'source_actor_id' => $owner->id,
                    'source_wallet_transaction_id' => $grant->wallet_transaction_id, 'finding_reference' => $finding->reference, 'demo_only' => true],
                orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
            $event = ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $owner->id,
                'event_type' => 'loss-return-unused-credit', 'sequence' => $version + 1, 'amount_cents' => $amount,
                'source_event_id' => $source->id, 'wallet_transaction_id' => $transaction->id, 'reason' => $data['reason'],
                'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'funding_credit_request_id' => $grant->id, 'wallet_reference' => $transaction->reference,
                    'finding_reference' => $finding->reference, 'no_liability_payment' => true, 'no_parcel_release' => true, 'paid_compensation_unchanged' => true], 'created_at' => now()]);
            app(WalletFundingCredits::class)->grantBalances($owner);

            return $event;
        }, 3);
    }
}
