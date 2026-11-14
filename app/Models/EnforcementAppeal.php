<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnforcementAppeal extends Model
{
    protected $fillable = [
        'reference',
        'enforcement_sanction_id',
        'appellant_user_id',
        'reason',
        'evidence_notes',
        'evidence_refs',
        'status',
        'reviewed_by_id',
        'review_notes',
        'reviewed_at',
    ];

    protected $casts = [
        'evidence_refs' => 'array',
        'reviewed_at' => 'immutable_datetime',
    ];

    public function sanction(): BelongsTo
    {
        return $this->belongsTo(EnforcementSanction::class, 'enforcement_sanction_id');
    }

    public function appellant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appellant_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
