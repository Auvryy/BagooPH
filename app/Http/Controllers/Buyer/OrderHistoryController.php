<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\BuyerAccessService;
use App\Services\Commerce\ReviewService;
use App\Services\Commerce\BuyAgainService;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderTrackingService;
use App\Services\Orders\OrderWorkspaceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OrderHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $buyer = app(BuyerAccessService::class)->requireOrderHistory($request->user());

        if ($buyer->canAccessPortal() || ! $buyer->isKycApproved()) {
            $request->query->set('tab', 'orders');

            return app(BuyerProfileController::class)->index($request);
        }

        $workspace = app(OrderWorkspaceService::class);
        $status = $workspace->selection($request, 'buyer', 'order_status');
        $owned = Order::where('buyer_id', $buyer->id);

        return Inertia::render('Buyer/Orders', [
            'orderCounts' => $workspace->counts($owned, 'buyer'),
            'currentOrderStatus' => $status,
            'orders' => $workspace->paginate($workspace->stable($workspace->filter(clone $owned, 'buyer', $status))
                ->with(OrderTrackingService::relations(history: false)), 12)
                ->through(fn (Order $order) => [
                    ...$order->only(['id', 'order_number', 'status', 'total_amount', 'created_at']),
                    'tracking' => app(OrderTrackingService::class)->present($order, history: false),
                ]),
            'canUsePortal' => $buyer->canAccessPortal(),
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        $access = app(BuyerAccessService::class);
        $user = $access->current($request->user());
        $ownedBuyerOrder = $access->canViewOrderHistory($user) && $order->buyer_id === $user->id;
        $canFulfillOrder = $ownedBuyerOrder && $access->canAccessExistingOrders($user);
        $adminOversight = $user->isAdmin() && $user->canAccessPortal();
        abort_unless($ownedBuyerOrder || $adminOversight, 403);

        $order->load(['items.product.shop', 'items.review', 'delivery.courier', ...OrderTrackingService::relations()]);
        if ($ownedBuyerOrder && $user->canAccessPortal()) {
            foreach ($order->items as $item) {
                if ($item->review) {
                    $editable = app(ReviewService::class)->canEdit($item->review);
                    $item->review->setAttribute('edit_url', $editable ? route('buyer.reviews.update', $item->review, absolute: false) : null);
                }
            }
        }
        $tracking = app(OrderTrackingService::class);
        $canConfirmReceipt = $canFulfillOrder && app(OrderWorkspaceService::class)->canConfirmReceipt($order, $user);
        $pickupClaim = $canFulfillOrder ? $order->delivery?->pickupClaim?->only(['reference', 'status', 'expires_at', 'code_issued_at', 'locked_until']) : null;
        $pickupHub = $ownedBuyerOrder ? $order->delivery?->destinationBayanHub?->only(['name', 'address', 'operating_hours']) : null;
        $pickupCodeAvailable = $canFulfillOrder && $tracking->canShowPickupCode($order);
        $tracking->attach($order);

        return Inertia::render('Buyer/OrderDetail', [
            'order' => $order,
            'canUsePortal' => $user->isBuyer() && $user->canAccessPortal(),
            'buyAgainUrl' => $ownedBuyerOrder && $user->canAccessPortal() && $order->status === 'completed'
                ? route('buyer.orders.buy-again', $order, absolute: false) : null,
            'buyAgainToken' => $ownedBuyerOrder && $user->canAccessPortal() && $order->status === 'completed'
                ? app(BuyAgainService::class)->requestToken($user, $order) : null,
            'canConfirmReceipt' => $canConfirmReceipt,
            'cancelOrderUrl' => $ownedBuyerOrder && app(OrderLifecycleService::class)->canBuyerCancel($order, $user)
                ? route('buyer.orders.cancel', $order, absolute: false) : null,
            'cancellationReasons' => OrderLifecycleService::BUYER_CANCELLATION_REASONS,
            'cancellationReason' => $ownedBuyerOrder && $order->status === 'cancelled'
                ? $order->delivery?->checkpoints()->where('checkpoint_type', 'cancelled')->value('notes') ?? $order->notes : null,
            'pickupClaim' => $pickupClaim,
            'pickupHub' => $pickupHub,
            'pickupCodeAvailable' => $pickupCodeAvailable,
            'pickupCodeUrl' => $canFulfillOrder ? route('buyer.orders.pickup-code', $order, absolute: false) : null,
            'orderNotices' => $ownedBuyerOrder ? $user->notifications()->whereIn('type', NotificationCenterService::TYPES)
                ->where('data->order_id', $order->id)->latest()->get()
                ->map(fn ($notice) => app(NotificationCenterService::class)->present($notice, $user)) : [],
        ]);
    }

    public function readNotice(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requireExistingOrders($request->user());
        $notice = $user->notifications()->whereKey($notification)->firstOrFail();
        $order = Order::findOrFail($notice->data['order_id'] ?? 0);
        app(BuyerAccessService::class)->requireExistingOrders($user, $order);
        app(NotificationCenterService::class)->acknowledge($user, $notice->id);

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

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        try {
            app(OrderLifecycleService::class)->cancelByBuyer($order, $request->user(), $request->all());
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Buyer cancellation could not be saved.', ['order_id' => $order->id, 'exception' => $error::class]);

            return back()->withErrors(['reason' => 'We could not cancel this order. Your order is unchanged; try again.']);
        }

        return back()->with('success', 'Your order is cancelled. Its delivery will not proceed.');
    }
}
