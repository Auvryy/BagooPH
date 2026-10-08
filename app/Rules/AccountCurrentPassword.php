<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

class AccountCurrentPassword implements ValidationRule
{
    public function __construct(private User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Hash::check($value, $this->user->getAuthPassword())) {
            $fail('The current password is incorrect.');
        }
    }
}
