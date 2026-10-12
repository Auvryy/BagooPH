<?php

namespace App\Services;

use App\Rules\MiddleInitial;
use App\Rules\RegistrationDocument;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Normalizer;

class AccountRegistrationService
{
    private const MANAGED_FIELDS = ['user_id', 'shop_id', 'company_id', 'logistics_company_id', 'hub_id', 'assigned_hub_id', 'vehicle_id'];

    public function preflight(Request $request): void
    {
        $selection = $request->validate([
            'role' => ['required', 'string', 'in:buyer,seller,courier,logistics'],
            'step' => ['nullable', 'integer', 'between:1,3'],
        ]);
        $role = $selection['role'];
        $separatedName = $this->prepare($request, $role, $selection['step'] ?? null);
        $rules = $this->rules($role, $separatedName, $request->input('vehicle_type'));
        if ($step = $selection['step'] ?? null) {
            $fields = $this->stepFields($role)[$step - 1] ?? null;
            if ($fields === null) {
                throw ValidationException::withMessages(['step' => 'Choose an available registration step.']);
            }
            $rules = array_filter($rules, fn ($field) => in_array(explode('.', $field)[0], [...$fields, ...self::MANAGED_FIELDS], true), ARRAY_FILTER_USE_KEY);
        }
        $request->validate($rules, $this->messages());
    }

    private function prepare(Request $request, string $role, ?int $step = null): bool
    {
        $phones = ['phone', 'shop_phone', 'company_phone'];
        if ($step !== null) {
            $phones = array_intersect($phones, $this->stepFields($role)[(int) $step - 1] ?? []);
        }
        $request->validate(array_fill_keys($phones, ['bail', 'nullable', 'string', 'max:24']));
        $hasInitial = $request->exists('middle_initial');
        $separatedName = $request->exists('first_name') || $request->exists('middle_name') || $request->exists('last_name') || $hasInitial;
        if ($separatedName) {
            $errors = [];
            foreach (['first_name', $hasInitial ? 'middle_initial' : 'middle_name', 'last_name'] as $field) {
                if ($request->input($field) !== null && ! is_string($request->input($field))) {
                    $errors[$field] = 'Enter a name using letters, spaces, apostrophes, hyphens or periods.';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        }

        if ($hasInitial) {
            $initial = $request->input('middle_initial');
            if (is_string($initial) && ! preg_match('/\p{C}/u', $initial)) {
                $initial = trim(Normalizer::normalize($initial, Normalizer::FORM_KC), ' ');
            }
            $request->merge(['middle_initial' => $initial, 'middle_name' => $initial]);
        }

        $applications = app(ApplicationValidationService::class);
        $request->merge($applications->registrationValues($request->all(), $role));
        if ($separatedName) {
            $parts = array_filter(array_map(fn ($field) => $request->input($field), ['first_name', 'middle_name', 'last_name']), fn ($value) => $value !== null && $value !== '');
            $request->merge(['name' => implode(' ', $parts)]);
        }

        return $separatedName;
    }

    private function rules(string $role, bool $separatedName = false, mixed $vehicleType = null): array
    {
        $rules = app(ApplicationValidationService::class)->rules($role) + app(RegistrationPolicyService::class)->rules() + [
            'password' => ['bail', 'required', 'string', 'confirmed', Rules\Password::defaults()],
            'otp_token' => ['nullable', 'string', 'max:128'],
            'role' => 'nullable|string|in:buyer,seller,courier,logistics',
            'id_document' => ['bail', 'nullable', 'file', new RegistrationDocument],
            'middle_initial' => ['bail', 'nullable', 'string', new MiddleInitial],
        ];
        if ($separatedName) {
            foreach (['first_name', 'last_name'] as $field) {
                $rules[$field] = array_map(fn ($rule) => $rule === 'nullable' ? 'required' : $rule, $rules[$field]);
            }
        }
        foreach (self::MANAGED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }
        $files = match ($role) {
            'seller' => ['id_document', 'business_permit'],
            'courier' => ['id_document', 'driver_license', 'or_cr_document'],
            'logistics' => ['business_permit'],
            default => [],
        };
        foreach ($files as $field) {
            $rules[$field] = ['bail', 'required', 'file', new RegistrationDocument];
        }
        if ($role === 'logistics') {
            $rules['franchise_document'] = ['bail', 'nullable', 'file', new RegistrationDocument];
        }

        return array_replace($rules, app(ApplicationValidationService::class)->submissionFieldRules($role, $vehicleType));
    }

    private function messages(): array
    {
        return app(RegistrationPolicyService::class)->messages() + app(ApplicationValidationService::class)->submissionMessages();
    }

    private function stepFields(string $role): array
    {
        $location = ['address', 'city', 'province', 'municipality', 'barangay', 'postal_code'];
        $password = ['password', 'password_confirmation'];
        $name = ['name', 'first_name', 'middle_name', 'middle_initial', 'last_name', 'sex'];
        $policies = ['policy_accepted', 'terms_version', 'privacy_version'];

        return match ($role) {
            'buyer' => [
                [...$name, 'email', ...$password],
                ['birthday', 'phone', ...$location, 'id_document', ...$policies],
            ],
            'seller' => [
                [...$name, 'birthday', 'shop_name', 'root_category_id', 'email'],
                ['phone', ...$location, 'shop_phone', 'shop_address', 'shop_city'],
                [...$password, 'id_document', 'business_permit', ...$policies],
            ],
            'courier' => [
                [...$name, 'birthday', 'email', 'phone', ...$location],
                ['vehicle_type', 'plate_number', 'license_number'],
                [...$password, 'id_document', 'driver_license', 'or_cr_document', ...$policies],
            ],
            'logistics' => [
                [...$name, 'birthday', 'company_name', 'company_code', 'email', 'company_email', 'phone', 'company_phone', ...$password],
                ['operating_province', 'franchise_number', 'fleet_size', 'vehicle_types'],
                [...$location, 'company_address', 'business_permit', 'franchise_document', ...$policies],
            ],
        };
    }

    public function submit(Request $request, ?string $fixedRole = null, bool $requireVerifiedEmail = false): array
    {
        $role = $fixedRole ?? $request->input('role', 'buyer');
        if (! is_string($role)) {
            throw ValidationException::withMessages(['role' => 'Choose a supported account role.']);
        }
        $birthDates = app(BirthDateEligibility::class);
        $separatedName = $this->prepare($request, $role);
        $validated = $request->validate($this->rules($role, $separatedName, $request->input('vehicle_type')), $this->messages());

        if ($requireVerifiedEmail && ! app(OtpService::class)->hasValidToken($validated['email'], (string) $request->input('otp_token'), 'registration')) {
            throw ValidationException::withMessages(['otp_token' => 'Verify your email before submitting your rider application.']);
        }

        $paths = app(VerificationDocumentService::class)->storeUploads($validated);
        $idPath = $paths['id_document_path'] ?? null;
        $permitPath = $paths['business_permit_path'] ?? null;
        $licensePath = $paths['driver_license_path'] ?? null;
        $orCrPath = $paths['or_cr_path'] ?? null;
        $franchisePath = $paths['franchise_document_path'] ?? null;

        $isBuyer = ($role === 'buyer');

        $birthday = $validated['birthday'] ?? null;
        $age = $birthDates->age($birthday);

        $emailVerifiedAt = null;

        // Create User with appropriate role status
        $account = [
            'name' => $validated['name'],
            'email_verified_at' => $emailVerifiedAt,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'middle_name' => $validated['middle_name'] ?? null,
            'sex' => $validated['sex'] ?? null,
            'birthday' => $birthday,
            'age' => $age,
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $role,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? $validated['municipality'] ?? null,
            'province' => $validated['province'] ?? null,
            'municipality' => $validated['municipality'] ?? null,
            'barangay' => $validated['barangay'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'status' => $isBuyer ? 'active' : 'pending_approval',
            'kyc_status' => $isBuyer ? ($idPath ? 'pending_approval' : 'none') : 'pending_approval',
            'id_document_path' => $idPath,
            'business_permit_path' => $permitPath,
            'driver_license_path' => $licensePath,
            'or_cr_path' => $orCrPath,
            'kyc_submitted_at' => $idPath ? now() : ($isBuyer ? null : now()),
        ];

        try {
            $user = DB::transaction(function () use ($request, $requireVerifiedEmail, $account, $validated, $franchisePath) {
                $token = $request->input('otp_token');
                $verified = $token && app(OtpService::class)->validateAndBurnToken($validated['email'], $token, 'registration');
                if ($requireVerifiedEmail && ! $verified) {
                    throw ValidationException::withMessages(['otp_token' => 'Your verification session expired. Verify your email again.']);
                }
                $account['email_verified_at'] = $verified ? now() : null;

                $user = app(ApplicationRegistrationService::class)->register($account + ['_franchise_path' => $franchisePath], $validated);
                app(RegistrationPolicyService::class)->record($user, $requireVerifiedEmail ? 'native' : 'web');

                return $user;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete(array_values($paths));
            if ($exception instanceof QueryException && in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                $detail = $exception->errorInfo[2] ?? '';
                if (str_contains($detail, 'users_email') || str_contains($detail, 'users.email') || str_contains($detail, 'account_emails_email') || str_contains($detail, 'account_emails.email')) {
                    throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
                }
                if (str_contains($detail, 'logistics_companies_code') || str_contains($detail, 'logistics_companies.code')) {
                    throw ValidationException::withMessages(['company_code' => 'This company code is already registered.']);
                }
            }
            throw $exception;
        }

        $emailSent = true;
        try {
            event(new Registered($user));
        } catch (\Throwable $exception) {
            $emailSent = false;
            Log::warning('Failed to dispatch registration verification mail.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }

        return ['user' => $user, 'verification_email_sent' => $emailSent];
    }
}
