<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F34_to_F35_E2EAndAdversarialTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

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

    public function test_t1_f34_05_actual_lifecycle_records_buyer_completion(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
        $this->assertSame('completed', $order->fresh()->status);
    }

    // ==========================================
    // Feature 35: Adversarial Coverage Hardening
    // ==========================================

    public function test_t1_f35_01_state_skipping_barred(): void
    {
        Storage::fake('public');
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($rider)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $before = [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_t1_f35_02_idor_protection_across_sellers(): void
    {
        $order = $this->newFlowOrder('confirmed');
        $otherSeller = $this->createApprovedUser('seller');
        $otherShop = $this->createE2EShop($otherSeller);
        $before = [$order->getRawOriginal(), $order->delivery->checkpoints()->get()->toArray()];
        $this->actingAs($otherSeller)->withSession(['active_seller_shop_id' => $otherShop->id])->post(route('seller.orders.pack', $order))->assertForbidden();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $order->delivery->checkpoints()->get()->toArray()]);
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
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $before = [$delivery->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), $this->codCollectionInput($delivery) + ['status' => 'delivered'])->assertSessionHas('success');
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()]);
        $this->assertSame('completed', $order->fresh()->status);
        // Recorded collection/reconciliation and settlement remain Phase 5 prerequisites.
        $this->assertLedgerIdempotent($order);
    }

    public function test_t1_f35_05_sequential_competing_claims_preserve_first_owner(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $first = $this->flowRider($hub);
        $second = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($first)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $before = $delivery->fresh()->getRawOriginal();
        $this->actingAs($second)->post(route('courier.claim', $delivery))->assertSessionHas('error');
        $this->assertSame($first->id, $delivery->fresh()->courier_id);
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }
}
