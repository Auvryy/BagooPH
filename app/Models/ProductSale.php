<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSale extends Model
{
    protected $fillable = ['product_id', 'kind', 'name', 'price', 'starts_at', 'ends_at', 'quantity', 'used_quantity', 'ended_at', 'discount_type', 'percentage'];

    protected $casts = ['price' => 'decimal:2', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'quantity' => 'integer', 'used_quantity' => 'integer', 'percentage' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->starts_at->lessThanOrEqualTo(now()) && $this->ends_at->greaterThan(now());
    }
}
