<?php

namespace App\Http\Controllers;

use App\Models\Advertisement;
use App\Models\AdvertisementRevision;
use App\Services\Advertisements\AdvertisementManager;
use App\Services\Advertisements\PromotionContentAssistant;
use App\Services\Advertisements\SellerPromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class PromotionContentAssistantController extends Controller
{
    public function seller(Request $request, PromotionContentAssistant $assistant, SellerPromotionService $promotions, ?string $advertisement = null): JsonResponse
    {
        $promotions->authorize($request->user());
        $input = $this->input($request);
        $revision = $advertisement
            ? $promotions->find($request->user(), (int) $advertisement)->latestRevision()->with('asset')->first()
            : null;

        return $this->respond($assistant, $input, $revision);
    }

    public function admin(Request $request, PromotionContentAssistant $assistant, AdvertisementManager $manager, ?string $advertisement = null): JsonResponse
    {
        $actor = $manager->authorize($request->user());
        abort_unless($actor->isAdmin(), 403);
        $input = $this->input($request);
        $revision = null;
        if ($advertisement !== null) {
            $record = Advertisement::whereKey((int) $advertisement)->firstOrFail();
            if (isset($input['submission_id'])) {
                abort_unless($input['action'] === 'check' && $record->shop_id !== null, 422);
                $submission = $record->submissions()->with(['revision.asset'])->findOrFail((int) $input['submission_id']);
                abort_unless($submission->state() === 'pending', 409, 'Only the current pending seller submission can be checked.');
                // A moderation check must inspect the immutable submitted artwork,
                // even if a client includes a different upload in its request.
                $input['image'] = null;
                $revision = $submission->revision;
                $input['title'] = $revision->title;
                $input['alt_text'] = $revision->alt_text;
                $input['summary'] = $revision->summary;
                $input['purpose'] = $revision->purpose;
            } else {
                abort_unless($record->shop_id === null, 404);
                $revision = $record->latestRevision()->with('asset')->first();
            }
        } elseif (isset($input['submission_id'])) {
            abort(404);
        }

        return $this->respond($assistant, $input, $revision);
    }

    private function input(Request $request): array
    {
        return Validator::make($request->all(), [
            'action' => 'required|in:generate,improve,check',
            'title' => 'nullable|string|max:80',
            'alt_text' => 'nullable|string|max:180',
            'summary' => 'nullable|string|max:600',
            'purpose' => 'nullable|in:feature,event,offer',
            'submission_id' => 'nullable|integer|min:1|max:2147483647',
            'image' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp',
        ])->validate();
    }

    private function respond(PromotionContentAssistant $assistant, array $input, ?AdvertisementRevision $revision = null): JsonResponse
    {
        try {
            return response()->json($assistant->assist($input, $input['image'] ?? null, $revision));
        } catch (RuntimeException) {
            return response()->json(['message' => 'Gemini is unavailable right now. Your promotion draft is still available to save manually.'], 503);
        }
    }
}
