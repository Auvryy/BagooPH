<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ScanBatchRequest extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_reference',
        'logistics_company_id',
        'hub_id',
        'actor_id',
        'source_channel',
        'direct_mode',
        'pinned_action',
        'submitted_count',
        'confirmed_count',
        'duplicate_count',
        'rejected_count',
        'elapsed_ms',
        'finalized_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'direct_mode' => 'boolean',
            'finalized_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            if ($batch->getOriginal('finalized_at') !== null) {
                throw new LogicException('Finalized scan batch history cannot be modified.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Scan batch history cannot be deleted.');
        });
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScanBatchItem::class, 'scan_batch_request_id')->orderBy('item_index');
    }
}
