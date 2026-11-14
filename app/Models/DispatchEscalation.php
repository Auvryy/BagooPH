<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DispatchEscalation extends Model
{
    public const TIERS = [
        'open_pool',
        'direct_assignment',
        'priority_bounty_deferred',
        'hub_emergency_sweep',
        'unpicked_fallback',
    ];

    protected $fillable = [
        'delivery_id',
        'order_id',
        'origin_bayan_hub_id',
        'logistics_company_id',
        'current_tier',
        'status',
        'coverage_outcome',
        'is_urgent',
        'dispatch_delay_label',
        'surge_deferred_note',
        'penalty_exempt',
        'pickup_sla_started_at',
        'delayed_notice_sent_at',
        'escalated_at',
        'resolved_at',
        'assigned_rider_id',
        'resolution_action',
        'resolution_notes',
    ];

    protected $casts = [
        'is_urgent' => 'boolean',
        'penalty_exempt' => 'boolean',
        'pickup_sla_started_at' => 'datetime',
        'delayed_notice_sent_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function originHub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'origin_bayan_hub_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_rider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DispatchEscalationEvent::class)->orderByDesc('id');
    }
}
