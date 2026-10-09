<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Shop;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderWorkspaceService;
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
        $workspace = app(OrderWorkspaceService::class);
        $status = $workspace->selection($request, 'seller');
        $owned = Order::whereHas('items', fn ($items) => $items->where('shop_id', $shop->id));
        $counts = $workspace->counts($owned, 'seller');
        $eligible = app(ShopEligibilityService::class)->isEligible($shop);
        $query = $workspace->stable($workspace->filter(clone $owned, 'seller', $status))
            ->withCount('items')->withCount(['items as owned_items_count' => fn ($items) => $items->where('shop_id', $shop->id)])
            ->with([
                'buyer' => fn ($buyer) => $buyer->select(['id', 'name', 'avatar', 'kyc_status'])->withCount([
                    'orders' => fn ($orders) => $orders->whereHas('items', fn ($items) => $items->where('shop_id', $shop->id)),
                    'orders as completed_orders_count' => fn ($orders) => $orders->where('status', 'completed')
                        ->whereHas('items', fn ($items) => $items->where('shop_id', $shop->id)),
                ]),
                'delivery.checkpoints',
                'commissionLedger' => fn ($ledger) => $ledger->where('seller_id', $shop->user_id),
                'items' => fn ($items) => $items->where('shop_id', $shop->id)->with('product.category')->orderBy('id'),
            ]);
        $orders = $workspace->paginate($query, 10);
        foreach ($orders as $order) {
            $fullyOwned = (int) $order->items_count > 0 && (int) $order->items_count === (int) $order->owned_items_count;
            $order->setAttribute('has_mixed_shops', ! $fullyOwned);
            foreach (app(OrderLifecycleService::class)->sellerActionFlags($order, $eligible, $fullyOwned) as $flag => $value) {
                $order->setAttribute($flag, $value);
            }
            $delivery = $order->delivery;
            if ($delivery && in_array($delivery->status, OrderWorkspaceService::RETURN_CUSTODY, true)) {
                $returns = app(DeliveryReturnService::class);
                try {
                    $route = $returns->route($delivery);
                    $delivery->setAttribute('return_route_reference', $route->reference);
                    $delivery->setAttribute('return_ready_for_receipt', $fullyOwned && $returns->readyForSeller($delivery) && (bool) $returns->event($route, 'seller_staged'));
                } catch (DomainException $error) {
                    $delivery->setAttribute('return_ready_for_receipt', false);
                }
            }
        }

        return Inertia::render('Seller/Orders', [
            'orders' => $orders, 'shopEligible' => $eligible, 'shop' => $shop,
            'currentStatus' => $status, 'counts' => $counts,
            'returnReceiptToken' => (string) Str::uuid(),
            'cancellationReasons' => OrderLifecycleService::SELLER_CANCELLATION_REASONS,
            'batchLimit' => OrderLifecycleService::BATCH_LIMIT,
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
        try {
            $count = app(OrderLifecycleService::class)->sellerBatchReady($request->only('order_ids'), $this->getShop($request), $request->user());
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$count} orders scheduled for courier pickup.");
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $shop = $this->getShop($request);

        try {
            app(OrderLifecycleService::class)->cancelBySeller($order, $shop, $request->user(), $request->only(['reason', 'notes']));
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
