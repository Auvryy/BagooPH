<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CustodyRecoveryGrant extends Model
{
    use ImmutableGovernanceRecord;

    protected $guarded = ['id', 'reference'];

    protected $hidden = ['request_token', 'request_fingerprint', 'restriction_snapshot'];

    protected $casts = ['source_state' => 'array', 'custody_snapshot' => 'array', 'restriction_snapshot' => 'array', 'expires_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $grant) {
            $grant->reference = 'RCG-'.strtoupper((string) Str::uuid());
            $actor = User::findOrFail($grant->authorized_by_id);
            $grant->actor_role = $actor->role;
            $grant->actor_name = $actor->name;
        });
    }
}
