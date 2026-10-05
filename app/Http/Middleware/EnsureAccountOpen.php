<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && User::whereKey($request->user()->id)->whereNotNull('closed_at')->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'This account is closed.'], 403);
            }

            return redirect('/login')->withErrors(['email' => 'This account is closed. Please contact support for its retained records.']);
        }

        return $next($request);
    }
}
