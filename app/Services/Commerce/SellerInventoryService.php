<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use App\Rules\AsciiPositiveInteger;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class SellerInventoryService
{
    public function selection(Request $request, Shop $shop): array
    {
        $values = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', Rule::in(app(ShopEligibilityService::class)->categoryIds($shop))],
            'status' => ['nullable', 'string', Rule::in(['all', 'active', 'draft', 'archived'])],
            'stock' => ['nullable', 'string', Rule::in(['all', 'low_stock', 'out_of_stock', 'in_stock'])],
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000'],
        ]);

        return [
            'search' => trim((string) ($values['search'] ?? '')),
            'category_id' => isset($values['category_id']) ? (int) $values['category_id'] : null,
            'status' => $values['status'] ?? 'all',
            'stock' => $values['stock'] ?? 'all',
        ];
    }

    public function filter(Builder $products, array $filters): Builder
    {
        $products->when($filters['search'] !== '', function (Builder $query) use ($filters) {
            $pattern = '%'.$filters['search'].'%';
            $query->where(fn (Builder $listing) => $listing
                ->whereLike('name', $pattern, caseSensitive: false)
                ->orWhereLike('sku', $pattern, caseSensitive: false));
        })->when($filters['category_id'] !== null, fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when($filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']));

        // Alerts and stock worklists describe active listing stock, not draft stock or variant totals.
        return $this->stockFilter($products, $filters['stock']);
    }

    private function stockFilter(Builder $products, string $stock): Builder
    {
        if ($stock === 'all') {
            return $products;
        }

        $products->where('status', 'active');

        return match ($stock) {
            'low_stock' => $products->whereBetween('stock', [1, 5]),
            'out_of_stock' => $products->where('stock', 0),
            'in_stock' => $products->where('stock', '>', 0),
        };
    }

    public function stockCounts(int $shopId): array
    {
        $products = Product::query()->where('shop_id', $shopId);

        return [
            'lowStockCount' => $this->stockFilter(clone $products, 'low_stock')->count(),
            'outOfStockCount' => $this->stockFilter(clone $products, 'out_of_stock')->count(),
        ];
    }

    public function paginate(Builder $products, array $filters): LengthAwarePaginator
    {
        $query = $products->reorder()->latest('updated_at')->latest('id');
        $page = (clone $query)->paginate(10);
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(10, ['*'], 'page', $page->lastPage());
        }

        return $page->appends(array_filter($filters, fn ($value) => $value !== null && $value !== '' && $value !== 'all'));
    }
}
