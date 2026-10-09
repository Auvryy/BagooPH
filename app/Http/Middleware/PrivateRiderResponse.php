<?php

namespace App\Http\Middleware;

use App\Services\Courier\RiderApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrivateRiderResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (RiderApiResponse::applies($request)) {
            RiderApiResponse::requestId($request);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return RiderApiResponse::decorate($response, $request);
    }
}
