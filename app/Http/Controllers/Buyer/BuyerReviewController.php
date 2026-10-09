<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Services\Commerce\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuyerReviewController extends Controller
{
    public function store(Request $request, ReviewService $reviews): RedirectResponse
    {
        $reviews->create($request->user(), $request->all());

        return back()->with('success', 'Your verified purchase review has been saved.');
    }
}
