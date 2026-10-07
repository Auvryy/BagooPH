<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustodyRecoveryReceipt extends Model
{
    use ImmutableGovernanceRecord;

    protected $guarded = ['id', 'reference'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'target_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->reference = 'RCR-'.strtoupper((string) Str::uuid());
            $actor = User::findOrFail($receipt->actor_id);
            $receipt->actor_role = $actor->role;
            $receipt->actor_name = $actor->name;
        });
    }
}
