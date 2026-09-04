<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CrossDomainFallbackMiddleware
{
    /**
     * Buyer-specific paths that must not be accessed on worker subdomains.
     * Note: '/products' is intentionally omitted because sellers manage products at '/products'.
     *
     * @var array<string>
     */
    protected array $buyerPatterns = [
        'cart',
        'cart/*',
        'checkout',
        'checkout/*',
        'buyer',
        'buyer/*',
        'my-orders',
        'my-orders/*',
        'catalog',
        'catalog/*',
        'overview',
        'about',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();

        if ($this->isWorkerSubdomain($host)) {
            foreach ($this->buyerPatterns as $pattern) {
                if ($request->is($pattern)) {
                    $baseDomain = env('APP_DOMAIN', 'bagooph.shop');
                    $targetUrl = 'https://' . $baseDomain . $request->getRequestUri();

                    return redirect()->away($targetUrl, 302);
                }
            }
        }

        return $next($request);
    }

    /**
     * Determine whether the given host is a worker subdomain.
     */
    protected function isWorkerSubdomain(string $host): bool
    {
        return str_starts_with($host, 'seller.') ||
               str_starts_with($host, 'courier.') ||
               str_starts_with($host, 'hub.') ||
               str_starts_with($host, 'admin.');
    }
}
