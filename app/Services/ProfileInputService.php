<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class ProfileInputService
{
    public function __construct(private ApplicationValidationService $applications) {}

    public function normalize(array $values, User $user): array
    {
        return $this->applications->normalize(array_intersect_key($values, array_flip(['name', 'email', 'phone'])), $user->role);
    }

    public function rules(User $user, array $fields): array
    {
        $rules = array_intersect_key($this->applications->rules($user->role, $user), array_flip($fields));
        // Updating contact details does not require a new registration application.
        if (isset($rules['phone'])) {
            $rules['phone'] = array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $rules['phone']);
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
