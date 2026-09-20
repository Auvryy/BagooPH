<?php

namespace App\Models;

use App\Models\Builders\DeliveryBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'courier_id',
        'logistics_company_id',
        'origin_bayan_hub_id',
        'origin_mother_hub_id',
        'destination_mother_hub_id',
        'destination_bayan_hub_id',
        'current_hub_id',
        'assigned_rider_id',
        'tracking_number',
        'logistics_partner',
        'delivery_type', // doorstep, hub_self_pickup
        'status',
        'shuttle_manifest_number',
        'truck_manifest_number',
        'destination_bin',
        'failure_reason',
        'failure_attempts',
        'pickup_store_name',
        'pickup_address',
        'pickup_phone',
        'delivery_address',
        'delivery_recipient_name',
        'delivery_phone',
        'estimated_delivery_at',
        'assigned_at',
        'picked_up_at',
        'delivered_at',
        'proof_image',
        'pod_latitude',
        'pod_longitude',
        'pod_geofence_verified',
        'pod_signature',
        'courier_notes',
    ];

    protected $casts = [
        'estimated_delivery_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'pod_latitude' => 'float',
        'pod_longitude' => 'float',
        'pod_geofence_verified' => 'boolean',
        'failure_attempts' => 'integer',
    ];

    public function newEloquentBuilder($query): DeliveryBuilder
    {
        return new DeliveryBuilder($query);
    }

    public function setStatusAttribute($value): void
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }
        $this->attributes['status'] = $value !== null ? strtolower(trim((string) $value)) : null;
    }

    public function isSelfPickup(): bool
    {
        return $this->delivery_type === 'hub_self_pickup';
    }

    public function isDoorstep(): bool
    {
        return $this->delivery_type === 'doorstep';
    }

    public function canReattempt(): bool
    {
        return $this->failure_attempts < 2; // Up to 2 re-attempts (3 attempts total)
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rider_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function originBayanHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_bayan_hub_id');
    }

    public function originMotherHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_mother_hub_id');
    }

    public function destinationMotherHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_mother_hub_id');
    }

    public function destinationBayanHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'destination_bayan_hub_id');
    }

    public function currentHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'current_hub_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(DeliveryCheckpoint::class);
    }
}
