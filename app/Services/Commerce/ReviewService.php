<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\ShopEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReviewService
{
    public function create(User $actor, array $input): Review
    {
        app(BuyerAccessService::class)->requirePortal($actor);
        $data = Validator::make($input, [
            'order_item_id' => ['bail', 'required_without:order_id', 'nullable', new AsciiPositiveInteger, 'integer'],
            'order_id' => ['bail', 'required_without:order_item_id', 'nullable', new AsciiPositiveInteger, 'integer'],
            'product_id' => ['bail', 'required_without:order_item_id', 'nullable', new AsciiPositiveInteger, 'integer'],
            'buyer_id' => 'prohibited', 'submission_fingerprint' => 'prohibited', 'verified_purchase' => 'prohibited',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => ['nullable', 'string', new ApplicationText('notes', 1, 1000)],
            'images' => 'nullable|array|max:5', 'images.*' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ])->validate();
        $images = $data['images'] ?? [];
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $data, $images, &$paths) {
                $item = isset($data['order_item_id']) ? OrderItem::findOrFail($data['order_item_id']) : null;
                $order = Order::whereKey($item?->order_id ?? $data['order_id'])->lockForUpdate()->firstOrFail();
                $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
                if ($order->buyer_id !== $buyer->id) {
                    throw new AuthorizationException('This purchase belongs to another buyer.');
                }
                if ($order->status !== 'completed') {
                    throw ValidationException::withMessages(['order_item_id' => 'Confirm receipt of this order before reviewing its items.']);
                }
                if (! $item) {
                    $matches = $order->items()->where('product_id', $data['product_id'])->limit(2)->get();
                    if ($matches->count() !== 1) {
                        throw ValidationException::withMessages(['order_item_id' => 'Select the exact purchased item and variant to review.']);
                    }
                    $item = $matches->first();
                }
                $item = OrderItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
                if ($item->order_id !== $order->id || (isset($data['order_id']) && (int) $data['order_id'] !== $order->id)
                    || (isset($data['product_id']) && (int) $data['product_id'] !== $item->product_id)) {
                    throw ValidationException::withMessages(['order_item_id' => 'The purchase identifiers do not match.']);
                }
                $fingerprint = hash('sha256', json_encode([$item->id, (int) $data['rating'], $data['comment'] ?? null,
                    array_map(fn ($image) => hash_file('sha256', $image->getRealPath()), $images)], JSON_THROW_ON_ERROR));
                $existing = Review::where('order_item_id', $item->id)->first();
                if ($existing) {
                    $sameLegacyText = $existing->submission_fingerprint === null && empty($existing->images) && ! $images
                        && $existing->rating === (int) $data['rating'] && $existing->comment === ($data['comment'] ?? null);
                    if ($sameLegacyText || ($existing->submission_fingerprint && hash_equals($existing->submission_fingerprint, $fingerprint))) {
                        return $existing;
                    }
                    throw ValidationException::withMessages(['order_item_id' => 'This purchased item already has a review.']);
                }
                $shop = Shop::whereKey($item->shop_id)->lockForUpdate()->firstOrFail();
                $product = Product::whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                foreach ($images as $image) {
                    $path = $image->store('reviews', 'public');
                    if (! $path) {
                        throw new \RuntimeException('The review photo could not be saved.');
                    }
                    $paths[] = $path;
                }
                $review = Review::create([
                    'order_item_id' => $item->id, 'order_id' => $order->id, 'product_id' => $item->product_id,
                    'buyer_id' => $buyer->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? null,
                    'submission_fingerprint' => $fingerprint,
                    'images' => $paths ? array_map(fn ($path) => '/storage/'.$path, $paths) : null,
                ]);
                $product->update(['rating' => round((float) Review::where('product_id', $product->id)->verifiedPurchase()->avg('rating'), 2)]);
                $shop->update(['rating' => round((float) Review::verifiedPurchase()->whereHas('product', fn (Builder $query) => $query->where('shop_id', $shop->id))->avg('rating'), 2)]);

                return $review;
            });
        } catch (Throwable $error) {
            Storage::disk('public')->delete($paths);
            throw $error;
        }
    }

    public function reply(User $actor, Shop $shop, int $reviewId, array $input): ReviewReply
    {
        $data = Validator::make($input, ['reply_text' => ['required', 'string', new ApplicationText('notes', 1, 500)]])->validate();

        return DB::transaction(function () use ($actor, $shop, $reviewId, $data) {
            $seller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if (! $seller->isSeller() || ! $seller->canAccessPortal() || $shop->user_id !== $seller->id
                || Shop::where('user_id', $seller->id)->count() !== 1) {
                throw new AuthorizationException('Only the active approved shop owner may reply.');
            }
            app(ShopEligibilityService::class)->lockCategories();
            app(ShopEligibilityService::class)->assertEligible($shop);
            $review = Review::whereKey($reviewId)->whereHas('product', fn (Builder $product) => $product->where('shop_id', $shop->id))
                ->lockForUpdate()->firstOrFail();
            if ($shop->user_id !== $actor->id) {
                throw new AuthorizationException('Only this shop owner may reply.');
            }
            $reply = $review->reply()->first();
            if ($reply && ($reply->seller_id !== $actor->id || $reply->shop_id !== $shop->id)) {
                throw new AuthorizationException('The original reply owner has changed.');
            }

            return ReviewReply::updateOrCreate(['review_id' => $review->id], [
                'seller_id' => $actor->id, 'shop_id' => $shop->id, 'text' => $data['reply_text'],
            ]);
        });
    }

    public function shopSummary(Shop $shop): array
    {
        $reviews = Review::verifiedPurchase()->whereHas('product', fn (Builder $query) => $query->where('shop_id', $shop->id));
        $count = (clone $reviews)->count();
        $replied = (clone $reviews)->whereHas('reply')->count();

        return [
            'rating' => $count ? round((float) (clone $reviews)->avg('rating'), 2) : null,
            'review_count' => $count,
            'response_rate' => $count ? round($replied * 100 / $count).'%' : null,
            'products_count' => $shop->products()->availableForSale()->where('status', 'active')->count(),
            'joined' => $shop->created_at?->toDateString(),
        ];
    }

    public function publicReviews(Product $product): void
    {
        $product->load(['reviews' => fn ($reviews) => $reviews->withPurchaseVerification()
            ->with(['buyer:id,name,avatar', 'reply.shop:id,name'])->latest('id')]);
        $product->setRelation('reviews', $product->reviews->map(fn (Review $review) => [
            ...$review->only(['id', 'rating', 'comment', 'images', 'created_at', 'verified_purchase']),
            'buyer' => $review->buyer?->only(['name', 'avatar']),
            'reply' => $review->reply ? [
                ...$review->reply->only(['text', 'created_at', 'updated_at']),
                'shop_name' => $review->reply->shop?->name,
            ] : null,
        ]));
    }
}
