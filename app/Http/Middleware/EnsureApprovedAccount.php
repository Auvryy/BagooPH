<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\AccountReviewService;
use App\Services\BuyerAccessService;
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

        $user = app(BuyerAccessService::class)->current($user);
        $request->setUserResolver(fn () => $user);

        abort_unless(UserRole::tryFrom($user->role), 403, 'A recognized account role is required.');

        if (in_array($user->status, ['suspended', 'inactive'], true) || $user->kyc_status === 'rejected') {
            $status = app(AccountReviewService::class)->heading($user);

            return $request->expectsJson()
                ? response()->json(['message' => $status['title'].'. '.$status['message'], 'account_status_url' => '/account/status'], 403)
                : redirect()->route('account.status')->with('error', $status['title']);
        }

        if ($user->canAccessPortal()) {
            return $next($request);
        }

        if ($user->isBuyer()) {
            abort_unless(app(BuyerAccessService::class)->canViewHolding($user), 403);

            return redirect()->route('kyc.pending')->with('error', app(BuyerAccessService::class)->portalIssue($user));
        }

        if ($user->status === 'suspended' || $user->isAdmin()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your account is not eligible for this protected action.'], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => $user->status === 'suspended'
                    ? 'Your account has been suspended by platform administration.'
                    : 'Your account is not active. Please contact platform administration.',
            ]);
        }

        return redirect('/pending-approval');
    }
}
