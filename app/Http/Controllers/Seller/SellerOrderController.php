<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SellerOrderController extends Controller
{
    private function getShop(Request $request): Shop
    {
        return Shop::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'name' => $request->user()->name . "'s Store",
                'slug' => \Illuminate\Support\Str::slug($request->user()->name . '-store-' . $request->user()->id),
                'status' => 'active',
            ]
        );
    }

    public function index(Request $request): Response
    {
        $shop = $this->getShop($request);
        $status = $request->input('status', 'all');

        $query = OrderItem::where('shop_id', $shop->id)
            ->with(['order.buyer', 'order.delivery', 'product.category'])
            ->latest();

        if ($status === 'to_pack') {
            $query->whereHas('order', fn($q) => $q->whereIn('status', ['pending', 'processing']));
        } elseif ($status === 'to_pickup') {
            $query->whereHas('order', fn($q) => $q->where('status', 'ready_for_pickup'));
        } elseif ($status === 'in_transit') {
            $query->whereHas('order', fn($q) => $q->where('status', 'shipped'));
        } elseif ($status === 'delivered') {
            $query->whereHas('order', fn($q) => $q->where('status', 'delivered'));
        }

        $orderItems = $query->paginate(15)->withQueryString();

        return Inertia::render('Seller/Orders', [
            'orderItems' => $orderItems,
            'shop' => $shop,
            'currentStatus' => $status,
        ]);
    }

    public function pack(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        // IDOR Protection: Verify this order contains items for this seller's shop
        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'processing']);
        return back()->with('success', "Order #{$order->order_number} marked as Packed. Ready to schedule courier pickup.");
    }

    public function readyForPickup(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        // IDOR Protection: Verify this order contains items for this seller's shop
        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'ready_for_pickup']);
        
        // Ensure Delivery record exists and is set to unassigned / ready for pickup
        if ($order->delivery) {
            $order->delivery->update([
                'status' => 'unassigned',
                'pickup_store_name' => $shop->name,
                'pickup_address' => ($shop->address ?? 'Artisan District') . ', ' . ($shop->city ?? 'Metro Manila'),
            ]);
            $delivery = $order->delivery;
        } else {
            $delivery = Delivery::create([
                'order_id' => $order->id,
                'tracking_number' => 'BGO-' . strtoupper(\Illuminate\Support\Str::random(10)),
                'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                'status' => 'unassigned',
                'pickup_store_name' => $shop->name,
                'pickup_address' => ($shop->address ?? 'Artisan District') . ', ' . ($shop->city ?? 'Metro Manila'),
                'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Customer',
                'delivery_address' => ($order->shipping_address ?? 'Customer Address') . ', ' . ($order->shipping_city ?? 'Metro Manila'),
                'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
            ]);
        }

        \App\Models\DeliveryCheckpoint::firstOrCreate(
            ['delivery_id' => $delivery->id, 'checkpoint_type' => 'seller_pack'],
            [
                'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                'barcode_scanned' => $delivery->tracking_number,
                'notes' => 'Seller approved and packed order into shipping parcel',
                'scanned_by_id' => $request->user()->id,
            ]
        );

        return back()->with('success', "Pickup scheduled! Courier dispatched to collect Order #{$order->order_number}.");
    }
}
