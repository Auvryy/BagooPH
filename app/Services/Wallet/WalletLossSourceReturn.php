<?php

namespace App\Services\Wallet;

use App\Models\BuyerReturnCase;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletPayment;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\AccountRestrictionService;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Finance\ShipmentRefusalReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Restores only an actual unused owned contribution, never a paid beneficiary's credits. */
class WalletLossSourceReturn
{
    private function owner(User $actor, ShipmentFinancialPlan $plan): User
    {
        $owner = User::findOrFail($actor->id);
        abort_unless($owner->isLogistics() && $owner->email_verified_at && $owner->isKycApproved() && $owner->closed_at === null
            && LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $owner->id)->exists(), 403,
            'Only the original company owner may consent to restoring its own unused contribution.');

        return $owner;
    }

    private function unpaid(ShipmentFinancialPlan $plan): void
    {
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet'
            && ! app(WalletLossReserve::class)->summary($plan)['resolved']
            && ! $plan->events()->where('event_type', 'loss-resolve-wallet')->exists()
            && ! WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->where('source_kind', 'refund')->exists(), 409,
            'Paid compensation remains retained. This action returns only original funding that has not paid an approved outcome.');
    }

    private function source(User $owner, ShipmentFinancialPlan $plan, ShipmentFinancialEvent $event): WalletRefundReserveEntry
    {
        $entries = WalletRefundReserveEntry::where('wallet_transaction_id', $event->wallet_transaction_id)->get();
        $entry = $entries->first();
        $transaction = WalletTransaction::find($event->wallet_transaction_id);
        $prefix = 'shipment-loss-funding:'.$plan->id.':';
        abort_unless($entries->count() === 1 && $entry && $transaction && $entry->source_kind === 'liable_company' && $entry->delta_cents > 0
            && $entry->source_user_id === $owner->id && $entry->shipment_financial_plan_id === $plan->id
            && $entry->buyer_return_repayment_plan_id === null && $entry->authority_reference === $plan->reference && $entry->order_id === $plan->order_id
            && $event->shipment_financial_plan_id === $plan->id && $event->event_type === 'loss-contribute-company'
            && $event->actor_id === $owner->id && $event->recipient_id === null && $event->amount_cents === $entry->delta_cents
            && ($event->context['plan_reference'] ?? null) === $plan->reference && ($event->context['wallet_reference'] ?? null) === $transaction->reference
            && $transaction->kind === 'wallet_loss_funding' && $transaction->actor_id === $owner->id && $transaction->amount_cents === $entry->delta_cents
            && $transaction->order_id === $plan->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
            && str_starts_with($transaction->business_key, $prefix)
            && hash('sha256', substr($transaction->business_key, strlen($prefix))) === $event->request_token_hash
            && $event->request_fingerprint === hash('sha256', json_encode([$plan->id, $owner->id, 'contribute-company', $event->sequence - 1,
                $entry->delta_cents, $event->reason], JSON_THROW_ON_ERROR))
            && $transaction->reason === $event->reason
            && ($transaction->context['shipment_financial_plan_reference'] ?? null) === $plan->reference
            && ($transaction->context['reserve_source_kind'] ?? null) === 'liable_company'
            && $transaction->request_token_hash === $event->request_token_hash, 409,
            'Retain the original company contribution, exact source event and actual reserve deposit.');
        $this->journal($transaction, $owner, -$entry->delta_cents, $entry->wallet_account_id);
        abort_unless(($transaction->context['source_account_id'] ?? null) === app(WalletLedger::class)->user($owner)->id, 409,
            'Retain the actual original owned funding account.');

        return $entry;
    }

    public function sources(User $actor, ShipmentFinancialPlan $plan): array
    {
        $owner = $this->owner($actor, $plan);
        $this->unpaid($plan);
        $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
        $this->movements($owner);
        $sources = [];
        foreach ($plan->events()->where('event_type', 'loss-contribute-company')->where('actor_id', $owner->id)->get() as $event) {
            $entry = $this->source($owner, $plan, $event);
            if ($event->sequence <= $finding->financial_version && ! $this->returned($entry)) {
                $sources[] = ['id' => $event->id, 'label' => $event->reference, 'amount_cents' => $entry->delta_cents];
            }
        }

        return $sources;
    }

    private function returned(WalletRefundReserveEntry $entry): bool
    {
        return WalletRefundReserveEntry::where('source_kind', 'company_source_return')->where('context->source_reserve_entry_id', $entry->id)->exists();
    }

    /** Immutable actual source returns restore only the grants consumed by that original contribution. */
    public function movements(User $owner): array
    {
        $owned = WalletEntry::join('wallet_accounts as return_owner', 'return_owner.id', '=', 'wallet_entries.wallet_account_id')
            ->where('return_owner.user_id', $owner->id)->where('return_owner.kind', 'user')->select('wallet_entries.wallet_transaction_id');
        $transactions = WalletTransaction::where('kind', 'wallet_loss_company_source_return')->whereIn('id', $owned)->get();
        $movements = [];
        foreach ($transactions as $transaction) {
            $events = ShipmentFinancialEvent::where('wallet_transaction_id', $transaction->id)->where('event_type', 'loss-return-company-source')->get();
            $event = $events->first();
            $plan = $event ? ShipmentFinancialPlan::find($event->shipment_financial_plan_id) : null;
            $source = $event?->source_event_id ? ShipmentFinancialEvent::find($event->source_event_id) : null;
            abort_unless($events->count() === 1 && $plan && $source, 409, 'Retain the original exact financial source of an actual company reserve return.');
            $entry = $this->source($owner, $plan, $source);
            $returned = WalletRefundReserveEntry::where('wallet_transaction_id', $transaction->id)->get();
            $row = $returned->first();
            $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->first();
            abort_unless($finding && $returned->count() === 1 && $row && $row->source_kind === 'company_source_return'
                && $row->source_user_id === $owner->id && $row->shipment_financial_plan_id === $plan->id && $row->buyer_return_repayment_plan_id === null
                && $row->authority_reference === $plan->reference && $row->order_id === $plan->order_id && $row->wallet_account_id === $entry->wallet_account_id
                && $row->delta_cents === -$entry->delta_cents && ($row->context['source_reserve_entry_id'] ?? null) === $entry->id
                && $transaction->amount_cents === $entry->delta_cents && $transaction->actor_id === $owner->id
                && $transaction->order_id === $plan->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
                && $transaction->business_key === 'loss-company-source-return:'.$entry->id
                && ($transaction->context['shipment_financial_plan_reference'] ?? null) === $plan->reference
                && ($transaction->context['reserve_source_kind'] ?? null) === 'company_source_return'
                && ($transaction->context['source_reserve_entry_id'] ?? null) === $entry->id
                && ($transaction->context['source_wallet_transaction_id'] ?? null) === $entry->wallet_transaction_id
                && ($transaction->context['finding_reference'] ?? null) === $finding->reference
                && $event->actor_id === $owner->id && $event->recipient_id === $owner->id && $event->amount_cents === $entry->delta_cents
                && $event->request_token_hash === $transaction->request_token_hash
                && ($event->context['plan_reference'] ?? null) === $plan->reference && ($event->context['wallet_reference'] ?? null) === $transaction->reference
                && ($event->context['source_reserve_entry_id'] ?? null) === $entry->id && ($event->context['finding_reference'] ?? null) === $finding->reference
                && $source->sequence <= $finding->financial_version && $finding->financial_version + 1 < $event->sequence
                && $entry->wallet_transaction_id < $transaction->id
                && $plan->events()->where('event_type', 'loss-found-quarantined')->where('sequence', $finding->financial_version + 1)
                    ->where('actor_id', $finding->actor_id)->where('amount_cents', 0)->whereNull('wallet_transaction_id')
                    ->where('request_token_hash', $finding->request_token_hash)->where('request_fingerprint', $finding->request_fingerprint)
                    ->where('context->finding_reference', $finding->reference)->exists(), 409,
                'Retain the actual original funding, exact finding and matched immutable reserve-return journals.');
            $this->journal($transaction, $owner, $entry->delta_cents, $entry->wallet_account_id);
            $movements[] = ['id' => $transaction->id, 'authority' => $plan->reference, 'type' => 'restore',
                'source_id' => $entry->wallet_transaction_id, 'amount' => $entry->delta_cents];
        }

        return $movements;
    }

    private function journal(WalletTransaction $transaction, User $owner, int $ownerDelta, int $reserveId): void
    {
        $ledger = app(WalletLedger::class);
        abort_unless($reserveId === $ledger->platform('return_reserve')->id, 409);
        $expected = [$ledger->user($owner)->id => $ownerDelta, $reserveId => -$ownerDelta];
        $actual = WalletEntry::where('wallet_transaction_id', $transaction->id)->get()->mapWithKeys(fn ($entry) => [$entry->wallet_account_id => $entry->delta_cents])->all();
        ksort($expected);
        ksort($actual);
        abort_unless($actual === $expected, 409, 'Retain only the exact balanced original company and reserve accounts.');
    }

    public function execute(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentFinancialEvent
    {
        $allowed = ['expected_version', 'request_token', 'source_event_id', 'reason', 'company_source_return_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, $rules + ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'source_event_id' => ['required', new AsciiPositiveInteger, 'integer'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'company_source_return_confirmed' => ['required', 'accepted']])->validate();
        $uuid = strtolower($data['request_token']);
        $token = hash('sha256', $uuid);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], (int) $data['source_event_id'],
            $data['reason'], 'return-company-source'], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $original, $data, $uuid, $token, $fingerprint) {
            app(AccountRestrictionService::class)->lockGuard();
            $order = Order::whereKey($original->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($original->delivery_id)->lockForUpdate()->firstOrFail();
            User::whereIn('id', [$actor->id, $original->buyer_id, $original->seller_id])->orderBy('id')->lockForUpdate()->get();
            WalletPayment::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $plan = ShipmentFinancialPlan::whereKey($original->id)->lockForUpdate()->firstOrFail();
            LogisticsCompany::whereKey($plan->logistics_company_id)->lockForUpdate()->firstOrFail();
            LogisticsManifest::whereKey($plan->snapshot['source']['manifest_id'])->lockForUpdate()->firstOrFail();
            if ($plan->buyer_return_case_id) {
                $case = BuyerReturnCase::whereKey($plan->buyer_return_case_id)->lockForUpdate()->firstOrFail();
                $case->parcel()->lockForUpdate()->firstOrFail();
            }
            $owner = $this->owner($actor, $plan);
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $owner->id
                    && $prior->event_type === 'loss-return-company-source' && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'Retain the exact original company source-return request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            $this->unpaid($plan);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'] && app(WalletPaymentService::class)->paid($order, allowRefunded: true), 409,
                'Retain the current original financial history and actual payment.');
            $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
            $source = $plan->events()->whereKey($data['source_event_id'])->first();
            abort_unless($source, 409, 'Select this original company contribution.');
            $entry = $this->source($owner, $plan, $source);
            abort_unless($source->sequence <= $finding->financial_version && ! $this->returned($entry)
                && app(WalletLossReserve::class)->summary($plan)['balance_cents'] >= $entry->delta_cents, 409,
                'Return only this original still-unused contribution once.');
            app(WalletFundingCredits::class)->grantBalances($owner);
            $ledger = app(WalletLedger::class);
            $transaction = $ledger->post('loss-company-source-return:'.$entry->id, 'wallet_loss_company_source_return', $entry->delta_cents,
                [$ledger->platform('return_reserve')->id => -$entry->delta_cents, $ledger->user($owner)->id => $entry->delta_cents], $owner, $data['reason'],
                ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => 'company_source_return',
                    'source_reserve_entry_id' => $entry->id, 'source_wallet_transaction_id' => $entry->wallet_transaction_id,
                    'finding_reference' => $finding->reference, 'demo_only' => true], orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
            WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null, 'wallet_transaction_id' => $transaction->id,
                'wallet_account_id' => $entry->wallet_account_id, 'source_user_id' => $owner->id, 'source_kind' => 'company_source_return',
                'delta_cents' => -$entry->delta_cents, 'context' => ['source_reserve_entry_id' => $entry->id, 'demo_only' => true], 'created_at' => now()]);
            $event = ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $owner->id, 'recipient_id' => $owner->id,
                'event_type' => 'loss-return-company-source', 'sequence' => $version + 1, 'amount_cents' => $entry->delta_cents, 'source_event_id' => $source->id,
                'wallet_transaction_id' => $transaction->id, 'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference, 'source_reserve_entry_id' => $entry->id,
                    'finding_reference' => $finding->reference, 'no_parcel_release' => true, 'paid_compensation_unchanged' => true], 'created_at' => now()]);
            $this->movements($owner);
            app(WalletFundingCredits::class)->grantBalances($owner);

            return $event;
        }, 3);
    }
}
