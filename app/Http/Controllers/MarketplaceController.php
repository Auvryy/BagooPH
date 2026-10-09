<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Commerce\ReviewService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MarketplaceController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])->availableForSale()->withReviewSummary()
            ->where('status', 'active');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->input('category'));
            });
        }

        if ($request->filled('sort')) {
            switch ($request->input('sort')) {
                case 'price_low':
                    $query->orderBy('price', 'asc');
                    break;
                case 'price_high':
                    $query->orderBy('price', 'desc');
                    break;
                case 'popular':
                    $query->orderBy('sales_count', 'desc');
                    break;
                case 'rating':
                    $query->orderBy('verified_rating', 'desc');
                    break;
                default:
                    $query->latest();
                    break;
            }
        } else {
            $query->latest();
        }

        $products = $query->paginate(12)->withQueryString();
        $categories = Category::where('is_active', true)->withCount('products')->get();
        $featuredShops = Shop::eligible()->withReviewSummary()->withCount('products')->take(4)->get();

        return Inertia::render('Marketplace/Index', [
            'products' => $products,
            'categories' => $categories,
            'featuredShops' => $featuredShops,
            'filters' => $request->only(['search', 'category', 'sort']),
        ]);
    }

    public function catalog(Request $request): Response
    {
        $query = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'images'])->availableForSale()->withReviewSummary()
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

        if ($request->filled('in_stock') && $request->boolean('in_stock')) {
            $query->where('stock', '>', 0);
        }

        if ($request->filled('min_price')) {
            $query->where('price', '>=', (float) $request->input('min_price'));
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', (float) $request->input('max_price'));
        }

        switch ($request->input('sort')) {
            case 'price_asc':
            case 'price_low':
                $query->orderBy('price', 'asc');
                break;
            case 'price_desc':
            case 'price_high':
                $query->orderBy('price', 'desc');
                break;
            case 'popular':
                $query->orderBy('sales_count', 'desc');
                break;
            case 'rating':
                $query->orderBy('verified_rating', 'desc');
                break;
            default:
                $query->latest();
                break;
        }

        $products = $query->paginate(12)->withQueryString();
        $categories = Category::where('is_active', true)->withCount('products')->orderBy('id')->get();
        $featuredShops = Shop::eligible()->withReviewSummary()->withCount('products')->take(4)->get();

        // If authenticated as buyer, fetch latest active shipment telemetry
        $activeShipment = null;
        if (auth()->check() && auth()->user()->role === 'buyer') {
            $activeOrder = Order::with(['delivery', 'items.product'])
                ->where('buyer_id', auth()->id())
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
                    'estimated_delivery' => $activeOrder->delivery->estimated_delivery_at ? $activeOrder->delivery->estimated_delivery_at->diffForHumans() : 'Within 24 Hours',
                    'item_name' => $activeOrder->items->first()?->product?->name ?? 'Ordered Package',
                    'item_count' => $activeOrder->items->count(),
                ];
            }
        }

        return Inertia::render('Marketplace/Catalog', [
            'products' => $products,
            'categories' => $categories,
            'featuredShops' => $featuredShops,
            'activeShipment' => $activeShipment,
            'filters' => $request->only(['search', 'category', 'sort', 'in_stock', 'min_price', 'max_price']),
        ]);
    }

    public function show(string $slug): Response
    {
        $product = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'images'])->availableForSale()->withReviewSummary()
            ->where('status', 'active')
            ->where(function ($q) use ($slug) {
                $q->where('slug', $slug);
                if (is_numeric($slug)) {
                    $q->orWhere('id', (int) $slug);
                }
            })
            ->first();

        if (! $product && preg_match('/(?:-i\.|\.)?(\d+)$/', $slug, $matches)) {
            $product = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'images'])->availableForSale()->withReviewSummary()
                ->where('status', 'active')
                ->where('id', (int) $matches[1])
                ->first();
        }

        if (! $product) {
            abort(404, 'Product not found.');
        }

        $relatedProducts = Product::where('category_id', $product->category_id)->availableForSale()->withReviewSummary()
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->take(4)
            ->get();

        app(ReviewService::class)->publicReviews($product);
        $product->shop->setAttribute('rating', app(ReviewService::class)->shopSummary($product->shop)['rating']);

        return Inertia::render('Marketplace/ProductDetail', [
            'product' => $product,
            'relatedProducts' => $relatedProducts,
        ]);
    }

    public function shop(Request $request, string $slug): Response
    {
        $shop = Shop::with('user')->eligible()
            ->withCount(['products' => function ($q) {
                $q->where('status', 'active');
            }])
            ->where('slug', $slug)
            ->firstOrFail();

        $products = Product::where('shop_id', $shop->id)->availableForSale()->withReviewSummary()
            ->where('status', 'active')
            ->latest()
            ->paginate(18);

        $currentUser = auth()->user();
        $isOwner = $currentUser && $currentUser->isSeller() && $currentUser->canAccessPortal()
            && $currentUser->id === $shop->user_id;
        $isPreview = $request->has('preview') || (bool) $isOwner;

        return Inertia::render('Marketplace/ShopDetail', [
            'shop' => $shop,
            'shopStats' => app(ReviewService::class)->shopSummary($shop),
            'products' => $products,
            'isOwner' => (bool) $isOwner,
            'isPreview' => (bool) $isPreview,
        ]);
    }

    public function updateBranding(Request $request, string $slug): RedirectResponse
    {
        return DB::transaction(function () use ($request, $slug) {
            $user = User::whereKey($request->user()?->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isSeller() && $user->canAccessPortal(), 403);
            $shop = Shop::where('slug', $slug)->where('user_id', $user->id)->lockForUpdate()->first();
            abort_unless($shop, 403);
            $eligibility = app(ShopEligibilityService::class);
            $eligibility->lockCategories();
            $eligibility->assertEligible($shop);
            $eligibility->protectReviewedDetails($shop, $request->all());

            return $this->saveBranding($request, $shop);
        }, 3);
    }

    private function saveBranding(Request $request, Shop $shop): RedirectResponse
    {
        $rules = [
            'description' => 'nullable|string',
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
            $shop->logo = '/storage/'.$path;
        } elseif ($request->filled('logo')) {
            $shop->logo = $validated['logo'];
        }

        if ($request->hasFile('banner') || $request->hasFile('banner_file')) {
            $file = $request->file('banner') ?? $request->file('banner_file');
            $path = $file->store('shops/banners', 'public');
            $shop->banner = '/storage/'.$path;
        } elseif ($request->filled('banner')) {
            $shop->banner = $validated['banner'];
        }

        if (isset($validated['name'])) {
            $shop->name = $validated['name'];
        }
        if (array_key_exists('description', $validated)) {
            $shop->description = $validated['description'];
        }
        if (isset($validated['phone'])) {
            $shop->phone = $validated['phone'];
        }
        if (isset($validated['address'])) {
            $shop->address = $validated['address'];
        }
        if (isset($validated['city'])) {
            $shop->city = $validated['city'];
        }

        $shop->save();

        return back()
            ->with('message', 'Storefront branding and bio updated successfully.')
            ->with('success', 'Storefront branding and bio updated successfully.');
    }
}
