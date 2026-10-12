<?php

namespace App\Rules;

use App\Models\AccountEmail;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private ?int $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::taken($value, $this->ignore)) {
            $fail('This email address is already registered.');
        }
    }

    public static function taken(string $email, ?int $ignore = null): bool
    {
        return AccountEmail::where('email', strtolower(trim($email)))->when($ignore, fn ($query) => $query->where('user_id', '<>', $ignore))->exists()
            || User::whereRaw('LOWER(email) = LOWER(?)', [trim($email)])->when($ignore, fn ($query) => $query->where('id', '<>', $ignore))->exists();
    }
}
