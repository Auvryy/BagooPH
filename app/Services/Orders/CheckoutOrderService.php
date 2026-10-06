<?php

namespace App\Services\Orders;

use App\Exceptions\CheckoutException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CheckoutSubmission;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Voucher;
use App\Services\BuyerAccessService;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Commerce\InventoryService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\ShopEligibilityService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CheckoutOrderService
{
    public function __construct(
        private readonly LogisticsRoutingEngine $routingEngine,
        private readonly InventoryService $inventory
    ) {}

    /**
     * @param  list<int>  $cartItemIds
     * @return Collection<int, Order>
     */
    public function place(User $buyer, Cart $cart, array $cartItemIds, array $data): Collection
    {
        $buyer = app(BuyerAccessService::class)->current($buyer);
        if (! $buyer->isBuyer()) {
            throw new CheckoutException('Buyer access is required to place an order.');
        }

        // Keep the invariant in the order service as well as the controller so
        // no future checkout entry point can place an order around the UI gate.
        if (! $buyer->canCompleteCheckout()) {
            if ($buyer->status !== 'active') {
                throw new CheckoutException('Your account is not active and cannot place an order.');
            }

            if ($buyer->isKycPending()) {
                throw new CheckoutException('Your ID verification is currently pending review. Please wait for approval before completing your purchase.');
            }

            if ($buyer->isKycRejected()) {
                throw new CheckoutException('Your submitted ID was rejected. Please re-upload a valid ID to proceed.');
            }

            throw new CheckoutException('Identity verification is required before placing an order. Please upload a valid ID to proceed.');
        }

        $submissions = app(CheckoutSubmissionService::class);
        $tokenHash = $submissions->tokenHash($buyer, $cart, $data['checkout_token'] ?? null);
        $data = app(CommerceInputService::class)->checkout($data, $cartItemIds);
        $cartItemIds = $data['item_ids'];
        $requestHash = $submissions->fingerprint($data);

        return DB::transaction(function () use ($buyer, $cart, $cartItemIds, $data, $submissions, $tokenHash, $requestHash) {
            // Serialize the owned Bag before lines and the seller/shop/category/product locks.
            if (! Cart::whereKey($cart->id)->where('user_id', $buyer->id)->lockForUpdate()->first()) {
                throw new CheckoutException('This Shopping Bag is unavailable to your account.');
            }
            // The original result remains available after selected Bag lines, stock and routing change.
            if ($original = $submissions->replay($buyer, $cart, $tokenHash, $requestHash)) {
                return $original;
            }
            if (! $this->routingEngine->isContiguousRoadServiceable($data['shipping_province'], $data['shipping_city'])) {
                throw new CheckoutException('Delivery address is outside contiguous road freight boundaries. Maritime shipping is excluded.');
            }
            if ($data['delivery_type'] === 'hub_self_pickup' && ! LogisticsHub::eligible()
                ->whereKey($data['pickup_hub_id'])->where('tier', 'local_bayan_hub')->where('allows_self_pickup', true)->exists()) {
                throw ValidationException::withMessages(['pickup_hub_id' => 'Choose an available Bayan Hub that supports self-pickup.']);
            }
            $selectedIds = $cartItemIds;

            $items = CartItem::query()
                ->where('cart_id', $cart->id)
                ->whereIn('id', $selectedIds)
                ->with('product.shop')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($items->count() !== count($selectedIds)) {
                throw new CheckoutException('One or more selected Shopping Bag items are unavailable.');
            }

            $products = app(ShopEligibilityService::class)->lockSaleProducts(
                $items->pluck('product_id')->unique()->all(), [$buyer->id]
            );
            if (! $buyer->fresh()->canCompleteCheckout()) {
                throw new CheckoutException('Your account is no longer eligible to place an order.');
            }

            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product || $product->status !== 'active' || ! $product->shop || $product->shop->status !== 'active') {
                    throw new CheckoutException('One or more selected products or shops are unavailable.');
                }
                $item->setRelation('product', $product);
            }

            try {
                $this->inventory->assertCheckoutAvailability($items, $products);
            } catch (RuntimeException $exception) {
                throw new CheckoutException($exception->getMessage(), previous: $exception);
            }

            $shopGroups = $items->groupBy(fn (CartItem $item) => $item->product->shop_id);
            $deliveryType = $data['delivery_type'] ?? 'doorstep';
            $shopAmounts = $shopGroups->map(function (Collection $group) use ($deliveryType) {
                $subtotal = round($group->sum(
                    fn (CartItem $item) => (float) $item->product->price * $item->quantity
                ), 2);

                return [
                    'subtotal' => $subtotal,
                    'shipping' => $deliveryType === 'hub_self_pickup' ? 0.0 : ($subtotal > 1500 ? 0.0 : 50.0),
                ];
            });

            $voucher = $this->lockVoucher($data['voucher_code'] ?? null);
            $discounts = $this->allocateVoucher($voucher, $shopAmounts);
            $orders = collect();

            foreach ($shopGroups as $shopId => $group) {
                $shop = $group->first()->product->shop;
                $amounts = $shopAmounts->get($shopId);
                $discount = $discounts->get($shopId, 0.0);

                $order = Order::create([
                    'order_number' => 'BGO-'.strtoupper(Str::random(8)),
                    'buyer_id' => $buyer->id,
                    'voucher_id' => $discount > 0 ? $voucher?->id : null,
                    'subtotal' => $amounts['subtotal'],
                    'voucher_discount' => $discount,
                    'shipping_fee' => $amounts['shipping'],
                    'total_amount' => max(0, round($amounts['subtotal'] + $amounts['shipping'] - $discount, 2)),
                    'payment_method' => 'cod',
                    'payment_status' => 'pending',
                    'status' => 'placed',
                    'delivery_type' => $deliveryType,
                    'pickup_hub_id' => $deliveryType === 'hub_self_pickup' ? ($data['pickup_hub_id'] ?? null) : null,
                    'destination_barangay' => $data['destination_barangay'] ?? null,
                    'destination_latitude' => $data['shipping_latitude'] ?? null,
                    'destination_longitude' => $data['shipping_longitude'] ?? null,
                    'recipient_name' => $data['recipient_name'],
                    'recipient_phone' => $data['recipient_phone'],
                    'shipping_address' => $data['shipping_address'],
                    'shipping_city' => $data['shipping_city'],
                    'shipping_province' => $data['shipping_province'],
                    'shipping_postal_code' => $data['shipping_postal_code'] ?? null,
                    'notes' => $this->deliveryNotes($data),
                ]);

                foreach ($group as $item) {
                    $product = $item->product;
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'shop_id' => $product->shop_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $product->price,
                        'subtotal' => round((float) $product->price * $item->quantity, 2),
                        'color' => $item->color,
                        'size' => $item->size,
                        'sku_snapshot' => $item->sku_snapshot,
                    ]);

                    $this->inventory->decrement(
                        $product,
                        $item->quantity,
                        $item->color,
                        $item->size
                    );
                }

                $delivery = Delivery::create([
                    'order_id' => $order->id,
                    'tracking_number' => 'BGO-'.strtoupper(Str::random(10)),
                    'delivery_type' => $deliveryType,
                    'status' => 'unassigned',
                    'pickup_store_name' => $shop->name,
                    'pickup_address' => "{$shop->address}, {$shop->city}",
                    'pickup_phone' => $shop->phone,
                    'delivery_recipient_name' => $data['recipient_name'],
                    'delivery_address' => $this->deliveryAddress($data),
                    'delivery_phone' => $data['recipient_phone'],
                    'estimated_delivery_at' => now()->addDays(3),
                ]);

                try {
                    $this->routingEngine->planDeliveryRoute($delivery, $order, $shop);
                } catch (DomainException $exception) {
                    throw new CheckoutException($exception->getMessage(), previous: $exception);
                }
                $orders->push($order->load('delivery', 'items'));
            }

            if ($voucher && $discounts->sum() > 0) {
                $voucher->increment('used_count');
            }

            if ($data['save_address']) {
                app(BuyerAddressService::class)->create($buyer, [
                    'phone' => $data['recipient_phone'], 'province' => $data['shipping_province'],
                    'city' => $data['shipping_city'], 'barangay' => $data['destination_barangay'],
                    'street' => $data['shipping_address'], 'postal_code' => $data['shipping_postal_code'],
                    'latitude' => $data['shipping_latitude'], 'longitude' => $data['shipping_longitude'],
                    'landmark' => $data['landmark'], 'type' => 'Home', 'is_default' => false,
                ]);
            }

            CartItem::where('cart_id', $cart->id)->whereIn('id', $selectedIds)->delete();

            $submission = CheckoutSubmission::create([
                'buyer_id' => $buyer->id, 'cart_id' => $cart->id, 'token_hash' => $tokenHash,
                'request_hash' => $requestHash, 'order_count' => $orders->count(), 'created_at' => now(),
            ]);
            $submission->orders()->attach($orders->pluck('id')->all());

            return $orders;
        });
    }

    private function lockVoucher(?string $code): ?Voucher
    {
        $normalized = strtoupper(trim((string) $code));

        $voucher = $normalized === ''
            ? null
            : Voucher::where('code', $normalized)->lockForUpdate()->first();
        if ($normalized !== '' && ! $voucher) {
            throw new CheckoutException('This voucher is unavailable. Remove it or choose another voucher.');
        }

        return $voucher;
    }

    private function allocateVoucher(?Voucher $voucher, Collection $shopAmounts): Collection
    {
        $discounts = $shopAmounts->mapWithKeys(fn ($value, $shopId) => [$shopId => 0.0]);
        if (! $voucher) {
            return $discounts;
        }

        if ($voucher->shop_id) {
            $amounts = $shopAmounts->get($voucher->shop_id);
            if (! $amounts || ! $voucher->isValidForAmount($amounts['subtotal'], $voucher->shop_id)) {
                throw new CheckoutException('This shop voucher is not valid for the selected items.');
            }

            return $discounts->put(
                $voucher->shop_id,
                $voucher->calculateDiscount($amounts['subtotal'], $amounts['shipping'])
            );
        }

        $totalSubtotal = round($shopAmounts->sum('subtotal'), 2);
        $totalShipping = round($shopAmounts->sum('shipping'), 2);
        if (! $voucher->isValidForAmount($totalSubtotal)) {
            throw new CheckoutException('This platform voucher is no longer valid.');
        }

        $totalDiscountCents = (int) round($voucher->calculateDiscount($totalSubtotal, $totalShipping) * 100);
        $remaining = $totalDiscountCents;
        $shopIds = $shopAmounts->keys()->values();

        foreach ($shopIds as $index => $shopId) {
            $cents = $index === $shopIds->count() - 1
                ? $remaining
                : (int) floor($totalDiscountCents * ($shopAmounts[$shopId]['subtotal'] / $totalSubtotal));
            $cents = min($cents, (int) round(($shopAmounts[$shopId]['subtotal'] + $shopAmounts[$shopId]['shipping']) * 100));
            $discounts->put($shopId, $cents / 100);
            $remaining -= $cents;
        }

        return $discounts;
    }

    private function deliveryAddress(array $data): string
    {
        $landmark = empty($data['landmark']) ? '' : "[Landmark: {$data['landmark']}] ";

        return "{$landmark}{$data['shipping_address']}, {$data['shipping_city']}, {$data['shipping_province']}";
    }

    private function deliveryNotes(array $data): string
    {
        $landmark = empty($data['landmark']) ? '' : "[Landmark: {$data['landmark']}] ";

        return trim($landmark.($data['notes'] ?? ''));
    }
}
