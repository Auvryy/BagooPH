<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\Shop;
use App\Services\ShopEligibilityService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerListingActions
{
    public function owned(Shop $shop, int $id): Product
    {
        $product = Product::whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($product->shop_id === $shop->id, 403, 'This product belongs to another shop.');

        return $product;
    }

    public function hasUnfinishedOrders(Product $product): bool
    {
        return $product->orderItems()->whereHas('order', fn ($order) => $order->whereNotIn('status', ['completed', 'cancelled', 'canceled', 'returned']))->exists();
    }

    public function remove(Product $product): string
    {
        if ($this->hasUnfinishedOrders($product)) {
            throw ValidationException::withMessages(['product' => 'This product has unfinished orders. Finish them before deleting it. You can archive the listing to pause new purchases.']);
        }
        if ($product->hasRetainedReferences()) {
            $product->update(['status' => 'archived']);

            return 'archived';
        }
        $product->delete();

        return 'deleted';
    }

    public function duplicate(Shop $shop, Product $product): Product
    {
        abort_unless($product->shop_id === $shop->id, 403);
        if ($product->compliance_restricted) {
            throw ValidationException::withMessages(['product' => 'Ask Platform Admin to review this restriction before copying the listing.']);
        }
        app(ShopEligibilityService::class)->categoryFor($shop, $product->category_id);
        $copy = $product->replicate(['slug', 'sku', 'rating', 'sales_count', 'compliance_restricted', 'moderation_version', 'low_stock_active', 'low_stock_cycle'])->withoutRelations();
        $copy->status = 'draft';
        $copy->sku = 'BGO-COPY-'.Str::upper(Str::random(12));
        if (($copy->variants['pricing_mode'] ?? null) === 'independent') {
            $variants = $copy->variants;
            foreach ($variants['combinations'] as $index => &$row) {
                $row['sku'] = $copy->sku.'-'.($index + 1);
            }
            unset($row);
            $copy->variants = $variants;
        }
        $copy->save();
        foreach ($product->images()->orderBy('sort_order')->orderBy('id')->get() as $image) {
            $copy->images()->create($image->only(['image_url', 'sort_order', 'is_primary']));
        }

        $copy->storeCategories()->sync($product->storeCategories()->pluck('store_categories.id')->all());

        return $copy;
    }

    public function bulk(Shop $shop, array $ids, string $action): array
    {
        // Validate the complete selection before changing any owned row.
        $products = Product::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($products->count() === count($ids) && $products->every(fn ($product) => $product->shop_id === $shop->id), 403, 'Choose products from your own shop.');
        $results = [];
        foreach ($products as $product) {
            if ($action === 'deactivate') {
                $product->update(['status' => 'archived']);
                $result = 'archived';
                $reason = 'New purchases are paused.';
            } elseif ($this->hasUnfinishedOrders($product)) {
                $result = 'skipped';
                $reason = 'This product has unfinished orders.';
            } else {
                $result = $this->remove($product);
                $reason = $result === 'archived' ? 'Archived to preserve saved references and history.' : 'Product deleted.';
            }
            $results[] = ['id' => $product->id, 'name' => $product->name, 'result' => $result, 'reason' => $reason];
        }

        return $results;
    }
}
