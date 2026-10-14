<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\HandoverIssue;
use App\Models\HandoverRecoveryGrant;
use App\Services\Logistics\HandoverRecoveryService;
use App\Services\Logistics\HubHandoverRecoveryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HandoverRecoveryController extends Controller
{
    public function fulfillment(Request $request, HandoverIssue $issue, HandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->confirmFulfillment($request->user(), $issue, $request->all()));
    }

    public function authorize(Request $request, HandoverIssue $issue, HandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->authorize($request->user(), $issue, $request->all()));
    }

    public function authorizeHub(Request $request, HandoverIssue $issue, HubHandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->authorize($request->user(), $issue, $request->all()));
    }

    public function acknowledge(Request $request, HandoverRecoveryGrant $recoveryGrant, HandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->acknowledge($request->user(), $recoveryGrant, $request->all()));
    }

    public function receive(Request $request, HandoverRecoveryGrant $recoveryGrant, HandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->receive($request->user(), $recoveryGrant, $request->all()));
    }

    public function withdraw(Request $request, HandoverRecoveryGrant $recoveryGrant, HandoverRecoveryService $service): JsonResponse|RedirectResponse
    {
        return $this->result($request, fn () => $service->withdraw($request->user(), $recoveryGrant, $request->all()));
    }

    private function result(Request $request, callable $command): JsonResponse|RedirectResponse
    {
        try {
            $result = $command();
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409)
                : back()->withErrors(['recovery' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['id' => $result->id, 'issue_id' => $result->handover_issue_id])
            : back()->with('success', 'The authorized recovery action was recorded.');
    }
}
