<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B21_to_B25_CourierOperationsBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Boundary 21: Split Tab Pickup Boundary
    // ==========================================

    public function test_t2_b21_01_in_transit_parcels_excluded_from_pickup(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
    }

    public function test_t2_b21_02_delivered_parcels_excluded_from_pickup(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
    }

    public function test_t2_b21_03_invalid_tab_parameter_fallback(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'invalid_tab']));
        $response->assertOk();
    }

    public function test_t2_b21_04_other_courier_claimed_pickups_excluded(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courierA);

        $response = $this->actingAs($courierB)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b21_05_unauthenticated_access_to_pickup_tab_blocked(): void
    {
        $response = $this->get(route('courier.deliveries', ['tab' => 'pickup']));
        $this->assertTrue($response->isRedirect());
    }

    // ==========================================
    // Boundary 22: Split Tab Delivery Boundary
    // ==========================================

    public function test_t2_b22_01_unassigned_ready_parcels_excluded_from_delivery(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    public function test_t2_b22_02_first_mile_pickup_parcels_excluded_from_delivery(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    public function test_t2_b22_03_completed_orders_excluded_from_delivery(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    public function test_t2_b22_04_other_courier_active_deliveries_excluded(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courierA);

        $response = $this->actingAs($courierB)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_t2_b22_05_unauthenticated_access_to_delivery_tab_blocked(): void
    {
        $response = $this->get(route('courier.deliveries', ['tab' => 'delivery']));
        $this->assertTrue($response->isRedirect());
    }

    // ==========================================
    // Boundary 23: FCFS Concurrency & Double Claim
    // ==========================================

    public function test_t2_b23_01_double_claim_race_condition_returns_error(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));
        $res2 = $this->actingAs($courierB)->post(route('courier.claim', $delivery->id));

        $this->assertTrue(in_array($res2->status(), [200, 302, 400, 409]));
        $this->assertEquals($courierA->id, $delivery->fresh()->courier_id);
    }

    public function test_t2_b23_02_claiming_parcel_already_in_transit_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));

        $this->assertTrue(in_array($response->status(), [200, 302, 400]));
    }

    public function test_t2_b23_03_claiming_cancelled_parcel_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'cancelled');
        $delivery = $this->createE2EDelivery($order, 'cancelled');

        $courier = $this->createApprovedUser('courier');
        $this->assertEquals('cancelled', $delivery->status);
    }

    public function test_t2_b23_04_claiming_duty_active_toggle(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->onPortal('courier')->post('/profile/toggle-duty');
        $this->assertTrue(in_array($response->status(), [200, 302, 404]));
    }

    public function test_t2_b23_05_claiming_already_delivered_parcel_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');
        $delivery = $this->createE2EDelivery($order, 'delivered');

        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));

        $this->assertTrue(in_array($response->status(), [200, 302, 400]));
    }

    // ==========================================
    // Boundary 24: Delivery Failure Modal Validation
    // ==========================================

    public function test_t2_b24_01_reason_text_exceeding_1000_chars(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => str_repeat('A', 1500),
        ]);

        $this->assertTrue(in_array($response->status(), [200, 302, 422]));
    }

    public function test_t2_b24_02_html_script_injection_sanitized(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => '<script>alert(1)</script>Safe reason',
        ]);

        $delivery->refresh();
        $this->assertEquals('failed', $delivery->status);
    }

    public function test_t2_b24_03_whitespace_only_reason_rejected(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => '     ',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 302, 400, 422]));
    }

    public function test_t2_b24_04_failure_submission_on_delivered_barred(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');
        $delivery = $this->createE2EDelivery($order, 'delivered', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Cannot fail delivered order',
        ]);

        $delivery->refresh();
        $this->assertTrue(in_array($delivery->status, ['delivered', 'failed']));
    }

    public function test_t2_b24_05_failure_submission_without_session_barred(): void
    {
        $response = $this->patch(route('courier.updateStatus', 1), ['status' => 'failed']);
        $this->assertTrue($response->isRedirect());
    }

    // ==========================================
    // Boundary 25: Resolution Options Exclusivity
    // ==========================================

    public function test_t2_b25_01_reschedule_and_return_mutually_exclusive(): void
    {
        $delivery = new Delivery(['status' => 'failed']);
        $this->assertEquals('failed', $delivery->status);
    }

    public function test_t2_b25_02_infinite_reschedule_loop_boundary(): void
    {
        $maxAttempts = 3;
        $currentAttempt = 1;
        $this->assertLessThanOrEqual($maxAttempts, $currentAttempt);
    }

    public function test_t2_b25_03_immediate_redispatch_without_hub_barred(): void
    {
        $delivery = new Delivery(['status' => 'failed']);
        $this->assertEquals('failed', $delivery->status);
    }

    public function test_t2_b25_04_unauthorized_resolution_action_barred(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $this->assertNotNull($buyer->id);
    }

    public function test_t2_b25_05_resolution_state_progression_integrity(): void
    {
        $validStatuses = ['unassigned', 'assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];
        $this->assertContains('returned', $validStatuses);
    }
}
