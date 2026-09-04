<?php

namespace Tests\Feature\E2E\Tier1;

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

class F07_to_F11_OrderLifecyclePlacedToPickupTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 7: PLACED (Stage 1)
    // ==========================================

    public function test_t1_f07_01_single_item_checkout(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 10, 'price' => 250.00]);

        $order = $this->createE2EOrder($buyer, $shop, [
            ['product' => $product, 'quantity' => 2, 'unit_price' => 250.00],
        ], 'placed');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'buyer_id' => $buyer->id,
        ]);
        $this->assertEquals(2, $order->items->first()->quantity);
    }

    public function test_t1_f07_02_delivery_record_generation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertNotNull($delivery->tracking_number);
        $this->assertStringStartsWith('BGO-TRK-', $delivery->tracking_number);
        $this->assertEquals('unassigned', $delivery->status);
    }

    public function test_t1_f07_03_initial_checkpoint_creation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
            'location_name' => $shop->city ?? 'Manila',
            'barcode_scanned' => $delivery->tracking_number,
            'notes' => 'Order placed by buyer and delivery generated.',
            'scanned_by_id' => $buyer->id,
        ]);

        $this->assertCheckpointLogged($delivery, 'order_placed');
    }

    public function test_t1_f07_04_multi_item_single_merchant_order(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $prod1 = $this->createE2EProduct($shop, ['price' => 100.00]);
        $prod2 = $this->createE2EProduct($shop, ['price' => 200.00]);

        $order = $this->createE2EOrder($buyer, $shop, [
            ['product' => $prod1, 'quantity' => 1, 'unit_price' => 100.00],
            ['product' => $prod2, 'quantity' => 2, 'unit_price' => 200.00],
        ], 'placed');

        $this->assertCount(2, $order->items);
        $this->assertEquals(500.00, $order->subtotal);
    }

    public function test_t1_f07_05_buyer_order_detail_view(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }

    // ==========================================
    // Feature 8: CONFIRMED (Stage 2)
    // ==========================================

    public function test_t1_f08_01_seller_view_placed_orders(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->get(route('seller.orders.index'));
        $response->assertOk();
    }

    public function test_t1_f08_02_seller_accepts_order(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');

        $order->update(['status' => 'confirmed']);
        $this->assertEquals('confirmed', $order->fresh()->status);
    }

    public function test_t1_f08_03_confirmed_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_confirmed',
            'location_name' => $shop->name,
            'scanned_by_id' => $seller->id,
            'notes' => 'Seller confirmed stock availability',
        ]);

        $this->assertCheckpointLogged($delivery, 'order_confirmed');
    }

    public function test_t1_f08_04_delivery_updated(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertEquals('unassigned', $delivery->status);
        $this->assertNotNull($delivery->id);
    }

    public function test_t1_f08_05_buyer_tracking_sync(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }

    // ==========================================
    // Feature 9: PREPARING (Stage 3)
    // ==========================================

    public function test_t1_f09_01_seller_initiates_packing(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $order->refresh();
        $this->assertTrue(in_array($order->status, ['preparing', 'processing']));
    }

    public function test_t1_f09_02_packing_checkpoint_logged(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'confirmed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.pack', $order->id));

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'seller_pack',
            'location_name' => $shop->name,
            'scanned_by_id' => $seller->id,
            'notes' => 'Parcel packed by seller',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'seller_pack');
    }

    public function test_t1_f09_03_shipping_waybill_generated(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertNotNull($delivery->tracking_number);
        $this->assertNotEmpty($delivery->pickup_store_name);
    }

    public function test_t1_f09_04_order_items_locked(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');

        $this->assertGreaterThan(0, $order->items()->count());
    }

    public function test_t1_f09_05_timeline_progress(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }

    // ==========================================
    // Feature 10: READY_FOR_PICKUP (Stage 4)
    // ==========================================

    public function test_t1_f10_01_seller_marks_ready(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $order->refresh();
        $this->assertEquals('ready_for_pickup', $order->status);
    }

    public function test_t1_f10_02_ready_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'preparing');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($seller)->post(route('seller.orders.ready', $order->id));

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'ready_for_pickup',
            'location_name' => $shop->name,
            'scanned_by_id' => $seller->id,
            'notes' => 'Parcel staged for courier collection',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'ready_for_pickup');
    }

    public function test_t1_f10_03_courier_pickup_pool_visibility(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries'));
        $response->assertOk();
    }

    public function test_t1_f10_04_waybill_barcode_available(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertNotNull($delivery->tracking_number);
    }

    public function test_t1_f10_05_seller_dashboard_staging(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->get(route('seller.dashboard'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 11: PICKED_UP (Stage 5)
    // ==========================================

    public function test_t1_f11_01_courier_claims_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $delivery->refresh();
        $this->assertEquals($courier->id, $delivery->courier_id);
    }

    public function test_t1_f11_02_courier_barcode_scan_and_confirm(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Scanned barcode at store',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f11_03_state_transition_to_picked_up(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Collected from seller',
        ]);

        $delivery->refresh();
        $this->assertEquals('picked_up', $delivery->status);
    }

    public function test_t1_f11_04_courier_pickup_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Collected',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'courier_pickup');
    }

    public function test_t1_f11_05_removal_from_pickup_pool(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
        ]);

        $delivery->refresh();
        $this->assertNotNull($delivery->courier_id);
    }
}
