<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedCourier
{
    public function __construct(private readonly EnsureApprovedAccount $approval) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isCourier(), 403, 'Courier access is required.');

        return $this->approval->handle($request, $next);
    }
}
