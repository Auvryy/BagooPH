<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountRegistrationService;
use App\Services\ApplicationValidationService;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\KycSubmissionService;
use App\Services\MasterCategoryService;
use App\Services\VerificationDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $result = app(AccountRegistrationService::class)->submit($request);
        $user = $result['user'];
        if (! $result['verification_email_sent']) {
            if (! $user->isBuyer()) {
                Auth::login($user);
            }

            return redirect($user->isBuyer() ? route('login') : '/pending-approval')->withErrors([
                'email' => 'Your account was created, but we could not send the verification email. Please sign in and request it again.',
            ]);
        }
        if ($user->isBuyer()) {
            if ($user->email_verified_at) {
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
