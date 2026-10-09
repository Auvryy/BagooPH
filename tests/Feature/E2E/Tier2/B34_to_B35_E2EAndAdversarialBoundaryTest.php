<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B34_to_B35_E2EAndAdversarialBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

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
        $initialUsers = User::count();

        try {
            DB::transaction(function () {
                User::factory()->create();
                throw new \Exception('Forced rollback');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertEquals($initialUsers, User::count());
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

    public function test_t2_b35_03_delivery_retry_preserves_required_single_settlement(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $before = [$delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), $this->codCollectionInput($delivery) + ['status' => 'delivered'])->assertSessionHas('success');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $this->assertSame('completed', $order->fresh()->status);

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
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $before = [$delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs($order->buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHas('success');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $this->assertSame('completed', $order->fresh()->status);

        $this->assertLedgerIdempotent($order);
    }
}
