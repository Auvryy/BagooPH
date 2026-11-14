<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MissingParcelInvestigation extends Model
{
    public const STATUSES = ['investigating', 'recovered', 'confirmed_lost', 'closed_unfounded'];

    public const SOURCE_KINDS = ['scan_timeout', 'hub_stall', 'pickup_transit_gap', 'manifest_missing', 'manual_report'];

    protected $fillable = [
        'reference',
        'delivery_id',
        'order_id',
        'buyer_return_case_id',
        'logistics_company_id',
        'hub_id',
        'logistics_manifest_event_id',
        'sla_clock_id',
        'source_kind',
        'status',
        'proves_loss',
        'liability_determined',
        'compensation_status',
        'insurer_funding_status',
        'shipment_financial_plan_id',
        'investigator_id',
        'opened_by_id',
        'resolved_by_id',
        'summary',
        'resolution_notes',
        'opened_at',
        'resolved_at',
    ];

    protected $casts = [
        'proves_loss' => 'boolean',
        'liability_determined' => 'boolean',
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $investigation): void {
            if (! $investigation->reference) {
                $investigation->reference = 'MPI-'.strtoupper(Str::random(8));
            }
        });
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyerReturnCase(): BelongsTo
    {
        return $this->belongsTo(BuyerReturnCase::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function manifestObservation(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestEvent::class, 'logistics_manifest_event_id');
    }

    public function slaClock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MissingParcelInvestigationEvent::class)->orderByDesc('id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'investigating');
    }
}
