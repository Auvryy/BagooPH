<?php

use App\Http\Middleware\CrossDomainFallbackMiddleware;
use App\Http\Middleware\EnsureAccountOpen;
use App\Http\Middleware\EnsureApprovedAccount;
use App\Http\Middleware\EnsureApprovedCourier;
use App\Http\Middleware\EnsureBuyerAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SubdomainRoleMiddleware;
use App\Services\Courier\RiderApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // These validators must see controls before generic input trimming removes them.
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('track', 'track/*', 'api/track/*', 'checkout', 'buyer/checkout')
            || $request->is('api/v1/rider/settings/*') || RiderApiResponse::applies($request)
            || ($request->isMethod('POST') && $request->is('api/v1/rider/applications', 'api/v1/rider/registration/email/verify', 'register', 'kyc/resubmit', 'admin/kyc/*/reject', 'kyc/*/reject', 'seller/shops/*/resubmit', 'shops/*/resubmit', 'admin/shops/*/reject', 'shops/*/reject', 'admin/products/*/moderation', 'products/*/moderation', 'buyer/addresses', 'hub/sort', 'sort', 'buyer/profile', 'seller/profile', 'profile', 'cart', 'hub/scan', 'scan', 'hub/manifests', 'hub/manifests/*', 'manifests', 'manifests/*', 'hub/release', 'release', 'hub/counter/hours', 'counter/hours', 'custody-recovery/*', 'exceptions/*'))
            || ($request->isMethod('PATCH') && $request->is('profile', 'courier/profile/account', 'profile/account', 'cart/*', 'courier/deliveries/*/status', 'deliveries/*/status'))]);

        $middleware->prepend(CrossDomainFallbackMiddleware::class);

        $middleware->web(append: [
            EnsureAccountOpen::class,
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
        $exceptions->render(function (DomainException $exception, Request $request) {
            if (RiderApiResponse::applies($request)) {
                return response()->json(['code' => 'OPERATION_CONFLICT', 'message' => $exception->getMessage()], 409);
            }
        });
        $exceptions->respond(function (Response $response) {
            $request = request();
            if ($response->getStatusCode() === 419 && $request->header('X-Inertia') && $request->is('login', 'custody-recovery/sign-in')) {
                $path = $request->is('custody-recovery/sign-in') ? '/custody-recovery/sign-in' : '/login';
                $referrer = parse_url($request->header('Referer', ''));
                if (($referrer['host'] ?? null) === $request->getHost()
                    && in_array($referrer['path'] ?? null, ['/login', '/seller/login', '/courier/login', '/admin/login', '/hub/login', '/custody-recovery/sign-in'], true)) {
                    $path = $referrer['path'];
                }
                $destination = redirect($path)->withErrors(['email' => 'Your sign-in page expired. Please try again.']);
                $destination->setTargetUrl($path);

                return Inertia::location($destination);
            }

            if (request()->is('api/v1/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->set('Pragma', 'no-cache');
            }

            return RiderApiResponse::decorate($response, $request);
        });
        $exceptions->dontFlash(['otp_token', 'token', 'code', 'claim_code', 'pickup_code', 'signature', 'checkout_token']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
