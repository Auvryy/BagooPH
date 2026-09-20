<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'rating' => 'float',
    ];

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
