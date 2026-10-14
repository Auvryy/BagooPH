<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HandoverManifestReceipt extends Model
{
    use ImmutableGovernanceRecord;

    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['source_manifest_snapshot'];

    protected $casts = ['route_snapshot' => 'array', 'source_manifest_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->reference = 'HMR-'.strtoupper((string) Str::uuid());
            $receipt->actor_role = User::whereKey($receipt->received_by_id)->value('role');
        });
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(HandoverRecoveryGrant::class, 'handover_recovery_grant_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifest::class, 'manifest_id');
    }

    public function manifestEvent(): BelongsTo
    {
        return $this->belongsTo(LogisticsManifestEvent::class, 'manifest_event_id');
    }

    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(DeliveryCheckpoint::class, 'checkpoint_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_id');
    }

    public function supports(?DeliveryCheckpoint $checkpoint = null): bool
    {
        $checkpoint ??= $this->checkpoint;
        $grant = $this->grant;
        $scan = $this->manifestEvent;
        $manifest = $this->manifest;
        $context = $grant?->source_snapshot['context'] ?? [];

        return $grant?->mode === 'hub_manifest'
            && $grant->delivery_id === $this->delivery_id
            && $grant->handover_issue_id === $this->handover_issue_id
            && $grant->hub_id === $this->hub_id
            && $grant->holder_id !== $this->received_by_id
            && $grant->events()->where('event_type', 'holder_acknowledged')->where('actor_id', $grant->holder_id)->exists()
            && ($context['manifest']['state']['id'] ?? null) === $this->manifest_id
            && ($context['member_id'] ?? null) === $this->manifest_parcel_id
            && ($context['route'] ?? null) === $this->route_snapshot
            && ($context['manifest'] ?? null) === $this->source_manifest_snapshot
            && $manifest?->destination_hub_id === $this->hub_id
            && $manifest->logistics_company_id === $grant->logistics_company_id
            && $this->actor_role === 'logistics'
            && $this->created_at->betweenIncluded($grant->created_at, $grant->expires_at)
            && $scan?->event_type === 'handover-recovery-received'
            && $scan->manifest_id === $this->manifest_id
            && $scan->manifest_parcel_id === $this->manifest_parcel_id
            && $scan->actor_id === $this->received_by_id && $scan->hub_id === $this->hub_id
            && ($scan->payload['handover_recovery_grant_id'] ?? null) === $grant->id
            && $scan->barcode_scanned === $this->tracking_number_snapshot
            && $checkpoint?->id === $this->checkpoint_id
            && $checkpoint->delivery_id === $this->delivery_id
            && $checkpoint->hub_id === $this->hub_id
            && $checkpoint->scanned_by_id === $this->received_by_id
            && $checkpoint->barcode_scanned === $this->tracking_number_snapshot
            && $checkpoint->manifest_number === $manifest->reference
            && $checkpoint->source_state === $scan->source_state
            && $checkpoint->target_state === $scan->target_state
            && ($checkpoint->custody_after['kind'] ?? null) === 'hub'
            && ($checkpoint->custody_after['hub_id'] ?? null) === $this->hub_id
            && ($checkpoint->custody_after['manifest_scan_reference'] ?? null) === $scan->reference
            && ($checkpoint->custody_after['handover_recovery_reference'] ?? null) === $grant->reference;
    }
}
