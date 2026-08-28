<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F7_OrderSimulatorTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f7_01_simulator_advance_endpoint_progresses_order_from_pending_to_packaging(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($buyer);
        $response = $this->advanceOrderStage($order);

        $response->assertOk();
        $this->assertOrderStage($order, 'processing', 'unassigned');
    }

    public function test_f7_02_simulator_advance_endpoint_progresses_order_to_ready_for_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($seller);
        // pending -> processing
        $this->advanceOrderStage($order);
        // processing -> ready_for_pickup
        $response = $this->advanceOrderStage($order);

        $response->assertOk();
        $this->assertOrderStage($order, 'ready_for_pickup', 'unassigned');
    }

    public function test_f7_03_simulator_advance_endpoint_auto_assigns_courier_and_progresses_to_picked_up(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');

        $this->actingAs($courier);
        $response = $this->advanceOrderStage($order);

        $response->assertOk();
        $this->assertOrderStage($order, 'shipped', 'picked_up');
    }

    public function test_f7_04_simulator_advance_endpoint_progresses_through_in_transit_to_out_for_delivery(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);

        $this->actingAs($courier);

        // picked_up -> in_transit
        $this->advanceOrderStage($order);
        $this->assertOrderStage($order, 'shipped', 'in_transit');

        // in_transit -> out_for_delivery
        $this->advanceOrderStage($order);
        $this->assertOrderStage($order, 'shipped', 'out_for_delivery');
    }

    public function test_f7_05_simulator_advance_endpoint_delivers_order_and_executes_commission_split(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($admin = $this->createApprovedUser('admin'));

        $deliveredOrder = $this->fastForwardToDelivered($order);

        $this->assertEquals('delivered', $deliveredOrder->status);
        $this->assertEquals('delivered', $deliveredOrder->delivery->status);
        $this->assertNotNull($deliveredOrder->commissionLedger);

        $this->assertCommissionSplit($deliveredOrder);
    }
}
