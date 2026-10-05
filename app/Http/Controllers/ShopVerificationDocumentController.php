<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Services\ShopReviewService;
use App\Services\VerificationDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShopVerificationDocumentController extends Controller
{
    public function show(Request $request, Shop $shop, string $document, ShopReviewService $reviews, VerificationDocumentService $documents): StreamedResponse
    {
        $owner = $shop->user()->firstOrFail();
        $documents->authorize($request->user(), $owner);
        abort_unless(preg_match('/\A(id|permit)\.(jpg|jpeg|png|webp|pdf)\z/', $document, $matches), 404);
        $decision = null;
        if ($request->has('decision')) {
            $data = $request->validate(['decision' => 'required|integer|min:1']);
            $decision = ShopReviewDecision::where('shop_id', $shop->id)->where('seller_id', $owner->id)->findOrFail($data['decision']);
        }
        $submission = $decision?->submission ?? $reviews->submission($shop);
        $path = $submission['documents'][$matches[1]]['path'] ?? null;
        if ($decision) {
            abort_unless($path && preg_match('/\Akyc_documents\/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp|pdf)\z/i', $path)
                && Storage::disk('local')->exists($path), 404);
            abort_unless(hash_file('sha256', Storage::disk('local')->path($path)) === ($submission['documents'][$matches[1]]['sha256'] ?? null), 409, 'The reviewed document is no longer intact.');
        }
        $evidenceOwner = clone $owner;
        $evidenceOwner->setRawAttributes(array_replace($owner->getAttributes(), [VerificationDocumentService::FIELDS[$matches[1]] => $path]), true);
        $response = $documents->show($request->user(), $evidenceOwner, $document);
        if (! $decision) {
            $reviews->inspect($request, $shop, $document, $submission);
        }

        return $response;
    }
}
