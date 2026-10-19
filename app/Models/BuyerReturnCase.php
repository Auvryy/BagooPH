<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class BuyerReturnCase extends Model
{
    public const TERMINAL = ['withdrawn', 'expired', 'denied', 'repaid', 'closed_no_repayment'];

    protected $guarded = ['id'];

    protected $hidden = ['creation_token_hash', 'creation_fingerprint'];

    protected $casts = ['version' => 'integer', 'route_snapshot' => 'array', 'delivered_at' => 'datetime',
        'request_until' => 'datetime', 'response_until' => 'datetime', 'appeal_until' => 'datetime', 'dropoff_until' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $record) {
            if (array_diff(array_keys($record->getDirty()), ['status', 'version', 'appeal_until', 'dropoff_until', 'updated_at'])) {
                throw new LogicException('The original return request must be retained unchanged.');
            }
        });
        static::deleting(fn () => throw new LogicException('Return history must be retained.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BuyerReturnItem::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(BuyerReturnEvidence::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(BuyerReturnEvent::class)->orderBy('sequence');
    }

    public function parcel(): HasOne
    {
        return $this->hasOne(BuyerReturnParcel::class);
    }
}
