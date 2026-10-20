<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MiddleInitial implements ValidationRule
{
    public static function canonical(mixed $value): mixed
    {
        if (! is_string($value) || preg_match('/\p{C}/u', $value)) {
            return $value;
        }
        $initial = trim(\Normalizer::normalize($value, \Normalizer::FORM_KC), ' ');

        if (! preg_match('/\A\p{Latin}{1,2}\.?\z/u', $initial)) {
            return $initial;
        }
        $letters = rtrim($initial, '.');
        $uppercase = mb_strtoupper($letters);

        return (mb_strlen($uppercase) <= 2 ? $uppercase : $letters).'.';
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\A\p{Latin}{1,2}\.?\z/u', $value)) {
            $fail('Enter one or two letters, such as M. or MD.');
        }
    }
}
