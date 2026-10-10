<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class ParcelRecoveryCase extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['route_snapshot' => 'array', 'hub_ids' => 'array', 'source_state' => 'array', 'custody_snapshot' => 'array', 'version' => 'integer', 'leg_index' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $case) => $case->reference = 'PRC-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $case) {
            if (collect(array_keys($case->getDirty()))->diff(['state', 'leg_index', 'version', 'active_delivery_id', 'updated_at'])->isNotEmpty()) {
                throw new LogicException('Parcel recovery identity must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Parcel recovery history must be retained.'));
    }

    public function observation(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestEvent::class, 'observation_event_id');
    }

    public function originalMember(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestParcel::class, 'original_manifest_parcel_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ParcelRecoveryEvent::class);
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(LogisticsManifest::class);
    }
}
