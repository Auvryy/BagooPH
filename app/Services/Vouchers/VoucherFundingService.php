<?php

namespace App\Services\Vouchers;

use App\Models\CheckoutSubmission;
use App\Models\Order;
use App\Models\SellerSettlementEvent;
use App\Models\User;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRedemption;
use App\Models\VoucherRevisionBalance;
use App\Services\Finance\CodMoney;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherFundingService
{
    /** Called only inside the final checkout transaction, with campaign balances locked. */
    public function commit(User $buyer, CheckoutSubmission $submission, Collection $orders, Collection $revisions, array $quotes): void
    {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('Voucher commitments require the complete checkout transaction.');
        }
        $byShop = $orders->keyBy(fn ($order) => $order->items->first()->shop_id);
        foreach ($quotes as $quote) {
            $revision = $revisions->firstWhere('id', $quote['revision_id']);
            $redemption = VoucherRedemption::create(['buyer_id' => $buyer->id, 'voucher_revision_id' => $revision->id,
                'checkout_submission_id' => $submission->id, 'discount_cents' => $quote['discount_cents'], 'terms' => $revision->terms()]);
            foreach ($quote['allocations'] as $part) {
                $order = $byShop[$part['shop_id']];
                unset($part['shop_id']);
                $allocation = VoucherOrderAllocation::create($part + ['voucher_redemption_id' => $redemption->id,
                    'order_id' => $order->id, 'delivery_id' => $order->delivery->id]);
                $allocation->fundingEntries()->create(['reference' => 'VFD-'.strtoupper((string) Str::uuid()),
                    'event_type' => 'commitment', 'amount_cents' => $allocation->seller_expense_cents + $allocation->platform_expense_cents,
                    'actor_id' => $buyer->id, 'reason' => 'Funding reserved for this original checkout allocation.']);
            }
            $balance = $revision->balance;
            $balance->increment('redemption_count');
            $balance->increment('committed_cents', $quote['discount_cents']);
            $revision->voucher->increment('used_count');
        }
    }

    public function amounts(Order $order): array
    {
        $allocations = VoucherOrderAllocation::where('order_id', $order->id)->with(['redemption.revision', 'fundingEntries'])->orderBy('id')->get();
        $seller = $allocations->sum('seller_expense_cents');
        $platform = $allocations->sum('platform_expense_cents');
        $discount = CodMoney::cents($order->voucher_discount);
        $review = [];
        if ($seller + $platform !== $discount || ($discount > 0 && $order->voucher_accounting_version !== 1)) {
            $review[] = 'The original discount needs an explicit funding review before seller release.';
        }
        foreach ($allocations as $allocation) {
            $commitment = $allocation->fundingEntries->firstWhere('event_type', 'commitment');
            $terms = $allocation->redemption->terms;
            if (! $commitment || $commitment->amount_cents !== $allocation->seller_expense_cents + $allocation->platform_expense_cents
                || $allocation->product_discount_cents + $allocation->shipping_discount_cents !== $commitment->amount_cents
                || $allocation->delivery_id !== $order->delivery?->id || $allocation->redemption->buyer_id !== $order->buyer_id
                || ($terms['funding_party'] === 'shop' && ($allocation->platform_expense_cents !== 0
                    || $terms['shop_id'] !== $order->items->first()?->shop_id || $allocation->shipping_discount_cents !== 0))
                || ($terms['funding_party'] === 'platform' && $allocation->seller_expense_cents !== 0)
                || $allocation->fundingEntries->contains('event_type', 'release')) {
                $review[] = 'The original promotion commitment requires evidence review.';
            }
        }

        return ['seller_promotion_cents' => $seller, 'platform_promotion_cents' => $platform,
            'promotion_blockers' => array_values(array_unique($review)),
            'promotion_snapshot' => $allocations->map(fn ($a) => ['allocation_id' => $a->id,
                'redemption_id' => $a->voucher_redemption_id, 'revision_id' => $a->redemption->voucher_revision_id,
                'code' => $a->redemption->terms['code'], 'issuer' => $a->redemption->terms['issuer_type'],
                'funding_party' => $a->redemption->terms['funding_party'], 'product_discount_cents' => $a->product_discount_cents,
                'shipping_discount_cents' => $a->shipping_discount_cents, 'seller_expense_cents' => $a->seller_expense_cents,
                'platform_expense_cents' => $a->platform_expense_cents])->all()];
    }

    public function releaseCancellation(Order $order, User $seller, string $reason): void
    {
        $seller = User::findOrFail($seller->id);
        $order = Order::with('items.shop', 'delivery')->findOrFail($order->id);
        abort_unless($seller->isSeller() && $seller->canAccessPortal()
            && $order->items->isNotEmpty() && $order->items->every(fn ($i) => $i->shop?->user_id === $seller->id)
            && in_array($order->status, ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging', 'ready_for_pickup'], true)
            && (! $order->delivery || ($order->delivery->status === 'unassigned' && ! $order->delivery->courier_id && ! $order->delivery->assigned_rider_id))
            && ! $order->codAccount, 403);
        $this->finishCommitments($order, $seller, 'release', $reason);
    }

    public function recordExpense(Order $order, User $admin, SellerSettlementEvent $event): void
    {
        $admin = User::findOrFail($admin->id);
        abort_unless($admin->isAdmin() && $admin->canAccessPortal() && $event->actor_id === $admin->id
            && $event->event_type === 'payment_recorded' && $event->settlement?->order_id === $order->id, 403);
        $this->finishCommitments($order, $admin, 'expense', 'Promotional expense recorded with the evidenced seller payment.', $event);
    }

    private function finishCommitments(Order $order, User $actor, string $type, string $reason, ?SellerSettlementEvent $event = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new DomainException('Funding changes require the authorized order transaction.');
        }
        $allocations = VoucherOrderAllocation::where('order_id', $order->id)->with('redemption')->orderBy('id')->get();
        $balances = VoucherRevisionBalance::whereIn('voucher_revision_id', $allocations->pluck('redemption.voucher_revision_id')->unique())
            ->orderBy('voucher_revision_id')->lockForUpdate()->get()->keyBy('voucher_revision_id');
        foreach ($allocations as $allocation) {
            $entries = $allocation->fundingEntries()->orderBy('id')->get();
            $source = $entries->firstWhere('event_type', 'commitment');
            if (! $source || $source->amount_cents !== $allocation->seller_expense_cents + $allocation->platform_expense_cents) {
                throw new DomainException('The original funding commitment requires review.');
            }
            if ($entries->contains('event_type', $type)) {
                continue;
            }
            if ($entries->contains(fn ($e) => in_array($e->event_type, ['expense', 'release'], true))) {
                throw new DomainException('This funding commitment has already been spent or released.');
            }
            $balance = $balances[$allocation->redemption->voucher_revision_id];
            if ($balance->committed_cents < $source->amount_cents) {
                throw new DomainException('The campaign funding balance requires review.');
            }
            $allocation->fundingEntries()->create(['reference' => 'VFD-'.strtoupper((string) Str::uuid()),
                'event_type' => $type, 'source_entry_id' => $source->id, 'settlement_event_id' => $event?->id,
                'actor_id' => $actor->id, 'amount_cents' => $source->amount_cents, 'reason' => $reason]);
            $balance->decrement('committed_cents', $source->amount_cents);
            $balance->increment($type === 'release' ? 'released_cents' : 'spent_cents', $source->amount_cents);
        }
    }
}
