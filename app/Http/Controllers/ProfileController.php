<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Buyer\BuyerProfileController;
use App\Http\Controllers\Courier\CourierDeliveryController;
use App\Http\Controllers\Seller\SellerDashboardController;
use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AccountClosureService;
use App\Services\AccountSettingsService;
use App\Services\BuyerAccessService;
use App\Services\IdentityCorrectionService;
use App\Services\ProfileInputService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProfileController extends Controller
{
    public function settings(Request $request): Response|RedirectResponse
    {
        $user = $request->user()->fresh();
        abort_unless($user && $user->closed_at === null, 403);
        $request->setUserResolver(fn () => $user);
        if ($user->canAccessPortal()) {
            if ($user->isBuyer()) {
                $request->query->set('tab', 'account');

                return app(BuyerProfileController::class)->index($request);
            }
            if ($user->isSeller()) {
                return app(SellerDashboardController::class)->profile($request);
            }
            if ($user->isCourier()) {
                return app(CourierDeliveryController::class)->profile($request);
            }

            return $this->edit($request);
        }
        abort_unless($user->isKycApproved(), 403);

        return Inertia::render('Profile/Edit', [
            ...app(AccountSettingsService::class)->presentation($user),
            'settingsOnly' => true, 'mustVerifyEmail' => false, 'closure' => null,
        ]);
    }

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        $user = $request->user()->fresh();
        abort_unless($user?->canAccessPortal(), 403);
        $request->setUserResolver(fn () => $user);
        if ($request->user()->isBuyer()) {
            app(BuyerAccessService::class)->requirePortal($request->user());
        }
        if ($request->user()->isCourier()) {
            return Redirect::route('courier.profile');
        }

        return Inertia::render('Profile/Edit', [
            ...app(AccountSettingsService::class)->presentation($user),
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            'closure' => app(AccountClosureService::class)->presentation($request->user(), $request->user()->id, self: true),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        return app(IdentityCorrectionService::class)->mutateProfile($request, function () use ($request) {
            if ($request->user()->isBuyer()) {
                app(BuyerAccessService::class)->requirePortal($request->user());
            }
            $validated = app(ProfileInputService::class)->validate($request, ['name', 'email', 'phone']);
            app(IdentityCorrectionService::class)->protectReviewedIdentity($request->user(), $validated);
            $request->user()->fill($validated);

            $request->user()->save();

            return back(fallback: route('profile.edit'))->with('success', 'Contact details updated.');
        });
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user()->isBuyer()) {
            app(BuyerAccessService::class)->requirePortal($request->user());
        }
        try {
            app(AccountClosureService::class)->close($request->user(), $request->user()->id, $request->all(), self: true);
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }
            throw ValidationException::withMessages(['source_token' => $error->getMessage()]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
