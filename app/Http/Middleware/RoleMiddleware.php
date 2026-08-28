<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Admins bypass KYC gate and have full portal access
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Block and logout suspended users
        if ($user->status === 'suspended') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your account has been suspended by platform administration.',
            ]);
        }

        // Intercept pending or rejected KYC accounts
        if ($user->kyc_status === 'pending_approval' || $user->status === 'pending_approval' || $user->kyc_status === 'rejected') {
            return redirect()->route('kyc.pending');
        }

        // Enforce role authorization
        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $user->role . ').');
        }

        return $next($request);
    }
}
