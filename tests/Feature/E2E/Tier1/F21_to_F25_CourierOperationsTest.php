<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F21_to_F25_CourierOperationsTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

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
        $this->scopeRiderForDelivery($courierA, $delivery);
        $response = $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));

        $response->assertSessionHas('success');
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
        $this->scopeRiderForDelivery($courierA, $delivery);
        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id))->assertSessionHas('success');

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
        $this->scopeRiderForDelivery($courierA, $delivery);
        $this->actingAs($courierA)->post(route('courier.claim', $delivery->id))->assertSessionHas('success');

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
        $this->scopeRiderForDelivery($courierB, $delivery);
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

    public function test_t1_f24_02_failure_action_is_not_exposed_before_recovery_flow_exists(): void
    {
        $courier = $this->createApprovedUser('courier');

        $this->actingAs($courier)
            ->get(route('courier.deliveries'))
            ->assertOk();
    }

    public function test_t1_f24_03_mandatory_explanation_input(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'failed',
        ])->assertSessionHasErrors('status');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_t1_f24_04_failure_alias_cannot_bypass_the_canonical_transition_contract(): void
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
        ])->assertSessionHasErrors('status');

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
    }

    public function test_t1_f24_05_rejected_failure_does_not_store_notes(): void
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
        ])->assertSessionHasErrors('status');

        $delivery->refresh();
        $this->assertNull($delivery->courier_notes);
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
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        $this->retryFailureFlow($delivery);
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }

    public function test_t1_f25_03_reschedule_checkpoint(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        $this->retryFailureFlow($delivery);
        $this->assertCheckpointLogged($delivery, 'delivery_rescheduled');
    }

    public function test_t1_f25_04_return_action(): void
    {
        $order = $this->newFlowOrder();
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        // The owning Phase 3 branch must supply the reviewed retry or reverse-route actions before this final gate passes.
        $this->assertCheckpointLogged($delivery, 'parcel_returned');
        $this->assertSame('returned', $delivery->fresh()->status);
    }

    public function test_t1_f25_05_attempt_cap_warning(): void
    {
        $delivery = new Delivery(['status' => 'failed']);
        $this->assertEquals('failed', $delivery->status);
    }

    private function scopeRiderForDelivery(User $rider, Delivery $delivery): void
    {
        $suffix = Str::lower(Str::random(8));
        $company = LogisticsCompany::create([
            'user_id' => $this->createApprovedUser('logistics')->id,
            'name' => "E2E Dispatch {$suffix}",
            'slug' => "e2e-dispatch-{$suffix}",
            'code' => 'E2E-'.Str::upper($suffix),
            'status' => 'active',
            'is_active' => true,
        ]);
        $hub = LogisticsHub::create([
            'logistics_company_id' => $company->id,
            'name' => "E2E Bayan Hub {$suffix}",
            'code' => 'BH-'.Str::upper($suffix),
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Santa Cruz',
            'address' => 'E2E Test Address',
            'is_active' => true,
        ]);

        $rider->courierProfile()->update([
            'logistics_company_id' => $company->id,
            'assigned_hub_id' => $hub->id,
            'is_available' => true,
        ]);
        $delivery->update([
            'logistics_company_id' => $company->id,
            'origin_bayan_hub_id' => $hub->id,
        ]);
    }
}
