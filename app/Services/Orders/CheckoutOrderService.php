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
use App\Services\BuyerAccessService;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Commerce\CommerceInputService;
use App\Services\Commerce\InventoryService;
use App\Services\Commerce\PhilippineAddressCatalog;
use App\Services\Commerce\ProductSkuService;
use App\Services\Commerce\SalePriceService;
use App\Services\Logistics\LogisticsCheckoutService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Notifications\LifecycleNoticeService;
use App\Services\ShopEligibilityService;
use App\Services\Vouchers\VoucherFundingService;
use App\Services\Wallet\WalletPaymentService;
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
        $confirmationToken = $data['checkout_token'] ?? null;
        $tokenHash = $submissions->tokenHash($buyer, $cart, $confirmationToken);
        $data = app(CommerceInputService::class)->checkout($data, $cartItemIds);
        $cartItemIds = $data['item_ids'];
        $requestHash = $submissions->fingerprint($data);

        return DB::transaction(function () use ($buyer, $cart, $cartItemIds, $data, $submissions, $tokenHash, $requestHash, $confirmationToken) {
            // Serialize the owned Bag before lines and the seller/shop/category/product locks.
            if (! Cart::whereKey($cart->id)->where('user_id', $buyer->id)->lockForUpdate()->first()) {
                throw new CheckoutException('This Shopping Bag is unavailable to your account.');
            }
            // The original result remains available after selected Bag lines, stock and routing change.
            if ($original = $submissions->replay($buyer, $cart, $tokenHash, $requestHash, $data)) {
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
            $bagQuantities = $items->pluck('quantity', 'id');
            $items = app(CheckoutQuoteService::class)->withQuantities($items, $data['item_quantities'] ?? []);

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
            $submissions->assertCurrentPrices($cart, $confirmationToken);

            try {
                $this->inventory->assertCheckoutAvailability($items, $products, $buyer->id);
            } catch (RuntimeException $exception) {
                throw new CheckoutException($exception->getMessage(), previous: $exception);
            }

            $shopGroups = $items->groupBy(fn (CartItem $item) => $item->product->shop_id)->sortKeys();
            $company = app(LogisticsCheckoutService::class)->select($items, $data, $data['logistics_company_id']);
            $deliveryType = $data['delivery_type'] ?? 'doorstep';
            $revisions = app(VoucherSelectionService::class)->resolve($data, $items, lock: true);
            $quote = app(CheckoutQuoteService::class)->quote($items, $deliveryType, $revisions, $buyer, $company);
            // A clock boundary can change prices after the earlier check, even while rows stay locked.
            $submissions->assertCurrentPrices($cart, $confirmationToken, $items);
            if ($quote['voucher_error']) {
                throw new CheckoutException($quote['voucher_error']);
            }
            $shopAmounts = collect($quote['shops'])->keyBy('shop_id');
            $discounts = $shopAmounts->pluck('discount', 'shop_id');
            $orders = collect();

            foreach ($shopGroups as $shopId => $group) {
                $shop = $group->first()->product->shop;
                $amounts = $shopAmounts->get($shopId);
                $discount = $discounts->get($shopId, 0.0);

                $order = Order::create([
                    'logistics_flow_version' => 2,
                    'shipping_quote' => $amounts['shipping_quote'],
                    'order_number' => 'BGO-'.strtoupper(Str::random(8)),
                    'buyer_id' => $buyer->id,
                    'voucher_id' => $discount > 0 && $revisions->count() === 1 ? $revisions->first()->voucher_id : null,
                    'voucher_accounting_version' => 1,
                    'subtotal' => $amounts['subtotal'],
                    'voucher_discount' => $discount,
                    'shipping_fee' => $amounts['shipping'],
                    'total_amount' => $amounts['total'],
                    'payment_method' => $data['payment_method'],
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
                    $lineQuote = collect($quote['items'])->firstWhere('id', $item->id);
                    foreach ($lineQuote['price_segments'] as $segment) {
                        if ($segment['product_sale_id']) {
                            app(SalePriceService::class)->reserve($product, $segment['product_sale_id'], $segment['quantity']);
                        }
                        OrderItem::create([
                            'order_id' => $order->id, 'product_id' => $product->id, 'shop_id' => $product->shop_id,
                            'product_sale_id' => $segment['product_sale_id'], 'quantity' => $segment['quantity'],
                            'unit_price' => $segment['unit_price'], 'subtotal' => $segment['subtotal'],
                            'color' => $item->color, 'size' => $item->size, 'sku_snapshot' => app(ProductSkuService::class)->selected($product, $item->color, $item->size),
                            'declared_weight_kg' => $product->weight_kg,
                        ]);
                    }

                    $this->inventory->decrement(
                        $product,
                        $item->quantity,
                        $item->color,
                        $item->size
                    );
                }

                $delivery = Delivery::create([
                    'logistics_company_id' => $company->id,
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
                app(LifecycleNoticeService::class)->order($order, 'placed');
                $orders->push($order->load('delivery', 'items'));
            }

            if ($data['save_address']) {
                try {
                    app(BuyerAddressService::class)->create($buyer, [
                        'recipient_name' => $data['recipient_name'],
                        'region' => app(PhilippineAddressCatalog::class)->regionFor($data['shipping_province'], $data['shipping_city']),
                        'phone' => $data['recipient_phone'], 'province' => $data['shipping_province'],
                        'city' => $data['shipping_city'], 'barangay' => $data['destination_barangay'],
                        'street' => $data['shipping_address'], 'postal_code' => $data['shipping_postal_code'],
                        'latitude' => $data['shipping_latitude'], 'longitude' => $data['shipping_longitude'],
                        'landmark' => $data['landmark'], 'type' => 'Home', 'is_default' => false,
                    ]);
                } catch (ValidationException $exception) {
                    $fields = ['phone' => 'recipient_phone', 'street' => 'shipping_address', 'city' => 'shipping_city',
                        'province' => 'shipping_province', 'barangay' => 'destination_barangay', 'postal_code' => 'shipping_postal_code',
                        'latitude' => 'shipping_latitude', 'longitude' => 'shipping_longitude', 'region' => 'shipping_province'];
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors[$fields[$field] ?? $field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }

            foreach ($items as $item) {
                $remaining = $bagQuantities[$item->id] - $item->quantity;
                $line = CartItem::where('cart_id', $cart->id)->whereKey($item->id);
                if ($remaining > 0) {
                    $line->update(['quantity' => $remaining, 'unit_price' => $this->inventory->unitPrice($item->product, $item->color, $item->size)]);
                } else {
                    $line->delete();
                }
            }

            $submission = CheckoutSubmission::create([
                'buyer_id' => $buyer->id, 'cart_id' => $cart->id, 'token_hash' => $tokenHash,
                'request_hash' => $requestHash, 'order_count' => $orders->count(), 'created_at' => now(),
                'fingerprint_version' => 2,
            ]);
            $submission->orders()->attach($orders->pluck('id')->all());
            app(VoucherFundingService::class)->commit($buyer, $submission, $orders, $revisions, $quote['redemptions']);
            if ($data['payment_method'] === 'wallet') {
                foreach ($orders as $order) {
                    app(WalletPaymentService::class)->issue($order);
                }
            }

            return $orders;
        });
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
