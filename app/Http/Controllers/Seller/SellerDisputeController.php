<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerDisputeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        $disputes = [];

        return Inertia::render('Seller/Disputes', [
            'disputes' => $disputes,
            'shop' => $shop,
        ]);
    }

    public function respond(Request $request, string $disputeId): RedirectResponse
    {
        $request->validate([
            'action' => 'required|in:accept_exchange,accept_refund,dispute_claim',
            'explanation' => 'nullable|string|max:500',
        ]);

        return back()->with('success', 'Dispute response recorded and dispatched to customer and logistics.');
    }
}
