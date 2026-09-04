<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
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

    public function confirmReceived(Request $request, Order $order): RedirectResponse
    {
        if ($order->buyer_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }

        if (! in_array($order->status, ['delivered', 'completed'], true)) {
            return back()->with('error', 'Only delivered orders can be confirmed as received.');
        }

        if ($order->status !== 'completed') {
            $order->update(['status' => 'completed']);

            if ($order->delivery) {
                DeliveryCheckpoint::create([
                    'delivery_id' => $order->delivery->id,
                    'checkpoint_type' => 'buyer_completed',
                    'location_name' => 'Buyer Destination',
                    'barcode_scanned' => $order->delivery->tracking_number,
                    'notes' => 'Buyer confirmed receipt of order. Transaction completed.',
                    'scanned_by_id' => $request->user()->id,
                ]);
            }
        }

        return back()->with('success', 'Order confirmed as received! Thank you for shopping with Bagoo.');
    }
}
