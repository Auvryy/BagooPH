<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerDashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. Active Shipments with Live Telemetry
        $activeOrders = Order::with(['delivery', 'items.product', 'items.shop'])
            ->where('buyer_id', $user->id)
            ->whereIn('status', ['processing', 'ready_for_pickup', 'shipped'])
            ->latest()
            ->get();

        // 2. Recent Order History
        $recentOrders = Order::with(['delivery', 'items.product', 'items.shop'])
            ->where('buyer_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        // 3. User's Active Cart
        $cart = Cart::with(['items.product.shop', 'items.product.category'])
            ->where('user_id', $user->id)
            ->first();

        // 4. Recommended Products across 14 Departments
        $recommendedProducts = Product::with(['shop', 'category'])
            ->where('status', 'active')
            ->where('stock', '>', 0)
            ->orderBy('rating', 'desc')
            ->take(8)
            ->get();

        // 5. 14 Verified Departments
        $categories = Category::where('is_active', true)
            ->withCount('products')
            ->orderBy('id')
            ->get();

        // 6. Platform Vouchers & Discounts Wallet
        $vouchers = [
            [
                'code' => 'BAGOO10',
                'title' => '10% Platform Discount',
                'description' => 'Valid on all 14 departments with min spend of ₱1,000.',
                'discount' => '10% OFF',
                'min_spend' => 1000,
                'expires_at' => '30 Days Remaining',
                'badge' => 'PLATFORM PRIVILEGE',
            ],
            [
                'code' => 'FREESHIP',
                'title' => 'Zero Courier Surcharge',
                'description' => 'Free doorstep courier delivery for orders above ₱1,500.',
                'discount' => 'FREE SHIPPING',
                'min_spend' => 1500,
                'expires_at' => '14 Days Remaining',
                'badge' => 'COURIER FLEET',
            ],
            [
                'code' => 'WELCOME50',
                'title' => '₱50 Instant Welcome Credit',
                'description' => 'Direct reduction applied at checkout for first-time shoppers.',
                'discount' => '₱50 OFF',
                'min_spend' => 500,
                'expires_at' => 'No Expiry',
                'badge' => 'NEW SHOPPER',
            ],
        ];

        // 7. Aggregate Buyer Telemetry Stats
        $stats = [
            'total_orders' => Order::where('buyer_id', $user->id)->count(),
            'active_shipments' => $activeOrders->count(),
            'completed_orders' => Order::where('buyer_id', $user->id)->where('status', 'delivered')->count(),
            'cart_items' => $cart ? $cart->items->sum('quantity') : 0,
            'total_spent' => Order::where('buyer_id', $user->id)->where('payment_status', 'paid')->sum('total_amount'),
        ];

        return Inertia::render('Buyer/Dashboard', [
            'activeOrders' => $activeOrders,
            'recentOrders' => $recentOrders,
            'cart' => $cart,
            'recommendedProducts' => $recommendedProducts,
            'categories' => $categories,
            'vouchers' => $vouchers,
            'stats' => $stats,
        ]);
    }
}
