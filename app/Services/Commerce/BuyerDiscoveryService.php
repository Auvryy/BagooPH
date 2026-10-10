<?php

namespace App\Services\Commerce;

use App\Models\Category;
use App\Models\Product;
use App\Rules\AsciiPositiveInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuyerDiscoveryService
{
    public function selection(Request $request): array
    {
        $prices = ['bail', 'nullable', 'numeric', 'regex:/\A[0-9]+(?:\.[0-9]{1,2})?\z/', 'between:0,99999999.99'];
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', Rule::in(['all', ...Category::where('is_active', true)->pluck('slug')->all()])],
            'sort' => ['nullable', 'string', Rule::in(['relevance', 'price_asc', 'price_desc', 'top_sales', 'top_rated', 'newest'])],
            'min_price' => $prices,
            'max_price' => $prices,
            'rating' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000'],
        ]);
        if (isset($data['min_price'], $data['max_price']) && (float) $data['min_price'] > (float) $data['max_price']) {
            throw ValidationException::withMessages(['max_price' => 'The maximum price must be at least the minimum price.']);
        }

        return [
            'search' => trim((string) ($data['search'] ?? '')),
            'category' => $data['category'] ?? 'all',
            'sort' => $data['sort'] ?? 'relevance',
            'min_price' => isset($data['min_price']) ? (string) $data['min_price'] : '',
            'max_price' => isset($data['max_price']) ? (string) $data['max_price'] : '',
            'in_stock' => (bool) ($data['in_stock'] ?? false),
            'rating' => isset($data['rating']) ? (string) $data['rating'] : '',
        ];
    }

    public function catalogue(): Builder
    {
        return Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])
            ->availableForSale()->withReviewSummary();
    }

    public function filter(Builder $products, array $filters): Builder
    {
        if ($filters['search'] !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['search'])).'%';
            $products->where(function (Builder $query) use ($pattern) {
                $query->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(products.description) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('category', fn (Builder $category) => $category->whereRaw("LOWER(categories.name) LIKE ? ESCAPE '!'", [$pattern]))
                    ->orWhereHas('shop', fn (Builder $shop) => $shop->whereRaw("LOWER(shops.name) LIKE ? ESCAPE '!'", [$pattern]));
            });
        }
        if ($filters['category'] !== 'all') {
            $products->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }
        if ($filters['min_price'] !== '') {
            $products->where('price', '>=', $filters['min_price']);
        }
        if ($filters['max_price'] !== '') {
            $products->where('price', '<=', $filters['max_price']);
        }
        if ($filters['in_stock']) {
            $products->where('stock', '>', 0);
        }
        if ($filters['rating'] !== '') {
            $products->whereVerifiedRatingAtLeast((float) $filters['rating']);
        }

        return $products;
    }

    public function paginate(Builder $products, array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $products->reorder();
        match ($filters['sort']) {
            'price_asc' => $products->orderBy('price'),
            'price_desc' => $products->orderByDesc('price'),
            'top_sales' => $products->orderByDesc('sales_count'),
            'top_rated' => $products->orderByRaw('verified_rating DESC NULLS LAST'),
            'newest' => $products->latest('products.created_at'),
            default => $products->orderByDesc('sales_count')->orderByRaw('verified_rating DESC NULLS LAST'),
        };
        $products->orderByDesc('products.id');
        $page = (clone $products)->paginate($perPage);
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $products)->paginate($perPage, ['*'], 'page', $page->lastPage());
        }

        return $page->appends(array_filter($filters, fn ($value) => $value !== '' && $value !== 'all' && $value !== false));
    }
}
