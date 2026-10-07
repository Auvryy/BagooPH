<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LogisticsManifestParcel extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['route_snapshot' => 'array', 'source_state' => 'array', 'included' => 'boolean', 'received_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $parcel) {
            if ($parcel->isDirty(['manifest_id', 'delivery_id', 'tracking_number_snapshot', 'route_snapshot', 'source_state', 'created_at'])) {
                throw new LogicException('Manifest membership identity must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Manifest membership history must be retained.'));
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
