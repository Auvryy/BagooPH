<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Services\Commerce\BuyerDiscoveryService;
use App\Services\Commerce\PurchasePolicyService;
use App\Services\Commerce\ReviewService;
use App\Services\Commerce\SaleReminderService;
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
        $related = $discovery->catalogue()->whereNotIn('products.id', $products->pluck('id'));
        if ($filters['category'] !== 'all') {
            $related->whereIn('category_id', $discovery->categoryIds($filters['category']));
        }

        return Inertia::render('Buyer/Search', [
            'products' => $products,
            'categories' => fn () => $discovery->categories(),
            'relatedProducts' => fn () => $related->orderByDesc('sales_count')->orderByDesc('products.id')->limit(6)->get(),
            'filters' => $filters,
            'suggestions' => fn () => $products->total() === 0 ? $discovery->suggestions($filters) : [],
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
            'shopOffers' => app(VoucherCollectionService::class)->shopOffers($product->shop_id, $request->user()),
        ]);
    }
}
