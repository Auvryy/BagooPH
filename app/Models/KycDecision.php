<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class KycDecision extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'user_id', 'reviewer_id', 'reviewer_role', 'reviewer_name', 'subject_role',
        'submission_token', 'decision', 'reason', 'submission', 'before_state',
        'after_state', 'reviewed_at',
    ];

    protected $hidden = ['submission_token', 'submission', 'before_state', 'after_state'];

    protected function casts(): array
    {
        return [
            'submission' => 'array',
            'before_state' => 'array',
            'after_state' => 'array',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('KYC decisions are immutable.');
        });
        static::deleting(function (): void {
            throw new LogicException('KYC decisions are immutable.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
