<?php

namespace App\Models;

use App\Models\Concerns\RetainsWalletEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletRefundReserveEntry extends Model
{
    use RetainsWalletEvidence;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['delta_cents' => 'integer', 'context' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $entry) {
            $plan = BuyerReturnRepaymentPlan::find($entry->buyer_return_repayment_plan_id);
            $transaction = WalletTransaction::find($entry->wallet_transaction_id);
            $account = WalletAccount::find($entry->wallet_account_id);
            $journal = WalletEntry::where('wallet_transaction_id', $entry->wallet_transaction_id)->where('wallet_account_id', $entry->wallet_account_id)->first();
            $deposit = in_array($entry->source_kind, ['original_escrow', 'original_commission', 'liable_seller', 'liable_company', 'advance'], true);
            $liable = in_array($entry->source_kind, ['liable_seller', 'liable_company'], true);
            abort_unless(DB::transactionLevel() > 0 && $plan && $plan->reference === $entry->authority_reference && $plan->order_id === $entry->order_id
                && $transaction?->order_id === $entry->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
                && ($transaction->context['repayment_plan_reference'] ?? null) === $plan->reference
                && ($transaction->context['reserve_source_kind'] ?? null) === $entry->source_kind
                && $account?->account_key === 'platform:return_reserve' && $account->kind === 'return_reserve' && $account->user_id === null
                && ($deposit || in_array($entry->source_kind, ['refund', 'advance_retirement'], true))
                && is_int($entry->delta_cents) && $entry->delta_cents !== 0 && ($entry->delta_cents > 0) === $deposit
                && ($entry->source_user_id !== null) === $liable && $journal && $journal->delta_cents === $entry->delta_cents, 409,
                'The virtual reserve must match its actual balanced journal and approved original plan.');
            if ($entry->source_user_id) {
                $source = WalletAccount::where('user_id', $entry->source_user_id)->where('kind', 'user')->first();
                abort_unless($transaction->actor_id === $entry->source_user_id && $source
                    && WalletEntry::where('wallet_transaction_id', $transaction->id)->where('wallet_account_id', $source->id)
                        ->where('delta_cents', -$entry->delta_cents)->exists(), 409,
                    'The named liable account must actually fund its original virtual reserve.');
            }
        });
    }
}
