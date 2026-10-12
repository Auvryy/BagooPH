<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated as FrameworkRedirectIfAuthenticated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated extends FrameworkRedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            foreach ($guards ?: [null] as $guard) {
                if (Auth::guard($guard)->check()) {
                    return response()->json([
                        'message' => 'You are already signed in. Sign out before continuing with this form.',
                    ], Response::HTTP_CONFLICT)->header('Cache-Control', 'no-store, private');
                }
            }
        }

        return parent::handle($request, $next, ...$guards);
    }
}
