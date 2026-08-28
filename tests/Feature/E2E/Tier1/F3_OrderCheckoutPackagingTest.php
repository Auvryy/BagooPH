<?php

namespace Tests\Feature\E2E\Tier1;

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

class F3_OrderCheckoutPackagingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f3_01_buyer_can_checkout_with_variant_persistence_in_pending_status(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 750.00, 'stock' => 20]);

        $cart = Cart::create(['user_id' => $buyer->id]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => $product->price,
            'subtotal' => $product->price * 2,
            'color' => 'Navy Blue',
            'size' => 'L',
            'sku_snapshot' => $product->sku,
        ]);

        $response = $this->actingAs($buyer)->post('/checkout', [
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 123 4567',
            'shipping_address' => 'Unit 12B Infinity Tower',
            'shipping_city' => 'Taguig City',
            'shipping_postal_code' => '1634',
            'payment_method' => 'cod',
            'notes' => 'Leave with front desk concierge',
        ]);

        $response->assertRedirect(route('buyer.orders.index'));

        $this->assertDatabaseHas('orders', [
            'buyer_id' => $buyer->id,
            'payment_method' => 'cod',
            'subtotal' => 1500.00,
            'total_amount' => 1550.00,
        ]);

        $order = Order::where('buyer_id', $buyer->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals(1, $order->items()->count());
    }

    public function test_f3_02_buyer_can_apply_valid_voucher_during_checkout(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['price' => 1000.00, 'stock' => 10]);

        $voucher = $this->createE2EVoucher($shop, [
            'code' => 'BAGOO100',
            'discount_type' => 'fixed',
            'discount_value' => 100.00,
            'min_spend' => 500.00,
            'is_active' => true,
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
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 123 4567',
            'shipping_address' => 'Unit 12B Infinity Tower',
            'shipping_city' => 'Taguig City',
            'payment_method' => 'cod',
            'voucher_code' => 'BAGOO100',
        ]);

        $order = Order::where('buyer_id', $buyer->id)->latest()->first();
        $this->assertNotNull($order);
        // Subtotal (1000) + Shipping (50) - Voucher (100) = 950
        $this->assertEquals(950.00, (float) $order->total_amount);
    }

    public function test_f3_03_seller_can_view_incoming_pending_order_in_cockpit(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $order = $this->createE2EOrder($buyer, $shop, [], 'processing');

        $response = $this->actingAs($seller)->get(route('seller.orders.index'));

        $response->assertOk();
    }

    public function test_f3_04_seller_can_approve_and_transition_order_to_packaging(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $response = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));

        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('processing', $order->status);
    }

    public function test_f3_05_seller_can_mark_order_ready_for_pickup_generating_waybill(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);

        $order = $this->createE2EOrder($buyer, $shop, [], 'processing');

        $response = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));

        $response->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);

        $this->assertNotNull($order->delivery);
        $this->assertEquals('unassigned', $order->delivery->status);
        $this->assertNotNull($order->delivery->tracking_number);
    }
}
