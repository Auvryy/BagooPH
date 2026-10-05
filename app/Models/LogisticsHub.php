<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsHub extends Model
{
    use HasFactory;

    protected $fillable = [
        'logistics_company_id',
        'name',
        'code',
        'tier',
        'province',
        'city_municipality',
        'barangay',
        'address',
        'latitude',
        'longitude',
        'capacity',
        'coverage_barangays',
        'allows_self_pickup',
        'is_active',
    ];

    protected $casts = [
        'coverage_barangays' => 'array',
        'allows_self_pickup' => 'boolean',
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'capacity' => 'integer',
    ];

    public function isMotherHub(): bool
    {
        return $this->tier === 'regional_mother_hub';
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('logistics_hubs.is_active', true)
            ->whereIn('logistics_hubs.tier', ['local_bayan_hub', 'regional_mother_hub'])
            ->whereHas('company', fn (Builder $company) => $company->eligible());
    }

    public function isBayanHub(): bool
    {
        return $this->tier === 'local_bayan_hub';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function handlers(): HasMany
    {
        return $this->hasMany(HubHandler::class, 'hub_id');
    }

    public function fleet(): HasMany
    {
        return $this->hasMany(LogisticsFleet::class, 'hub_id');
    }

    public function assignedRiders(): HasMany
    {
        return $this->hasMany(CourierProfile::class, 'assigned_hub_id');
    }

    public function outboundDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'origin_bayan_hub_id');
    }

    public function inboundDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'destination_bayan_hub_id');
    }

    public function currentDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'current_hub_id');
    }

    public function selfPickupOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'pickup_hub_id');
    }

    /**
     * Check if this Bayan Hub covers a given barangay.
     */
    public function coversBarangay(string $barangay): bool
    {
        if (empty($this->coverage_barangays)) {
            return false;
        }

        return in_array(strtolower(trim($barangay)), array_map('strtolower', $this->coverage_barangays));
    }
}
