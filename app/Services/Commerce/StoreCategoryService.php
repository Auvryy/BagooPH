<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreCategory;
use App\Rules\AsciiPositiveInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class StoreCategoryService
{
    /** Caller holds the seller/shop locks for all writes. */
    public function rows(Shop $shop): Collection
    {
        return StoreCategory::where('shop_id', $shop->id)->withCount('products')->orderBy('position')->orderBy('id')->get();
    }

    public function owned(Shop $shop, int $id): StoreCategory
    {
        return StoreCategory::where('shop_id', $shop->id)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function save(Shop $shop, array $input, ?StoreCategory $category = null): StoreCategory
    {
        if (isset($input['name']) && is_string($input['name'])) {
            $input['name'] = trim(preg_replace('/\s+/u', ' ', $input['name']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:1', 'max:50', 'not_regex:/[<>\p{C}]/u'],
            'parent_id' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer'],
            'is_visible' => ['required', 'boolean'],
        ], ['name.not_regex' => 'Use a plain category name without markup or hidden characters.'])->validate();
        if ($category) {
            $category = $this->owned($shop, $category->id);
        }
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        if ($parentId !== null) {
            $parent = StoreCategory::where('shop_id', $shop->id)->find($parentId);
            if (! $parent || $parent->parent_id !== null || $parent->id === $category?->id || $category?->children()->exists()) {
                throw ValidationException::withMessages(['parent_id' => 'Choose a top-level category from this store. Only two levels are allowed.']);
            }
        }
        $normalized = mb_strtolower($data['name']);
        if (StoreCategory::where('shop_id', $shop->id)->where('normalized_name', $normalized)
            ->when($category, fn ($query) => $query->whereKeyNot($category->id))->exists()) {
            throw ValidationException::withMessages(['name' => 'This store already has a category with that name.']);
        }
        $position = $category && $category->parent_id === $parentId ? $category->position
            : (int) StoreCategory::where('shop_id', $shop->id)->where('parent_id', $parentId)->max('position') + 1;
        $values = ['shop_id' => $shop->id, 'parent_id' => $parentId, 'name' => $data['name'],
            'normalized_name' => $normalized, 'position' => $position, 'is_visible' => (bool) $data['is_visible']];
        if ($category) {
            $category->update($values);

            return $category;
        }

        return StoreCategory::create($values);
    }

    public function reorder(Shop $shop, array $ids): void
    {
        $rows = StoreCategory::where('shop_id', $shop->id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($rows->count() !== count($ids) || $rows->pluck('parent_id')->unique()->count() !== 1
            || StoreCategory::where('shop_id', $shop->id)->where('parent_id', $rows->first()?->parent_id)->count() !== count($ids)) {
            throw ValidationException::withMessages(['category_ids' => 'Reorder the complete list of categories at the same level in your own store.']);
        }
        foreach ($ids as $position => $id) {
            $rows->firstWhere('id', (int) $id)->update(['position' => $position]);
        }
    }

    public function affected(Shop $shop, StoreCategory $category): array
    {
        $category = $this->owned($shop, $category->id);
        $children = $category->children()->get(['id', 'name']);
        $ids = [$category->id, ...$children->modelKeys()];
        $products = Product::where('shop_id', $shop->id)->whereHas('storeCategories', fn ($query) => $query->whereIn('store_categories.id', $ids))
            ->orderBy('id')->get(['id', 'name']);

        $productRows = $products->map(fn (Product $product) => $product->only(['id', 'name']))->all();

        return ['category' => $category->only(['id', 'name']), 'children' => $children->toArray(),
            'products' => $productRows, 'product_count' => $products->count(),
            'version' => hash('sha256', json_encode([$category->getRawOriginal(), $children->toArray(), $productRows]))];
    }

    public function remove(Shop $shop, StoreCategory $category, string $version): void
    {
        $snapshot = $this->affected($shop, $category);
        if (! hash_equals($snapshot['version'], $version)) {
            throw ValidationException::withMessages(['version' => 'This category changed. Review its affected products again before deleting.']);
        }
        // Only memberships disappear. Products and their approved platform categories remain intact.
        StoreCategory::where('shop_id', $shop->id)->whereKey($category->id)->delete();
    }

    public function membershipIds(Shop $shop, mixed $ids): array
    {
        if (is_string($ids)) {
            try {
                $ids = json_decode($ids, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages(['store_category_ids' => 'Choose valid store categories.']);
            }
        }
        if ($ids !== null && (! is_array($ids) || ! array_is_list($ids))) {
            throw ValidationException::withMessages(['store_category_ids' => 'Choose a list of store categories.']);
        }
        $data = Validator::make(['store_category_ids' => $ids], [
            'store_category_ids' => ['bail', 'nullable', 'array', 'list', 'max:3'],
            'store_category_ids.*' => ['bail', new AsciiPositiveInteger, 'integer', 'distinct'],
        ], ['store_category_ids.max' => 'Choose up to 3 store categories for a product.'])->validate();
        $ids = array_map('intval', $data['store_category_ids'] ?? []);
        if (StoreCategory::where('shop_id', $shop->id)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['store_category_ids' => 'Choose categories from your own store.']);
        }

        return $ids;
    }

    public function publicRows(Shop $shop): Collection
    {
        return StoreCategory::where('shop_id', $shop->id)->where('is_visible', true)
            ->where(fn ($query) => $query->whereNull('parent_id')->orWhereHas('parent', fn ($parent) => $parent->where('is_visible', true)->where('shop_id', $shop->id)))
            ->orderBy('position')->orderBy('id')->get(['id', 'parent_id', 'name', 'position', 'is_visible']);
    }

    public function publicSelection(Request $request, Shop $shop, ?StoreCategory $category = null): string
    {
        if ($category) {
            abort_unless($this->publicRows($shop)->contains('id', $category->id), 404);

            return (string) $category->id;
        }
        $value = $request->input('store_category', 'all');
        if (in_array($value, ['all', 'uncategorized'], true)) {
            return $value;
        }
        Validator::make(['store_category' => $value], ['store_category' => ['bail', 'required', new AsciiPositiveInteger, 'integer']])->validate();
        abort_unless($this->publicRows($shop)->contains('id', (int) $value), 404);

        return (string) $value;
    }

    public function filterPublic(Builder $products, Shop $shop, string $selection): Builder
    {
        if ($selection === 'uncategorized') {
            return $products->whereDoesntHave('storeCategories');
        }
        if ($selection !== 'all') {
            $rows = $this->publicRows($shop);
            $ids = [(int) $selection, ...$rows->where('parent_id', (int) $selection)->modelKeys()];
            $products->whereHas('storeCategories', fn ($query) => $query->whereIn('store_categories.id', $ids));
        }

        return $products;
    }
}
