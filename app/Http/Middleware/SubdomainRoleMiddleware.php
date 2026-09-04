<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubdomainRoleMiddleware
{
    /**
     * Handle an incoming request on an isolated subdomain.
     */
    public function handle(Request $request, Closure $next, ?string $expectedRole = null): Response
    {
        $user = $request->user();

        if (! $expectedRole) {
            $host = $request->getHost();
            if (str_starts_with($host, 'seller.')) {
                $expectedRole = 'seller';
            } elseif (str_starts_with($host, 'courier.')) {
                $expectedRole = 'courier';
            } elseif (str_starts_with($host, 'hub.')) {
                $expectedRole = 'logistics';
            } elseif (str_starts_with($host, 'admin.')) {
                $expectedRole = 'admin';
            }
        }

        if (! $expectedRole) {
            return $next($request);
        }

        if (! $user) {
            return redirect()->guest('/login');
        }

        // Hub allows logistics and admin; other cockpits are strictly single-role
        $allowedRoles = ($expectedRole === 'logistics')
            ? ['logistics', 'admin']
            : [$expectedRole];

        if (! in_array($user->role, $allowedRoles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $user->role . ').');
        }

        return $next($request);
    }
}
