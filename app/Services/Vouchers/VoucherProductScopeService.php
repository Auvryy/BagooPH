<?php

namespace App\Services\Vouchers;

use App\Models\Product;
use App\Models\Shop;
use App\Models\VoucherRevision;
use App\Rules\AsciiPositiveInteger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoucherProductScopeService
{
    public const MAX_PRODUCTS = 100;

    public function validate(array $input, ?int $shopId): array
    {
        $data = Validator::make([
            'product_scope' => array_key_exists('product_scope', $input) ? $input['product_scope'] : 'all',
            'product_ids' => array_key_exists('product_ids', $input) ? $input['product_ids'] : [],
        ], [
            'product_scope' => 'required|in:all,selected',
            'product_ids' => ['present', 'array', 'list', 'max:'.self::MAX_PRODUCTS],
            'product_ids.*' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'max:2147483647', 'distinct'],
        ])->validate();
        $ids = array_map('intval', $data['product_ids']);
        sort($ids, SORT_NUMERIC);
        if ($data['product_scope'] === 'all') {
            if ($ids !== []) {
                throw ValidationException::withMessages(['product_ids' => 'Choose Selected products to limit this voucher, or clear the product selection.']);
            }
        } elseif (! $shopId) {
            throw ValidationException::withMessages(['product_scope' => 'Selected products are available for shop vouchers only.']);
        } elseif ($ids === [] || Product::availableForSale()->where('shop_id', $shopId)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['product_ids' => 'Choose at least one published, approved product from your shop. Remove any product that is no longer available.']);
        }

        return ['product_scope' => $data['product_scope'], 'product_ids' => $ids];
    }

    public function search(Shop $shop, array $input): LengthAwarePaginator
    {
        $data = Validator::make($input, ['search' => 'nullable|string|max:100',
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000']])->validate();
        $search = trim($data['search'] ?? '');

        return Product::availableForSale()->where('shop_id', $shop->id)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('name', '%'.$search.'%')->orWhereLike('sku', '%'.$search.'%')))
            ->orderBy('name')->orderBy('id')->paginate(12, ['id', 'name', 'sku', 'featured_image'])->withQueryString()
            ->through(fn ($product) => $product->only(['id', 'name', 'sku', 'featured_image']));
    }

    public function sellerRows(?int $shopId, array $terms): array
    {
        if (($terms['product_scope'] ?? 'all') !== 'selected' || ! $shopId) {
            return [];
        }
        $ids = $terms['product_ids'] ?? [];
        $eligible = Product::availableForSale()->where('shop_id', $shopId)->whereIn('id', $ids)->pluck('id');

        return Product::where('shop_id', $shopId)->whereIn('id', $ids)->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'sku', 'featured_image'])->map(fn ($product) => [
                ...$product->only(['id', 'name', 'sku', 'featured_image']), 'eligible' => $eligible->contains($product->id),
            ])->all();
    }

    public function publicRows(VoucherRevision $revision): array
    {
        if (($revision->product_scope ?? 'all') !== 'selected') {
            return [];
        }
        // A quote can compare the same immutable revision many times in one request.
        if (! $revision->relationLoaded('scopeProducts')) {
            $revision->setRelation('scopeProducts', Product::availableForSale()->where('shop_id', $revision->shop_id)
                ->whereIn('id', $revision->product_ids ?? [])->orderBy('name')->orderBy('id')->get(['id', 'name', 'slug']));
        }

        return $revision->getRelation('scopeProducts')->map(fn ($product) => [
            'id' => $product->id, 'name' => $product->name, 'url' => route('products.show', $product->slug, absolute: false),
        ])->all();
    }
}
