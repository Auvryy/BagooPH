<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\BuyAgainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BuyAgainController extends Controller
{
    public function show(Request $request, Order $order, BuyAgainService $buyAgain): Response
    {
        return Inertia::render('Buyer/BuyAgain', [...$buyAgain->preview($request->user(), $order),
            'submitUrl' => route('buyer.orders.buy-again.store', $order, absolute: false),
            'bagUrl' => route('cart.index', absolute: false),
        ]);
    }

    public function store(Request $request, Order $order, BuyAgainService $buyAgain): JsonResponse|RedirectResponse
    {
        $result = $buyAgain->add($request->user(), $order, $request->all());

        return $request->expectsJson() ? response()->json($result) : redirect()->route('cart.index')->with('success', $result['replayed']
            ? 'This selection was already added. Your current Shopping Bag is shown.'
            : 'Selected items were added at current prices. Review your Shopping Bag before checkout.');
    }
}
