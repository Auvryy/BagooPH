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
use App\Services\Commerce\SalePriceService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\SellerSettlementService;
use App\Services\ShopEligibilityService;
use App\Services\Vouchers\VoucherProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

class CheckoutQuoteService
{
    public function selectedItems(User $buyer, array $ids, mixed $quantities = []): Collection
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
        $items = $this->withQuantities($items, $quantities);
        try {
            app(InventoryService::class)->assertCheckoutAvailability($items, $items->pluck('product')->keyBy('id'), $buyer->id);
        } catch (RuntimeException $exception) {
            throw new CheckoutException($exception->getMessage(), previous: $exception);
        }

        return $items;
    }

    public function withQuantities(Collection $items, mixed $quantities = []): Collection
    {
        $quantities = app(CommerceInputService::class)->quantities($quantities, $items->pluck('id')->all());

        return $items->map(function (CartItem $item) use ($quantities) {
            $quantity = $quantities[$item->id] ?? $item->quantity;
            if ($quantity > $item->quantity) {
                throw new CheckoutException('A selected quantity exceeds the amount in your Bag. Review your selection.');
            }
            $copy = clone $item;
            $copy->quantity = $quantity;

            return $copy;
        });
    }

    /** Current prices come from selectedItems or the locked final checkout. */
    public function quote(Collection $items, string $deliveryType = 'doorstep', Voucher|Collection|null $selection = null, ?User $buyer = null): array
    {
        $revisions = $selection instanceof Voucher ? collect([$selection->currentRevision]) : ($selection ?? collect());
        $pricePlans = app(SalePriceService::class)->plan($items);
        $lineAmounts = collect($pricePlans)->map(fn ($plan) => collect($plan['price_segments'])
            ->sum(fn ($segment) => CodMoney::cents(number_format($segment['unit_price'], 2, '.', '')) * $segment['quantity']));
        $shops = $items->groupBy(fn (CartItem $item) => $item->product->shop_id)->sortKeys()->map(function (Collection $group, int $shopId) use ($deliveryType, $pricePlans) {
            $subtotal = $group->sum(fn (CartItem $item) => collect($pricePlans[$item->id]['price_segments'])
                ->sum(fn ($segment) => CodMoney::cents(number_format($segment['unit_price'], 2, '.', '')) * $segment['quantity']));
            $doorstep = $subtotal > 150000 ? 0 : 5000;

            return ['shop_id' => $shopId, 'shop_name' => $group->first()->product->shop->name, 'subtotal' => $subtotal,
                'doorstep_shipping' => $doorstep, 'shipping' => $deliveryType === 'hub_self_pickup' ? 0 : $doorstep];
        });
        $productDiscounts = $shops->map(fn () => 0);
        $shippingDiscounts = $shops->map(fn () => 0);
        $redemptions = [];
        $failedRevisionId = null;
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
            $failedRevisionId = $revision->id;
            $qualified = ($revision->product_scope ?? 'all') === 'selected'
                ? $this->selectedProductBasis($revision, $items, $lineAmounts, $shops) : null;
            $reason = $this->unavailableReason($revision, $shops, $buyer, $qualified['subtotal'] ?? null);
            if ($reason) {
                break;
            }
            $shipping = $revision->discount_type === 'free_shipping';
            $scope = $revision->issuer_type === 'shop' ? $shops->only([$revision->shop_id]) : $shops;
            $weights = $scope->map(fn ($shop, $id) => $shipping ? $shop['shipping'] - $shippingDiscounts[$id] : $shop['subtotal'] - $productDiscounts[$id]);
            if ($qualified !== null) {
                $weights = $scope->map(fn () => $qualified['subtotal']);
            }
            $basis = $weights->sum();
            $amount = $revision->discount_type === 'percent'
                ? intdiv($basis * $revision->percentage_bps + 5000, 10000)
                : ($shipping && $revision->value_cents === 0 ? $basis : $revision->value_cents);
            $amount = min($basis, $amount, $revision->maximum_cents);
            if ($revision->issuer_type === 'shop') {
                $gross = $qualified['seller_cents'] ?? SellerSettlementService::split($scope->sum('subtotal'))['seller_cents'];
                $amount = min($amount, $gross);
            }
            if ($amount <= 0) {
                $reason = $shipping ? 'There is no shipping fee for this voucher to reduce.' : 'This voucher does not reduce the selected item total.';
                break;
            }
            $balance = $revision->balance;
            if ($amount > $revision->approvedBudget() - $balance->committed_cents - $balance->spent_cents) {
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
            $failedRevisionId = null;
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
            'items' => array_values($pricePlans),
            'voucher' => count($presented) === 1 ? $presented[0] : null, 'vouchers' => $presented,
            'voucher_error' => $reason, 'redemptions' => $redemptions,
            ...($reason && $failedRevisionId ? ['failed_revision_id' => $failedRevisionId] : [])];
    }

    public function offers(?Collection $items = null, string $deliveryType = 'doorstep', int $limit = 24, ?User $buyer = null, ?Collection $selected = null): array
    {
        $query = $this->availableQuery($items, $buyer);
        $count = (clone $query)->count();
        $offers = $query->orderByDesc('id')->limit($limit)->get()->map(function (VoucherRevision $revision) use ($items, $deliveryType, $buyer, $selected) {
            $candidate = ($selected ?? collect())->reject(fn ($current) => $current->issuer_type === $revision->issuer_type && $current->shop_id === $revision->shop_id)->push($revision);
            $quote = $items !== null ? $this->quote($items, $deliveryType, $candidate, $buyer) : null;
            $saving = $quote ? collect($quote['redemptions'])->firstWhere('revision_id', $revision->id)['discount_cents'] ?? 0 : null;
            $presented = $this->present($revision);
            $unavailable = $quote['voucher_error'] ?? ($presented['product_scope'] === 'selected' && $presented['eligible_products'] === []
                ? 'The qualifying products are currently unavailable.' : null);

            return [...$presented, 'eligible' => $unavailable !== null ? false : ($quote !== null ? true : null),
                'unavailable_reason' => $unavailable, 'saving' => $saving === null ? null : $saving / 100];
        })->all();

        return ['offers' => $offers, 'count' => $count];
    }

    public function availableQuery(?Collection $items = null, ?User $buyer = null): Builder
    {
        $query = VoucherRevision::with(['voucher', 'shop:id,name,slug', 'balance'])->withSum('budgetIncreases', 'budget_increase_cents')
            ->whereHas('voucher', fn (Builder $q) => $q->where('is_active', true)->whereNull('archived_at')->whereColumn('current_revision_id', 'voucher_revisions.id'))
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->whereHas('balance', fn (Builder $q) => $q->whereColumn('redemption_count', '<', 'voucher_revisions.global_limit')
                ->whereRaw("committed_cents + spent_cents < voucher_revisions.budget_cents + coalesce((select sum(budget_increase_cents) from voucher_campaign_actions where voucher_revision_id = voucher_revisions.id and action = 'budget_increased'), 0)"))
            ->where(fn (Builder $q) => $q->where('issuer_type', 'platform')->orWhereHas('shop', fn (Builder $shop) => $shop->eligible()));
        if ($items !== null) {
            $query->where(fn (Builder $q) => $q->where('issuer_type', 'platform')->orWhereIn('shop_id', $items->pluck('product.shop_id')->unique()));
        }
        if ($buyer) {
            $query->whereRaw('(select count(*) from voucher_redemptions where voucher_revision_id = voucher_revisions.id and buyer_id = ? and not exists (select 1 from wallet_voucher_restorations where voucher_redemption_id = voucher_redemptions.id)) < voucher_revisions.per_buyer_limit', [$buyer->id]);
        }

        return $query;
    }

    public function present(VoucherRevision $revision): array
    {
        $products = app(VoucherProductScopeService::class)->publicRows($revision);

        return ['id' => $revision->voucher_id, 'revision_id' => $revision->id, 'code' => $revision->code,
            'name' => $revision->name, 'description' => $revision->description, 'discount_type' => $revision->discount_type,
            'discount_value' => $revision->discount_type === 'percent' ? $revision->percentage_bps / 100 : $revision->value_cents / 100,
            'min_spend' => $revision->minimum_cents / 100, 'max_discount' => $revision->maximum_cents / 100,
            'shop_id' => $revision->shop_id, 'shop_name' => $revision->shop?->name,
            'issuer' => $revision->issuer_type, 'expires_at' => $revision->ends_at->toIso8601String(),
            'starts_at' => $revision->starts_at->toIso8601String(),
            'product_scope' => $revision->product_scope ?? 'all', 'eligible_products' => $products,
            'use_url' => $products[0]['url'] ?? ($revision->issuer_type === 'shop' && $revision->shop?->slug
                ? route('shop.show', $revision->shop->slug, absolute: false) : route('buyer.cart', absolute: false))];
    }

    /** Match the original purchased-line order and its exact commission allocation for later returns. */
    private function selectedProductBasis(VoucherRevision $revision, Collection $items, Collection $lineAmounts, Collection $shops): array
    {
        $total = $shops->get($revision->shop_id)['subtotal'] ?? 0;
        if ($total <= 0) {
            return ['subtotal' => 0, 'seller_cents' => 0];
        }
        $commission = SellerSettlementService::split($total)['commission_cents'];
        $prefix = $previous = $subtotal = $seller = 0;
        foreach ($items->filter(fn ($item) => $item->product->shop_id === $revision->shop_id) as $item) {
            $gross = $lineAmounts[$item->id];
            $prefix += $gross;
            $next = $this->proportion($commission, $prefix, $total);
            if (in_array($item->product_id, $revision->product_ids ?? [], true)) {
                $subtotal += $gross;
                $seller += $gross - ($next - $previous);
            }
            $previous = $next;
        }

        return ['subtotal' => $subtotal, 'seller_cents' => $seller];
    }

    private function unavailableReason(VoucherRevision $revision, Collection $shops, ?User $buyer, ?int $qualifiedSubtotal = null): ?string
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
        $uses = $revision->getAttribute('quote_buyer_id') === $buyer?->id && $revision->getAttribute('buyer_uses_count') !== null
            ? $revision->getAttribute('buyer_uses_count')
            : ($buyer ? VoucherRedemption::where('voucher_revision_id', $revision->id)->where('buyer_id', $buyer->id)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('wallet_voucher_restorations')->whereColumn('voucher_redemption_id', 'voucher_redemptions.id'))->count() : 0);
        if ($buyer && $uses >= $revision->per_buyer_limit) {
            return 'You have reached your usage limit for this voucher.';
        }
        if ($revision->issuer_type === 'shop' && (! $shops->has($revision->shop_id) || ! $revision->shop
            || ! ($revision->getAttribute('quote_shop_eligible') ?? app(ShopEligibilityService::class)->isEligible($revision->shop)))) {
            return 'This voucher is not for the selected shop items.';
        }
        if ($qualifiedSubtotal !== null && $qualifiedSubtotal <= 0) {
            return 'Choose a qualifying product from this shop to use this voucher.';
        }
        $subtotal = $qualifiedSubtotal ?? ($revision->issuer_type === 'shop' ? $shops->only([$revision->shop_id]) : $shops)->sum('subtotal');
        if ($subtotal < $revision->minimum_cents) {
            $remaining = number_format(($revision->minimum_cents - $subtotal) / 100, 2);

            return $qualifiedSubtotal !== null ? "Add ₱{$remaining} in qualifying products from this shop to use this voucher."
                : ($revision->issuer_type === 'shop' ? "Add ₱{$remaining} from this shop to use this voucher." : "Add ₱{$remaining} in selected products to use this voucher.");
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
