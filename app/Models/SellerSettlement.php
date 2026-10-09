<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SellerSettlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'product_cents' => 'integer', 'seller_cents' => 'integer',
        'commission_cents' => 'integer', 'shipping_cents' => 'integer', 'discount_cents' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement sources must be retained.'));
        static::deleting(fn () => throw new LogicException('Settlement sources must be retained.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SellerSettlementEvent::class);
    }
}
