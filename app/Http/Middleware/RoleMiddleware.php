<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if (! $request->user()) {
            return redirect()->route('login');
        }

        $userRole = $request->user()->role;

        // If admin, grant full access across role routes
        if ($userRole === 'admin') {
            return $next($request);
        }

        if (! in_array($userRole, $roles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $userRole . ').');
        }

        return $next($request);
    }
}
