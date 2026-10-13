<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountMobile implements ValidationRule
{
    public function __construct(private ?int $ownerId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $mobile = PhilippineContact::canonical($value);
        if ($mobile !== null && User::where('phone', $mobile)
            ->when($this->ownerId !== null, fn ($query) => $query->where('id', '<>', $this->ownerId))->exists()) {
            $fail('This mobile number is already registered. Use a different number.');
        }
    }
}
