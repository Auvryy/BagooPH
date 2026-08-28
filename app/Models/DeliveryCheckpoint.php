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

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_id');
    }
}
