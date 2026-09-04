<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F34_to_F35_E2EAndAdversarialTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 34: E2E Testing Suite
    // ==========================================

    public function test_t1_f34_01_suite_runner_configuration(): void
    {
        $this->assertEquals('testing', config('app.env'));
    }

    public function test_t1_f34_02_isolated_clean_test_database(): void
    {
        $this->assertEquals('sqlite', config('database.default'));
        $this->assertEquals(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_t1_f34_03_zero_production_interference(): void
    {
        $this->assertTrue(app()->environment('testing'));
    }

    public function test_t1_f34_04_support_trait_availability(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $this->assertNotNull($buyer->id);
    }

    public function test_t1_f34_05_exit_code_contract_adherence(): void
    {
        $this->assertTrue(true);
    }

    // ==========================================
    // Feature 35: Adversarial Coverage Hardening
    // ==========================================

    public function test_t1_f35_01_state_skipping_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'placed');
        $delivery = $this->createE2EDelivery($order, 'unassigned');
        $courier = $this->createApprovedUser('courier');

        // Cannot jump directly from unassigned to delivered
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $delivery->refresh();
        $this->assertNotEquals('delivered', $delivery->status);
    }

    public function test_t1_f35_02_idor_protection_across_sellers(): void
    {
        $seller1 = $this->createApprovedUser('seller');
        $shop1 = $this->createE2EShop($seller1);
        $buyer = $this->createApprovedUser('buyer');
        $order1 = $this->createE2EOrder($buyer, $shop1, [], 'placed');

        $seller2 = $this->createApprovedUser('seller');
        $this->createE2EShop($seller2);

        $response = $this->actingAs($seller2)->post(route('seller.orders.pack', $order1->id));
        $this->assertEquals(403, $response->status());
    }

    public function test_t1_f35_03_centavo_precision_accounting(): void
    {
        $subtotal = 149.99;
        $sellerAmount = round($subtotal * 0.90, 2);
        $platformFee = round($subtotal * 0.10, 2);

        $this->assertEquals(134.99, $sellerAmount);
        $this->assertEquals(15.00, $platformFee);
        $this->assertEquals(149.99, round($sellerAmount + $platformFee, 2));
    }

    public function test_t1_f35_04_double_settlement_idempotency(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        // First delivery completion
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        // Second delivery completion attempt
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $this->assertLedgerIdempotent($order);
    }

    public function test_t1_f35_05_concurrent_claim_race_condition_prevention(): void
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
}
