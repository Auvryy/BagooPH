<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class CodCashEvent extends Model
{
    protected $guarded = ['id', 'reference', 'actor_role'];

    protected $hidden = ['request_token', 'request_fingerprint', 'private_evidence', 'source_state', 'target_state'];

    protected $casts = ['sequence' => 'integer', 'amount_cents' => 'integer', 'expected_cents' => 'integer',
        'received_cents' => 'integer', 'discrepancy_cents' => 'integer', 'private_evidence' => 'array', 'source_state' => 'array', 'target_state' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->reference ??= 'COD-'.strtoupper((string) Str::uuid());
            $event->actor_role = User::whereKey($event->actor_id)->value('role');
        });
        static::updating(fn () => throw new LogicException('Cash events are immutable.'));
        static::deleting(fn () => throw new LogicException('Cash events are immutable.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CodAccount::class, 'cod_account_id');
    }
}
