<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
            if ($product->isDirty('name') && !$product->isDirty('slug')) {
                $product->slug = static::generateUniqueSlug($product->name, $product->id);
            } elseif ($product->isDirty('slug')) {
                $product->slug = static::makeSlugUnique($product->slug, $product->id);
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
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
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

        while (static::where('slug', $candidate)->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))->exists()) {
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
        'price' => 'decimal:2',
        'compare_at_price' => 'decimal:2',
        'weight_kg' => 'decimal:2',
        'rating' => 'decimal:2',
        'stock' => 'integer',
        'sales_count' => 'integer',
        'variants' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
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

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
