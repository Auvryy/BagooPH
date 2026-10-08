<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\RiderAccountService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRiderAccountToken
{
    public function handle(Request $request, Closure $next, string $purpose = 'account'): Response
    {
        $plain = $request->bearerToken();
        $token = app(RiderAccountService::class)->findToken($plain);
        $user = $token?->tokenable;
        if (! $token || ! $user instanceof User || ! $token->expires_at || now()->greaterThanOrEqualTo($token->expires_at)
            || ! $token->can($purpose === 'logout' ? 'rider:logout' : 'rider:account')) {
            return response()->json(['message' => 'Please sign in to your rider account.'], 401);
        }
        if ($purpose !== 'logout') {
            if (! hash_equals((string) $token->getAttribute('credential_fingerprint'), hash('sha256', $user->getAuthPassword()))) {
                $token->delete();

                return response()->json(['message' => 'Your session expired. Please sign in again.'], 401);
            }
            try {
                app(RiderAccountService::class)->assertAccessible($user);
            } catch (HttpResponseException $exception) {
                $token->delete();
                throw $exception;
            }
        }
        if (str_starts_with($purpose, 'settings:')) {
            abort_unless($user->isEligibleCourier(), 403, 'An approved active rider account is required for settings.');
            abort_unless(app(RiderAccountService::class)->settingsAvailable(), 503, 'Account settings are temporarily unavailable.');
            $ability = 'rider:'.$purpose;
            app(RiderAccountService::class)->assertSettingsToken($request, $user, $ability);
            $request->attributes->set('rider_settings_ability', $ability);
        }
        $token->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $user->withAccessToken($token));

        return $next($request);
    }
}
