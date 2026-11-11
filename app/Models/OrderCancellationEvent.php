<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class OrderCancellationEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token_hash', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'target_state' => 'array', 'version' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = 'OCE-'.Str::upper((string) Str::uuid());
            $event->actor_role = $event->actor_id ? User::whereKey($event->actor_id)->value('role') : null;
            $event->created_at ??= now();
        });
        static::updating(fn () => throw new LogicException('Cancellation evidence must be retained.'));
        static::deleting(fn () => throw new LogicException('Cancellation evidence must be retained.'));
    }
}
