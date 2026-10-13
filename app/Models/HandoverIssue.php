<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class HandoverIssue extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['evidence_path', 'evidence_hash', 'request_token', 'request_fingerprint'];

    protected $casts = ['source_snapshot' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $issue) => $issue->reference = 'HI-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $issue): void {
            if (array_diff(array_keys($issue->getDirty()), ['state', 'active_delivery_id', 'version', 'updated_at'])) {
                throw new LogicException('Original handover report must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Handover evidence must be retained.'));
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(HandoverIssueEvent::class)->orderBy('id');
    }
}
