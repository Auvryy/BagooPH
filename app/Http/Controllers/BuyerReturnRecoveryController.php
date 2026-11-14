<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnRecovery;
use App\Services\Returns\BuyerReturnRecoveryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyerReturnRecoveryController extends Controller
{
    use Concerns\EncryptsSensitiveHistory;

    public function show(Request $request, BuyerReturnRecovery $recovery, BuyerReturnRecoveryService $service): Response
    {
        $this->encryptSensitiveHistory($request);

        return Inertia::render('Returns/Recovery', ['recovery' => $service->present($request->user(), $recovery)]);
    }

    public function command(Request $request, BuyerReturnRecovery $recovery, string $action, BuyerReturnRecoveryService $service): JsonResponse|RedirectResponse
    {
        try {
            $saved = $service->command($request->user(), $recovery, $action, $request->only(['version', 'request_token', 'notes', 'source_token', 'barcode']), $request->file('photo'));
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409) : back()->withErrors(['recovery' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['recovery' => $saved->only(['id', 'reference', 'state', 'version', 'leg_index'])])
            : redirect('/buyer-return-recoveries/'.$saved->id)->with('success', 'The actual return-recovery update was saved.');
    }
}
