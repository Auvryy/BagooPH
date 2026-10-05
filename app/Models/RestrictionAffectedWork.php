<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RestrictionAffectedWork extends Model
{
    protected $table = 'restriction_affected_work';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['restriction_decision_id', 'order_id', 'delivery_id', 'responsible_user_id', 'snapshot', 'recorded_at'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Restriction responsibility records are immutable.'));
        static::deleting(fn () => throw new LogicException('Restriction responsibility records are immutable.'));
    }
}
