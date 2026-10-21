<?php

namespace App\Http\Responses;

use App\Services\Logistics\LogisticsEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogisticsErrorResponse
{
    public function render(Request $request, Response $original, Throwable $exception): Response
    {
        $status = $original->getStatusCode();
        if ($status < 400 || $status === 422 || $original->headers->has('X-Inertia') || ! $this->applies($request)) {
            return $original;
        }

        [$title, $description] = $this->message($status, $request->isMethod('GET'));
        $reference = $status >= 500 ? (string) Str::uuid() : null;
        if ($reference) {
            try {
                Log::error('Logistics request failed.', ['request_id' => $reference, 'status' => $status,
                    'path' => $request->path(), 'exception_type' => $exception::class]);
            } catch (Throwable) {
                // A logging outage must not replace the recovery response with another failure.
            }
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $response = response()->json(['message' => $description, 'request_id' => $reference], $status);
        } else {
            $prefix = str_starts_with($request->getHost(), 'hub.') ? '' : '/hub';
            $overview = $prefix.'/dashboard';
            if ($status === 403) {
                try {
                    // Explicitly choosing a currently authorized facility can recover a stale selection.
                    $hub = app(LogisticsEligibilityService::class)->accessibleHubs($request->user())->orderBy('id')->first();
                    if ($hub) {
                        $overview .= '?hub_id='.$hub->id;
                    }
                } catch (Throwable) {
                }
            }
            $path = $request->getPathInfo();
            $canRetry = $request->isMethod('GET') && ($status >= 500 || in_array($status, [408, 429], true))
                && str_starts_with($path, '/') && ! str_starts_with($path, '//')
                && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $path);
            $props = ['status' => $status, 'title' => $title, 'description' => $description,
                'requestId' => $reference, 'overviewUrl' => $overview,
                'loginUrl' => in_array($status, [401, 419], true) ? ($prefix ? '/hub/login' : '/login') : null,
                'retryUrl' => $canRetry ? $path : null];

            if ($request->header('X-Inertia')) {
                // Shared queues and account summaries may themselves be the source of the failure.
                Inertia::flushShared();
                $response = Inertia::render('Hub/Error', $props)->toResponse($request)->setStatusCode($status);
            } else {
                // Direct recovery also works when JavaScript assets are missing or unavailable.
                $response = response()->view('errors.logistics', $props, $status);
            }
        }

        foreach (['Retry-After', 'Allow', 'WWW-Authenticate'] as $header) {
            if ($original->headers->has($header)) {
                $response->headers->set($header, $original->headers->get($header));
            }
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'X-Inertia');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        if ($reference) {
            $response->headers->set('X-Request-ID', $reference);
        }

        return $response;
    }

    private function applies(Request $request): bool
    {
        if ($request->is('api', 'api/*')) {
            return false;
        }
        if ($request->is('hub', 'hub/*') || str_starts_with($request->getHost(), 'hub.')) {
            return true;
        }

        try {
            return $request->user()?->isLogistics() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    private function message(int $status, bool $read): array
    {
        return match ($status) {
            401, 419 => ['Sign in again', 'Your session or form expired. Sign in again, then reopen the record before submitting an update.'],
            403 => ['Access unavailable', 'Your account cannot open this page or use this facility. Open Overview to see the work available to you.'],
            404, 410 => ['Page unavailable', 'We could not find this logistics page or record. Open Overview to choose a current record.'],
            405 => ['Action unavailable', 'Open the current record from Overview and choose an available action.'],
            408 => ['Request timed out', 'This request took too long. Open Overview and check the latest record before trying an update again.'],
            409 => ['Record changed', 'Reopen the current record and check its latest details before trying this action again.'],
            429 => ['Please wait a moment', 'Too many requests arrived together. Wait a moment before opening the page again.'],
            502, 503, 504 => ['Logistics temporarily unavailable', 'Please try opening this page again in a moment. Check the latest record before repeating an update.'],
            default => $status >= 500
                ? [$read ? 'Could not open this page' : 'Could not finish this request', 'Return to Overview and check the latest record before repeating an update. Use the reference below when reporting the problem.']
                : ['Request unavailable', 'Open Overview and try the action from its current record.'],
        };
    }
}
