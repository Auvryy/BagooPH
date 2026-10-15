<?php

namespace App\Services\Logistics;

use App\Rules\ApplicationText;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Normalizer;

trait CompanyRequestInput
{
    protected function validate(array $input, array $rules, array $text = []): array
    {
        $unexpected = array_diff_key($input, $rules);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys(array_keys($unexpected), 'This field cannot be set through company requests.'));
        }
        foreach ($text as $field => $maximum) {
            if (! array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            Validator::make([$field => $input[$field]], [$field => ['bail', 'string', 'max:'.$maximum]])->validate();
            $value = $input[$field];
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$field => 'Use readable plain text.']);
            }
            if (preg_match('/\A[ \r\n]*\z/', $value)) {
                $input[$field] = null;

                continue;
            }
            Validator::make([$field => $value], [$field => [new ApplicationText('notes', 1, $maximum)]])->validate();
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            if ($normalized === false) {
                throw ValidationException::withMessages([$field => 'Use readable plain text.']);
            }
            $normalized = trim($normalized, " \r\n");
            $input[$field] = $normalized === '' ? null : $normalized;
        }

        return Validator::make($input, $rules)->validate();
    }

    protected function number(bool $zero = false): \Closure
    {
        return function ($attribute, $value, $fail) use ($zero) {
            if ((! is_string($value) && ! is_int($value)) || ! preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/', (string) $value)
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $zero ? 0 : 1, 'max_range' => PHP_INT_MAX]]) === false) {
                $fail('Use a valid whole number for the :attribute.');
            }
        };
    }

    protected function token(): array
    {
        return ['bail', 'required', 'string', 'uuid', 'regex:/\A[a-f0-9-]{36}\z/'];
    }

    protected function fingerprint(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
