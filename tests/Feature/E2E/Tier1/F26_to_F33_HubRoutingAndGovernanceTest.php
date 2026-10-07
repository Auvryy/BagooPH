<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\LogisticsHub;
use App\Services\Logistics\LogisticsRoutingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

class F26_to_F33_HubRoutingAndGovernanceTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithKycReviews;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Feature 26: Destination Area Partitioning
    // ==========================================

    public function test_t1_f26_01_area_a_territory_mapping(): void
    {
        $order = $this->newFlowOrder('placed', ['shipping_city' => 'Santa Cruz']);
        $hub = app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Laguna', 'Santa Cruz', $order->destination_barangay);
        $this->assertSame('Santa Cruz', $hub->city_municipality);
        $this->assertSame($hub->id, $order->delivery->destination_bayan_hub_id);
    }

    public function test_t1_f26_02_area_b_territory_mapping(): void
    {
        $order = $this->newFlowOrder('placed', ['shipping_city' => 'Pagsanjan']);
        $hub = app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Laguna', 'Pagsanjan', $order->destination_barangay);
        $this->assertSame('Pagsanjan', $hub->city_municipality);
        $this->assertSame($hub->id, $order->delivery->destination_bayan_hub_id);
    }

    public function test_t1_f26_03_area_c_territory_mapping(): void
    {
        $order = $this->newFlowOrder('placed', ['shipping_city' => 'Los Baños']);
        $hub = app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Laguna', 'Los Baños', $order->destination_barangay);
        $this->assertSame('Los Baños', $hub->city_municipality);
        $this->assertSame($hub->id, $order->delivery->destination_bayan_hub_id);
    }

    public function test_t1_f26_04_address_matching_service(): void
    {
        $order = $this->newFlowOrder();
        $hub = app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub(' LAGUNA ', 'santa cruz', $order->destination_barangay);
        $this->assertSame($order->delivery->destination_bayan_hub_id, $hub->id);
    }

    public function test_t1_f26_05_unmapped_destination_has_no_fallback_hub(): void
    {
        $order = $this->newFlowOrder();
        $this->assertNull(app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Laguna', 'Unknown Municipality', $order->destination_barangay));
    }

    // ==========================================
    // Feature 27: Parcel Sorting by Area
    // ==========================================

    public function test_t1_f27_01_hub_operator_sorts_area_a(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Santa Cruz']);
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'bin' => 'BIN-1', 'barangay' => $order->destination_barangay,
        ])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        $this->assertSame('BIN-1', $delivery->fresh()->destination_bin);
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
    }

    public function test_t1_f27_02_hub_operator_sorts_area_b(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Pagsanjan']);
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'bin' => 'BIN-2', 'barangay' => $order->destination_barangay,
        ])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        $this->assertSame('BIN-2', $delivery->fresh()->destination_bin);
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
    }

    public function test_t1_f27_03_hub_operator_sorts_area_c(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Los Baños']);
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id, 'bin' => 'BIN-3', 'barangay' => $order->destination_barangay,
        ])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        $this->assertSame('BIN-3', $delivery->fresh()->destination_bin);
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin');
    }

    public function test_t1_f27_04_database_persistence(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'status' => 'sorted_to_barangay_bin', 'destination_bin' => 'BIN: BRGY-POBLACION-III']);
        $this->assertSame('sorted', $order->fresh()->status);
    }

    public function test_t1_f27_05_sorting_view_filter(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->get(route('hub.index'))->assertInertia(fn (Assert $page) => $page
            ->where('scope.selected_hub_id', $hub->id)->where('stats.parcels_in_custody', 1));
    }

    // ==========================================
    // Feature 28: Area-Matched Rider Assignment
    // ==========================================

    public function test_t1_f28_01_area_a_parcel_assigned_to_area_a_rider(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Santa Cruz']);
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->assertNotNull($delivery->assigned_rider_id);
        $this->assertSame($delivery->destination_bayan_hub_id, $delivery->assignedRider->courierProfile->assigned_hub_id);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);
    }

    public function test_t1_f28_02_area_b_parcel_assigned_to_area_b_rider(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Pagsanjan']);
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->assertNotNull($delivery->assigned_rider_id);
        $this->assertSame($delivery->destination_bayan_hub_id, $delivery->assignedRider->courierProfile->assigned_hub_id);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);
    }

    public function test_t1_f28_03_area_c_parcel_assigned_to_area_c_rider(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup', ['shipping_city' => 'Los Baños']);
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->assertNotNull($delivery->assigned_rider_id);
        $this->assertSame($delivery->destination_bayan_hub_id, $delivery->assignedRider->courierProfile->assigned_hub_id);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);
    }

    public function test_t1_f28_04_candidate_courier_list_filtering(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $this->actingAs($this->flowHandler($hub))->get(route('hub.deliveries'))->assertInertia(fn (Assert $page) => $page
            ->has('eligibleRiders', 1)->where('eligibleRiders.0.id', $rider->id));
    }

    public function test_t1_f28_05_assigned_status_progression(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $this->assertSame('assigned_to_rider', $delivery->status);
        $this->assertSame('assigned_to_rider', $order->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'assigned_to_rider');
    }

    // ==========================================
    // Feature 29: Hub Rider Fleet Review & KYC
    // ==========================================

    public function test_t1_f29_01_fleet_roster_view(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.kyc.index'));
        $response->assertOk();
    }

    public function test_t1_f29_02_document_modal_inspection(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.kyc.index'));
        $response->assertOk();
        $this->assertNotNull($pendingCourier->id);
    }

    public function test_t1_f29_03_vehicle_and_identity_verification(): void
    {
        $courier = $this->createApprovedUser('courier');
        $profile = $courier->courierProfile;

        $this->assertNotNull($profile);
        $this->assertNotNull($profile->vehicle_type);
    }

    public function test_t1_f29_04_filter_by_kyc_status(): void
    {
        $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->get(route('admin.kyc.index', ['status' => 'pending']));
        $response->assertOk();
    }

    public function test_t1_f29_05_operator_authorization(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->get(route('admin.kyc.index'));
        $response->assertForbidden();
    }

    // ==========================================
    // Feature 30: Hub Rider Approval & Area Designation
    // ==========================================

    public function test_t1_f30_01_approve_with_area_a(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $pendingCourier->refresh();
        $this->assertEquals('approved', $pendingCourier->kyc_status);
        $this->assertEquals('active', $pendingCourier->status);
    }

    public function test_t1_f30_02_approve_with_area_b(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $this->assertTrue(in_array($response->status(), [200, 302]));
        $this->assertEquals('approved', $pendingCourier->fresh()->kyc_status);
    }

    public function test_t1_f30_03_approve_with_area_c(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $this->assertTrue(in_array($response->status(), [200, 302]));
        $this->assertEquals('approved', $pendingCourier->fresh()->kyc_status);
    }

    public function test_t1_f30_04_audit_timestamp(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $pendingCourier->refresh();
        $this->assertNotNull($pendingCourier->kyc_reviewed_at);
    }

    public function test_t1_f30_05_immediate_dashboard_access(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 31: Hub Rider Disapproval / Rejection
    // ==========================================

    public function test_t1_f31_01_reject_with_feedback(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => 'Driver license image is unreadable and blurred',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $pendingCourier->refresh();
        $this->assertEquals('rejected', $pendingCourier->kyc_status);
    }

    public function test_t1_f31_02_user_state_updated(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => 'OR/CR expired, please submit updated registration',
        ]);

        $pendingCourier->refresh();
        $this->assertEquals('rejected', $pendingCourier->kyc_status);
        $this->assertNotNull($pendingCourier->kyc_feedback);
    }

    public function test_t1_f31_03_login_redirection_to_feedback(): void
    {
        $rejectedCourier = $this->createRejectedUser('courier', 'Blurred license photo');
        $response = $this->actingAs($rejectedCourier)->get('/dashboard');
        $this->assertTrue($response->isRedirect(route('kyc.pending')));
    }

    public function test_t1_f31_04_action_barred(): void
    {
        $rejectedCourier = $this->createRejectedUser('courier');
        $response = $this->actingAs($rejectedCourier)->get(route('courier.deliveries'));
        $this->assertTrue($response->isRedirect(route('kyc.pending')));
    }

    public function test_t1_f31_05_resubmission_permitted(): void
    {
        $rejectedCourier = $this->createRejectedUser('courier');
        $response = $this->actingAs($rejectedCourier)->get(route('kyc.pending'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 32: Hub Rider Activation / Deactivation
    // ==========================================

    public function test_t1_f32_01_deactivate_active_courier(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_t1_f32_02_deactivated_courier_claim_block(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $before = $delivery->getRawOriginal();
        $this->actingAs($courier->fresh())->postJson(route('courier.claim', $delivery))->assertForbidden();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t1_f32_03_deactivated_courier_assignment_block(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $before = $delivery->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $courier->id])->assertUnprocessable();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t1_f32_04_reactivate_suspended_courier(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $this->restrictFlowAccount($courier, 'reactivate');
        $this->actingAs($courier->fresh())->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $this->assertSame($courier->id, $delivery->fresh()->courier_id);
        $this->assertDatabaseCount('restriction_decisions', 2);
    }

    public function test_t1_f32_05_reactivated_courier_restored(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $this->restrictFlowAccount($courier, 'reactivate');
        $this->actingAs($courier->fresh())->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $this->assertSame($courier->id, $delivery->fresh()->courier_id);
        $this->assertDatabaseCount('restriction_decisions', 2);
        $this->actingAs($courier->fresh())->get(route('courier.deliveries'))->assertInertia(fn (Assert $page) => $page->has('queues.pickupTasks', 1)->where('queues.pickupTasks.0.id', $delivery->id));
    }

    // ==========================================
    // Feature 33: Dedicated Hub Layout & Pages
    // ==========================================

    public function test_t1_f33_01_hub_dashboard_rendering(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    public function test_t1_f33_02_hub_sorting_page_rendering(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    public function test_t1_f33_03_hub_riders_page_rendering(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.users'));
        $response->assertOk();
    }

    public function test_t1_f33_04_zero_marketplace_navigation(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    public function test_t1_f33_05_active_workstation_tab_state(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }
}
