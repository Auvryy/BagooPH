<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WalletAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['balance_cents' => 'integer'];

    protected static function booted(): void
    {
        static::updating(function (self $account) {
            if (array_diff(array_keys($account->getDirty()), ['balance_cents', 'updated_at'])) {
                throw new LogicException('Wallet ownership and account purpose cannot change.');
            }
        });
        static::deleting(fn () => throw new LogicException('Wallet accounts retain their original history.'));
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }
}
