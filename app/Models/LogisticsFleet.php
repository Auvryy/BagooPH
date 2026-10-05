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
            ->where(function (Builder $driver) {
                $driver->whereNull('logistics_fleet.assigned_driver_id')
                    ->orWhereHas('driver', fn (Builder $user) => $user->eligibleCouriers()
                        ->whereHas('courierProfile', fn (Builder $profile) => $profile
                            ->whereColumn('courier_profiles.logistics_company_id', 'logistics_fleet.logistics_company_id')
                            ->whereColumn('courier_profiles.assigned_hub_id', 'logistics_fleet.hub_id')
                            ->whereColumn('courier_profiles.vehicle_id', 'logistics_fleet.id')));
            });
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
