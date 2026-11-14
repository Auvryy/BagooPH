<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShipmentLossReconciliation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = [
        'proof_path',
        'proof_hash',
        'request_token_hash',
        'request_fingerprint',
        'invalidation_token_hash',
        'invalidation_fingerprint',
        'release_token_hash',
        'release_fingerprint',
    ];

    protected $casts = [
        'plan_snapshot' => 'array',
        'finding_snapshot' => 'array',
        'forward_route_hub_ids' => 'array',
        'return_route_hub_ids' => 'array',
        'release_custody_after' => 'array',
        'financial_version' => 'integer',
        'return_route_position' => 'integer',
        'proof_bytes' => 'integer',
        'invalidated_at' => 'immutable_datetime',
        'released_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->reference ??= (string) Str::uuid();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ShipmentFinancialPlan::class, 'shipment_financial_plan_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ShipmentLossFinding::class, 'shipment_loss_finding_id');
    }

    public function returnRoutes(): HasMany
    {
        return $this->hasMany(ShipmentLossReturnRoute::class, 'reconciliation_id');
    }
}
