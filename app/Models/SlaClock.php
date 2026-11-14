<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaClock extends Model
{
    public const KINDS = [
        'seller_acceptance_pack',
        'pickup_dispatch_wait',
        'hub_idle_sorting',
        'final_mile_staging',
        'forward_interhub_transit',
        'counter_pickup_holding',
        'return_decision_window',
        'reverse_consolidation_transit',
    ];

    protected $fillable = [
        'subject_type',
        'subject_id',
        'clock_kind',
        'order_id',
        'delivery_id',
        'logistics_company_id',
        'hub_id',
        'status',
        'pause_reason',
        'active_pause_id',
        'started_at',
        'warning_at',
        'breach_at',
        'critical_at',
        'paused_at',
        'paused_seconds',
        'stopped_at',
        'last_warned_at',
        'last_breached_at',
        'last_critical_at',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'warning_at' => 'datetime',
        'breach_at' => 'datetime',
        'critical_at' => 'datetime',
        'paused_at' => 'datetime',
        'paused_seconds' => 'integer',
        'stopped_at' => 'datetime',
        'last_warned_at' => 'datetime',
        'last_breached_at' => 'datetime',
        'last_critical_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function activePause(): BelongsTo
    {
        return $this->belongsTo(SlaPause::class, 'active_pause_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SlaClockEvent::class)->orderByDesc('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['running', 'paused']);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }
}
