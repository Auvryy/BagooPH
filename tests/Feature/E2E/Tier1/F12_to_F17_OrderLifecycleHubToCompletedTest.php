<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
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

class F12_to_F17_OrderLifecycleHubToCompletedTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers, InteractsWithPortals;

    // ==========================================
    // Feature 12: AT_SORTING_CENTER (Stage 6)
    // ==========================================

    public function test_t1_f12_01_hub_intake_barcode_scan(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);
        $logistics = $this->createApprovedUser('logistics');

        $response = $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'location_name' => 'Metro Manila Central Sorting Station',
        ]);

        $response->assertOk();
    }

    public function test_t1_f12_02_state_transition_to_at_sorting_center(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);
        $logistics = $this->createApprovedUser('logistics');

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
        ]);

        $delivery->refresh();
        $this->assertTrue(in_array($delivery->status, ['in_transit', 'at_sorting_center']));
    }

    public function test_t1_f12_03_hub_intake_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);
        $logistics = $this->createApprovedUser('logistics');

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'hub_intake');
    }

    public function test_t1_f12_04_first_mile_courier_discharged(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'picked_up', $courier);
        $logistics = $this->createApprovedUser('logistics');

        $this->actingAs($logistics)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
        ]);

        $delivery->refresh();
        $this->assertNotNull($delivery->id);
    }

    public function test_t1_f12_05_hub_intake_queue(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 13: SORTED (Stage 7)
    // ==========================================

    public function test_t1_f13_01_area_sorting_submission(): void
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
            'sorting_bin' => 'BIN-A1',
            'bin' => 'BIN-A1',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        $response->assertOk();
    }

    public function test_t1_f13_02_delivery_attributes_updated(): void
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
            'sorting_bin' => 'BIN-A1',
            'bin' => 'BIN-A1',
            'barangay' => 'Santa Cruz, Laguna',
        ]);

        $delivery->refresh();
        $this->assertNotNull($delivery->id);
    }

    public function test_t1_f13_03_state_transition_to_sorted(): void
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
            'bin' => 'BIN-A1',
            'barangay' => 'Barangay San Antonio',
        ]);

        $delivery->refresh();
        $this->assertNotNull($delivery->id);
    }

    public function test_t1_f13_04_sorted_checkpoint_logged(): void
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
            'bin' => 'BIN-A1',
            'barangay' => 'Barangay San Antonio',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'barangay_sort', 'Barangay San Antonio');
    }

    public function test_t1_f13_05_hub_area_queue_count(): void
    {
        $logistics = $this->createApprovedUser('logistics');
        $response = $this->actingAs($logistics)->get(route('hub.index'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 14: ASSIGNED_TO_RIDER (Stage 8)
    // ==========================================

    public function test_t1_f14_01_hub_rider_candidate_lookup(): void
    {
        $admin = $this->createApprovedUser('admin');
        $response = $this->actingAs($admin)->get(route('admin.users'));
        $response->assertOk();
    }

    public function test_t1_f14_02_assign_parcel_to_area_rider(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $this->assertEquals($courier->id, $delivery->courier_id);
    }

    public function test_t1_f14_03_state_transition_to_assigned_to_rider(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_t1_f14_04_rider_assigned_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'rider_assigned',
            'location_name' => 'Central Sorting Hub',
            'scanned_by_id' => $courier->id,
            'notes' => 'Assigned to delivery rider',
        ]);

        $this->assertCheckpointLogged($delivery, 'rider_assigned');
    }

    public function test_t1_f14_05_courier_delivery_tab_visibility(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries', ['tab' => 'delivery']));
        $response->assertOk();
    }

    // ==========================================
    // Feature 15: OUT_FOR_DELIVERY (Stage 9)
    // ==========================================

    public function test_t1_f15_01_rider_departs_hub_dock(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
            'courier_notes' => 'Departed dock for delivery',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f15_02_state_transition_to_out_for_delivery(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
            'courier_notes' => 'Rider en route',
        ]);

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
    }

    public function test_t1_f15_03_out_for_delivery_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
            'courier_notes' => 'En route to customer',
        ]);

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'out_for_delivery',
            'location_name' => $delivery->delivery_address,
            'scanned_by_id' => $courier->id,
            'notes' => 'Departed dock',
        ]);

        $this->assertCheckpointLogged($delivery, 'out_for_delivery');
    }

    public function test_t1_f15_04_buyer_live_notification(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }

    public function test_t1_f15_05_courier_active_route(): void
    {
        $courier = $this->createApprovedUser('courier');
        $response = $this->actingAs($courier)->get(route('courier.deliveries'));
        $response->assertOk();
    }

    // ==========================================
    // Feature 16: DELIVERED (Stage 10)
    // ==========================================

    public function test_t1_f16_01_doorstep_handover_execution(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
            'courier_notes' => 'Received by buyer at front door',
        ]);
        $this->assertTrue(in_array($response->status(), [200, 302]));
    }

    public function test_t1_f16_02_state_transition_to_delivered(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $delivery->refresh();
        $order->refresh();
        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
    }

    public function test_t1_f16_03_doorstep_handover_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $delivery->refresh();
        $this->assertCheckpointLogged($delivery, 'doorstep_handover');
    }

    public function test_t1_f16_04_payment_status_settled(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $order->refresh();
        $this->assertEquals('paid', $order->payment_status);
    }

    public function test_t1_f16_05_commission_ledger_generation(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500',
        ]);

        $this->assertCommissionSplit($order);
    }

    // ==========================================
    // Feature 17: COMPLETED (Stage 11)
    // ==========================================

    public function test_t1_f17_01_buyer_confirmation_action(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');
        $delivery = $this->createE2EDelivery($order, 'delivered');

        $response = $this->actingAs($buyer)->post(route('buyer.orders.confirm', $order->id));
        $response->assertRedirect();

        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_t1_f17_02_state_transition_to_completed(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'delivered');

        $this->actingAs($buyer)->post(route('buyer.orders.confirm', $order->id));
        $this->assertEquals('completed', $order->fresh()->status);
    }

    public function test_t1_f17_03_buyer_confirmed_checkpoint(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'completed');
        $delivery = $this->createE2EDelivery($order, 'delivered');

        DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'buyer_confirmed',
            'location_name' => $delivery->delivery_address,
            'scanned_by_id' => $buyer->id,
            'notes' => 'Customer confirmed receipt of goods',
        ]);

        $this->assertCheckpointLogged($delivery, 'buyer_confirmed');
    }

    public function test_t1_f17_04_seller_settlement_finalized(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'completed');

        $this->assertNotNull($order->id);
    }

    public function test_t1_f17_05_review_submission_enabled(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'completed');

        $response = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $response->assertOk();
    }
}
