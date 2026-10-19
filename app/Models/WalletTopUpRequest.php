<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WalletTopUpRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['request_token_hash', 'fingerprint', 'review_token_hash', 'review_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $request) {
            if ($request->getOriginal('status') !== 'pending' || array_diff(array_keys($request->getDirty()),
                ['status', 'pending_buyer_id', 'reviewer_id', 'review_reason', 'reviewed_at', 'review_token_hash', 'review_fingerprint', 'wallet_transaction_id', 'updated_at'])) {
                throw new LogicException('The original wallet request and review are retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Wallet requests are retained.'));
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
