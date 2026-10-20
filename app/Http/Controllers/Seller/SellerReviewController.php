<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Rules\AsciiPositiveInteger;
use App\Services\Commerce\ReviewService;
use App\Services\ShopEligibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerReviewController extends Controller
{
    use HasSellerShop;

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request);
        $selection = $request->validate([
            'rating' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'page' => ['bail', 'nullable', new AsciiPositiveInteger, 'integer', 'max:1000000'],
        ]);
        $rating = isset($selection['rating']) ? (int) $selection['rating'] : null;

        $productIds = Product::where('shop_id', $shop?->id ?? 0)->pluck('id');

        $query = Review::withPurchaseVerification()->with(['product', 'buyer:id,name,avatar', 'reply',
            'orderItem' => fn ($item) => $item->where('shop_id', $shop->id)->whereIn('product_id', $productIds)
                ->select(['id', 'order_id', 'product_id', 'shop_id', 'color', 'size', 'sku_snapshot']),
        ])
            ->whereIn('product_id', $productIds)
            ->when($rating !== null, fn ($query) => $query->where('rating', $rating))
            ->latest()
            ->orderByDesc('id');
        $reviews = (clone $query)->paginate(15);
        if ($reviews->currentPage() > $reviews->lastPage()) {
            $reviews = (clone $query)->paginate(15, ['*'], 'page', $reviews->lastPage());
        }
        $reviews->withPath($request->getPathInfo())->appends($rating === null ? [] : ['rating' => $rating]);
        $reviews->through(function (Review $review) {
            $review->setAttribute('purchased_variant', $review->verified_purchase && $review->orderItem
                ? $review->orderItem->only(['color', 'size', 'sku_snapshot']) : null);
            $review->unsetRelation('orderItem');

            return $review;
        });

        $summary = app(ReviewService::class)->shopSummary($shop);
        $totalReviews = $summary['review_count'];
        $avgRating = $summary['rating'];

        $stats = [
            'average_rating' => $avgRating,
            'total_reviews' => $totalReviews,
            'response_rate' => $summary['response_rate'] ?? 'N/A',
            'rating_breakdown' => [
                '5_star' => Review::verifiedPurchase()->whereIn('product_id', $productIds)->where('rating', 5)->count(),
                '4_star' => Review::verifiedPurchase()->whereIn('product_id', $productIds)->where('rating', 4)->count(),
                '3_star' => Review::verifiedPurchase()->whereIn('product_id', $productIds)->where('rating', 3)->count(),
                '2_star' => Review::verifiedPurchase()->whereIn('product_id', $productIds)->where('rating', 2)->count(),
                '1_star' => Review::verifiedPurchase()->whereIn('product_id', $productIds)->where('rating', 1)->count(),
            ],
        ];

        return Inertia::render('Seller/Reviews', [
            'reviews' => $reviews,
            'stats' => $stats,
            'shop' => $shop,
            'filters' => ['rating' => $rating],
        ]);
    }

    public function reply(Request $request, int $reviewId): RedirectResponse
    {
        return app(ShopEligibilityService::class)->mutate($request, function () use ($request, $reviewId) {
            app(ReviewService::class)->reply($request->user(), $this->getActiveShop($request), $reviewId, $request->input());

            return back()->with('success', 'Your reply has been saved.');
        });
    }
}
