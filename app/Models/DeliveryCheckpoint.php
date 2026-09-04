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
        'checkpoint_type',
        'location_name',
        'barcode_scanned',
        'notes',
        'scanned_by_id',
        'proof_image',
    ];

    public static function record(
        Delivery $delivery,
        string $type,
        ?string $location = null,
        ?string $notes = null,
        ?User $actor = null,
        ?string $proofImage = null
    ): self {
        return self::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => $type,
            'location_name' => $location ?? $delivery->pickup_store_name ?? 'Logistics Station',
            'barcode_scanned' => $delivery->tracking_number,
            'notes' => $notes,
            'scanned_by_id' => $actor?->id,
            'proof_image' => $proofImage,
        ]);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_id');
    }
}
