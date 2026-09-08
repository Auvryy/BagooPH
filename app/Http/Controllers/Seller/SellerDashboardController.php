<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
        ];

        if ($request->hasFile('logo')) {
            $rules['logo'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } elseif ($request->hasFile('logo_file')) {
            $rules['logo_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } else {
            $rules['logo'] = 'nullable|string|max:1000';
        }

        if ($request->hasFile('banner')) {
            $rules['banner'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:5120';
        } elseif ($request->hasFile('banner_file')) {
            $rules['banner_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:5120';
        } else {
            $rules['banner'] = 'nullable|string|max:1000';
        }

        $validated = $request->validate($rules);

        if ($request->hasFile('logo') || $request->hasFile('logo_file')) {
            $file = $request->file('logo') ?? $request->file('logo_file');
            $path = $file->store('shops/logos', 'public');
            $shop->logo = '/storage/' . $path;
        } elseif ($request->filled('logo')) {
            $shop->logo = $validated['logo'];
        }

        if ($request->hasFile('banner') || $request->hasFile('banner_file')) {
            $file = $request->file('banner') ?? $request->file('banner_file');
            $path = $file->store('shops/banners', 'public');
            $shop->banner = '/storage/' . $path;
        } elseif ($request->filled('banner')) {
            $shop->banner = $validated['banner'];
        }

        if (isset($validated['name'])) $shop->name = $validated['name'];
        if (array_key_exists('description', $validated)) $shop->description = $validated['description'];
        if (isset($validated['phone'])) $shop->phone = $validated['phone'];
        if (isset($validated['address'])) $shop->address = $validated['address'];
        if (isset($validated['city'])) $shop->city = $validated['city'];

        $shop->save();

        return back()->with('success', 'Storefront settings updated successfully.');
    }

    public function profile(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first() ?? Shop::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $user->name . "'s Store",
                'slug' => Str::slug($user->name . '-store-' . $user->id),
                'description' => 'Welcome to our official verified storefront on BagooPH.',
                'phone' => $user->phone ?? '+63 912 345 6789',
                'address' => $user->address ?? 'Warehouse 4B, Industrial Park',
                'city' => $user->city ?? 'Metro Manila',
                'status' => 'active',
                'rating' => 4.95,
            ]
        );

        return Inertia::render('Seller/Profile', [
            'user' => $user,
            'shop' => $shop,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'required|email|max:255|unique:users,email,' . $user->id,
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:3072',
            'remove_avatar' => 'nullable|boolean',
        ]);

        if ($request->boolean('remove_avatar')) {
            if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $user->avatar);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $user->avatar = null;
        } elseif ($request->hasFile('avatar')) {
            if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $user->avatar);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar = '/storage/' . $path;
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->save();

        return back()->with('success', 'Profile updated successfully.');
    }

    public function previewStorefront(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::with('user')
            ->withCount(['products' => function ($q) {
                $q->where('status', 'active');
            }])
            ->where('user_id', $user->id)
            ->first();

        if (!$shop) {
            $shop = Shop::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $user->name . "'s Store",
                    'slug' => Str::slug($user->name . '-store-' . $user->id),
                    'description' => 'Welcome to our official verified storefront on BagooPH.',
                    'phone' => $user->phone ?? '+63 912 345 6789',
                    'address' => $user->address ?? 'Warehouse 4B, Industrial Park',
                    'city' => $user->city ?? 'Metro Manila',
                    'status' => 'active',
                    'rating' => 4.95,
                ]
            );
        }

        $products = Product::where('shop_id', $shop->id)
            ->where('status', 'active')
            ->with('images')
            ->latest()
            ->paginate(12);

        return Inertia::render('Marketplace/ShopDetail', [
            'shop' => $shop,
            'products' => $products,
            'isOwner' => true,
            'isPreview' => true,
        ]);
    }
}
