<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F18_to_F20_OrderLifecycleFailureCheckpointsTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Feature 18: DELIVERY_FAILED (Stage 12)
    // ==========================================

    public function test_t1_f18_01_courier_reports_delivery_failure(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
    }

    public function test_t1_f18_02_state_transition_to_delivery_failed(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->assertSame('delivery_failed', $order->fresh()->status);
    }

    public function test_t1_f18_03_delivery_failed_checkpoint(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->assertCheckpointLogged($delivery, 'delivery_failed');
    }

    public function test_t1_f18_04_buyer_exception_view(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))->assertInertia(fn (AssertableInertia $page) => $page->where('order.status', 'delivery_failed'));
    }

    public function test_t1_f18_05_hub_exception_queue(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get(route('hub.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('stats.parcels_in_custody', 1));
    }

    // ==========================================
    // Feature 19: RETURNED (Stage 13)
    // ==========================================

    public function test_t1_f19_01_return_execution_from_hub(): void
    {
        $order = $this->newFlowOrder();
        $product = $order->items->first()->product;
        $quantity = $order->items->sum('quantity');
        $stock = $product->fresh()->stock;
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $product->fresh()->stock, 'Hub receipt alone must not restock the seller.');
        // Phase 3 must add the reviewed reverse Mother Hub route and actual seller receipt before these gates pass.
        $this->assertSame('returned', $delivery->fresh()->status);
        $this->assertSame('returned', $order->fresh()->status);
    }

    public function test_t1_f19_02_state_transition_to_returned(): void
    {
        $order = $this->newFlowOrder();
        $product = $order->items->first()->product;
        $quantity = $order->items->sum('quantity');
        $stock = $product->fresh()->stock;
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $product->fresh()->stock, 'Hub receipt alone must not restock the seller.');
        // Phase 3 must add the reviewed reverse Mother Hub route and actual seller receipt before these gates pass.
        $this->assertSame('returned', $delivery->fresh()->status);
    }

    public function test_t1_f19_03_return_checkpoint_logged(): void
    {
        $order = $this->newFlowOrder();
        $product = $order->items->first()->product;
        $quantity = $order->items->sum('quantity');
        $stock = $product->fresh()->stock;
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $product->fresh()->stock, 'Hub receipt alone must not restock the seller.');
        // Phase 3 must add the reviewed reverse Mother Hub route and actual seller receipt before these gates pass.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
    }

    public function test_t1_f19_04_inventory_reversal(): void
    {
        $order = $this->newFlowOrder();
        $product = $order->items->first()->product;
        $quantity = $order->items->sum('quantity');
        $stock = $product->fresh()->stock;
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $product->fresh()->stock, 'Hub receipt alone must not restock the seller.');
        // Phase 3 must add the reviewed reverse Mother Hub route and actual seller receipt before these gates pass.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame($stock + $quantity, $product->fresh()->stock);
    }

    public function test_t1_f19_05_seller_return_notice(): void
    {
        $order = $this->newFlowOrder();
        $product = $order->items->first()->product;
        $quantity = $order->items->sum('quantity');
        $stock = $product->fresh()->stock;
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $product->fresh()->stock, 'Hub receipt alone must not restock the seller.');
        // Phase 3 must add the reviewed reverse Mother Hub route and actual seller receipt before these gates pass.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertTrue(Schema::hasTable('notifications'), 'Recorded return notifications remain a Phase 4 prerequisite.');
        $this->assertSame(1, $order->shop->user->notifications()->count());
    }

    // ==========================================
    // Feature 20: Delivery Checkpoints Pipeline
    // ==========================================

    public function test_t1_f20_01_comprehensive_checkpoint_sequence(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCheckpointSequence($delivery, [
            'seller_pack', 'ready_for_pickup', 'assigned_pickup', 'picked_up', 'courier_pickup', 'arrived_at_origin_hub',
            'in_transit_to_mother_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul',
            'in_transit_to_destination_hub', 'arrived_at_destination_hub', 'sorted_to_barangay_bin',
            'assigned_to_rider', 'out_for_delivery', 'delivered', 'buyer_completed',
        ]);
    }

    public function test_t1_f20_02_checkpoint_metadata_integrity(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_origin_hub')->sole();
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->assertSame($delivery->id, $checkpoint->delivery_id);
        $this->assertSame($hub->id, $checkpoint->hub_id);
        $this->assertSame($hub->code, $checkpoint->facility_code);
        $this->assertSame($delivery->tracking_number, $checkpoint->barcode_scanned);
        $this->assertNotNull($checkpoint->created_at);
    }

    public function test_t1_f20_03_barcode_traceability(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $this->assertBarcodeScanned($delivery, $delivery->tracking_number);
        $this->assertCheckpointLogged($delivery, 'courier_pickup');
    }

    public function test_t1_f20_04_actor_attribution(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'courier_pickup')->sole();
        $this->assertSame($delivery->courier_id, $checkpoint->scanned_by_id);
    }

    public function test_t1_f20_05_scan_retry_preserves_original_history(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $before = $delivery->checkpoints()->get()->toArray();
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up',
        ])->assertOk();
        $this->assertSame($before, $delivery->checkpoints()->get()->toArray());
        $this->assertSame('arrived_at_origin_hub', $delivery->fresh()->status);
    }
}
