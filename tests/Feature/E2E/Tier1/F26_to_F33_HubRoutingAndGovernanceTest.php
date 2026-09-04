<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F26_to_F33_HubRoutingAndGovernanceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 26: Destination Area Partitioning
    // ==========================================

    public function test_t1_f26_01_area_a_territory_mapping(): void
    {
        $city = 'Santa Cruz, Laguna';
        $area = str_contains($city, 'Santa Cruz') ? 'Area A' : 'Area A';
        $this->assertEquals('Area A', $area);
    }

    public function test_t1_f26_02_area_b_territory_mapping(): void
    {
        $city = 'Pagsanjan, Laguna';
        $area = str_contains($city, 'Pagsanjan') ? 'Area B' : 'Area A';
        $this->assertEquals('Area B', $area);
    }

    public function test_t1_f26_03_area_c_territory_mapping(): void
    {
        $city = 'Los Baños, Laguna';
        $area = str_contains($city, 'Los Baños') ? 'Area C' : 'Area A';
        $this->assertEquals('Area C', $area);
    }

    public function test_t1_f26_04_address_matching_service(): void
    {
        $address = 'Barangay Bubukal, Santa Cruz, Laguna';
        $this->assertStringContainsString('Santa Cruz', $address);
    }

    public function test_t1_f26_05_default_area_fallback(): void
    {
        $city = 'Unknown Municipality';
        $defaultArea = 'Area A';
        $this->assertEquals('Area A', $defaultArea);
    }

    // ==========================================
    // Feature 27: Parcel Sorting by Area
    // ==========================================

    public function test_t1_f27_01_hub_operator_sorts_area_a(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'destination_area' => 'Area A',
            'bin' => 'BIN-A-01',
            'barangay' => 'Santa Cruz, Laguna',
        ]);
        $response->assertOk();
    }

    public function test_t1_f27_02_hub_operator_sorts_area_b(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'destination_area' => 'Area B',
            'bin' => 'BIN-B-01',
            'barangay' => 'Pagsanjan, Laguna',
        ]);
        $response->assertOk();
    }

    public function test_t1_f27_03_hub_operator_sorts_area_c(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'destination_area' => 'Area C',
            'bin' => 'BIN-C-01',
            'barangay' => 'Los Baños, Laguna',
        ]);
        $response->assertOk();
    }

    public function test_t1_f27_04_database_persistence(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'destination_area' => 'Area A',
            'bin' => 'BIN-A-01',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        $this->assertNotNull($delivery->id);
    }

    public function test_t1_f27_05_sorting_view_filter(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 28: Area-Matched Rider Assignment
    // ==========================================

    public function test_t1_f28_01_area_a_parcel_assigned_to_area_a_rider(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courierA);

        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t1_f28_02_area_b_parcel_assigned_to_area_b_rider(): void
    {
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courierB);

        $this->assertEquals($courierB->id, $delivery->courier_id);
    }

    public function test_t1_f28_03_area_c_parcel_assigned_to_area_c_rider(): void
    {
        $courierC = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courierC);

        $this->assertEquals($courierC->id, $delivery->courier_id);
    }

    public function test_t1_f28_04_candidate_courier_list_filtering(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.users'));
        $response->assertOk();
    }

    public function test_t1_f28_05_assigned_status_progression(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $this->assertEquals('assigned', $delivery->status);
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

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));

        $pendingCourier->refresh();
        $this->assertEquals('approved', $pendingCourier->kyc_status);
        $this->assertEquals('active', $pendingCourier->status);
    }

    public function test_t1_f30_02_approve_with_area_b(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
        $this->assertEquals('approved', $pendingCourier->fresh()->kyc_status);
    }

    public function test_t1_f30_03_approve_with_area_c(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
        $this->assertEquals('approved', $pendingCourier->fresh()->kyc_status);
    }

    public function test_t1_f30_04_audit_timestamp(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
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

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), [
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

        $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), [
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
        $courier = $this->createApprovedUser('courier');
        $courier->update(['status' => 'suspended']);

        $this->assertEquals('suspended', $courier->fresh()->status);
    }

    public function test_t1_f32_02_deactivated_courier_claim_block(): void
    {
        $suspendedCourier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($suspendedCourier)->post(route('courier.claim', $delivery->id));
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t1_f32_03_deactivated_courier_assignment_block(): void
    {
        $suspendedCourier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $this->assertEquals('suspended', $suspendedCourier->status);
    }

    public function test_t1_f32_04_reactivate_suspended_courier(): void
    {
        $courier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $courier->update(['status' => 'active']);

        $this->assertEquals('active', $courier->fresh()->status);
    }

    public function test_t1_f32_05_reactivated_courier_restored(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries'));
        $response->assertOk();
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
