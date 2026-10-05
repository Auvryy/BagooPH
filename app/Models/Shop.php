<?php

namespace App\Models;

use App\Models\Concerns\RetainsRestrictionHistory;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use HasFactory;
    use RetainsRestrictionHistory;

    protected $fillable = [
        'user_id',
        'root_category_id',
        'is_default',
        'name',
        'slug',
        'description',
        'logo',
        'banner',
        'phone',
        'address',
        'city',
        'rating',
        'status',
        'review_status', 'review_submitted_at', 'reviewed_at', 'review_feedback',
        'business_permit_path', 'review_decision_id', 'review_version',
    ];

    protected $hidden = ['business_permit_path', 'review_decision_id', 'review_version'];

    protected $casts = [
        'is_default' => 'boolean',
        'rating' => 'float',
        'review_submitted_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
        'review_version' => 'integer',
    ];

    public function currentReview(): BelongsTo
    {
        return $this->belongsTo(ShopReviewDecision::class, 'review_decision_id');
    }

    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(ShopReviewDecision::class);
    }

    public function scopeEligible(Builder $query): Builder
    {
        return app(ShopEligibilityService::class)->eligibleShops($query);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rootCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'root_category_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
