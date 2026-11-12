<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class WalletRefundCreditRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['active_key', 'request_token_hash', 'request_fingerprint', 'review_token_hash', 'review_fingerprint'];

    protected $casts = ['amount_cents' => 'integer', 'version' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->reference = (string) Str::uuid());
        static::updating(function (self $request) {
            $mutable = ['status', 'version', 'active_key', 'reviewer_id', 'review_reason', 'reviewed_at', 'wallet_transaction_id', 'review_token_hash', 'review_fingerprint', 'updated_at'];
            if ($request->getRawOriginal('status') !== 'pending' || array_diff(array_keys($request->getDirty()), $mutable)) {
                throw new LogicException('Retain the original demonstration funding request and final review.');
            }
        });
        static::deleting(fn () => throw new LogicException('Retain original demonstration funding requests.'));
    }
}
