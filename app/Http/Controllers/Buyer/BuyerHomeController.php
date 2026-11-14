<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SubCategory;
use App\Services\Advertisements\AdvertisementReader;
use App\Services\Advertisements\AdvertisementRecommendationService;
use App\Services\Commerce\BuyerDiscoveryService;
use App\Services\Monitoring\EtaCalculationService;
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

        $banners = app(AdvertisementReader::class)->homepage(true, array_slice(
            (array) $request->session()->get('buyer.recent_product_views', []),
            0,
            AdvertisementRecommendationService::MAX_RECENT_PRODUCT_VIEWS,
        ));

        $categories = app(BuyerDiscoveryService::class)->categories();

        // 4. Live Flash Deals (from ProductSale with kind = 'flash' or live offers)
        $flashSaleProducts = Product::with(['shop', 'category', 'sales' => fn ($q) => $q->whereNull('ended_at')->where('ends_at', '>', now())])
            ->whereHas('sales', fn ($q) => $q->where('kind', 'flash')->whereNull('ended_at')->where('starts_at', '<=', now())->where('ends_at', '>', now()))
            ->where('status', 'active')
            ->take(6)
            ->get();

        if ($flashSaleProducts->isEmpty()) {
            $flashSaleProducts = Product::with(['shop', 'category'])
                ->availableForSale()
                ->where('status', 'active')
                ->whereNotNull('compare_at_price')
                ->orderByDesc('sales_count')
                ->take(6)
                ->get();
        }

        // 5. Algorithmic Top Products & Curated Subcategory / Niche Showcases (Row 5)
        $topCuratedCollections = self::curatedCollections();

        $topPersonalizedProducts = Product::with(['shop', 'category', 'subCategory'])
            ->availableForSale()
            ->where('status', 'active')
            ->orderByDesc('sales_count')
            ->take(6)
            ->get()
            ->map(function ($product, $index) use ($topCuratedCollections) {
                $meta = $topCuratedCollections[$index % count($topCuratedCollections)];
                $product->personalized_ribbon = $meta['ribbon'];
                $product->collection_tag = $meta['category_slug'];
                $product->collection_sub = $meta['sub_slug'];
                $product->collection_title = $meta['title'];
                $product->sales_badge = $meta['sales_badge'];
                return $product;
            });

        // 6. "Daily Discover" & Search Product Feed
        $query = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'subCategory'])->availableForSale()->withReviewSummary()
            ->where('status', 'active');

        $discovery = app(BuyerDiscoveryService::class);
        if ($request->input('sort') === 'new_arrivals' || (! $request->filled('sort') && $request->input('tab') === 'new_arrivals')) {
            $request->query->set('sort', 'newest');
        }
        $filters = $discovery->selection($request);
        $feedProducts = $discovery->paginate($discovery->filter($query, $filters), $filters, 18);

        // Subcategories for active category or all active
        $selectedCategory = $filters['category'] !== 'all' && $filters['category'] !== ''
            ? Category::where('slug', $filters['category'])->where('is_active', true)->first()
            : null;

        $subcategoriesQuery = SubCategory::where('is_active', true);
        if ($selectedCategory) {
            $subcategoriesQuery->where('category_id', $selectedCategory->id);
        }
        $subcategories = $subcategoriesQuery->orderBy('sort_order')->get();

        $subCounts = Product::availableForSale()->whereNotNull('sub_category_id')
            ->selectRaw('sub_category_id, count(*) as count')
            ->groupBy('sub_category_id')
            ->pluck('count', 'sub_category_id');

        $subcategories->each(function ($sub) use ($subCounts) {
            $sub->setAttribute('products_count', (int) ($subCounts[$sub->id] ?? 0));
        });

        // 5b. Related / Suggested Products (when search is active or when feed has few results)
        $relatedProducts = [];
        if ($request->filled('search') || $request->filled('category')) {
            $matchedIds = $feedProducts->pluck('id')->toArray();
            $relatedQuery = $discovery->filter($discovery->catalogue(), [...$filters, 'search' => ''])
                ->whereNotIn('id', $matchedIds);

            $relatedProducts = $relatedQuery->orderBy('sales_count', 'desc')
                ->take(6)
                ->get();
        }

        // 7. Active in-transit shipment telemetry if logged in
        $activeShipment = null;
        if ($user) {
            $activeOrder = Order::with(['delivery', 'items.product'])
                ->where('buyer_id', $user->id)
                ->whereIn('status', ['processing', 'ready_for_pickup', 'shipped'])
                ->latest()
                ->first();

            if ($activeOrder && $activeOrder->delivery) {
                $eta = app(EtaCalculationService::class)->present($activeOrder->delivery, $activeOrder);
                $activeShipment = [
                    'order_id' => $activeOrder->id,
                    'order_number' => $activeOrder->order_number,
                    'status' => $activeOrder->status,
                    'tracking_number' => $activeOrder->delivery->tracking_number,
                    'courier_name' => $activeOrder->delivery->logistics_partner,
                    'item_name' => $activeOrder->items->first()?->product?->name ?? 'Package',
                    'item_count' => $activeOrder->items->count(),
                    'estimated_delivery' => $eta['label'] ?? 'Forecast pending',
                    'eta' => $eta,
                ];
            }
        }

        return Inertia::render('Buyer/Home', [
            'banners' => $banners,
            'categories' => $categories,
            'subcategories' => $subcategories,
            'feedProducts' => $feedProducts,
            'flashSaleProducts' => $flashSaleProducts,
            'topPersonalizedProducts' => $topPersonalizedProducts,
            'topCuratedCollections' => $topCuratedCollections,
            'relatedProducts' => $relatedProducts,
            'activeShipment' => $activeShipment,
            'filters' => [...$filters, 'tab' => match ($filters['sort']) {
                'newest' => 'new_arrivals', 'top_sales', 'top_rated' => $filters['sort'], default => 'all',
            }],
        ]);
    }

    public function topProducts(Request $request): InertiaResponse
    {
        $categories = app(BuyerDiscoveryService::class)->categories();
        $collections = self::curatedCollections();

        $activeCollectionSlug = $request->input('collection', $collections[0]['sub_slug']);
        $activeCollection = collect($collections)->firstWhere('sub_slug', $activeCollectionSlug) ?? $collections[0];

        $subCategory = SubCategory::where('slug', $activeCollection['sub_slug'])->first();

        $productsQuery = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'subCategory'])
            ->availableForSale()
            ->withReviewSummary()
            ->where('status', 'active');

        if ($subCategory) {
            $products = (clone $productsQuery)
                ->where('sub_category_id', $subCategory->id)
                ->orderByDesc('sales_count')
                ->orderByDesc('rating')
                ->get();

            if ($products->count() < 6) {
                $existingIds = $products->pluck('id')->all();
                $fillers = (clone $productsQuery)
                    ->whereNotIn('id', $existingIds)
                    ->orderByDesc('sales_count')
                    ->take(6 - count($existingIds))
                    ->get();
                $products = $products->concat($fillers);
            }
        } else {
            $products = $productsQuery
                ->orderByDesc('sales_count')
                ->orderByDesc('rating')
                ->take(12)
                ->get();
        }

        return Inertia::render('Buyer/TopProducts', [
            'categories' => $categories,
            'collections' => $collections,
            'activeCollection' => $activeCollection,
            'products' => $products,
        ]);
    }

    public static function curatedCollections(): array
    {
        return [
            [
                'id' => 1,
                'title' => 'Wireless Audio & Gadgets',
                'ribbon' => '#1 BESTSELLER',
                'ribbon_color' => 'amber',
                'sales_badge' => '3.8K+ SOLD/MO',
                'category_slug' => 'electronics-and-gadgets',
                'category_name' => 'Electronics & Gadgets',
                'sub_slug' => 'audio-and-video-equipment',
                'image' => '/images/products/carousell_electronics_and_gadgets_1.jpg',
                'item_count_label' => '15+ items',
                'target_url' => '/top-products?collection=audio-and-video-equipment',
            ],
            [
                'id' => 2,
                'title' => 'Streetwear Boxy Tees',
                'ribbon' => 'TRENDING',
                'ribbon_color' => 'crimson',
                'sales_badge' => '2.9K+ SOLD/MO',
                'category_slug' => 'mens-apparel',
                'category_name' => "Men's Apparel",
                'sub_slug' => 'tees-and-tops',
                'image' => '/images/products/carousell_mens_apparel_1.jpg',
                'item_count_label' => '20+ items',
                'target_url' => '/top-products?collection=tees-and-tops',
            ],
            [
                'id' => 3,
                'title' => 'Heritage Automatic Watches',
                'ribbon' => 'TOP RATED 5.0★',
                'ribbon_color' => 'emerald',
                'sales_badge' => '2.1K+ SOLD/MO',
                'category_slug' => 'jewelry-and-watches',
                'category_name' => 'Jewelry & Watches',
                'sub_slug' => 'watches-for-men-and-women',
                'image' => '/images/products/carousell_jewelry_and_watches_1.jpg',
                'item_count_label' => '12+ items',
                'target_url' => '/top-products?collection=watches-for-men-and-women',
            ],
            [
                'id' => 4,
                'title' => 'Linen Dresses & Summer Wear',
                'ribbon' => 'HOT DEMAND',
                'ribbon_color' => 'orange',
                'sales_badge' => '1.8K+ SOLD/MO',
                'category_slug' => 'womens-apparel',
                'category_name' => "Women's Apparel",
                'sub_slug' => 'dresses-and-skirts',
                'image' => '/images/products/carousell_womens_apparel_1.jpg',
                'item_count_label' => '14+ items',
                'target_url' => '/top-products?collection=dresses-and-skirts',
            ],
            [
                'id' => 5,
                'title' => 'Outdoor Camping & Trail Gear',
                'ribbon' => 'RISING STAR',
                'ribbon_color' => 'amber',
                'sales_badge' => '1.4K+ SOLD/MO',
                'category_slug' => 'sports-and-outdoors',
                'category_name' => 'Sports & Outdoors',
                'sub_slug' => 'camping-and-hiking-gear',
                'image' => '/images/products/carousell_sports_and_outdoors_1.jpg',
                'item_count_label' => '10+ items',
                'target_url' => '/top-products?collection=camping-and-hiking-gear',
            ],
            [
                'id' => 6,
                'title' => 'Hydrating Skincare Essentials',
                'ribbon' => 'COMMUNITY CHOICE',
                'ribbon_color' => 'emerald',
                'sales_badge' => '1.1K+ SOLD/MO',
                'category_slug' => 'health-and-beauty',
                'category_name' => 'Health & Beauty',
                'sub_slug' => 'skincare-products',
                'image' => '/images/products/carousell_health_and_beauty_1.jpg',
                'item_count_label' => '16+ items',
                'target_url' => '/top-products?collection=skincare-products',
            ],
        ];
    }
}
