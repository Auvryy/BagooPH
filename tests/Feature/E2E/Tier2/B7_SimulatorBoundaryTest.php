<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\CommissionLedger;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B7_SimulatorBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b7_01_simulator_advance_on_already_delivered_order_returns_safe_noop(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($buyer);
        $deliveredOrder = $this->fastForwardToDelivered($order);

        $response = $this->postJson(route('simulator.orders.advance', $deliveredOrder->id));

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Order is already delivered and settled.',
        ]);

        $this->assertEquals('delivered', $deliveredOrder->fresh()->status);
        $this->assertEquals('delivered', $deliveredOrder->fresh()->delivery->status);
    }

    public function test_b7_02_simulator_advance_on_cancelled_order_returns_error(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');

        $this->actingAs($buyer);
        $response = $this->postJson(route('simulator.orders.advance', $order->id));

        $response->assertStatus(400);
        $response->assertJson([
            'error' => 'Cannot advance a cancelled order.',
        ]);

        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    public function test_b7_03_simulator_reset_endpoint_reverts_order_to_pending_and_delivery_to_unassigned(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        $this->actingAs($buyer);
        $deliveredOrder = $this->fastForwardToDelivered($order);

        $this->assertEquals('delivered', $deliveredOrder->fresh()->status);
        $this->assertNotNull($deliveredOrder->fresh()->commissionLedger);

        // Call reset endpoint
        $resetResponse = $this->postJson(route('simulator.orders.reset', $deliveredOrder->id));

        $resetResponse->assertOk();
        $resetResponse->assertJson([
            'success' => true,
            'message' => "Order #{$deliveredOrder->order_number} reset to pending.",
        ]);

        $deliveredOrder->refresh();
        $this->assertEquals('pending', $deliveredOrder->status);
        $this->assertEquals('pending', $deliveredOrder->payment_status);
        $this->assertEquals('unassigned', $deliveredOrder->delivery->status);
        $this->assertNull($deliveredOrder->delivery->assigned_at);
        $this->assertNull($deliveredOrder->delivery->delivered_at);

        // Checkpoints wiped on reset
        $this->assertEquals(0, DeliveryCheckpoint::where('delivery_id', $deliveredOrder->delivery->id)->count());

        // Commission ledger deleted on reset
        $this->assertNull(CommissionLedger::where('order_id', $deliveredOrder->id)->first());
    }

    public function test_b7_04_simulator_endpoints_reject_invalid_or_non_existent_order_ids(): void
    {
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin);

        // Advance on non-existent order ID
        $advanceResponse = $this->postJson('/simulator/orders/99999/advance');
        $advanceResponse->assertNotFound();

        // Reset on non-existent order ID
        $resetResponse = $this->postJson('/simulator/orders/99999/reset');
        $resetResponse->assertNotFound();
    }

    public function test_b7_05_unauthenticated_request_to_simulator_endpoint_is_blocked(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'pending');

        // Unauthenticated advance request
        $response = $this->post(route('simulator.orders.advance', $order->id));
        $response->assertRedirect(route('login'));

        // Unauthenticated reset request
        $resetResponse = $this->post(route('simulator.orders.reset', $order->id));
        $resetResponse->assertRedirect(route('login'));
    }
}
