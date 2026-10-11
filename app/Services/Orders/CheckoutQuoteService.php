<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Services\BuyerAccessService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Commerce\InventoryService;
use App\Services\Finance\CodMoney;
use DomainException;
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

    /** Current prices are supplied by selectedItems or the locked final checkout. */
    public function quote(Collection $items, string $deliveryType = 'doorstep', ?Voucher $voucher = null): array
    {
        $shops = $items->groupBy(fn (CartItem $item) => $item->product->shop_id)->sortKeys()->map(function (Collection $group, int $shopId) use ($deliveryType) {
            $subtotal = $group->sum(fn (CartItem $item) => CodMoney::cents($item->product->price) * $item->quantity);

            $doorstepShipping = $subtotal > 150000 ? 0 : 5000;

            return ['shop_id' => $shopId, 'shop_name' => $group->first()->product->shop->name,
                'subtotal' => $subtotal, 'doorstep_shipping' => $doorstepShipping,
                'shipping' => $deliveryType === 'hub_self_pickup' ? 0 : $doorstepShipping];
        });
        $discounts = $shops->map(fn () => 0);
        $reason = $voucher ? $this->unavailableReason($voucher, $shops) : null;
        if ($voucher && $reason === null) {
            $shipping = $voucher->discount_type === 'free_shipping';
            $scope = $voucher->shop_id ? $shops->only([$voucher->shop_id]) : $shops;
            $weights = $scope->map(fn ($shop) => $shop[$shipping ? 'shipping' : 'subtotal']);
            $basis = $weights->sum();
            $value = CodMoney::cents($voucher->discount_value);
            $amount = match ($voucher->discount_type) {
                'percent' => intdiv($basis * $value + 5000, 10000),
                'free_shipping' => $value > 0 ? $value : $basis,
                default => $value,
            };
            $amount = min($basis, $amount, $voucher->max_discount !== null ? CodMoney::cents($voucher->max_discount) : $basis);
            if ($amount === 0) {
                $reason = 'This voucher does not reduce the selected item total.';
            }
            $left = $amount;
            foreach ($weights as $shopId => $weight) {
                $part = $this->proportion($amount, $weight, $basis);
                $discounts->put($shopId, $part);
                $left -= $part;
            }
            foreach ($weights as $shopId => $weight) {
                if ($left > 0 && $discounts[$shopId] < $weight) {
                    $discounts->put($shopId, $discounts[$shopId] + 1);
                    $left--;
                }
            }
        }
        $shopQuotes = $shops->map(function ($shop, $shopId) use ($discounts) {
            $discount = $discounts[$shopId];

            return [...$shop, 'subtotal' => $shop['subtotal'] / 100, 'shipping' => $shop['shipping'] / 100,
                'doorstep_shipping' => $shop['doorstep_shipping'] / 100,
                'discount' => $discount / 100, 'total' => ($shop['subtotal'] + $shop['shipping'] - $discount) / 100];
        })->values()->all();
        $subtotal = $shops->sum('subtotal');
        $fees = $shops->sum('shipping');
        $discount = $discounts->sum();

        return ['subtotal' => $subtotal / 100, 'shipping_fee' => $fees / 100,
            'doorstep_shipping_fee' => $shops->sum('doorstep_shipping') / 100, 'discount' => $discount / 100,
            'shipping_discount' => $voucher?->discount_type === 'free_shipping' && $reason === null ? $discount / 100 : 0,
            'total' => ($subtotal + $fees - $discount) / 100, 'shops' => $shopQuotes,
            'items' => $items->map(fn (CartItem $item) => ['id' => $item->id,
                'quantity' => $item->quantity, 'unit_price' => CodMoney::cents($item->product->price) / 100,
                'subtotal' => CodMoney::cents($item->product->price) * $item->quantity / 100])->values()->all(),
            'voucher' => $voucher && $reason === null ? $this->present($voucher) : null, 'voucher_error' => $reason];
    }

    public function offers(?Collection $items = null, string $deliveryType = 'doorstep', int $limit = 24): array
    {
        $query = $this->publicQuery();
        if ($items !== null) {
            $shopIds = $items->pluck('product.shop_id')->unique()->all();
            $query->where(fn (Builder $scope) => $scope->whereNull('shop_id')->orWhereIn('shop_id', $shopIds));
        }
        $count = (clone $query)->count();
        $offers = $query->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->map(function (Voucher $voucher) use ($items, $deliveryType) {
            $quote = $items !== null ? $this->quote($items, $deliveryType, $voucher) : null;

            return [...$this->present($voucher), 'eligible' => $quote ? $quote['voucher_error'] === null : null,
                'unavailable_reason' => $quote['voucher_error'] ?? null, 'saving' => $quote['discount'] ?? null];
        })->all();

        return ['offers' => $offers, 'count' => $count];
    }

    private function publicQuery(): Builder
    {
        return Voucher::query()->with('shop:id,name')->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->whereIn('discount_type', ['fixed', 'percent', 'free_shipping'])
            ->where('min_spend', '>=', 0)->where('discount_value', '>=', 0)
            ->where(fn (Builder $q) => $q->where('discount_type', 'free_shipping')->orWhere('discount_value', '>', 0))
            ->where(fn (Builder $q) => $q->where('discount_type', '!=', 'percent')->orWhere('discount_value', '<=', 100))
            ->where(fn (Builder $q) => $q->whereNull('max_discount')->orWhere('max_discount', '>', 0))
            ->where(fn (Builder $q) => $q->whereNull('shop_id')->orWhereHas('shop', fn (Builder $shop) => $shop->eligible()));
    }

    private function present(Voucher $voucher): array
    {
        return $voucher->only(['id', 'code', 'name', 'description', 'discount_type', 'discount_value', 'min_spend', 'max_discount'])
            + ['shop_id' => $voucher->shop_id, 'shop_name' => $voucher->shop?->name,
                'issuer' => $voucher->shop_id ? 'shop' : 'platform', 'expires_at' => $voucher->expires_at?->toIso8601String()];
    }

    private function unavailableReason(Voucher $voucher, Collection $shops): ?string
    {
        if (! $voucher->is_active) {
            return 'This voucher is paused.';
        }
        if ($voucher->expires_at && $voucher->expires_at->lessThanOrEqualTo(now())) {
            return 'This voucher has expired.';
        }
        if ($voucher->usage_limit !== null && $voucher->used_count >= $voucher->usage_limit) {
            return 'This voucher has reached its usage limit.';
        }
        if ($voucher->shop_id && ! $shops->has($voucher->shop_id)) {
            return 'This voucher is not for the selected shop items.';
        }
        try {
            $minimum = CodMoney::cents($voucher->min_spend);
            $value = CodMoney::cents($voucher->discount_value);
            if (! in_array($voucher->discount_type, ['fixed', 'percent', 'free_shipping'], true)
                || ($voucher->discount_type === 'percent' && $value > 10000)
                || ($voucher->max_discount !== null && CodMoney::cents($voucher->max_discount) === 0)) {
                return 'This voucher needs its offer details corrected.';
            }
        } catch (DomainException) {
            return 'This voucher needs its offer details corrected.';
        }
        $scope = $voucher->shop_id ? $shops->only([$voucher->shop_id]) : $shops;
        $subtotal = $scope->sum('subtotal');
        if ($subtotal < $minimum) {
            $remaining = number_format(($minimum - $subtotal) / 100, 2);

            return $voucher->shop_id ? "Add ₱{$remaining} from this shop to use this voucher." : "Add ₱{$remaining} in selected products to use this voucher.";
        }
        if ($voucher->discount_type === 'free_shipping' && $scope->sum('shipping') === 0) {
            return 'There is no shipping fee for this voucher to reduce.';
        }
        if ($subtotal === 0 || ($voucher->discount_type !== 'free_shipping' && $value === 0)) {
            return 'This voucher does not reduce the selected item total.';
        }

        return null;
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
