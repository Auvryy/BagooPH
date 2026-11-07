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
        $topPersonalizedProducts = Product::with(['shop', 'category', 'subCategory'])
            ->availableForSale()
            ->where('status', 'active')
            ->orderByDesc('sales_count')
            ->take(4)
            ->get()
            ->map(function ($product, $index) {
                $ribbons = [
                    ['text' => '#1 BESTSELLER', 'tag' => 'backpacks-and-bags', 'sub' => 'crossbody-slings', 'niche' => 'Tactical Crossbody Slings', 'sales' => '3.4K SOLD/MO'],
                    ['text' => 'TRENDING', 'tag' => 'mens-apparel', 'sub' => 'tees-and-tops', 'niche' => 'Oversized Streetwear Tees', 'sales' => '2.8K SOLD/MO'],
                    ['text' => 'TOP RATED 5.0★', 'tag' => 'jewelry-and-watches', 'sub' => 'tactical-field-watches', 'niche' => 'Mil-Spec Field Watches', 'sales' => '1.9K SOLD/MO'],
                    ['text' => 'HOT DEMAND', 'tag' => 'backpacks-and-bags', 'sub' => 'tech-pouches-and-organizers', 'niche' => 'Modular Tech Organizers', 'sales' => '1.5K SOLD/MO'],
                ];
                $meta = $ribbons[$index % count($ribbons)];
                $product->personalized_ribbon = $meta['text'];
                $product->collection_tag = $meta['tag'];
                $product->collection_sub = $meta['sub'];
                $product->collection_title = $meta['niche'];
                $product->sales_badge = $meta['sales'];
                return $product;
            });

        $topCuratedCollections = [
            [
                'id' => 1,
                'title' => 'Tactical Crossbody Slings',
                'ribbon' => '#1 BESTSELLER',
                'ribbon_color' => 'amber',
                'sales_badge' => '3.4K SOLD/MO',
                'category_slug' => 'backpacks-and-bags',
                'category_name' => 'Bags & EDC',
                'sub_slug' => 'crossbody-slings',
                'image' => '/images/products/stealth_sling_front.jpg',
                'item_count_label' => '12+ items',
                'target_url' => '/?category=backpacks-and-bags&sub=crossbody-slings',
            ],
            [
                'id' => 2,
                'title' => 'Oversized Streetwear Tees',
                'ribbon' => 'TRENDING',
                'ribbon_color' => 'crimson',
                'sales_badge' => '2.8K SOLD/MO',
                'category_slug' => 'mens-apparel',
                'category_name' => "Men's Apparel",
                'sub_slug' => 'tees-and-tops',
                'image' => '/images/products/boxy_tee_front.jpg',
                'item_count_label' => '18+ items',
                'target_url' => '/?category=mens-apparel&sub=tees-and-tops',
            ],
            [
                'id' => 3,
                'title' => 'Mil-Spec Field Watches',
                'ribbon' => 'TOP RATED 5.0★',
                'ribbon_color' => 'emerald',
                'sales_badge' => '1.9K SOLD/MO',
                'category_slug' => 'jewelry-and-watches',
                'category_name' => 'Jewelry & Watches',
                'sub_slug' => 'tactical-field-watches',
                'image' => '/images/categories/jewelry_watches.jpg',
                'item_count_label' => '8+ items',
                'target_url' => '/?category=jewelry-and-watches&sub=tactical-field-watches',
            ],
            [
                'id' => 4,
                'title' => 'Modular Tech Organizers',
                'ribbon' => 'HOT DEMAND',
                'ribbon_color' => 'orange',
                'sales_badge' => '1.5K SOLD/MO',
                'category_slug' => 'backpacks-and-bags',
                'category_name' => 'Bags & EDC',
                'sub_slug' => 'tech-pouches-and-organizers',
                'image' => '/images/products/nomad_pouch_front.jpg',
                'item_count_label' => '14+ items',
                'target_url' => '/?category=backpacks-and-bags&sub=tech-pouches-and-organizers',
            ],
        ];

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
}
