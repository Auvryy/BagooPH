<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\AccountRestrictionService;
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

class B12_to_B17_LifecycleHubToCompletedBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Boundary 12: Hub Intake Barcode Mismatch
    // ==========================================

    public function test_t2_b12_01_non_existent_barcode_scan(): void
    {
        $delivery = $this->parcel('picked_up');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), ['barcode' => 'BGO-TRK-NONEXISTENT', 'hub_id' => $origin->id, 'mode' => 'inspect'])->assertNotFound();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b12_02_double_intake_scan_idempotency(): void
    {
        $delivery = $this->parcel('picked_up');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $origin, 'RECEIVE_FROM_PICKUP_RIDER');
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up',
        ])->assertOk();
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'arrived_at_origin_hub')->count());
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b12_03_intake_scan_on_cancelled_parcel(): void
    {
        $delivery = $this->cancelledParcel();
        $before = $this->snapshot($delivery);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])->assertConflict();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b12_04_non_hub_user_intake_barred(): void
    {
        $delivery = $this->parcel('picked_up');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($delivery->order->buyer)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])->assertForbidden();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b12_05_premature_intake_before_pickup(): void
    {
        $delivery = $this->parcel('unassigned');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])->assertConflict();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    // ==========================================
    // Boundary 13: Destination Sorting Misclassification
    // ==========================================

    public function test_t2_b13_01_sorting_unsorted_non_intake_parcel(): void
    {
        $delivery = $this->parcel('picked_up');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'barangay' => $delivery->order->destination_barangay])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b13_02_invalid_destination_area_rejected(): void
    {
        $delivery = $this->parcel('arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'barangay' => "Poblacion\nInvalid", 'bin' => 'BIN-A1'])->assertUnprocessable()->assertJsonValidationErrors('barangay');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b13_03_re_sorting_rejects_and_preserves_bin(): void
    {
        $delivery = $this->parcel('sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A2'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b13_04_unknown_delivery_identifier_rejected(): void
    {
        $delivery = $this->parcel('arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), ['delivery_id' => 999999, 'bin' => 'BIN-A1'])->assertUnprocessable()->assertJsonValidationErrors('delivery_id');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b13_05_non_hub_operator_sorting_barred(): void
    {
        $delivery = $this->parcel('arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($delivery->order->buyer)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A1'])->assertForbidden();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    // ==========================================
    // Boundary 14: Rider Assignment Incompatibility
    // ==========================================

    public function test_t2_b14_01_cross_area_rider_assignment_mismatch(): void
    {
        $delivery = $this->parcel('sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $rider->courierProfile->update(['assigned_barangay' => 'Another Barangay']);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b14_02_assignment_to_suspended_rider_barred(): void
    {
        $delivery = $this->parcel('sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $service = app(AccountRestrictionService::class);
        $this->actingAs($this->createApprovedUser('admin'))->postJson(route('admin.users.activity.store', $rider), [
            'action' => 'suspend', 'reason' => 'Review the current rider assignment.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($rider->fresh())),
        ])->assertOk();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b14_03_assignment_to_pending_kyc_rider_barred(): void
    {
        $delivery = $this->parcel('sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->createPendingUser('courier');
        $rider->courierProfile->update(['logistics_company_id' => $hub->logistics_company_id, 'assigned_hub_id' => $hub->id, 'assigned_barangay' => 'Poblacion III', 'is_available' => true, 'vehicle_id' => null]);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b14_04_assignment_without_hub_sort_barred(): void
    {
        $delivery = $this->parcel('arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b14_05_double_assignment_collision(): void
    {
        $delivery = $this->parcel('assigned_to_rider');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    // ==========================================
    // Boundary 15: Out for Delivery Departure Violations
    // ==========================================

    public function test_t2_b15_01_out_for_delivery_without_rider_assignment(): void
    {
        $delivery = $this->parcel('sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b15_02_non_assigned_rider_dispatching_barred(): void
    {
        $delivery = $this->parcel('assigned_to_rider');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b15_03_double_out_for_delivery_invocation(): void
    {
        $delivery = $this->parcel('out_for_delivery');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('success');
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'out_for_delivery')->count());
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b15_04_out_for_delivery_on_cancelled_order(): void
    {
        $delivery = $this->cancelledParcel();
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b15_05_owned_dispatch_allows_optional_notes(): void
    {
        $delivery = $this->parcel('assigned_to_rider');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'out_for_delivery');
    }

    // ==========================================
    // Boundary 16: Doorstep Handover Missing Proof
    // ==========================================

    public function test_t2_b16_01_handover_without_proof_photo_is_rejected(): void
    {
        $delivery = $this->parcel('out_for_delivery');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'delivered'])->assertSessionHasErrors('proof_image_file');
        $this->assertNull($delivery->proof_image);
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b16_02_handover_from_wrong_status(): void
    {
        $delivery = $this->parcel('assigned_to_rider');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        Storage::fake('public');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('error');
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b16_03_double_delivered_invocation_idempotency(): void
    {
        $delivery = $this->parcel('delivered');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->codCollectionInput($delivery) + ['status' => 'delivered'])->assertSessionHas('success');
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'delivered')->count());
        $this->assertCount(1, Storage::disk('public')->allFiles('delivery-proofs'));
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b16_04_non_assigned_courier_handover_barred(): void
    {
        $delivery = $this->parcel('out_for_delivery');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        Storage::fake('public');
        $rider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('error');
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b16_05_proof_image_storage(): void
    {
        $delivery = $this->parcel('delivered');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->assertStringStartsWith('/storage/delivery-proofs/', $delivery->proof_image);
        $this->assertTrue(Storage::disk('public')->exists(substr($delivery->proof_image, strlen('/storage/'))));
        $this->assertSame('pending', $delivery->order->payment_status);
    }

    // ==========================================
    // Boundary 17: Order Completion State Skipping
    // ==========================================

    public function test_t2_b17_01_complete_order_before_delivery_barred(): void
    {
        $delivery = $this->parcel('out_for_delivery');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($delivery->order->buyer)->post(route('buyer.orders.confirm', $delivery->order))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b17_02_non_buyer_order_completion_barred(): void
    {
        $delivery = $this->parcel('delivered');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->createApprovedUser('buyer'))->post(route('buyer.orders.confirm', $delivery->order))->assertForbidden();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b17_03_double_order_completion_idempotency(): void
    {
        $delivery = $this->parcel('delivered');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->completeFlowOrder($delivery->order);
        $before = $this->snapshot($delivery);
        $this->actingAs($delivery->order->buyer)->post(route('buyer.orders.confirm', $delivery->order))->assertSessionHas('success');
        $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'buyer_completed')->count());
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b17_04_cancelled_order_completion_barred(): void
    {
        $delivery = $this->cancelledParcel();
        $before = $this->snapshot($delivery);
        $this->actingAs($delivery->order->buyer)->post(route('buyer.orders.confirm', $delivery->order))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_t2_b17_05_post_completion_settlement_bounds(): void
    {
        $delivery = $this->parcel('delivered');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->completeFlowOrder($delivery->order);
        $this->settleFlowOrder($delivery->order);

        $this->assertCommissionSplit($delivery->order, null, (float) $delivery->order->shipping_fee);
    }

    private function parcel(string $stage): Delivery
    {
        return $this->flowDelivery($this->newFlowOrder(), $stage);
    }

    private function cancelledParcel(): Delivery
    {
        $order = $this->newFlowOrder();
        $this->actingAs($order->shop->user)->withSession(['active_seller_shop_id' => $order->shop_id])
            ->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->delivery->fresh()->status);

        return $order->delivery->fresh();
    }

    private function snapshot(Delivery $delivery): array
    {
        return [$delivery->fresh()->getRawOriginal(), $delivery->order->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
    }
}
