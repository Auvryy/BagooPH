<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsPlacementRecord;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\Courier\CourierMessagingService;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsPlacementService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogisticsResourceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $handler;

    private User $rider;

    private LogisticsCompany $company;

    private LogisticsHub $hub;

    private LogisticsFleet $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->account('logistics');
        $this->handler = $this->account('logistics');
        $this->rider = $this->account('courier');
        $this->company = LogisticsCompany::create([
            'user_id' => $this->owner->id, 'name' => 'Bagoo Laguna Network',
            'slug' => 'bagoo-laguna-network', 'code' => 'B04', 'status' => 'active', 'is_active' => true,
        ]);
        $this->hub = LogisticsHub::create([
            'logistics_company_id' => $this->company->id, 'name' => 'Bagoo Bayan Hub', 'code' => 'B04-BH',
            'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz',
            'address' => 'Test facility', 'is_active' => true, 'allows_self_pickup' => true,
        ]);
        HubHandler::create(['user_id' => $this->handler->id, 'hub_id' => $this->hub->id, 'is_active' => true]);
        $this->vehicle = LogisticsFleet::create([
            'logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id,
            'plate_number' => 'B04-001', 'vehicle_type' => 'motorcycle', 'status' => 'active',
            'assigned_driver_id' => $this->rider->id,
        ]);
        CourierProfile::factory()->create([
            'user_id' => $this->rider->id, 'logistics_company_id' => $this->company->id,
            'assigned_hub_id' => $this->hub->id, 'vehicle_id' => $this->vehicle->id, 'is_available' => true,
        ]);
    }

    public function test_unassigned_logistics_account_cannot_read_global_fleet(): void
    {
        $this->actingAs($this->account('logistics'))->get(route('hub.fleet'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->has('fleet', 0)->where('stats.total', 0));
    }

    public function test_suspended_parent_excludes_active_facility_and_rider_options(): void
    {
        $this->company->update(['status' => 'suspended']);
        $this->actingAs($this->handler)->get(route('hub.deliveries'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('activeHub', null)->has('hubs', 0)->has('eligibleRiders', 0)->has('deliveries.data', 0));
    }

    public function test_stale_selected_facility_is_denied_instead_of_falling_back(): void
    {
        $this->actingAs($this->handler)->withSession(['active_hub_id' => 99999])
            ->get(route('hub.deliveries'))->assertForbidden();
    }

    public function test_company_ownership_does_not_grant_floor_scan_access(): void
    {
        $this->actingAs($this->owner)->get(route('hub.scan.station'))->assertForbidden();
    }

    public function test_cached_rider_cannot_claim_after_company_owner_loses_approval(): void
    {
        $delivery = $this->pickup();
        $this->rider->load('courierProfile.company.user', 'courierProfile.hub', 'courierProfile.vehicle');
        $this->owner->update(['kyc_status' => 'rejected']);
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public static function parentRestrictions(): array
    {
        return [
            'pending company' => ['company', 'status', 'pending'],
            'rejected company' => ['company', 'status', 'rejected'],
            'suspended company' => ['company', 'status', 'suspended'],
            'unknown company' => ['company', 'status', 'unknown'],
            'inactive company' => ['company', 'is_active', false],
            'missing owner' => ['company', 'user_id', null],
            'pending owner' => ['owner', 'kyc_status', 'pending_approval'],
            'rejected owner' => ['owner', 'kyc_status', 'rejected'],
            'unknown owner approval' => ['owner', 'kyc_status', 'unknown'],
            'inactive owner' => ['owner', 'status', 'inactive'],
            'suspended owner' => ['owner', 'status', 'suspended'],
            'unknown owner state' => ['owner', 'status', 'unknown'],
            'underage owner' => ['owner', 'birthday', '2020-01-01'],
            'future owner birth date' => ['owner', 'birthday', '2099-01-01'],
        ];
    }

    #[DataProvider('parentRestrictions')]
    public function test_every_parent_gate_agrees_for_options_private_reads_and_claims(string $model, string $field, mixed $value): void
    {
        $delivery = $this->pickup();
        $cached = $this->rider->fresh(['courierProfile.company', 'courierProfile.hub']);
        $this->{$model}->update([$field => $value]);
        $this->assertFalse(app(LogisticsEligibilityService::class)->isOperational($cached->courierProfile));
        $this->assertSame([], LogisticsHub::eligible()->pluck('id')->all());
        $this->assertSame([], app(LogisticsEligibilityService::class)->riderCandidates($this->hub)->pluck('user_id')->all());
        $this->actingAs($this->handler)->get(route('hub.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('hubs', 0)->has('deliveries.data', 0)->has('eligibleRiders', 0)->where('auth.user.logisticsCompany', null));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($cached, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public static function resourceRestrictions(): array
    {
        return [
            'inactive facility' => ['hub', 'is_active', false],
            'unknown facility tier' => ['hub', 'tier', 'unknown'],
            'maintenance vehicle' => ['vehicle', 'status', 'maintenance'],
            'idle vehicle' => ['vehicle', 'status', 'idle'],
            'unknown vehicle status' => ['vehicle', 'status', 'unknown'],
            'unknown vehicle type' => ['vehicle', 'vehicle_type', 'unknown'],
            'missing vehicle driver' => ['vehicle', 'assigned_driver_id', null],
        ];
    }

    #[DataProvider('resourceRestrictions')]
    public function test_ineligible_children_are_hidden_and_direct_claims_leave_parcels_untouched(string $model, string $field, mixed $value): void
    {
        $delivery = $this->pickup();
        $this->{$model}->update([$field => $value]);
        $this->actingAs($this->rider)->get(route('courier.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scope.isOperational', false)->has('queues.availablePickups', 0)
                ->missing('auth.user.courier_profile.vehicle')->missing('auth.user.courier_profile.hub'));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
    }

    public function test_foreign_resource_relationships_cannot_enable_claims_or_leak_private_fields(): void
    {
        [$company, $hub] = $this->foreignNetwork();
        $delivery = $this->pickup();
        foreach ([['logistics_company_id' => $company->id], ['hub_id' => $hub->id], ['assigned_driver_id' => $this->account('courier')->id]] as $change) {
            $original = $this->vehicle->getAttributes();
            $this->vehicle->update($change);
            $this->assertFalse(app(LogisticsEligibilityService::class)->isOperational($this->rider->courierProfile));
            $this->assertSame([], app(LogisticsEligibilityService::class)->riderCandidates($this->hub)->pluck('user_id')->all());
            $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
            $this->vehicle->setRawAttributes($original)->save();
        }
        $this->rider->courierProfile->update(['assigned_hub_id' => $hub->id]);
        $this->actingAs($this->rider)->get(route('courier.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('scope.hub', null)->where('scope.hubCode', null)->has('queues.availablePickups', 0));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
    }

    public function test_a_hub_parent_change_does_not_move_a_handler_to_another_company(): void
    {
        [$foreign] = $this->foreignNetwork();
        $original = HubHandler::where('user_id', $this->handler->id)->firstOrFail();
        $this->hub->update(['logistics_company_id' => $foreign->id]);
        $this->assertSame($this->company->id, $original->fresh()->logistics_company_id);
        $this->assertFalse(app(LogisticsEligibilityService::class)->canScan($this->handler, $this->hub));
        $this->actingAs($this->handler)->get(route('hub.network'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('hubs', 0));
    }

    public function test_wrong_role_owner_and_unapproved_handler_are_not_operational(): void
    {
        $this->company->update(['user_id' => $this->account('buyer')->id]);
        $this->assertDatabaseCount('hub_handlers', 1);
        $this->assertSame([], LogisticsHub::eligible()->pluck('id')->all());
        $this->company->update(['user_id' => $this->owner->id]);
        $this->handler->update(['kyc_status' => 'pending_approval']);
        $this->assertFalse(app(LogisticsEligibilityService::class)->canScan($this->handler, $this->hub));
        $delivery = $this->pickup();
        $delivery->update(['status' => 'picked_up', 'courier_id' => $this->rider->id]);
        $this->deny(fn () => app(OrderStateMachineService::class)->transition($delivery, 'arrived_at_origin_hub', $this->handler, ['hub_id' => $this->hub->id]));
        $this->assertSame('picked_up', $delivery->fresh()->status);
    }

    public function test_reviewed_legacy_accounts_can_work_and_off_duty_only_blocks_new_assignments(): void
    {
        foreach ([$this->owner, $this->handler, $this->rider] as $account) {
            $account->update(['kyc_status' => 'verified', 'birthday' => null]);
        }
        $delivery = $this->pickup();
        app(CourierOperationsService::class)->claimPickup($this->rider, $delivery);
        app(CourierOperationsService::class)->setAvailability($this->rider, false);
        app(OrderStateMachineService::class)->transition($delivery, 'picked_up', $this->rider);
        $other = $this->pickup();
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $other));
        app(OrderStateMachineService::class)->transition($delivery, 'arrived_at_origin_hub', $this->handler, ['hub_id' => $this->hub->id]);
        $this->assertSame('arrived_at_origin_hub', $delivery->fresh()->status);
        $this->assertSame('verified', $this->rider->fresh()->kyc_status);
        $this->assertFalse($this->rider->courierProfile->fresh()->is_available);
    }

    public function test_existing_assignments_do_not_bypass_a_suspended_parent_or_vehicle(): void
    {
        $delivery = $this->pickup();
        app(CourierOperationsService::class)->claimPickup($this->rider, $delivery);
        $this->vehicle->update(['status' => 'maintenance']);
        $this->deny(fn () => app(OrderStateMachineService::class)->transition($delivery, 'picked_up', $this->rider));
        $this->vehicle->update(['status' => 'active']);
        $this->company->update(['status' => 'suspended']);
        $this->deny(fn () => app(OrderStateMachineService::class)->transition($delivery, 'picked_up', $this->rider));
        $this->deny(fn () => app(CourierOperationsService::class)->setAvailability($this->rider, true));
        app(CourierOperationsService::class)->setAvailability($this->rider, false);
        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
        $this->assertSame($this->rider->id, $delivery->fresh()->courier_id);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
    }

    public function test_stale_account_and_profile_are_rechecked_for_direct_work(): void
    {
        $delivery = $this->pickup();
        $cached = $this->rider->fresh('courierProfile');
        User::whereKey($cached->id)->update(['kyc_status' => 'rejected']);
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($cached, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertSame('rejected', $cached->fresh()->kyc_status);
    }

    public static function portals(): array
    {
        return ['root' => ['http://localhost/hub'], 'subdomain' => ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_both_hub_portals_share_reads_placement_and_floor_authority(string $prefix): void
    {
        $this->actingAs($this->owner)->get($prefix.'/network')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('hubs', 1)->where('scope.can_switch_facility', true)->where('scope.can_scan', false));
        $this->get($prefix.'/scan')->assertForbidden();
        $newHandler = $this->account('logistics');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson($prefix.'/placements', ['kind' => 'handler', 'email' => $newHandler->email, 'hub_id' => $this->hub->id])->assertOk();
        }
        $this->assertSame(1, HubHandler::where('user_id', $newHandler->id)->count());
        $this->assertDatabaseCount('logistics_placement_records', 1);
        Auth::forgetGuards();
        $this->actingAs($newHandler)->withSession(['active_hub_id' => $this->hub->id])->get($prefix.'/scan')->assertOk();
        $this->postJson($prefix.'/placements', ['kind' => 'handler', 'email' => $this->owner->email, 'hub_id' => $this->hub->id])->assertForbidden();
    }

    #[DataProvider('portals')]
    public function test_foreign_stale_and_ineligible_requests_fail_on_both_portals(string $prefix): void
    {
        [, $foreign] = $this->foreignNetwork();
        $this->actingAs($this->owner)->withSession(['active_hub_id' => $this->hub->id]);
        $this->postJson($prefix.'/placements', ['kind' => 'handler', 'email' => $this->handler->email, 'hub_id' => $foreign->id])->assertForbidden();
        $this->assertSame($this->hub->id, session('active_hub_id'));
        $this->company->update(['status' => 'suspended']);
        $this->get($prefix.'/deliveries')->assertForbidden();
        $this->postJson($prefix.'/switch-hub', ['hub_id' => $this->hub->id])->assertForbidden();
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_unassigned_workstations_never_return_private_parcels_candidates_or_counts(): void
    {
        $delivery = $this->pickup();
        $delivery->update(['delivery_type' => 'hub_self_pickup', 'status' => 'ready_for_hub_pickup']);
        $this->actingAs($this->account('logistics'))->get(route('hub.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 0)->has('eligibleRiders', 0)->where('counts.ready_pickup', 0));
        $this->get(route('hub.counter'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('counterParcels', 0)->has('recentlyCollected', 0));
    }

    public function test_foreign_parcels_and_fleet_cannot_leak_through_a_matching_hub_id(): void
    {
        [$foreign] = $this->foreignNetwork();
        $delivery = $this->pickup();
        $delivery->update(['logistics_company_id' => $foreign->id, 'current_hub_id' => $this->hub->id,
            'delivery_type' => 'hub_self_pickup', 'status' => 'ready_for_hub_pickup']);
        $this->vehicle->update(['logistics_company_id' => $foreign->id]);
        $this->actingAs($this->handler)->get(route('hub.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 0)->where('counts.ready_pickup', 0));
        $this->get(route('hub.counter'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('counterParcels', 0));
        $this->get(route('hub.fleet'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('fleet', 0)->where('stats.total', 0));
    }

    public function test_pending_courier_placement_cannot_approve_or_assign_the_account(): void
    {
        $this->rider->update(['kyc_status' => 'pending_approval']);
        $before = $this->rider->courierProfile->getAttributes();
        $this->deny(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $this->vehicle->id));
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertSame('pending_approval', $this->rider->fresh()->kyc_status);
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_first_courier_placement_and_retry_keep_approval_history_and_duty_honest(): void
    {
        $courier = $this->account('courier');
        CourierProfile::factory()->create(['user_id' => $courier->id, 'logistics_company_id' => null, 'assigned_hub_id' => null, 'vehicle_id' => null, 'is_available' => true]);
        $vehicle = $this->vehicle->replicate();
        $vehicle->fill(['plate_number' => 'B04-002', 'assigned_driver_id' => null])->save();
        $placements = app(LogisticsPlacementService::class);
        $placed = $placements->placeCourier($this->owner, $this->hub, $courier, $vehicle->id, 'Poblacion III');
        $originalRecord = LogisticsPlacementRecord::firstOrFail()->getAttributes();
        $again = $placements->placeCourier($this->owner, $this->hub, $courier, $vehicle->id, 'Poblacion III');
        $this->assertSame($placed->fresh()->getAttributes(), $again->getAttributes());
        $this->assertSame($originalRecord, LogisticsPlacementRecord::firstOrFail()->getAttributes());
        $this->assertDatabaseCount('logistics_placement_records', 1);
        $this->assertFalse($placed->is_available);
        $this->assertSame('approved', $courier->fresh()->kyc_status);
        $this->assertSame($courier->id, $vehicle->fresh()->assigned_driver_id);
        app(CourierOperationsService::class)->setAvailability($courier, true);
        $this->assertTrue($placed->fresh()->is_available);
    }

    public function test_failed_placement_rolls_back_both_sides_and_does_not_touch_custody(): void
    {
        $vehicle = $this->vehicle->replicate();
        $vehicle->fill(['plate_number' => 'B04-002', 'assigned_driver_id' => null])->save();
        $before = $this->rider->courierProfile->getAttributes();
        LogisticsPlacementRecord::creating(fn () => throw new DomainException('Simulated history storage failure.'));
        try {
            $this->deny(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $vehicle->id, expectedHubId: $this->hub->id));
        } finally {
            LogisticsPlacementRecord::flushEventListeners();
            LogisticsPlacementRecord::clearBootedModels();
            new LogisticsPlacementRecord;
        }
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertSame($this->rider->id, $this->vehicle->fresh()->assigned_driver_id);
        $this->assertNull($vehicle->fresh()->assigned_driver_id);
        $this->assertDatabaseCount('logistics_placement_records', 0);
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    public function test_active_custody_foreign_vehicle_and_stale_placement_do_not_change_existing_assignments(): void
    {
        $delivery = $this->pickup();
        app(CourierOperationsService::class)->claimPickup($this->rider, $delivery);
        $vehicle = $this->vehicle->replicate();
        $vehicle->fill(['plate_number' => 'B04-002', 'assigned_driver_id' => null])->save();
        $before = $this->rider->courierProfile->getAttributes();
        $this->deny(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $vehicle->id, expectedHubId: $this->hub->id));
        $delivery->update(['status' => 'return_to_sender']);
        $this->deny(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $vehicle->id, expectedHubId: $this->hub->id));
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->assertSame($this->rider->id, $delivery->fresh()->courier_id);
    }

    public function test_destination_assignment_retry_preserves_the_first_rider_and_checkpoint(): void
    {
        $delivery = $this->pickup();
        $delivery->update(['status' => 'sorted_to_barangay_bin', 'current_hub_id' => $this->hub->id]);
        $metadata = ['hub_id' => $this->hub->id, 'rider_id' => $this->rider->id];
        $state = app(OrderStateMachineService::class);
        $state->transition($delivery, 'assigned_to_rider', $this->owner, $metadata);
        $before = $delivery->fresh()->getAttributes();
        app(CourierOperationsService::class)->setAvailability($this->rider, false);
        $state->transition($delivery, 'assigned_to_rider', $this->owner, $metadata);
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 1);
        $this->deny(fn () => $state->transition($delivery, 'assigned_to_rider', $this->owner, [...$metadata, 'rider_id' => $this->account('courier')->id]));
        $this->assertSame($this->rider->id, $delivery->fresh()->assigned_rider_id);
    }

    public function test_busy_rider_is_absent_from_candidates_and_admin_audit_cannot_scan(): void
    {
        $delivery = $this->pickup();
        app(CourierOperationsService::class)->claimPickup($this->rider, $delivery);
        $this->assertSame([], app(LogisticsEligibilityService::class)->riderCandidates($this->hub)->pluck('user_id')->all());
        $admin = $this->account('admin');
        $this->actingAs($admin)->get(route('admin.logistics'))->assertOk();
        $this->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $this->hub->id])->assertForbidden();
        $this->deny(fn () => app(OrderStateMachineService::class)->transition($delivery, 'arrived_at_origin_hub', $admin, ['hub_id' => $this->hub->id]));
        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
    }

    public function test_route_planning_rechecks_cached_company_and_always_requires_a_mother_hub(): void
    {
        $shop = Shop::factory()->create(['city' => 'Santa Cruz']);
        $delivery = $this->pickup();
        $order = $delivery->order;
        $order->update(['shipping_province' => 'Laguna', 'shipping_city' => 'Santa Cruz']);
        $delivery->load('company');
        $before = $delivery->fresh()->getAttributes();
        $engine = app(LogisticsRoutingEngine::class);
        $this->deny(fn () => $engine->planDeliveryRoute($delivery, $order, $shop));
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $mother = $this->hub->replicate();
        $mother->fill(['name' => 'Bagoo Mother Hub', 'code' => 'B04-MH', 'tier' => 'regional_mother_hub'])->save();
        $engine->planDeliveryRoute($delivery, $order, $shop);
        $this->assertSame($mother->id, $delivery->fresh()->origin_mother_hub_id);
        $this->assertSame($mother->id, $delivery->fresh()->destination_mother_hub_id);
        $before = $delivery->fresh()->getAttributes();
        $this->owner->update(['kyc_status' => 'rejected']);
        $this->deny(fn () => $engine->planDeliveryRoute($delivery, $order, $shop));
        $this->assertSame($before, $delivery->fresh()->getAttributes());
    }

    public function test_vehicle_and_personnel_placement_reject_foreign_and_stale_relationships(): void
    {
        [$foreign, $foreignHub] = $this->foreignNetwork();
        $vehicle = $this->vehicle->replicate();
        $vehicle->fill(['logistics_company_id' => $foreign->id, 'hub_id' => $foreignHub->id,
            'plate_number' => 'OTHER-001', 'assigned_driver_id' => null])->save();
        $placements = app(LogisticsPlacementService::class);
        $before = $this->rider->courierProfile->getAttributes();
        $this->deny(fn () => $placements->placeCourier($this->owner, $this->hub, $this->rider, $vehicle->id, expectedHubId: $this->hub->id));
        $this->deny(fn () => $placements->placeCourier($this->owner, $this->hub, $this->rider, $this->vehicle->id, 'Changed barangay', expectedHubId: $foreignHub->id));
        $otherHandler = $this->account('logistics');
        HubHandler::create(['user_id' => $otherHandler->id, 'hub_id' => $foreignHub->id, 'is_active' => true]);
        $this->deny(fn () => $placements->assignHandler($this->owner, $this->hub, $otherHandler));
        HubHandler::where('user_id', $this->handler->id)->update(['is_active' => false]);
        $this->deny(fn () => $placements->assignHandler($this->owner, $this->hub, $this->handler));
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertFalse(HubHandler::where('user_id', $this->handler->id)->firstOrFail()->is_active);
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_assignment_checkpoint_failure_rolls_back_parcel_order_and_rider(): void
    {
        $delivery = $this->pickup();
        $delivery->update(['status' => 'sorted_to_barangay_bin', 'current_hub_id' => $this->hub->id]);
        $parcelBefore = $delivery->fresh()->getAttributes();
        $orderBefore = $delivery->order->getAttributes();
        DeliveryCheckpoint::creating(fn () => throw new DomainException('Simulated checkpoint storage failure.'));
        try {
            $this->deny(fn () => app(OrderStateMachineService::class)->transition($delivery, 'assigned_to_rider', $this->owner, ['hub_id' => $this->hub->id, 'rider_id' => $this->rider->id]));
        } finally {
            DeliveryCheckpoint::flushEventListeners();
        }
        $this->assertSame($parcelBefore, $delivery->fresh()->getAttributes());
        $this->assertSame($orderBefore, $delivery->order->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 0);
    }

    #[DataProvider('portals')]
    public function test_dispatch_http_retry_preserves_assignment_even_when_rider_is_now_off_duty(string $prefix): void
    {
        $delivery = $this->pickup();
        $delivery->update(['status' => 'sorted_to_barangay_bin', 'current_hub_id' => $this->hub->id]);
        $this->rider->courierProfile->update(['is_available' => false]);
        $this->actingAs($this->owner)->postJson($prefix.'/deliveries/'.$delivery->id.'/assign-rider', ['rider_id' => $this->rider->id])->assertUnprocessable();
        $this->assertNull($delivery->fresh()->assigned_rider_id);
        $this->rider->courierProfile->update(['is_available' => true]);
        $this->postJson($prefix.'/deliveries/'.$delivery->id.'/assign-rider', ['rider_id' => $this->rider->id])->assertOk();
        $before = $delivery->fresh()->getAttributes();
        $this->rider->courierProfile->update(['is_available' => false]);
        $this->postJson($prefix.'/deliveries/'.$delivery->id.'/assign-rider', ['rider_id' => $this->rider->id])->assertOk();
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertDatabaseCount('delivery_checkpoints', 1);
    }

    public function test_handler_scope_migration_preserves_flags_and_stamps_the_existing_relationship_only(): void
    {
        $this->company->update(['status' => 'pending', 'is_active' => false]);
        $this->handler->update(['kyc_status' => 'rejected']);
        $migration = require database_path('migrations/2026_10_05_070100_preserve_handler_company_scope.php');
        $migration->down();
        $before = DB::table('hub_handlers')->first();
        $migration->up();
        $after = DB::table('hub_handlers')->first();
        $this->assertSame($this->company->id, $after->logistics_company_id);
        foreach (['id', 'user_id', 'hub_id', 'is_active', 'created_at', 'updated_at'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field});
        }
        $this->assertSame('rejected', $this->handler->fresh()->kyc_status);
        $this->assertSame('pending', $this->company->fresh()->status);
        $this->assertSame([], HubHandler::eligible()->pluck('id')->all());
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_buyer_completion_survives_a_restricted_network_without_new_custody(): void
    {
        $delivery = $this->pickup();
        $delivery->update(['status' => 'delivered']);
        $delivery->order->update(['status' => 'delivered']);
        $this->company->update(['status' => 'suspended']);
        $buyer = $delivery->order->buyer;
        $buyer->update(['status' => 'active', 'kyc_status' => 'approved']);
        app(OrderStateMachineService::class)->transition($delivery, 'completed', $buyer);
        $this->assertSame('completed', $delivery->fresh()->status);
        $this->assertSame('completed', $delivery->order->fresh()->status);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_mother_hub_cannot_offer_or_perform_a_bayan_pickup(): void
    {
        $this->hub->update(['tier' => 'regional_mother_hub']);
        $delivery = $this->pickup();
        $this->assertSame([], app(LogisticsEligibilityService::class)->riderCandidates($this->hub)->pluck('user_id')->all());
        $this->actingAs($this->rider)->get(route('courier.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queues.availablePickups', 0));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
        $this->assertNull($delivery->fresh()->courier_id);
    }

    public function test_facility_tier_is_rechecked_between_route_selection_and_save(): void
    {
        $shop = Shop::factory()->create(['city' => 'Santa Cruz']);
        $delivery = $this->pickup();
        $order = $delivery->order;
        $order->update(['shipping_province' => 'Laguna', 'shipping_city' => 'Santa Cruz']);
        $mother = $this->hub->replicate();
        $mother->fill(['code' => 'B04-MH', 'tier' => 'regional_mother_hub'])->save();
        $before = $delivery->fresh()->getAttributes();
        $real = app(LogisticsEligibilityService::class);
        $this->mock(LogisticsEligibilityService::class, function ($mock) use ($real, $mother) {
            $mock->shouldReceive('lockNetwork')->once()->andReturnUsing(function ($companyId, $accounts = [], $hubs = []) use ($real, $mother) {
                LogisticsHub::whereKey($mother->id)->update(['tier' => 'local_bayan_hub']);

                return $real->lockNetwork($companyId, $accounts, $hubs);
            });
        });
        $this->deny(fn () => app(LogisticsRoutingEngine::class)->planDeliveryRoute($delivery, $order, $shop));
        $this->assertSame($before, $delivery->fresh()->getAttributes());
        $this->assertSame('regional_mother_hub', $mother->fresh()->tier);
    }

    public function test_placement_history_cannot_be_rewritten_or_deleted(): void
    {
        app(LogisticsPlacementService::class)->assignHandler($this->owner, $this->hub, $this->account('logistics'));
        $record = LogisticsPlacementRecord::firstOrFail();
        $before = $record->getAttributes();
        foreach ([fn () => $record->update(['kind' => 'courier']), fn () => $record->delete()] as $action) {
            try {
                $action();
                $this->fail('Placement history was modified.');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public function test_direct_database_writes_cannot_rewrite_or_delete_placement_history(): void
    {
        app(LogisticsPlacementService::class)->assignHandler($this->owner, $this->hub, $this->account('logistics'));
        $record = LogisticsPlacementRecord::firstOrFail();
        $before = $record->getAttributes();
        foreach ([
            fn () => DB::table('logistics_placement_records')->where('id', $record->id)->update(['kind' => 'courier']),
            fn () => DB::table('logistics_placement_records')->where('id', $record->id)->delete(),
        ] as $action) {
            try {
                DB::transaction($action);
                $this->fail('The database accepted a placement history mutation.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Logistics placement records are immutable.', $exception->getMessage());
            }
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public function test_courier_placement_cannot_assign_an_unserved_barangay(): void
    {
        $this->hub->update(['coverage_barangays' => ['Poblacion III']]);
        $before = $this->rider->courierProfile->getAttributes();
        $this->deny(fn () => app(LogisticsPlacementService::class)->placeCourier($this->owner, $this->hub, $this->rider, $this->vehicle->id, 'Outside service area', $this->hub->id));
        $this->assertSame($before, $this->rider->courierProfile->fresh()->getAttributes());
        $this->assertDatabaseCount('logistics_placement_records', 0);
    }

    public function test_existing_message_history_remains_owned_and_operational_writes_recheck_parents(): void
    {
        $delivery = $this->pickup();
        $delivery->update(['status' => 'assigned_to_rider', 'assigned_rider_id' => $this->rider->id]);
        $messages = app(CourierMessagingService::class);
        $messages->send($this->rider, $delivery, 'The parcel is ready for delivery.', 'final_mile');
        $this->vehicle->update(['status' => 'maintenance']);
        $history = $messages->conversations($this->rider);
        $this->assertCount(1, $history);
        $this->assertFalse($history[0]['can_send']);
        $this->assertCount(1, $history[0]['messages']);
        $this->deny(fn () => $messages->send($this->rider, $delivery, 'A second update.', 'final_mile'));
        $this->assertDatabaseCount('messages', 1);
        User::whereKey($this->rider->id)->update(['status' => 'suspended']);
        $this->assertSame([], $messages->conversations($this->rider));
    }

    public function test_final_mile_work_and_pickup_capacity_agree_with_the_job_board(): void
    {
        $final = $this->pickup();
        $final->update(['status' => 'sorted_to_barangay_bin', 'current_hub_id' => $this->hub->id]);
        app(OrderStateMachineService::class)->transition($final, 'assigned_to_rider', $this->owner, ['hub_id' => $this->hub->id, 'rider_id' => $this->rider->id]);
        $new = $this->pickup();
        $this->actingAs($this->rider)->get(route('courier.deliveries'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('queues.availablePickups', 0)->has('queues.finalMileTasks', 1));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $new));
        $final->update(['status' => 'delivered']);
        foreach (range(1, Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) as $index) {
            $parcel = $this->pickup();
            app(CourierOperationsService::class)->claimPickup($this->rider, $parcel);
        }
        $this->get(route('courier.deliveries'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('queues.availablePickups', 0));
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $new));
        $this->assertNull($new->fresh()->courier_id);
    }

    public function test_ambiguous_owner_company_relationships_require_review_instead_of_a_first_match(): void
    {
        $duplicate = $this->company->replicate();
        $duplicate->fill(['slug' => 'duplicate-network', 'code' => 'DUPLICATE'])->save();
        $delivery = $this->pickup();
        $this->assertSame([], LogisticsCompany::eligible()->pluck('id')->all());
        $this->assertSame([], LogisticsHub::eligible()->pluck('id')->all());
        $this->deny(fn () => app(CourierOperationsService::class)->claimPickup($this->rider, $delivery));
        $this->assertSame('active', $this->company->fresh()->status);
        $this->assertSame('active', $duplicate->fresh()->status);
        $this->assertNull($delivery->fresh()->courier_id);
    }

    public function test_a_courier_can_enter_final_mile_after_its_pickup_handoff_and_new_placement(): void
    {
        $destination = $this->hub->replicate();
        $destination->fill(['code' => 'B04-DEST', 'name' => 'Bagoo Destination Hub'])->save();
        $vehicle = $this->vehicle->replicate();
        $vehicle->fill(['plate_number' => 'B04-DEST-001', 'hub_id' => $destination->id, 'assigned_driver_id' => null])->save();
        $delivery = $this->pickup();
        $delivery->update(['courier_id' => $this->rider->id, 'status' => 'sorted_to_barangay_bin', 'current_hub_id' => $destination->id, 'destination_bayan_hub_id' => $destination->id]);
        app(LogisticsPlacementService::class)->placeCourier($this->owner, $destination, $this->rider, $vehicle->id, expectedHubId: $this->hub->id);
        app(CourierOperationsService::class)->setAvailability($this->rider, true);
        app(OrderStateMachineService::class)->transition($delivery, 'assigned_to_rider', $this->owner, ['hub_id' => $destination->id, 'rider_id' => $this->rider->id]);
        app(CourierMessagingService::class)->send($this->rider, $delivery->fresh(), 'The final-mile parcel is ready.', 'final_mile');
        $this->assertSame($this->rider->id, $delivery->fresh()->courier_id);
        $this->assertSame($this->rider->id, $delivery->fresh()->assigned_rider_id);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('logistics_placement_records', 1);
    }

    private function foreignNetwork(): array
    {
        $owner = $this->account('logistics');
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Other Bagoo Network', 'slug' => 'other-network', 'code' => 'OTHER', 'status' => 'active', 'is_active' => true]);
        $hub = $this->hub->replicate();
        $hub->fill(['logistics_company_id' => $company->id, 'code' => 'OTHER-BH'])->save();

        return [$company, $hub];
    }

    private function account(string $role, array $attributes = []): User
    {
        return User::factory()->create([...['role' => $role, 'status' => 'active', 'kyc_status' => 'approved', 'birthday' => '2000-01-01'], ...$attributes]);
    }

    private function pickup(): Delivery
    {
        return Delivery::factory()->create([
            'order_id' => Order::factory()->create(['status' => 'ready_for_pickup'])->id,
            'logistics_company_id' => $this->company->id, 'origin_bayan_hub_id' => $this->hub->id,
            'destination_bayan_hub_id' => $this->hub->id, 'courier_id' => null, 'status' => 'unassigned',
        ]);
    }

    private function deny(callable $action): void
    {
        try {
            $action();
            $this->fail('An ineligible logistics action was accepted.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
