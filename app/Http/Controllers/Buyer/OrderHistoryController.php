<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        return app(BuyerProfileController::class)->index($request);
    }

    public function show(Request $request, Order $order): Response
    {
        if ($order->buyer_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        $order->load(['items.product.shop', 'delivery.courier']);

        return Inertia::render('Buyer/OrderDetail', [
            'order' => $order,
        ]);
    }

    public function confirmReceived(Request $request, Order $order): RedirectResponse
    {
        if ($order->buyer_id !== $request->user()->id) {
            abort(403);
        }

        try {
            app(OrderLifecycleService::class)->buyerComplete($order, $request->user());
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order confirmed as received! Thank you for shopping with Bagoo.');
    }
}
