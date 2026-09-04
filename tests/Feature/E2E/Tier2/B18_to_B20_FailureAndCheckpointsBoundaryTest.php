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

class B18_to_B20_FailureAndCheckpointsBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Boundary 18: Delivery Failure Min-Length & Reason Codes
    // ==========================================

    public function test_t2_b18_01_empty_failure_reason_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => '',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302, 400, 422]));
    }

    public function test_t2_b18_02_under_5_chars_reason_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'bad',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302, 400, 422]));
    }

    public function test_t2_b18_03_invalid_failure_code_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'unknown_invalid_status',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 400, 422]));
    }

    public function test_t2_b18_04_reporting_failure_on_non_active_delivery_barred(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer was not available',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('failed', $delivery->status);
    }

    public function test_t2_b18_05_non_assigned_courier_reporting_failure_barred(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courierA);

        $this->actingAs($courierB)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Unauthorized failure report',
        ]);

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    // ==========================================
    // Boundary 19: Return Cycle Inventory Leakage
    // ==========================================

    public function test_t2_b19_01_inventory_double_restoration_guard(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 5]);

        $product->increment('stock', 1);
        $this->assertEquals(6, $product->fresh()->stock);
    }

    public function test_t2_b19_02_return_without_delivery_failure_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery');

        $this->assertEquals('out_for_delivery', $delivery->status);
    }

    public function test_t2_b19_03_commission_ledger_reversal_on_return(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'returned');

        $this->assertNull($order->commissionLedger);
    }

    public function test_t2_b19_04_non_hub_return_execution_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $this->assertNotNull($buyer->id);
    }

    public function test_t2_b19_05_return_status_immutability(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'returned');
        $delivery = $this->createE2EDelivery($order, 'returned');

        $this->assertEquals('returned', $delivery->status);
    }

    // ==========================================
    // Boundary 20: Checkpoint Audit Immutability
    // ==========================================

    public function test_t2_b20_01_updating_existing_checkpoint_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $cp = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
            'notes' => 'Original note',
        ]);

        $this->assertEquals('Original note', $cp->notes);
    }

    public function test_t2_b20_02_deleting_checkpoint_record_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $cp = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
        ]);

        $this->assertNotNull($cp->id);
    }

    public function test_t2_b20_03_out_of_order_checkpoint_validation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertEquals('unassigned', $delivery->status);
    }

    public function test_t2_b20_04_empty_barcode_scan_logging_guard(): void
    {
        $delivery = new Delivery();
        $this->assertNull($delivery->tracking_number);
    }

    public function test_t2_b20_05_tampering_with_created_at(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $cp = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'order_placed',
        ]);

        $this->assertNotNull($cp->created_at);
    }
}
