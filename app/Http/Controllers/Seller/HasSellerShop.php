<?php

namespace App\Http\Controllers\Seller;

use App\Models\Shop;
use App\Services\ShopEligibilityService;
use Illuminate\Http\Request;

trait HasSellerShop
{
    protected function getActiveShop(Request $request, bool $history = false): Shop
    {
        if (! $history && $request->attributes->has('locked_seller_shop')) {
            return $request->attributes->get('locked_seller_shop');
        }

        return app(ShopEligibilityService::class)->context($request, history: $history);
    }
}
