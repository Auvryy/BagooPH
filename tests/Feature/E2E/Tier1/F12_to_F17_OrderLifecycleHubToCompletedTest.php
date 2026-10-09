<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F12_to_F17_OrderLifecycleHubToCompletedTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Feature 12: AT_SORTING_CENTER (Stage 6)
    // ==========================================

    public function test_t1_f12_01_hub_intake_barcode_scan(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $hub, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->assertSame($hub->id, $delivery->fresh()->current_hub_id);
    }

    public function test_t1_f12_02_state_transition_to_at_sorting_center(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $hub, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->assertSame('arrived_at_origin_hub', $delivery->fresh()->status);
        $this->assertSame('at_sorting_center', $order->fresh()->status);
    }

    public function test_t1_f12_03_hub_intake_checkpoint(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $hub, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->assertCheckpointLogged($delivery, 'arrived_at_origin_hub');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'hub_id' => $hub->id, 'scanned_by_id' => $this->flowHandler($hub)->id]);
    }

    public function test_t1_f12_04_first_mile_courier_discharged(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $hub, 'RECEIVE_FROM_PICKUP_RIDER');
        $pickup = User::findOrFail($delivery->courier_id);
        $this->actingAs($pickup)->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page->has('queues.pickupTasks', 0));
        $this->assertSame($pickup->id, $delivery->fresh()->courier_id);
    }

    public function test_t1_f12_05_hub_intake_queue(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get(route('hub.index'))->assertInertia(fn (Assert $page) => $page
            ->where('scope.selected_hub_id', $hub->id)->where('stats.parcels_in_custody', 1));
    }

    // ==========================================
    // Feature 13: SORTED (Stage 7)
    // ==========================================

    public function test_t1_f13_01_area_sorting_submission(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'barangay' => 'Poblacion III', 'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
    }

    public function test_t1_f13_02_delivery_attributes_updated(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'barangay' => 'Poblacion III', 'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();
        $this->assertSame('Poblacion III', $order->fresh()->destination_barangay);
        $this->assertSame('BIN: BRGY-POBLACION-III', $delivery->fresh()->destination_bin);
    }

    public function test_t1_f13_03_state_transition_to_sorted(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'barangay' => 'Poblacion III', 'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        $this->assertSame('sorted', $order->fresh()->status);
    }

    public function test_t1_f13_04_sorted_checkpoint_logged(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'barangay' => 'Poblacion III', 'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'hub_id' => $hub->id, 'scanned_by_id' => $this->flowHandler($hub)->id]);
    }

    public function test_t1_f13_05_hub_area_queue_count(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get(route('hub.index'))->assertInertia(fn (Assert $page) => $page
            ->where('scope.selected_hub_id', $hub->id)->where('stats.parcels_in_custody', 1));
    }

    // ==========================================
    // Feature 14: ASSIGNED_TO_RIDER (Stage 8)
    // ==========================================

    public function test_t1_f14_01_hub_rider_candidate_lookup(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertOk();
        $this->assertSame($rider->id, $delivery->fresh()->assigned_rider_id);
        $this->assertNotSame($rider->id, $delivery->courier_id);
    }

    public function test_t1_f14_02_assign_parcel_to_area_rider(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertOk();
        $this->assertSame($rider->id, $delivery->fresh()->assigned_rider_id);
    }

    public function test_t1_f14_03_state_transition_to_assigned_to_rider(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertOk();
        $this->assertSame('assigned_to_rider', $delivery->fresh()->status);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);
    }

    public function test_t1_f14_04_rider_assigned_checkpoint(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertOk();
        $this->assertCheckpointLogged($delivery, 'assigned_to_rider');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'checkpoint_type' => 'assigned_to_rider', 'scanned_by_id' => $this->flowHandler($hub)->id]);
    }

    public function test_t1_f14_05_courier_delivery_tab_visibility(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.finalMileTasks', 1)->where('queues.finalMileTasks.0.id', $delivery->id));
    }

    // ==========================================
    // Feature 15: OUT_FOR_DELIVERY (Stage 9)
    // ==========================================

    public function test_t1_f15_01_rider_departs_hub_dock(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        $this->assertNull($delivery->fresh()->current_hub_id);
    }

    public function test_t1_f15_02_state_transition_to_out_for_delivery(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
    }

    public function test_t1_f15_03_out_for_delivery_checkpoint(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        $this->assertCheckpointLogged($delivery, 'out_for_delivery');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'checkpoint_type' => 'out_for_delivery', 'scanned_by_id' => $delivery->assigned_rider_id]);
    }

    public function test_t1_f15_04_buyer_live_notification(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        // A tracking page is not evidence of a persistent event notice (Phase 4).
        $this->assertTrue(Schema::hasTable('notifications'), 'Phase 4: persistent event notification storage is missing.');
        $this->assertGreaterThan(0, $order->buyer->notifications()->count(), 'The buyer must receive a recorded dispatch notice.');
    }

    public function test_t1_f15_05_courier_active_route(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.finalMileTasks', 1)->where('queues.finalMileTasks.0.status', 'out_for_delivery'));
    }

    // ==========================================
    // Feature 16: DELIVERED (Stage 10)
    // ==========================================

    public function test_t1_f16_01_doorstep_handover_execution(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handover.jpg'),
        ])->assertSessionHas('success');
        $this->assertTrue(Storage::disk('local')->exists($delivery->fresh()->proof_image));
    }

    public function test_t1_f16_02_state_transition_to_delivered(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handover.jpg'),
        ])->assertSessionHas('success');
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_t1_f16_03_doorstep_handover_checkpoint(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handover.jpg'),
        ])->assertSessionHas('success');
        $this->assertCheckpointLogged($delivery, 'delivered');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'checkpoint_type' => 'delivered', 'scanned_by_id' => $delivery->assigned_rider_id]);
    }

    public function test_t1_f16_04_payment_status_settled(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handover.jpg'),
        ])->assertSessionHas('success');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);

        $this->assertNotNull($order->fresh()->commissionLedger, 'Phase 5: recorded reconciliation and seller settlement are missing.');
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_t1_f16_05_commission_ledger_generation(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        Storage::fake('public');
        Storage::fake('local');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handover.jpg'),
        ])->assertSessionHas('success');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);

        $this->assertNotNull($order->fresh()->commissionLedger, 'Phase 5: recorded reconciliation and seller settlement are missing.');
        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
    }

    // ==========================================
    // Feature 17: COMPLETED (Stage 11)
    // ==========================================

    public function test_t1_f17_01_buyer_confirmation_action(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_t1_f17_02_state_transition_to_completed(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_t1_f17_03_buyer_confirmed_checkpoint(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
        $this->assertDatabaseHas('delivery_checkpoints', ['delivery_id' => $delivery->id, 'checkpoint_type' => 'buyer_completed', 'scanned_by_id' => $order->buyer_id]);
    }

    public function test_t1_f17_04_seller_settlement_finalized(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
    }

    public function test_t1_f17_05_review_submission_enabled(): void
    {
        $order = $this->readyOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $product = $order->items->first()->product;
        $this->actingAs($order->buyer)->post(route('buyer.reviews.store'), [
            'product_id' => $product->id, 'order_id' => $order->id, 'rating' => 5, 'comment' => 'The parcel arrived safely.',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('reviews', ['buyer_id' => $order->buyer_id, 'order_id' => $order->id, 'product_id' => $product->id, 'rating' => 5]);
    }

    private function readyOrder(): Order
    {
        $seller = $this->createApprovedUser('seller');

        return $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $this->createE2EShop($seller), [], 'ready_for_pickup');
    }
}
