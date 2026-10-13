<?php

namespace App\Http\Middleware;

use App\Services\AuthenticatedSessionRedirect;
use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as FrameworkRedirectIfAuthenticated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated extends FrameworkRedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        foreach ($guards ?: [null] as $guard) {
            if (! Auth::guard($guard)->check()) {
                continue;
            }
            $request->setUserResolver(fn () => Auth::guard($guard)->user());
            $destination = app(AuthenticatedSessionRedirect::class)->response($request);
            if (! $destination instanceof RedirectResponse) {
                return $destination;
            }
            if ($request->header('X-Inertia')) {
                return Inertia::location($destination);
            }
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'code' => Auth::guard($guard)->check() ? 'ALREADY_AUTHENTICATED' : 'SESSION_UNAVAILABLE',
                    'message' => Auth::guard($guard)->check() ? 'You are already signed in. Returning to your account.' : 'Please sign in again.',
                    'redirect' => $destination->getTargetUrl(),
                ], Response::HTTP_CONFLICT)->header('Cache-Control', 'no-store, private');
            }

            return $destination;
        }

        return $next($request);
    }
}
