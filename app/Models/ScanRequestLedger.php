<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ScanRequestLedger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id',
        'hub_id',
        'delivery_id',
        'request_token',
        'request_fingerprint',
        'barcode_submitted',
        'action_applied',
        'status_before',
        'status_after',
        'checkpoint_reference',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Scan request ledger entries cannot be modified.');
        });

        static::deleting(function (): void {
            throw new LogicException('Scan request ledger entries cannot be deleted.');
        });
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
