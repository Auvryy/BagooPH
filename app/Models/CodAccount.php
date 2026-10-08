<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class CodAccount extends Model
{
    public const SOURCE_FIELDS = ['reference', 'order_id', 'delivery_id', 'logistics_company_id', 'hub_id', 'collector_id',
        'delivery_checkpoint_id', 'pickup_cash_entry_id', 'source_kind', 'expected_cents', 'product_subtotal_cents',
        'discount_cents', 'shipping_cents', 'created_at'];

    protected $guarded = ['id', 'reference'];

    protected $casts = ['state' => 'array', 'version' => 'integer', 'expected_cents' => 'integer',
        'product_subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'shipping_cents' => 'integer', 'reconciled_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $account) => $account->reference = 'CASH-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $account) {
            if ($account->isDirty(self::SOURCE_FIELDS) || $account->getRawOriginal('reconciled_at') !== null) {
                throw new LogicException('COD source and reconciled records must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('COD history must be retained.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CodCashEvent::class);
    }
}
