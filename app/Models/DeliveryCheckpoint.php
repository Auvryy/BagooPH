<?php

namespace App\Models;

use App\Services\Notifications\LifecycleNoticeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class DeliveryCheckpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'hub_id',
        'facility_code',
        'checkpoint_type',
        'location_name',
        'latitude',
        'longitude',
        'barcode_scanned',
        'manifest_number',
        'notes',
        'scanned_by_id',
        'proof_image',
        'scan_provenance',
        'source_state',
        'target_state',
        'custody_before',
        'custody_after',
        'source_checkpoint_id',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'source_state' => 'array',
        'target_state' => 'array',
        'custody_before' => 'array',
        'custody_after' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->record_reference = 'CP-'.strtoupper((string) Str::uuid());
            $record->actor_role = $record->scanned_by_id ? User::whereKey($record->scanned_by_id)->value('role') : null;
        });
        static::updating(fn () => throw new LogicException('Custody evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Custody evidence is immutable.'));
    }

    public static function state(Delivery $delivery): array
    {
        return ['delivery_status' => $delivery->status, 'order_status' => $delivery->order?->status,
            'logistics_company_id' => $delivery->logistics_company_id, 'current_hub_id' => $delivery->current_hub_id,
            'pickup_rider_id' => $delivery->courier_id, 'assigned_rider_id' => $delivery->assigned_rider_id];
    }

    public static function lastCustody(Delivery $delivery): array
    {
        return $delivery->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first()?->custody_after
            ?? ['kind' => 'unknown', 'reason' => 'No prior recorded custody evidence.'];
    }

    public static function record(
        Delivery $delivery,
        string $type,
        ?string $location = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $proofImage = null,
        ?LogisticsHub $hub = null,
        ?string $facilityCode = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $manifestNumber = null,
        ?string $barcodeScanned = null,
        array $evidence = [],
        ?string $scanProvenance = null,
    ): self {
        $checkpoint = self::create([
            'delivery_id' => $delivery->id,
            'hub_id' => $hub?->id ?? $delivery->current_hub_id,
            'facility_code' => $facilityCode ?? $hub?->code,
            'checkpoint_type' => $type,
            'location_name' => $location ?? $hub?->name ?? $delivery->pickup_store_name ?? 'Logistics Facility',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'barcode_scanned' => $barcodeScanned,
            'scan_provenance' => $scanProvenance ?? ($barcodeScanned === null ? 'not_scanned' : 'submitted'),
            'manifest_number' => $manifestNumber,
            'notes' => $notes,
            'scanned_by_id' => $actor?->id,
            'proof_image' => $proofImage,
        ] + array_intersect_key($evidence, array_flip(['source_state', 'target_state', 'custody_before', 'custody_after', 'source_checkpoint_id'])));

        app(LifecycleNoticeService::class)->checkpoint($checkpoint);

        return $checkpoint;
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_id');
    }
}
