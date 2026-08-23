<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'shop_id',
        'discount_type',
        'discount_value',
        'min_spend',
        'max_discount',
        'usage_limit',
        'used_count',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function isValidForAmount(float $amount, ?int $shopId = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        if ($amount < (float) $this->min_spend) {
            return false;
        }

        if ($this->shop_id && $shopId && $this->shop_id !== $shopId) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $subtotal, float $shippingFee = 50.0): float
    {
        if ($this->discount_type === 'free_shipping') {
            return min($shippingFee, (float) $this->discount_value > 0 ? (float) $this->discount_value : $shippingFee);
        }

        if ($this->discount_type === 'percent') {
            $discount = ($subtotal * (float) $this->discount_value) / 100;
            if ($this->max_discount && $discount > (float) $this->max_discount) {
                return (float) $this->max_discount;
            }
            return round($discount, 2);
        }

        // Fixed discount
        return min($subtotal, (float) $this->discount_value);
    }
}
