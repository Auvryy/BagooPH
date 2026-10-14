<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RiderCompanyIntakeEvent extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['request_token', 'fingerprint'];

    protected function casts(): array
    {
        return ['before_enabled' => 'boolean', 'after_enabled' => 'boolean', 'before_version' => 'integer', 'after_version' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rider company history is immutable.'));
        static::deleting(fn () => throw new LogicException('Rider company history is immutable.'));
    }
}
