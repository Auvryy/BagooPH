<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BuyerReturnManifestParcel extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['leg_index' => 'integer', 'included' => 'boolean', 'source_state' => 'array', 'received_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $member) {
            if ($member->isDirty(['manifest_id', 'buyer_return_parcel_id', 'barcode_snapshot', 'leg_index', 'source_state', 'created_at'])) {
                throw new LogicException('Original return manifest membership must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Return manifest history must be retained.'));
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnParcel::class, 'buyer_return_parcel_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }
}
