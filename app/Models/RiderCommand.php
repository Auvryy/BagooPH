<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RiderCommand extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['result' => 'array', 'recorded_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rider command results are retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Rider command results are retained unchanged.'));
    }
}
