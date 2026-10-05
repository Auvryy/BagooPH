<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourierProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'logistics_company_id',
        'assigned_hub_id',
        'assigned_barangay',
        'vehicle_id',
        'vehicle_type',
        'plate_number',
        'license_number',
        'or_cr_status',
        'is_available',
    ];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereHas('user', fn (Builder $user) => $user->eligibleCouriers())
            ->whereHas('company', fn (Builder $company) => $company->eligible())
            ->whereHas('hub', fn (Builder $hub) => $hub->eligible()
                ->whereColumn('logistics_hubs.logistics_company_id', 'courier_profiles.logistics_company_id'))
            ->where(function (Builder $vehicle) {
                // A personal vehicle has no fleet ID. Linked fleet vehicles must agree in both directions.
                $vehicle->whereNull('courier_profiles.vehicle_id')
                    ->orWhereHas('vehicle', fn (Builder $fleet) => $fleet->ready()
                        ->whereColumn('logistics_fleet.logistics_company_id', 'courier_profiles.logistics_company_id')
                        ->whereColumn('logistics_fleet.hub_id', 'courier_profiles.assigned_hub_id')
                        ->whereColumn('logistics_fleet.assigned_driver_id', 'courier_profiles.user_id'));
            });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'assigned_hub_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(LogisticsFleet::class, 'vehicle_id');
    }
}
