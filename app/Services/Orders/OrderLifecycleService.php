<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
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
    public function __construct(private readonly InventoryService $inventory) {}

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

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            $this->applySellerTransition($lockedOrder, $shop, $seller, $targetStatus);

            return $lockedOrder->fresh(['delivery', 'items']);
        });
    }

    public function sellerAcceptAndPack(Order $order, Shop $shop, User $seller): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller) {
            $lockedOrder = $this->lockSellerOrder($order->id);

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
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
                $this->assertSellerOwnsCompleteOrder($order, $shop, $seller);
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
        $canAdvance = $work && $unclaimed && $order->delivery && $paid;

        return [
            'handover_hold' => (bool) $held,
            'can_fulfill' => $work && $paid,
            'can_accept_and_pack' => $canAdvance && in_array($order->status, ['placed', 'pending'], true),
            'can_pack' => $canAdvance && $order->status === 'confirmed',
            'can_mark_ready' => $canAdvance && in_array($order->status, ['preparing', 'processing', 'packaging'], true)
                && $this->hasCompleteRoute($order) && $order->delivery->checkpoints->contains('checkpoint_type', 'seller_pack'),
            'can_cancel' => $work && $unclaimed && in_array($order->status, [...OrderWorkspaceService::PREPARATION, 'ready_for_pickup'], true),
            'can_print_waybill' => $fullyOwned && (bool) $order->delivery?->tracking_number,
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

    public function cancelBySeller(Order $order, Shop $shop, User $seller, array $input): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller, $input) {
            $lockedOrder = $this->lockSellerOrder($order->id);

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            if ($lockedOrder->delivery) {
                app(HandoverHoldService::class)->assertMovable($lockedOrder->delivery);
            }
            if (! in_array($lockedOrder->status, ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging', 'ready_for_pickup'], true)) {
                throw new RuntimeException('This order can no longer be cancelled by the seller.');
            }

            if ($lockedOrder->delivery && ! $this->isUnclaimed($lockedOrder->delivery)) {
                throw new RuntimeException('A rider has already claimed or received parcel custody.');
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
            && $this->buyerCancellationIssue($order) === null;
    }

    private function buyerCancellationIssue(Order $order): ?string
    {
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
        if (($byBuyer || $automaticExpiry) && $parcel) {
            DeliveryCheckpoint::firstOrCreate(['delivery_id' => $parcel->id, 'checkpoint_type' => 'cancelled'], [
                'location_name' => $automaticExpiry ? 'Unpaid wallet expiry' : 'Buyer cancellation', 'notes' => $reason, 'scanned_by_id' => $automaticExpiry ? null : $actor->id,
                'scan_provenance' => 'not_scanned', 'source_state' => $source,
                'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody, 'custody_after' => $custody,
            ]);
        }
        app(LifecycleNoticeService::class)->order($order, 'cancelled');

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

    private function assertSellerOwnsCompleteOrder(Order $order, Shop $shop, User $seller): void
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
    }

    private function hasCompleteRoute(Order $order): bool
    {
        $delivery = $order->delivery;

        return $delivery
            && $delivery->logistics_company_id
            && $delivery->origin_bayan_hub_id
            && $delivery->origin_mother_hub_id
            && $delivery->destination_mother_hub_id
            && $delivery->destination_bayan_hub_id;
    }
}
