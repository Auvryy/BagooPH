<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LogisticsPlacementRecord extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['logistics_company_id', 'actor_id', 'user_id', 'kind', 'before_state', 'after_state', 'recorded_at'];

    protected $hidden = ['before_state', 'after_state'];

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Logistics placement records are immutable.'));
        static::deleting(fn () => throw new LogicException('Logistics placement records are immutable.'));
    }
}
