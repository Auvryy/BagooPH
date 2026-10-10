<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\Commerce\BuyerDiscoveryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BuyerHomeController extends Controller
{
    public function index(Request $request): InertiaResponse|SymfonyResponse
    {
        $user = $request->user();
        $host = $request->getHost();

        // Prevent worker subdomains from accidentally rendering the buyer marketplace
        if (str_starts_with($host, 'seller.')) {
            if ($user && $user->isSeller()) {
                return redirect('/dashboard');
            }

            return Inertia::render('Seller/Landing');
        }
        if (str_starts_with($host, 'courier.')) {
            if ($user && $user->isCourier()) {
                return redirect('/deliveries');
            }

            return app(AuthenticatedSessionController::class)->createCourier();
        }
        if (str_starts_with($host, 'hub.')) {
            if ($user && ($user->isLogistics() || $user->isAdmin())) {
                return redirect('/dashboard');
            }

            return app(AuthenticatedSessionController::class)->createHub();
        }
        if (str_starts_with($host, 'admin.')) {
            if ($user && $user->isAdmin()) {
                return redirect('/dashboard');
            }

            return app(AuthenticatedSessionController::class)->createAdmin();
        }

        // 1. Promotional Hero Carousel Banners
        $banners = [
            [
                'id' => 1,
                'title' => '8.8 MEGA PAYDAY SALE',
                'subtitle' => 'UP TO 70% OFF ON ALL 14 DEPARTMENTS',
                'tag' => 'LIMITED TIME ONLY',
                'code' => 'PAYDAY70',
                'image' => 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=1400&q=80',
                'cta' => 'Claim Vouchers Now',
                'badge' => 'PLATFORM MEGA EVENT',
            ],
            [
                'id' => 2,
                'title' => 'FREE SHIPPING ₱0 MIN SPEND',
                'subtitle' => 'ZERO COURIER SURCHARGE WITH BAGOO EXPRESS',
                'tag' => 'NATIONWIDE DISPATCH',
                'code' => 'FREESHIP',
                'image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1400&q=80',
                'cta' => 'Shop Free Delivery',
                'badge' => 'DOORSTEP GUARANTEE',
            ],
            [
                'id' => 3,
                'title' => 'NEW SHOPPER PRIVILEGE: ₱200 OFF',
                'subtitle' => 'INSTANT CASH DISCOUNT APPLIED AT CHECKOUT',
                'tag' => 'WELCOME VOUCHER',
                'code' => 'BAGOO10',
                'image' => 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=1400&q=80',
                'cta' => 'Claim ₱200 Bonus',
                'badge' => 'FIRST ORDER EXCLUSIVE',
            ],
        ];

        // 2. 8 Quick Service Icon Actions
        $quickServices = [
            ['id' => 'freeship', 'name' => 'Free Shipping', 'icon' => 'Truck', 'tag' => '₱0 Min'],
            ['id' => 'new', 'name' => 'New Arrivals', 'icon' => 'Sparkles', 'tag' => 'Just In'],
            ['id' => 'mall', 'name' => 'Bagoo Mall', 'icon' => 'ShieldCheck', 'tag' => '100% Authentic'],
            ['id' => 'vouchers', 'name' => 'Vouchers', 'icon' => 'Tag', 'tag' => 'Claim All'],
            ['id' => 'top', 'name' => 'Top Rankings', 'icon' => 'TrendingUp', 'tag' => 'Best Seller'],
            ['id' => 'global', 'name' => 'Global Finds', 'icon' => 'Globe', 'tag' => 'Direct Import'],
            ['id' => 'cashback', 'name' => '15% Cashback', 'icon' => 'Coins', 'tag' => 'Coins Back'],
            ['id' => 'vip', 'name' => 'VIP Member', 'icon' => 'Crown', 'tag' => 'Perks'],
        ];

        // 4. 14 Verified Departments with Visual Data
        $categories = Category::where('is_active', true)
            ->withCount(['products' => fn ($products) => $products->availableForSale()])
            ->orderBy('id')
            ->get();

        // 5. "Daily Discover" & Search Product Feed
        $query = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])->availableForSale()->withReviewSummary()
            ->where('status', 'active');

        $discovery = app(BuyerDiscoveryService::class);
        if ($request->input('sort') === 'new_arrivals' || (! $request->filled('sort') && $request->input('tab') === 'new_arrivals')) {
            $request->query->set('sort', 'newest');
        }
        $filters = $discovery->selection($request);
        $feedProducts = $discovery->paginate($discovery->filter($query, $filters), $filters, 18);

        // 5b. Related / Suggested Products (when search is active or when feed has few results)
        $relatedProducts = [];
        if ($request->filled('search') || $request->filled('category')) {
            $matchedIds = $feedProducts->pluck('id')->toArray();
            $relatedQuery = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])->availableForSale()->withReviewSummary()
                ->where('status', 'active')
                ->whereNotIn('id', $matchedIds);

            if ($request->filled('category') && $request->input('category') !== 'all') {
                $relatedQuery->whereHas('category', function ($q) use ($request) {
                    $q->where('slug', $request->input('category'));
                });
            }

            $relatedProducts = $relatedQuery->orderBy('sales_count', 'desc')
                ->take(6)
                ->get();
        }

        // 6. Active Vouchers in Platform
        $vouchers = [
            [
                'code' => 'BAGOO10',
                'discount' => '₱200 OFF',
                'min_spend' => 1000,
                'description' => 'Min. Spend ₱1,000 across all 14 departments',
                'expires' => 'Valid today',
            ],
            [
                'code' => 'FREESHIP',
                'discount' => 'FREE SHIPPING',
                'min_spend' => 0,
                'description' => '₱0 Min. Spend on Bagoo Express Standard',
                'expires' => 'Expiring in 2 days',
            ],
            [
                'code' => 'PAYDAY70',
                'discount' => '15% CASHBACK',
                'min_spend' => 500,
                'description' => 'Capped at 150 Coins for verified buyers',
                'expires' => 'Payday special',
            ],
        ];

        // 7. Active in-transit shipment telemetry if logged in
        $activeShipment = null;
        if ($user) {
            $activeOrder = Order::with(['delivery', 'items.product'])
                ->where('buyer_id', $user->id)
                ->whereIn('status', ['processing', 'ready_for_pickup', 'shipped'])
                ->latest()
                ->first();

            if ($activeOrder && $activeOrder->delivery) {
                $activeShipment = [
                    'order_id' => $activeOrder->id,
                    'order_number' => $activeOrder->order_number,
                    'status' => $activeOrder->status,
                    'tracking_number' => $activeOrder->delivery->tracking_number,
                    'courier_name' => $activeOrder->delivery->logistics_partner,
                    'item_name' => $activeOrder->items->first()?->product?->name ?? 'Package',
                    'item_count' => $activeOrder->items->count(),
                    'estimated_delivery' => $activeOrder->delivery->estimated_delivery_at ? $activeOrder->delivery->estimated_delivery_at->diffForHumans() : 'Within 24 Hours',
                ];
            }
        }

        return Inertia::render('Buyer/Home', [
            'banners' => $banners,
            'quickServices' => $quickServices,
            'categories' => $categories,
            'feedProducts' => $feedProducts,
            'relatedProducts' => $relatedProducts,
            'vouchers' => $vouchers,
            'activeShipment' => $activeShipment,
            'filters' => [...$filters, 'tab' => match ($filters['sort']) {
                'newest' => 'new_arrivals', 'top_sales', 'top_rated' => $filters['sort'], default => 'all',
            }],
        ]);
    }
}
