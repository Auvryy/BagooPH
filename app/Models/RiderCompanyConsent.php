<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RiderCompanyConsent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['request_token', 'fingerprint', 'source_hash'];

    protected function casts(): array
    {
        return ['acknowledged' => 'boolean', 'accepted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rider company consent is immutable.'));
        static::deleting(fn () => throw new LogicException('Rider company consent is immutable.'));
    }
}
