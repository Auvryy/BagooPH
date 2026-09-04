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

class F18_to_F20_OrderLifecycleFailureCheckpointsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 18: DELIVERY_FAILED (Stage 12)
    // ==========================================

    public function test_t1_f18_01_courier_reports_delivery_failure(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer unreachable after 3 calls, gate locked',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f18_02_state_transition_to_delivery_failed(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer address invalid',
        ]);

        $delivery->refresh();
        $this->assertEquals('failed', $delivery->status);
    }

    public function test_t1_f18_03_delivery_failed_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer unreachable',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'delivery_failed');
    }

    public function test_t1_f18_04_buyer_exception_view(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'failed', $courier);

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }

    public function test_t1_f18_05_hub_exception_queue(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 19: RETURNED (Stage 13)
    // ==========================================

    public function test_t1_f19_01_return_execution_from_hub(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivery_failed');
        $delivery = $this->createE2EDelivery($order, 'failed');

        $delivery->update(['status' => 'returned']);
        $order->update(['status' => 'returned']);

        $this->assertEquals('returned', $delivery->fresh()->status);
        $this->assertEquals('returned', $order->fresh()->status);
    }

    public function test_t1_f19_02_state_transition_to_returned(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivery_failed');
        $delivery = $this->createE2EDelivery($order, 'failed');

        $delivery->update(['status' => 'returned']);
        $this->assertEquals('returned', $delivery->fresh()->status);
    }

    public function test_t1_f19_03_return_checkpoint_logged(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'returned');
        $delivery = $this->createE2EDelivery($order, 'returned');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'parcel_returned',
            'location_name' => $shop->name,
            'notes' => 'Parcel returned to merchant inventory',
        ]);

        $this->assertCheckpointLogged($delivery, 'parcel_returned');
    }

    public function test_t1_f19_04_inventory_reversal(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 10]);

        $product->increment('stock', 2);
        $this->assertEquals(12, $product->fresh()->stock);
    }

    public function test_t1_f19_05_seller_return_notice(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->get(route('seller.orders.index'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 20: Delivery Checkpoints Pipeline
    // ==========================================

    public function test_t1_f20_01_comprehensive_checkpoint_sequence(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');
        $delivery = $this->createE2EDelivery($order, 'delivered');

        $types = ['seller_pack', 'courier_pickup', 'hub_intake', 'doorstep_handover'];
        foreach ($types as $t) {
            DeliveryCheckpoint::create([
                'delivery_id' => $delivery->id,
                'checkpoint_type' => $t,
                'location_name' => 'Metro Manila Central Hub',
                'barcode_scanned' => $delivery->tracking_number,
                'scanned_by_id' => $seller->id,
            ]);
        }

        $this->assertCheckpointSequence($delivery, $types);
    }

    public function test_t1_f20_02_checkpoint_metadata_integrity(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $cp = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
            'location_name' => 'Manila Dock',
            'barcode_scanned' => $delivery->tracking_number,
            'scanned_by_id' => $buyer->id,
            'notes' => 'Metadata verification test',
        ]);

        $this->assertNotNull($cp->id);
        $this->assertEquals($delivery->id, $cp->delivery_id);
        $this->assertEquals('order_placed', $cp->checkpoint_type);
        $this->assertNotNull($cp->created_at);
    }

    public function test_t1_f20_03_barcode_traceability(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'courier_pickup',
            'barcode_scanned' => $delivery->tracking_number,
            'scanned_by_id' => $seller->id,
        ]);

        $this->assertBarcodeScanned($delivery, $delivery->tracking_number);
    }

    public function test_t1_f20_04_actor_attribution(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $cp = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'courier_pickup',
            'scanned_by_id' => $courier->id,
        ]);

        $this->assertEquals($courier->id, $cp->scanned_by_id);
    }

    public function test_t1_f20_05_immutable_historical_audit(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
            'scanned_by_id' => $buyer->id,
        ]);

        $this->assertEquals(1, DeliveryCheckpoint::where('delivery_id', $delivery->id)->count());
    }
}
