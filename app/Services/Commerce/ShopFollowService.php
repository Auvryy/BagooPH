<?php

namespace App\Services\Commerce;

use App\Models\Shop;
use App\Models\ShopFollow;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopFollowService
{
    public function follow(User $actor, Shop $shop): void
    {
        DB::transaction(function () use ($actor, $shop) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            $attributes = ['buyer_id' => $buyer->id, 'shop_id' => $shop->id];
            if (ShopFollow::where($attributes)->exists()) {
                return;
            }
            if (! Shop::eligible()->whereKey($shop->id)->exists()) {
                throw ValidationException::withMessages(['shop_id' => 'This shop is currently unavailable.']);
            }
            ShopFollow::firstOrCreate($attributes);
        });
    }

    public function unfollow(User $actor, int $shopId): void
    {
        DB::transaction(function () use ($actor, $shopId) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            ShopFollow::where('buyer_id', $buyer->id)->where('shop_id', $shopId)->delete();
        });
    }

    public function paginate(User $actor): LengthAwarePaginator
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $query = ShopFollow::where('buyer_id', $buyer->id)->latest('id');
        $page = (clone $query)->paginate(24);
        if ($page->currentPage() > $page->lastPage()) {
            $page = (clone $query)->paginate(24, ['*'], 'page', $page->lastPage());
        }
        $shops = Shop::eligible()->whereIn('id', $page->pluck('shop_id'))
            ->get(['id', 'name', 'slug', 'logo', 'description'])->keyBy('id');

        return $page->withPath(request()->getPathInfo())->through(fn (ShopFollow $follow) => [
            'id' => $follow->id,
            'shop_id' => $follow->shop_id,
            'shop' => $shops->get($follow->shop_id),
        ]);
    }
}
