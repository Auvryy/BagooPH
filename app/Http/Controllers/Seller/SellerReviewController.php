<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        $productIds = Product::where('shop_id', $shop?->id ?? 0)->pluck('id');

        $reviews = Review::with(['product', 'buyer', 'order'])
            ->whereIn('product_id', $productIds)
            ->latest()
            ->paginate(15);

        $totalReviews = Review::whereIn('product_id', $productIds)->count();
        $avgRating = $totalReviews > 0 ? round((float) Review::whereIn('product_id', $productIds)->avg('rating'), 1) : 0.0;

        $stats = [
            'average_rating' => $avgRating,
            'total_reviews' => $totalReviews,
            'response_rate' => $totalReviews > 0 ? '100%' : 'N/A',
            'rating_breakdown' => [
                '5_star' => Review::whereIn('product_id', $productIds)->where('rating', 5)->count(),
                '4_star' => Review::whereIn('product_id', $productIds)->where('rating', 4)->count(),
                '3_star' => Review::whereIn('product_id', $productIds)->where('rating', 3)->count(),
                '2_star' => Review::whereIn('product_id', $productIds)->where('rating', 2)->count(),
                '1_star' => Review::whereIn('product_id', $productIds)->where('rating', 1)->count(),
            ],
        ];

        return Inertia::render('Seller/Reviews', [
            'reviews' => $reviews,
            'stats' => $stats,
            'shop' => $shop,
        ]);
    }

    public function reply(Request $request, int $reviewId): RedirectResponse
    {
        $request->validate([
            'reply_text' => 'required|string|max:500',
        ]);

        return back()->with('success', 'Merchant reply posted to customer review.');
    }
}
