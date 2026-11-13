<?php

namespace App\Services\Wallet;

use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class WalletFundingCredits
{
    /** Reviewed liability grants remain available to their original authority until its actual owned contribution. */
    public function available(User $owner, string $authority): int
    {
        $state = $this->positions($owner);

        return $state['balance'] - array_sum($state['unspent']) + ($state['unspent'][$authority] ?? 0);
    }

    public function grantBalances(User $owner): array
    {
        return $this->positions($owner)['remaining'];
    }

    private function positions(User $owner): array
    {
        $balance = app(WalletLedger::class)->balance($owner);
        abort_unless($balance !== null && $balance >= 0, 409, 'Review the actual owned Wallet journal before funding an original obligation.');
        if (! Schema::hasTable('wallet_refund_credit_requests')) {
            return ['balance' => $balance, 'unspent' => [], 'remaining' => []];
        }
        $grants = WalletRefundCreditRequest::where('actor_id', $owner->id)->where('status', 'approved')->orderBy('id')->get()->keyBy('id');
        if ($grants->isEmpty()) {
            return ['balance' => $balance, 'unspent' => [], 'remaining' => []];
        }
        $movements = [];
        foreach ($grants as $grant) {
            $transaction = WalletTransaction::find($grant->wallet_transaction_id);
            $loss = $grant->shipment_financial_plan_id !== null;
            $key = $loss ? 'shipment_financial_plan_reference' : 'repayment_plan_reference';
            abort_unless($transaction && $grant->amount_cents > 0 && $grant->reviewer_id && $grant->reviewer_id !== $owner->id
                && $transaction->actor_id === $grant->reviewer_id && $transaction->amount_cents === $grant->amount_cents
                && $transaction->kind === ($loss ? 'demo_loss_funding_credit' : 'demo_return_funding_credit')
                && $transaction->business_key === ($loss ? 'loss-funding-credit:' : 'return-funding-credit:').$grant->id
                && ($transaction->context[$key] ?? null) === $grant->authority_reference
                && ($transaction->context['funding_credit_request_id'] ?? null) === $grant->id
                && ($transaction->context['source_actor_id'] ?? null) === $owner->id, 409,
                'Retain the original reviewed grant and its exact actual owned issuance before using funding credits.');
            $this->journal($transaction, $owner, $grant->amount_cents, 'demo_issuance');
            $movements[] = ['id' => $transaction->id, 'authority' => $grant->authority_reference,
                'type' => 'issue', 'grant_id' => $grant->id, 'amount' => $grant->amount_cents];
        }
        $contributions = WalletRefundReserveEntry::where('source_user_id', $owner->id)->whereIn('source_kind', ['liable_company', 'liable_seller'])
            ->where('delta_cents', '>', 0)->whereIn('authority_reference', $grants->pluck('authority_reference'))->get();
        foreach ($contributions as $entry) {
            $transaction = WalletTransaction::find($entry->wallet_transaction_id);
            $loss = $entry->shipment_financial_plan_id !== null;
            $key = $loss ? 'shipment_financial_plan_reference' : 'repayment_plan_reference';
            abort_unless($transaction && $transaction->actor_id === $owner->id && $transaction->amount_cents === $entry->delta_cents
                && $transaction->order_id === $entry->order_id && $transaction->kind === ($loss ? 'wallet_loss_funding' : 'wallet_return_funding')
                && ($transaction->context[$key] ?? null) === $entry->authority_reference
                && ($transaction->context['reserve_source_kind'] ?? null) === $entry->source_kind, 409,
                'Retain the original actual owned contribution before consuming its reviewed funding credits.');
            $this->journal($transaction, $owner, -$entry->delta_cents, 'return_reserve');
            $movements[] = ['id' => $transaction->id, 'authority' => $entry->authority_reference, 'type' => 'contribute', 'amount' => $entry->delta_cents];
        }
        $movements = [...$movements, ...$this->returnedGrants($owner, $grants)];
        usort($movements, fn ($left, $right) => $left['id'] <=> $right['id']);
        $remaining = [];
        $issued = [];
        foreach ($movements as $movement) {
            if ($movement['type'] === 'issue') {
                $remaining[$movement['grant_id']] = $movement['amount'];
                $issued[] = $movement['grant_id'];
            } elseif ($movement['type'] === 'return') {
                abort_unless(($remaining[$movement['grant_id']] ?? 0) >= $movement['amount'], 409,
                    'A return cannot consume another grant or credits that were already contributed.');
                $remaining[$movement['grant_id']] -= $movement['amount'];
            } else {
                $left = $movement['amount'];
                foreach ($issued as $grantId) {
                    $grant = $grants->get($grantId);
                    if ($grant->authority_reference !== $movement['authority']) {
                        continue;
                    }
                    $used = min($left, $remaining[$grant->id] ?? 0);
                    $remaining[$grant->id] = ($remaining[$grant->id] ?? 0) - $used;
                    $left -= $used;
                    if ($left === 0) {
                        break;
                    }
                }
            }
        }
        $unspent = [];
        foreach ($grants as $grant) {
            $reference = $grant->authority_reference;
            $unspent[$reference] = ($unspent[$reference] ?? 0) + ($remaining[$grant->id] ?? 0);
        }
        abort_unless(array_sum($unspent) <= $balance, 409,
            'Reviewed credits for an original obligation are missing from the owned Wallet. Reconcile their actual use before new funding.');

        return ['balance' => $balance, 'unspent' => $unspent, 'remaining' => $remaining];
    }

    private function returnedGrants(User $owner, Collection $grants): array
    {
        $owned = WalletEntry::join('wallet_accounts as grant_owner', 'grant_owner.id', '=', 'wallet_entries.wallet_account_id')
            ->where('grant_owner.user_id', $owner->id)->where('grant_owner.kind', 'user')->select('wallet_entries.wallet_transaction_id');
        $returns = WalletTransaction::where('kind', 'demo_loss_credit_return')->whereIn('id', $owned)->get();
        $movements = [];
        foreach ($returns as $transaction) {
            $grant = $grants->get($transaction->context['funding_credit_request_id'] ?? 0);
            $plan = $grant?->shipment_financial_plan_id ? ShipmentFinancialPlan::find($grant->shipment_financial_plan_id) : null;
            $events = ShipmentFinancialEvent::where('wallet_transaction_id', $transaction->id)->where('event_type', 'loss-return-unused-credit')->get();
            $event = $events->first();
            $source = $event?->source_event_id ? ShipmentFinancialEvent::find($event->source_event_id) : null;
            $finding = $plan ? ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)
                ->where('reference', $transaction->context['finding_reference'] ?? '')->first() : null;
            abort_unless($grant && $plan && $finding && $events->count() === 1 && $transaction->amount_cents > 0
                && $transaction->actor_id === $owner->id && $transaction->order_id === $plan->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
                && $transaction->business_key === 'loss-credit-return:'.$grant->id.':'.$transaction->request_token_hash
                && ($transaction->context['shipment_financial_plan_reference'] ?? null) === $grant->authority_reference
                && ($transaction->context['source_actor_id'] ?? null) === $owner->id
                && ($transaction->context['source_wallet_transaction_id'] ?? null) === $grant->wallet_transaction_id
                && $event->shipment_financial_plan_id === $plan->id && $event->actor_id === $owner->id && $event->recipient_id === null
                && $event->amount_cents === $transaction->amount_cents && $event->request_token_hash === $transaction->request_token_hash
                && ($event->context['wallet_reference'] ?? null) === $transaction->reference
                && ($event->context['plan_reference'] ?? null) === $plan->reference
                && ($event->context['funding_credit_request_id'] ?? null) === $grant->id
                && ($event->context['finding_reference'] ?? null) === $finding->reference
                && $source && $source->shipment_financial_plan_id === $plan->id && $source->event_type === 'loss-credit-approved'
                && $source->actor_id === $grant->reviewer_id && $source->recipient_id === $owner->id
                && $source->wallet_transaction_id === $grant->wallet_transaction_id && $source->amount_cents === $grant->amount_cents
                && $source->request_token_hash === $grant->review_token_hash && $source->request_fingerprint === $grant->review_fingerprint
                && ($source->context['funding_credit_request_id'] ?? null) === $grant->id
                && $source->sequence < $finding->financial_version + 1 && $finding->financial_version + 1 < $event->sequence
                && $plan->events()->where('event_type', 'loss-found-quarantined')->where('sequence', $finding->financial_version + 1)
                    ->where('actor_id', $finding->actor_id)->where('context->finding_reference', $finding->reference)->where('amount_cents', 0)->exists(), 409,
                'Retain the original owned grant, actual quarantine, exact source approval and balanced return journal.');
            $this->journal($transaction, $owner, -$transaction->amount_cents, 'demo_issuance');
            $movements[] = ['id' => $transaction->id, 'authority' => $grant->authority_reference,
                'type' => 'return', 'grant_id' => $grant->id, 'amount' => $transaction->amount_cents];
        }

        return $movements;
    }

    private function journal(WalletTransaction $transaction, User $owner, int $ownerDelta, string $platform): void
    {
        $expected = ['user:'.$owner->id => [$ownerDelta, 'user', $owner->id],
            'platform:'.$platform => [-$ownerDelta, $platform, null]];
        $actual = [];
        foreach (WalletEntry::where('wallet_transaction_id', $transaction->id)
            ->join('wallet_accounts', 'wallet_accounts.id', '=', 'wallet_entries.wallet_account_id')
            ->get(['wallet_accounts.account_key', 'wallet_accounts.kind', 'wallet_accounts.user_id', 'wallet_entries.delta_cents']) as $entry) {
            abort_unless(isset($expected[$entry->account_key]) && $expected[$entry->account_key][1] === $entry->kind
                && $expected[$entry->account_key][2] === $entry->user_id, 409,
                'Retain the exact original owned and platform funding accounts.');
            $actual[$entry->account_key] = [($actual[$entry->account_key][0] ?? 0) + $entry->delta_cents, $entry->kind, $entry->user_id];
        }
        ksort($expected);
        ksort($actual);
        abort_unless($actual === $expected, 409, 'Retain the exact balanced original grant or contribution journal before using its credits.');
    }
}
