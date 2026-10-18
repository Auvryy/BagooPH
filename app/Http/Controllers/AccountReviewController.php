<?php

namespace App\Http\Controllers;

use App\Models\AccountReviewRequest;
use App\Services\AccountReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AccountReviewController extends Controller
{
    public function status(Request $request, AccountReviewService $reviews): Response
    {
        return Inertia::render('Auth/AccountStatus', ['status' => $reviews->presentation($request->user())]);
    }

    public function store(Request $request, AccountReviewService $reviews): JsonResponse|RedirectResponse
    {
        try {
            $result = $reviews->submit($request);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409 || $request->wantsJson()) {
                throw $exception;
            }

            return back()->withErrors(['message' => $exception->getMessage()]);
        }

        return $request->wantsJson() ? response()->json(['id' => $result->id, 'status' => $result->status])
            : redirect()->route('account.status')->with('success', 'Your review request was sent. An admin will review it; your current access stays unchanged.');
    }

    public function index(Request $request, AccountReviewService $reviews): Response
    {
        $status = $request->validate(['status' => 'nullable|string|in:pending,accepted,declined,superseded,all'])['status'] ?? 'pending';

        return Inertia::render('Admin/AccountReviews', ['requests' => $reviews->listing($request->user(), $status), 'filter' => $status]);
    }

    public function show(Request $request, AccountReviewRequest $reviewRequest, AccountReviewService $reviews): Response
    {
        return Inertia::render('Admin/AccountReview', ['review' => $reviews->review($request->user(), $reviewRequest),
            'endpoint' => $request->getPathInfo().'/decision']);
    }

    public function decide(Request $request, AccountReviewRequest $reviewRequest, AccountReviewService $reviews): JsonResponse|RedirectResponse
    {
        try {
            $decision = $reviews->decide($request, $reviewRequest);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409 || $request->wantsJson()) {
                throw $exception;
            }

            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return $request->wantsJson() ? response()->json(['decision_id' => $decision->id, 'message' => 'Your review decision was saved.'])
            : back()->with('success', 'Your review decision was saved. The owner can see the outcome in Account status.');
    }
}
