<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    use HasFactory;

    public const SCORED_STATUSES = ['published', 'restored'];

    public const PUBLIC_VISIBLE_STATUSES = ['published', 'restored', 'excluded_courier_issue'];

    protected $fillable = [
        'product_id',
        'buyer_id',
        'order_id',
        'order_item_id',
        'submission_fingerprint',
        'rating',
        'comment',
        'images',
        'edited_at', 'edit_fingerprint', 'original_content',
        'moderation_status', 'moderation_reason', 'detected_flags', 'moderated_by_id', 'moderated_at',
    ];

    protected $hidden = ['submission_fingerprint', 'edit_fingerprint', 'original_content'];

    protected $casts = [
        'rating' => 'integer',
        'images' => 'array',
        'verified_purchase' => 'boolean',
        'edited_at' => 'immutable_datetime',
        'original_content' => 'array',
        'detected_flags' => 'array',
        'moderated_at' => 'immutable_datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(ReviewDispute::class);
    }

    public function moderatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by_id');
    }

    private static function purchaseLink(Builder $query): void
    {
        $query->whereColumn('order_items.order_id', 'reviews.order_id')
            ->whereColumn('order_items.product_id', 'reviews.product_id')
            ->whereBetween('reviews.rating', [1, 5])
            ->whereHas('order', fn (Builder $order) => $order->where('status', 'completed')
                ->whereColumn('orders.buyer_id', 'reviews.buyer_id'));
    }

    public function scopeVerifiedPurchase(Builder $query): Builder
    {
        return $query->whereBetween('reviews.rating', [1, 5])
            ->whereIn('reviews.moderation_status', self::SCORED_STATUSES)
            ->whereHas('orderItem', self::purchaseLink(...));
    }

    public function scopePublicVisible(Builder $query): Builder
    {
        return $query->whereIn('reviews.moderation_status', self::PUBLIC_VISIBLE_STATUSES);
    }

    public function scopeWithPurchaseVerification(Builder $query): Builder
    {
        return $query->withExists(['orderItem as verified_purchase' => self::purchaseLink(...)]);
    }
}
