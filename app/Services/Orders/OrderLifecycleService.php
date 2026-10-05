<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\Commerce\InventoryService;
use App\Services\ShopEligibilityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderLifecycleService
{
    public function __construct(private readonly InventoryService $inventory) {}

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
            $lockedOrder = Order::with(['items.product', 'delivery'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            $this->applySellerTransition($lockedOrder, $shop, $seller, $targetStatus);

            return $lockedOrder->fresh(['delivery', 'items']);
        });
    }

    public function sellerAcceptAndPack(Order $order, Shop $shop, User $seller): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller) {
            $lockedOrder = Order::with(['items.product', 'delivery'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

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
        $expectedTarget = self::SELLER_TRANSITIONS[$order->status] ?? null;
        if ($expectedTarget !== $targetStatus) {
            throw new RuntimeException(
                "Order is currently {$order->status}; the requested {$targetStatus} transition is not allowed."
            );
        }

        if (! $order->delivery) {
            throw new RuntimeException('This order has no waybill or logistics route and cannot advance.');
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

        $order->update(['status' => $targetStatus]);

        if ($targetStatus === 'preparing') {
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $order->delivery->id, 'checkpoint_type' => 'seller_pack'],
                [
                    'location_name' => $shop->name,
                    'barcode_scanned' => $order->delivery->tracking_number,
                    'notes' => 'Seller packed the parcel and prepared its waybill.',
                    'scanned_by_id' => $seller->id,
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
                ]
            );
        }
    }

    public function cancelBySeller(Order $order, Shop $shop, User $seller, string $reason): Order
    {
        return DB::transaction(function () use ($order, $shop, $seller, $reason) {
            $lockedOrder = Order::with(['items.product', 'delivery'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSellerOwnsCompleteOrder($lockedOrder, $shop, $seller);
            if (! in_array($lockedOrder->status, ['placed', 'pending', 'confirmed', 'preparing', 'processing', 'packaging', 'ready_for_pickup'], true)) {
                throw new RuntimeException('This order can no longer be cancelled by the seller.');
            }

            if ($lockedOrder->delivery && (
                $lockedOrder->delivery->courier_id
                || $lockedOrder->delivery->assigned_rider_id
                || ! in_array($lockedOrder->delivery->status, ['unassigned'], true)
            )) {
                throw new RuntimeException('A rider has already claimed or received parcel custody.');
            }

            foreach ($lockedOrder->items as $item) {
                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $this->inventory->restore($product, $item->quantity, $item->size);
                }
            }

            $lockedOrder->update(['status' => 'cancelled', 'notes' => $reason]);
            $lockedOrder->delivery?->update(['status' => 'cancelled']);

            return $lockedOrder->fresh();
        });
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
            if ($lockedOrder->status !== 'delivered' || $lockedOrder->delivery?->status !== 'delivered') {
                throw new RuntimeException('Only a physically delivered parcel can be confirmed as received.');
            }

            $lockedOrder->update(['status' => 'completed']);
            DeliveryCheckpoint::firstOrCreate(
                ['delivery_id' => $lockedOrder->delivery->id, 'checkpoint_type' => 'buyer_completed'],
                [
                    'location_name' => 'Buyer Destination',
                    'barcode_scanned' => $lockedOrder->delivery->tracking_number,
                    'notes' => 'Buyer confirmed receipt. The commercial order is completed.',
                    'scanned_by_id' => $buyer->id,
                ]
            );

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
            throw new AuthorizationException('The selected shop does not belong to this seller.');
        }
        app(ShopEligibilityService::class)->lockCategories();
        app(ShopEligibilityService::class)->assertEligible($shop);

        $shopIds = $order->items->pluck('shop_id')->unique();
        if ($shopIds->count() !== 1 || (int) $shopIds->first() !== $shop->id) {
            throw new AuthorizationException('This order does not belong to the selected shop.');
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
