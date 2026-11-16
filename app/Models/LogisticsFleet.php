<?php

namespace App\Models;

use App\Models\Concerns\RetainsRestrictionHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsFleet extends Model
{
    use HasFactory;
    use RetainsRestrictionHistory;

    protected $table = 'logistics_fleet';

    protected $fillable = [
        'logistics_company_id',
        'hub_id',
        'plate_number',
        'vehicle_type', // motorcycle, tricycle, l300_van, wing_truck
        'model',
        'capacity_kg',
        'assigned_driver_id',
        'transport_driver_id',
        'status', // active, maintenance, idle
    ];

    protected $casts = [
        'capacity_kg' => 'float',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('logistics_fleet.status', 'active')
            ->whereIn('logistics_fleet.vehicle_type', ['motorcycle', 'tricycle', 'l300_van', 'wing_truck'])
            ->whereHas('company', fn (Builder $company) => $company->eligible())
            ->whereHas('hub', fn (Builder $hub) => $hub->eligible()
                ->whereColumn('logistics_hubs.logistics_company_id', 'logistics_fleet.logistics_company_id'))
            ->where(function (Builder $drivers) {
                $drivers->whereHas('transportDriver', fn ($driver) => $driver->eligible()
                    ->whereColumn('logistics_transport_drivers.logistics_company_id', 'logistics_fleet.logistics_company_id')
                    ->whereColumn('logistics_transport_drivers.hub_id', 'logistics_fleet.hub_id'))
                    ->orWhere(fn ($legacy) => $legacy->whereNull('transport_driver_id')->where(function (Builder $driver) {
                        $driver->whereNull('logistics_fleet.assigned_driver_id')
                            ->orWhereHas('driver', fn (Builder $user) => $user->eligibleCouriers()
                                ->whereHas('courierProfile', fn (Builder $profile) => $profile
                                    ->whereColumn('courier_profiles.logistics_company_id', 'logistics_fleet.logistics_company_id')
                                    ->whereColumn('courier_profiles.assigned_hub_id', 'logistics_fleet.hub_id')
                                    ->whereColumn('courier_profiles.vehicle_id', 'logistics_fleet.id')));
                    }));
            });
    }

    public function transportDriver(): BelongsTo
    {
        return $this->belongsTo(LogisticsTransportDriver::class, 'transport_driver_id');
    }

    public function driverName(): string
    {
        return $this->transportDriver?->name ?? $this->driver?->name ?? 'Unassigned driver';
    }

    public function scopeDispatchReady(Builder $query): Builder
    {
        return $query->ready()->where(fn ($drivers) => $drivers->whereNotNull('transport_driver_id')
            ->orWhereHas('driver.courierProfile', fn ($profile) => $profile->operational()->where('is_available', true)))
            ->whereNotIn('id', LogisticsManifest::awaitingPhysicalReceipt()->whereNotNull('vehicle_id')->select('vehicle_id'))
            ->where(fn ($q) => $q->whereNull('assigned_driver_id')->orWhereNotIn('assigned_driver_id', LogisticsManifest::awaitingPhysicalReceipt()->whereNotNull('driver_id')->select('driver_id')))
            ->where(fn ($q) => $q->whereNull('transport_driver_id')->orWhereNotIn('transport_driver_id', LogisticsManifest::awaitingPhysicalReceipt()->whereNotNull('transport_driver_id')->select('transport_driver_id')));
    }

    public function hasBusyCourier(): bool
    {
        return $this->assigned_driver_id !== null && Delivery::riderHasActiveWork($this->assigned_driver_id);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_id');
    }
}
