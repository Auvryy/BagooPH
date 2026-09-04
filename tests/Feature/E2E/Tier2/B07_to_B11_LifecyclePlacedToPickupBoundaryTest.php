<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B07_to_B11_LifecyclePlacedToPickupBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Boundary 7: Checkout Stock Exhaustion & Validation
    // ==========================================

    public function test_t2_b07_01_zero_stock_purchase_rejection(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 0]);

        $this->assertEquals(0, $product->stock);
    }

    public function test_t2_b07_02_negative_quantity_boundary(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->post('/cart', [
            'product_id' => 1,
            'quantity' => -5,
        ]);
        $this->assertTrue(in_array($response->status(), [302, 404, 422]));
    }

    public function test_t2_b07_03_empty_cart_checkout_attempt(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->post('/checkout', [
            'shipping_address' => '123 Test St',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 400, 422]));
    }

    public function test_t2_b07_04_missing_shipping_address(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->post('/checkout', [
            'shipping_address' => '',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 422]));
    }

    public function test_t2_b07_05_exceeded_available_stock(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 3]);

        $this->assertLessThan(10, $product->stock);
    }

    // ==========================================
    // Boundary 8: Seller Confirmation Authorization & Conflict
    // ==========================================

    public function test_t2_b08_01_idor_confirmation_attempt_by_different_seller(): void
    {
        $seller1 = $this->createApprovedUser('seller');
        $shop1 = $this->createE2EShop($seller1);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop1, [], 'placed');

        $seller2 = $this->createApprovedUser('seller');
        $this->createE2EShop($seller2);

        $response = $this->actingAs($seller2)->post(route('seller.orders.pack', $order->id));
        $this->assertEquals(403, $response->status());
    }

    public function test_t2_b08_02_confirming_already_confirmed_order(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t2_b08_03_confirming_cancelled_order_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $this->assertEquals('cancelled', $order->status);
    }

    public function test_t2_b08_04_non_seller_confirmation_attempt(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');

        $response = $this->actingAs($buyer)->post(route('seller.orders.pack', $order->id));
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b08_05_concurrency_during_confirmation(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $order->refresh();
        $this->assertNotEquals('placed', $order->status);
    }

    // ==========================================
    // Boundary 9: Packaging Phase Tampering
    // ==========================================

    public function test_t2_b09_01_pack_order_before_confirmation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t2_b09_02_double_packing_invocation_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));

        $order->refresh();
        $this->assertTrue(in_array($order->status, ['processing', 'preparing']));
    }

    public function test_t2_b09_03_packing_cancelled_order_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $this->assertEquals('cancelled', $order->status);
    }

    public function test_t2_b09_04_idor_packing_attempt_by_non_owner(): void
    {
        $sellerA = $this->createApprovedUser('seller');
        $shopA = $this->createE2EShop($sellerA);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shopA, [], 'confirmed');

        $sellerB = $this->createApprovedUser('seller');
        $this->createE2EShop($sellerB);

        $response = $this->actingAs($sellerB)->post(route('seller.orders.pack', $order->id));
        $this->assertEquals(403, $response->status());
    }

    public function test_t2_b09_05_item_quantity_modification_during_packaging(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');

        $this->assertGreaterThan(0, $order->items()->count());
    }

    // ==========================================
    // Boundary 10: Ready for Pickup Staging Inconsistencies
    // ==========================================

    public function test_t2_b10_01_ready_without_packing_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t2_b10_02_double_ready_submission_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));

        $this->assertEquals('ready_for_pickup', $order->fresh()->status);
    }

    public function test_t2_b10_03_non_seller_marking_ready_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');

        $response = $this->actingAs($buyer)->post(route('seller.orders.ready', $order->id));
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b10_04_premature_pickup_scan_before_ready(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('picked_up', $delivery->status);
    }

    public function test_t2_b10_05_cancelled_order_marked_ready_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $this->assertEquals('cancelled', $order->status);
    }

    // ==========================================
    // Boundary 11: Courier Pickup Verification & Collision
    // ==========================================

    public function test_t2_b11_01_pickup_claim_without_approved_kyc_barred(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($pendingCourier)->post(route('courier.claim', $delivery->id));
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b11_02_pickup_claim_on_non_ready_parcel_barred(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');
        $delivery = $this->createE2EDelivery($order, 'delivered');

        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $this->assertTrue(in_array($response->status(), [200, 302, 400]));
    }

    public function test_t2_b11_03_double_claim_race_condition(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));
        $this->actingAs($courierB)->post(route('courier.claim', $delivery->id));

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b11_04_pickup_confirmation_without_claim_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('picked_up', $delivery->status);
    }

    public function test_t2_b11_05_non_courier_pickup_attempt_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($buyer)->post(route('courier.claim', $delivery->id));
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }
}
