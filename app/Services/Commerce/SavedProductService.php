<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\SavedProduct;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\ShopEligibilityService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SavedProductService
{
    public function save(User $actor, Product $product): void
    {
        DB::transaction(function () use ($actor, $product) {
            try {
                app(ShopEligibilityService::class)->lockSaleProducts([$product->id], [$actor->id]);
            } catch (RuntimeException $exception) {
                app(BuyerAccessService::class)->requirePortal($actor);
                // An unchanged retry can retain an entry whose listing became unavailable.
                if (SavedProduct::where('buyer_id', $actor->id)->where('product_id', $product->id)->exists()) {
                    return;
                }
                throw ValidationException::withMessages(['product_id' => $exception->getMessage()]);
            }
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            SavedProduct::firstOrCreate(['buyer_id' => $buyer->id, 'product_id' => $product->id]);
        });
    }

    public function remove(User $actor, int $productId): void
    {
        DB::transaction(function () use ($actor, $productId) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            SavedProduct::where('buyer_id', $buyer->id)->where('product_id', $productId)->delete();
        });
    }

    public function paginate(User $actor): LengthAwarePaginator
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $query = SavedProduct::where('buyer_id', $buyer->id)->latest('id');
        $page = (clone $query)->paginate(24);
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(24, ['*'], 'page', $page->lastPage());
        }
        $publicProducts = app(BuyerDiscoveryService::class)->catalogue()->whereIn('products.id', $page->pluck('product_id'))->get()->keyBy('id');

        return $page->through(fn (SavedProduct $entry) => [
            'id' => $entry->id,
            'product_id' => $entry->product_id,
            'saved_at' => $entry->created_at,
            'product' => $publicProducts->get($entry->product_id),
            'available' => ($publicProducts->get($entry->product_id)?->stock ?? 0) > 0,
            'unavailable_reason' => $publicProducts->has($entry->product_id) ? 'Out of stock' : 'This saved product is currently unavailable.',
        ]);
    }
}
