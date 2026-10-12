<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippineLicenseNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A(?:[A-Z][0-9]{2}-[0-9]{2}-[0-9]{6}|[A-Z][0-9]{10})\z/', $value)) {
            $fail('Use the licence number on your card, such as N01-12-345678. The same 11 letters and digits without hyphens are also accepted.');
        }
    }

    public static function canonical(string $value): string
    {
        return preg_match('/\A[A-Z][0-9]{10}\z/', $value)
            ? substr($value, 0, 3).'-'.substr($value, 3, 2).'-'.substr($value, 5)
            : $value;
    }
}
