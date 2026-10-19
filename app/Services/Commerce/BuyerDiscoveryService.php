<?php

namespace App\Services\Commerce;

use App\Models\Category;
use App\Models\Product;
use App\Rules\AsciiPositiveInteger;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
            'location' => ['bail', 'nullable', 'string', Rule::in(['all', ...array_column(app(CatalogueLocationService::class)->areas(), 'value')])],
            'sort' => ['nullable', 'string', Rule::in(['relevance', 'price_asc', 'price_desc', 'top_sales', 'top_rated', 'newest'])],
            'min_price' => $prices,
            'max_price' => $prices,
            'rating' => ['bail', 'nullable', 'numeric', 'regex:/\A[1-5](?:\.[0-9])?\z/', 'between:1,5'],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000'],
        ]);
        if (isset($data['min_price'], $data['max_price']) && (float) $data['min_price'] > (float) $data['max_price']) {
            throw ValidationException::withMessages(['max_price' => 'The maximum price must be at least the minimum price.']);
        }

        return [
            'search' => trim((string) ($data['search'] ?? '')),
            'category' => $data['category'] ?? 'all',
            'location' => $data['location'] ?? 'all',
            'sort' => $data['sort'] ?? 'relevance',
            'min_price' => isset($data['min_price']) ? (string) $data['min_price'] : '',
            'max_price' => isset($data['max_price']) ? (string) $data['max_price'] : '',
            'in_stock' => (bool) ($data['in_stock'] ?? false),
            'rating' => isset($data['rating']) ? (string) (float) $data['rating'] : '',
        ];
    }

    public function catalogue(): Builder
    {
        return Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])
            ->availableForSale()->withReviewSummary();
    }

    public function suggestions(array $filters): array
    {
        $search = mb_strtolower($filters['search']);
        // A small eligible catalogue sample bounds work while keeping private listings out.
        if (! preg_match('/\A[a-z ]{3,100}\z/', $search)) {
            return [];
        }
        $tokens = preg_split('/ +/', trim($search));
        if (count($tokens) > 4) {
            return [];
        }
        $names = $this->filter($this->catalogue(), [...$filters, 'search' => ''])
            ->orderByDesc('sales_count')->orderByDesc('products.id')->limit(250)->pluck('products.name');
        $words = array_unique(preg_split('/[^a-z]+/', strtolower($names->implode(' ')), -1, PREG_SPLIT_NO_EMPTY));
        $corrected = [];
        foreach ($tokens as $token) {
            $best = $token;
            $distance = strlen($token) < 5 ? 2 : 3;
            foreach ($words as $word) {
                if (strlen($word) < 3 || strlen($word) > 32 || abs(strlen($word) - strlen($token)) >= $distance) {
                    continue;
                }
                $candidate = levenshtein($token, $word);
                if ($candidate < $distance) {
                    $best = $word;
                    $distance = $candidate;
                }
            }
            $corrected[] = $best;
        }
        $suggestion = implode(' ', $corrected);

        return $suggestion !== $search && $this->filter($this->catalogue(), [...$filters, 'search' => $suggestion])->exists()
            ? [$suggestion] : [];
    }

    public function categoryIds(string $slug): array
    {
        $category = Category::where('slug', $slug)->where('is_active', true)->first();

        return $category ? app(ShopEligibilityService::class)->categoryTreeIds($category) : [];
    }

    public function categories(): Collection
    {
        $counts = Product::availableForSale()->selectRaw('category_id, count(*) as product_count')->groupBy('category_id')->pluck('product_count', 'category_id');
        $categories = Category::where('is_active', true)->orderBy('id')->get();

        return $categories->each(function (Category $category) use ($counts, $categories) {
            $ids = app(ShopEligibilityService::class)->categoryTreeIds($category, $categories);
            $category->setAttribute('products_count', (int) $counts->only($ids)->sum());
        });
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
            $products->whereIn('products.category_id', $this->categoryIds($filters['category']));
        }
        if (($filters['location'] ?? 'all') !== 'all' && $filters['location'] !== '') {
            app(CatalogueLocationService::class)->filter($products, $filters['location']);
        }
        $price = app(SalePriceService::class)->cataloguePriceSql();
        if ($filters['min_price'] !== '') {
            $products->whereRaw($price['sql'].' >= CAST(? AS DECIMAL(12,2))', [...$price['bindings'], $filters['min_price']]);
        }
        if ($filters['max_price'] !== '') {
            $products->whereRaw($price['sql'].' <= CAST(? AS DECIMAL(12,2))', [...$price['bindings'], $filters['max_price']]);
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
        $price = app(SalePriceService::class)->cataloguePriceSql();
        match ($filters['sort']) {
            'price_asc' => $products->orderByRaw($price['sql'].' ASC', $price['bindings']),
            'price_desc' => $products->orderByRaw($price['sql'].' DESC', $price['bindings']),
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

        return $page->withPath(request()->getPathInfo())
            ->appends(array_filter($filters, fn ($value) => $value !== '' && $value !== 'all' && $value !== false));
    }
}
