<?php

namespace App\Models\Concerns;

use LogicException;

trait RetainsWalletEvidence
{
    protected static function bootRetainsWalletEvidence(): void
    {
        static::updating(fn () => throw new LogicException('Wallet history is retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Wallet history is retained unchanged.'));
    }
}
