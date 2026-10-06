<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCheckoutNetwork;
use Tests\TestCase;

class DeliveryPhoneConsistencyTest extends TestCase
{
    use InteractsWithCheckoutNetwork;
    use RefreshDatabase;

    public function test_checkout_populates_delivery_phone_and_variant_fields(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'name' => 'Fashion Haven',
            'slug' => 'fashion-haven',
            'phone' => '+63 917 111 0000',
            'address' => '123 Market St',
            'city' => 'Makati City',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
            'is_active' => true,
            'parent_id' => $shop->root_category_id,
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Classic Tee',
            'slug' => 'classic-tee',
            'price' => 500.00,
            'stock' => 50,
            'sku' => 'TEE-001',
            'variants' => ['colors' => [['name' => 'Navy Blue']], 'sizes' => [['name' => 'L', 'stock' => 50]]],
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->createCheckoutNetwork($shop, ['Pasig City' => 'Metro Manila']);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 500.00,
            'color' => 'Navy Blue',
            'size' => 'L',
            'sku_snapshot' => 'TEE-001-Navy Blue-L',
        ]);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Jane Buyer',
            'recipient_phone' => '+63 918 765 4321',
            'shipping_address' => '456 Acacia Ave',
            'shipping_city' => 'Pasig City',
            'shipping_province' => 'Metro Manila',
            'destination_barangay' => 'Poblacion',
            'item_ids' => $cart->items()->pluck('id')->all(),
            'shipping_postal_code' => '1600',
            'payment_method' => 'cod',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));

        $order = Order::latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('+63 918 765 4321', $order->recipient_phone);

        $orderItem = OrderItem::where('order_id', $order->id)->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals('Navy Blue', $orderItem->color);
        $this->assertEquals('L', $orderItem->size);
        $this->assertEquals('TEE-001-Navy Blue-L', $orderItem->sku_snapshot);

        $delivery = Delivery::where('order_id', $order->id)->first();
        $this->assertNotNull($delivery);
        $this->assertEquals('+63 918 765 4321', $delivery->delivery_phone);
    }

    public function test_seller_order_ready_preserves_the_checkout_delivery_and_phone(): void
    {
        $seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $shop = Shop::factory()->approved()->create([
            'user_id' => $seller->id,
            'name' => 'Tech Hub',
            'slug' => 'tech-hub',
            'status' => 'active',
        ]);

        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $category = Category::create([
            'name' => 'Gadgets',
            'slug' => 'gadgets',
            'is_active' => true,
            'parent_id' => $shop->root_category_id,
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Smart Watch',
            'slug' => 'smart-watch',
            'price' => 1000.00,
            'stock' => 10,
            'status' => 'active',
        ]);

        $this->createCheckoutNetwork($shop, ['Taguig' => 'Metro Manila']);
        $this->actingAs($buyer)->post('/cart', ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Mark Recipient', 'recipient_phone' => '+63 919 888 7777',
            'shipping_address' => '789 Pine Street', 'shipping_city' => 'Taguig',
            'shipping_province' => 'Metro Manila', 'shipping_postal_code' => '1634',
            'destination_barangay' => 'Fort Bonifacio', 'payment_method' => 'cod',
            'item_ids' => Cart::where('user_id', $buyer->id)->firstOrFail()->items()->pluck('id')->all(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $order = Order::where('buyer_id', $buyer->id)->firstOrFail();
        $deliveryId = $order->delivery->id;
        $this->actingAs($seller)->post("/seller/orders/{$order->id}/accept")->assertSessionHas('success');
        $this->actingAs($seller)->post("/seller/orders/{$order->id}/pack")->assertSessionHas('success');

        $response = $this->actingAs($seller)->post("/seller/orders/{$order->id}/ready");
        $response->assertSessionHas('success');

        $delivery = Delivery::where('order_id', $order->id)->first();
        $this->assertNotNull($delivery);
        $this->assertSame($deliveryId, $delivery->id);
        $this->assertSame('ready_for_pickup', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(1, Delivery::where('order_id', $order->id)->count());
        $this->assertEquals('+63 919 888 7777', $delivery->delivery_phone);
    }
}
