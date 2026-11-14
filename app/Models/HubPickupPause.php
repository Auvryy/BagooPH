<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class HubPickupPause extends Model
{
    protected $fillable = [
        'reference',
        'logistics_hub_id',
        'logistics_company_id',
        'barangay',
        'reason',
        'status',
        'paused_at',
        'resumed_at',
        'created_by_id',
        'resumed_by_id',
    ];

    protected $casts = [
        'paused_at' => 'datetime',
        'resumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pause): void {
            if (! $pause->reference) {
                $pause->reference = 'HPP-'.strtoupper(Str::random(8));
            }
        });
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'logistics_hub_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function resumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function matchesBarangay(?string $barangay): bool
    {
        if ($this->barangay === null || trim($this->barangay) === '') {
            return true;
        }

        if ($barangay === null || trim($barangay) === '') {
            return false;
        }

        return mb_strtolower(trim($this->barangay)) === mb_strtolower(trim($barangay));
    }
}
