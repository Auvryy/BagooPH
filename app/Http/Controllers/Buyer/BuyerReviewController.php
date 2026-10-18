<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Commerce\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BuyerReviewController extends Controller
{
    public function store(Request $request, ReviewService $reviews): RedirectResponse
    {
        $reviews->create($request->user(), $request->all());

        return back()->with('success', 'Your verified purchase review has been saved.');
    }

    public function update(Request $request, Review $review, ReviewService $reviews): RedirectResponse
    {
        try {
            $reviews->edit($request->user(), $review, $request->all());
        } catch (ValidationException|\Illuminate\Auth\Access\AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable $error) {
            Log::warning('Buyer review edit could not be saved.', ['review_id' => $review->id, 'exception' => $error::class]);

            return back()->withErrors(['rating' => 'We could not save your edit. Your review is unchanged; try again.']);
        }

        return back()->with('success', 'Your review has been updated.');
    }
}
