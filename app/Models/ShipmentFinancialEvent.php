<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ShipmentFinancialEvent extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token_hash', 'request_fingerprint'];

    protected $casts = ['context' => 'array', 'amount_cents' => 'integer', 'sequence' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference = (string) Str::uuid();
        });
    }
}
