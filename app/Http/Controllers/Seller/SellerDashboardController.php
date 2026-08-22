<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name . "'s Store",
                'slug' => \Illuminate\Support\Str::slug($user->name . '-store-' . $user->id),
                'description' => 'Welcome to our official store on Bagoo.',
                'phone' => $user->phone ?? '+1 (555) 000-0000',
                'address' => $user->address ?? 'Main Street',
                'city' => $user->city ?? 'Metro City',
                'status' => 'active',
            ]
        );

        $totalProducts = Product::where('shop_id', $shop->id)->count();
        $totalSales = OrderItem::where('shop_id', $shop->id)->sum('quantity');
        $totalRevenue = OrderItem::where('shop_id', $shop->id)->sum('subtotal');

        $recentOrders = OrderItem::where('shop_id', $shop->id)
            ->with(['order.buyer', 'product'])
            ->latest()
            ->take(5)
            ->get();

        $topProducts = Product::where('shop_id', $shop->id)
            ->orderBy('sales_count', 'desc')
            ->take(5)
            ->get();

        return Inertia::render('Seller/Dashboard', [
            'shop' => $shop,
            'stats' => [
                'totalProducts' => $totalProducts,
                'totalSales' => $totalSales,
                'totalRevenue' => (float) $totalRevenue,
            ],
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
        ]);
    }
}
