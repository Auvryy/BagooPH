<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class PickupClaimEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'target_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->reference = 'PCE-'.strtoupper((string) Str::uuid());
            $record->actor_role = $record->actor_id ? User::whereKey($record->actor_id)->value('role') : null;
        });
        static::updating(fn () => throw new LogicException('Pickup and cash evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Pickup and cash evidence is immutable.'));
    }
}
