<?php

use App\Http\Middleware\CrossDomainFallbackMiddleware;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Http\Middleware\EnsureApprovedCourier;
use App\Http\Middleware\EnsureBuyerAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SubdomainRoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // These validators must see controls before generic input trimming removes them.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('track', 'track/*', 'api/track/*')
            || ($request->isMethod('POST') && $request->is('register', 'kyc/resubmit', 'admin/kyc/*/reject', 'kyc/*/reject', 'seller/shops', 'seller/shops/*/resubmit', 'shops', 'shops/*/resubmit', 'admin/shops/*/reject', 'shops/*/reject'))]);

        $middleware->prepend(CrossDomainFallbackMiddleware::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'buyer.approved' => EnsureBuyerAccess::class,
            'account.approved' => EnsureApprovedAccount::class,
            'courier.approved' => EnsureApprovedCourier::class,
            'role' => RoleMiddleware::class,
            'subdomain.role' => SubdomainRoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['otp_token', 'token', 'code', 'claim_code', 'pickup_code', 'signature']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
