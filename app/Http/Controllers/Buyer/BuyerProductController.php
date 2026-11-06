<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Services\Commerce\BuyerDiscoveryService;
use App\Services\Commerce\CatalogueLocationService;
use App\Services\Commerce\PurchasePolicyService;
use App\Services\Commerce\ReviewService;
use App\Services\Commerce\SaleReminderService;
use App\Services\Commerce\BuyerProductViewTracker;
use App\Services\Advertisements\AdvertisementRecommendationService;
use App\Services\Vouchers\VoucherCollectionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProductController extends Controller
{
    /**
     * Dedicated Product Search & Filter Catalog Page
     */
    public function search(Request $request): Response
    {
        $discovery = app(BuyerDiscoveryService::class);
        $filters = $discovery->selection($request);
        $products = $discovery->paginate($discovery->filter($discovery->catalogue(), $filters), $filters);
        $pageProducts = $products->getCollection();
        $suggestions = $products->total() === 0 ? $discovery->suggestions($filters) : [];
        $related = $discovery->filter($discovery->catalogue(), [...$filters, 'search' => '']);
        if ($filters['search'] !== '' && $pageProducts->isNotEmpty()) {
            $categoryIds = $pageProducts->pluck('category_id')->filter()->unique()->values()->all();
            $shopIds = $pageProducts->pluck('shop_id')->filter()->unique()->values()->all();
            $related->where(function ($query) use ($categoryIds, $shopIds) {
                if ($categoryIds) {
                    $query->whereIn('products.category_id', $categoryIds);
                }
                if ($shopIds) {
                    $query->when($categoryIds, fn ($scope) => $scope->orWhereIn('products.shop_id', $shopIds),
                        fn ($scope) => $scope->whereIn('products.shop_id', $shopIds));
                }
            });
            $matches = $discovery->filter($discovery->catalogue(), $filters)->reorder()->select('products.id');
            $related->whereNotIn('products.id', $matches);
            if ($categoryIds) {
                $related->orderByRaw('CASE WHEN products.category_id IN ('.implode(',', array_fill(0, count($categoryIds), '?')).') THEN 0 ELSE 1 END', $categoryIds);
            }
            if ($shopIds) {
                $related->orderByRaw('CASE WHEN products.shop_id IN ('.implode(',', array_fill(0, count($shopIds), '?')).') THEN 0 ELSE 1 END', $shopIds);
            }
        } elseif ($filters['search'] !== '' && $suggestions) {
            $related = $discovery->filter($discovery->catalogue(), [...$filters, 'search' => $suggestions[0]]);
        }
        $related->orderByDesc('sales_count')->orderByDesc('products.id')->limit(6);

        return Inertia::render('Buyer/Search', [
            'products' => $products,
            'categories' => fn () => $discovery->categories(),
            'locations' => fn () => app(CatalogueLocationService::class)->areas(),
            'relatedProducts' => fn () => $related->get(),
            'filters' => $filters,
            'suggestions' => fn () => $suggestions,
        ]);
    }

    public function show(Request $request, string $slug): Response
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

        // Support slug format with appended ID (e.g. {slug}-{id} or {slug}-i.{id})
        if (! $product && preg_match('/(?:-i\.|\.)?(\d+)$/', $slug, $matches)) {
            $product = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category', 'images'])->availableForSale()->withReviewSummary()
                ->where('status', 'active')
                ->where('id', (int) $matches[1])
                ->first();
        }

        if (! $product) {
            abort(404, 'Product not found.');
        }

        if (! $request->prefetch()) {
            $request->session()->put('buyer.recent_product_views', app(AdvertisementRecommendationService::class)
                ->rememberRecentProductView($request->session()->get('buyer.recent_product_views', []), $product->id));
            app(BuyerProductViewTracker::class)->record($product, $request);
        }

        $product->load(['storeTags' => fn ($query) => $query->where('store_tags.shop_id', $product->shop_id)]);

        $relatedProducts = Product::with(['shop' => fn ($shops) => $shops->withReviewSummary(), 'category'])->availableForSale()->withReviewSummary()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->where('status', 'active')
            ->take(6)
            ->get();

        // Product variations (Colorways & Sizes configured by merchant)
        $variations = $product->variants ?: [
            'colors' => [],
            'sizes' => [],
        ];

        $reviews = app(ReviewService::class);
        $reviews->publicReviews($product);
        $shopStats = $reviews->shopSummary($product->shop);
        $product->shop->setAttribute('rating', $shopStats['rating']);

        $cart = Cart::query()
            ->when(
                $request->user(),
                fn ($query, $user) => $query->where('user_id', $user->id),
                fn ($query) => $query->where('session_id', $request->session()->getId())->whereNull('user_id')
            )
            ->with(['items' => fn ($query) => $query->where('product_id', $product->id)])
            ->first();
        $cartQuantities = $cart?->items->map(fn ($item) => [
            'color' => $item->color,
            'size' => $item->size,
            'quantity' => $item->quantity,
        ])->values() ?? collect();

        return Inertia::render('Buyer/ProductDetail', [
            'product' => $product,
            'saleReminder' => $request->user()?->isBuyer() ? app(SaleReminderService::class)->status($request->user(), $product->sale_offer['upcoming']['id'] ?? null) : null,
            'variations' => $variations,
            'relatedProducts' => $relatedProducts,
            'shopStats' => $shopStats,
            'cartQuantities' => $cartQuantities,
            'purchaseAllowance' => app(PurchasePolicyService::class)->remaining($product, $request->user()?->id),
            'shopOffers' => app(VoucherCollectionService::class)->shopOffers($product->shop_id, $request->user(), $product->id),
        ]);
    }
}
