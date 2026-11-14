<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnforcementSanction extends Model
{
    public const SCOPES = [
        'warning',
        'cod_restricted',
        'review_restricted',
        'catalog_visibility_restricted',
        'payout_hold',
        'pickup_zone_restricted',
    ];

    public const ACTIVE_STATUSES = ['active'];

    protected $fillable = [
        'reference',
        'subject_type',
        'subject_id',
        'subject_user_id',
        'scope',
        'status',
        'reason_code',
        'reason',
        'evidence_summary',
        'evidence_refs',
        'source_financial_penalty_id',
        'source_signal_id',
        'auto_release_on_debt_clear',
        'issued_by_id',
        'issued_by_role',
        'resolved_by_id',
        'issued_at',
        'expires_at',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'evidence_refs' => 'array',
        'auto_release_on_debt_clear' => 'boolean',
        'issued_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function sourcePenalty(): BelongsTo
    {
        return $this->belongsTo(FinancialPenalty::class, 'source_financial_penalty_id');
    }

    public function sourceSignal(): BelongsTo
    {
        return $this->belongsTo(ModerationSignal::class, 'source_signal_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(EnforcementAppeal::class)->orderByDesc('id');
    }
}
