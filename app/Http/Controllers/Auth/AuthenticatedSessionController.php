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
     * Display the default / buyer login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'portal' => 'buyer',
        ]);
    }

    /**
     * Display the merchant / seller portal login view.
     */
    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerLogin', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'portal' => 'seller',
        ]);
    }

    /**
     * Display the courier rider dispatch portal login view.
     */
    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierLogin', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'portal' => 'courier',
        ]);
    }

    /**
     * Display the administrative governance login console.
     */
    public function createAdmin(): Response
    {
        return Inertia::render('Auth/AdminLogin', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'portal' => 'admin',
        ]);
    }

    /**
     * Display the logistics sorting hub login console.
     */
    public function createHub(): Response
    {
        return Inertia::render('Auth/HubLogin', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'portal' => 'logistics',
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

        $host = $request->getHost();

        // Subdomain-specific landing redirection
        if (str_starts_with($host, 'seller.')) {
            return redirect()->intended('/dashboard');
        }
        if (str_starts_with($host, 'courier.')) {
            return redirect()->intended('/deliveries');
        }
        if (str_starts_with($host, 'hub.')) {
            return redirect()->intended('/dashboard');
        }
        if (str_starts_with($host, 'admin.')) {
            return redirect()->intended('/dashboard');
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
