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
            $terms = preg_split('/\s+/u', trim(mb_strtolower($filters['search'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $products->where(function (Builder $query) use ($terms) {
                foreach ($terms as $term) {
                    $pattern = $this->likePattern($term);
                    $query->where(function (Builder $matches) use ($pattern) {
                        $this->whereSearchTerm($matches, $pattern);
                    });
                }
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
            default => $filters['search'] !== ''
                ? $this->orderBySearchRelevance($products, $filters['search'])
                : $products->orderByDesc('sales_count')->orderByRaw('verified_rating DESC NULLS LAST'),
        };
        if ($filters['sort'] === 'relevance' && $filters['search'] !== '') {
            $products->orderByDesc('sales_count')->orderByRaw('verified_rating DESC NULLS LAST');
        }
        $products->orderByDesc('products.id');
        $page = (clone $products)->paginate($perPage);
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $products)->paginate($perPage, ['*'], 'page', $page->lastPage());
        }

        return $page->withPath(request()->getPathInfo())
            ->appends(array_filter($filters, fn ($value) => $value !== '' && $value !== 'all' && $value !== false));
    }

    private function whereSearchTerm(Builder $query, string $pattern): void
    {
        $query->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("LOWER(products.description) LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereHas('category', fn (Builder $category) => $category->whereRaw("LOWER(categories.name) LIKE ? ESCAPE '!'", [$pattern]))
            ->orWhereHas('storeTags', fn (Builder $tag) => $tag->whereRaw("LOWER(store_tags.name) LIKE ? ESCAPE '!'", [$pattern]))
            ->orWhereHas('shop', fn (Builder $shop) => $shop->whereRaw("LOWER(shops.name) LIKE ? ESCAPE '!'", [$pattern]));
    }

    private function orderBySearchRelevance(Builder $products, string $search): Builder
    {
        $terms = preg_split('/\s+/u', trim(mb_strtolower($search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($terms === []) {
            return $products->orderByDesc('sales_count');
        }

        $phrase = implode(' ', $terms);
        $bindings = [$phrase, $this->likePattern($phrase, trailingWildcard: true), $this->likePattern($phrase)];
        $case = "CASE WHEN LOWER(products.name) = ? THEN 0 WHEN LOWER(products.name) LIKE ? ESCAPE '!' THEN 1 WHEN LOWER(products.name) LIKE ? ESCAPE '!' THEN 2";

        if (count($terms) > 1) {
            $allNameTerms = implode(' AND ', array_fill(0, count($terms), "LOWER(products.name) LIKE ? ESCAPE '!'"));
            $case .= " WHEN {$allNameTerms} THEN 3";
            foreach ($terms as $term) {
                $bindings[] = $this->likePattern($term);
            }
        }

        $case .= " WHEN EXISTS (SELECT 1 FROM product_store_tag JOIN store_tags ON store_tags.id = product_store_tag.store_tag_id WHERE product_store_tag.product_id = products.id AND store_tags.shop_id = products.shop_id AND LOWER(store_tags.name) = ?) THEN 4"
            ." WHEN EXISTS (SELECT 1 FROM product_store_tag JOIN store_tags ON store_tags.id = product_store_tag.store_tag_id WHERE product_store_tag.product_id = products.id AND store_tags.shop_id = products.shop_id AND LOWER(store_tags.name) LIKE ? ESCAPE '!') THEN 5"
            ." WHEN LOWER(products.sku) LIKE ? ESCAPE '!' THEN 6"
            ." WHEN EXISTS (SELECT 1 FROM categories WHERE categories.id = products.category_id AND LOWER(categories.name) LIKE ? ESCAPE '!') THEN 7"
            ." WHEN EXISTS (SELECT 1 FROM shops WHERE shops.id = products.shop_id AND LOWER(shops.name) LIKE ? ESCAPE '!') THEN 8"
            ." WHEN LOWER(products.description) LIKE ? ESCAPE '!' THEN 9 ELSE 10 END";
        $bindings[] = $phrase;
        $bindings[] = $this->likePattern($phrase);
        $bindings[] = $this->likePattern($phrase);
        $bindings[] = $this->likePattern($phrase);
        $bindings[] = $this->likePattern($phrase);
        $bindings[] = $this->likePattern($phrase);

        return $products->orderByRaw($case, $bindings);
    }

    private function likePattern(string $value, bool $trailingWildcard = false): string
    {
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value));

        return $trailingWildcard ? $literal.'%' : '%'.$literal.'%';
    }
}
