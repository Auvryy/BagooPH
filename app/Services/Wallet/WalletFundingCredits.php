<?php

namespace App\Services\Wallet;

use App\Models\User;
use App\Models\WalletEntry;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Schema;

class WalletFundingCredits
{
    /** Reviewed liability grants remain available to their original authority until its actual owned contribution. */
    public function available(User $owner, string $authority): int
    {
        $balance = app(WalletLedger::class)->balance($owner);
        abort_unless($balance !== null && $balance >= 0, 409, 'Review the actual owned Wallet journal before funding an original obligation.');
        if (! Schema::hasTable('wallet_refund_credit_requests')) {
            return $balance;
        }
        $grants = WalletRefundCreditRequest::where('actor_id', $owner->id)->where('status', 'approved')->get();
        if ($grants->isEmpty()) {
            return $balance;
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
            $movements[] = ['id' => $transaction->id, 'authority' => $grant->authority_reference, 'delta' => $grant->amount_cents];
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
            $movements[] = ['id' => $transaction->id, 'authority' => $entry->authority_reference, 'delta' => -$entry->delta_cents];
        }
        usort($movements, fn ($left, $right) => $left['id'] <=> $right['id']);
        $unspent = [];
        foreach ($movements as $movement) {
            $reference = $movement['authority'];
            // An earlier contribution cannot consume credits that were issued later.
            $unspent[$reference] = max(0, ($unspent[$reference] ?? 0) + $movement['delta']);
        }
        abort_unless(array_sum($unspent) <= $balance, 409,
            'Reviewed credits for an original obligation are missing from the owned Wallet. Reconcile their actual use before new funding.');

        return $balance - array_sum($unspent) + ($unspent[$authority] ?? 0);
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
