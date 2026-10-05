<?php

namespace App\Http\Middleware;

use App\Services\BuyerAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBuyerAccess
{
    public function __construct(private readonly BuyerAccessService $access) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        if (! $request->user()) {
            return $mode === 'optional' ? $next($request) : redirect()->guest(route('login'));
        }
        $user = $this->access->current($request->user());
        $request->setUserResolver(fn () => $user);
        abort_unless($user->isBuyer(), 403, 'Buyer access is required.');
        if ($user->canAccessPortal()) {
            return $next($request);
        }
        abort_unless($this->access->canViewHolding($user), 403, 'Your account is not eligible for this action.');

        return redirect()->route('kyc.pending')->with('error', $this->access->portalIssue($user));
    }
}
