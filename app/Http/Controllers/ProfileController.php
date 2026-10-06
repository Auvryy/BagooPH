<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\AccountClosureService;
use App\Services\BuyerAccessService;
use App\Services\IdentityCorrectionService;
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
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        if ($request->user()->isBuyer()) {
            app(BuyerAccessService::class)->requirePortal($request->user());
        }
        if ($request->user()->isCourier()) {
            return Redirect::route('courier.profile');
        }

        return Inertia::render('Profile/Edit', [
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
            app(IdentityCorrectionService::class)->protectReviewedIdentity($request->user(), $request->validated());
            $request->user()->fill($request->validated());

            if ($request->user()->isDirty('email')) {
                $request->user()->email_verified_at = null;
            }

            $request->user()->save();

            return Redirect::route('profile.edit');
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
