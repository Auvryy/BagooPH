<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class DeliveryReturnEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['forward_route' => 'array', 'hub_ids' => 'array', 'source_state' => 'array', 'target_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->reference = 'DRETN-'.strtoupper((string) Str::uuid());
            $record->actor_role = User::whereKey($record->actor_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Return custody evidence must be retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Return custody evidence must be retained unchanged.'));
    }
}
