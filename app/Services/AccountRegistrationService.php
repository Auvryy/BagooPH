<?php

namespace App\Services;

use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class AccountRegistrationService
{
    public function submit(Request $request, ?string $fixedRole = null, bool $requireVerifiedEmail = false): array
    {
        $role = $fixedRole ?? $request->input('role', 'buyer');
        if (! is_string($role)) {
            throw ValidationException::withMessages(['role' => 'Choose a supported account role.']);
        }
        $birthDates = app(BirthDateEligibility::class);

        // Merge composite name if first_name or last_name is provided without a full name
        if (! $request->filled('name') && ($request->filled('first_name') || $request->filled('last_name'))) {
            foreach (['first_name', 'middle_name', 'last_name'] as $field) {
                if ($request->filled($field) && ! is_string($request->input($field))) {
                    throw ValidationException::withMessages([$field => 'Enter a valid name.']);
                }
            }
            $compositeName = trim(
                ($request->input('first_name', '').' '.
                ($request->input('middle_name') ? $request->input('middle_name').' ' : '').
                $request->input('last_name', ''))
            );
            $request->merge(['name' => $compositeName]);
        }

        $applications = app(ApplicationValidationService::class);
        $request->merge($applications->registrationValues($request->all(), $role));
        $rules = $applications->rules($role) + [
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'otp_token' => ['nullable', 'string', 'max:128'],
            'role' => 'nullable|string|in:buyer,seller,courier,logistics',
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];
        foreach (['user_id', 'shop_id', 'company_id', 'logistics_company_id', 'hub_id', 'assigned_hub_id', 'vehicle_id'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $files = match ($role) {
            'seller' => ['id_document', 'business_permit'],
            'courier' => ['id_document', 'driver_license', 'or_cr_document'],
            'logistics' => ['business_permit'],
            default => [],
        };
        foreach ($files as $field) {
            $rules[$field] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        }
        if ($role === 'logistics') {
            $rules['franchise_document'] = 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        }
        $validated = $request->validate($rules);

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

                return app(ApplicationRegistrationService::class)->register($account + ['_franchise_path' => $franchisePath], $validated);
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
