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
            $loss = $entry->shipment_financial_plan_id !== null;
            $plan = $loss ? ShipmentFinancialPlan::find($entry->shipment_financial_plan_id)
                : BuyerReturnRepaymentPlan::find($entry->buyer_return_repayment_plan_id);
            $transaction = WalletTransaction::find($entry->wallet_transaction_id);
            $account = WalletAccount::find($entry->wallet_account_id);
            $journal = WalletEntry::where('wallet_transaction_id', $entry->wallet_transaction_id)->where('wallet_account_id', $entry->wallet_account_id)->first();
            $deposit = in_array($entry->source_kind, ['original_escrow', 'original_commission', 'liable_seller', 'liable_company', 'advance'], true);
            $liable = in_array($entry->source_kind, ['liable_seller', 'liable_company'], true);
            $authority = $loss ? $plan?->kind === 'transit_loss' && $entry->buyer_return_repayment_plan_id === null
                && ($transaction?->context['shipment_financial_plan_reference'] ?? null) === $plan->reference
                && ! in_array($entry->source_kind, ['original_commission', 'liable_seller'], true)
                : $plan && ($transaction?->context['repayment_plan_reference'] ?? null) === $plan->reference;
            abort_unless(DB::transactionLevel() > 0 && $plan && $authority && $plan->reference === $entry->authority_reference && $plan->order_id === $entry->order_id
                && $transaction?->order_id === $entry->order_id && $transaction->buyer_return_case_id === $plan->buyer_return_case_id
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
            if ($loss) {
                if ($deposit) {
                    $source = match ($entry->source_kind) {
                        'original_escrow' => WalletAccount::where('account_key', 'platform:escrow')->where('kind', 'escrow')->whereNull('user_id')->first(),
                        'advance' => WalletAccount::where('account_key', 'platform:demo_issuance')->where('kind', 'demo_issuance')->whereNull('user_id')->first(),
                        'liable_company' => WalletAccount::where('user_id', $entry->source_user_id)->where('kind', 'user')->first(),
                        default => null,
                    };
                    abort_unless($transaction->kind === 'wallet_loss_funding' && $transaction->amount_cents === $entry->delta_cents
                        && $source && ($transaction->context['source_account_id'] ?? null) === $source->id
                        && WalletEntry::where('wallet_transaction_id', $transaction->id)->where('wallet_account_id', $source->id)
                            ->where('delta_cents', -$entry->delta_cents)->exists()
                        && ($entry->source_kind !== 'liable_company' || LogisticsCompany::whereKey($plan->logistics_company_id)->where('user_id', $entry->source_user_id)->exists()), 409,
                        'Retain the actual original escrow, explicit issuance or named company debit behind this loss reserve.');
                } elseif ($entry->source_kind === 'refund') {
                    abort_unless($transaction->kind === 'wallet_loss_refund' && $transaction->amount_cents === $plan->snapshot['buyer_refund_cents']
                        && $entry->delta_cents === -$plan->snapshot['reserve_goal_cents'], 409,
                        'The original loss reserve must pay its separately approved buyer, seller and earned-shipping total.');
                } else {
                    $issuance = WalletAccount::where('account_key', 'platform:demo_issuance')->where('kind', 'demo_issuance')->whereNull('user_id')->first();
                    abort_unless($transaction->kind === 'wallet_loss_advance_retirement' && $transaction->amount_cents === -$entry->delta_cents && $issuance
                        && WalletEntry::where('wallet_transaction_id', $transaction->id)->where('wallet_account_id', $issuance->id)
                            ->where('delta_cents', -$entry->delta_cents)->exists(), 409,
                        'Retiring an actual advance must return its credits to the original demonstration issuance account.');
                }
            }
        });
    }
}
