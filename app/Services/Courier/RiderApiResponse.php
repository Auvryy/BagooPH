<?php

namespace App\Services\Courier;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RiderApiResponse
{
    public static function applies(Request $request): bool
    {
        return preg_match('#\Aapi/v1/rider/(home|duty|pickup-jobs|tasks|commands|trips|conversations|notifications|cash)(/|\z)#', $request->path()) === 1;
    }

    public static function decorate(Response $response, Request $request): Response
    {
        if (! self::applies($request)) {
            return $response;
        }
        $candidate = $request->header('X-Request-ID', '');
        $id = $request->attributes->get('rider_request_id') ?? (Str::isUuid($candidate) ? strtolower($candidate) : (string) Str::uuid());
        $request->attributes->set('rider_request_id', $id);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            $body = json_decode((string) $response->getContent(), true) ?? [];
            $code = $body['code'] ?? match ($status) {
                401 => 'SESSION_EXPIRED', 403 => 'ACCESS_DENIED', 404 => 'NOT_FOUND', 409 => 'OPERATION_CONFLICT',
                422 => 'VALIDATION_FAILED', 429 => 'RATE_LIMITED', 503 => 'SERVICE_UNAVAILABLE', default => 'REQUEST_FAILED',
            };
            $message = $status >= 500 ? 'Rider operations are temporarily unavailable.' : ($body['message'] ?? 'This request cannot be completed.');
            $payload = ['code' => $code, 'message' => $message, 'errors' => $status === 422 ? ($body['errors'] ?? []) : [], 'request_id' => $id];
            if ($status === 429) {
                $payload['cooldown'] = max(1, min(300, (int) $response->headers->get('Retry-After', 60)));
            }
            $headers = $status === 429 ? ['Retry-After' => (string) $payload['cooldown']] : [];
            $response = response()->json($payload, $status, $headers);
        } elseif (str_contains($response->headers->get('Content-Type', ''), 'application/json')) {
            $body = json_decode((string) $response->getContent(), true);
            if (is_array($body)) {
                $body['request_id'] = $id;
                $response->setContent(json_encode($body, JSON_THROW_ON_ERROR));
            }
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
