<?php

namespace App\Services\Commerce;

use App\Exceptions\CheckoutException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSale;
use App\Services\Finance\CodMoney;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalePriceService
{
    /** A product lock serializes all sale edits, quota writes and stock changes. */
    public function context(Product $product): array
    {
        $product->loadMissing(['sales' => fn ($sales) => $sales->whereNull('ended_at')->where('ends_at', '>', now())->orderBy('id')]);
        $live = $product->sales->filter(fn (ProductSale $sale) => $sale->isLive());
        $store = $live->firstWhere('kind', 'store');
        $flash = $live->first(fn (ProductSale $sale) => $sale->kind === 'flash' && $sale->used_quantity < $sale->quantity);
        $regular = CodMoney::cents($product->price);

        return ['regular_cents' => $regular,
            'fallback_cents' => $store ? $this->saleCents($store, $regular, $regular) : $regular,
            'store_id' => $store?->id,
            'store_type' => $store?->discount_type ?? 'fixed', 'store_percentage' => $store?->percentage,
            'store_price' => $store ? CodMoney::cents($store->price) : null,
            'flash_cents' => $flash ? $this->saleCents($flash, $regular, $regular) : null,
            'flash_id' => $flash?->id,
            'flash_type' => $flash?->discount_type ?? 'fixed', 'flash_percentage' => $flash?->percentage,
            'flash_price' => $flash ? CodMoney::cents($flash->price) : null,
            'flash_remaining' => $flash ? max(0, $flash->quantity - $flash->used_quantity) : 0];
    }

    public function unitPrice(Product $product, string $regularUnitPrice): string
    {
        $context = $this->context($product);

        return number_format($this->contextCents($context, CodMoney::cents($regularUnitPrice), $context['flash_id'] !== null) / 100, 2, '.', '');
    }

    public function range(Product $product): array
    {
        $context = $this->context($product);
        $regular = $this->regularPrices($product);
        $prices = array_map(fn ($cents) => $this->contextCents($context, $cents, $context['flash_id'] !== null), $regular);

        return ['min' => number_format(min($prices) / 100, 2, '.', ''), 'max' => number_format(max($prices) / 100, 2, '.', ''),
            'regular_min' => number_format(min($regular) / 100, 2, '.', ''), 'regular_max' => number_format(max($regular) / 100, 2, '.', '')];
    }

    /** @return list<int> */
    public function regularPrices(Product $product): array
    {
        $options = $product->variants['combinations'] ?? $product->variants['sizes'] ?? [];
        if (($product->variants['pricing_mode'] ?? null) === 'independent' && $options !== []) {
            return array_map(fn ($row) => CodMoney::cents($row['price']), $options);
        }

        return array_map(fn ($row) => CodMoney::cents($product->price) + max(0, CodMoney::cents(number_format((float) ($row['extra_price'] ?? 0), 2, '.', ''))), $options ?: [['extra_price' => 0]]);
    }

    public function discountedCents(int $regular, int $percentage): int
    {
        return max(1, intdiv($regular * (100 - $percentage) + 50, 100));
    }

    public function assertFixedSalesRemainPositive(Product $product): void
    {
        $minimum = min($this->regularPrices($product));
        foreach ($product->sales()->whereNull('ended_at')->where('ends_at', '>', now())->where('discount_type', 'fixed')->get() as $sale) {
            if ($minimum - max(0, CodMoney::cents($product->price) - CodMoney::cents($sale->price)) < 1) {
                throw ValidationException::withMessages(['price' => 'An existing sale would make an option free or negative. Review the sale before changing these prices.']);
            }
        }
    }

    private function saleCents(ProductSale $sale, int $regular, int $base): int
    {
        return $sale->discount_type === 'percentage' ? $this->discountedCents($regular, $sale->percentage)
            : max(1, $regular - max(0, $base - CodMoney::cents($sale->price)));
    }

    private function contextCents(array $context, int $regular, bool $flash): int
    {
        $prefix = $flash ? 'flash' : 'store';
        if (! $context[$prefix.'_id']) {
            return $regular;
        }

        return $context[$prefix.'_type'] === 'percentage' ? $this->discountedCents($regular, $context[$prefix.'_percentage'])
            : max(1, $regular - max(0, $context['regular_cents'] - $context[$prefix.'_price']));
    }

    /** @return array<int, array<string, mixed>> */
    public function plan(Collection $items): array
    {
        $remaining = [];
        $plans = [];
        foreach ($items->sortBy('id') as $item) {
            $product = $item->product;
            $context = $this->context($product);
            $regular = CodMoney::cents(app(InventoryService::class)->regularUnitPrice($product, $item->color, $item->size));
            $remaining[$product->id] ??= $context['flash_remaining'];
            $flashQuantity = min($item->quantity, $remaining[$product->id]);
            $remaining[$product->id] -= $flashQuantity;
            $segments = [];
            if ($flashQuantity > 0) {
                $segments[] = $this->segment($flashQuantity, $this->contextCents($context, $regular, true), $context['flash_id'], 'flash');
            }
            if ($item->quantity > $flashQuantity) {
                $segments[] = $this->segment($item->quantity - $flashQuantity, $this->contextCents($context, $regular, false), $context['store_id'], $context['store_id'] ? 'store' : null);
            }
            $plans[$item->id] = ['id' => $item->id, 'quantity' => $item->quantity,
                'unit_price' => $segments[0]['unit_price'],
                'subtotal' => array_sum(array_column($segments, 'subtotal')),
                'price_segments' => $segments];
            if ($item->getAttribute('checkout_price_context') === null) {
                $item->setAttribute('checkout_price_context', $context)->makeHidden('checkout_price_context');
            }
        }

        return $plans;
    }

    private function segment(int $quantity, int $cents, ?int $saleId, ?string $kind): array
    {
        return ['quantity' => $quantity, 'unit_price' => $cents / 100, 'subtotal' => $cents * $quantity / 100, 'product_sale_id' => $saleId, 'sale_kind' => $kind];
    }

    public function reserve(Product $product, int $saleId, int $quantity): void
    {
        $sale = ProductSale::whereKey($saleId)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
        if (! $sale->isLive() || ($sale->kind === 'flash' && $sale->used_quantity + $quantity > $sale->quantity)) {
            throw new CheckoutException('A sale ended or its remaining quantity changed. Reload checkout and review your total.');
        }
        if ($sale->kind === 'flash') {
            $sale->increment('used_quantity', $quantity);
            $product->unsetRelation('sales');
        }
    }

    public function release(OrderItem $item): void
    {
        if (! $item->product_sale_id) {
            return;
        }
        $sale = ProductSale::whereKey($item->product_sale_id)->where('product_id', $item->product_id)->lockForUpdate()->firstOrFail();
        if ($sale->kind === 'flash') {
            if ($sale->used_quantity < $item->quantity) {
                throw new CheckoutException('The original sale allocation needs review before this order can be cancelled.');
            }
            $sale->decrement('used_quantity', $item->quantity);
        }
    }

    public function present(Product $product): array
    {
        $context = $this->context($product);
        $activeId = $context['flash_id'] ?? $context['store_id'];
        $active = $product->sales->firstWhere('id', $activeId);
        $upcoming = $product->sales->filter(fn (ProductSale $sale) => $sale->kind === 'flash' && ! $sale->ended_at && $sale->starts_at->isFuture() && $sale->ends_at->isFuture())->sortBy('starts_at')->first();

        return ['server_now' => now()->toIso8601String(), 'effective_price' => (float) $this->range($product)['min'],
            'active' => $active ? $this->saleSummary($active, $product) : null,
            'upcoming' => $upcoming ? $this->saleSummary($upcoming, $product) : null];
    }

    public function saleSummary(ProductSale $sale, ?Product $product = null): array
    {
        $price = $product ? number_format($this->saleCents($sale, min($this->regularPrices($product)), CodMoney::cents($product->price)) / 100, 2, '.', '') : $sale->price;

        return ['id' => $sale->id, 'kind' => $sale->kind, 'name' => $sale->name, 'price' => $price,
            'discount_type' => $sale->discount_type ?? 'fixed', 'percentage' => $sale->percentage,
            'starts_at' => $sale->starts_at->toIso8601String(), 'ends_at' => $sale->ends_at->toIso8601String(),
            'remaining_quantity' => $sale->kind === 'flash' ? max(0, $sale->quantity - $sale->used_quantity) : null];
    }

    /** Correlated expression used by catalogue price filters and sorting on both supported databases. */
    public function cataloguePriceSql(): array
    {
        $postgres = DB::getDriverName() === 'pgsql';
        $mode = $postgres ? "products.variants::jsonb->>'pricing_mode'" : "json_extract(products.variants, '$.pricing_mode')";
        $rowPrice = $postgres ? "select min(cast(v->>'price' as decimal(12,2))) from jsonb_array_elements(products.variants::jsonb->'combinations') v"
            : "select min(cast(json_extract(value, '$.price') as decimal(12,2))) from json_each(products.variants, '$.combinations')";
        $extra = $postgres ? "select min(cast(coalesce(v->>'extra_price','0') as decimal(12,2))) from jsonb_array_elements(coalesce(products.variants::jsonb->'combinations',products.variants::jsonb->'sizes','[]'::jsonb)) v"
            : "select min(cast(coalesce(json_extract(value, '$.extra_price'),0) as decimal(12,2))) from json_each(coalesce(json_extract(products.variants, '$.combinations'),json_extract(products.variants, '$.sizes'),'[]'))";
        $regular = "(case when {$mode} = 'independent' then coalesce(({$rowPrice}), products.price) else products.price + coalesce(({$extra}),0) end)";
        $price = "case when s.discount_type = 'percentage' then round({$regular} * (100.0 - s.percentage) / 100.0, 2) when s.price < products.price then {$regular} - (products.price - s.price) else {$regular} end";
        $clamp = $postgres ? "greatest(0.01, ({$price}))" : "max(0.01, ({$price}))";
        $select = 'select '.$clamp.' from product_sales s where s.product_id = products.id and s.ended_at is null and s.starts_at <= ? and s.ends_at > ? and s.kind = ?';

        return ['sql' => 'coalesce(('.$select.' and s.used_quantity < s.quantity order by s.id limit 1), ('.$select.' order by s.id limit 1), '.$regular.')',
            'bindings' => [now(), now(), 'flash', now(), now(), 'store']];
    }
}
