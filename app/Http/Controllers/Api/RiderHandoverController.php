<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Courier\RiderHandoverService;
use App\Services\Logistics\HandoverIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RiderHandoverController extends Controller
{
    public function __construct(private readonly RiderHandoverService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->index($request)]);
    }

    public function show(Request $request, string $issue): JsonResponse
    {
        return response()->json(['data' => $this->service->show($request->user(), $issue)]);
    }

    public function report(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->report($request)]);
    }

    public function acknowledge(Request $request, string $grant): JsonResponse
    {
        return response()->json(['data' => $this->service->acknowledge($request, $grant)]);
    }

    public function evidence(Request $request, string $issue, HandoverIssueService $issues): BinaryFileResponse
    {
        $record = $this->service->issue($request->user(), $issue);

        return response()->download($issues->evidencePath($request->user(), $record), $record->reference.'.'.$record->evidence_extension,
            ['Content-Type' => $record->evidence_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
