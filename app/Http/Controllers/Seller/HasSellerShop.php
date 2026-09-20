<?php

namespace App\Http\Controllers\Seller;

use App\Models\Category;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait HasSellerShop
{
    /**
     * Resolve the active shop context for the authenticated merchant.
     */
    protected function getActiveShop(Request $request): Shop
    {
        $user = $request->user();
        $sessionShopId = $request->session()->get('active_seller_shop_id');

        if ($sessionShopId) {
            $shop = Shop::where('id', $sessionShopId)
                ->where('user_id', $user->id)
                ->with('rootCategory')
                ->first();
            if ($shop) {
                return $shop;
            }
        }

        $defaultShop = Shop::where('user_id', $user->id)
            ->where('is_default', true)
            ->with('rootCategory')
            ->first() ?? Shop::where('user_id', $user->id)
                ->with('rootCategory')
                ->first();

        if ($defaultShop) {
            $request->session()->put('active_seller_shop_id', $defaultShop->id);
            return $defaultShop;
        }

        // Auto-assign default root category if none exists
        $firstCategory = Category::first();

        // Create initial default shop if merchant has none
        $newShop = Shop::create([
            'user_id' => $user->id,
            'root_category_id' => $firstCategory?->id,
            'name' => $user->name . "'s Store",
            'slug' => Str::slug($user->name . '-store-' . $user->id),
            'description' => 'Welcome to our official verified storefront on BagooPH.',
            'phone' => $user->phone ?? '+63 912 345 6789',
            'address' => $user->address ?? 'Warehouse 4B, Industrial Park',
            'city' => $user->city ?? 'Metro Manila',
            'status' => 'active',
            'rating' => 5.00,
            'is_default' => true,
        ]);

        $request->session()->put('active_seller_shop_id', $newShop->id);
        return $newShop;
    }

    /**
     * Retrieve all available shops for multi-store profile switching under one master login.
     */
    protected function getAvailableShops(Request $request)
    {
        return Shop::with('rootCategory:id,name,slug')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->get();
    }
}
