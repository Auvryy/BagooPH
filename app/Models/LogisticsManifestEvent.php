<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class LogisticsManifestEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $casts = ['payload' => 'array', 'source_state' => 'array', 'target_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = 'MFE-'.strtoupper((string) Str::uuid());
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Manifest evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Manifest evidence is immutable.'));
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function parcel(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestParcel::class, 'manifest_parcel_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
