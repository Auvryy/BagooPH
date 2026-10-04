<?php

namespace App\Http\Controllers;

use App\Models\KycDecision;
use App\Models\User;
use App\Services\KycDecisionService;
use App\Services\VerificationDocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationDocumentController extends Controller
{
    public function show(Request $request, User $user, string $document, VerificationDocumentService $documents): StreamedResponse
    {
        $documents->authorize($request->user(), $user);
        $decision = null;
        if ($request->has('decision')) {
            $validated = $request->validate(['decision' => 'required|integer|min:1']);
            $decision = KycDecision::where('user_id', $user->id)->findOrFail($validated['decision']);
        }
        $response = $documents->show($request->user(), $user, $document, $decision);
        if (! $decision) {
            app(KycDecisionService::class)->recordInspection($request, $user, $document);
        }

        return $response;
    }
}
