<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

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
        'issuer_type',
        'current_revision_id',
        'archived_at',
        'campaign_version',
        'suspended_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'min_spend' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'archived_at' => 'datetime',
        'shop_id' => 'integer',
        'current_revision_id' => 'integer',
        'campaign_version' => 'integer',
        'suspended_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $voucher) {
            if ($voucher->isDirty(['issuer_type', 'shop_id'])) {
                throw new LogicException('A voucher issuer and shop scope cannot change. Reissue reviewed terms instead.');
            }
            if ($voucher->getOriginal('current_revision_id') && $voucher->isDirty(['code', 'discount_type', 'discount_value', 'min_spend', 'max_discount', 'usage_limit', 'expires_at'])) {
                throw new LogicException('Funded voucher terms require a new immutable revision.');
            }
        });
        static::deleting(function (self $voucher) {
            if ($voucher->current_revision_id || $voucher->used_count > 0 || $voucher->archived_at) {
                throw new LogicException('Published and historical vouchers must be archived.');
            }
        });
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(VoucherRevision::class, 'current_revision_id');
    }

    public function draft(): HasOne
    {
        return $this->hasOne(VoucherCampaignDraft::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VoucherRevision::class);
    }

    public function campaignActions(): HasMany
    {
        return $this->hasMany(VoucherCampaignAction::class);
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
