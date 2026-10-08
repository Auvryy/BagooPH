<?php

namespace App\Services\Finance;

use DomainException;

final class CodMoney
{
    public const RULE = '/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/';

    public static function cents(mixed $amount): int
    {
        if (! is_string($amount) || ! preg_match(self::RULE, $amount)) {
            throw new DomainException('Use a peso amount with at most two decimal places.');
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
