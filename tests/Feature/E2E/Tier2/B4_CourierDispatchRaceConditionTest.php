<?php

namespace Tests\Feature\E2E\Tier2;

use App\Models\Delivery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class B4_CourierDispatchRaceConditionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithRoles, CreatesE2EOrders, SimulatesOrderLifecycle, AssertsDeliveryCheckpoints, AssertsCommissionLedgers;

    public function test_b4_01_second_courier_claiming_already_claimed_delivery_is_rejected_gracefully(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        // Courier A claims the job first
        $claimAResponse = $this->actingAs($courierA)->post(route('courier.claim', $delivery->id));
        $claimAResponse->assertSessionHas('success');

        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
        $this->assertEquals('assigned', $delivery->status);

        // Courier B tries to claim the same job (Race condition boundary)
        $claimBResponse = $this->actingAs($courierB)->post(route('courier.claim', $delivery->id));
        $claimBResponse->assertSessionHas('error', 'This delivery has already been claimed by another rider.');

        // Verify courier assignment remains strictly Courier A
        $delivery->refresh();
        $this->assertEquals($courierA->id, $delivery->courier_id);
    }

    public function test_b4_02_courier_cannot_update_delivery_status_of_an_unassigned_or_other_couriers_order(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');

        $courierA = $this->createApprovedUser('courier');
        $courierB = $this->createApprovedUser('courier');

        $delivery = $this->createE2EDelivery($order, 'assigned', $courierA);

        // Courier B attempts to update Courier A's delivery task
        $response = $this->actingAs($courierB)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'picked_up',
            'courier_notes' => 'Unauthorized update attempt',
        ]);

        $response->assertForbidden();

        $delivery->refresh();
        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_b4_03_courier_cannot_jump_from_assigned_directly_to_delivered_skipping_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'assigned', $courier);

        // Attempt invalid status transition value
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'non_existent_status_value',
        ]);

        $response->assertSessionHasErrors(['status']);

        $delivery->refresh();
        $this->assertEquals('assigned', $delivery->status);
    }

    public function test_b4_04_delivery_completion_without_proof_image_fails_validation_or_uses_verified_fallback(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'shipped');
        $courier = $this->createApprovedUser('courier');
        $delivery = $this->createE2EDelivery($order, 'out_for_delivery', $courier);

        // Delivery completion with null proof image uses verified fallback
        $response = $this->actingAs($courier)->patch(route('courier.updateStatus', $delivery->id), [
            'status' => 'delivered',
            'courier_notes' => 'Package handed to buyer Juan Dela Cruz.',
        ]);

        $response->assertSessionHas('success');

        $delivery->refresh();
        $order->refresh();

        $this->assertEquals('delivered', $delivery->status);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals('paid', $order->payment_status);
        $this->assertNotNull($delivery->proof_image);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_b4_05_inactive_or_off_duty_courier_cannot_claim_new_delivery_jobs(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $order = $this->createE2EOrder($buyer, $shop, [], 'ready_for_pickup');
        $delivery = $this->createE2EDelivery($order, 'unassigned');

        // Unapproved/pending courier attempt to claim
        $pendingCourier = $this->createPendingUser('courier');

        $response = $this->actingAs($pendingCourier)->post(route('courier.claim', $delivery->id));
        $response->assertRedirect(route('kyc.pending'));

        // Delivery must remain unassigned
        $delivery->refresh();
        $this->assertNull($delivery->courier_id);
        $this->assertEquals('unassigned', $delivery->status);
    }
}
