<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class LogisticsTransportTrip extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['creation_token', 'creation_fingerprint'];

    protected $casts = ['version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $trip) => $trip->reference = (string) Str::uuid());
        static::updating(function (self $trip) {
            if ($trip->isDirty(array_diff(array_keys($trip->getAttributes()), ['status', 'version', 'updated_at']))) {
                throw new LogicException('The original trip identity must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Trip history must be retained.'));
    }

    public function manifests()
    {
        return $this->belongsToMany(LogisticsManifest::class, 'logistics_trip_manifests', 'trip_id', 'manifest_id');
    }
}
