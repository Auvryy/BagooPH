<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        if ($user->status === 'suspended') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been suspended by platform administration.',
            ]);
        }

        if (! $user->isAdmin() && ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected')) {
            return redirect()->route('kyc.pending');
        }

        $targetRoute = match($user->role) {
            'admin' => route('admin.dashboard', absolute: false),
            'seller' => route('seller.dashboard', absolute: false),
            'courier' => route('courier.deliveries', absolute: false),
            'logistics' => route('hub.index', absolute: false),
            default => route('buyer.index', absolute: false),
        };

        return redirect()->intended($targetRoute);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
