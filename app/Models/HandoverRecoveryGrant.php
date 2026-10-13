<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class HandoverRecoveryGrant extends Model
{
    use ImmutableGovernanceRecord;

    protected $guarded = ['id', 'reference'];

    protected $hidden = ['source_snapshot', 'authority_snapshot', 'request_token', 'request_fingerprint'];

    protected $casts = ['source_snapshot' => 'array', 'authority_snapshot' => 'array', 'expires_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $grant) => $grant->reference = 'HRG-'.strtoupper((string) Str::uuid()));
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(HandoverIssue::class, 'handover_issue_id');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(LogisticsHub::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(HandoverIssueEvent::class)->orderBy('id');
    }
}
