<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ApplicationText implements ValidationRule
{
    public function __construct(private string $kind, private int $minimum, private int $maximum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < $this->minimum || mb_strlen($value) > $this->maximum) {
            $fail("The :attribute must contain {$this->minimum} to {$this->maximum} characters.");

            return;
        }
        $pattern = match ($this->kind) {
            'name' => "/\A[\p{Latin}\p{M} .’'\-]+\z/u",
            'code' => '/\A[A-Z0-9]+(?:-[A-Z0-9]+)*\z/',
            'bin' => '/\A[A-Za-z0-9][A-Za-z0-9 :._\/-]*\z/',
            'notes' => '/\A(?:[^\p{C}<>`{}]|\r?\n)*\z/u',
            default => "/\A[\p{Latin}\p{M}\p{N} #.,’'\-\/()&:]+\z/u",
        };
        $unsafe = preg_match('/[\x{034f}\x{fe00}-\x{fe0f}]|(?:javascript|vbscript|data)\s*:|\bon[a-z]+\s*=/iu', $value);
        if ($unsafe || ! preg_match($pattern, $value) || ! preg_match($this->kind === 'name' ? '/\p{Latin}/u' : '/[\p{L}\p{N}]/u', $value)) {
            $fail('The :attribute must contain meaningful plain text in the permitted format, without hidden characters or markup.');
        }
    }
}
