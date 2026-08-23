<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuyerReviewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'order_id' => 'nullable|exists:orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:5',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        // If order_id is provided, verify verified purchase authorization
        if (! empty($validated['order_id'])) {
            $order = Order::where('id', $validated['order_id'])
                ->where('buyer_id', $user->id)
                ->first();

            if (! $order) {
                abort(403, 'Unauthorized review submission for this order.');
            }

            $orderHasProduct = $order->items()->where('product_id', $validated['product_id'])->exists();
            if (! $orderHasProduct) {
                return back()->with('error', 'This product was not part of the specified order.');
            }

            // Prevent duplicate review for the same order and product
            $existingReview = Review::where('buyer_id', $user->id)
                ->where('order_id', $validated['order_id'])
                ->where('product_id', $validated['product_id'])
                ->first();

            if ($existingReview) {
                return back()->with('error', 'You have already submitted a review for this purchased item.');
            }
        }

        $imageUrls = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $imageFile) {
                $path = $imageFile->store('reviews', 'public');
                $imageUrls[] = '/storage/' . $path;
            }
        }

        Review::create([
            'buyer_id' => $user->id,
            'product_id' => $validated['product_id'],
            'order_id' => $validated['order_id'] ?? null,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'images' => !empty($imageUrls) ? $imageUrls : null,
        ]);

        // Update product average rating
        $product = Product::find($validated['product_id']);
        if ($product) {
            $avg = Review::where('product_id', $product->id)->avg('rating');
            $product->update(['rating' => round($avg, 2)]);
        }

        return back()->with('success', 'Thank you! Your verified review and photos have been posted.');
    }
}
