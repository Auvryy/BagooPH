<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Models\VoucherRevision;
use App\Services\BuyerAccessService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Commerce\InventoryService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class CheckoutQuoteService
{
    public function selectedItems(User $buyer, array $ids): Collection
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($buyer);
        $ids = app(CommerceInputService::class)->selection($ids);
        $cart = Cart::where('user_id', $buyer->id)->first();
        $items = $cart?->items()->whereIn('id', $ids)->with('product.shop')->orderBy('id')->get() ?? collect();
        if ($items->count() !== count($ids)) {
            throw new CheckoutException('One or more selected Shopping Bag items are unavailable.');
        }
        $eligible = Product::availableForSale()->whereIn('id', $items->pluck('product_id'))->pluck('id');
        if ($items->contains(fn (CartItem $item) => ! $eligible->contains($item->product_id))) {
            throw new CheckoutException('One or more selected products or shops are unavailable.');
        }
        try {
            app(InventoryService::class)->assertCheckoutAvailability($items, $items->pluck('product')->keyBy('id'));
        } catch (RuntimeException $exception) {
            throw new CheckoutException($exception->getMessage(), previous: $exception);
        }

        return $items;
    }

    /** Current prices come from selectedItems or the locked final checkout. */
    public function quote(Collection $items, string $deliveryType = 'doorstep', Voucher|Collection|null $selection = null, ?User $buyer = null): array
    {
        $revisions = $selection instanceof Voucher ? collect([$selection->currentRevision]) : ($selection ?? collect());
        $shops = $items->groupBy(fn (CartItem $item) => $item->product->shop_id)->sortKeys()->map(function (Collection $group, int $shopId) use ($deliveryType) {
            $subtotal = $group->sum(fn (CartItem $item) => CodMoney::cents($item->product->price) * $item->quantity);
            $doorstep = $subtotal > 150000 ? 0 : 5000;

            return ['shop_id' => $shopId, 'shop_name' => $group->first()->product->shop->name, 'subtotal' => $subtotal,
                'doorstep_shipping' => $doorstep, 'shipping' => $deliveryType === 'hub_self_pickup' ? 0 : $doorstep];
        });
        $productDiscounts = $shops->map(fn () => 0);
        $shippingDiscounts = $shops->map(fn () => 0);
        $redemptions = [];
        $reason = $revisions->contains(null) ? 'This older voucher needs its funding reviewed before use.' : null;
        if (! $reason && ($revisions->where('issuer_type', 'platform')->count() > 1
            || $revisions->where('issuer_type', 'shop')->pluck('shop_id')->duplicates()->isNotEmpty())) {
            $reason = 'Choose one offer per shop and one Bagoo offer for this checkout.';
        }
        // Shop reductions come first, independent of request/map order.
        foreach ($revisions->filter()->sortBy(fn ($r) => ($r->issuer_type === 'shop' ? '0' : '1').str_pad((string) $r->id, 20, '0', STR_PAD_LEFT)) as $revision) {
            if ($reason) {
                break;
            }
            $reason = $this->unavailableReason($revision, $shops, $buyer);
            if ($reason) {
                break;
            }
            $shipping = $revision->discount_type === 'free_shipping';
            $scope = $revision->issuer_type === 'shop' ? $shops->only([$revision->shop_id]) : $shops;
            $weights = $scope->map(fn ($shop, $id) => $shipping ? $shop['shipping'] - $shippingDiscounts[$id] : $shop['subtotal'] - $productDiscounts[$id]);
            $basis = $weights->sum();
            $amount = $revision->discount_type === 'percent'
                ? intdiv($basis * $revision->percentage_bps + 5000, 10000)
                : ($shipping && $revision->value_cents === 0 ? $basis : $revision->value_cents);
            $amount = min($basis, $amount, $revision->maximum_cents);
            if ($revision->issuer_type === 'shop') {
                $gross = SellerSettlementService::split($scope->sum('subtotal'))['seller_cents'];
                $amount = min($amount, $gross);
            }
            if ($amount <= 0) {
                $reason = $shipping ? 'There is no shipping fee for this voucher to reduce.' : 'This voucher does not reduce the selected item total.';
                break;
            }
            $balance = $revision->balance;
            if ($amount > $revision->budget_cents - $balance->committed_cents - $balance->spent_cents) {
                $reason = 'This voucher has reached its available funding limit.';
                break;
            }
            $parts = $this->allocate($amount, $weights);
            $allocations = [];
            foreach ($parts as $shopId => $part) {
                if ($part === 0) {
                    continue;
                }
                if ($shipping) {
                    $shippingDiscounts->put($shopId, $shippingDiscounts[$shopId] + $part);
                } else {
                    $productDiscounts->put($shopId, $productDiscounts[$shopId] + $part);
                }
                $allocations[] = ['shop_id' => $shopId, 'product_discount_cents' => $shipping ? 0 : $part,
                    'shipping_discount_cents' => $shipping ? $part : 0,
                    'seller_expense_cents' => $revision->funding_party === 'shop' ? $part : 0,
                    'platform_expense_cents' => $revision->funding_party === 'platform' ? $part : 0];
            }
            $redemptions[] = ['revision_id' => $revision->id, 'discount_cents' => $amount, 'allocations' => $allocations];
        }
        if ($reason) {
            $productDiscounts = $shops->map(fn () => 0);
            $shippingDiscounts = $shops->map(fn () => 0);
            $redemptions = [];
        }
        $shopQuotes = $shops->map(function ($shop, $id) use ($productDiscounts, $shippingDiscounts) {
            $discount = $productDiscounts[$id] + $shippingDiscounts[$id];

            return [...$shop, 'subtotal' => $shop['subtotal'] / 100, 'shipping' => $shop['shipping'] / 100,
                'doorstep_shipping' => $shop['doorstep_shipping'] / 100, 'discount' => $discount / 100,
                'product_discount' => $productDiscounts[$id] / 100, 'shipping_discount' => $shippingDiscounts[$id] / 100,
                'total' => ($shop['subtotal'] + $shop['shipping'] - $discount) / 100];
        })->values()->all();
        $discount = $productDiscounts->sum() + $shippingDiscounts->sum();
        $presented = $reason ? [] : $revisions->map(fn ($r) => $this->present($r))->values()->all();

        return ['subtotal' => $shops->sum('subtotal') / 100, 'shipping_fee' => $shops->sum('shipping') / 100,
            'doorstep_shipping_fee' => $shops->sum('doorstep_shipping') / 100, 'discount' => $discount / 100,
            'shipping_discount' => $shippingDiscounts->sum() / 100,
            'total' => ($shops->sum('subtotal') + $shops->sum('shipping') - $discount) / 100, 'shops' => $shopQuotes,
            'items' => $items->map(fn (CartItem $item) => ['id' => $item->id, 'quantity' => $item->quantity,
                'unit_price' => CodMoney::cents($item->product->price) / 100,
                'subtotal' => CodMoney::cents($item->product->price) * $item->quantity / 100])->values()->all(),
            'voucher' => count($presented) === 1 ? $presented[0] : null, 'vouchers' => $presented,
            'voucher_error' => $reason, 'redemptions' => $redemptions];
    }

    public function offers(?Collection $items = null, string $deliveryType = 'doorstep', int $limit = 24, ?User $buyer = null, ?Collection $selected = null): array
    {
        $query = VoucherRevision::with(['voucher', 'shop:id,name', 'balance'])
            ->whereHas('voucher', fn (Builder $q) => $q->where('is_active', true)->whereNull('archived_at')->whereColumn('current_revision_id', 'voucher_revisions.id'))
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->whereHas('balance', fn (Builder $q) => $q->whereColumn('redemption_count', '<', 'voucher_revisions.global_limit')
                ->whereRaw('committed_cents + spent_cents < voucher_revisions.budget_cents'))
            ->where(fn (Builder $q) => $q->where('issuer_type', 'platform')->orWhereHas('shop', fn (Builder $shop) => $shop->eligible()));
        if ($items !== null) {
            $query->where(fn (Builder $q) => $q->where('issuer_type', 'platform')->orWhereIn('shop_id', $items->pluck('product.shop_id')->unique()));
        }
        if ($buyer) {
            $query->whereRaw('(select count(*) from voucher_redemptions where voucher_revision_id = voucher_revisions.id and buyer_id = ?) < voucher_revisions.per_buyer_limit', [$buyer->id]);
        }
        $count = (clone $query)->count();
        $offers = $query->orderByDesc('id')->limit($limit)->get()->map(function (VoucherRevision $revision) use ($items, $deliveryType, $buyer, $selected) {
            $candidate = ($selected ?? collect())->reject(fn ($current) => $current->issuer_type === $revision->issuer_type && $current->shop_id === $revision->shop_id)->push($revision);
            $quote = $items !== null ? $this->quote($items, $deliveryType, $candidate, $buyer) : null;
            $saving = $quote ? collect($quote['redemptions'])->firstWhere('revision_id', $revision->id)['discount_cents'] ?? 0 : null;

            return [...$this->present($revision), 'eligible' => $quote ? $quote['voucher_error'] === null : null,
                'unavailable_reason' => $quote['voucher_error'] ?? null, 'saving' => $saving === null ? null : $saving / 100];
        })->all();

        return ['offers' => $offers, 'count' => $count];
    }

    private function present(VoucherRevision $revision): array
    {
        return ['id' => $revision->voucher_id, 'revision_id' => $revision->id, 'code' => $revision->code,
            'name' => $revision->name, 'description' => $revision->description, 'discount_type' => $revision->discount_type,
            'discount_value' => $revision->discount_type === 'percent' ? $revision->percentage_bps / 100 : $revision->value_cents / 100,
            'min_spend' => $revision->minimum_cents / 100, 'max_discount' => $revision->maximum_cents / 100,
            'shop_id' => $revision->shop_id, 'shop_name' => $revision->shop?->name,
            'issuer' => $revision->issuer_type, 'expires_at' => $revision->ends_at->toIso8601String()];
    }

    private function unavailableReason(VoucherRevision $revision, Collection $shops, ?User $buyer): ?string
    {
        $voucher = $revision->voucher;
        if (! $voucher || $voucher->current_revision_id !== $revision->id || $voucher->archived_at
            || $voucher->issuer_type !== $revision->issuer_type || $voucher->shop_id !== $revision->shop_id) {
            return 'This voucher is unavailable. Remove it or choose another voucher.';
        }
        if (! $voucher->is_active) {
            return 'This voucher is paused.';
        }
        if ($revision->starts_at->isFuture()) {
            return 'This voucher is not available yet.';
        }
        if ($revision->ends_at->lessThanOrEqualTo(now())) {
            return 'This voucher has expired.';
        }
        $balance = $revision->balance;
        if (! $balance || $balance->redemption_count >= $revision->global_limit) {
            return 'This voucher has reached its usage limit.';
        }
        if ($buyer && VoucherRedemption::where('voucher_revision_id', $revision->id)->where('buyer_id', $buyer->id)->count() >= $revision->per_buyer_limit) {
            return 'You have reached your usage limit for this voucher.';
        }
        if ($revision->issuer_type === 'shop' && (! $shops->has($revision->shop_id) || ! $revision->shop
            || ! app(ShopEligibilityService::class)->isEligible($revision->shop))) {
            return 'This voucher is not for the selected shop items.';
        }
        $subtotal = ($revision->issuer_type === 'shop' ? $shops->only([$revision->shop_id]) : $shops)->sum('subtotal');
        if ($subtotal < $revision->minimum_cents) {
            $remaining = number_format(($revision->minimum_cents - $subtotal) / 100, 2);

            return $revision->issuer_type === 'shop' ? "Add ₱{$remaining} from this shop to use this voucher." : "Add ₱{$remaining} in selected products to use this voucher.";
        }

        return null;
    }

    private function allocate(int $amount, Collection $weights): Collection
    {
        $parts = $weights->map(fn ($weight) => $this->proportion($amount, $weight, $weights->sum()));
        $left = $amount - $parts->sum();
        foreach ($weights as $id => $weight) {
            if ($left > 0 && $parts[$id] < $weight) {
                $parts->put($id, $parts[$id] + 1);
                $left--;
            }
        }

        return $parts;
    }

    /** Exact floor(amount * weight / total), without a large integer product. */
    private function proportion(int $amount, int $weight, int $total): int
    {
        $result = $remainder = 0;
        foreach (str_split((string) $amount) as $digit) {
            $step = $remainder * 10 + (int) $digit * $weight;
            $result = $result * 10 + intdiv($step, $total);
            $remainder = $step % $total;
        }

        return $result;
    }
}
