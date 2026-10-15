<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdvertisementResponseHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if (str_starts_with((string) $request->route()?->getActionName(), 'App\\Http\\Controllers\\Admin\\AdvertisementController@')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
