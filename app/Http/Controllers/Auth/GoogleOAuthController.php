<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Models\User;
use App\Services\ApplicationValidationService;
use App\Services\BuyerAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleOAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google and authenticate.
     */
    public function handleGoogleCallback(): RedirectResponse
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

        if (! is_string($email) || ! is_string($googleId) || empty($email) || empty($googleId)) {
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
                $identity = $applications->normalize(['name' => $googleUser->getName() ?: 'Buyer', 'email' => $email], 'buyer');
                $validator = Validator::make($identity, $applications->rules('buyer'));
                if ($validator->fails()) {
                    return redirect()->route('login')->withErrors(['email' => 'Your Google account details need correction. Please register with valid application details.']);
                }
                try {
                    $user = User::create([
                        'name' => $identity['name'],
                        'email' => $identity['email'],
                        'google_id' => $googleId,
                        'avatar' => $googleUser->getAvatar(),
                        'role' => 'buyer',
                        'status' => 'active',
                        'kyc_status' => 'none',
                        'id_document_path' => null,
                        'password' => Hash::make(Str::random(32)),
                        'email_verified_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    // The database also enforces email identity when registration races another request.
                    if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                        throw $exception;
                    }

                    return redirect()->route('login')->withErrors(['email' => 'An account already uses these details. Please sign in again.']);
                }
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
}
