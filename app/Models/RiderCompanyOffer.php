<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RiderCompanyOffer extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected $hidden = ['sources', 'source_hash'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'sources' => 'array', 'display' => 'array', 'issued_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rider company offers are immutable.'));
        static::deleting(fn () => throw new LogicException('Rider company offers are immutable.'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(RiderCompanyRequest::class, 'rider_company_request_id');
    }
}
