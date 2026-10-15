<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Courier\RiderCommandService;
use App\Services\Courier\RiderCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderCompanyController extends Controller
{
    public function __construct(private readonly RiderCompanyService $service) {}

    public function companies(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->companies($request)]);
    }

    public function company(Request $request, string $company): JsonResponse
    {
        return response()->json(['data' => $this->service->company($request, $company)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->applications($request)]);
    }

    public function show(Request $request, string $application): JsonResponse
    {
        return response()->json(['data' => $this->service->application($request, $application)]);
    }

    public function submit(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->submit($request)]);
    }

    public function withdraw(Request $request, string $application): JsonResponse
    {
        return response()->json(['data' => $this->service->decide($request, $application, 'withdrawn')]);
    }

    public function accept(Request $request, string $application): JsonResponse
    {
        return response()->json(['data' => $this->service->decide($request, $application, 'accepted')]);
    }

    public function decline(Request $request, string $application): JsonResponse
    {
        return response()->json(['data' => $this->service->decide($request, $application, 'declined')]);
    }

    public function assignment(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->assignment($request)]);
    }

    public function command(Request $request, string $key, RiderCommandService $commands): JsonResponse
    {
        $this->service->emptyRead($request);

        return response()->json(['data' => $commands->owned($request, $key)]);
    }
}
