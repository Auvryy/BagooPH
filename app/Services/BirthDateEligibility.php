<?php

namespace App\Services;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class BirthDateEligibility
{
    public const ADULT_AGE = 18;

    public function requiresAdult(mixed $role): bool
    {
        return in_array($role, [UserRole::SELLER->value, UserRole::COURIER->value, UserRole::LOGISTICS->value, UserRole::ADMIN->value], true);
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Manila')->startOfDay();
    }

    public function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        if (! is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Manila');
    }

    public function age(mixed $birthday): ?int
    {
        $date = $this->parse($birthday);
        $today = $this->today();

        return $date && $date->lessThan($today) ? $date->diff($today)->y : null;
    }

    public function issue(mixed $birthday, bool $adult = false): ?string
    {
        if (! $this->parse($birthday)) {
            return 'Enter a real birth date in YYYY-MM-DD format.';
        }
        $age = $this->age($birthday);
        if ($age === null) {
            return 'Birth date must be before today.';
        }
        if ($adult && $age < self::ADULT_AGE) {
            return 'You must be at least 18 years old for this account role.';
        }

        return null;
    }

    public function limits(): array
    {
        $today = $this->today();

        return [
            'today' => $today->toDateString(),
            'past_maximum' => $today->subDay()->toDateString(),
            'adult_maximum' => $today->subYearsNoOverflow(self::ADULT_AGE)->toDateString(),
        ];
    }
}
