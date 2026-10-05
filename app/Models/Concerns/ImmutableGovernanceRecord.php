<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableGovernanceRecord
{
    public static function bootImmutableGovernanceRecord(): void
    {
        static::updating(fn () => throw new LogicException('Governance evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Governance evidence is immutable.'));
    }
}
