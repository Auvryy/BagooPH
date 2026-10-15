<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class AdvertisementEvidence extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Advertisement history is immutable.'));
        static::deleting(fn () => throw new LogicException('Advertisement history is immutable.'));
    }
}
