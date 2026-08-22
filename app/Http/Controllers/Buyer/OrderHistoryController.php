<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $orders = Order::where('buyer_id', $request->user()->id)
            ->with(['items.product', 'delivery.courier'])
            ->latest()
            ->paginate(10);

        return Inertia::render('Buyer/Orders', [
            'orders' => $orders,
        ]);
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
}
