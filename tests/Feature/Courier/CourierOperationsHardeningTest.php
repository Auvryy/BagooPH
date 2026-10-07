<?php

namespace Tests\Feature\Courier;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Message;
use App\Models\Shop;
use App\Models\User;
use App\Services\Courier\CourierMessagingService;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    public static function approvedCourierPortals(): array
    {
        return [
            ['/courier', 'approved'], ['/courier', 'verified'],
            ['http://courier.localhost', 'approved'], ['http://courier.localhost', 'verified'],
        ];
    }

    public static function courierPortals(): array
    {
        return [['/courier'], ['http://courier.localhost']];
    }

    #[DataProvider('courierPortals')]
    public function test_contact_and_password_updates_stay_in_the_current_portal(string $prefix): void
    {
        $before = $this->rider->courierProfile->fresh()->getAttributes();
        $this->actingAs($this->rider)->from($prefix.'/profile')
            ->patch($prefix.'/profile/account', [
                'name' => $this->rider->name, 'phone' => '0917 123 4567',
                'assigned_hub_id' => $this->destinationHub->id,
                'logistics_company_id' => 999999, 'role' => 'admin',
            ])->assertRedirect($prefix.'/profile')->assertSessionHas('success');
        $this->assertSame($this->rider->name, $this->rider->fresh()->name);
        $this->assertSame('+639171234567', $this->rider->fresh()->phone);
        $this->assertSame('courier', $this->rider->fresh()->role);
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $courierRoutes = new RouteCollection;
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'courier.')) {
                $courierRoutes->add($route);
            }
        }
        $compiled = $courierRoutes->compile();
        $this->assertArrayHasKey('courier.profile.update', $compiled['attributes']);
        $this->assertArrayHasKey('courier.profile.password.update', $compiled['attributes']);

        $this->put($prefix.'/profile/password', [
            'current_password' => 'password', 'password' => 'UpdatedRiderPassword!',
            'password_confirmation' => 'UpdatedRiderPassword!',
        ])->assertRedirect($prefix.'/profile')->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('UpdatedRiderPassword!', $this->rider->fresh()->password));
    }

    #[DataProvider('courierPortals')]
    public function test_invalid_contact_and_password_changes_leave_the_account_intact(string $prefix): void
    {
        $before = $this->rider->getAttributes();
        $this->actingAs($this->rider)->from($prefix.'/profile')
            ->patch($prefix.'/profile/account', ['name' => '1', 'phone' => '123'])
            ->assertSessionHasErrors(['name', 'phone']);
        $this->put($prefix.'/profile/password', [
            'current_password' => 'incorrect', 'password' => 'short', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['current_password', 'password']);
        $this->assertSame($before, $this->rider->fresh()->getAttributes());
    }

    #[DataProvider('courierPortals')]
    public function test_each_portal_requires_verified_email_to_change_password(string $prefix): void
    {
        $this->rider->update(['email_verified_at' => null]);
        $this->actingAs($this->rider)->from($prefix.'/profile')->put($prefix.'/profile/password', [
            'current_password' => 'password', 'password' => 'UpdatedRiderPassword!',
            'password_confirmation' => 'UpdatedRiderPassword!',
        ])->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $this->rider->fresh()->password));
    }

    #[DataProvider('courierPortals')]
    public function test_rider_directions_use_authorized_hubs_and_the_saved_checkout_destination(string $prefix): void
    {
        $this->originHub->update(['latitude' => 14.1, 'longitude' => 121.2]);
        $pickup = $this->createDelivery('picked_up', $this->rider);
        $this->actingAs($this->rider)->get($prefix.'/deliveries')->assertInertia(fn (Assert $page) => $page
            ->where('queues.pickupTasks.0.originHub.address', $this->originHub->address)
            ->where('queues.pickupTasks.0.originHub.latitude', 14.1)
            ->where('queues.pickupTasks.0.originHub.longitude', 121.2)
            ->where('queues.pickupTasks.0.nextAction', 'await_origin_hub_scan')
            ->missing('queues.pickupTasks.0.recipient')
            ->missing('queues.pickupTasks.0.payment'));

        $this->rider->courierProfile->update(['assigned_hub_id' => $this->destinationHub->id]);
        $this->destinationHub->update(['latitude' => 14.3, 'longitude' => 121.4]);
        $finalMile = $this->createDelivery('assigned_to_rider', $this->rider);
        $finalMile->order->update(['destination_latitude' => 14.5, 'destination_longitude' => 121.6]);
        $this->buyer->update(['address' => 'Changed default address', 'city' => 'Changed city']);
        $address = $this->buyer->addresses()->create([
            'recipient_name' => 'Changed recipient', 'phone' => '09170000000',
            'street' => 'Changed saved street', 'city' => 'Santa Cruz', 'province' => 'Laguna',
            'latitude' => 14.7, 'longitude' => 121.8, 'is_default' => true,
        ]);
        $address->update(['latitude' => 14.9, 'longitude' => 121.9]);

        $this->get($prefix.'/deliveries')->assertInertia(fn (Assert $page) => $page
            ->has('queues.pickupTasks', 0)
            ->has('queues.finalMileTasks', 1)
            ->where('queues.finalMileTasks.0.destinationHub.address', $this->destinationHub->address)
            ->where('queues.finalMileTasks.0.destinationHub.latitude', 14.3)
            ->where('queues.finalMileTasks.0.destinationHub.longitude', 121.4)
            ->where('queues.finalMileTasks.0.recipient.address', $finalMile->delivery_address)
            ->where('queues.finalMileTasks.0.recipient.latitude', 14.5)
            ->where('queues.finalMileTasks.0.recipient.longitude', 121.6)
            ->where('queues.finalMileTasks.0.payment.codAmount', fn ($amount) => (float) $amount === (float) $finalMile->order->total_amount));
        $this->assertSame('picked_up', $pickup->fresh()->status);
    }

    public function test_missing_saved_locations_are_returned_without_an_invented_pin(): void
    {
        $this->rider->courierProfile->update(['assigned_hub_id' => $this->destinationHub->id]);
        $delivery = $this->createDelivery('out_for_delivery', $this->rider);
        $delivery->order->update(['destination_latitude' => null, 'destination_longitude' => null]);
        $this->actingAs($this->rider)->get('/courier/deliveries')->assertInertia(fn (Assert $page) => $page
            ->where('queues.finalMileTasks.0.recipient.latitude', null)
            ->where('queues.finalMileTasks.0.recipient.longitude', null)
            ->where('queues.finalMileTasks.0.destinationHub.latitude', null)
            ->where('queues.finalMileTasks.0.destinationHub.longitude', null));
    }

    public function test_today_counts_use_the_philippine_day_shown_to_the_rider(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03T12:00:00Z'));
        $this->rider->courierProfile->update(['assigned_hub_id' => $this->destinationHub->id]);
        foreach (['2026-10-02 15:59:59', '2026-10-02 16:00:00', '2026-10-03 16:00:00'] as $deliveredAt) {
            $this->createDelivery('delivered', $this->rider, ['delivered_at' => $deliveredAt]);
        }
        $this->actingAs($this->rider)->get('/courier/deliveries')
            ->assertInertia(fn (Assert $page) => $page->where('stats.completedToday', 1));
        $this->get('/courier/earnings')
            ->assertInertia(fn (Assert $page) => $page->where('summary.completedToday', 1)->where('summary.completedDeliveries', 3));
    }

    #[DataProvider('courierPortals')]
    public function test_only_the_viewed_messages_in_one_thread_are_acknowledged(string $prefix): void
    {
        $first = $this->createDelivery('assigned_pickup', $this->rider);
        $second = $this->createDelivery('assigned_pickup', $this->rider);
        $viewed = $this->incomingMessage($first, $this->seller);
        $unopened = $this->incomingMessage($second, $this->seller);
        $newArrival = $this->incomingMessage($first, $this->seller);

        $this->actingAs($this->rider)->get($prefix.'/messages')->assertInertia(fn (Assert $page) => $page
            ->where('scope.hub', $this->originHub->name)->has('conversations', 2));
        foreach ([$viewed, $unopened, $newArrival] as $message) {
            $this->assertFalse($message->fresh()->is_read);
        }
        $this->postJson($prefix.'/messages/read', [
            'delivery_id' => $first->id, 'phase' => 'pickup', 'through_message_id' => $viewed->id,
        ])->assertOk()->assertJson(['acknowledged' => true]);
        $this->assertTrue($viewed->fresh()->is_read);
        $this->assertFalse($unopened->fresh()->is_read);
        $this->assertFalse($newArrival->fresh()->is_read);
        $this->postJson($prefix.'/messages/read', [
            'delivery_id' => $first->id, 'phase' => 'pickup', 'through_message_id' => $viewed->id,
        ])->assertOk();
        $this->assertFalse($newArrival->fresh()->is_read);
    }

    public function test_message_acknowledgement_rejects_foreign_assignment_phase_and_message_ids(): void
    {
        $owned = $this->createDelivery('assigned_pickup', $this->rider);
        $otherRider = $this->createScopedRider($this->originHub);
        $foreign = $this->createDelivery('assigned_pickup', $otherRider);
        $ownedMessage = $this->incomingMessage($owned, $this->seller);
        $foreignMessage = $this->incomingMessage($foreign, $this->seller, $otherRider);
        $this->actingAs($this->rider);
        foreach ([
            [$foreign->id, 'pickup', $foreignMessage->id],
            [$owned->id, 'final_mile', $ownedMessage->id],
            [$owned->id, 'pickup', $foreignMessage->id],
        ] as [$delivery, $phase, $message]) {
            $this->postJson('/courier/messages/read', [
                'delivery_id' => $delivery, 'phase' => $phase, 'through_message_id' => $message,
            ])->assertForbidden();
        }
        $this->rider->courierProfile->update(['assigned_hub_id' => $this->destinationHub->id]);
        $this->postJson('/courier/messages/read', [
            'delivery_id' => $owned->id, 'phase' => 'pickup', 'through_message_id' => $ownedMessage->id,
        ])->assertForbidden();
        $this->assertFalse($ownedMessage->fresh()->is_read);
        $this->assertFalse($foreignMessage->fresh()->is_read);
    }

    public function test_same_parcel_pickup_and_final_mile_threads_have_separate_read_acknowledgements(): void
    {
        $delivery = $this->createDelivery('out_for_delivery', $this->rider, ['destination_bayan_hub_id' => $this->originHub->id]);
        $sellerMessage = $this->incomingMessage($delivery, $this->seller);
        $buyerMessage = $this->incomingMessage($delivery, $this->buyer);
        $this->actingAs($this->rider)->postJson('/courier/messages/read', [
            'delivery_id' => $delivery->id, 'phase' => 'final_mile', 'through_message_id' => $buyerMessage->id,
        ])->assertOk();
        $this->assertTrue($buyerMessage->fresh()->is_read);
        $this->assertFalse($sellerMessage->fresh()->is_read);
    }

    public function test_message_order_is_stable_when_timestamps_match(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);
        $this->freezeTime();
        $first = $this->incomingMessage($delivery, $this->seller);
        $second = $this->incomingMessage($delivery, $this->seller);
        $this->actingAs($this->rider)->get('/courier/messages')->assertInertia(fn (Assert $page) => $page
            ->where('conversations.0.messages.0.id', $first->id)
            ->where('conversations.0.messages.1.id', $second->id)
            ->where('conversations.0.last_message', $second->message));
    }

    #[DataProvider('courierPortals')]
    public function test_a_stale_pickup_conversation_cannot_send_its_draft_to_the_buyer(string $prefix): void
    {
        $delivery = $this->createDelivery('out_for_delivery', $this->rider, [
            'destination_bayan_hub_id' => $this->originHub->id,
        ]);
        $this->actingAs($this->rider)->post($prefix.'/messages/send', [
            'delivery_id' => $delivery->id, 'phase' => 'pickup', 'message' => 'I am at the seller pickup point.',
        ])->assertSessionHas('error');
        $this->assertDatabaseCount('messages', 0);
        $this->post($prefix.'/messages/send', [
            'delivery_id' => $delivery->id, 'phase' => 'shipping', 'message' => 'Invalid phase.',
        ])->assertSessionHasErrors('phase');
        $this->assertDatabaseCount('messages', 0);
        $this->post($prefix.'/messages/send', [
            'delivery_id' => $delivery->id, 'phase' => 'final_mile', 'message' => 'I am approaching your delivery address.',
        ])->assertSessionHas('success');
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', [
            'receiver_id' => $this->buyer->id, 'sender_id' => $this->rider->id,
            'order_id' => $delivery->order_id, 'message' => 'I am approaching your delivery address.',
        ]);
    }

    private function incomingMessage(Delivery $delivery, User $sender, ?User $receiver = null): Message
    {
        return Message::create([
            'sender_id' => $sender->id, 'receiver_id' => ($receiver ?? $this->rider)->id,
            'order_id' => $delivery->order_id, 'shop_id' => $sender->isSeller() ? $this->shop->id : null,
            'message' => 'Parcel update '.Str::random(8), 'is_read' => false,
        ]);
    }

    #[DataProvider('approvedCourierPortals')]
    public function test_approved_rider_can_claim_then_collect_off_duty_on_each_portal(string $prefix, string $approval): void
    {
        $this->rider->update(['kyc_status' => $approval]);
        $delivery = $this->createDelivery('unassigned');
        $this->actingAs($this->rider)->get($prefix.'/deliveries')->assertOk();
        $this->post($prefix.'/deliveries/'.$delivery->id.'/claim')->assertSessionHas('success');
        $this->post($prefix.'/profile/toggle-duty', ['is_available' => false])->assertSessionHas('success');
        $this->patch($prefix.'/deliveries/'.$delivery->id.'/status', ['status' => 'picked_up', 'barcode' => $delivery->tracking_number])->assertSessionHas('success');

        $this->assertSame('picked_up', $delivery->fresh()->status);
        $this->assertSame('picked_up', $delivery->order->fresh()->status);
        $this->assertSame($approval, $this->rider->fresh()->kyc_status);
        $this->assertSame(['assigned_pickup', 'picked_up', 'courier_pickup'], $delivery->checkpoints()->orderBy('id')->pluck('checkpoint_type')->all());
    }

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
                'name' => $this->rider->name,
                'phone' => '0917 123 4567',
                'assigned_hub_id' => $this->destinationHub->id,
                'logistics_company_id' => 999999,
            ])
            ->assertSessionHas('success');

        $this->assertSame($this->rider->name, $this->rider->fresh()->name);
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

    public function test_unverified_courier_cannot_change_password_from_the_courier_portal(): void
    {
        $this->rider->update(['email_verified_at' => null]);

        $this->actingAs($this->rider)
            ->from(route('courier.profile'))
            ->put(route('courier.profile.password.update'), [
                'current_password' => 'password',
                'password' => 'CourierPassword2026!',
                'password_confirmation' => 'CourierPassword2026!',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $this->rider->fresh()->password));
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
            ->patch(route('courier.updateStatus', $delivery), ['status' => 'picked_up', 'barcode' => $delivery->tracking_number])
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
                'barcode' => $delivery->tracking_number,
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
                'barcode' => $delivery->tracking_number,
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

    public function test_terminal_orders_cannot_resume_rider_custody_even_with_active_delivery_rows(): void
    {
        Storage::fake('public');
        $finalRider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        foreach (['cancelled', 'completed', 'returned'] as $terminalStatus) {
            $pickup = $this->createDelivery('assigned_pickup', $this->rider);
            $pickup->order->update(['status' => $terminalStatus]);
            $this->actingAs($this->rider)->patch(route('courier.updateStatus', $pickup), ['status' => 'picked_up', 'barcode' => $pickup->tracking_number])->assertSessionHas('error');
            $this->assertSame('assigned_pickup', $pickup->fresh()->status);

            $drop = $this->createDelivery('out_for_delivery', $finalRider);
            $drop->order->update(['status' => $terminalStatus]);
            $this->actingAs($finalRider)->patch(route('courier.updateStatus', $drop), [
                'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
            ])->assertSessionHas('error');
            $this->assertSame('out_for_delivery', $drop->fresh()->status);
            $this->assertSame($terminalStatus, $drop->order->fresh()->status);
        }
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('delivery-proofs'));
    }

    public function test_rider_scan_requires_the_current_commercial_state(): void
    {
        Storage::fake('public');
        foreach (['placed', 'confirmed', 'preparing'] as $orderStatus) {
            $delivery = $this->createDelivery('assigned_pickup', $this->rider);
            $delivery->order->update(['status' => $orderStatus]);
            $this->actingAs($this->rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'picked_up', 'barcode' => $delivery->tracking_number])->assertSessionHas('error');
            $this->assertSame('assigned_pickup', $delivery->fresh()->status);
            $this->assertSame($orderStatus, $delivery->order->fresh()->status);
        }
        $rider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        foreach (['assigned_to_rider' => 'out_for_delivery', 'out_for_delivery' => 'delivered'] as $source => $target) {
            $delivery = $this->createDelivery($source, $rider);
            $delivery->order->update(['status' => 'ready_for_pickup']);
            $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
                'status' => $target, 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
            ])->assertSessionHas('error');
            $this->assertSame($source, $delivery->fresh()->status);
            $this->assertSame('ready_for_pickup', $delivery->order->fresh()->status);
        }
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('delivery-proofs'));
    }

    public function test_shared_lifecycle_service_rejects_missing_or_untrusted_proof(): void
    {
        Storage::fake('public');
        $rider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        $delivery = $this->createDelivery('out_for_delivery', $rider);
        foreach ([null, '<UNTRUSTED_PROOF_URL>', '/storage/delivery-proofs/missing.jpg', '/storage/delivery-proofs/../private.jpg'] as $proof) {
            try {
                app(OrderStateMachineService::class)->transition($delivery, 'delivered', $rider, ['proof_image' => $proof]);
                $this->fail('Delivery must require a stored proof file.');
            } catch (DomainException $exception) {
                $this->assertSame('Upload a proof of delivery image before recording handoff.', $exception->getMessage());
            }
            $this->assertSame('out_for_delivery', $delivery->fresh()->status);
            $this->assertSame('out_for_delivery', $delivery->order->fresh()->status);
        }
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_retried_delivery_keeps_original_proof_notes_and_timestamp_after_buyer_completion(): void
    {
        Storage::fake('public');
        $rider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        $delivery = $this->createDelivery('out_for_delivery', $rider);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'delivered', 'courier_notes' => 'Original handoff note',
            'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $before = $delivery->fresh()->getAttributes();
        $delivery->order->update(['status' => 'completed']);
        $this->patch(route('courier.updateStatus', $delivery), [
            'status' => 'delivered', 'courier_notes' => 'Replacement handoff note',
            'proof_image_file' => UploadedFile::fake()->create('replacement.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertSame('completed', $delivery->order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('delivery-proofs'));
    }

    public function test_stale_delivery_submission_removes_unused_proof_and_preserves_the_winning_result(): void
    {
        Storage::fake('public');
        $rider = $this->createScopedRider($this->destinationHub, 'Poblacion III');
        $delivery = $this->createDelivery('out_for_delivery', $rider, ['courier_notes' => 'Original handoff note']);
        $originalProof = '/storage/'.UploadedFile::fake()->create('original.jpg', 20, 'image/jpeg')->store('delivery-proofs', 'public');
        $service = app(OrderStateMachineService::class);
        $this->mock(OrderStateMachineService::class, function (MockInterface $mock) use ($service, $originalProof) {
            $mock->shouldReceive('transition')->once()->andReturnUsing(function ($parcel, $target, $actor, $metadata) use ($service, $originalProof) {
                // Model a second submission winning after this request bound the old parcel state.
                $service->transition($parcel, $target, $actor, ['proof_image' => $originalProof]);

                return $service->transition($parcel, $target, $actor, $metadata);
            });
        });
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'delivered', 'courier_notes' => 'Stale replacement note',
            'proof_image_file' => UploadedFile::fake()->create('replacement.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $this->assertSame($originalProof, $delivery->fresh()->proof_image);
        $this->assertSame('Original handoff note', $delivery->fresh()->courier_notes);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertCount(1, Storage::disk('public')->allFiles('delivery-proofs'));
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_pickup_and_checkpoints_roll_back_when_recording_the_message_fails(): void
    {
        $delivery = $this->createDelivery('assigned_pickup', $this->rider);
        $this->mock(CourierMessagingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('recordPickupNote')->once()->andThrow(new RuntimeException('Message storage failed.'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->rider)->patch(route('courier.updateStatus', $delivery), [
                'status' => 'picked_up', 'barcode' => $delivery->tracking_number, 'courier_notes' => 'Parcel collected',
            ]);
            $this->fail('A failed transaction must not claim a successful pickup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Message storage failed.', $exception->getMessage());
        }
        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
        $this->assertSame('ready_for_pickup', $delivery->order->fresh()->status);
        $this->assertNull($delivery->fresh()->courier_notes);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
        $this->assertDatabaseCount('messages', 0);
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
                ->missing('completedDeliveries')
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
        $this->seller->update(['avatar' => '/images/seller-profile.png']);
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
                ->where('conversations.0.participant.avatar', '/images/seller-profile.png')
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
