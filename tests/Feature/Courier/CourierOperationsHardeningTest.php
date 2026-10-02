<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CourierOperationsHardeningTest extends TestCase
{
    use CreatesE2EOrders;
    use InteractsWithRoles;
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private User $rider;

    private Shop $shop;

    private LogisticsCompany $company;

    private LogisticsHub $originHub;

    private LogisticsHub $destinationHub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->createApprovedUser('buyer');
        $this->seller = $this->createApprovedUser('seller');
        $this->rider = $this->createApprovedUser('courier');
        $this->shop = $this->createE2EShop($this->seller, [
            'name' => 'Laguna Home Store',
            'address' => 'National Highway',
            'city' => 'Los Banos',
        ]);

        $owner = $this->createApprovedUser('logistics');
        $this->company = LogisticsCompany::create([
            'user_id' => $owner->id,
            'name' => 'Laguna Dispatch Cooperative',
            'slug' => 'laguna-dispatch-'.Str::lower(Str::random(6)),
            'code' => 'LDC-'.Str::upper(Str::random(6)),
            'status' => 'active',
            'is_active' => true,
        ]);
        $this->originHub = $this->createHub('Los Banos Bayan Hub', 'BH-LBN');
        $this->destinationHub = $this->createHub('Santa Cruz Bayan Hub', 'BH-SCZ');

        $this->rider->courierProfile->update([
            'logistics_company_id' => $this->company->id,
            'assigned_hub_id' => $this->originHub->id,
            'assigned_barangay' => 'Batong Malake',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'BG-LBN-101',
            'license_number' => 'N01-26-880001',
            'or_cr_status' => 'Verified',
            'is_available' => true,
        ]);
    }

    public function test_dispatch_board_exposes_only_pickup_phase_data_for_available_jobs(): void
    {
        $delivery = $this->createDelivery('unassigned');

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Courier/Deliveries')
                ->where('scope.company', $this->company->name)
                ->where('scope.hub', $this->originHub->name)
                ->has('queues.availablePickups', 1)
                ->where('queues.availablePickups.0.id', $delivery->id)
                ->where('queues.availablePickups.0.merchant.name', 'Laguna Home Store')
                ->missing('queues.availablePickups.0.recipient')
                ->missing('queues.availablePickups.0.payment')
            );
    }

    public function test_foreign_company_and_foreign_hub_pickups_are_not_exposed(): void
    {
        $this->createDelivery('unassigned');

        $foreignOwner = $this->createApprovedUser('logistics');
        $foreignCompany = LogisticsCompany::create([
            'user_id' => $foreignOwner->id,
            'name' => 'Foreign Carrier',
            'slug' => 'foreign-carrier-'.Str::lower(Str::random(6)),
            'code' => 'FOR-'.Str::upper(Str::random(6)),
            'status' => 'active',
            'is_active' => true,
        ]);
        $foreignHub = LogisticsHub::create([
            'logistics_company_id' => $foreignCompany->id,
            'name' => 'Foreign Bayan Hub',
            'code' => 'BH-FOR-'.Str::upper(Str::random(4)),
            'tier' => 'local_bayan_hub',
            'province' => 'Cavite',
            'city_municipality' => 'Dasmarinas',
            'address' => 'Foreign carrier road',
            'is_active' => true,
        ]);
        $this->createDelivery('unassigned', null, [
            'logistics_company_id' => $foreignCompany->id,
            'origin_bayan_hub_id' => $foreignHub->id,
            'destination_bayan_hub_id' => $foreignHub->id,
        ]);
        $otherOwnedHub = $this->createHub('Pagsanjan Bayan Hub', 'BH-PGS');
        $this->createDelivery('unassigned', null, [
            'origin_bayan_hub_id' => $otherOwnedHub->id,
        ]);

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('queues.availablePickups', 1)
            );
    }

    public function test_duty_toggle_persists_to_the_courier_profile(): void
    {
        $this->actingAs($this->rider)
            ->post(route('courier.toggleDuty'), ['is_available' => false])
            ->assertSessionHas('success');

        $this->assertFalse($this->rider->courierProfile->fresh()->is_available);

        $this->actingAs($this->rider)
            ->post(route('courier.toggleDuty'), ['is_available' => true])
            ->assertSessionHas('success');

        $this->assertTrue($this->rider->courierProfile->fresh()->is_available);
    }

    public function test_unscoped_rider_cannot_go_on_duty(): void
    {
        $unscoped = $this->createApprovedUser('courier');
        $unscoped->courierProfile->update([
            'logistics_company_id' => null,
            'assigned_hub_id' => null,
            'is_available' => false,
        ]);

        $this->actingAs($unscoped)
            ->post(route('courier.toggleDuty'), ['is_available' => true])
            ->assertSessionHas('error');

        $this->assertFalse($unscoped->courierProfile->fresh()->is_available);
    }

    public function test_inactive_hub_pauses_new_jobs_but_preserves_existing_custody_visibility(): void
    {
        $available = $this->createDelivery('unassigned');
        $active = $this->createDelivery('assigned_pickup', $this->rider);
        $this->originHub->update(['is_active' => false]);
        $this->rider->courierProfile->update(['is_available' => false]);

        $this->actingAs($this->rider)
            ->post(route('courier.toggleDuty'), ['is_available' => true])
            ->assertSessionHas('error');

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope.isOperational', false)
                ->has('queues.availablePickups', 0)
                ->has('queues.pickupTasks', 1)
                ->where('queues.pickupTasks.0.id', $active->id)
            );

        $this->assertNull($available->fresh()->courier_id);
        $this->assertFalse($this->rider->courierProfile->fresh()->is_available);
    }

    public function test_off_duty_rider_cannot_claim_pickup(): void
    {
        $delivery = $this->createDelivery('unassigned');
        $this->rider->courierProfile->update(['is_available' => false]);

        $this->actingAs($this->rider)
            ->post(route('courier.claim', $delivery))
            ->assertSessionHas('error');

        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertSame('unassigned', $delivery->fresh()->status);
    }

    public function test_off_duty_rider_does_not_receive_new_pickup_jobs_but_keeps_active_custody_visible(): void
    {
        $available = $this->createDelivery('unassigned');
        $active = $this->createDelivery('assigned_pickup', $this->rider);
        $this->rider->courierProfile->update(['is_available' => false]);

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isOnline', false)
                ->where('stats.availablePickups', 0)
                ->has('queues.availablePickups', 0)
                ->has('queues.pickupTasks', 1)
                ->where('queues.pickupTasks.0.id', $active->id)
            );

        $this->assertNull($available->fresh()->courier_id);
    }

    public function test_courier_can_update_personal_contact_details_without_changing_operational_assignment(): void
    {
        $originalHubId = $this->rider->courierProfile->assigned_hub_id;
        $originalCompanyId = $this->rider->courierProfile->logistics_company_id;

        $this->actingAs($this->rider)
            ->patch(route('courier.profile.update'), [
                'name' => 'Rider Updated Name',
                'phone' => '0917 123 4567',
                'assigned_hub_id' => $this->destinationHub->id,
                'logistics_company_id' => 999999,
            ])
            ->assertSessionHas('success');

        $this->assertSame('Rider Updated Name', $this->rider->fresh()->name);
        $this->assertSame('+639171234567', $this->rider->fresh()->phone);
        $this->assertSame($originalHubId, $this->rider->courierProfile->fresh()->assigned_hub_id);
        $this->assertSame($originalCompanyId, $this->rider->courierProfile->fresh()->logistics_company_id);
    }

    public function test_courier_can_change_password_from_the_courier_portal(): void
    {
        $this->actingAs($this->rider)
            ->from(route('courier.profile'))
            ->put(route('courier.profile.password.update'), [
                'current_password' => 'password',
                'password' => 'CourierPassword2026!',
                'password_confirmation' => 'CourierPassword2026!',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('CourierPassword2026!', $this->rider->fresh()->password));
    }

    public function test_rider_can_claim_multiple_pickups_within_capacity(): void
    {
        $active = $this->createDelivery('assigned_pickup', $this->rider);
        $nextDelivery = $this->createDelivery('unassigned');

        $this->actingAs($this->rider)
            ->post(route('courier.claim', $nextDelivery))
            ->assertSessionHas('success');

        $this->assertSame($this->rider->id, $nextDelivery->fresh()->courier_id);
        $this->assertSame('assigned_pickup', $nextDelivery->fresh()->status);

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.activePickups', 2)
                ->where('stats.activePickupLimit', Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER)
                ->has('queues.pickupTasks', 2)
                ->where('queues.pickupTasks.0.id', $active->id)
                ->where('queues.pickupTasks.1.id', $nextDelivery->id)
            );
    }

    public function test_rider_cannot_claim_beyond_pickup_capacity(): void
    {
        foreach (range(1, Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) as $_) {
            $this->createDelivery('assigned_pickup', $this->rider);
        }
        $nextDelivery = $this->createDelivery('unassigned');

        $this->actingAs($this->rider)
            ->post(route('courier.claim', $nextDelivery))
            ->assertSessionHas('error');

        $this->assertNull($nextDelivery->fresh()->courier_id);
        $this->assertSame(
            Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER,
            Delivery::activePickupCount($this->rider->id)
        );
    }

    public function test_pickup_claim_is_scoped_and_creates_one_assignment_checkpoint(): void
    {
        $delivery = $this->createDelivery('unassigned');

        $this->actingAs($this->rider)
            ->post(route('courier.claim', $delivery))
            ->assertSessionHas('success');

        $this->assertSame($this->rider->id, $delivery->fresh()->courier_id);
        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 1);

        $secondRider = $this->createScopedRider($this->originHub);
        $this->actingAs($secondRider)
            ->post(route('courier.claim', $delivery))
            ->assertSessionHas('error');

        $this->assertSame($this->rider->id, $delivery->fresh()->courier_id);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
    }

    public function test_legacy_status_aliases_are_rejected_without_mutation(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);

        foreach (['in_transit', 'failed', 'delivery_failed'] as $status) {
            $this->actingAs($this->rider)
                ->patch(route('courier.updateStatus', $delivery), ['status' => $status])
                ->assertSessionHasErrors('status');

            $this->assertSame('assigned_pickup', $delivery->fresh()->status);
        }
    }

    public function test_wrong_rider_cannot_advance_an_assigned_pickup(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);
        $otherRider = $this->createScopedRider($this->originHub);

        $this->actingAs($otherRider)
            ->patch(route('courier.updateStatus', $delivery), ['status' => 'picked_up'])
            ->assertSessionHas('error');

        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
    }

    public function test_pickup_note_is_sent_to_the_seller_message_inbox_once(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);
        $note = 'Waybill matched and the sealed parcel was collected at the dispatch counter.';

        $this->actingAs($this->rider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'picked_up',
                'courier_notes' => $note,
            ])
            ->assertSessionHas('success');

        $this->assertSame($note, $delivery->fresh()->courier_notes);
        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->rider->id,
            'receiver_id' => $this->seller->id,
            'shop_id' => $this->shop->id,
            'order_id' => $delivery->order_id,
            'message' => $note,
            'is_read' => false,
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Seller/Messages')
                ->has('conversations', 1)
                ->where('conversations.0.user.id', $this->rider->id)
                ->where('conversations.0.order_id', $delivery->order_id)
                ->where('conversations.0.last_message', $note)
                ->where('conversations.0.unread_count', 1)
                ->has('conversations.0.messages', 1)
                ->where('conversations.0.messages.0.message', $note)
            );

        $this->actingAs($this->rider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'picked_up',
                'courier_notes' => $note,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseCount('messages', 1);
    }

    public function test_delivery_requires_proof_and_invalid_attempt_leaves_no_file(): void
    {
        Storage::fake('public');
        $this->rider->courierProfile->update([
            'assigned_hub_id' => $this->destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
        ]);
        $delivery = $this->createDelivery('out_for_delivery', $this->rider);

        $this->actingAs($this->rider)
            ->patch(route('courier.updateStatus', $delivery), ['status' => 'delivered'])
            ->assertSessionHasErrors('proof_image_file');

        $wrongRider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        $this->actingAs($wrongRider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'delivered',
                'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
            ])
            ->assertSessionHas('error');

        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
        $this->assertSame([], Storage::disk('public')->allFiles('delivery-proofs'));
    }

    public function test_duplicate_delivery_submission_is_idempotent_and_does_not_create_an_orphaned_proof(): void
    {
        Storage::fake('public');
        $this->rider->courierProfile->update([
            'assigned_hub_id' => $this->destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
        ]);
        $delivery = $this->createDelivery('out_for_delivery', $this->rider);

        $this->actingAs($this->rider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'delivered',
                'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
            ])
            ->assertSessionHas('success');

        $this->actingAs($this->rider)
            ->patch(route('courier.updateStatus', $delivery), [
                'status' => 'delivered',
            ])
            ->assertSessionHas('success');

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertCount(1, Storage::disk('public')->allFiles('delivery-proofs'));
        $this->assertDatabaseCount('delivery_checkpoints', 1);
    }

    public function test_final_mile_queue_exposes_delivery_details_only_after_assignment(): void
    {
        $this->rider->courierProfile->update([
            'assigned_hub_id' => $this->destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
        ]);
        $delivery = $this->createDelivery('assigned_to_rider', $this->rider);

        $this->actingAs($this->rider)
            ->get(route('courier.deliveries'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('queues.availablePickups', 0)
                ->has('queues.finalMileTasks', 1)
                ->where('queues.finalMileTasks.0.id', $delivery->id)
                ->where('queues.finalMileTasks.0.recipient.name', $delivery->delivery_recipient_name)
                ->where('queues.finalMileTasks.0.payment.method', 'COD')
            );
    }

    public function test_profile_and_trip_history_use_stored_data_without_financial_claims(): void
    {
        $this->rider->courierProfile->update([
            'assigned_hub_id' => $this->destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
            'plate_number' => 'REAL-2026',
        ]);
        $this->createDelivery('delivered', $this->rider);

        $this->actingAs($this->rider)
            ->get(route('courier.profile'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vehicle.plate_number', 'REAL-2026')
                ->where('assignment.hub', $this->destinationHub->name)
                ->where('completedDeliveries', 1)
                ->missing('fleetData.rating')
            );

        $this->actingAs($this->rider)
            ->get(route('courier.earnings'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.completedDeliveries', 1)
                ->has('trips', 1)
                ->missing('stats.totalEarnings')
                ->missing('stats.codCollected')
                ->missing('stats.remittanceStatus')
            );
    }

    public function test_rider_can_message_only_the_participant_for_an_active_assignment(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);

        $this->actingAs($this->rider)
            ->post(route('courier.messages.send'), [
                'delivery_id' => $delivery->id,
                'message' => 'I am arriving at the merchant pickup point.',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->rider->id,
            'receiver_id' => $this->seller->id,
            'order_id' => $delivery->order_id,
            'message' => 'I am arriving at the merchant pickup point.',
        ]);

        $this->actingAs($this->rider)
            ->get(route('courier.messages'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.participant.id', $this->seller->id)
                ->where('conversations.0.phase', 'pickup')
                ->where('currentUserId', $this->rider->id)
            );
    }

    public function test_rider_cannot_message_through_an_unassigned_or_foreign_delivery(): void
    {
        $unassigned = $this->createDelivery('unassigned');

        $this->actingAs($this->rider)
            ->post(route('courier.messages.send'), [
                'delivery_id' => $unassigned->id,
                'message' => 'This must not be sent.',
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_empty_message_page_contains_no_sample_conversations(): void
    {
        $this->actingAs($this->rider)
            ->get(route('courier.messages'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('conversations', 0)
                ->where('currentUserId', $this->rider->id)
            );
    }

    public function test_courier_cannot_bypass_delivery_messaging_with_the_shared_chat_endpoint(): void
    {
        $this->actingAs($this->rider)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->buyer->id,
                'message' => 'This bypass must be rejected.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_buyer_may_reply_only_to_the_assigned_final_mile_rider(): void
    {
        $this->rider->courierProfile->update([
            'assigned_hub_id' => $this->destinationHub->id,
            'assigned_barangay' => 'Poblacion III',
        ]);
        $delivery = $this->createDelivery('out_for_delivery', $this->rider);

        $this->actingAs($this->buyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->rider->id,
                'order_id' => $delivery->order_id,
                'message' => 'Please call when you reach the gate.',
            ])
            ->assertOk();

        $foreignBuyer = $this->createApprovedUser('buyer');
        $this->actingAs($foreignBuyer)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->rider->id,
                'order_id' => $delivery->order_id,
                'message' => 'I do not own this order.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->buyer->id,
            'receiver_id' => $this->rider->id,
            'order_id' => $delivery->order_id,
        ]);
        $this->assertDatabaseMissing('messages', [
            'sender_id' => $foreignBuyer->id,
            'receiver_id' => $this->rider->id,
        ]);
    }

    public function test_seller_may_reply_only_to_the_assigned_pickup_rider(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);

        $this->actingAs($this->seller)
            ->postJson(route('chat.send'), [
                'receiver_id' => $this->rider->id,
                'shop_id' => $this->shop->id,
                'order_id' => $delivery->order_id,
                'message' => 'The parcel is ready at the dispatch counter.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'sender_id' => $this->seller->id,
            'receiver_id' => $this->rider->id,
            'order_id' => $delivery->order_id,
        ]);
    }

    private function createDelivery(string $status, ?User $rider = null, array $attributes = []): Delivery
    {
        $orderStatus = match ($status) {
            'unassigned', 'assigned', 'assigned_pickup' => 'ready_for_pickup',
            'picked_up' => 'picked_up',
            'assigned_to_rider' => 'assigned_to_rider',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            default => 'ready_for_pickup',
        };
        $order = $this->createE2EOrder($this->buyer, $this->shop, [], $orderStatus);
        $order->update(['destination_barangay' => 'Poblacion III']);

        return $this->createE2EDelivery($order, $status, $rider, array_merge([
            'logistics_company_id' => $this->company->id,
            'origin_bayan_hub_id' => $this->originHub->id,
            'destination_bayan_hub_id' => $this->destinationHub->id,
        ], $attributes));
    }

    private function createScopedRider(LogisticsHub $hub, ?string $barangay = null): User
    {
        $rider = $this->createApprovedUser('courier');
        $rider->courierProfile->update([
            'logistics_company_id' => $this->company->id,
            'assigned_hub_id' => $hub->id,
            'assigned_barangay' => $barangay,
            'is_available' => true,
        ]);

        return $rider->fresh('courierProfile');
    }

    private function createHub(string $name, string $code): LogisticsHub
    {
        return LogisticsHub::create([
            'logistics_company_id' => $this->company->id,
            'name' => $name,
            'code' => $code.'-'.Str::upper(Str::random(4)),
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => $name,
            'address' => $name.' operations center',
            'is_active' => true,
        ]);
    }
}
