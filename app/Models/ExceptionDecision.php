<?php

namespace App\Models;

use App\Models\Concerns\ImmutableGovernanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExceptionDecision extends Model
{
    use ImmutableGovernanceRecord;

    protected $guarded = ['id', 'reference', 'actor_role', 'actor_name', 'responsible_name'];

    protected $hidden = ['request_token', 'request_fingerprint'];

    protected $casts = ['source_state' => 'array', 'supporting_evidence' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $record) {
            $record->reference = 'EXD-'.strtoupper((string) Str::uuid());
            $actor = User::findOrFail($record->actor_id);
            $record->actor_role = $actor->role;
            $record->actor_name = $actor->name;
            $record->responsible_name = $record->responsible_user_id ? User::findOrFail($record->responsible_user_id)->name : null;
        });
    }
}
