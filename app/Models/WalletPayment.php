<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WalletPayment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['source_hash'];

    protected $casts = ['amount_cents' => 'integer', 'expires_at' => 'datetime', 'paid_at' => 'datetime', 'closed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            if (array_diff(array_keys($payment->getDirty()), ['status', 'wallet_transaction_id', 'settlement_transaction_id', 'paid_at', 'closed_at', 'updated_at'])) {
                throw new LogicException('The original wallet payment amount and source are retained.');
            }
        });
        static::deleting(fn () => throw new LogicException('Wallet payment history is retained.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(WalletPaymentAttempt::class);
    }
}
