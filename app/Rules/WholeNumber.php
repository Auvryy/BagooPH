<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class WholeNumber implements ValidationRule
{
    public function __construct(private int $maximum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/\A[1-9][0-9]*\z/', (string) $value) || (float) $value > $this->maximum) {
            $fail("Use a whole number from 1 to {$this->maximum} for the :attribute.");
        }
    }
}
