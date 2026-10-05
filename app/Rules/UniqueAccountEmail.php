<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private ?int $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (User::whereRaw('LOWER(email) = LOWER(?)', [$value])->when($this->ignore, fn ($query) => $query->where('id', '<>', $this->ignore))->exists()) {
            $fail('This email address is already registered.');
        }
    }
}
