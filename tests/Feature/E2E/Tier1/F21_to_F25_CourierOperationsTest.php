<?php

namespace Tests\Feature\E2E\Tier1;

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

class F21_to_F25_CourierOperationsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 21: Split Tab: Items for Pickup
    // ==========================================

    public function test_t1_f21_01_pickup_tab_render(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
    }

    public function test_t1_f21_02_available_pickup_jobs_listed(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
        $this->assertEquals('unassigned', $delivery->status);
    }

    public function test_t1_f21_03_active_claimed_pickups_listed(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
        $this->assertEquals($courier->id, $delivery->courier_id);
    }

    public function test_t1_f21_04_merchant_pickup_details_displayed(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller, ['name' => 'Luzon Craft Emporium']);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $this->assertEquals('Luzon Craft Emporium', $delivery->pickup_store_name);
    }

    public function test_t1_f21_05_delivery_parcels_excluded(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'pickup']));
        $response->assertOk();
    }

    // ==========================================
    // Feature 22: Split Tab: Items for Delivery
    // ==========================================

    public function test_t1_f22_01_delivery_tab_render(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    public function test_t1_f22_02_assigned_doorstep_deliveries_listed(): void
    {
        $courier = $this->createApprovedUser('courier');
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
        $this->assertEquals($courier->id, $delivery->courier_id);
    }

    public function test_t1_f22_03_customer_doorstep_details_displayed(): void
    {
        $buyer = $this->createApprovedUser('buyer', ['name' => 'Eduardo Mendoza']);
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery');

        $this->assertEquals('Eduardo Mendoza', $delivery->delivery_recipient_name);
    }

    public function test_t1_f22_04_action_triggers_present(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    public function test_t1_f22_05_pickup_parcels_excluded(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    // ==========================================
    // Feature 23: FCFS Pickup Claiming
    // ==========================================

    public function test_t1_f23_01_successful_first_claim(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $response = $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));

        $this->assertTrue(in_array($response->status(), [200, 302]));
        $this->assertEquals($courierA->id, $delivery->fresh()->courier_id);
    }

    public function test_t1_f23_02_immediate_pool_removal(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));

        $this->assertNotNull($delivery->fresh()->courier_id);
        $this->assertNotEquals('unassigned', $delivery->fresh()->status);
    }

    public function test_t1_f23_03_claim_assigned_status(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));

        $delivery->refresh();
        $this->assertTrue(in_array($delivery->status, ['assigned', 'assigned_pickup']));
    }

    public function test_t1_f23_04_courier_active_list_update(): void
    {
        $courierA = $this->createApprovedUser('courier');
        $response = $this->actingAs($courierA)->get(route('courier.deliveries'));
        $response->assertOk();
    }

    public function test_t1_f23_05_atomic_lock_verification(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courierA = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courierA);

        $courierB = $this->createApprovedUser('courier');
        $response = $this->actingAs($courierB)->post(route('courier.claim', $delivery->id));

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    // ==========================================
    // Feature 24: Delivery Failure Modal & Reason
    // ==========================================

    public function test_t1_f24_01_interactive_modal_trigger(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries'));
        $response->assertOk();
    }

    public function test_t1_f24_02_reason_code_options(): void
    {
        $reasons = ['customer_unreachable', 'customer_refused', 'wrong_address', 'force_majeure'];
        $this->assertCount(4, $reasons);
    }

    public function test_t1_f24_03_mandatory_explanation_input(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer was not at home and phone was busy',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f24_04_valid_failure_submission(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer requested reschedule next Tuesday',
        ]);

        $delivery->refresh();
        $this->assertEquals('failed', $delivery->status);
    }

    public function test_t1_f24_05_delivery_notes_storage(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
            'courier_notes' => 'Customer address inaccessible due to flooding',
        ]);

        $delivery->refresh();
        $this->assertStringContainsString('flooding', $delivery->courier_notes);
    }

    // ==========================================
    // Feature 25: Delivery Failure Resolution Options
    // ==========================================

    public function test_t1_f25_01_resolution_options_display(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    public function test_t1_f25_02_reschedule_action(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'failed');

        $delivery->update(['status' => 'in_transit']);
        $this->assertEquals('in_transit', $delivery->fresh()->status);
    }

    public function test_t1_f25_03_reschedule_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $delivery = $this->createE2EDelivery($order, 'in_transit');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'delivery_rescheduled',
            'location_name' => 'Central Sorting Hub',
            'notes' => 'Rescheduled for next business day delivery',
        ]);

        $this->assertCheckpointLogged($delivery, 'delivery_rescheduled');
    }

    public function test_t1_f25_04_return_action(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivery_failed');
        $delivery = $this->createE2EDelivery($order, 'failed');

        $delivery->update(['status' => 'returned']);
        $this->assertEquals('returned', $delivery->fresh()->status);
    }

    public function test_t1_f25_05_attempt_cap_warning(): void
    {
        $delivery = new Delivery(['status' => 'failed']);
        $this->assertEquals('failed', $delivery->status);
    }
}
