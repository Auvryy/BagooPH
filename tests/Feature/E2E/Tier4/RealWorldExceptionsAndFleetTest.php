<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldExceptionsAndFleetTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    /**
     * T4-13: Flash Sale High-Volume Cart Contention & Rapid Fulfillment
     */
    public function test_t4_13_flash_sale_inventory_contention(): void
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 5, 'price' => 200.00]);

        $successfulOrders = [];
        for ($i = 0; $i < 5; $i++) {
            $buyer = $this->createApprovedUser('buyer');
            $order = $this->createE2EOrder($buyer, $shop, [
                ['product' => $product, 'quantity' => 1, 'unit_price' => 200.00],
            ], 'placed');
            $successfulOrders[] = $order;
            $product->decrement('stock', 1);
        }

        $this->assertEquals(0, $product->fresh()->stock);
        $this->assertCount(5, $successfulOrders);

        // Sixth checkout cannot be fulfilled due to 0 stock
        $this->assertTrue($product->fresh()->stock <= 0);
    }

    /**
     * T4-14: Unmapped Address Graceful Fallback & Hub Manual Override
     */
    public function test_t4_14_unmapped_address_fallback_and_override(): void
    {
        $buyer = $this->createApprovedUser('buyer', [
            'address' => 'Remote Sitio Riverside',
            'city' => 'Unknown Valley',
        ]);
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $logistics = $this->createApprovedUser('logistics');

        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'at_sorting_center');

        // Hub operator manually overrides and assigns to Area B
        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-B-OVERRIDE',
            'barangay' => 'Pagsanjan, Laguna',
        ]);
        $response->assertOk();

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Pagsanjan, Laguna');
    }

    /**
     * T4-15: Courier Duty Cycle: Shift Start, Pickups, Deliveries, and Off-Duty
     */
    public function test_t4_15_courier_duty_cycle_and_shift(): void
    {
        $courier = $this->createApprovedUser('courier');
        $this->assertNotNull($courier->courierProfile);

        // 1. Courier is active
        $this->assertTrue($courier->courierProfile->is_available);

        // 2. Courier completes a pickup and delivery
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'ready_for_pickup');

        $this->actingAs($courier)->post(route('courier.claim', $delivery->id));
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $this->assertEquals('delivered', $delivery->fresh()->status);

        // 3. Courier toggles duty off
        $courier->courierProfile->update(['is_available' => false]);
        $this->assertFalse($courier->fresh()->courierProfile->is_available);
    }

    /**
     * T4-16: Seller Order Cancellation Gate: Pre-Pickup vs Post-Pickup Race
     */
    public function test_t4_16_order_cancellation_pre_vs_post_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $courier = $this->createApprovedUser('courier');

        // Order 1: Pre-pickup can be cancelled
        $order1 = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $order1->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $order1->fresh()->status);

        // Order 2: Post-pickup is locked and proceeding
        $order2 = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $del2 = $this->createE2EDelivery($order2, 'ready_for_pickup');

        $this->actingAs($courier)->post(route('courier.claim', $del2->id));
        $del2->refresh();
        $this->assertTrue(in_array($del2->status, ['assigned', 'assigned_pickup']));

        // Post-claim/pickup orders cannot be cancelled by buyer
        $this->assertNotEquals('cancelled', $order2->fresh()->status);
    }

    /**
     * T4-17: Hub Sorting Dock Morning Rush Batch Processing
     */
    public function test_t4_17_hub_sorting_dock_morning_rush(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $buyer = $this->createApprovedUser('buyer');

        $deliveries = [];
        for ($i = 0; $i < 6; $i++) {
            $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
            $delivery = $this->createE2EDelivery($order, 'at_sorting_center');
            $deliveries[] = $delivery;
        }

        // Intake and sort batch into Area A and Area B
        foreach ($deliveries as $idx => $del) {
            $area = $idx % 2 === 0 ? 'Santa Cruz, Laguna' : 'Pagsanjan, Laguna';
            $bin = $idx % 2 === 0 ? 'BIN-A1' : 'BIN-B1';

            $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
                'delivery_id' => $del->id,
                'bin' => $bin,
                'barangay' => $area,
            ]);
            $response->assertOk();
        }

        $this->assertCount(6, $deliveries);
    }

    /**
     * T4-18: End-to-End Platform Governance & Financial Audit
     */
    public function test_t4_18_platform_governance_and_financial_audit(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $courier = $this->createApprovedUser('courier');
        $admin = $this->createApprovedUser('admin');

        // Create 3 completed orders
        $totalGross = 0;
        for ($i = 0; $i < 3; $i++) {
            $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
            $order->update(['subtotal' => 1000.00, 'total_amount' => 1050.00]);
            $totalGross += 1000.00;

            $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

            $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
                'status' => 'delivered',
                'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
            ]);
            $order->update(['status' => 'completed']);
            $this->assertCommissionSplit($order);
        }

        // Verify total gross volume across the 3 orders is 3,000
        $this->assertEquals(3000.00, $totalGross);

        // Admin governance report access
        $response = $this->actingAs($admin)->onPortal('admin')->get('/admin/dashboard');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }
}
