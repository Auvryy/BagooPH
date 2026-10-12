<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MiddleInitial implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A\p{Latin}\.?\z/u', $value)) {
            $fail('Enter one letter, with an optional period, such as M or M.');
        }
    }
}
