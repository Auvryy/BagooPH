<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class AccountSessionAction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['request_token_hash', 'request_fingerprint'];

    protected function casts(): array
    {
        return ['affected_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Retain the original session sign-out result.'));
        static::deleting(fn () => throw new LogicException('Retain session sign-out replay protection.'));
    }
}
