<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountIdentityService
{
    public function selectedRole(Request $request): ?string
    {
        $values = $request->validate(['role' => ['nullable', 'string', Rule::enum(UserRole::class)]]);
        $selected = $values['role'] ?? null;
        $allowed = $this->portalRoles($request);
        if ($selected !== null && $allowed && ! in_array($selected, $allowed, true)) {
            throw ValidationException::withMessages(['email' => 'Open the sign-in page for your account type, then try again.']);
        }

        return $selected ?? (count($allowed) === 1 ? $allowed[0] : null);
    }

    public function portalRoles(Request $request): array
    {
        if ($request->is('custody-recovery/sign-in', 'cash-handover/sign-in')) {
            return ['courier', 'logistics'];
        }
        $host = $request->getHost();
        foreach (['seller' => ['seller'], 'courier' => ['courier'], 'hub' => ['logistics', 'admin'], 'admin' => ['admin']] as $prefix => $roles) {
            if (str_starts_with($host, $prefix.'.')) {
                return $roles;
            }
        }
        $domain = env('APP_DOMAIN', 'bagooph.shop');

        return in_array($host, [$domain, 'www.'.$domain], true) ? ['buyer'] : [];
    }

    public function scope(Builder $query, Request $request): Builder
    {
        $role = $this->selectedRole($request);

        return $query->when($role !== null, fn ($query) => $query->where('role', $role))
            ->when($this->portalRoles($request) !== [], fn ($query) => $query->whereIn('role', $this->portalRoles($request)));
    }

    public function signInAccount(Request $request, string $email): ?User
    {
        $matches = $this->scope(User::query(), $request)
            ->whereRaw('LOWER(TRIM(email)) = ?', [strtolower(trim($email))])->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['email' => 'Choose the account type you want to sign in to.']);
        }

        return $matches->first();
    }

    public static function label(string $role): string
    {
        return match ($role) {
            'courier' => 'rider',
            'logistics' => 'logistics',
            'admin' => 'admin',
            default => $role,
        };
    }

    public static function duplicateMessage(string $role): string
    {
        return 'This email is already registered for a '.self::label($role).' account. Sign in instead.';
    }
}
