<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

class LogisticsManifest extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['creation_token', 'creation_fingerprint'];

    protected $casts = ['sealed_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'closed_at' => 'datetime', 'version' => 'integer', 'recovery_leg_index' => 'integer'];

    public function scopeAwaitingPhysicalReceipt(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $hasWholeTransportBags = Schema::hasTable('whole_transport_loss_bags');
            $query->where('logistics_manifests.status', 'dispatched');
            $query->orWhereExists(fn ($held) => $held->selectRaw('1')->from('mother_bags as original_bag')
                ->whereColumn('original_bag.manifest_id', 'logistics_manifests.id')->whereNotNull('original_bag.dispatched_at')
                ->whereNotIn('original_bag.status', ['received', 'emptied_at_source'])
                ->when($hasWholeTransportBags, fn ($q) => $q->whereNotExists(fn ($wtl) => $wtl->selectRaw('1')
                    ->from('whole_transport_loss_bags as wtl_bag')
                    ->whereColumn('wtl_bag.mother_bag_id', 'original_bag.id')
                    ->where('original_bag.status', 'lost')
                    ->whereNull('original_bag.opened_at'))));
            if (Schema::hasTable('shipment_loss_accounting_manifests')) {
                $query->orWhere(fn ($held) => $held->whereExists(fn ($loss) => $loss->selectRaw('1')->from('shipment_loss_accounting_manifests as loss_transport')
                    ->whereColumn('loss_transport.manifest_id', 'logistics_manifests.id')->whereIn('loss_transport.relation_kind', ['source', 'original']))
                    ->when(Schema::hasTable('whole_transport_loss_manifests') && Schema::hasTable('whole_transport_loss_confirmations'),
                        fn ($q) => $q->whereNotExists(fn ($wtl) => $wtl->selectRaw('1')
                            ->from('whole_transport_loss_manifests as wtl_manifest')
                            ->join('whole_transport_loss_confirmations as wtl_confirm', 'wtl_confirm.id', '=', 'wtl_manifest.whole_transport_loss_confirmation_id')
                            ->whereColumn('wtl_manifest.manifest_id', 'logistics_manifests.id')
                            ->where('wtl_confirm.scope_kind', 'whole_transport')))
                    ->whereNotExists(fn ($bag) => $bag->selectRaw('1')->from('mother_bags as arrived_loss_bag')
                        ->whereColumn('arrived_loss_bag.manifest_id', 'logistics_manifests.id')->whereNotNull('arrived_loss_bag.opened_at'))
                    ->whereNotExists(fn ($bag) => $bag->selectRaw('1')->from('mother_bag_return_parcels as arrived_loss_return_member')
                        ->join('buyer_return_manifest_parcels as arrived_loss_expected_return', 'arrived_loss_expected_return.id', '=', 'arrived_loss_return_member.buyer_return_manifest_parcel_id')
                        ->join('mother_bags as arrived_loss_return_bag', 'arrived_loss_return_bag.id', '=', 'arrived_loss_return_member.mother_bag_id')
                        ->whereColumn('arrived_loss_expected_return.manifest_id', 'logistics_manifests.id')->whereNotNull('arrived_loss_return_bag.opened_at'))
                    ->whereNotExists(fn ($receipt) => $receipt->selectRaw('1')->from('logistics_manifest_events as loss_arrival')
                        ->whereColumn('loss_arrival.manifest_id', 'logistics_manifests.id')->where(fn ($actual) => $actual
                        ->where(fn ($forward) => $forward->where('loss_arrival.event_type', 'parcel_received')->whereNull('loss_arrival.payload->parcel_recovery_reference'))
                        ->orWhere(fn ($reverse) => $reverse->where('loss_arrival.event_type', 'buyer-return-received')->whereNull('loss_arrival.payload->recovery_scan_id'))
                        ->orWhere(fn ($vehicle) => $vehicle->where('loss_arrival.event_type', 'transport-arrived')->where('loss_arrival.actor_role', 'logistics')
                            ->whereColumn('loss_arrival.hub_id', 'logistics_manifests.destination_hub_id')->whereNull('loss_arrival.manifest_parcel_id')->whereNull('loss_arrival.barcode_scanned')))));
            }
            if (Schema::hasColumn('mother_bags', 'return_trip_id')) {
                $query->orWhereExists(fn ($held) => $held->selectRaw('1')->from('logistics_trip_manifests as tm')
                    ->join('logistics_transport_trips as t', 't.id', '=', 'tm.trip_id')
                    ->join('mother_bags as s', 's.return_trip_id', '=', 't.id')
                    ->whereColumn('tm.manifest_id', 'logistics_manifests.id')->where('t.status', 'dispatched')
                    ->whereNotIn('s.status', ['received', 'emptied_at_source'])
                    ->when($hasWholeTransportBags, fn ($q) => $q->whereNotExists(fn ($wtl) => $wtl->selectRaw('1')
                        ->from('whole_transport_loss_bags as wtl_return_bag')
                        ->whereColumn('wtl_return_bag.mother_bag_id', 's.id')
                        ->where('s.status', 'lost')
                        ->whereNull('s.opened_at'))));
            }
        });
    }

    protected static function booted(): void
    {
        static::creating(fn (self $manifest) => $manifest->reference = 'MFT-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $manifest) {
            if ($manifest->isDirty(['reference', 'logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id', 'created_by_id', 'creation_token', 'creation_fingerprint', 'type', 'direction', 'created_at', 'parcel_recovery_case_id', 'recovery_leg_index'])) {
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

    public function buyerReturnParcels(): HasMany
    {
        return $this->hasMany(BuyerReturnManifestParcel::class, 'manifest_id');
    }

    public function buyerReturnRoute(): HasOne
    {
        return $this->hasOne(BuyerReturnManifestRoute::class, 'manifest_id');
    }

    public function buyerReturnRecoveryRoute(): HasOne
    {
        return $this->hasOne(BuyerReturnRecoveryRoute::class, 'manifest_id');
    }

    public function lossReturnRoute(): HasOne
    {
        return $this->hasOne(ShipmentLossReturnRoute::class, 'manifest_id');
    }

    public function getBuyerReturnRecoveryIdAttribute(): ?int
    {
        return $this->direction === 'buyer_return' && Schema::hasTable('buyer_return_recovery_routes')
            ? $this->buyerReturnRecoveryRoute?->recovery_id : null;
    }

    public function getBuyerReturnCaseIdAttribute(): ?int
    {
        if ($this->direction !== 'buyer_return') {
            return null;
        }

        return $this->buyerReturnRoute?->buyer_return_case_id
            ?? ($this->buyer_return_recovery_id ? $this->buyerReturnRecoveryRoute->recovery->buyer_return_case_id : null)
            ?? (Schema::hasTable('shipment_loss_return_routes') ? $this->lossReturnRoute?->reconciliation?->plan?->buyer_return_case_id : null);
    }

    public function getBuyerReturnLegIndexAttribute(): ?int
    {
        if ($this->direction !== 'buyer_return') {
            return null;
        }

        return $this->buyerReturnRoute?->leg_index
            ?? ($this->buyer_return_recovery_id ? $this->buyerReturnRecoveryRoute->leg_index : null)
            ?? (Schema::hasTable('shipment_loss_return_routes') ? $this->lossReturnRoute?->leg_index : null);
    }

    public function recoveryCase(): BelongsTo
    {
        return $this->belongsTo(ParcelRecoveryCase::class, 'parcel_recovery_case_id');
    }

    public function correctiveReceipts(): HasMany
    {
        return $this->hasMany(HandoverManifestReceipt::class, 'manifest_id');
    }

    public function state(): array
    {
        return $this->only(['id', 'reference', 'logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id', 'direction', 'status', 'version', 'sealed_at', 'dispatched_at', 'received_at', 'closed_at', 'dispatcher_id', 'receiver_id']);
    }
}
