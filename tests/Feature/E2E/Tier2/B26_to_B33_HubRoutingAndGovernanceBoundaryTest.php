<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\LogisticsRoutingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B26_to_B33_HubRoutingAndGovernanceBoundaryTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithKycReviews;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    // ==========================================
    // Boundary 26: Partitioning Edge Cases & Ambiguous Addresses
    // ==========================================

    public function test_t2_b26_01_empty_address_rejects_without_fallback(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_address'] = '';
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHasErrors('shipping_address');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_t2_b26_02_cross_boundary_municipality_resolution(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_city'] = 'Boundary between Santa Cruz and Pagsanjan';
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(50, $product->fresh()->stock);
    }

    public function test_t2_b26_03_special_characters_in_address(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_address'] = "123 O'Connor Street, #04-12, Market & Sons";
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $this->assertSame($payload['shipping_address'], Order::firstOrFail()->shipping_address);
    }

    public function test_t2_b26_04_unserved_province_has_no_fallback(): void
    {
        $order = $this->newFlowOrder();
        $this->assertNull(app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Davao del Sur', 'Davao City', $order->destination_barangay));
    }

    public function test_t2_b26_05_missing_postal_code_handling(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_postal_code'] = null;
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHasErrors('shipping_postal_code');
        $this->assertDatabaseCount('orders', 0);
    }

    // ==========================================
    // Boundary 27: Area Sorting Bin Collision & Capacity
    // ==========================================

    public function test_t2_b27_01_multiple_parcels_to_same_bin(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $first = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        $second = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
        foreach ([$first, $second] as $order) {
            $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
            $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
            $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
                'delivery_id' => $delivery->id, 'barangay' => $order->destination_barangay, 'bin' => 'BIN-A1',
            ])->assertOk();
            $this->assertSame('BIN-A1', $delivery->fresh()->destination_bin);
            $this->assertSame(1, $delivery->checkpoints()->where('checkpoint_type', 'sorted_to_barangay_bin')->count());
        }
        $this->assertSame(2, Delivery::where('destination_bin', 'BIN-A1')->count());
    }

    public function test_t2_b27_02_missing_area_field_in_sort(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A1'])->assertOk();
        $this->assertSame('BIN-A1', $delivery->fresh()->destination_bin);
        $this->assertCheckpointLogged($delivery, 'sorted_to_barangay_bin', $order->destination_barangay);
    }

    public function test_t2_b27_03_bin_format_string_validation(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $before = $delivery->getRawOriginal();
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-α
HIDDEN'])
            ->assertUnprocessable()->assertJsonValidationErrors('bin');
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t2_b27_04_sorting_already_sorted_parcel(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A1'])->assertOk();
        $before = [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A2'])->assertUnprocessable();
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
    }

    public function test_t2_b27_05_sorting_non_hub_user_barred(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $before = $delivery->getRawOriginal();
        $this->actingAs($order->buyer)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A1'])->assertForbidden();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    // ==========================================
    // Boundary 28: Cross-Area Rider Assignment Prohibition
    // ==========================================

    public function test_t2_b28_01_area_a_parcel_to_area_b_rider_rejected(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $rider->courierProfile->update(['assigned_barangay' => 'San Antonio']);
        $before = $delivery->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertNull($delivery->fresh()->assigned_rider_id);
    }

    public function test_t2_b28_02_area_b_parcel_to_area_c_rider_rejected(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $rider->courierProfile->update(['assigned_barangay' => 'San Jose']);
        $before = $delivery->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertNull($delivery->fresh()->assigned_rider_id);
    }

    public function test_t2_b28_03_area_c_parcel_to_area_a_rider_rejected(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->flowRider($hub);
        $rider->courierProfile->update(['assigned_barangay' => 'San Juan']);
        $before = $delivery->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
        $this->assertNull($delivery->fresh()->assigned_rider_id);
    }

    public function test_t2_b28_04_rider_without_area_assignment_rejected(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $rider = $this->createApprovedUser('courier');
        $before = $delivery->getRawOriginal();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t2_b28_05_reassignment_across_areas_rejected(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $second = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $before = [$delivery->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $second->id])->assertUnprocessable();
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
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

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $pendingCourier->refresh();

        $this->assertEquals('approved', $pendingCourier->kyc_status);
        $this->assertEquals('active', $pendingCourier->status);
    }

    public function test_t2_b30_02_approval_timestamp_recorded(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $this->prepareKycReview($admin, $pendingCourier));
        $pendingCourier->refresh();

        $this->assertNotNull($pendingCourier->kyc_reviewed_at);
    }

    public function test_t2_b30_03_double_approval_idempotency(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $payload = $this->prepareKycReview($admin, $pendingCourier);
        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $payload)->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.kyc.approve', $pendingCourier->id), $payload)->assertSessionHas('success');
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

        $response = $this->actingAs($admin)->post(route('admin.kyc.approve', $activeCourier->id), $this->kycPayload($activeCourier));
        $response->assertConflict();
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    // ==========================================
    // Boundary 31: Rider Rejection Feedback Min-Length
    // ==========================================

    public function test_t2_b31_01_rejection_with_under_5_chars_rejected(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => 'bad',
        ]);
        $response->assertSessionHasErrors('reason');
    }

    public function test_t2_b31_02_rejection_with_empty_reason_rejected(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $response = $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => '',
        ]);
        $response->assertSessionHasErrors('reason');
    }

    public function test_t2_b31_03_rejection_sets_rejected_status(): void
    {
        $pendingCourier = $this->createPendingUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $pendingCourier->id), $this->kycPayload($pendingCourier) + [
            'reason' => 'Plate number blurred and unreadable',
        ]);
        $pendingCourier->refresh();

        $this->assertEquals('rejected', $pendingCourier->kyc_status);
    }

    public function test_t2_b31_04_rejection_cannot_overwrite_existing_approval(): void
    {
        $approvedCourier = $this->createApprovedUser('courier');
        $admin = $this->createApprovedUser('admin');

        $this->actingAs($admin)->post(route('admin.kyc.reject', $approvedCourier->id), $this->kycPayload($approvedCourier) + [
            'reason' => 'Suspicious document reported by operator',
        ])->assertConflict();
        $approvedCourier->refresh();

        $this->assertEquals('approved', $approvedCourier->kyc_status);
        $this->assertDatabaseCount('kyc_decisions', 0);
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
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $this->restrictFlowAccount($courier, 'suspend');
        $before = $delivery->getRawOriginal();
        $this->actingAs($courier->fresh())->postJson(route('courier.claim', $delivery))->assertForbidden();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t2_b32_02_suspended_rider_dispatch_blocked(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $courier = User::findOrFail($delivery->assigned_rider_id);
        $this->restrictFlowAccount($courier, 'suspend');
        $before = $delivery->getRawOriginal();
        $this->actingAs($courier->fresh())->patchJson(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery'])->assertForbidden();
        $this->assertSame($before, $delivery->fresh()->getRawOriginal());
    }

    public function test_t2_b32_03_reactivation_restores_dispatch(): void
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

    public function test_t2_b32_04_non_admin_toggling_status_barred(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $before = $courier->getRawOriginal();
        $this->actingAs($order->buyer)->postJson(route('admin.users.activity.store', $courier), ['action' => 'suspend', 'reason' => 'Review the account.'])->assertForbidden();
        $this->assertSame($before, $courier->fresh()->getRawOriginal());
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_t2_b32_05_double_suspension_idempotency(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery;
        $hub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $courier = $this->flowRider($hub);
        $service = app(AccountRestrictionService::class);
        $admin = $this->createApprovedUser('admin');
        $payload = ['action' => 'suspend', 'reason' => 'Review the current rider responsibilities.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($courier->fresh()))];
        $this->actingAs($admin)->postJson(route('admin.users.activity.store', $courier), $payload)->assertOk();
        $before = $courier->fresh()->getRawOriginal();
        $this->actingAs($admin)->postJson(route('admin.users.activity.store', $courier), $payload)->assertOk();
        $this->assertSame($before, $courier->fresh()->getRawOriginal());
        $this->assertDatabaseCount('restriction_decisions', 1);
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
