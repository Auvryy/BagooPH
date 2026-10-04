<?php

namespace App\Rules;

use App\Services\BirthDateEligibility;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BirthDate implements ValidationRule
{
    public function __construct(private bool $adult = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $issue = app(BirthDateEligibility::class)->issue($value, $this->adult);
        if ($issue) {
            $fail($issue);
        }
    }
}
