<?php

namespace App\Http\Middleware;

use App\Services\AccountSessions\AccountSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class TrackAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessions = app(AccountSessionService::class);
        $before = $sessions->currentWeb($request);
        if (($request->attributes->get(AccountSessionService::LOGIN_ATTRIBUTE)['method'] ?? null) === 'remembered') {
            if (! $sessions->finishWeb($request, null)) {
                return $this->ended($request);
            }
            $request->attributes->remove(AccountSessionService::LOGIN_ATTRIBUTE);
            $before = $sessions->currentWeb($request);
        }
        if ($request->user() && ! $before && ! $request->attributes->has(AccountSessionService::LOGIN_ATTRIBUTE)) {
            return $this->ended($request);
        }
        $response = $next($request);
        if (! $sessions->finishWeb($request, $before)) {
            return $this->ended($request);
        }

        return $response;
    }

    private function ended(Request $request): Response
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();
        $message = 'This session has ended. Sign in again to continue.';
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 401)->header('Cache-Control', 'private, no-store');
        }
        $redirect = redirect('/login')->withErrors(['email' => $message]);
        $redirect->setTargetUrl('/login');

        return $request->header('X-Inertia') ? Inertia::location($redirect) : $redirect;
    }
}
