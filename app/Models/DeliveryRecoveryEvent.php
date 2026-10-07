<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class DeliveryRecoveryEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'target_state' => 'array', 'retry_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = 'DRE-'.strtoupper((string) Str::uuid());
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Delivery recovery evidence must be retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Delivery recovery evidence must be retained unchanged.'));
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(DeliveryAttempt::class, 'delivery_attempt_id');
    }
}
