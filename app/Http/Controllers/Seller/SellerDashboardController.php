<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Category;
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
    use HasSellerShop;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = $this->getActiveShop($request);

        $totalProducts = Product::where('shop_id', $shop->id)->count();
        $lowStockCount = Product::where('shop_id', $shop->id)->where('stock', '<=', 5)->count();
        
        $totalSales = OrderItem::where('shop_id', $shop->id)->sum('quantity');
        $totalRevenue = OrderItem::where('shop_id', $shop->id)->sum('subtotal');

        // Order Pipeline metrics (Canonical 13-stage lifecycle support)
        $pendingPackCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging']))
            ->count();

        $readyPickupCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['ready_for_pickup']))
            ->count();

        $shippedCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped']))
            ->count();

        $completedCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['delivered', 'completed']))
            ->count();

        // Cancellation & Return claims count (cancelled/returned orders + actionable disputes)
        $cancelledCount = OrderItem::where('shop_id', $shop->id)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['cancelled', 'canceled', 'returned', 'delivery_failed']))
            ->count();
        $returnCount = $cancelledCount;

        // 7-day revenue analytics
        $dailySales = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $dayLabel = now()->subDays($i)->format('M j');
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
                'returnCount' => $returnCount,
            ],
            'dailySales' => $dailySales,
            'recentOrders' => $recentOrders,
            'topProducts' => $topProducts,
            'availableShops' => $this->getAvailableShops($request),
            'categories' => Category::where('is_active', true)->select('id', 'name', 'slug')->get(),
        ]);
    }

    public function switchShop(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shop_id' => 'required|exists:shops,id',
        ]);

        $shop = Shop::where('id', $validated['shop_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $request->session()->put('active_seller_shop_id', $shop->id);

        return back()->with('success', "Switched active store profile to {$shop->name}.");
    }

    public function createShop(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'root_category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string|max:1000',
        ]);

        $slug = Str::slug($validated['name'] . '-' . $request->user()->id . '-' . Str::random(4));

        $shop = Shop::create([
            'user_id' => $request->user()->id,
            'root_category_id' => $validated['root_category_id'],
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? 'Verified specialty shop on BagooPH.',
            'phone' => $request->user()->phone ?? '+63 912 345 6789',
            'address' => $request->user()->address ?? 'Warehouse 4B, Industrial Park',
            'city' => $request->user()->city ?? 'Metro Manila',
            'status' => 'active',
            'rating' => 5.00,
            'is_default' => false,
        ]);

        $request->session()->put('active_seller_shop_id', $shop->id);

        return back()->with('success', "Shop '{$shop->name}' created successfully with dedicated root category enclosure.");
    }

    public function reports(Request $request): Response
    {
        $user = $request->user();
        $shop = $this->getActiveShop($request);

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
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/Settings', [
            'shop' => $shop,
            'availableShops' => $this->getAvailableShops($request),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $shop = $this->getActiveShop($request);

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
        $shop = $this->getActiveShop($request);

        return Inertia::render('Seller/Profile', [
            'user' => $user,
            'shop' => $shop,
            'availableShops' => $this->getAvailableShops($request),
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
        $shop = $this->getActiveShop($request);
        $shop->load('user');
        $shop->loadCount(['products' => function ($q) {
            $q->where('status', 'active');
        }]);

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
