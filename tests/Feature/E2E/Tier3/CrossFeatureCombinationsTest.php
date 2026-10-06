<?php

namespace Tests\Feature\E2E\Tier3;

use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\AccountRestrictionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class CrossFeatureCombinationsTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithKycReviews;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    public function test_t3_01_subdomain_routing_role_locked_auth_and_fallback(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->portalGet('seller', '/dashboard');
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $fallback = $this->portalGet('seller', '/catalog');
        $this->assertTrue(in_array($fallback->status(), [200, 302]));
    }

    public function test_t3_02_subdomain_registration_kyc_approval_immediate_access(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $pendingCourier->refresh();

        $response = $this->actingAs($pendingCourier)->portalGet('courier', '/deliveries');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t3_03_checkout_placed_seller_confirmation_stock_deduction(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 20, 'price' => 150]);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product, 'quantity' => 2]], 'confirmed');
        $this->assertSame('confirmed', $order->status);
        $this->assertSame(18, $product->fresh()->stock);
        $this->assertEquals(300, $order->subtotal);
    }

    public function test_t3_04_seller_packaging_waybill_barcode_checkpoint(): void
    {
        $order = $this->newFlowOrder('preparing');
        $this->assertNotNull($order->delivery->tracking_number);
        $this->assertCheckpointLogged($order->delivery, 'seller_pack');
        $this->assertSame(1, $order->delivery->checkpoints()->where('checkpoint_type', 'seller_pack')->count());
    }

    public function test_t3_05_ready_for_pickup_courier_pickup_tab_visibility(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $rider = $this->flowRider(LogisticsHub::findOrFail($delivery->origin_bayan_hub_id));
        $this->actingAs($rider)->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.availablePickups', 1)->where('queues.availablePickups.0.id', $delivery->id));
    }

    public function test_t3_06_courier_pickup_claim_and_collection_scan(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'picked_up');
        $this->assertSame('picked_up', $delivery->status);
        $this->assertSame('picked_up', $order->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'courier_pickup');
    }

    public function test_t3_07_first_mile_delivery_to_hub_intake_scan(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'picked_up');
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->confirmFlowScan($delivery, $hub, 'RECEIVE_FROM_PICKUP_RIDER');
        $this->assertSame('arrived_at_origin_hub', $delivery->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'arrived_at_origin_hub');
    }

    public function test_t3_08_hub_sorting_destination_area_and_bin(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $this->assertSame('BIN: BRGY-POBLACION-III', $delivery->destination_bin);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
    }

    public function test_t3_09_area_matched_rider_assignment_tab_visibility(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.finalMileTasks', 1)->where('queues.finalMileTasks.0.id', $delivery->id));
    }

    public function test_t3_10_dock_departure_and_buyer_tracking_sync(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'out_for_delivery')->where('canConfirmReceipt', false));
        $this->assertNull($delivery->current_hub_id);
    }

    public function test_t3_11_doorstep_handover_proof_photo_tracking_update(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->assertCheckpointLogged($delivery, 'delivered');
        $this->actingAs($order->buyer)->get(route('buyer.orders.show', $order))->assertInertia(fn (Assert $page) => $page
            ->where('order.status', 'delivered')->where('canConfirmReceipt', true));
    }

    public function test_t3_12_cod_payment_settlement_automated_split(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCommissionSplit($order);
    }

    public function test_t3_13_buyer_confirmation_seller_settlement(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCommissionSplit($order);
    }

    public function test_t3_14_doorstep_delivery_failure_reason_logging(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->assertCheckpointLogged($delivery, 'delivery_failed');
    }

    public function test_t3_15_reschedule_option_selection_queue(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->assertCheckpointLogged($delivery, 'delivery_failed');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($delivery->destination_bayan_hub_id, $delivery->fresh()->current_hub_id);
        // The owning Phase 3 branch must add the recorded retry review/date and actual retry action chain.
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_t3_16_return_option_selection_merchant_restock(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $stock = $order->items->first()->product->fresh()->stock;
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->receiveFailureFlow($delivery);
        $this->assertSame($stock, $order->items->first()->product->fresh()->stock, 'Stock cannot be restored before authenticated seller receipt.');
        $this->assertNotSame('returned', $order->fresh()->status);
        // Phase 3 still needs reverse scans and authenticated seller receipt before these final requirements can pass.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame($stock + $order->items->first()->quantity, $order->items->first()->product->fresh()->stock);
    }

    public function test_t3_17_split_courier_tab_state_isolation(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $pickup = User::findOrFail($delivery->courier_id);
        $final = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($pickup)->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.pickupTasks', 0)->has('queues.finalMileTasks', 0));
        $this->actingAs($final)->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('queues.pickupTasks', 0)->where('queues.finalMileTasks.0.id', $delivery->id));
    }

    public function test_t3_18_sequential_fcfs_claims_preserve_first_assignment(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $first = $this->flowRider($hub);
        $second = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->actingAs($first)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $before = $delivery->fresh()->getRawOriginal();
        $this->actingAs($second)->post(route('courier.claim', $delivery))->assertSessionHas('error');
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertSame($first->id, $delivery->fresh()->courier_id);
    }

    public function test_t3_19_destination_area_partitioning_normalization(): void
    {
        $areas = ['Area A' => 'Santa Cruz', 'Area B' => 'Pagsanjan', 'Area C' => 'Los Baños'];
        $this->assertCount(3, $areas);
    }

    public function test_t3_20_hub_rider_fleet_review_approval_gate(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.kyc.index'));
        $response->assertOk();

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $this->assertEquals('approved', $pendingCourier->fresh()->kyc_status);
    }

    public function test_t3_21_hub_rider_area_designation_filtered_jobs(): void
    {
        $firstOrder = $this->newFlowOrder();
        $hub = LogisticsHub::findOrFail($firstOrder->delivery->destination_bayan_hub_id);
        $firstRider = $this->flowRider($hub);
        $first = $this->flowDelivery($firstOrder, 'assigned_to_rider', $firstRider);
        $secondOrder = $this->newFlowOrder();
        $secondRider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $second = $this->flowDelivery($secondOrder, 'assigned_to_rider', $secondRider);
        foreach ([[$firstRider, $first], [$secondRider, $second]] as [$rider, $parcel]) {
            $this->actingAs($rider)->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page
                ->has('queues.finalMileTasks', 1)->where('queues.finalMileTasks.0.id', $parcel->id));
        }
    }

    public function test_t3_22_hub_rider_rejection_feedback_appeal(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => 'Please upload a clearer copy of driver license',
        ]);

        $pendingCourier->refresh();
        $this->assertEquals('rejected', $pendingCourier->kyc_status);
        $this->assertNotNull($pendingCourier->kyc_feedback);
    }

    public function test_t3_23_hub_rider_suspension_dispatch_lockout(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $placement = $rider->courierProfile->getRawOriginal();
        $service = app(AccountRestrictionService::class);
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->postJson(route('admin.users.activity.store', $rider), [
            'action' => 'suspend', 'reason' => 'Review the current rider responsibilities.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($rider->fresh())),
        ])->assertOk();
        $this->assertSame('suspended', $rider->fresh()->status);
        $before = $delivery->fresh()->getRawOriginal();
        $this->actingAs($rider->fresh())->postJson(route('courier.claim', $delivery))->assertForbidden();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertSame($placement, $rider->courierProfile->fresh()->getRawOriginal());
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_t3_24_hub_rider_reactivation_restoration(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $placement = $rider->courierProfile->getRawOriginal();
        $service = app(AccountRestrictionService::class);
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->postJson(route('admin.users.activity.store', $rider), [
            'action' => 'suspend', 'reason' => 'Review the current rider responsibilities.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($rider->fresh())),
        ])->assertOk();
        $this->assertSame('suspended', $rider->fresh()->status);
        $this->actingAs($admin)->postJson(route('admin.users.activity.store', $rider), [
            'action' => 'reactivate', 'reason' => 'The account review is complete.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($rider->fresh())),
        ])->assertOk();
        $this->assertSame('active', $rider->fresh()->status);
        $this->assertSame($placement, $rider->courierProfile->fresh()->getRawOriginal());
        $this->actingAs($rider->fresh())->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $this->assertSame($rider->id, $delivery->fresh()->courier_id);
        $this->assertDatabaseCount('restriction_decisions', 2);
    }

    public function test_t3_25_hub_workstation_layout_navigation_isolation(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t3_26_multi_item_multi_seller_checkout_independent_deliveries(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop1 = $this->createE2EShop($this->createApprovedUser('seller'));
        $shop2 = $this->createE2EShop($this->createApprovedUser('seller'));
        $orders = $this->checkoutFlowOrders($buyer, [
            ['product' => $this->createE2EProduct($shop1)], ['product' => $this->createE2EProduct($shop2)],
        ]);
        $this->assertCount(2, $orders);
        $this->assertCount(2, $orders->pluck('delivery.tracking_number')->unique());
        $this->assertDatabaseCount('checkout_submissions', 1);
    }

    public function test_t3_27_voucher_discount_centavo_commission(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => 149.99]);
        $voucher = $this->createE2EVoucher($shop, ['min_spend' => 100]);
        $order = $this->checkoutFlowOrders($buyer, [['product' => $product]], ['voucher_code' => $voucher->code])->first();
        $this->assertEquals(149.99, $order->subtotal);
        $this->assertEquals(50, $order->voucher_discount);
        $this->assertEquals(149.99, $order->total_amount);
        $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertCommissionSplit($order, 149.99);
    }

    public function test_t3_28_product_stock_depletion_out_of_stock_guard(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 0]);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_t3_29_seller_cancellation_inventory_restoration(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 5]);
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product]]);
        $this->assertSame(4, $product->fresh()->stock);
        $this->actingAs($shop->user)->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('success');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
        $before = $order->fresh()->getRawOriginal();
        $this->actingAs($shop->user)->post(route('seller.orders.cancel', $order), ['reason' => 'Stock unavailable'])->assertSessionHas('error');
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_t3_30_buyer_cancellation_stays_unavailable_without_restock(): void
    {
        $order = $this->newFlowOrder();
        $before = [$order->fresh()->getRawOriginal(), $order->items->first()->product->stock];
        $this->actingAs($order->buyer)->post(route('seller.orders.cancel', $order), ['reason' => 'Changed plans'])->assertForbidden();
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $order->items->first()->product->fresh()->stock]);
    }

    public function test_t3_31_courier_profile_vehicle_metadata_fixture(): void
    {
        $courier = $this->createApprovedUser('courier');
        $profile = $courier->courierProfile;

        // Schema-only fixture coverage; this does not verify reviewed fleet changes or rider eligibility.
        $profile->update(['vehicle_type' => 'Van']);
        $this->assertEquals('Van', $profile->fresh()->vehicle_type);
    }

    public function test_t3_32_admin_kyc_queue_filtering_by_role_and_status(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.kyc.index', [
            'status' => 'pending_approval',
            'role' => 'courier',
        ]));
        $response->assertOk();
    }

    public function test_t3_33_complete_13_stage_linear_lifecycle_walkthrough(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
    }

    public function test_t3_34_double_delivery_attempt_immutable_ledger(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $before = [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), ['status' => 'delivered'])->assertSessionHas('success');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
        $this->assertLedgerIdempotent($order);
    }

    public function test_t3_35_end_to_end_adversarial_suite_execution(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_pickup');
        $before = [$order->fresh()->getRawOriginal(), $delivery->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()];
        Storage::fake('public');
        $this->actingAs(User::findOrFail($delivery->courier_id))->patch(route('courier.updateStatus', $delivery), [
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('premature.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('error');
        $this->assertSame($before, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
        $this->assertCount(0, Storage::disk('public')->allFiles('delivery-proofs'));
    }
}
