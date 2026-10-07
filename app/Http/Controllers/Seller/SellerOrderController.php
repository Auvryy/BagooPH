<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shop;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\ShopEligibilityService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SellerOrderController extends Controller
{
    use HasSellerShop;

    private function getShop(Request $request): Shop
    {
        return $this->getActiveShop($request);
    }

    public function index(Request $request): Response
    {
        $shop = $this->getActiveShop($request, history: true);
        $status = $request->input('status', 'all');

        $baseItemQuery = fn () => OrderItem::where('shop_id', $shop->id);

        $counts = [
            'all' => $baseItemQuery()->count(),
            'to_pack' => $baseItemQuery()->whereHas('order', fn ($q) => $q->whereIn('status', ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging']))->count(),
            'to_pickup' => $baseItemQuery()->whereHas('order', fn ($q) => $q->where('status', 'ready_for_pickup'))->count(),
            'in_transit' => $baseItemQuery()->whereHas('order', fn ($q) => $q->whereIn('status', ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped']))->count(),
            'delivered' => $baseItemQuery()->whereHas('order', fn ($q) => $q->whereIn('status', ['delivered', 'completed']))->count(),
            'cancelled' => $baseItemQuery()->whereHas('order', fn ($q) => $q->whereIn('status', ['cancelled', 'returned', 'delivery_failed']))->count(),
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
                'order.commissionLedger',
                'order.items.product',
                'product.category',
            ])
            ->latest();

        if ($status === 'to_pack') {
            $query->whereHas('order', fn ($q) => $q->whereIn('status', ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging']));
        } elseif ($status === 'to_pickup') {
            $query->whereHas('order', fn ($q) => $q->where('status', 'ready_for_pickup'));
        } elseif ($status === 'in_transit') {
            $query->whereHas('order', fn ($q) => $q->whereIn('status', ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped']));
        } elseif ($status === 'delivered') {
            $query->whereHas('order', fn ($q) => $q->whereIn('status', ['delivered', 'completed']));
        } elseif ($status === 'cancelled') {
            $query->whereHas('order', fn ($q) => $q->whereIn('status', ['cancelled', 'returned', 'delivery_failed']));
        }

        $orderItems = $query->paginate(10)->withQueryString();
        foreach ($orderItems->items() as $item) {
            $delivery = $item->order->delivery;
            if ($delivery && in_array($delivery->status, ['return_to_sender', 'return_in_transit', 'returned'], true)) {
                $returns = app(DeliveryReturnService::class);
                try {
                    $route = $returns->route($delivery);
                    $delivery->setAttribute('return_route_reference', $route->reference);
                    $delivery->setAttribute('return_ready_for_receipt', $returns->readyForSeller($delivery) && (bool) $returns->event($route, 'seller_staged'));
                } catch (DomainException $error) {
                    $delivery->setAttribute('return_ready_for_receipt', false);
                }
            }
        }

        return Inertia::render('Seller/Orders', [
            'orderItems' => $orderItems,
            'shopEligible' => app(ShopEligibilityService::class)->isEligible($shop),
            'shop' => $shop,
            'currentStatus' => $status,
            'counts' => $counts,
            'returnReceiptToken' => (string) Str::uuid(),
        ]);
    }

    public function receiveReturn(Request $request, Order $order): JsonResponse|RedirectResponse
    {
        abort_unless($order->delivery, 404);
        try {
            $parcel = app(DeliveryReturnService::class)->sellerReceive($order->delivery, $request->user(), $this->getShop($request),
                $request->only(['barcode', 'route_reference', 'notes', 'request_token']));
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409)
                : back()->withErrors(['return_receipt' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['status' => $parcel->status])
            : back()->with('success', 'Your original parcel receipt was recorded. The order is now returned.');
    }

    public function accept(Request $request, Order $order): RedirectResponse
    {
        return $this->transition($request, $order, 'confirmed', "Order #{$order->order_number} confirmed. You may now pack the items.");
    }

    public function pack(Request $request, Order $order): RedirectResponse
    {
        return $this->transition($request, $order, 'preparing', "Order #{$order->order_number} marked as packed. Attach the waybill before release.");
    }

    public function acceptAndPack(Request $request, Order $order): RedirectResponse
    {
        try {
            app(OrderLifecycleService::class)->sellerAcceptAndPack(
                $order,
                $this->getShop($request),
                $request->user(),
            );
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Order #{$order->order_number} confirmed and packed. It can now be marked ready for pickup.");
    }

    public function readyForPickup(Request $request, Order $order): RedirectResponse
    {
        return $this->transition($request, $order, 'ready_for_pickup', "Order #{$order->order_number} is ready for an eligible pickup rider.");
    }

    public function handover(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $hasItems = $order->items()->where('shop_id', $shop->id)->exists();
        if (! $hasItems && ! $request->user()->isAdmin()) {
            abort(403, 'Unauthorized action for this order.');
        }

        return back()->with('error', "Order #{$order->order_number} must be scanned as picked up by its assigned rider.");
    }

    public function batchReady(Request $request): RedirectResponse
    {
        $this->getShop($request);
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'integer|exists:orders,id',
        ]);

        $orders = Order::whereIn('id', $validated['order_ids'])->get();
        if ($orders->count() !== count(array_unique($validated['order_ids']))) {
            return back()->with('error', 'One or more selected orders no longer exist.');
        }
        $processedCount = 0;

        foreach ($orders as $order) {
            try {
                app(OrderLifecycleService::class)->sellerTransition(
                    $order,
                    $this->getShop($request),
                    $request->user(),
                    'ready_for_pickup'
                );
                $processedCount++;
            } catch (\RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        return back()->with('success', "{$processedCount} orders scheduled for courier pickup.");
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $reasonText = $validated['reason'].(! empty($validated['notes']) ? ': '.$validated['notes'] : '');

        try {
            app(OrderLifecycleService::class)->cancelBySeller($order, $shop, $request->user(), $reasonText);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Order #{$order->order_number} has been cancelled and stock released.");
    }

    private function transition(Request $request, Order $order, string $targetStatus, string $success): RedirectResponse
    {
        try {
            app(OrderLifecycleService::class)->sellerTransition(
                $order,
                $this->getShop($request),
                $request->user(),
                $targetStatus
            );
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', $success);
    }
}
