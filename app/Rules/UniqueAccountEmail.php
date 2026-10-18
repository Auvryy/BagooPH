<?php

namespace App\Rules;

use App\Models\AccountEmail;
use App\Models\User;
use App\Services\AccountIdentityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueAccountEmail implements ValidationRule
{
    public function __construct(private ?int $ignore = null, private string $role = 'buyer') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::taken($value, $this->ignore, $this->role)) {
            $fail(AccountIdentityService::duplicateMessage($this->role));
        }
    }

    public static function taken(string $email, ?int $ignore = null, string $role = 'buyer'): bool
    {
        return AccountEmail::where('role', $role)->where('email', strtolower(trim($email)))->when($ignore, fn ($query) => $query->where('user_id', '<>', $ignore))->exists()
            || User::where('role', $role)->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->when($ignore, fn ($query) => $query->where('id', '<>', $ignore))->exists();
    }
}
