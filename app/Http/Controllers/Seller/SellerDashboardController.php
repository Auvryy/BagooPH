<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
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
                'description' => 'Welcome to our official verified storefront on BagooPH.',
                'phone' => $user->phone ?? '+63 912 345 6789',
                'address' => $user->address ?? 'Warehouse 4B, Industrial Park',
                'city' => $user->city ?? 'Metro Manila',
                'status' => 'active',
                'rating' => 4.95,
            ]
        );

        $totalProducts = Product::where('shop_id', $shop->id)->count();
        $lowStockCount = Product::where('shop_id', $shop->id)->where('stock', '<=', 5)->count();
        
        $totalSales = OrderItem::where('shop_id', $shop->id)->sum('quantity');
        $totalRevenue = OrderItem::where('shop_id', $shop->id)->sum('subtotal');

        // Order Pipeline metrics
        $pendingPackCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->where('status', 'processing'))
            ->count();

        $readyPickupCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->where('status', 'ready_for_pickup'))
            ->count();

        $shippedCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->where('status', 'shipped'))
            ->count();

        $completedCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->where('status', 'delivered'))
            ->count();

        // 7-day revenue analytics
        $dailySales = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayLabel = now()->subDays($i)->format('M d');
            $revenue = OrderItem::where('shop_id', $shop->id)
                ->whereDate('created_at', $date)
                ->sum('subtotal');
            $units = OrderItem::where('shop_id', $shop->id)
                ->whereDate('created_at', $date)
                ->sum('quantity');

            $dailySales[] = [
                'date' => $dayLabel,
                'revenue' => (float) $revenue,
                'units' => (int) $units,
            ];
        }

        $recentOrders = OrderItem::where('shop_id', $shop->id)
            ->with(['order.buyer', 'order.delivery', 'product'])
            ->latest()
            ->take(6)
            ->get();

        $topProducts = Product::where('shop_id', $shop->id)
            ->with('category')
            ->orderBy('sales_count', 'desc')
            ->take(5)
            ->get();

        return Inertia::render('Seller/Dashboard', [
            'shop' => $shop,
            'stats' => [
                'totalProducts' => $totalProducts,
                'lowStockCount' => $lowStockCount,
                'totalSales' => (int) $totalSales,
                'totalRevenue' => (float) $totalRevenue,
                'pendingPackCount' => $pendingPackCount,
                'readyPickupCount' => $readyPickupCount,
                'shippedCount' => $shippedCount,
                'completedCount' => $completedCount,
            ],
            'dailySales' => $dailySales,
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
        ]);
    }

    public function reports(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        $fromDate = $request->input('from_date', now()->subDays(30)->format('Y-m-d'));
        $toDate = $request->input('to_date', now()->format('Y-m-d'));

        $query = OrderItem::where('shop_id', $shop?->id ?? 0)
            ->whereDate('created_at', '>=', $fromDate)
            ->whereDate('created_at', '<=', $toDate)
            ->with(['order.buyer', 'product.category']);

        $orderItems = $query->get();

        $grossSales = (float) $orderItems->sum('subtotal');
        $totalUnits = (int) $orderItems->sum('quantity');
        $platformCommission = $grossSales * 0.10; // 10% platform fee
        $netPayout = $grossSales - $platformCommission;
        $orderCount = $orderItems->pluck('order_id')->unique()->count();
        $avgOrderValue = $orderCount > 0 ? $grossSales / $orderCount : 0;

        return Inertia::render('Seller/Reports', [
            'shop' => $shop,
            'filters' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ],
            'report' => [
                'grossSales' => $grossSales,
                'totalUnits' => $totalUnits,
                'platformCommission' => $platformCommission,
                'netPayout' => $netPayout,
                'orderCount' => $orderCount,
                'avgOrderValue' => $avgOrderValue,
            ],
            'orderItems' => $orderItems->take(25),
        ]);
    }

    public function settings(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        return Inertia::render('Seller/Settings', [
            'shop' => $shop,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'phone' => 'required|string|max:50',
            'address' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'logo' => 'nullable|string',
            'banner' => 'nullable|string',
        ]);

        $shop->update($validated);

        return back()->with('success', 'Storefront settings updated successfully.');
    }
}
