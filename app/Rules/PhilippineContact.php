<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippineContact implements ValidationRule
{
    public function __construct(private bool $landline = false) {}

    public static function canonical(mixed $value, bool $landline = false): ?string
    {
        if (! is_string($value) || ! preg_match('/\A\+?[0-9 ()-]+\z/', $value)) {
            return null;
        }
        $digits = str_replace([' ', '(', ')', '-'], '', $value);
        if (str_starts_with($digits, '0')) {
            $digits = '+63'.substr($digits, 1);
        } elseif (str_starts_with($digits, '63')) {
            $digits = '+'.$digits;
        }
        if (preg_match('/\A\+639[0-9]{9}\z/', $digits)) {
            return $digits;
        }
        // Geographic contacts require a full area code; service/premium/extension numbers are excluded.
        if ($landline && preg_match('/\A\+63(?:2[0-9]{8}|(?:3[2-68]|4[2-9]|5[2-6]|6[2-58]|7[24578]|8[2-8])[0-9]{7})\z/', $digits)) {
            return $digits;
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::canonical($value, $this->landline) === null) {
            $fail($this->landline ? 'The :attribute must be a Philippine mobile or landline number with its area code.' : 'The :attribute must be a Philippine mobile number, such as 09171234567 or +639171234567.');
        }
    }
}
