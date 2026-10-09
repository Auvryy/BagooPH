<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SellerSettlementEvent extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['proof_path', 'proof_hash', 'request_token', 'request_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'sequence' => 'integer', 'context' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Settlement events are immutable.'));
        static::deleting(fn () => throw new LogicException('Settlement events are immutable.'));
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SellerSettlement::class, 'seller_settlement_id');
    }
}
