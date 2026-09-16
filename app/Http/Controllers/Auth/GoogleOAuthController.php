<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        if (empty($email) || empty($googleId)) {
            return redirect()->route('login')->withErrors([
                'email' => 'Unable to retrieve your Google account details. Please try again.',
            ]);
        }

        // 1. Look for existing user with this google_id
        $user = User::where('google_id', $googleId)->first();

        // 2. If not found by google_id, check if existing account has the same email
        if (!$user) {
            $user = User::where('email', $email)->first();

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
                $user = User::create([
                    'name' => $googleUser->getName() ?: 'Buyer',
                    'email' => $email,
                    'google_id' => $googleId,
                    'avatar' => $googleUser->getAvatar(),
                    'role' => 'buyer',
                    'status' => 'active',
                    'password' => Hash::make(Str::random(32)),
                    'email_verified_at' => now(),
                ]);
            }
        }

        // Verify account is active
        if ($user->status !== 'active') {
            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been deactivated. Please contact support.',
            ]);
        }

        Auth::login($user, true);
        request()->session()->regenerate();

        // Redirect according to role
        if ($user->role === 'seller') {
            return redirect()->route('seller.dashboard')->with('success', 'Signed in with Google successfully.');
        }

        if ($user->role === 'courier') {
            return redirect()->route('courier.deliveries')->with('success', 'Signed in with Google successfully.');
        }

        if ($user->role === 'hub') {
            return redirect()->route('hub.dashboard')->with('success', 'Signed in with Google successfully.');
        }

        if ($user->role === 'admin') {
            return redirect()->route('admin.dashboard')->with('success', 'Signed in with Google successfully.');
        }

        return redirect()->intended(route('buyer.index'))->with('success', 'Signed in with Google successfully.');
    }
}
