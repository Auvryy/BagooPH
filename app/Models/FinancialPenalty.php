<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialPenalty extends Model
{
    protected $fillable = [
        'reference',
        'subject_type',
        'subject_id',
        'subject_user_id',
        'category',
        'amount_cents',
        'settled_cents',
        'outstanding_cents',
        'status',
        'reason_code',
        'reason',
        'evidence_summary',
        'evidence_refs',
        'order_id',
        'delivery_id',
        'hub_id',
        'sla_clock_id',
        'scale_discrepancy_log_id',
        'proposed_by_id',
        'approved_by_id',
        'waived_by_id',
        'wallet_transaction_id',
        'decision_notes',
        'proposed_at',
        'decided_at',
        'settled_at',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'amount_cents' => 'integer',
        'settled_cents' => 'integer',
        'outstanding_cents' => 'integer',
        'evidence_refs' => 'array',
        'proposed_at' => 'immutable_datetime',
        'decided_at' => 'immutable_datetime',
        'settled_at' => 'immutable_datetime',
    ];

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class, 'hub_id');
    }

    public function slaClock(): BelongsTo
    {
        return $this->belongsTo(SlaClock::class);
    }

    public function scaleDiscrepancyLog(): BelongsTo
    {
        return $this->belongsTo(ScaleDiscrepancyLog::class);
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(EnforcementSanction::class, 'source_financial_penalty_id');
    }
}
