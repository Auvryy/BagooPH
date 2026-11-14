<?php

namespace App\Http\Controllers;

use App\Models\EnforcementSanction;
use App\Models\FinancialPenalty;
use App\Services\Governance\EnforcementService;
use App\Services\Governance\FinancialPenaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccountEnforcementController extends Controller
{
    public function submitAppeal(Request $request, EnforcementSanction $sanction, EnforcementService $service): JsonResponse|RedirectResponse
    {
        try {
            $appeal = $service->submitAppeal($request->user(), $sanction, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['appeal' => $appeal], 201)
                : back()->with('success', "Appeal {$appeal->reference} submitted for independent platform review.");
        } catch (ValidationException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['errors' => $e->errors()], 422)
                : back()->withErrors($e->errors());
        }
    }

    public function settlePenalty(Request $request, FinancialPenalty $penalty, FinancialPenaltyService $service, EnforcementService $enforcement): JsonResponse|RedirectResponse
    {
        try {
            $updated = $service->settleDebtFromWallet($request->user(), $penalty, $enforcement);

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['penalty' => $updated])
                : back()->with('success', "Obligation {$updated->reference} settled from Bagoo Wallet. Any linked debt restrictions were updated.");
        } catch (ValidationException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['errors' => $e->errors()], 422)
                : back()->withErrors($e->errors());
        }
    }
}
