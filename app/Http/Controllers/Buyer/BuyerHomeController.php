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

class BuyerHomeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // 1. Promotional Hero Carousel Banners (Shopee / SHEIN Style)
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
                'bgGradient' => 'from-[#E00D42] via-[#A80830] to-black',
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
                'bgGradient' => 'from-indigo-900 via-blue-900 to-black',
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
                'bgGradient' => 'from-amber-900 via-red-950 to-black',
            ],
        ];

        // 2. 8 Shopee-Style Quick Service Icon Actions
        $quickServices = [
            ['id' => 'freeship', 'name' => 'Free Shipping', 'icon' => 'Truck', 'color' => 'bg-emerald-500', 'tag' => '₱0 Min'],
            ['id' => 'flash', 'name' => 'Flash Deals', 'icon' => 'Zap', 'color' => 'bg-amber-500', 'tag' => 'Up to 70%'],
            ['id' => 'mall', 'name' => 'Bagoo Mall', 'icon' => 'ShieldCheck', 'color' => 'bg-[#E00D42]', 'tag' => '100% Authentic'],
            ['id' => 'vouchers', 'name' => 'Vouchers', 'icon' => 'Tag', 'color' => 'bg-purple-500', 'tag' => 'Claim All'],
            ['id' => 'top', 'name' => 'Top Rankings', 'icon' => 'TrendingUp', 'color' => 'bg-blue-500', 'tag' => 'Best Seller'],
            ['id' => 'global', 'name' => 'Global Finds', 'icon' => 'Globe', 'color' => 'bg-cyan-500', 'tag' => 'Direct Import'],
            ['id' => 'cashback', 'name' => '15% Cashback', 'icon' => 'Coins', 'color' => 'bg-rose-500', 'tag' => 'Coins Back'],
            ['id' => 'vip', 'name' => 'VIP Member', 'icon' => 'Crown', 'color' => 'bg-yellow-500', 'tag' => 'Perks'],
        ];

        // 3. ⚡ Flash Deals Section (Products with slashed prices & claimed status)
        $flashDeals = Product::with(['shop', 'category'])
            ->where('status', 'active')
            ->where('stock', '>', 0)
            ->inRandomOrder()
            ->take(6)
            ->get()
            ->map(function ($product) {
                $discount = $product->compare_at_price && $product->compare_at_price > $product->price
                    ? round((($product->compare_at_price - $product->price) / $product->compare_at_price) * 100)
                    : rand(25, 60);
                
                $comparePrice = $product->compare_at_price ?: ($product->price * (1 + ($discount / 100)));
                $claimedPercent = rand(55, 92);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'price' => (float)$product->price,
                    'compare_at_price' => (float)$comparePrice,
                    'discount_pct' => $discount,
                    'claimed_percent' => $claimedPercent,
                    'stock' => $product->stock,
                    'featured_image' => $product->featured_image,
                    'category_name' => $product->category?->name ?? 'General',
                ];
            });

        // 4. 14 Verified Departments with Visual Data
        $categories = Category::where('is_active', true)
            ->withCount('products')
            ->orderBy('id')
            ->get();

        // 5. "Daily Discover" Product Feed
        $query = Product::with(['shop', 'category'])
            ->where('status', 'active');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->input('category') !== 'all') {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        switch ($request->input('tab')) {
            case 'top_sales':
                $query->orderBy('sales_count', 'desc');
                break;
            case 'top_rated':
                $query->orderBy('rating', 'desc');
                break;
            case 'new_arrivals':
                $query->latest();
                break;
            default:
                $query->orderBy('sales_count', 'desc')->orderBy('rating', 'desc');
                break;
        }

        $feedProducts = $query->paginate(18)->withQueryString();

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
            'flashDeals' => $flashDeals,
            'categories' => $categories,
            'feedProducts' => $feedProducts,
            'vouchers' => $vouchers,
            'activeShipment' => $activeShipment,
            'filters' => $request->only(['search', 'category', 'tab']),
        ]);
    }
}
