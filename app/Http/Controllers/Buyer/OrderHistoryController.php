<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Services\BuyerAccessService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrderHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $buyer = app(BuyerAccessService::class)->requireExistingOrders($request->user());

        return Inertia::render('Buyer/Orders', [
            'orders' => Order::where('buyer_id', $buyer->id)->latest('id')->paginate(12)
                ->through(fn (Order $order) => $order->only(['id', 'order_number', 'status', 'total_amount', 'created_at'])),
            'canUsePortal' => $buyer->canAccessPortal(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $access = app(BuyerAccessService::class);
        $user = $access->current($request->user());
        $ownedBuyerOrder = $access->canAccessExistingOrders($user) && $order->buyer_id === $user->id;
        $adminOversight = $user->isAdmin() && $user->canAccessPortal();
        abort_unless($ownedBuyerOrder || $adminOversight, 403);

        $order->load(['items.product.shop', 'delivery.courier']);

        return Inertia::render('Buyer/OrderDetail', [
            'order' => $order,
            'canUsePortal' => $user->isBuyer() && $user->canAccessPortal(),
            'canConfirmReceipt' => $ownedBuyerOrder && $order->status === 'delivered' && ($order->delivery?->status === 'delivered'
                || ($order->delivery?->status === 'customer_collected' && app(PickupClaimService::class)->hasCollectionEvidence($order->delivery))),
            'pickupClaim' => $ownedBuyerOrder && $order->delivery ? PickupClaim::where('delivery_id', $order->delivery->id)->first()?->only(['reference', 'status', 'expires_at', 'code_issued_at', 'locked_until']) : null,
            'pickupHub' => $ownedBuyerOrder && $order->delivery ? $order->delivery->destinationBayanHub?->only(['name', 'address', 'operating_hours']) : null,
            'pickupCodeUrl' => $ownedBuyerOrder ? route('buyer.orders.pickup-code', $order, absolute: false) : null,
            'orderNotices' => $ownedBuyerOrder ? $user->notifications()->where('data->order_id', $order->id)->latest()->get()->map(fn ($notice) => ['id' => $notice->id, 'data' => $notice->data, 'read_at' => $notice->read_at, 'created_at' => $notice->created_at]) : [],
        ]);
    }

    public function readNotice(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requireExistingOrders($request->user());
        $notice = $user->notifications()->whereKey($notification)->firstOrFail();
        $order = Order::findOrFail($notice->data['order_id']);
        app(BuyerAccessService::class)->requireExistingOrders($user, $order);
        $notice->markAsRead();

        return $request->expectsJson() ? response()->json(['read' => true]) : back();
    }

    public function confirmReceived(Request $request, Order $order): RedirectResponse
    {
        $buyer = app(BuyerAccessService::class)->current($request->user());
        if (! $buyer->isBuyer() || $order->buyer_id !== $buyer->id) {
            abort(403);
        }
        app(BuyerAccessService::class)->requireExistingOrders($buyer, $order);

        try {
            app(OrderLifecycleService::class)->buyerComplete($order, $buyer);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Order confirmed as received! Thank you for shopping with Bagoo.');
    }
}
