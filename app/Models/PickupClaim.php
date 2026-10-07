<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class PickupClaim extends Model
{
    protected $guarded = ['id', 'reference'];

    protected $hidden = ['code_hash'];

    protected $casts = ['ready_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'code_issued_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'locked_until' => 'immutable_datetime', 'failed_verifications' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $claim) => $claim->reference = 'PUP-'.strtoupper((string) Str::uuid()));
        static::updating(function (self $claim) {
            if ($claim->isDirty(['reference', 'delivery_id', 'ready_checkpoint_id', 'hub_id', 'buyer_id', 'prepared_by_id', 'tracking_number_snapshot', 'ready_at', 'expires_at', 'created_at'])
                || ($claim->getRawOriginal('code_hash') !== null && $claim->isDirty(['code_hash', 'code_issued_at']))
                || ($claim->getRawOriginal('consumed_at') !== null && $claim->isDirty('consumed_at'))) {
                throw new LogicException('Pickup source identity, deadline and used code must be retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Pickup history must be retained.'));
    }

    public function state(): array
    {
        return $this->only(['id', 'reference', 'delivery_id', 'hub_id', 'buyer_id', 'status', 'ready_at', 'expires_at', 'code_issued_at', 'consumed_at', 'failed_verifications', 'locked_until']);
    }
}
