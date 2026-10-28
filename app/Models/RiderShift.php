<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RiderShift extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cash_event_floor' => 'integer', 'started_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'closing_snapshot' => 'array'];
    }
}
