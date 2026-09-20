<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

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
        ?string $manifestNumber = null
    ): self {
        return self::create([
            'delivery_id' => $delivery->id,
            'hub_id' => $hub?->id ?? $delivery->current_hub_id,
            'facility_code' => $facilityCode ?? $hub?->code,
            'checkpoint_type' => $type,
            'location_name' => $location ?? $hub?->name ?? $delivery->pickup_store_name ?? 'Logistics Facility',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'barcode_scanned' => $delivery->tracking_number,
            'manifest_number' => $manifestNumber,
            'notes' => $notes,
            'scanned_by_id' => $actor?->id,
            'proof_image' => $proofImage,
        ]);
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
