<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        abort_unless(UserRole::tryFrom($user->role), 403, 'A recognized account role is required.');

        if ($user->canAccessPortal()) {
            return $next($request);
        }

        if ($user->status === 'suspended' || $user->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => $user->status === 'suspended'
                    ? 'Your account has been suspended by platform administration.'
                    : 'Your account is not active. Please contact platform administration.',
            ]);
        }

        return redirect('/pending-approval');
    }
}
