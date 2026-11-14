<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ScanBatchItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scan_batch_request_id',
        'item_index',
        'request_token',
        'barcode_submitted',
        'delivery_id',
        'expected_action',
        'action_applied',
        'outcome',
        'status_before',
        'status_after',
        'checkpoint_reference',
        'error_message',
        'client_scanned_at',
        'server_processed_at',
        'latency_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'client_scanned_at' => 'datetime',
            'server_processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Scan batch item history cannot be modified.');
        });

        static::deleting(function (): void {
            throw new LogicException('Scan batch item history cannot be deleted.');
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ScanBatchRequest::class, 'scan_batch_request_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
