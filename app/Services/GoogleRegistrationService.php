<?php

namespace App\Services;

use App\Models\User;
use App\Rules\MiddleInitial;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleRegistrationService
{
    public function pending(Request $request): array
    {
        $pending = $request->session()->get('registration.google');
        if (! is_array($pending) || ($pending['expires_at'] ?? 0) <= now()->timestamp) {
            $request->session()->forget('registration.google');
            throw ValidationException::withMessages(['email' => 'Your Google registration expired. Start Google sign-in again.']);
        }

        return $pending;
    }

    public function register(Request $request): User
    {
        $pending = $this->pending($request);
        $policies = app(RegistrationPolicyService::class);
        $data = $request->validate($policies->rules() + ['registration_nonce' => ['required', 'string', 'max:64']], $policies->messages());
        if (! hash_equals($pending['nonce'], $data['registration_nonce'])) {
            throw ValidationException::withMessages(['registration_nonce' => 'This registration changed. Start Google sign-in again.']);
        }
        $applications = app(ApplicationValidationService::class);
        $request->validate(['phone' => ['bail', 'nullable', 'string', 'max:24']]);
        $values = $applications->registrationValues($request->only(['first_name', 'middle_initial', 'last_name', 'sex', 'birthday', 'phone', 'region', 'address', 'city', 'province', 'municipality', 'barangay', 'postal_code']) + ['email' => $pending['identity']['email']], 'buyer');
        $rules = $applications->rules('buyer');
        foreach (['first_name', 'last_name'] as $field) {
            $rules[$field] = array_map(fn ($rule) => $rule === 'nullable' ? 'required' : $rule, $rules[$field]);
        }
        $rules['middle_initial'] = ['bail', 'nullable', 'string', new MiddleInitial];
        $rules += app(RegistrationAddressService::class)->rules();
        unset($rules['name']);
        $validated = app(RegistrationAddressService::class)->resolve(Validator::make($values, $rules)->validate());
        $parts = ['first_name' => $validated['first_name'], 'middle_name' => $validated['middle_initial'] ?? null, 'last_name' => $validated['last_name']];
        $name = implode(' ', array_filter($parts, fn ($value) => $value !== null && $value !== ''));
        Validator::make(['name' => $name], ['name' => $applications->rules('buyer')['name']])->validate();
        $account = array_intersect_key($validated, array_flip(ApplicationValidationService::ACCOUNT_FIELDS)) + $parts + ['name' => $name];
        $account['age'] = app(BirthDateEligibility::class)->age($validated['birthday'] ?? null);
        try {
            $user = DB::transaction(function () use ($pending, $policies, $account) {
                $user = User::create($account + [
                    'google_id' => $pending['google_id'], 'avatar' => $pending['avatar'],
                    'role' => 'buyer', 'status' => 'active', 'kyc_status' => 'none',
                    'password' => Str::password(40), 'email_verified_at' => now(),
                ]);
                $policies->record($user, 'google');

                return $user;
            });
        } catch (QueryException $exception) {
            if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                throw $exception;
            }
            throw ValidationException::withMessages(['email' => 'An account already uses these details. Please sign in again.']);
        }
        $request->session()->forget('registration.google');

        return $user;
    }
}
