<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\Shop;
use App\Models\User;
use App\Services\OtpService;
use App\Services\VerificationDocumentService;
use Carbon\Carbon;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Display the dedicated seller registration view.
     */
    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerRegister');
    }

    /**
     * Display the dedicated courier registration view.
     */
    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierRegister');
    }

    /**
     * Display the dedicated logistics partner registration view.
     */
    public function createLogistics(): Response
    {
        return Inertia::render('Auth/LogisticsRegister');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $role = $request->input('role', 'buyer');

        // Merge composite name if first_name or last_name is provided without a full name
        if (! $request->filled('name') && ($request->filled('first_name') || $request->filled('last_name'))) {
            $compositeName = trim(
                ($request->input('first_name', '').' '.
                ($request->input('middle_name') ? $request->input('middle_name').' ' : '').
                $request->input('last_name', ''))
            );
            $request->merge(['name' => $compositeName]);
        }

        // Base validation rules
        $rules = [
            'name' => 'required|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'sex' => 'nullable|string|max:50',
            'birthday' => 'nullable|date',
            'age' => 'nullable|integer|min:0|max:150',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'nullable|string|in:buyer,seller,courier,logistics',
            'phone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'barangay' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];

        // Role-specific validation rules
        if ($role === 'seller') {
            $rules['shop_name'] = 'required|string|max:255';
            $rules['phone'] = 'required|string|max:255';
            $rules['address'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['id_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['business_permit'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        } elseif ($role === 'courier') {
            $rules['phone'] = 'required|string|max:255';
            $rules['address'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['vehicle_type'] = 'required|string|max:100';
            $rules['plate_number'] = 'required|string|max:50';
            $rules['license_number'] = 'nullable|string|max:50';
            $rules['id_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['driver_license'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
            $rules['or_cr_document'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
        } elseif ($role === 'logistics') {
            $rules['company_name'] = 'required|string|max:255';
            $rules['company_code'] = 'nullable|string|max:20';
            $rules['franchise_number'] = 'nullable|string|max:100';
            $rules['fleet_size'] = 'nullable|integer|min:1|max:100000';
            $rules['phone'] = 'required|string|max:255';
            $rules['address'] = 'required|string|max:255';
            $rules['city'] = 'required|string|max:255';
            $rules['business_permit'] = 'required|file|mimes:jpeg,png,jpg,pdf,webp|max:5120';
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

        // Auto-calculate age from birthday if needed
        $birthday = $validated['birthday'] ?? null;
        $age = $validated['age'] ?? null;
        if ($birthday && ! $age) {
            try {
                $age = Carbon::parse($birthday)->age;
            } catch (\Exception $e) {
                $age = null;
            }
        }

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
        $user = User::create([
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
        ]);

        // Create associated role profile
        if ($role === 'seller') {
            $shopName = $validated['shop_name'];
            Shop::create([
                'user_id' => $user->id,
                'name' => $shopName,
                'slug' => Str::slug($shopName.'-'.$user->id),
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? $validated['municipality'] ?? null,
                'status' => 'pending',
            ]);
        } elseif ($role === 'courier') {
            CourierProfile::create([
                'user_id' => $user->id,
                'vehicle_type' => $validated['vehicle_type'],
                'plate_number' => $validated['plate_number'],
                'license_number' => $validated['license_number'] ?? null,
                'or_cr_status' => 'Pending Verification',
                'is_available' => false,
            ]);
        } elseif ($role === 'logistics') {
            $companyName = $validated['company_name'];
            $baseCode = ! empty($validated['company_code'])
                ? Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $validated['company_code']))
                : Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $companyName), 0, 4));
            if (strlen($baseCode) < 2) {
                $baseCode = 'LOG';
            }
            $code = $baseCode;
            $counter = 1;
            while (LogisticsCompany::where('code', $code)->exists()) {
                $code = $baseCode.$counter;
                $counter++;
            }

            LogisticsCompany::create([
                'user_id' => $user->id,
                'name' => $companyName,
                'slug' => Str::slug($companyName.'-'.$user->id),
                'code' => $code,
                'contact_email' => $validated['email'],
                'contact_phone' => $validated['phone'] ?? null,
                'address' => trim(($validated['address'] ?? '').', '.($validated['city'] ?? '')),
                'status' => 'pending',
                'is_active' => false,
                'accreditation_details' => [
                    'franchise_number' => $validated['franchise_number'] ?? 'PENDING-LTFRB',
                    'fleet_size' => (int) ($validated['fleet_size'] ?? 10),
                    'franchise_document_path' => $franchisePath,
                    'business_permit_path' => $permitPath,
                    'operating_province' => $validated['province'] ?? $validated['city'] ?? 'Laguna',
                    'vehicle_types' => $request->input('vehicle_types', ['motorcycle', 'l300_van']),
                ],
            ]);
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

                return redirect()->route('buyer.index')->with('success', 'Registration successful! Welcome to BagooPH.');
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
        $user = $request->user();

        // Buyers are never held at the pending approval screen
        if ($user->isBuyer()) {
            return redirect()->route('buyer.index');
        }

        // If user is already active and approved, redirect to their role dashboard
        if ($user->canAccessPortal()) {
            return redirect()->route('dashboard');
        }

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
            'shop' => $user->shop,
            'courierProfile' => $user->courierProfile,
            'logisticsCompany' => $user->logisticsCompany,
        ]);
    }

    /**
     * Handle KYC document re-submission for rejected applicants.
     */
    public function resubmitKyc(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'id_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'business_permit' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'driver_license' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'or_cr_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
            'franchise_document' => 'nullable|file|mimes:jpeg,png,jpg,pdf,webp|max:5120',
        ];

        $validated = $request->validate($rules);

        $updates = [
            'kyc_status' => 'pending_approval',
            'status' => 'pending_approval',
            'kyc_feedback' => null,
            'kyc_submitted_at' => now(),
        ];

        $paths = app(VerificationDocumentService::class)->storeUploads($validated);
        $updates += array_intersect_key($paths, array_flip(['id_document_path', 'business_permit_path', 'driver_license_path', 'or_cr_path']));
        if ($user->isLogistics() && $user->logisticsCompany) {
            $accreditation = $user->logisticsCompany->accreditation_details ?? [];
            foreach (['business_permit_path', 'franchise_document_path'] as $field) {
                if (isset($paths[$field])) {
                    $accreditation[$field] = $paths[$field];
                }
            }
            $user->logisticsCompany->update(['accreditation_details' => $accreditation]);
        }

        $user->update($updates);

        return back()->with('success', 'Your verification documents have been resubmitted successfully.');
    }
}
