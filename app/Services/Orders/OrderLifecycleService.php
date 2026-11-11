<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\BuyerAccessService;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\SalePriceService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\ShopEligibilityService;
use App\Services\Vouchers\VoucherFundingService;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletReservationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderLifecycleService
{
    public function __construct(private readonly InventoryService $inventory, private readonly OrderCancellationService $cancellations) {}

    public const SELLER_CANCELLATION_REASONS = [
        'Out of stock / Inventory shortage',
        'Damaged item during inspection',
        'Pricing / Listing discrepancy',
        'Buyer requested cancellation via chat',
        'Unable to deliver to destination',
        'Other reason',
    ];

    public const BATCH_LIMIT = 50;

    public const BUYER_CANCELLATION_REASONS = [
        'Ordered by mistake', 'Need to change delivery details', 'Need to change an item or quantity',
        'No longer needed', 'Other reason',
    ];

    private const SELLER_TRANSITIONS = [
        'placed' => 'confirmed',
        'pending' => 'confirmed',
        'confirmed' => 'preparing',
        'preparing' => 'ready_for_pickup',
        // Legacy records remain readable and can finish the canonical seller flow.
        'processing' => 'ready_for_pickup',
        'packaging' => 'ready_for_pickup',
    ];

    public function sellerTransition(Order $order, Shop $shop, User $seller, string $targetStatus): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller, $targetStatus) {
            $lockedOrder = $this->lockSellerOrder($order->id);

            $shop = $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            $this->applySellerTransition($lockedOrder, $shop, $seller, $targetStatus);

            return $lockedOrder->fresh(['delivery', 'items']);
        });
    }

    public function sellerAcceptAndPack(Order $order, Shop $shop, User $seller): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller) {
            $lockedOrder = $this->lockSellerOrder($order->id);

            $shop = $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            if (! in_array($lockedOrder->status, ['placed', 'pending'], true)) {
                throw new RuntimeException(
                    "Order is currently {$lockedOrder->status}; only a newly placed order can be confirmed and packed together."
                );
            }

            $this->applySellerTransition($lockedOrder, $shop, $seller, 'confirmed');
            $this->applySellerTransition($lockedOrder, $shop, $seller, 'preparing');

            return $lockedOrder->fresh(['delivery', 'items']);
        });
    }

    private function applySellerTransition(Order $order, Shop $shop, User $seller, string $targetStatus): void
    {
        $this->assertSellerTransition($order, $targetStatus);
        $sourceState = DeliveryCheckpoint::state($order->delivery->setRelation('order', $order));
        $custodyBefore = DeliveryCheckpoint::lastCustody($order->delivery);
        $custodyAfter = ['kind' => 'seller', 'user_id' => $seller->id, 'shop_id' => $shop->id];
        $order->update(['status' => $targetStatus]);

        if ($targetStatus === 'preparing') {
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $order->delivery->id, 'checkpoint_type' => 'seller_pack'],
                [
                    'location_name' => $shop->name,
                    'barcode_scanned' => $order->delivery->tracking_number,
                    'notes' => 'Seller packed the parcel and prepared its waybill.',
                    'scanned_by_id' => $seller->id,
                    'scan_provenance' => 'prepared_waybill',
                    'source_state' => $sourceState,
                    'target_state' => DeliveryCheckpoint::state($order->delivery),
                    'custody_before' => $custodyBefore,
                    'custody_after' => $custodyAfter,
                ]
            );
        }

        if ($targetStatus === 'ready_for_pickup') {
            $order->delivery->update([
                'status' => 'unassigned',
                'pickup_store_name' => $shop->name,
                'pickup_address' => "{$shop->address}, {$shop->city}",
                'pickup_latitude' => $shop->pickup_latitude,
                'pickup_longitude' => $shop->pickup_longitude,
                'pickup_phone' => $shop->phone,
            ]);
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $order->delivery->id, 'checkpoint_type' => 'ready_for_pickup'],
                [
                    'location_name' => $shop->name,
                    'barcode_scanned' => $order->delivery->tracking_number,
                    'notes' => 'Seller attached the waybill and staged the parcel for rider pickup.',
                    'scanned_by_id' => $seller->id,
                    'scan_provenance' => 'prepared_waybill',
                    'source_state' => $sourceState,
                    'target_state' => DeliveryCheckpoint::state($order->delivery),
                    'custody_before' => $custodyBefore,
                    'custody_after' => $custodyAfter,
                ]
            );
        }
        app(LifecycleNoticeService::class)->order($order, $targetStatus);
    }

    private function assertSellerTransition(Order $order, string $targetStatus): void
    {
        app(WalletPaymentService::class)->assertFulfillable($order);
        $this->cancellations->expireForOrder($order);
        if ($this->cancellations->livePending($order)) {
            throw new RuntimeException('Review the buyer’s pending cancellation request before continuing preparation or pickup.');
        }
        if ($order->delivery) {
            app(HandoverHoldService::class)->assertMovable($order->delivery);
        }
        $expectedTarget = self::SELLER_TRANSITIONS[$order->status] ?? null;
        if ($expectedTarget !== $targetStatus) {
            throw new RuntimeException(
                "Order is currently {$order->status}; the requested {$targetStatus} transition is not allowed."
            );
        }

        if (! $order->delivery) {
            throw new RuntimeException('This order has no waybill or logistics route and cannot advance.');
        }

        if (! $this->isUnclaimed($order->delivery)) {
            throw new RuntimeException('A rider has already claimed or received parcel custody.');
        }
        if ($issue = $this->recordedCustodyIssue($order->delivery)) {
            throw new RuntimeException($issue);
        }

        if ($targetStatus === 'ready_for_pickup' && ! $this->hasCompleteRoute($order)) {
            throw new RuntimeException('The parcel route is incomplete and cannot be released for pickup.');
        }
        if (
            $targetStatus === 'ready_for_pickup'
            && ! $order->delivery->checkpoints()->where('checkpoint_type', 'seller_pack')->exists()
        ) {
            throw new RuntimeException('Pack the parcel and prepare its waybill before marking it ready for pickup.');
        }

    }

    public function sellerBatchReady(array $input, Shop $shop, User $seller): int
    {
        $data = Validator::make($input, [
            'order_ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::BATCH_LIMIT],
            'order_ids.*' => ['bail', new AsciiPositiveInteger, 'integer', 'distinct'],
        ])->validate();

        return DB::transaction(function () use ($data, $shop, $seller) {
            $orders = Order::whereIn('id', $data['order_ids'])->orderBy('id')->lockForUpdate()->with('items.product')->get();
            if ($orders->count() !== count($data['order_ids'])) {
                throw ValidationException::withMessages(['order_ids' => 'One or more selected orders are unavailable.']);
            }
            $parcels = Delivery::whereIn('order_id', $orders->modelKeys())->orderBy('id')->lockForUpdate()->get()->keyBy('order_id');
            foreach ($orders as $order) {
                $order->setRelation('delivery', $parcels->get($order->id));
                $shop = $this->assertSellerOwnsCompleteOrder($order, $shop, $seller);
                $this->assertSellerTransition($order, 'ready_for_pickup');
            }
            foreach ($orders as $order) {
                $this->applySellerTransition($order, $shop, $seller, 'ready_for_pickup');
            }

            return $orders->count();
        });
    }

    public function sellerActionFlags(Order $order, bool $eligible, bool $fullyOwned): array
    {
        $held = $order->delivery && app(HandoverHoldService::class)->held($order->delivery);
        $work = $eligible && $fullyOwned && ! $held;
        $unclaimed = ! $order->delivery || $this->isUnclaimed($order->delivery);
        $paid = $order->payment_method !== 'wallet' || app(WalletPaymentService::class)->paid($order);
        $custodyIssue = $order->delivery ? $this->recordedCustodyIssue($order->delivery) : null;
        $pendingCancellation = $this->cancellations->livePending($order);
        $canAdvance = $work && $unclaimed && $order->delivery && $paid && ! $custodyIssue && ! $pendingCancellation;

        return [
            'handover_hold' => (bool) $held,
            'can_fulfill' => $work && $paid,
            'can_accept_and_pack' => $canAdvance && in_array($order->status, ['placed', 'pending'], true),
            'can_pack' => $canAdvance && $order->status === 'confirmed',
            'can_mark_ready' => $canAdvance && in_array($order->status, ['preparing', 'processing', 'packaging'], true)
                && $this->hasCompleteRoute($order) && $order->delivery->checkpoints->contains('checkpoint_type', 'seller_pack'),
            'can_cancel' => $work && $this->sellerCancellationIssue($order) === null,
            'can_print_waybill' => $fullyOwned && app(SellerOrderDisclosureService::class)->available($order) && (bool) $order->delivery?->tracking_number,
            'fulfillment_feedback' => $this->fulfillmentFeedback($order, $work, $paid, $custodyIssue),
            'cancellation_feedback' => $this->cancellationFeedback($order),
            'cancellation_request' => $this->cancellations->present($order),
        ];
    }

    private function lockSellerOrder(int $id): Order
    {
        $order = Order::whereKey($id)->lockForUpdate()->firstOrFail();
        $order->setRelation('delivery', Delivery::where('order_id', $id)->lockForUpdate()->first());

        return $order->load('items.product');
    }

    private function isUnclaimed(Delivery $delivery): bool
    {
        return ! $delivery->courier_id && ! $delivery->assigned_rider_id && $delivery->status === 'unassigned';
    }

    private function recordedCustodyIssue(Delivery $parcel): ?string
    {
        $checkpoint = $parcel->latestCustodyCheckpoint;

        return $checkpoint && ($checkpoint->custody_after['kind'] ?? null) !== 'seller'
            ? 'A parcel handover is already recorded. Continue its delivery or reviewed return; preparation and cancellation cannot replace custody.' : null;
    }

    private function sellerCancellationIssue(Order $order): ?string
    {
        if (! in_array($order->shipping_quote['cancellation_policy_version'] ?? 1, [1, 2], true)) {
            return 'The purchased cancellation terms need review. Contact support.';
        }
        if (! in_array($order->status, [...OrderWorkspaceService::PREPARATION, 'ready_for_pickup'], true)) {
            return 'This order can no longer be cancelled by the seller.';
        }
        if ($order->delivery && app(HandoverHoldService::class)->held($order->delivery)) {
            return 'This parcel has a handover review in progress. Complete its review before changing the order.';
        }
        if ($order->delivery && ($issue = $this->recordedCustodyIssue($order->delivery))) {
            return $issue;
        }
        if ($order->delivery && ! $this->isUnclaimed($order->delivery)) {
            return 'A rider has already claimed or received parcel custody.';
        }
        if ($order->codAccount || ($order->payment_method === 'cod' && $order->payment_status !== 'pending')) {
            return 'A payment is already recorded. Contact support to review this order instead of cancelling it.';
        }
        if ($order->payment_method === 'wallet') {
            $payment = $order->walletPayment;
            if (! $payment || ! in_array($payment->status, ['pending', 'paid'], true)
                || ($payment->status === 'paid' && ! app(WalletPaymentService::class)->paid($order))) {
                return 'This wallet payment needs review before cancellation.';
            }
        } elseif ($order->payment_method !== 'cod') {
            return 'Contact support to review the original payment before cancelling this order.';
        }

        return null;
    }

    private function fulfillmentFeedback(Order $order, bool $work, bool $paid, ?string $custodyIssue): array
    {
        if (in_array($order->status, ['cancelled', 'completed', 'returned'], true)) {
            return ['code' => $order->status, 'message' => match ($order->status) {
                'cancelled' => 'The order is cancelled. Its original cancellation and payment records are retained.',
                'completed' => 'The order is completed. Any eligible return uses the separate return review.',
                'returned' => 'The original parcel return is recorded. Review its separate payment outcome.',
            }];
        }
        if (! $work) {
            return ['code' => 'review_required', 'message' => 'This order is read-only while its shop access or handover review is unresolved.'];
        }
        if ($custodyIssue) {
            return ['code' => 'custody_recorded', 'message' => $custodyIssue];
        }
        if ($this->cancellations->livePending($order)) {
            return ['code' => 'cancellation_requested', 'message' => 'The buyer requested cancellation. Review it before continuing preparation or pickup.'];
        }
        if (! $paid) {
            return ['code' => 'payment_required', 'message' => 'Wait for the original Wallet payment before accepting, packing or releasing this order.'];
        }
        if ($order->delivery && ! $this->isUnclaimed($order->delivery)) {
            return ['code' => 'rider_claimed', 'message' => 'A rider has claimed or collected the parcel. Follow its tracking and handover actions.'];
        }

        return match ($order->status) {
            'placed', 'pending' => ['code' => 'awaiting_acceptance', 'message' => 'Review the items before accepting the order.'],
            'confirmed' => ['code' => 'accepted', 'message' => 'Order accepted. Pack the items before marking them ready for pickup.'],
            'preparing', 'processing', 'packaging' => ['code' => 'packed', 'message' => 'Attach the waybill, then mark the parcel ready for pickup. Printing alone does not mark it ready.'],
            'ready_for_pickup' => ['code' => 'ready_for_pickup', 'message' => 'Ready for an eligible pickup rider. The assigned rider must scan the actual handover.'],
            default => ['code' => 'tracking', 'message' => 'Follow the recorded delivery or return progress.'],
        };
    }

    public function cancelBySeller(Order $order, Shop $shop, User $seller, array $input): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller, $input) {
            $lockedOrder = $this->lockSellerOrder($order->id);

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            if ($lockedOrder->status === 'cancelled') {
                return $lockedOrder;
            }
            if ($issue = $this->sellerCancellationIssue($lockedOrder)) {
                throw new RuntimeException($issue);
            }

            $data = Validator::make($input, [
                'reason' => ['required', 'string', Rule::in(self::SELLER_CANCELLATION_REASONS)],
                'notes' => [Rule::requiredIf(($input['reason'] ?? null) === 'Other reason'), 'nullable', 'string', new ApplicationText('notes', 1, 1000)],
            ])->validate();
            $reason = $data['reason'].(! empty($data['notes']) ? ': '.$data['notes'] : '');

            return $this->cancelLocked($lockedOrder, $seller, $reason);
        });
    }

    public function canBuyerCancel(Order $order, User $buyer): bool
    {
        return $buyer->isBuyer() && $buyer->canAccessPortal() && $order->buyer_id === $buyer->id
            && $this->buyerCancellationIssue($order) === null && ! $this->requiresSellerReview($order)
            && ! $this->cancellations->livePending($order);
    }

    private function requiresSellerReview(Order $order): bool
    {
        return ($order->shipping_quote['cancellation_policy_version'] ?? 1) === 2
            && in_array($order->status, ['confirmed', 'preparing', 'processing', 'packaging'], true);
    }

    public function canBuyerRequestCancellation(Order $order, User $buyer): bool
    {
        return $this->cancellations->available() && $buyer->isBuyer() && $buyer->canAccessPortal()
            && $order->buyer_id === $buyer->id && $this->requiresSellerReview($order)
            && $this->buyerCancellationIssue($order) === null && ! $this->cancellations->livePending($order);
    }

    public function cancellationFeedback(Order $order, ?User $buyer = null): array
    {
        $cancelled = $order->status === 'cancelled';
        $requestable = $buyer && $this->canBuyerRequestCancellation($order, $buyer);
        $request = $this->cancellations->present($order);
        $allowed = $buyer ? ($this->canBuyerCancel($order, $buyer) || $requestable) : $this->sellerCancellationIssue($order) === null;
        $message = $cancelled ? 'Order cancelled. Delivery will not proceed.'
            : ($buyer ? $this->buyerCancellationIssue($order) : $this->sellerCancellationIssue($order));
        if (! $cancelled && $buyer && $requestable) {
            $message = 'The seller accepted this order. You can request cancellation before it is ready for pickup; the seller has 24 hours to respond.';
        } elseif (! $cancelled && ($request['status'] ?? null) === 'pending') {
            $message = 'Cancellation requested. The order remains active while the seller reviews it; preparation and pickup are on hold.';
        } elseif ($buyer && ! $buyer->canAccessPortal()) {
            $message = 'Your account can view this order, but cancellation is unavailable. Contact support.';
        }
        $paymentMessage = match (true) {
            $order->payment_method === 'cod' && $order->payment_status === 'pending' => $cancelled ? 'No COD payment was collected.' : 'No COD refund is needed because payment has not been collected.',
            $order->payment_method === 'wallet' && $order->payment_status === 'paid' => 'A permitted cancellation returns the original paid amount to the buyer’s Bagoo Wallet once.',
            $order->payment_method === 'wallet' && in_array($order->payment_status, ['pending', 'cancelled'], true) => $cancelled ? 'No Wallet credits were deducted.' : 'This unpaid Wallet reservation can be released without a refund.',
            default => 'Review the recorded payment outcome; cancellation does not prove a cash repayment.',
        };
        $refund = $cancelled && $order->payment_method === 'wallet'
            ? WalletTransaction::where('business_key', 'cancellation:'.$order->id)->where('kind', 'wallet_cancellation_refund')
                ->where('order_id', $order->id)->first() : null;
        if ($refund) {
            $paymentMessage = 'The original payment was returned to the buyer’s Bagoo Wallet.';
        }

        return ['allowed' => $allowed && ! $cancelled, 'mode' => $requestable ? 'request' : 'direct',
            'code' => $cancelled ? 'cancelled' : (($request['status'] ?? null) === 'pending' ? 'pending' : ($allowed ? 'before_pickup' : 'unavailable')),
            'message' => $message ?? ($buyer && ($order->shipping_quote['cancellation_policy_version'] ?? 1) === 2 ? 'You can cancel directly before seller acceptance.' : 'Cancel before the order is ready for pickup or a rider claims it.'),
            'payment_message' => $paymentMessage,
            'refund' => $refund?->only(['reference', 'amount_cents', 'created_at']), 'request' => $request];
    }

    private function buyerCancellationIssue(Order $order): ?string
    {
        if (! in_array($order->shipping_quote['cancellation_policy_version'] ?? 1, [1, 2], true)) {
            return 'The purchased cancellation terms need review. Contact support.';
        }
        if (! in_array($order->status, OrderWorkspaceService::PREPARATION, true)) {
            return 'The seller has marked this order ready for pickup or delivery has started. It can no longer be cancelled here.';
        }
        if ($order->payment_method === 'wallet') {
            $payment = $order->walletPayment;
            if (! $payment || ! in_array($payment->status, ['pending', 'paid'], true)
                || ($payment->status === 'paid' && ! app(WalletPaymentService::class)->paid($order))) {
                return 'This wallet payment needs review before cancellation.';
            }
        } elseif ($order->payment_method !== 'cod') {
            return 'Contact support to review the payment before cancelling this order.';
        }
        if (($order->payment_method === 'cod' && $order->payment_status !== 'pending') || $order->codAccount) {
            return 'A payment is already recorded. Contact support about this order.';
        }
        $parcel = $order->delivery;
        if (! $parcel) {
            return null;
        }
        if (app(HandoverHoldService::class)->held($parcel)) {
            return 'This parcel has a handover review in progress. Contact support before changing this order.';
        }
        if (! $this->isUnclaimed($parcel)) {
            return 'A rider has already claimed this parcel. Contact the seller or support.';
        }
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $recorded = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->exists();
        if ($recorded && ($custody['kind'] ?? null) !== 'seller') {
            return 'A parcel handover is already recorded. Contact the seller or support.';
        }

        return null;
    }

    public function cancelByBuyer(Order $order, User $buyer, array $input): Order
    {
        $data = Validator::make($input, [
            'reason' => ['required', 'string', Rule::in(self::BUYER_CANCELLATION_REASONS)],
            'notes' => [Rule::requiredIf(($input['reason'] ?? null) === 'Other reason'), 'nullable', 'string', new ApplicationText('notes', 1, 500)],
        ])->validate();

        return DB::transaction(function () use ($order, $buyer, $data) {
            $locked = $this->lockSellerOrder($order->id);
            $buyer = app(BuyerAccessService::class)->requirePortal($buyer, lock: true);
            if ($locked->buyer_id !== $buyer->id) {
                throw new AuthorizationException('This order belongs to another buyer.');
            }
            if ($locked->status === 'cancelled') {
                return $locked;
            }
            if ($this->requiresSellerReview($locked)) {
                throw ValidationException::withMessages(['reason' => 'The seller accepted this order. Submit a cancellation request for review instead.']);
            }
            if ($this->cancellations->livePending($locked)) {
                throw ValidationException::withMessages(['reason' => 'A cancellation request is already awaiting the seller’s response.']);
            }
            if ($issue = $this->buyerCancellationIssue($locked)) {
                throw ValidationException::withMessages(['reason' => $issue]);
            }
            $reason = $data['reason'].(! empty($data['notes']) ? ': '.$data['notes'] : '');

            return $this->cancelLocked($locked, $buyer, $reason, byBuyer: true);
        });
    }

    public function expireUnpaidWallet(Order $order): Order
    {
        $payment = $order->walletPayment()->lockForUpdate()->first();
        abort_unless(DB::transactionLevel() > 0 && $order->payment_method === 'wallet' && $order->payment_status === 'pending'
            && in_array($order->status, ['placed', 'pending'], true) && $payment?->status === 'pending'
            && ! $payment->wallet_transaction_id && $payment->expires_at->lessThanOrEqualTo(now())
            && (! $order->delivery || ($this->isUnclaimed($order->delivery) && ! app(HandoverHoldService::class)->held($order->delivery))), 409,
            'Only an expired unpaid wallet reservation can be released automatically.');

        return $this->cancelLocked($order, User::findOrFail($order->buyer_id), 'Unpaid wallet order expired after one hour.', automaticExpiry: true);
    }

    private function cancelLocked(Order $order, User $actor, string $reason, bool $byBuyer = false, bool $automaticExpiry = false): Order
    {
        // Keep product locks before funding locks, matching checkout, and restore each size once.
        $products = Product::whereIn('id', $order->items->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $funding = app(VoucherFundingService::class);
        if ($automaticExpiry) {
            app(WalletReservationService::class)->release($order, $reason);
        } elseif ($byBuyer) {
            $funding->releaseBuyerCancellation($order, $actor, $reason);
        } else {
            $funding->releaseCancellation($order, $actor, $reason);
        }
        foreach ($order->items as $item) {
            if ($product = $products->get($item->product_id)) {
                $this->inventory->restore($product, $item->quantity, $item->size, $item->color);
                app(SalePriceService::class)->release($item);
            }
        }
        $parcel = $order->delivery;
        $source = $parcel ? DeliveryCheckpoint::state($parcel->setRelation('order', $order)) : null;
        $custody = $parcel ? DeliveryCheckpoint::lastCustody($parcel) : null;
        $order->update($byBuyer ? ['status' => 'cancelled'] : ['status' => 'cancelled', 'notes' => $reason]);
        $parcel?->update(['status' => 'cancelled']);
        app(WalletPaymentService::class)->closeCancellation($order, $automaticExpiry ? null : $actor, $reason, $automaticExpiry);
        if ($parcel) {
            DeliveryCheckpoint::firstOrCreate(['delivery_id' => $parcel->id, 'checkpoint_type' => 'cancelled'], [
                'location_name' => $automaticExpiry ? 'Unpaid wallet expiry' : ($byBuyer ? 'Buyer cancellation' : 'Seller cancellation'), 'notes' => $reason, 'scanned_by_id' => $automaticExpiry ? null : $actor->id,
                'scan_provenance' => 'not_scanned', 'source_state' => $source,
                'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody, 'custody_after' => $custody,
            ]);
        }
        app(LifecycleNoticeService::class)->order($order, 'cancelled');
        $this->cancellations->closeForOrder($order, $actor);

        return $order->fresh();
    }

    public function buyerComplete(Order $order, User $buyer): Order
    {
        return DB::transaction(function () use ($order, $buyer) {
            // Keep the same order, parcel, then account lock order as parcel mutations.
            $lockedOrder = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->setRelation('delivery', Delivery::where('order_id', $lockedOrder->id)->lockForUpdate()->first());
            $buyer = app(BuyerAccessService::class)->current($buyer, lock: true);
            if (! $buyer->isBuyer() || $lockedOrder->buyer_id !== $buyer->id) {
                throw new RuntimeException('Only the buyer who placed this order may confirm receipt.');
            }
            app(BuyerAccessService::class)->requireExistingOrders($buyer, $lockedOrder);
            if ($lockedOrder->status === 'completed') {
                return $lockedOrder;
            }
            if ($lockedOrder->delivery) {
                app(HandoverHoldService::class)->assertMovable($lockedOrder->delivery);
            }
            if ($lockedOrder->logistics_flow_version >= 2) {
                throw new RuntimeException('This order completes automatically after verified delivery and payment. Refresh tracking; contact support if its delivery evidence still needs review.');
            }
            $physical = $lockedOrder->delivery;
            $collected = $physical?->status === 'customer_collected' && app(PickupClaimService::class)->hasCollectionEvidence($physical);
            if ($lockedOrder->status !== 'delivered' || ($physical?->status !== 'delivered' && ! $collected)) {
                throw new RuntimeException('Only a physically delivered parcel can be confirmed as received.');
            }

            $sourceState = DeliveryCheckpoint::state($lockedOrder->delivery->setRelation('order', $lockedOrder));
            $custody = DeliveryCheckpoint::lastCustody($lockedOrder->delivery);
            $lockedOrder->update(['status' => 'completed']);
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $lockedOrder->delivery->id, 'checkpoint_type' => 'buyer_completed'],
                [
                    'location_name' => 'Buyer Destination',
                    'barcode_scanned' => null,
                    'scan_provenance' => 'not_scanned',
                    'notes' => 'Buyer confirmed receipt. The commercial order is completed.',
                    'scanned_by_id' => $buyer->id,
                    'source_state' => $sourceState,
                    'target_state' => DeliveryCheckpoint::state($lockedOrder->delivery),
                    'custody_before' => $custody,
                    'custody_after' => $custody,
                ]
            );

            app(LifecycleNoticeService::class)->order($lockedOrder, 'completed');

            return $lockedOrder->fresh();
        });
    }

    private function assertSellerOwnsCompleteOrder(Order $order, Shop $shop, User $seller): Shop
    {
        $seller = User::whereKey($seller->id)->lockForUpdate()->first();
        if (! $seller?->isSeller() || ! $seller->canAccessPortal()) {
            throw new AuthorizationException('Only a currently approved and active seller can fulfill an order.');
        }
        $shop = Shop::whereKey($shop->id)->lockForUpdate()->first();
        if (! $shop || $shop->user_id !== $seller->id) {
            throw new AuthorizationException('This shop does not belong to this seller.');
        }
        app(ShopEligibilityService::class)->lockCategories();
        app(ShopEligibilityService::class)->assertEligible($shop);

        $shopIds = $order->items->pluck('shop_id')->unique();
        if ($shopIds->count() !== 1 || (int) $shopIds->first() !== $shop->id) {
            throw new AuthorizationException('This order does not belong to your shop.');
        }

        return $shop;
    }

    private function hasCompleteRoute(Order $order): bool
    {
        $delivery = $order->delivery;
        if ($order->logistics_flow_version >= 2 && $delivery?->same_hub_bypass) {
            return $delivery->logistics_company_id && $delivery->origin_bayan_hub_id
                && $delivery->origin_bayan_hub_id === $delivery->destination_bayan_hub_id
                && $delivery->route_hub_ids === [$delivery->origin_bayan_hub_id]
                && $delivery->origin_mother_hub_id === null && $delivery->destination_mother_hub_id === null;
        }

        return $delivery
            && $delivery->logistics_company_id
            && $delivery->origin_bayan_hub_id
            && $delivery->origin_mother_hub_id
            && $delivery->destination_mother_hub_id
            && $delivery->destination_bayan_hub_id;
    }
}
