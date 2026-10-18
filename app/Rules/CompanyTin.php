<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CompanyTin implements ValidationRule
{
    public static function canonical(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value, ' ');
        if (! preg_match('/\A(?:[0-9]{12}|[0-9]{3}(?:-[0-9]{3}){3})\z/', $value)) {
            return null;
        }

        return str_replace('-', '', $value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::canonical($value) === null) {
            $fail('Enter a 12-digit TIN, including the branch code, such as 123-456-789-000.');
        }
    }
}
