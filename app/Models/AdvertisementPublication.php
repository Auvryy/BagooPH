<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementPublication extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime',
        'paused_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'superseded_at' => 'immutable_datetime', 'claims_reviewed' => 'boolean'];

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AdvertisementRevision::class);
    }

    public function effectiveEnd(): CarbonImmutable
    {
        return collect([$this->ends_at, $this->ended_at, $this->superseded_at])->filter()->sort()->first();
    }
}
