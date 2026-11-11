<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccountSessions\AccountSessionService;
use App\Services\Logistics\LogisticsAccountService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EnsureLogisticsAccountToken
{
    public function handle(Request $request, Closure $next, string $purpose = 'account')
    {
        $plain = $request->bearerToken();
        $parts = is_string($plain) ? explode('|', $plain, 2) : [];
        $valid = count($parts) === 2 && strlen($plain) <= 512 && ctype_digit($parts[0])
            && filter_var($parts[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
        $token = $valid ? PersonalAccessToken::findToken($plain) : null;
        $user = $token?->tokenable;
        if (! $token || ! $user instanceof User || ! $user->isLogistics() || ! str_starts_with($token->name, 'logistics:')
            || ! in_array('logistics:'.$purpose, $token->abilities, true)
            || ! $token->expires_at?->isFuture()
            || ! hash_equals((string) $token->credential_fingerprint, hash('sha256', $user->getAuthPassword()))) {
            return response()->json(['message' => 'Your operator session expired. Sign in again.'], 401);
        }
        if ($purpose !== 'logout') {
            app(LogisticsAccountService::class)->assertAccessible($user);
        }
        if (! app(AccountSessionService::class)->touchNative($user, $token, $request)) {
            return response()->json(['message' => 'This work session ended. Sign in again.'], 401);
        }
        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $user->withAccessToken($token));

        return $next($request);
    }
}
