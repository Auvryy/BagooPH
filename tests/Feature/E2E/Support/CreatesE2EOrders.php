<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Support\Str;

trait CreatesE2EOrders
{
    public function createE2EShop(User $seller, array $attributes = []): Shop
    {
        if ($seller->shop) {
            $seller->shop->update($attributes);
            return $seller->shop->fresh();
        }

        return Shop::factory()->create(array_merge([
            'user_id' => $seller->id,
            'name' => $seller->name . "'s Artisan Boutique",
            'status' => 'active',
        ], $attributes));
    }

    public function createE2EProduct(Shop $shop, array $attributes = []): Product
    {
        $category = Category::first() ?? Category::factory()->create();

        return Product::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'status' => 'active',
            'stock' => 50,
            'price' => 450.00,
        ], $attributes));
    }

    public function createE2EOrder(User $buyer, Shop $shop, array $items = [], string $status = 'pending'): Order
    {
        $subtotal = 0;
        $orderItemsData = [];

        if (empty($items)) {
            $product = $this->createE2EProduct($shop);
            $qty = 2;
            $unitPrice = (float) $product->price;
            $lineSubtotal = $qty * $unitPrice;
            $subtotal += $lineSubtotal;

            $orderItemsData[] = [
                'product' => $product,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $lineSubtotal,
                'color' => 'Classic Black',
                'size' => 'M',
                'sku_snapshot' => $product->sku,
            ];
        } else {
            foreach ($items as $item) {
                $product = $item['product'] ?? $this->createE2EProduct($shop);
                $qty = $item['quantity'] ?? 1;
                $unitPrice = (float) ($item['unit_price'] ?? $product->price);
                $lineSubtotal = $qty * $unitPrice;
                $subtotal += $lineSubtotal;

                $orderItemsData[] = [
                    'product' => $product,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'subtotal' => $lineSubtotal,
                    'color' => $item['color'] ?? 'Default',
                    'size' => $item['size'] ?? 'Standard',
                    'sku_snapshot' => $item['sku_snapshot'] ?? $product->sku,
                ];
            }
        }

        $shippingFee = $subtotal > 1500 ? 0.00 : 50.00;
        $totalAmount = $subtotal + $shippingFee;

        $order = Order::factory()->create([
            'order_number' => 'BGO-' . strtoupper(Str::random(8)),
            'buyer_id' => $buyer->id,
            'subtotal' => $subtotal,
            'shipping_fee' => $shippingFee,
            'total_amount' => $totalAmount,
            'payment_method' => 'cod',
            'payment_status' => $status === 'delivered' ? 'paid' : 'pending',
            'status' => $status,
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone ?? '+63 917 123 4567',
            'shipping_address' => $buyer->address ?? '123 Bonifacio High St',
            'shipping_city' => $buyer->city ?? 'Taguig',
            'shipping_postal_code' => $buyer->postal_code ?? '1634',
        ]);

        foreach ($orderItemsData as $data) {
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $data['product']->id,
                'shop_id' => $shop->id,
                'quantity' => $data['quantity'],
                'unit_price' => $data['unit_price'],
                'subtotal' => $data['subtotal'],
                'color' => $data['color'],
                'size' => $data['size'],
                'sku_snapshot' => $data['sku_snapshot'],
            ]);
        }

        return $order->fresh(['items.product', 'buyer']);
    }

    public function createE2EDelivery(Order $order, string $status = 'unassigned', ?User $courier = null, array $attributes = []): Delivery
    {
        $shop = $order->items->first()?->product?->shop;

        return Delivery::factory()->create(array_merge([
            'order_id' => $order->id,
            'courier_id' => $courier?->id,
            'tracking_number' => 'BGO-TRK-' . strtoupper(Str::random(8)),
            'logistics_partner' => 'Bagoo Express Dispatch Fleet',
            'status' => $status,
            'pickup_store_name' => $shop?->name ?? 'Merchant Store',
            'pickup_address' => ($shop?->address ?? 'Merchant Street') . ', ' . ($shop?->city ?? 'Manila'),
            'pickup_phone' => $shop?->phone ?? '+63 917 000 0000',
            'delivery_recipient_name' => $order->recipient_name,
            'delivery_address' => $order->shipping_address . ', ' . $order->shipping_city,
            'delivery_phone' => $order->recipient_phone,
            'assigned_at' => in_array($status, ['assigned', 'assigned_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered']) ? now()->subHours(4) : null,
            'picked_up_at' => in_array($status, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered']) ? now()->subHours(3) : null,
            'delivered_at' => $status === 'delivered' ? now() : null,
            'proof_image' => $status === 'delivered' ? 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500' : null,
        ], $attributes));
    }

    public function createE2EVoucher(Shop $shop, array $attributes = []): Voucher
    {
        return Voucher::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'code' => 'DISC' . strtoupper(Str::random(4)),
            'discount_type' => 'fixed',
            'discount_value' => 50.00,
            'min_spend' => 300.00,
            'is_active' => true,
        ], $attributes));
    }
}
