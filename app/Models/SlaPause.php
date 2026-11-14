<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SlaPause extends Model
{
    public const SCOPES = ['platform', 'province', 'company', 'hub'];

    public const REASONS = ['typhoon', 'flooding', 'earthquake', 'power_outage', 'system_outage', 'emergency'];

    protected $fillable = [
        'reference',
        'scope_type',
        'scope_value',
        'logistics_company_id',
        'logistics_hub_id',
        'reason_code',
        'title',
        'notes',
        'status',
        'starts_at',
        'ends_at',
        'resumed_at',
        'accumulated_paused_seconds',
        'created_by_id',
        'ended_by_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'resumed_at' => 'datetime',
        'accumulated_paused_seconds' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pause): void {
            if (! $pause->reference) {
                $pause->reference = 'FM-'.strtoupper(Str::random(8));
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(LogisticsCompany::class, 'logistics_company_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'logistics_hub_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    public function clocks(): HasMany
    {
        return $this->hasMany(SlaClock::class, 'active_pause_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        $now = now();

        return $query->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }

    /**
     * Checks whether this pause covers any of the supplied logistics attributes.
     *
     * @param  array<int, int>  $hubIds
     * @param  array<int, string>  $provinces
     */
    public function matchesContext(?int $companyId, array $hubIds = [], array $provinces = []): bool
    {
        if ($this->scope_type === 'platform') {
            return true;
        }

        if ($this->scope_type === 'company') {
            return $companyId !== null && (int) $this->logistics_company_id === $companyId;
        }

        if ($this->scope_type === 'hub') {
            return $this->logistics_hub_id !== null && in_array((int) $this->logistics_hub_id, array_map('intval', $hubIds), true);
        }

        if ($this->scope_type === 'province') {
            $target = mb_strtolower(trim((string) $this->scope_value));
            if ($target === '') {
                return false;
            }
            foreach ($provinces as $province) {
                if (mb_strtolower(trim((string) $province)) === $target) {
                    return true;
                }
            }
        }

        return false;
    }
}
