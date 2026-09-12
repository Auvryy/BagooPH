<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                'slug' => Str::slug($request->user()->name . '-store-' . $request->user()->id),
                'status' => 'active',
            ]
        );
    }

    public function index(Request $request): Response
    {
        $shop = $this->getShop($request);
        $status = $request->input('status', 'all');

        $baseItemQuery = fn() => OrderItem::where('shop_id', $shop->id);

        $counts = [
            'all' => $baseItemQuery()->count(),
            'to_pack' => $baseItemQuery()->whereHas('order', fn($q) => $q->whereIn('status', ['placed', 'pending', 'confirmed', 'preparing', 'processing']))->count(),
            'to_pickup' => $baseItemQuery()->whereHas('order', fn($q) => $q->where('status', 'ready_for_pickup'))->count(),
            'in_transit' => $baseItemQuery()->whereHas('order', fn($q) => $q->whereIn('status', ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped']))->count(),
            'delivered' => $baseItemQuery()->whereHas('order', fn($q) => $q->whereIn('status', ['delivered', 'completed']))->count(),
            'cancelled' => $baseItemQuery()->whereHas('order', fn($q) => $q->whereIn('status', ['cancelled', 'returned', 'delivery_failed']))->count(),
        ];

        $query = OrderItem::where('shop_id', $shop->id)
            ->with([
                'order.buyer' => function ($q) {
                    $q->withCount([
                        'orders',
                        'orders as completed_orders_count' => function ($cq) {
                            $cq->whereIn('status', ['delivered', 'completed']);
                        },
                    ]);
                },
                'order.delivery.checkpoints',
                'order.items.product',
                'product.category',
            ])
            ->latest();

        if ($status === 'to_pack') {
            $query->whereHas('order', fn($q) => $q->whereIn('status', ['placed', 'pending', 'confirmed', 'preparing', 'processing']));
        } elseif ($status === 'to_pickup') {
            $query->whereHas('order', fn($q) => $q->where('status', 'ready_for_pickup'));
        } elseif ($status === 'in_transit') {
            $query->whereHas('order', fn($q) => $q->whereIn('status', ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped']));
        } elseif ($status === 'delivered') {
            $query->whereHas('order', fn($q) => $q->whereIn('status', ['delivered', 'completed']));
        } elseif ($status === 'cancelled') {
            $query->whereHas('order', fn($q) => $q->whereIn('status', ['cancelled', 'returned', 'delivery_failed']));
        }

        $orderItems = $query->paginate(10)->withQueryString();

        return Inertia::render('Seller/Orders', [
            'orderItems' => $orderItems,
            'shop' => $shop,
            'currentStatus' => $status,
            'counts' => $counts,
        ]);
    }

    public function accept(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'confirmed']);

        return back()->with('success', "Order #{$order->order_number} confirmed. You may now pack the items.");
    }

    public function pack(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'preparing']);

        if (! $order->delivery) {
            $delivery = Delivery::create([
                'order_id' => $order->id,
                'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
                'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                'status' => 'unassigned',
                'pickup_store_name' => $shop->name,
                'pickup_address' => ($shop->address ?? 'Artisan District') . ', ' . ($shop->city ?? 'Metro Manila'),
                'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Customer',
                'delivery_address' => ($order->shipping_address ?? 'Customer Address') . ', ' . ($order->shipping_city ?? 'Metro Manila'),
                'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
            ]);
        } else {
            $delivery = $order->delivery;
        }

        DeliveryCheckpoint::firstOrCreate(
            ['delivery_id' => $delivery->id, 'checkpoint_type' => 'seller_pack'],
            [
                'location_name' => $shop->name ?? 'Merchant Store',
                'barcode_scanned' => $delivery->tracking_number,
                'notes' => 'Seller packed items into parcel and prepared waybill',
                'scanned_by_id' => $request->user()->id,
            ]
        );

        return back()->with('success', "Order #{$order->order_number} marked as packed. Ready to schedule courier pickup.");
    }

    public function readyForPickup(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'ready_for_pickup']);
        
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
                'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
                'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                'status' => 'unassigned',
                'pickup_store_name' => $shop->name,
                'pickup_address' => ($shop->address ?? 'Artisan District') . ', ' . ($shop->city ?? 'Metro Manila'),
                'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Customer',
                'delivery_address' => ($order->shipping_address ?? 'Customer Address') . ', ' . ($order->shipping_city ?? 'Metro Manila'),
                'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
            ]);
        }

        DeliveryCheckpoint::firstOrCreate(
            ['delivery_id' => $delivery->id, 'checkpoint_type' => 'seller_pack'],
            [
                'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                'barcode_scanned' => $delivery->tracking_number,
                'notes' => 'Seller packed parcel and requested courier pickup',
                'scanned_by_id' => $request->user()->id,
            ]
        );

        DeliveryCheckpoint::firstOrCreate(
            ['delivery_id' => $delivery->id, 'checkpoint_type' => 'ready_for_pickup'],
            [
                'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                'barcode_scanned' => $delivery->tracking_number,
                'notes' => 'Parcel staged for courier collection',
                'scanned_by_id' => $request->user()->id,
            ]
        );

        return back()->with('success', "Pickup scheduled! Courier dispatched to collect Order #{$order->order_number}.");
    }

    public function handover(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $order->update(['status' => 'picked_up']);

        if ($order->delivery) {
            $order->delivery->update([
                'status' => 'picked_up',
                'picked_up_at' => now(),
            ]);

            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $order->delivery->id, 'checkpoint_type' => 'courier_pickup'],
                [
                    'location_name' => $order->delivery->pickup_store_name ?? $shop->name ?? 'Merchant Store',
                    'barcode_scanned' => $order->delivery->tracking_number,
                    'notes' => 'Merchant handed parcel to courier driver',
                    'scanned_by_id' => $request->user()->id,
                ]
            );
        }

        return back()->with('success', "Order #{$order->order_number} confirmed handed over to courier.");
    }

    public function batchReady(Request $request): RedirectResponse
    {
        $shop = $this->getShop($request);

        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::whereIn('id', $validated['order_ids'])->get();
        $processedCount = 0;

        foreach ($orders as $order) {
            $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
            if (! $hasItems && ! $request->user()->isAdmin()) {
                continue;
            }

            $order->update(['status' => 'ready_for_pickup']);

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
                    'tracking_number' => 'BGO-' . strtoupper(Str::random(10)),
                    'logistics_partner' => 'Bagoo Express Dispatch Fleet',
                    'status' => 'unassigned',
                    'pickup_store_name' => $shop->name,
                    'pickup_address' => ($shop->address ?? 'Artisan District') . ', ' . ($shop->city ?? 'Metro Manila'),
                    'delivery_recipient_name' => $order->recipient_name ?? $order->buyer?->name ?? 'Customer',
                    'delivery_address' => ($order->shipping_address ?? 'Customer Address') . ', ' . ($order->shipping_city ?? 'Metro Manila'),
                    'delivery_phone' => $order->recipient_phone ?? $order->buyer?->phone ?? '+63 900 000 0000',
                ]);
            }

            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $delivery->id, 'checkpoint_type' => 'seller_pack'],
                [
                    'location_name' => $delivery->pickup_store_name ?? 'Merchant Store',
                    'barcode_scanned' => $delivery->tracking_number,
                    'notes' => 'Seller batch-packed parcel and requested courier pickup',
                    'scanned_by_id' => $request->user()->id,
                ]
            );

            $processedCount++;
        }

        return back()->with('success', "{$processedCount} orders scheduled for courier pickup.");
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reasonText = $validated['reason'] . ($validated['notes'] ? ': ' . $validated['notes'] : '');
        $order->update([
            'status' => 'cancelled',
            'notes' => $reasonText,
            'cancellation_reason' => $reasonText,
        ]);

        if ($order->delivery) {
            $order->delivery->update(['status' => 'cancelled']);
        }

        return back()->with('success', "Order #{$order->order_number} has been cancelled.");
    }
}
