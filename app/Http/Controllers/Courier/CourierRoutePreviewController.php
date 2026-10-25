<?php

namespace App\Http\Controllers\Courier;

use App\Http\Controllers\Controller;
use App\Services\Courier\CourierRoutePreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourierRoutePreviewController extends Controller
{
    public function __invoke(Request $request, string $task, CourierRoutePreviewService $routes): JsonResponse
    {
        $input = $request->validate([
            'latitude' => ['required', 'numeric', 'between:4,22'],
            'longitude' => ['required', 'numeric', 'between:116,128'],
            'accuracy' => ['required', 'numeric', 'between:0,50'],
            'recorded_at' => ['required', 'date', 'after_or_equal:'.now()->subSeconds(30)->toIso8601String(), 'before_or_equal:'.now()->addSeconds(2)->toIso8601String()],
            'expected_status' => ['required', 'string', 'max:50'],
            'destination' => ['prohibited'], 'api_key' => ['prohibited'],
        ]);

        return response()->json($routes->preview($request->user(), $task, $input))->header('Cache-Control', 'private, no-store');
    }
}
