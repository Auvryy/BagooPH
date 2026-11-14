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

/** Returns unused platform backing to its original source; never repays a buyer. */
class WalletLossPlatformReturn
{
    private const RETURNS = ['original_escrow' => 'original_escrow_return', 'advance' => 'found_advance_return'];

    private function admin(User $actor, ShipmentFinancialPlan $plan): User
    {
        $admin = app(WalletCreditService::class)->admin($actor);
        abort_if(in_array($admin->id, $plan->snapshot['source_actor_ids'], true), 403,
            'An independent current admin must review the actual original source and finding.');

        return $admin;
    }

    private function unpaid(ShipmentFinancialPlan $plan): void
    {
        abort_unless($plan->kind === 'transit_loss' && $plan->snapshot['payment_method'] === 'wallet'
            && ! app(WalletLossReserve::class)->summary($plan)['resolved']
            && ! $plan->events()->where('event_type', 'loss-resolve-wallet')->exists()
            && ! WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->where('source_kind', 'refund')->exists(), 409,
            'Retain every actual paid compensation. Only unused original platform backing may be restored here.');
    }

    private function journal(WalletTransaction $transaction, string $source, int $reserveDelta, int $reserveId): void
    {
        $ledger = app(WalletLedger::class);
        abort_unless($reserveId === $ledger->platform('return_reserve')->id, 409);
        $expected = [$reserveId => $reserveDelta, $ledger->platform($source)->id => -$reserveDelta];
        $actual = WalletEntry::where('wallet_transaction_id', $transaction->id)->get()
            ->mapWithKeys(fn ($entry) => [$entry->wallet_account_id => $entry->delta_cents])->all();
        ksort($expected);
        ksort($actual);
        abort_unless($actual === $expected, 409, 'Retain the exact balanced original platform source and its own reserve.');
    }

    private function funding(ShipmentFinancialPlan $plan, WalletRefundReserveEntry $entry, WalletTransaction $transaction): ShipmentFinancialEvent
    {
        $eventType = $entry->source_kind === 'original_escrow' ? 'loss-reserve-original' : 'loss-approve-advance';
        $events = $plan->events()->where('wallet_transaction_id', $transaction->id)->where('event_type', $eventType)->get();
        $event = $events->first();
        $prefix = 'shipment-loss-funding:'.$plan->id.':';
        $kind = $entry->source_kind === 'original_escrow' ? 'escrow' : 'demo_issuance';
        abort_unless($events->count() === 1 && $event && $entry->delta_cents > 0 && $entry->source_user_id === null
            && $transaction->kind === 'wallet_loss_funding' && $transaction->amount_cents === $entry->delta_cents
            && $event->actor_id === $transaction->actor_id && $event->recipient_id === null && $event->amount_cents === $entry->delta_cents
            && $event->request_token_hash === $transaction->request_token_hash && $transaction->reason === $event->reason
            && str_starts_with($transaction->business_key, $prefix)
            && hash('sha256', substr($transaction->business_key, strlen($prefix))) === $event->request_token_hash
            && $event->request_fingerprint === hash('sha256', json_encode([$plan->id, $event->actor_id, substr($eventType, 5), $event->sequence - 1,
                $entry->source_kind === 'original_escrow' ? null : $entry->delta_cents, $event->reason], JSON_THROW_ON_ERROR))
            && ($event->context['plan_reference'] ?? null) === $plan->reference && ($event->context['wallet_reference'] ?? null) === $transaction->reference
            && ($transaction->context['source_account_id'] ?? null) === app(WalletLedger::class)->platform($kind)->id, 409,
            'Retain the actual original platform funding, exact reviewed command and its source account.');
        $this->journal($transaction, $kind, $entry->delta_cents, $entry->wallet_account_id);

        return $event;
    }

    private function positions(ShipmentFinancialPlan $plan): array
    {
        $rows = WalletRefundReserveEntry::where('shipment_financial_plan_id', $plan->id)->where('authority_reference', $plan->reference)
            ->where('order_id', $plan->order_id)->whereNull('buyer_return_repayment_plan_id')->orderBy('wallet_transaction_id')->get();
        $funding = [];
        $remaining = [];
        $events = [];
        foreach ($rows as $row) {
            if (! in_array($row->source_kind, [...array_keys(self::RETURNS), ...array_values(self::RETURNS), 'advance_retirement'], true)) {
                continue;
            }
            $transaction = WalletTransaction::find($row->wallet_transaction_id);
            abort_unless($transaction && $transaction->order_id === $plan->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
                && ($transaction->context['shipment_financial_plan_reference'] ?? null) === $plan->reference
                && ($transaction->context['reserve_source_kind'] ?? null) === $row->source_kind, 409,
                'Retain the original authority and actual platform source journals.');
            if (isset(self::RETURNS[$row->source_kind])) {
                $events[$row->id] = $this->funding($plan, $row, $transaction);
                $funding[$row->id] = $row;
                $remaining[$row->id] = $row->delta_cents;
            } elseif ($row->source_kind === 'advance_retirement') {
                abort_unless($row->delta_cents < 0 && $row->source_user_id === null && $transaction->kind === 'wallet_loss_advance_retirement'
                    && $transaction->amount_cents === -$row->delta_cents, 409, 'Retain the original actual advance retirement.');
                $this->journal($transaction, 'demo_issuance', $row->delta_cents, $row->wallet_account_id);
                $left = -$row->delta_cents;
                foreach ($funding as $id => $source) {
                    if ($source->source_kind !== 'advance') {
                        continue;
                    }
                    $used = min($left, $remaining[$id]);
                    $remaining[$id] -= $used;
                    $left -= $used;
                }
                abort_unless($left === 0, 409, 'An actual retirement cannot consume later advances or original escrow.');
            } else {
                $id = $row->context['source_reserve_entry_id'] ?? 0;
                $source = $funding[$id] ?? null;
                $event = $source ? $events[$id] : null;
                $returns = $plan->events()->where('event_type', 'loss-return-platform-source')->where('wallet_transaction_id', $transaction->id)->get();
                $returned = $returns->first();
                $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->first();
                abort_unless($source && $event && $finding && $returns->count() === 1 && $returned && $row->source_user_id === null
                    && $row->source_kind === self::RETURNS[$source->source_kind] && $row->delta_cents < 0 && -$row->delta_cents === $remaining[$id]
                    && $row->wallet_account_id === $source->wallet_account_id
                    && $transaction->kind === 'wallet_loss_platform_source_return' && $transaction->amount_cents === -$row->delta_cents
                    && $transaction->business_key === 'loss-platform-source-return:'.$id
                    && ($transaction->context['source_reserve_entry_id'] ?? null) === $id
                    && ($transaction->context['source_wallet_transaction_id'] ?? null) === $source->wallet_transaction_id
                    && ($transaction->context['finding_reference'] ?? null) === $finding->reference
                    && $returned->source_event_id === $event->id && $returned->recipient_id === null
                    && $returned->actor_id === $transaction->actor_id && $returned->amount_cents === $transaction->amount_cents
                    && $returned->request_token_hash === $transaction->request_token_hash
                    && ($returned->context['plan_reference'] ?? null) === $plan->reference
                    && ($returned->context['wallet_reference'] ?? null) === $transaction->reference
                    && ($returned->context['source_reserve_entry_id'] ?? null) === $id
                    && ($returned->context['finding_reference'] ?? null) === $finding->reference
                    && $event->sequence <= $finding->financial_version && $finding->financial_version + 1 < $returned->sequence
                    && $plan->events()->where('event_type', 'loss-found-quarantined')->where('sequence', $finding->financial_version + 1)
                        ->where('actor_id', $finding->actor_id)->where('amount_cents', 0)->whereNull('wallet_transaction_id')
                        ->where('request_token_hash', $finding->request_token_hash)->where('request_fingerprint', $finding->request_fingerprint)
                        ->where('context->finding_reference', $finding->reference)->exists(), 409,
                    'Retain the exact finding, original funding and actual once-only platform source return.');
                $this->journal($transaction, $source->source_kind === 'original_escrow' ? 'escrow' : 'demo_issuance', $row->delta_cents, $row->wallet_account_id);
                $remaining[$id] = 0;
            }
        }
        abort_unless(array_sum($remaining) <= app(WalletLossReserve::class)->summary($plan)['balance_cents'], 409,
            'The original unused platform sources must remain backed by their own actual reserve.');

        return compact('funding', 'remaining', 'events');
    }

    public function sources(User $actor, ShipmentFinancialPlan $plan): array
    {
        $this->admin($actor, $plan);
        $this->unpaid($plan);
        $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
        $state = $this->positions($plan);
        $sources = [];
        foreach ($state['funding'] as $id => $entry) {
            $event = $state['events'][$id];
            if ($state['remaining'][$id] > 0 && $event->sequence <= $finding->financial_version) {
                $sources[] = ['id' => $event->id, 'label' => ($entry->source_kind === 'original_escrow' ? 'Original escrow' : 'Original demo advance').' · '.$event->reference,
                    'amount_cents' => $state['remaining'][$id]];
            }
        }

        return $sources;
    }

    public function execute(User $actor, ShipmentFinancialPlan $original, array $input): ShipmentFinancialEvent
    {
        $allowed = ['expected_version', 'request_token', 'source_event_id', 'reason', 'platform_source_return_confirmed'];
        $rules = array_fill_keys(array_diff(array_keys($input), $allowed), ['prohibited']);
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, $rules + ['expected_version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'source_event_id' => ['required', new AsciiPositiveInteger, 'integer'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'platform_source_return_confirmed' => ['required', 'accepted']])->validate();
        $uuid = strtolower($data['request_token']);
        $token = hash('sha256', $uuid);
        $fingerprint = hash('sha256', json_encode([$original->id, $actor->id, (int) $data['expected_version'], (int) $data['source_event_id'], $data['reason'], 'return-platform-source'], JSON_THROW_ON_ERROR));

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
            $admin = $this->admin($actor, $plan);
            if ($prior = ShipmentFinancialEvent::where('request_token_hash', $token)->first()) {
                abort_unless($prior->shipment_financial_plan_id === $plan->id && $prior->actor_id === $admin->id
                    && $prior->event_type === 'loss-return-platform-source' && hash_equals($prior->request_fingerprint, $fingerprint), 409,
                    'Retain the exact original platform source-return request.');

                return $prior;
            }
            app(ShipmentRefusalReview::class)->unused($token);
            $this->unpaid($plan);
            $version = (int) $plan->events()->max('sequence');
            abort_unless($version === (int) $data['expected_version'] && app(WalletPaymentService::class)->paid($order, allowRefunded: true), 409);
            $finding = app(ShipmentLossSource::class)->assertQuarantined($plan);
            $state = $this->positions($plan);
            $id = collect($state['events'])->search(fn ($event) => $event->id === (int) $data['source_event_id']);
            abort_unless($id !== false && $state['remaining'][$id] > 0 && $state['events'][$id]->sequence <= $finding->financial_version, 409,
                'Select this authority’s actual unused original escrow or advance.');
            $entry = $state['funding'][$id];
            $amount = $state['remaining'][$id];
            $kind = self::RETURNS[$entry->source_kind];
            $ledger = app(WalletLedger::class);
            $transaction = $ledger->post('loss-platform-source-return:'.$id, 'wallet_loss_platform_source_return', $amount,
                [$ledger->platform('return_reserve')->id => -$amount, $ledger->platform($entry->source_kind === 'original_escrow' ? 'escrow' : 'demo_issuance')->id => $amount],
                $admin, $data['reason'], ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => $kind, 'source_reserve_entry_id' => $id,
                    'source_wallet_transaction_id' => $entry->wallet_transaction_id, 'finding_reference' => $finding->reference, 'demo_only' => true],
                orderId: $plan->order_id, returnId: $plan->buyer_return_case_id, token: $uuid);
            WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                'shipment_financial_plan_id' => $plan->id, 'buyer_return_repayment_plan_id' => null, 'wallet_transaction_id' => $transaction->id,
                'wallet_account_id' => $entry->wallet_account_id, 'source_user_id' => null, 'source_kind' => $kind, 'delta_cents' => -$amount,
                'context' => ['source_reserve_entry_id' => $id, 'demo_only' => true], 'created_at' => now()]);
            $event = ShipmentFinancialEvent::create(['shipment_financial_plan_id' => $plan->id, 'actor_id' => $admin->id, 'event_type' => 'loss-return-platform-source',
                'sequence' => $version + 1, 'amount_cents' => $amount, 'source_event_id' => $state['events'][$id]->id, 'wallet_transaction_id' => $transaction->id,
                'reason' => $data['reason'], 'request_token_hash' => $token, 'request_fingerprint' => $fingerprint,
                'context' => ['plan_reference' => $plan->reference, 'wallet_reference' => $transaction->reference, 'source_reserve_entry_id' => $id,
                    'finding_reference' => $finding->reference, 'no_buyer_repayment' => true, 'no_parcel_release' => true], 'created_at' => now()]);
            $this->positions($plan);

            return $event;
        }, 3);
    }
}
