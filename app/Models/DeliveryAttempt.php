<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class DeliveryAttempt extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token', 'request_fingerprint'];

    protected $casts = ['attempt_number' => 'integer', 'attempted_at' => 'immutable_datetime', 'source_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $attempt) {
            $attempt->reference = 'DAT-'.strtoupper((string) Str::uuid());
            $attempt->actor_role = User::whereKey($attempt->rider_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Delivery attempts must be retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Delivery attempts must be retained unchanged.'));
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
