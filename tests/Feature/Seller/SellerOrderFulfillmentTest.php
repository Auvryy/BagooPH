<?php

namespace Tests\Feature\Seller;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerOrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private Shop $shop;
    private User $buyer;
    private Product $product;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->shop = Shop::create([
            'user_id' => $this->seller->id,
            'name' => 'Artisan Craft Store',
            'slug' => 'artisan-craft-store',
            'status' => 'active',
            'address' => 'Naval Market Road',
            'city' => 'Naval, Biliran',
            'phone' => '+63 912 345 6789',
        ]);

        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $this->category = Category::create([
            'name' => 'Handicrafts',
            'slug' => 'handicrafts',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'shop_id' => $this->shop->id,
            'category_id' => $this->category->id,
            'name' => 'Handwoven Bag',
            'slug' => 'handwoven-bag',
            'price' => 500,
            'stock_quantity' => 20,
            'status' => 'approved',
        ]);
    }

    private function createOrderForShop(string $status = 'pending', ?Shop $targetShop = null): Order
    {
        $shop = $targetShop ?? $this->shop;
        $product = ($shop->id === $this->shop->id) ? $this->product : Product::create([
            'shop_id' => $shop->id,
            'category_id' => $this->category->id,
            'name' => 'Other Product',
            'slug' => 'other-product-' . uniqid(),
            'price' => 300,
            'stock_quantity' => 10,
            'status' => 'approved',
        ]);

        $order = Order::create([
            'buyer_id' => $this->buyer->id,
            'order_number' => 'BGO-' . strtoupper(uniqid()),
            'status' => $status,
            'subtotal' => 500,
            'total_amount' => 500,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'shipping_address' => '123 Pine St',
            'shipping_city' => 'Tacloban City',
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 111 2222',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
        ]);

        return $order;
    }

    public function test_seller_can_view_orders_with_counts(): void
    {
        $this->createOrderForShop('pending');
        $this->createOrderForShop('ready_for_pickup');
        $this->createOrderForShop('delivered');

        $response = $this->actingAs($this->seller)->get(route('seller.orders.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Orders')
            ->has('orderItems.data', 3)
            ->has('counts')
            ->where('counts.all', 3)
            ->where('counts.to_pack', 1)
            ->where('counts.to_pickup', 1)
            ->where('counts.delivered', 1)
        );
    }

    public function test_seller_can_accept_order(): void
    {
        $order = $this->createOrderForShop('pending');

        $response = $this->actingAs($this->seller)->post(route('seller.orders.accept', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('confirmed', $order->fresh()->status);
    }

    public function test_seller_can_pack_order(): void
    {
        $order = $this->createOrderForShop('confirmed');

        $response = $this->actingAs($this->seller)->post(route('seller.orders.pack', $order));

        $response->assertRedirect();
        $this->assertEquals('preparing', $order->fresh()->status);
    }

    public function test_seller_can_mark_order_ready_for_pickup(): void
    {
        $order = $this->createOrderForShop('processing');

        $response = $this->actingAs($this->seller)->post(route('seller.orders.ready', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('ready_for_pickup', $order->fresh()->status);

        $this->assertDatabaseHas('deliveries', [
            'order_id' => $order->id,
            'status' => 'unassigned',
            'pickup_store_name' => $this->shop->name,
        ]);

        $this->assertDatabaseHas('delivery_checkpoints', [
            'checkpoint_type' => 'seller_pack',
        ]);
    }

    public function test_seller_can_batch_mark_orders_ready_for_pickup(): void
    {
        $order1 = $this->createOrderForShop('processing');
        $order2 = $this->createOrderForShop('confirmed');

        $response = $this->actingAs($this->seller)->post(route('seller.orders.batchReady'), [
            'order_ids' => [$order1->id, $order2->id],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('ready_for_pickup', $order1->fresh()->status);
        $this->assertEquals('ready_for_pickup', $order2->fresh()->status);
    }

    public function test_seller_can_cancel_order_with_reason(): void
    {
        $order = $this->createOrderForShop('pending');

        $response = $this->actingAs($this->seller)->post(route('seller.orders.cancel', $order), [
            'reason' => 'Out of stock / Inventory shortage',
            'notes' => 'Supplier delay for raw fibers',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertStringContainsString('Out of stock', $order->fresh()->cancellation_reason);
    }

    public function test_seller_cannot_modify_orders_of_other_merchants(): void
    {
        $otherSeller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $otherShop = Shop::create([
            'user_id' => $otherSeller->id,
            'name' => 'Other Merchant',
            'slug' => 'other-merchant',
            'status' => 'active',
        ]);

        $otherOrder = $this->createOrderForShop('pending', $otherShop);

        $response = $this->actingAs($this->seller)->post(route('seller.orders.accept', $otherOrder));
        $response->assertForbidden();

        $response = $this->actingAs($this->seller)->post(route('seller.orders.cancel', $otherOrder), [
            'reason' => 'Other',
        ]);
        $response->assertForbidden();
    }

    public function test_seller_can_confirm_handover_to_courier(): void
    {
        $order = $this->createOrderForShop('ready_for_pickup');
        $delivery = Delivery::create([
            'order_id' => $order->id,
            'tracking_number' => 'BGO-TEST-HANDOVER',
            'status' => 'unassigned',
            'pickup_store_name' => $this->shop->name,
            'pickup_address' => $this->shop->address,
            'delivery_recipient_name' => $order->recipient_name,
            'delivery_address' => $order->shipping_address,
            'delivery_phone' => $order->recipient_phone,
        ]);

        $response = $this->actingAs($this->seller)->post(route('seller.orders.handover', $order));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals('picked_up', $order->fresh()->status);
        $this->assertEquals('picked_up', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->picked_up_at);

        $this->assertDatabaseHas('delivery_checkpoints', [
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'courier_pickup',
        ]);
    }

    public function test_seller_orders_paginated_to_10_per_page(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->createOrderForShop('pending');
        }

        $response = $this->actingAs($this->seller)->get(route('seller.orders.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Orders')
            ->has('orderItems.data', 10)
            ->where('orderItems.per_page', 10)
            ->where('orderItems.total', 15)
            ->where('orderItems.current_page', 1)
            ->where('orderItems.last_page', 2)
            ->has('orderItems.next_page_url')
        );
    }

    public function test_seller_orders_filter_by_status(): void
    {
        $this->createOrderForShop('pending');
        $this->createOrderForShop('ready_for_pickup');

        $response = $this->actingAs($this->seller)->get(route('seller.orders.index', ['status' => 'to_pack']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Seller/Orders')
            ->has('orderItems.data', 1)
            ->where('currentStatus', 'to_pack')
        );
    }
}

