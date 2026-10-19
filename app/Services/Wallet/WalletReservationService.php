<?php

namespace App\Services\Wallet;

use App\Models\Order;
use App\Models\SavedVoucher;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRevisionBalance;
use App\Models\WalletPayment;
use Illuminate\Support\Facades\DB;

class WalletReservationService
{
    /** Retain a system release separately from user-authored cash funding evidence. */
    public function release(Order $order, string $reason): void
    {
        $payment = $order->walletPayment;
        abort_unless(DB::transactionLevel() > 0 && $payment && ! $payment->wallet_transaction_id
            && $payment->status === 'pending' && $payment->expires_at->lessThanOrEqualTo(now()), 409);
        $allocations = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->orderBy('id')->get();
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $allocations->pluck('redemption.voucher_revision_id')->unique())
            ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
        foreach ($allocations as $allocation) {
            if (DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $allocation->id)->exists()) {
                continue;
            }
            $entries = $allocation->fundingEntries;
            $commitment = $entries->firstWhere('event_type', 'commitment');
            abort_unless($commitment && ! $entries->contains(fn ($entry) => in_array($entry->event_type, ['expense', 'release'], true))
                && $commitment->amount_cents === $allocation->seller_expense_cents + $allocation->platform_expense_cents, 409,
                'The original reserved voucher funding needs review.');
            $balance = $balances[$allocation->redemption->voucher_revision_id] ?? null;
            abort_unless($balance && $balance->committed_cents >= $commitment->amount_cents, 409);
            DB::table('wallet_voucher_releases')->insert(['voucher_order_allocation_id' => $allocation->id,
                'wallet_payment_id' => $payment->id, 'source_entry_id' => $commitment->id,
                'amount_cents' => $commitment->amount_cents, 'reason' => $reason, 'created_at' => now()]);
            $balance->decrement('committed_cents', $commitment->amount_cents);
            $balance->increment('released_cents', $commitment->amount_cents);
        }
    }

    public function restoreUses(Order $order): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $allocations = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->orderBy('id')->get();
        foreach ($allocations->unique('voucher_redemption_id') as $allocation) {
            $redemption = $allocation->redemption;
            $balance = VoucherRevisionBalance::where('voucher_revision_id', $redemption->voucher_revision_id)->lockForUpdate()->firstOrFail();
            if (DB::table('wallet_voucher_restorations')->where('voucher_redemption_id', $redemption->id)->exists()) {
                continue;
            }
            $all = VoucherOrderAllocation::where('voucher_redemption_id', $redemption->id)->with('fundingEntries')->get();
            $payments = WalletPayment::whereIn('order_id', $all->pluck('order_id'))->get();
            if ($payments->count() !== $all->count() || $payments->contains(fn ($payment) => $payment->wallet_transaction_id
                || $payment->paid_at || ! in_array($payment->status, ['expired', 'cancelled'], true))
                || Order::whereIn('id', $all->pluck('order_id'))->where('status', '!=', 'cancelled')->exists()) {
                continue;
            }
            $allReleased = $all->every(fn ($part) => $part->fundingEntries->contains('event_type', 'release')
                || DB::table('wallet_voucher_releases')->where('voucher_order_allocation_id', $part->id)->exists());
            abort_unless($allReleased && $balance->redemption_count > 0, 409, 'The original voucher reservation needs review before restoring its use.');
            DB::table('wallet_voucher_restorations')->insert(['voucher_redemption_id' => $redemption->id,
                'payment_references' => json_encode($payments->pluck('reference')->all(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $balance->decrement('redemption_count');
            $voucher = $redemption->revision->voucher;
            abort_unless($voucher && $voucher->used_count > 0, 409);
            $voucher->decrement('used_count');
            SavedVoucher::firstOrCreate(['buyer_id' => $redemption->buyer_id, 'voucher_revision_id' => $redemption->voucher_revision_id]);
        }
    }
}
