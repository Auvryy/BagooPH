<?php

namespace App\Models;

use App\Models\Builders\DeliveryBuilder;
use App\Services\Logistics\DeliveryRecoveryService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Delivery extends Model
{
    use HasFactory;

    /**
     * Statuses where a rider still owns an assignment or physical custody.
     * Keep this list canonical and use a raw status comparison so the legacy
     * DeliveryBuilder aliases cannot accidentally broaden the busy check.
     */
    public const RIDER_ACTIVE_STATUSES = [
        'assigned',
        'assigned_pickup',
        'picked_up',
        'assigned_to_rider',
        'out_for_delivery',
        'delivery_failed',
        'return_to_sender',
    ];

    /**
     * Pickup work a rider may hold before the next claim requires a handoff.
     * This keeps batching practical without allowing an unbounded custody queue.
     */
    public const MAX_ACTIVE_PICKUPS_PER_RIDER = 5;

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
        $attempt = DeliveryAttempt::where('delivery_id', $this->id)->latest('attempt_number')->first();
        if ($this->status !== 'delivery_failed' || $this->failure_attempts < 1 || $this->failure_attempts > 2
            || $this->current_hub_id !== $this->destination_bayan_hub_id || ! $attempt
            || $attempt->attempt_number !== $this->failure_attempts || $attempt->reason_code === 'customer_refused') {
            return false;
        }
        $service = app(DeliveryRecoveryService::class);
        $receipt = $service->event($attempt, 'hub_return');
        $approval = $service->event($attempt, 'retry_approved');
        $custody = DeliveryCheckpoint::lastCustody($this);

        return $receipt && $approval && ! $approval->retry_at->isFuture()
            && ($custody['kind'] ?? null) === 'hub' && ($custody['recovery_reference'] ?? null) === $receipt->reference;
    }

    public static function riderHasActiveWork(int $riderId, ?int $exceptDeliveryId = null): bool
    {
        $placeholders = implode(',', array_fill(0, count(self::RIDER_ACTIVE_STATUSES), '?'));

        return self::query()
            ->where(function ($query) use ($riderId) {
                $query->where('courier_id', $riderId)
                    ->orWhere('assigned_rider_id', $riderId);
            })
            ->when($exceptDeliveryId, fn ($query) => $query->whereKeyNot($exceptDeliveryId))
            ->whereRaw("deliveries.status in ({$placeholders})", self::RIDER_ACTIVE_STATUSES)
            ->withoutReceivedFailure()
            ->exists();
    }

    public function scopeWithoutReceivedFailure($query)
    {
        return $query->whereNot(function ($received) {
            $received->whereRaw("deliveries.status in ('delivery_failed', 'return_to_sender')")
                ->whereExists(function ($evidence) {
                    $evidence->selectRaw('1')->from('delivery_recovery_events')
                        ->whereColumn('delivery_recovery_events.delivery_id', 'deliveries.id')
                        ->whereColumn('delivery_recovery_events.hub_id', 'deliveries.destination_bayan_hub_id')->where('event_type', 'hub_return');
                });
        });
    }

    public static function activePickupCount(int $riderId): int
    {
        return self::query()
            ->where('courier_id', $riderId)
            ->whereRaw("deliveries.status in ('assigned', 'assigned_pickup', 'picked_up')")
            ->count();
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
