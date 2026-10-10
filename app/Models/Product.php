<?php

namespace App\Models;

use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class Product extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->slug)) {
                $product->slug = static::generateUniqueSlug($product->name ?? 'product');
            } else {
                $product->slug = static::makeSlugUnique($product->slug, $product->id);
            }
        });

        static::updating(function (Product $product) {
            if ($product->isDirty('name') && ! $product->isDirty('slug')) {
                $product->slug = static::generateUniqueSlug($product->name, $product->id);
            } elseif ($product->isDirty('slug')) {
                $product->slug = static::makeSlugUnique($product->slug, $product->id);
            }
        });

        static::deleting(function (Product $product) {
            if ($product->hasRetainedReferences()) {
                throw new LogicException('Referenced products must be archived to preserve their history.');
            }
        });
    }

    /**
     * Generate a unique, collision-free URL slug for a product.
     */
    public static function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if (empty($base)) {
            $base = 'product';
        }

        do {
            $suffix = Str::lower(Str::random(6));
            $candidate = "{$base}-{$suffix}";
            $exists = static::where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists();
        } while ($exists);

        return $candidate;
    }

    /**
     * Ensure any provided slug string is unique across all products.
     */
    public static function makeSlugUnique(string $slug, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug);
        if (empty($base)) {
            $base = 'product';
        }

        $candidate = $base;
        $count = 1;

        while (static::where('slug', $candidate)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $count++;
            $candidate = "{$base}-{$count}";
        }

        return $candidate;
    }

    protected $fillable = [
        'shop_id',
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'compare_at_price',
        'stock',
        'sku',
        'variants',
        'featured_image',
        'weight_kg',
        'status',
        'rating',
        'sales_count',
    ];

    protected $casts = [
        'compliance_restricted' => 'boolean',
        'moderation_version' => 'integer',
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'rating' => 'decimal:2',
        'stock' => 'integer',
        'sales_count' => 'integer',
        'variants' => 'array',
    ];

    protected $attributes = ['compliance_restricted' => false, 'moderation_version' => 0];

    protected $hidden = ['verified_rating'];

    public function moderationDecisions(): HasMany
    {
        return $this->hasMany(ProductModerationDecision::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeAvailableForSale(Builder $query): Builder
    {
        return app(ShopEligibilityService::class)->availableProducts($query);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest();
    }

    public function scopeWithReviewSummary(Builder $query): Builder
    {
        return $query->withAvg(['reviews as verified_rating' => fn (Builder $reviews) => $reviews->verifiedPurchase()], 'rating')
            ->withCount(['reviews as verified_review_count' => fn (Builder $reviews) => $reviews->verifiedPurchase()]);
    }

    public function getRatingAttribute(mixed $value): mixed
    {
        return array_key_exists('verified_rating', $this->attributes)
            ? ($this->attributes['verified_rating'] === null ? null : round((float) $this->attributes['verified_rating'], 2))
            : ($value === null ? null : number_format((float) $value, 2, '.', ''));
    }

    public function scopeWhereVerifiedRatingAtLeast(Builder $query, float $rating): Builder
    {
        return $query->whereIn('products.id', Review::verifiedPurchase()->select('product_id')->groupBy('product_id')
            ->havingRaw('AVG(reviews.rating) >= CAST(? AS DECIMAL(10, 2))', [$rating]));
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function hasRetainedReferences(): bool
    {
        return $this->orderItems()->exists() || $this->reviews()->exists()
            || CartItem::where('product_id', $this->id)->exists() || $this->moderationDecisions()->exists()
            || SavedProduct::where('product_id', $this->id)->exists();
    }
}
