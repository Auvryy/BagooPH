<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BuyerMobileJson
{
    public static function applies(Request $request): bool
    {
        return $request->is('auth/google/native', 'auth/google/native/*', 'buyer/mobile-capabilities');
    }

    public static function decorate(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setVary('Cookie', false);

        return $response;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        // Native contracts return JSON even when CSRF or another early guard fails.
        $request->headers->set('Accept', 'application/json');
        $request->headers->remove('X-Inertia');
        if (preg_match('/\A(?:seller|courier|hub|admin)\./', $request->getHost()) === 1) {
            return self::decorate(response()->json([
                'code' => 'BUYER_ORIGIN_REQUIRED', 'message' => 'Open buyer services on the buyer website address.',
            ], 403));
        }

        return self::decorate($next($request));
    }
}
