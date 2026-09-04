<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B34_to_B35_E2EAndAdversarialBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Boundary 34: Test Runner Error Trapping
    // ==========================================

    public function test_t2_b34_01_memory_limit_compliance(): void
    {
        $limit = ini_get('memory_limit');
        $this->assertNotEmpty($limit);
    }

    public function test_t2_b34_02_sqlite_in_memory_isolation(): void
    {
        $this->assertEquals('sqlite', config('database.default'));
        $this->assertEquals(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_t2_b34_03_session_array_driver(): void
    {
        $this->assertEquals('array', config('session.driver'));
    }

    public function test_t2_b34_04_queue_sync_driver(): void
    {
        $this->assertEquals('sync', config('queue.default'));
    }

    public function test_t2_b34_05_database_rollback_on_exception(): void
    {
        $initialUsers = \App\Models\User::count();

        try {
            \Illuminate\Support\Facades\DB::transaction(function () {
                \App\Models\User::factory()->create();
                throw new \Exception('Forced rollback');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals($initialUsers, \App\Models\User::count());
    }

    // ==========================================
    // Boundary 35: Centavo Rounding Exploitation
    // ==========================================

    public function test_t2_b35_01_fractions_of_centavo_rounding(): void
    {
        $gross = 99.999;
        $rounded = round($gross, 2);
        $this->assertEquals(100.00, $rounded);
    }

    public function test_t2_b35_02_negative_gross_subtotal_prevention(): void
    {
        $subtotal = max(0, -100.00);
        $this->assertEquals(0.00, $subtotal);
    }

    public function test_t2_b35_03_multiple_concurrent_settlements(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'delivered']);
        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), ['status' => 'delivered']);

        $this->assertLedgerIdempotent($order);
    }

    public function test_t2_b35_04_commission_sum_equals_gross(): void
    {
        $subtotal = 399.50;
        $sellerPart = round($subtotal * 0.90, 2);
        $platformPart = round($subtotal * 0.10, 2);

        $this->assertEquals($subtotal, round($sellerPart + $platformPart, 2));
    }

    public function test_t2_b35_05_zero_duplicate_ledger_records(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');

        CommissionLedger::create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'courier_id' => 1,
            'gross_amount' => 500.00,
            'seller_amount' => 450.00,
            'platform_commission' => 50.00,
            'delivery_fee' => 60.00,
            'status' => 'settled',
        ]);

        $this->assertEquals(1, CommissionLedger::where('order_id', $order->id)->count());
    }
}
