<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Courier\RiderTripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderTripController extends Controller
{
    public function __construct(private readonly RiderTripService $trips) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->trips->list($request)]);
    }

    public function show(Request $request, string $trip): JsonResponse
    {
        return response()->json(['data' => $this->trips->resource($this->trips->owned($request->user(), $trip), $request->user())]);
    }

    public function checkpointProof(Request $request, string $trip, string $checkpoint)
    {
        return $this->trips->checkpointProof($request->user(), $trip, $checkpoint);
    }

    public function attemptProof(Request $request, string $trip, string $attempt)
    {
        return $this->trips->attemptProof($request->user(), $trip, $attempt);
    }
}
