<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BuyerReturnParcel extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['route_snapshot' => 'array', 'hub_ids' => 'array', 'custody' => 'array',
        'route_position' => 'integer', 'version' => 'integer', 'weight_grams' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $parcel) {
            if ($parcel->isDirty(['buyer_return_case_id', 'approval_event_id', 'logistics_company_id', 'barcode', 'route_snapshot', 'hub_ids', 'created_at'])
                || ($parcel->getRawOriginal('weight_grams') !== null && $parcel->isDirty('weight_grams'))) {
                throw new LogicException('The original return parcel and accepted weight must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Buyer return custody must be retained.'));
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnCase::class, 'buyer_return_case_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BuyerReturnManifestParcel::class);
    }

    public function currentHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'current_hub_id');
    }

    public function state(): array
    {
        return $this->only(['id', 'barcode', 'status', 'version', 'current_hub_id', 'route_position', 'weight_grams', 'custody']);
    }
}
