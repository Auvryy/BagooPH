<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use LogicException;

class WholeTransportLossConfirmation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id', 'reference'];

    protected function casts(): array
    {
        return [
            'manifest_version' => 'integer',
            'trip_version' => 'integer',
            'proof_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->reference = 'WTL-'.strtoupper((string) Str::uuid());
        });
        static::updating(fn () => throw new LogicException('Whole-transport-loss confirmation records are immutable.'));
        static::deleting(fn () => throw new LogicException('Whole-transport-loss confirmation records are immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(LogisticsTransportTrip::class, 'trip_id');
    }

    public function manifests(): BelongsToMany
    {
        return $this->belongsToMany(LogisticsManifest::class, 'whole_transport_loss_manifests', 'whole_transport_loss_confirmation_id', 'manifest_id')
            ->withPivot(['trip_id', 'whole_transport_key']);
    }

    public function bags(): BelongsToMany
    {
        return $this->belongsToMany(MotherBag::class, 'whole_transport_loss_bags', 'whole_transport_loss_confirmation_id', 'mother_bag_id')
            ->withPivot(['manifest_id', 'return_trip_id', 'original_status', 'physical_tag', 'seal_number', 'active_physical_key']);
    }
}
