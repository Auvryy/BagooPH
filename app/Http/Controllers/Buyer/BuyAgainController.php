<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\BuyAgainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuyAgainController extends Controller
{
    public function show(Request $request, Order $order, BuyAgainService $buyAgain): RedirectResponse
    {
        $buyAgain->requestToken($request->user(), $order);

        return redirect()->route('buyer.orders.show', $order);
    }

    public function store(Request $request, Order $order, BuyAgainService $buyAgain): JsonResponse|RedirectResponse
    {
        $result = $buyAgain->add($request->user(), $order, $request->all());

        return $request->expectsJson() ? response()->json($result) : redirect()->route('cart.index')
            ->with('buy_again_selection', array_values(array_unique(array_column($result['result']['items'], 'cart_item_id'))))
            ->with('success', $result['replayed']
            ? 'This selection was already added. Your current Shopping Bag is shown.'
            : 'Items were added and selected in your Bag at current prices. Matching items have an increased quantity. Review your Bag before checkout.');
    }
}
