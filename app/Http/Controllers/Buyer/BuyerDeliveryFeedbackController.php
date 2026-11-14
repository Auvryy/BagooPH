<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\ModerationSignal;
use App\Models\Review;
use App\Services\BuyerAccessService;
use App\Services\Commerce\CourierFeedbackService;
use App\Services\Commerce\ReviewService;
use App\Services\Governance\ModerationSignalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BuyerDeliveryFeedbackController extends Controller
{
    public function store(Request $request, Delivery $delivery, CourierFeedbackService $service): JsonResponse|RedirectResponse
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($request->user());

        try {
            $feedback = $service->submit($buyer, $delivery, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['feedback' => $feedback], 201)
                : back()->with('success', 'Thank you! Your delivery & courier feedback has been saved separately from product ratings.');
        } catch (ValidationException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['errors' => $e->errors()], 422)
                : back()->withErrors($e->errors());
        }
    }

    public function appealReview(Request $request, Review $review, ReviewService $reviews): JsonResponse|RedirectResponse
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($request->user());

        try {
            $dispute = $reviews->appealDispute($buyer, $review, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['dispute' => $dispute], 201)
                : back()->with('success', "Your review appeal {$dispute->reference} has been submitted for independent review.");
        } catch (ValidationException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['errors' => $e->errors()], 422)
                : back()->withErrors($e->errors());
        }
    }

    public function submitSafetyCheckin(Request $request, ModerationSignal $signal, ModerationSignalService $service): JsonResponse|RedirectResponse
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($request->user());

        try {
            $updated = $service->submitBuyerCheckin($buyer, $signal, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['signal' => $updated])
                : back()->with('success', 'Thank you for completing the Bagoo safety check-in.');
        } catch (ValidationException $e) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['errors' => $e->errors()], 422)
                : back()->withErrors($e->errors());
        }
    }
}
