<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AsciiPositiveInteger implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $value)) {
            $fail('The :attribute must be a positive whole number using digits only.');
        }
    }
}
