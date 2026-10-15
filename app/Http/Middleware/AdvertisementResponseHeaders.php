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
        $action = (string) $request->route()?->getActionName();
        if (str_starts_with($action, 'App\\Http\\Controllers\\Admin\\AdvertisementController@')
            || str_starts_with($action, 'App\\Http\\Controllers\\Seller\\PromotionController@')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
