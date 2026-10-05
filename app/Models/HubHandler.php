<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubHandler extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'logistics_company_id',
        'hub_id',
        'role_title',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('hub_handlers.is_active', true)
            ->whereHas('user', fn (Builder $user) => $user->eligibleLogisticsAccounts())
            ->whereHas('hub', fn (Builder $hub) => $hub->eligible()
                ->whereColumn('logistics_hubs.logistics_company_id', 'hub_handlers.logistics_company_id'));
    }

    protected static function booted(): void
    {
        static::creating(function (HubHandler $assignment) {
            $assignment->logistics_company_id ??= LogisticsHub::whereKey($assignment->hub_id)->value('logistics_company_id');
        });
        static::updating(function (HubHandler $assignment) {
            if ($assignment->isDirty(['hub_id', 'logistics_company_id', 'user_id'])) {
                throw new \DomainException('Create a separate handler assignment instead of replacing its original company or facility.');
            }
        });
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }
}
