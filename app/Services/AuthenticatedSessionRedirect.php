<?php

namespace App\Services;

use App\Http\Middleware\EnsureApprovedAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatedSessionRedirect
{
    public function response(Request $request): Response
    {
        $user = app(BuyerAccessService::class)->current($request->user());
        $request->setUserResolver(fn () => $user);
        // An old intended login/register URL must not send an existing session back to a guest form.
        $request->session()->forget('url.intended');
        $response = app(AccountReviewService::class)->needsAttention($user)
            ? redirect()->route('account.status')
            : ($user->isBuyer()
            ? app(BuyerAccessService::class)->signInDestination($request)
            : app(EnsureApprovedAccount::class)->handle($request, fn () => redirect($this->portalPath($user))));

        if ($response instanceof RedirectResponse) {
            $path = parse_url($response->getTargetUrl(), PHP_URL_PATH) ?: '/';
            $response->setTargetUrl($this->target($request, $user, $path));
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    private function portalPath(User $user): string
    {
        return route(match ($user->role) {
            'seller' => 'seller.dashboard',
            'courier' => 'courier.deliveries',
            'logistics' => 'hub.index',
            'admin' => 'admin.dashboard',
            default => 'buyer.index',
        }, absolute: false);
    }

    private function target(Request $request, User $user, string $path): string
    {
        $host = $request->getHost();
        $base = preg_replace('/^(seller|courier|hub|admin)\./', '', $host);
        $known = $base === 'localhost' || $base === parse_url(config('app.url'), PHP_URL_HOST)
            || $request->route()?->getDomain() === $host;
        if ($base === $host || ! $known) {
            return $path;
        }

        $prefix = match ($user->role) {
            'seller' => 'seller', 'courier' => 'courier', 'logistics' => 'hub', 'admin' => 'admin', default => null,
        };
        $targetHost = $prefix ? $prefix.'.'.$base : $base;
        $path = match ($path) {
            '/seller/dashboard', '/admin/dashboard', '/hub' => '/dashboard',
            '/courier/deliveries' => '/deliveries',
            default => $path,
        };
        if ($targetHost === $host) {
            return $path;
        }

        $scheme = $request->getScheme();
        $port = $request->getPort();
        $suffix = in_array($port, [80, 443], true) ? '' : ':'.$port;

        return $scheme.'://'.$targetHost.$suffix.$path;
    }
}
