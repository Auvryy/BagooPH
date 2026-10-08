<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class ProfileInputService
{
    public function __construct(private ApplicationValidationService $applications) {}

    public function normalize(array $values, User $user): array
    {
        $normalized = $this->applications->normalize(array_intersect_key($values, array_flip(['name', 'email', 'phone'])), $user->role);
        if (isset($normalized['email']) && is_string($normalized['email']) && strcasecmp($normalized['email'], $user->email) === 0) {
            $normalized['email'] = $user->email;
        }

        return $normalized;
    }

    public function rules(User $user, array $fields): array
    {
        $rules = array_intersect_key($this->applications->rules($user->role, $user), array_flip($fields));
        // Updating contact details does not require a new registration application.
        if (isset($rules['phone'])) {
            $rules['phone'] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $rules['phone']);
        }
        if (isset($rules['email'])) {
            $rules['email'][] = function ($attribute, $value, $fail) use ($user) {
                if (strcasecmp($value, $user->email) !== 0) {
                    $fail('Keep your original sign-in email. Add and verify another address in account settings.');
                }
            };
        }

        return $rules;
    }

    public function validate(Request $request, array $fields, array $additional = []): array
    {
        $user = $request->user();
        abort_unless($user?->canAccessPortal(), 403, 'An eligible account is required to update its profile.');
        $request->merge($this->normalize($request->all(), $user));

        return $request->validate($this->rules($user, $fields) + $additional);
    }
}
