<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(private readonly EnsureApprovedAccount $approval) {}

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

        // Enforce role authorization
        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Unauthorized access for your account role (' . $user->role . ').');
        }

        return $this->approval->handle($request, $next);
    }
}
