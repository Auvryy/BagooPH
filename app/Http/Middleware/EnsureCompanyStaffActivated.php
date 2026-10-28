<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EnsureCompanyStaffActivated
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user() ? User::find($request->user()->id) : null;
        if ($user?->must_change_password
            && ! $request->is('staff/activate', 'logout', 'auth/session')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Replace your temporary password before using your work account.', 'activation_required' => true], 423)
                : redirect('/staff/activate');
        }

        if ($user?->needsCompanyEmailVerification()
            && ! $request->is('verify-email', 'verify-email/*', 'email/verification-notification', 'logout', 'auth/session')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Verify your registered email before starting work.', 'email_verification_required' => true], 423)
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
