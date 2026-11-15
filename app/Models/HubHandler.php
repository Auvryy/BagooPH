<?php

namespace App\Models;

use App\Models\Concerns\RetainsRestrictionHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubHandler extends Model
{
    use HasFactory;
    use RetainsRestrictionHistory;

    public const JOB_ROLE_ALL = 'all';

    public const JOB_ROLE_INTAKE = 'intake';

    public const JOB_ROLE_PACK_DISPATCH = 'pack_dispatch';

    public const JOB_ROLES = [
        self::JOB_ROLE_ALL,
        self::JOB_ROLE_INTAKE,
        self::JOB_ROLE_PACK_DISPATCH,
    ];

    protected $fillable = [
        'user_id',
        'logistics_company_id',
        'hub_id',
        'role_title',
        'job_role',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function roleTitleFor(string $jobRole): string
    {
        return match ($jobRole) {
            self::JOB_ROLE_INTAKE => 'Entry / Intake Worker',
            self::JOB_ROLE_PACK_DISPATCH => 'Pack & Dispatch Worker',
            default => 'Hub Operator (All Tasks)',
        };
    }

    public static function jobRoleLabel(?string $jobRole): string
    {
        return match ($jobRole) {
            self::JOB_ROLE_INTAKE => 'Entry / Intake only',
            self::JOB_ROLE_PACK_DISPATCH => 'Pack & Dispatch only',
            default => 'Full Hub Operations',
        };
    }

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
