<?php

namespace App\Services\Commerce;

use App\Exceptions\CheckoutException;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSale;
use App\Services\Finance\CodMoney;
use Illuminate\Support\Collection;

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
            'fallback_cents' => $store ? min($regular, CodMoney::cents($store->price)) : $regular,
            'store_id' => $store?->id,
            'flash_cents' => $flash ? min($regular, CodMoney::cents($flash->price)) : null,
            'flash_id' => $flash?->id,
            'flash_remaining' => $flash ? max(0, $flash->quantity - $flash->used_quantity) : 0];
    }

    public function unitPrice(Product $product, string $regularUnitPrice): string
    {
        $context = $this->context($product);
        $extra = CodMoney::cents($regularUnitPrice) - $context['regular_cents'];

        return number_format((($context['flash_cents'] ?? $context['fallback_cents']) + $extra) / 100, 2, '.', '');
    }

    /** @return array<int, array<string, mixed>> */
    public function plan(Collection $items): array
    {
        $remaining = [];
        $plans = [];
        foreach ($items->sortBy('id') as $item) {
            $product = $item->product;
            $context = $this->context($product);
            $extra = CodMoney::cents(app(InventoryService::class)->regularUnitPrice($product, $item->color, $item->size)) - $context['regular_cents'];
            $remaining[$product->id] ??= $context['flash_remaining'];
            $flashQuantity = min($item->quantity, $remaining[$product->id]);
            $remaining[$product->id] -= $flashQuantity;
            $segments = [];
            if ($flashQuantity > 0) {
                $segments[] = $this->segment($flashQuantity, $context['flash_cents'] + $extra, $context['flash_id'], 'flash');
            }
            if ($item->quantity > $flashQuantity) {
                $segments[] = $this->segment($item->quantity - $flashQuantity, $context['fallback_cents'] + $extra, $context['store_id'], $context['store_id'] ? 'store' : null);
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

        return ['server_now' => now()->toIso8601String(), 'effective_price' => ($context['flash_cents'] ?? $context['fallback_cents']) / 100,
            'active' => $active ? $this->saleSummary($active) : null,
            'upcoming' => $upcoming ? $this->saleSummary($upcoming) : null];
    }

    public function saleSummary(ProductSale $sale): array
    {
        return ['id' => $sale->id, 'kind' => $sale->kind, 'name' => $sale->name, 'price' => $sale->price,
            'starts_at' => $sale->starts_at->toIso8601String(), 'ends_at' => $sale->ends_at->toIso8601String(),
            'remaining_quantity' => $sale->kind === 'flash' ? max(0, $sale->quantity - $sale->used_quantity) : null];
    }

    /** Correlated expression used by catalogue price filters and sorting on both supported databases. */
    public function cataloguePriceSql(): array
    {
        $select = 'select case when s.price < products.price then s.price else products.price end from product_sales s where s.product_id = products.id and s.ended_at is null and s.starts_at <= ? and s.ends_at > ? and s.kind = ?';

        return ['sql' => 'coalesce(('.$select.' and s.used_quantity < s.quantity order by s.id limit 1), ('.$select.' order by s.id limit 1), products.price)',
            'bindings' => [now(), now(), 'flash', now(), now(), 'store']];
    }
}
