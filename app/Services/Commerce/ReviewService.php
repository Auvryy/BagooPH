<?php

namespace App\Services\Commerce;

use App\Models\BuyerReturnCase;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\ReviewDispute;
use App\Models\ReviewReply;
use App\Models\ShipmentFinancialPlan;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Governance\EnforcementService;
use App\Services\Governance\ModerationSignalService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Returns\ReturnFaultReviewService;
use App\Services\ShopEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'rating' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'between:1,5'],
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
                if ($sanction = app(EnforcementService::class)->activeReviewRestrictionForBuyer($buyer)) {
                    throw new AuthorizationException("Your review privileges are currently restricted ({$sanction->reference}): {$sanction->reason}");
                }
                if ($order->status !== 'completed') {
                    throw ValidationException::withMessages(['order_item_id' => 'Confirm receipt of this order before reviewing its items.']);
                }
                if ($order->completed_at !== null && now()->gt($order->completed_at->copy()->addDays(30))) {
                    throw ValidationException::withMessages(['order_item_id' => 'Product reviews must be submitted within 30 days of order completion.']);
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
                $this->assertEligibleUnderReturnPolicy($order, $item);
                $this->assertAntiSpamContent($data['comment'] ?? null);
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
                $signals = app(ModerationSignalService::class);
                $courierTerms = $signals->detectCourierTerms($data['comment'] ?? null);
                $detectedFlags = $courierTerms !== [] ? ['possible_courier_complaint'] : [];

                $velocityWindow = Review::where('product_id', $product->id)
                    ->where('created_at', '>=', now()->subMinutes(60))
                    ->count();
                $burstOneStar = (int) $data['rating'] === 1
                    ? Review::where('product_id', $product->id)->where('rating', 1)->where('created_at', '>=', now()->subMinutes(15))->count()
                    : 0;

                $isVelocityHold = $velocityWindow >= 5 || $burstOneStar >= 3;
                $moderationStatus = $isVelocityHold ? 'held_velocity_review' : 'published';
                $moderationReason = $isVelocityHold ? 'Held temporarily due to unusual review velocity burst.' : null;
                if ($isVelocityHold) {
                    $detectedFlags[] = 'velocity_burst';
                }

                $review = Review::create([
                    'order_item_id' => $item->id, 'order_id' => $order->id, 'product_id' => $item->product_id,
                    'buyer_id' => $buyer->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? null,
                    'submission_fingerprint' => $fingerprint,
                    'images' => $paths ? array_map(fn ($path) => '/storage/'.$path, $paths) : null,
                    'moderation_status' => $moderationStatus,
                    'moderation_reason' => $moderationReason,
                    'detected_flags' => $detectedFlags !== [] ? $detectedFlags : null,
                ]);

                if ($isVelocityHold) {
                    $signals->recordVelocitySignal($review, max($velocityWindow + 1, $burstOneStar + 1), $burstOneStar >= 3 ? '15m' : '60m');
                } elseif ($courierTerms !== []) {
                    $signals->recordCourierKeywordSignal($review, $courierTerms);
                }

                $this->recalculateRatings($product, $shop);

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

    public function canEdit(Review $review): bool
    {
        return $review->edited_at === null && $review->created_at !== null
            && now()->lte($review->created_at->copy()->addDays(30))
            && Review::whereKey($review->id)->verifiedPurchase()->exists();
    }

    public function edit(User $actor, Review $review, array $input): Review
    {
        app(BuyerAccessService::class)->requirePortal($actor);
        $data = Validator::make($input, [
            'rating' => ['bail', 'required', new AsciiPositiveInteger, 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', new ApplicationText('notes', 1, 1000)],
            'images' => 'prohibited', 'buyer_id' => 'prohibited', 'product_id' => 'prohibited',
            'order_id' => 'prohibited', 'order_item_id' => 'prohibited', 'edited_at' => 'prohibited',
            'original_content' => 'prohibited', 'edit_fingerprint' => 'prohibited', 'submission_fingerprint' => 'prohibited',
        ])->validate();

        return DB::transaction(function () use ($actor, $review, $data) {
            $order = Order::whereKey($review->order_id)->lockForUpdate()->firstOrFail();
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            if ($order->buyer_id !== $buyer->id || $review->buyer_id !== $buyer->id) {
                throw new AuthorizationException('This review belongs to another buyer.');
            }
            $item = OrderItem::whereKey($review->order_item_id)->firstOrFail();
            $shop = Shop::whereKey($item->shop_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($review->product_id)->lockForUpdate()->firstOrFail();
            $current = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($current->buyer_id !== $buyer->id || $current->order_id !== $order->id || $current->order_item_id !== $item->id || $current->product_id !== $product->id) {
                throw new AuthorizationException('This review is no longer available to your account.');
            }
            if ($order->status !== 'completed' || ! Review::whereKey($current->id)->verifiedPurchase()->exists()) {
                throw ValidationException::withMessages(['rating' => 'Only a verified completed purchase review can be edited.']);
            }
            $fingerprint = hash('sha256', json_encode([$current->id, (int) $data['rating'], $data['comment'] ?? null], JSON_THROW_ON_ERROR));
            if ($current->edited_at !== null) {
                if ($current->edit_fingerprint && hash_equals($current->edit_fingerprint, $fingerprint)) {
                    return $current;
                }
                throw ValidationException::withMessages(['rating' => 'This review has already used its one edit.']);
            }
            if (! $this->canEdit($current)) {
                throw ValidationException::withMessages(['rating' => 'Reviews can be edited once within 30 days of posting.']);
            }
            $current->update(['original_content' => ['rating' => $current->rating, 'comment' => $current->comment,
                'images' => $current->images, 'edited_by' => $buyer->id],
                'rating' => $data['rating'], 'comment' => $data['comment'] ?? null,
                'edited_at' => now(), 'edit_fingerprint' => $fingerprint]);
            $product->update(['rating' => round((float) Review::where('product_id', $product->id)->verifiedPurchase()->avg('rating'), 2)]);
            $shop->update(['rating' => round((float) Review::verifiedPurchase()->whereHas('product', fn (Builder $query) => $query->where('shop_id', $shop->id))->avg('rating'), 2)]);

            return $current;
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
        $product->load(['reviews' => fn ($reviews) => $reviews->publicVisible()->withPurchaseVerification()
            ->with(['buyer:id,name,avatar', 'reply.shop:id,name', 'orderItem:id,color,size', 'dispute'])->latest('id')]);
        $product->setRelation('reviews', $product->reviews->map(fn (Review $review) => [
            ...$review->only(['id', 'rating', 'comment', 'images', 'created_at', 'edited_at', 'verified_purchase', 'moderation_status', 'moderation_reason', 'detected_flags']),
            'score_excluded' => $review->moderation_status === 'excluded_courier_issue',
            'buyer' => $review->buyer ? ['name' => $this->publicBuyerName($review->buyer->name), 'avatar' => $review->buyer->avatar] : null,
            'purchased_options' => $review->verified_purchase && ($review->orderItem?->color !== null || $review->orderItem?->size !== null)
                ? $review->orderItem?->only(['color', 'size']) : null,
            'reply' => $review->reply ? [
                ...$review->reply->only(['text', 'created_at', 'updated_at']),
                'shop_name' => $review->reply->shop?->name,
            ] : null,
        ]));
    }

    public function disputeReview(User $actor, Shop $shop, int $reviewId, array $input): ReviewDispute
    {
        $data = Validator::make($input, [
            'reason_code' => ['required', 'string', Rule::in(['courier_delivery_complaint', 'abusive_or_troll', 'unverified_or_false_claim', 'competitor_sabotage'])],
            'seller_evidence' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $shop, $reviewId, $data) {
            $seller = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            if (! $seller->isSeller() || ! $seller->canAccessPortal() || $shop->user_id !== $seller->id) {
                throw new AuthorizationException('Only the active approved shop owner may dispute a review.');
            }
            $review = Review::whereKey($reviewId)
                ->whereHas('product', fn (Builder $product) => $product->where('shop_id', $shop->id))
                ->lockForUpdate()
                ->firstOrFail();

            $open = ReviewDispute::where('review_id', $review->id)
                ->whereIn('status', ['pending', 'buyer_appealed'])
                ->exists();
            if ($open) {
                throw ValidationException::withMessages(['seller_evidence' => 'A review dispute is already open for this review.']);
            }

            return ReviewDispute::create([
                'reference' => 'RVD-'.Str::upper(Str::random(10)),
                'review_id' => $review->id,
                'shop_id' => $shop->id,
                'seller_id' => $seller->id,
                'buyer_id' => $review->buyer_id,
                'reason_code' => $data['reason_code'],
                'seller_evidence' => trim($data['seller_evidence']),
                'status' => 'pending',
            ]);
        });
    }

    public function adjudicateDispute(User $actor, ReviewDispute $dispute, array $input): ReviewDispute
    {
        $actor = app(EnforcementService::class)->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'outcome' => ['required', 'string', Rule::in(['upheld_excluded_courier', 'upheld_removed', 'rejected', 'release_held_review'])],
            'adjudication_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $dispute, $data) {
            $locked = ReviewDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending'], true)) {
                throw ValidationException::withMessages(['outcome' => 'This review dispute has already been adjudicated.']);
            }

            $review = Review::whereKey($locked->review_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($review->product_id)->lockForUpdate()->firstOrFail();
            $shop = Shop::whereKey($product->shop_id)->lockForUpdate()->firstOrFail();

            $nextReviewStatus = match ($data['outcome']) {
                'upheld_excluded_courier' => 'excluded_courier_issue',
                'upheld_removed' => 'removed_policy',
                'rejected', 'release_held_review' => 'published',
            };

            $nextDisputeStatus = $data['outcome'] === 'release_held_review' ? 'rejected' : $data['outcome'];

            $review->update([
                'moderation_status' => $nextReviewStatus,
                'moderation_reason' => trim($data['adjudication_notes']),
                'moderated_by_id' => $actor->id,
                'moderated_at' => now(),
            ]);

            $locked->update([
                'status' => $nextDisputeStatus,
                'adjudicated_by_id' => $actor->id,
                'adjudication_notes' => trim($data['adjudication_notes']),
                'adjudicated_at' => now(),
            ]);

            $this->recalculateRatings($product, $shop);

            app(NotificationDeliveryService::class)->record(
                $review->buyer_id,
                'review.moderation.'.$nextReviewStatus,
                'rvd-buyer:'.$locked->reference.':'.$nextDisputeStatus,
                ['dispute_reference' => $locked->reference, 'moderation_status' => $nextReviewStatus]
            );

            return $locked->fresh(['review']);
        });
    }

    public function moderateVelocityHold(User $actor, Review $review, array $input): Review
    {
        $actor = app(EnforcementService::class)->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'decision' => ['required', 'string', Rule::in(['release', 'remove'])],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $review, $data) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($locked->product_id)->lockForUpdate()->firstOrFail();
            $shop = Shop::whereKey($product->shop_id)->lockForUpdate()->firstOrFail();

            $locked->update([
                'moderation_status' => $data['decision'] === 'release' ? 'published' : 'removed_policy',
                'moderation_reason' => trim($data['reason']),
                'moderated_by_id' => $actor->id,
                'moderated_at' => now(),
            ]);

            $this->recalculateRatings($product, $shop);

            return $locked->fresh();
        });
    }

    public function appealDispute(User $actor, Review $review, array $input): ReviewDispute
    {
        $data = Validator::make($input, [
            'appeal_reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $review, $data) {
            $lockedReview = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($lockedReview->buyer_id !== $actor->id) {
                throw new AuthorizationException('Only the original reviewer can appeal this decision.');
            }

            if (! in_array($lockedReview->moderation_status, ['removed_policy', 'excluded_courier_issue', 'held_velocity_review'], true)) {
                throw ValidationException::withMessages(['appeal_reason' => 'Only moderated, excluded, or removed reviews can be appealed.']);
            }

            $dispute = ReviewDispute::where('review_id', $lockedReview->id)->lockForUpdate()->first();
            if (! $dispute) {
                $shopId = Product::whereKey($lockedReview->product_id)->value('shop_id');
                $shop = Shop::findOrFail($shopId);
                $dispute = ReviewDispute::create([
                    'reference' => 'RVD-'.Str::upper(Str::random(10)),
                    'review_id' => $lockedReview->id,
                    'shop_id' => $shop->id,
                    'seller_id' => $shop->user_id,
                    'buyer_id' => $lockedReview->buyer_id,
                    'reason_code' => 'buyer_moderation_appeal',
                    'seller_evidence' => $lockedReview->moderation_reason ?? 'Platform moderation hold',
                    'status' => 'buyer_appealed',
                    'buyer_appeal_reason' => trim($data['appeal_reason']),
                    'buyer_appealed_at' => now(),
                ]);

                return $dispute;
            }

            if ($dispute->status === 'buyer_appealed') {
                throw ValidationException::withMessages(['appeal_reason' => 'Your appeal is already awaiting independent review.']);
            }

            $dispute->update([
                'status' => 'buyer_appealed',
                'buyer_appeal_reason' => trim($data['appeal_reason']),
                'buyer_appealed_at' => now(),
            ]);

            return $dispute->fresh();
        });
    }

    public function adjudicateAppeal(User $actor, ReviewDispute $dispute, array $input): ReviewDispute
    {
        $actor = app(EnforcementService::class)->assertVerifiedAdmin($actor);
        $data = Validator::make($input, [
            'outcome' => ['required', 'string', Rule::in(['appeal_overturned', 'appeal_upheld'])],
            'appeal_notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
        ])->validate();

        return DB::transaction(function () use ($actor, $dispute, $data) {
            $locked = ReviewDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'buyer_appealed') {
                throw ValidationException::withMessages(['outcome' => 'Only an open buyer appeal can be decided here.']);
            }

            abort_if(
                $locked->adjudicated_by_id !== null && $locked->adjudicated_by_id === $actor->id,
                403,
                'An independent platform admin must decide this buyer review appeal.'
            );

            $review = Review::whereKey($locked->review_id)->lockForUpdate()->firstOrFail();
            $product = Product::whereKey($review->product_id)->lockForUpdate()->firstOrFail();
            $shop = Shop::whereKey($product->shop_id)->lockForUpdate()->firstOrFail();

            if ($data['outcome'] === 'appeal_overturned') {
                $review->update([
                    'moderation_status' => 'restored',
                    'moderation_reason' => 'Restored via buyer appeal '.$locked->reference.': '.trim($data['appeal_notes']),
                    'moderated_by_id' => $actor->id,
                    'moderated_at' => now(),
                ]);
                $this->recalculateRatings($product, $shop);
            }

            $locked->update([
                'status' => $data['outcome'],
                'appeal_reviewed_by_id' => $actor->id,
                'appeal_notes' => trim($data['appeal_notes']),
                'appeal_reviewed_at' => now(),
            ]);

            return $locked->fresh(['review']);
        });
    }

    public function recalculateRatings(Product $product, Shop $shop): void
    {
        $product->update(['rating' => round((float) Review::where('product_id', $product->id)->verifiedPurchase()->avg('rating'), 2)]);
        $shop->update(['rating' => round((float) Review::verifiedPurchase()->whereHas('product', fn (Builder $query) => $query->where('shop_id', $shop->id))->avg('rating'), 2)]);
    }

    private function assertEligibleUnderReturnPolicy(Order $order, OrderItem $item): void
    {
        if (Schema::hasTable('shipment_financial_plans')
            && ShipmentFinancialPlan::where('order_id', $order->id)->where('kind', 'buyer_refusal')->exists()) {
            throw ValidationException::withMessages([
                'order_item_id' => 'Refused COD orders cannot submit product ratings. Please submit Delivery Feedback if needed.',
            ]);
        }

        if (! Schema::hasTable('buyer_return_cases')) {
            return;
        }

        $cases = BuyerReturnCase::where('order_id', $order->id)
            ->whereNotIn('status', ['withdrawn', 'expired', 'denied'])
            ->whereHas('items', fn (Builder $q) => $q->where('order_item_id', $item->id))
            ->get();

        $faultReviews = app(ReturnFaultReviewService::class);
        foreach ($cases as $case) {
            $fault = $faultReviews->current($case)['fault'] ?? null;
            if ($fault !== 'seller') {
                throw ValidationException::withMessages([
                    'order_item_id' => 'Returns resolved for courier fault or non-seller reasons do not affect product ratings. Submit Delivery Feedback for the courier instead.',
                ]);
            }
        }
    }

    private function assertAntiSpamContent(?string $comment): void
    {
        if (! is_string($comment) || trim($comment) === '') {
            return;
        }

        if (preg_match('/(?:https?:\/\/|www\.)\S+/i', $comment)) {
            throw ValidationException::withMessages([
                'comment' => 'External links are not permitted in verified product reviews.',
            ]);
        }

        if (preg_match('/(.)\1{9,}/u', $comment)) {
            throw ValidationException::withMessages([
                'comment' => 'Please enter genuine product feedback without repeated filler characters.',
            ]);
        }
    }

    private function publicBuyerName(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! $parts) {
            return 'Buyer';
        }
        $initials = [mb_substr($parts[0], 0, 1).'***'];
        if (count($parts) > 1) {
            $initials[] = mb_substr($parts[count($parts) - 1], 0, 1).'***';
        }

        return implode(' ', $initials);
    }
}
