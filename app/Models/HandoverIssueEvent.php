<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HandoverIssueEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['parcel_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Handover history is immutable.'));
        static::deleting(fn () => throw new LogicException('Handover history is immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
