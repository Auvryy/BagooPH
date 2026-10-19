<?php

namespace App\Models\Concerns;

use LogicException;

trait RetainsBuyerReturnEvidence
{
    protected static function bootRetainsBuyerReturnEvidence(): void
    {
        static::updating(fn () => throw new LogicException('Original return evidence is retained unchanged.'));
        static::deleting(fn () => throw new LogicException('Original return evidence is retained unchanged.'));
    }
}
