<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class CodCustodyEntry extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'tender_cents' => 'integer', 'change_cents' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->reference = 'COD-'.strtoupper((string) Str::uuid());
            $record->actor_role = $record->actor_id ? User::whereKey($record->actor_id)->value('role') : null;
        });
        static::updating(fn () => throw new LogicException('Pickup and cash evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Pickup and cash evidence is immutable.'));
    }
}
