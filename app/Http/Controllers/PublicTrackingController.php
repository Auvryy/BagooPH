<?php

namespace App\Http\Controllers;

use App\Services\Logistics\PublicTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicTrackingController extends Controller
{
    public function __construct(private readonly PublicTrackingService $tracking) {}

    public function show(Request $request, ?string $tracking_number = null): Response|JsonResponse
    {
        $input = $tracking_number ?? $request->query('number', $request->query('tracking_number', $request->query('q', '')));
        $trackingCode = $input === null ? '' : (is_string($input) ? strtoupper(trim($input, ' ')) : null);
        $isValid = $trackingCode !== null && ($trackingCode === '' || preg_match('/\A[A-Z0-9][A-Z0-9_-]{0,63}\z/', $trackingCode) === 1);
        $validationMessage = $isValid ? null : 'Enter a tracking number using up to 64 letters, numbers, hyphens, or underscores.';
        $parcel = $isValid && $trackingCode !== '' ? $this->tracking->find($trackingCode) : null;
        $notFound = $isValid && $trackingCode !== '' && $parcel === null;

        if ($request->wantsJson()) {
            return response()->json([
                'success' => $parcel !== null,
                'message' => $validationMessage ?? ($parcel ? null : 'No parcel found. Check the tracking number on your waybill.'),
                'parcel' => $parcel,
                'available_actions' => [],
            ], ! $isValid ? 422 : ($parcel ? 200 : ($notFound ? 404 : 400)))
                ->header('Cache-Control', 'no-store');
        }

        return Inertia::render('Public/Tracking', [
            'parcel' => $parcel,
            'searchedNumber' => $isValid ? $trackingCode : '',
            'notFound' => $notFound,
            'validationMessage' => $validationMessage,
            'availableActions' => [],
        ]);
    }

    public function apiTrack(Request $request, string $tracking_number): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        return $this->show($request, $tracking_number);
    }
}
