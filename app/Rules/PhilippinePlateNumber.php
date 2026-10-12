<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PhilippinePlateNumber implements ValidationRule
{
    public function __construct(private mixed $vehicleType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 8 || ! preg_match('/\A[A-Z0-9]+(?:[- ][A-Z0-9]+)?\z/', $value)) {
            $fail('Use your registered plate, with at most 7 letters and digits and one optional space or hyphen.');

            return;
        }
        $plate = str_replace([' ', '-'], '', $value);
        $pattern = $this->vehicleType === 'Sedan / Van'
            ? '/\A[A-Z]{3}[0-9]{2,4}\z/'
            : '/\A(?:[A-Z]{2}[0-9]{4,5}|[0-9]{3,4}[A-Z]{2}|(?=[A-Z0-9]{6}\z)(?=.*[A-Z])(?=.*[0-9])[A-Z0-9]{6})\z/';
        if (! preg_match($pattern, $plate)) {
            $fail('Enter a Philippine plate format for your selected vehicle. Use the plate shown on your registration document.');
        }
    }
}
