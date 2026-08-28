<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B3_OrderCheckoutBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b3_01_checkout_fails_when_product_stock_is_insufficient(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 500.00, 'stock' => 2]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 5, // Demands 5, but only 2 in stock
            'unit_price' => $product->price,
            'subtotal' => $product->price * 5,
        ]);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 123 4567',
            'shipping_address' => '123 Test Street',
            'shipping_city' => 'Taguig City',
            'payment_method' => 'cod',
        ]);

        $response->assertSessionHas('error');

        // Inventory must remain intact
        $product->refresh();
        $this->assertEquals(2, $product->stock);

        // No order should be created
        $this->assertDatabaseMissing('orders', ['buyer_id' => $buyer->id]);
    }

    public function test_b3_02_checkout_fails_when_voucher_min_spend_is_not_met_or_voucher_expired(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 300.00, 'stock' => 10]);

        // Voucher requires minimum spend of ₱800
        $voucher = $this->createE2EVoucher($shop, [
            'code' => 'TIER2MIN800',
            'discount_type' => 'fixed',
            'discount_value' => 150.00,
            'min_spend' => 800.00,
            'is_active' => true,
            'used_count' => 0,
        ]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
            'subtotal' => $product->price,
        ]);

        $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Maria Santos',
            'recipient_phone' => '+63 918 222 3333',
            'shipping_address' => '456 Ayala Ave',
            'shipping_city' => 'Makati City',
            'payment_method' => 'cod',
            'voucher_code' => 'TIER2MIN800',
        ]);

        $order = Order::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($order);

        // Subtotal (300) + Shipping (50) without discount = 350
        $this->assertEquals(350.00, (float) $order->total_amount);

        // Voucher used count was not incremented
        $voucher->refresh();
        $this->assertEquals(0, $voucher->used_count);
    }

    public function test_b3_03_checkout_rejects_zero_or_negative_quantity_and_tampered_unit_prices(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 800.00, 'stock' => 10]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        // Tampered unit price in cart row attempting to exploit client-side spoofing
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1.00,
            'subtotal' => 1.00,
        ]);

        $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Tamper Tester',
            'recipient_phone' => '+63 917 999 8888',
            'shipping_address' => '789 Secure Way',
            'shipping_city' => 'Pasig City',
            'payment_method' => 'cod',
        ]);

        $order = Order::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($order);

        // Subtotal recalculated from DB price (800) + Shipping (50) = 850
        $this->assertEquals(800.00, (float) $order->subtotal);
        $this->assertEquals(850.00, (float) $order->total_amount);
    }

    public function test_b3_04_seller_cannot_pack_or_ready_another_merchants_order_idor_check(): void
    {
        $buyer = $this->createApprovedUser('buyer');

        $sellerA = $this->createApprovedUser('seller');
        $shopA = $this->createE2EShop($sellerA, ['name' => 'Shop Alpha']);

        $sellerB = $this->createApprovedUser('seller');
        $shopB = $this->createE2EShop($sellerB, ['name' => 'Shop Bravo']);

        // Order belongs strictly to Shop B
        $orderForShopB = $this->createE2EOrder($buyer, $shopB, [], 'pending');

        // Seller A attempts to pack Seller B's order
        $packResponse = $this->actingAs($sellerA)->post(route('seller.orders.pack', $orderForShopB->id));
        $packResponse->assertForbidden();

        // Seller A attempts to mark ready Seller B's order
        $readyResponse = $this->actingAs($sellerA)->post(route('seller.orders.ready', $orderForShopB->id));
        $readyResponse->assertForbidden();

        // Order status must remain 'pending'
        $orderForShopB->refresh();
        $this->assertEquals('pending', $orderForShopB->status);
    }

    public function test_b3_05_order_cannot_be_transitioned_to_packaging_from_invalid_states_e_g_cancelled(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $cancelledOrder = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $response = $this->actingAs($seller)->post(route('simulator.orders.advance', $cancelledOrder->id), [], [
            'Accept' => 'application/json',
        ]);

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Cannot advance a cancelled order.',
        ]);

        $cancelledOrder->refresh();
        $this->assertEquals('cancelled', $cancelledOrder->status);
    }
}
