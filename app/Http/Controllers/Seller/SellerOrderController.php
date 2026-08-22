<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $shop = Shop::where('user_id', $user->id)->first();

        $orderItems = OrderItem::where('shop_id', $shop?->id ?? 0)
            ->with(['order.buyer', 'order.delivery', 'product'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Seller/Orders', [
            'orderItems' => $orderItems,
            'shop' => $shop,
        ]);
    }

    public function readyForPickup(Request $request, Order $order): RedirectResponse
    {
        $order->update(['status' => 'ready_for_pickup']);
        if ($order->delivery) {
            $order->delivery->update(['status' => 'unassigned']);
        }

        return back()->with('success', 'Order marked ready for courier pickup.');
    }
}
