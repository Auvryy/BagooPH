<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Models\User;
use App\Services\ApplicationValidationService;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\GoogleRegistrationService;
use App\Services\RegistrationPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle(Request $request): RedirectResponse
    {
        $request->session()->forget('registration.google');

        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google and authenticate.
     */
    public function handleGoogleCallback(Request $request): RedirectResponse
    {
        try {
            /** @var \Laravel\Socialite\Two\User $googleUser */
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google authentication was cancelled or failed. Please try again.',
            ]);
        }

        $email = $googleUser->getEmail();
        $googleId = $googleUser->getId();

        if (! is_string($email) || ! is_string($googleId) || empty($email) || empty($googleId) || strlen($googleId) > 255 || ($googleUser->getRaw()['email_verified'] ?? false) !== true) {
            return redirect()->route('login')->withErrors([
                'email' => 'Unable to retrieve your Google account details. Please try again.',
            ]);
        }

        // 1. Look for existing user with this google_id
        $user = User::where('google_id', $googleId)->first();

        // 2. If not found by google_id, check if existing account has the same email
        if (! $user) {
            $user = User::whereRaw('LOWER(email) = LOWER(?)', [$email])->first();

            if ($user) {
                if ($user->google_id !== null && $user->google_id !== $googleId) {
                    return redirect()->route('login')->withErrors(['email' => 'This account is linked to a different Google identity. Use your existing sign-in method.']);
                }
                // Link Google account to existing user
                $user->google_id = $googleId;
                if (empty($user->avatar) && $googleUser->getAvatar()) {
                    $user->avatar = $googleUser->getAvatar();
                }
                if (is_null($user->email_verified_at)) {
                    $user->email_verified_at = now();
                }
                $user->save();
            } else {
                // 3. Register as a new buyer
                $applications = app(ApplicationValidationService::class);
                $identity = $applications->registrationValues(['name' => $googleUser->getName() ?: 'Buyer', 'email' => $email], 'buyer');
                $validator = Validator::make($identity, ['email' => $applications->rules('buyer')['email']]);
                if ($validator->fails()) {
                    return redirect()->route('login')->withErrors(['email' => 'Your Google account details need correction. Please register with valid application details.']);
                }
                $request->session()->put('registration.google', [
                    'identity' => $identity, 'google_id' => $googleId, 'avatar' => $googleUser->getAvatar(),
                    'nonce' => Str::random(40), 'expires_at' => now()->addMinutes(10)->timestamp,
                ]);

                return redirect()->route('auth.google.registration');
            }
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        if ($user->isBuyer()) {
            return app(BuyerAccessService::class)->signInDestination(request());
        }

        return app(EnsureApprovedAccount::class)->handle(request(), function (Request $request): RedirectResponse {
            $route = match ($request->user()->role) {
                'seller' => 'seller.dashboard',
                'courier' => 'courier.deliveries',
                'logistics' => 'hub.index',
                'admin' => 'admin.dashboard',
            };

            return redirect()->route($route)->with('success', 'Signed in with Google successfully.');
        });
    }

    public function registration(Request $request, GoogleRegistrationService $registration): Response|RedirectResponse
    {
        try {
            $pending = $registration->pending($request);
        } catch (ValidationException $exception) {
            return redirect()->route('login')->withErrors($exception->errors());
        }

        return Inertia::render('Auth/GoogleRegistration', [
            'identity' => $pending['identity'], 'registrationNonce' => $pending['nonce'],
            'birthDateLimits' => app(BirthDateEligibility::class)->limits(),
            'policies' => app(RegistrationPolicyService::class)->contract(),
        ]);
    }

    public function completeRegistration(Request $request, GoogleRegistrationService $registration): RedirectResponse
    {
        $user = $registration->register($request);
        Auth::login($user, true);
        $request->session()->regenerate();

        return app(BuyerAccessService::class)->signInDestination($request);
    }
}
