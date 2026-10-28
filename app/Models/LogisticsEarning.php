<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class LogisticsEarning extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'net_amount_cents' => 'integer', 'snapshot' => 'array', 'earned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['released_at', 'reversed_at', 'updated_at'])) {
                throw new LogicException('Original earnings amounts and sources are retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Earnings evidence is retained.'));
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
