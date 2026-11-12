<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShipmentFinancialPlan extends Model
{
    use ImmutableGovernanceRecord;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token_hash', 'request_fingerprint'];

    protected $casts = ['snapshot' => 'array', 'proof_bytes' => 'integer', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $plan) {
            $plan->reference = (string) Str::uuid();
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentFinancialEvent::class)->orderBy('sequence');
    }
}
