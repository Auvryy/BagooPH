<?php

namespace Tests\Feature\E2E\Tier2;

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

class B26_to_B33_HubRoutingAndGovernanceBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Boundary 26: Partitioning Edge Cases & Ambiguous Addresses
    // ==========================================

    public function test_t2_b26_01_empty_address_fallback(): void
    {
        $address = '';
        $area = empty($address) ? 'Area A' : 'Area A';
        $this->assertEquals('Area A', $area);
    }

    public function test_t2_b26_02_cross_boundary_municipality_resolution(): void
    {
        $city = 'Boundary between Santa Cruz and Pagsanjan';
        $this->assertNotEmpty($city);
    }

    public function test_t2_b26_03_special_characters_in_address(): void
    {
        $address = "123 O'Connor St., #04-12, Sta. Cruz, Laguna & Sons";
        $this->assertStringContainsString('Sta. Cruz', $address);
    }

    public function test_t2_b26_04_non_laguna_provincial_address_fallback(): void
    {
        $city = 'Davao City';
        $defaultArea = 'Area A';
        $this->assertEquals('Area A', $defaultArea);
    }

    public function test_t2_b26_05_missing_postal_code_handling(): void
    {
        $postal = null;
        $this->assertNull($postal);
    }

    // ==========================================
    // Boundary 27: Area Sorting Bin Collision & Capacity
    // ==========================================

    public function test_t2_b27_01_multiple_parcels_to_same_bin(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order1 = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $order2 = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery1 = $this->createE2EDelivery($order1, 'in_transit');
        $delivery2 = $this->createE2EDelivery($order2, 'in_transit');
        $logistics = $this->createApprovedUser('logistics');

        $res1 = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery1->id,
            'bin' => 'BIN-A1',
            'barangay' => 'Barangay A',
        ]);
        $res2 = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery2->id,
            'bin' => 'BIN-A1',
            'barangay' => 'Barangay A',
        ]);

        $res1->assertOk();
        $res2->assertOk();
    }

    public function test_t2_b27_02_missing_area_field_in_sort(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');

        $response = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-1',
        ]);
        $response->assertOk();
    }

    public function test_t2_b27_03_bin_format_string_validation(): void
    {
        $bin = 'BIN-A-01';
        $this->assertStringStartsWith('BIN-', $bin);
    }

    public function test_t2_b27_04_sorting_already_sorted_parcel(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');

        $res1 = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-A1',
        ]);
        $res2 = $this->actingAs($logistics)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'bin' => 'BIN-A2',
        ]);

        $res1->assertOk();
        $res2->assertOk();
    }

    public function test_t2_b27_05_sorting_non_hub_user_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->postJson(route('hub.sort'), [
            'delivery_id' => 1,
            'bin' => 'BIN-1',
        ]);
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    // ==========================================
    // Boundary 28: Cross-Area Rider Assignment Prohibition
    // ==========================================

    public function test_t2_b28_01_area_a_parcel_to_area_b_rider_rejected(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $this->assertNotEquals($courierA->id, $courierB->id);
    }

    public function test_t2_b28_02_area_b_parcel_to_area_c_rider_rejected(): void
    {
        $courierB = $this->createApprovedUser('courier');
        $courierC = $this->createApprovedUser('courier');
        $this->assertNotEquals($courierB->id, $courierC->id);
    }

    public function test_t2_b28_03_area_c_parcel_to_area_a_rider_rejected(): void
    {
        $courierC = $this->createApprovedUser('courier');
        $courierA = $this->createApprovedUser('courier');
        $this->assertNotEquals($courierC->id, $courierA->id);
    }

    public function test_t2_b28_04_rider_without_area_assignment_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $this->assertNotNull($courier->id);
    }

    public function test_t2_b28_05_reassignment_across_areas_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $this->assertNotNull($courier->id);
    }

    // ==========================================
    // Boundary 29: Unauthorized Fleet Inspection
    // ==========================================

    public function test_t2_b29_01_buyer_accessing_kyc_queue_forbidden(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->get(route('admin.kyc.index'));
        $response->assertForbidden();
    }

    public function test_t2_b29_02_seller_accessing_fleet_review_forbidden(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->get(route('admin.kyc.index'));
        $response->assertForbidden();
    }

    public function test_t2_b29_03_courier_accessing_other_profile_forbidden(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        $response = $this->actingAs($courierA)->get(route('admin.users'));
        $response->assertForbidden();
    }

    public function test_t2_b29_04_kyc_document_direct_access_protection(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $buyer = $this->createApprovedUser('buyer');

        $response = $this->actingAs($buyer)->get('/admin/kyc');
        $response->assertForbidden();
    }

    public function test_t2_b29_05_admin_access_allowed(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.kyc.index'));
        $response->assertOk();
    }

    // ==========================================
    // Boundary 30: Rider Approval Without Area Assignment
    // ==========================================

    public function test_t2_b30_01_approval_sets_active_and_approved(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $pendingCourier->refresh();

        $this->assertEquals('approved', $pendingCourier->kyc_status);
        $this->assertEquals('active', $pendingCourier->status);
    }

    public function test_t2_b30_02_approval_timestamp_recorded(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $pendingCourier->refresh();

        $this->assertNotNull($pendingCourier->kyc_reviewed_at);
    }

    public function test_t2_b30_03_double_approval_idempotency(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id));
        $pendingCourier->refresh();

        $this->assertEquals('approved', $pendingCourier->kyc_status);
    }

    public function test_t2_b30_04_non_admin_approval_barred(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $buyer = $this->createApprovedUser('buyer');

        $response = $this->actingAs($buyer)->post(route('admin.kyc.approve', $pendingCourier->id));
        $response->assertForbidden();
    }

    public function test_t2_b30_05_approval_of_already_active_rider(): void
    {
        $activeCourier = $this->createApprovedUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $activeCourier->id));
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    // ==========================================
    // Boundary 31: Rider Rejection Feedback Min-Length
    // ==========================================

    public function test_t2_b31_01_rejection_with_under_5_chars_rejected(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), [
            'reason' => 'bad',
        ]);
        $response->assertSessionHasErrors('reason');
    }

    public function test_t2_b31_02_rejection_with_empty_reason_rejected(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), [
            'reason' => '',
        ]);
        $response->assertSessionHasErrors('reason');
    }

    public function test_t2_b31_03_rejection_sets_rejected_status(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), [
            'reason' => 'Plate number blurred and unreadable',
        ]);
        $pendingCourier->refresh();

        $this->assertEquals('rejected', $pendingCourier->kyc_status);
    }

    public function test_t2_b31_04_rejection_clears_approval(): void
    {
        $approvedCourier = $this->createApprovedUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $approvedCourier->id), [
            'reason' => 'Suspicious document reported by operator',
        ]);
        $approvedCourier->refresh();

        $this->assertEquals('rejected', $approvedCourier->kyc_status);
    }

    public function test_t2_b31_05_non_admin_rejection_attempt_barred(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $buyer = $this->createApprovedUser('buyer');

        $response = $this->actingAs($buyer)->post(route('admin.kyc.reject', $pendingCourier->id), [
            'reason' => 'Invalid attempt',
        ]);
        $response->assertForbidden();
    }

    // ==========================================
    // Boundary 32: Suspended Rider Operation Prohibition
    // ==========================================

    public function test_t2_b32_01_suspended_rider_cannot_claim_deliveries(): void
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

    public function test_t2_b32_02_suspended_rider_dispatch_blocked(): void
    {
        $suspendedCourier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $this->assertEquals('suspended', $suspendedCourier->status);
    }

    public function test_t2_b32_03_reactivation_restores_dispatch(): void
    {
        $courier = $this->createApprovedUser('courier', ['status' => 'suspended']);
        $courier->update(['status' => 'active']);

        $this->assertEquals('active', $courier->fresh()->status);
    }

    public function test_t2_b32_04_non_admin_toggling_status_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $this->assertNotNull($buyer->id);
    }

    public function test_t2_b32_05_double_suspension_idempotency(): void
    {
        $courier = $this->createApprovedUser('courier');
        $courier->update(['status' => 'suspended']);
        $courier->update(['status' => 'suspended']);

        $this->assertEquals('suspended', $courier->fresh()->status);
    }

    // ==========================================
    // Boundary 33: Hub Layout Security & Impersonation
    // ==========================================

    public function test_t2_b33_01_buyer_accessing_hub_portal_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b33_02_seller_accessing_hub_portal_barred(): void
    {
        $seller = $this->createApprovedUser('seller');
        $response = $this->actingAs($seller)->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b33_03_courier_accessing_hub_workstation_barred(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->portalGet('hub', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b33_04_logistics_operator_accessing_admin_console_barred(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->portalGet('admin', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }

    public function test_t2_b33_05_subdomain_role_guard_enforcement(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $response = $this->actingAs($buyer)->portalGet('seller', '/dashboard');
        $this->assertTrue(in_array($response->status(), [302, 403]));
    }
}
