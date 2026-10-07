<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class LogisticsManifest extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['creation_token', 'creation_fingerprint'];

    protected $casts = ['sealed_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $manifest) => $manifest->reference = 'MFT-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $manifest) {
            if ($manifest->isDirty(['reference', 'logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id', 'created_by_id', 'creation_token', 'creation_fingerprint', 'type', 'direction', 'created_at'])) {
                throw new LogicException('Manifest identity must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Manifest history must be retained.'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function sourceHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'source_hub_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_hub_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(LogisticsFleet::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(LogisticsManifestParcel::class, 'manifest_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(LogisticsManifestEvent::class, 'manifest_id');
    }

    public function state(): array
    {
        return $this->only(['id', 'reference', 'logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id', 'direction', 'status', 'version', 'sealed_at', 'dispatched_at', 'received_at', 'closed_at', 'dispatcher_id', 'receiver_id']);
    }
}
