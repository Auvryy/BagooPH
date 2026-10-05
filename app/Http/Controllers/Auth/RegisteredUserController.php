<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApplicationRegistrationService;
use App\Services\ApplicationValidationService;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\KycSubmissionService;
use App\Services\MasterCategoryService;
use App\Services\OtpService;
use App\Services\VerificationDocumentService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register', ['birthDateLimits' => app(BirthDateEligibility::class)->limits()]);
    }

    /**
     * Display the dedicated seller registration view.
     */
    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerRegister', [
            'birthDateLimits' => app(BirthDateEligibility::class)->limits(),
            'masterCategories' => app(MasterCategoryService::class)->choices(),
        ]);
    }

    /**
     * Display the dedicated courier registration view.
     */
    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierRegister', ['birthDateLimits' => app(BirthDateEligibility::class)->limits()]);
    }

    /**
     * Display the dedicated logistics partner registration view.
     */
    public function createLogistics(): Response
    {
        return Inertia::render('Auth/LogisticsRegister', ['birthDateLimits' => app(BirthDateEligibility::class)->limits()]);
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = $request->input('role', 'buyer');
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

        $paths = app(VerificationDocumentService::class)->storeUploads($validated);
        $idPath = $paths['id_document_path'] ?? null;
        $permitPath = $paths['business_permit_path'] ?? null;
        $licensePath = $paths['driver_license_path'] ?? null;
        $orCrPath = $paths['or_cr_path'] ?? null;
        $franchisePath = $paths['franchise_document_path'] ?? null;

        $isBuyer = ($role === 'buyer');

        $birthday = $validated['birthday'] ?? null;
        $age = $birthDates->age($birthday);

        // Verify and burn OTP token if provided
        $otpToken = $request->input('otp_token');
        $emailVerifiedAt = null;
        if ($otpToken) {
            $otpService = app(OtpService::class);
            if ($otpService->validateAndBurnToken($validated['email'], $otpToken, 'registration')) {
                $emailVerifiedAt = now();
            }
        }

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
            'password' => Hash::make($validated['password']),
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
            $user = app(ApplicationRegistrationService::class)->register($account + ['_franchise_path' => $franchisePath], $validated);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete(array_values($paths));
            if ($exception instanceof QueryException && in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                $detail = $exception->errorInfo[2] ?? '';
                if (str_contains($detail, 'users_email') || str_contains($detail, 'users.email')) {
                    throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
                }
                if (str_contains($detail, 'logistics_companies_code') || str_contains($detail, 'logistics_companies.code')) {
                    throw ValidationException::withMessages(['company_code' => 'This company code is already registered.']);
                }
            }
            throw $exception;
        }

        try {
            event(new Registered($user));
        } catch (\Throwable $exception) {
            Log::warning('Failed to dispatch registration verification mail.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
            if (! $isBuyer) {
                Auth::login($user);
            }

            return redirect($isBuyer ? route('login') : '/pending-approval')->withErrors([
                'email' => 'Your account was created, but we could not send the verification email. Please sign in and request it again.',
            ]);
        }

        if ($isBuyer) {
            if ($emailVerifiedAt) {
                Auth::login($user);

                return app(BuyerAccessService::class)->signInDestination($request)->with('success', 'Your account was created. Submit your identity application for review.');
            }

            return redirect()->route('login')->with('status', 'Registration successful! Please sign in to your new account.');
        }

        Auth::login($user);

        return redirect('/pending-approval');
    }

    /**
     * Display the KYC pending approval / holding view.
     */
    public function pendingApproval(Request $request): Response|RedirectResponse
    {
        $user = app(BuyerAccessService::class)->current($request->user());
        if ($user->isBuyer()) {
            $access = app(BuyerAccessService::class);
            abort_unless($access->canViewHolding($user), 403);
            if ($user->canAccessPortal()) {
                return redirect()->route('buyer.index');
            }

            return Inertia::render('Auth/BuyerApproval', [
                'user' => $user->only(['id', 'name', 'email', 'status', 'kyc_status', 'kyc_feedback', 'kyc_submitted_at', 'kyc_reviewed_at']),
                'application' => app(ApplicationValidationService::class)->form($user),
                'identityDocumentUrl' => $access->canManageApplication($user) ? app(VerificationDocumentService::class)->links($user)['id_document_path'] : null,
                'birthDateLimits' => app(BirthDateEligibility::class)->limits(),
                'canViewOrders' => $access->canAccessExistingOrders($user),
            ]);
        }

        // If user is already active and approved, redirect to their role dashboard
        if ($user->canAccessPortal()) {
            return redirect()->route('dashboard');
        }

        $shop = $user->isSeller() ? $user->shop()->orderBy('id')->with('rootCategory')->first() : $user->shop;
        $category = app(MasterCategoryService::class)->snapshot($shop?->root_category_id);
        $user->setRelation('shop', $shop);

        return Inertia::render('Auth/PendingApproval', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'phone' => $user->phone,
                'address' => $user->address,
                'city' => $user->city,
                'postal_code' => $user->postal_code,
                'status' => $user->status,
                'kyc_status' => $user->kyc_status,
                'kyc_feedback' => $user->kyc_feedback,
                'kyc_submitted_at' => $user->kyc_submitted_at ? $user->kyc_submitted_at->toIso8601String() : null,
                'kyc_reviewed_at' => $user->kyc_reviewed_at ? $user->kyc_reviewed_at->toIso8601String() : null,
                ...app(VerificationDocumentService::class)->links($user),
            ],
            'shop' => $shop,
            'application' => app(ApplicationValidationService::class)->form($user),
            'sellerCategory' => $user->isSeller() ? [
                'value' => $shop?->root_category_id,
                'name' => $category['name'] ?? null,
                'issue' => ($category['eligible'] ?? false) ? null : MasterCategoryService::ISSUE,
                'can_correct' => $shop !== null && in_array($user->kyc_status, ['pending_approval', 'rejected'], true),
                'choices' => app(MasterCategoryService::class)->choices(),
            ] : null,
            'courierProfile' => $user->courierProfile,
            'logisticsCompany' => $user->logisticsCompany,
            'birthDate' => [
                'value' => $user->birthday?->toDateString(),
                'needs_correction' => app(BirthDateEligibility::class)->requiresAdult($user->role)
                    && app(BirthDateEligibility::class)->issue($user->birthday, true) !== null,
                'limits' => app(BirthDateEligibility::class)->limits(),
            ],
        ]);
    }

    /**
     * Handle rejected evidence resubmission or an unreviewed application correction.
     */
    public function resubmitKyc(Request $request): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->current($request->user());
        if ($user->isBuyer()) {
            app(BuyerAccessService::class)->requireApplication($user);
        }

        $applications = app(ApplicationValidationService::class);
        $request->merge($applications->normalize($request->all(), $user->role));
        $rules = [];
        foreach ($applications->rules($user->role, $user) as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }
        foreach (['id_document', 'business_permit', 'driver_license', 'or_cr_document', 'franchise_document'] as $field) {
            $rules[$field] = 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        }
        foreach (['role', 'user_id', 'shop_id', 'company_id', 'logistics_company_id', 'hub_id', 'assigned_hub_id', 'vehicle_id'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        if (! $user->isSeller()) {
            $rules['root_category_id'] = ['prohibited'];
        }
        $validated = $request->validate($rules);

        app(KycSubmissionService::class)->submit($user, $validated);

        return back()->with('success', 'Your application details have been submitted for review.');
    }
}
