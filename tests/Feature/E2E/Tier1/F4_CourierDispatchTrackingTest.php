<?php

namespace Tests\Feature\E2E\Tier1;

use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class F4_CourierDispatchTrackingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_f4_01_unassigned_ready_order_appears_on_courier_dispatch_board(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');

        $response = $this->actingAs($courier)->get(route('courier.deliveries'));

        $response->assertOk();
    }

    public function test_f4_02_courier_can_claim_available_delivery_job_fcfs(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courier = $this->createApprovedUser('courier');

        $response = $this->actingAs($courier)->post(route('courier.claim', $delivery->id));

        $response->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courier->id, $delivery->courier_id);
        $this->assertEquals('assigned', $delivery->status);
        $this->assertNotNull($delivery->assigned_at);
    }

    public function test_f4_03_courier_can_confirm_pickup_and_transition_to_in_transit(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Parcel verified and loaded on motorcycle carrier.',
        ]);

        $response->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();

        $this->assertEquals('picked_up', $delivery->status);
        $this->assertEquals('shipped', $order->status);
        $this->assertNotNull($delivery->picked_up_at);
    }

    public function test_f4_04_courier_can_transition_delivery_to_out_for_delivery(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'in_transit', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'out_for_delivery',
            'courier_notes' => 'Rider en route to buyer address in Taguig.',
        ]);

        $response->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals('out_for_delivery', $delivery->status);
    }

    public function test_f4_05_courier_can_complete_delivery_with_proof_photo_updating_buyer_tracking(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'proof_image' => 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=600',
            'courier_notes' => 'Signed by buyer Juan Dela Cruz. COD payment settled.',
        ]);

        $response->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();

        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNotNull($delivery->proof_image);

        // Buyer tracking verification
        $trackingResponse = $this->actingAs($buyer)->get(route('buyer.orders.show', $order->id));
        $trackingResponse->assertOk();
    }
}
