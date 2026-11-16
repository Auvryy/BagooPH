<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use App\Services\Logistics\HubFleetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class HubFleetEditingTest extends TestCase
{
    use RefreshDatabase;

    private LogisticsHub $mother;

    private LogisticsHub $child;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoutingCheckoutSeeder::class);
        $this->mother = LogisticsHub::where('code', 'MH-LAG-01')->sole();
        $this->child = LogisticsHub::where('parent_hub_id', $this->mother->id)->firstOrFail();
        $this->operator = User::factory()->create(['role' => 'logistics']);
        HubHandler::create(['user_id' => $this->operator->id, 'hub_id' => $this->mother->id, 'is_active' => true, 'job_role' => 'all', 'role_title' => 'Operator']);
    }

    private function vehicle(): LogisticsFleet
    {
        return LogisticsFleet::create(['logistics_company_id' => $this->mother->logistics_company_id, 'hub_id' => $this->mother->id,
            'plate_number' => 'EDIT-001', 'vehicle_type' => 'l300_van', 'capacity_kg' => 1200, 'model' => 'Closed transfer van', 'status' => 'active']);
    }

    private function input(LogisticsFleet $vehicle, array $changes = []): array
    {
        return [...$vehicle->only(['plate_number', 'vehicle_type', 'capacity_kg', 'model']), 'hub_id' => $this->mother->id,
            'target_hub_id' => $this->child->id, 'expected_state' => HubFleetService::stateToken($vehicle), ...$changes];
    }

    public function test_mother_operator_can_add_at_child_and_edit_existing_vehicles_with_stale_protection(): void
    {
        $this->actingAs($this->operator)->post(route('hub.fleet.store'), ['hub_id' => $this->mother->id, 'target_hub_id' => $this->child->id,
            'plate_number' => 'NEW-CHILD', 'vehicle_type' => 'l300_van', 'capacity_kg' => 1200])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('logistics_fleet', ['plate_number' => 'NEW-CHILD', 'hub_id' => $this->child->id]);
        $vehicle = $this->vehicle();
        $input = $this->input($vehicle, ['plate_number' => 'EDIT-UPDATED', 'model' => 'Updated closed van']);
        $this->put(route('hub.fleet.update', $vehicle), $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('logistics_fleet', ['id' => $vehicle->id, 'hub_id' => $this->child->id, 'plate_number' => 'EDIT-UPDATED', 'model' => 'Updated closed van']);
        $this->put(route('hub.fleet.update', $vehicle), $input)->assertSessionHasErrors('expected_state');
        $this->get(route('hub.fleet', ['hub_id' => $this->mother->id]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assignmentHubs', fn ($hubs) => collect($hubs)->contains('id', $this->child->id))
            ->where('fleet', fn ($fleet) => collect($fleet)->contains('plate_number', 'EDIT-UPDATED')));
    }

    public function test_moving_an_assigned_vehicle_keeps_driver_links_consistent_and_retains_history(): void
    {
        $vehicle = $this->vehicle();
        $driver = User::factory()->create(['role' => 'courier']);
        $profile = CourierProfile::factory()->create(['user_id' => $driver->id, 'logistics_company_id' => $vehicle->logistics_company_id,
            'assigned_hub_id' => $this->mother->id, 'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $vehicle->update(['assigned_driver_id' => $driver->id]);
        $this->actingAs($this->operator)->put(route('hub.fleet.update', $vehicle), $this->input($vehicle))->assertSessionHasNoErrors();
        $this->assertSame($this->child->id, $vehicle->fresh()->hub_id);
        $this->assertSame($this->child->id, $profile->fresh()->assigned_hub_id);
        $this->assertSame($vehicle->id, $profile->fresh()->vehicle_id);
        $this->assertSame($driver->id, $vehicle->fresh()->assigned_driver_id);
        $this->assertFalse($profile->fresh()->is_available);
        $this->assertDatabaseHas('logistics_placement_records', ['user_id' => $driver->id, 'actor_id' => $this->operator->id]);
    }

    public function test_child_operator_cannot_assign_upwards_and_foreign_company_is_rejected(): void
    {
        $childOperator = User::factory()->create(['role' => 'logistics']);
        HubHandler::create(['user_id' => $childOperator->id, 'hub_id' => $this->child->id, 'is_active' => true, 'job_role' => 'all', 'role_title' => 'Operator']);
        $this->actingAs($childOperator)->post(route('hub.fleet.store'), ['hub_id' => $this->child->id, 'target_hub_id' => $this->mother->id,
            'plate_number' => 'FORBIDDEN', 'vehicle_type' => 'l300_van', 'capacity_kg' => 1200])->assertForbidden();
        $foreignOwner = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $foreignOwner->id, 'name' => 'Other Fleet', 'code' => 'OTHER-FLEET', 'slug' => 'other-fleet', 'status' => 'active', 'is_active' => true]);
        $foreign = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Other hub', 'code' => 'OTHER-HUB',
            'tier' => 'regional_mother_hub', 'province' => 'Laguna', 'city_municipality' => 'Calamba City', 'address' => 'Other address', 'is_active' => true]);
        $vehicle = $this->vehicle();
        $this->actingAs($this->operator)->put(route('hub.fleet.update', $vehicle), $this->input($vehicle, ['target_hub_id' => $foreign->id]))->assertForbidden();
        $this->assertSame($this->mother->id, $vehicle->fresh()->hub_id);
    }

    public function test_existing_transfer_prevents_vehicle_changes(): void
    {
        $vehicle = $this->vehicle();
        $driver = User::factory()->create(['role' => 'courier']);
        LogisticsManifest::create(['logistics_company_id' => $vehicle->logistics_company_id, 'source_hub_id' => $this->mother->id,
            'destination_hub_id' => $this->child->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
            'created_by_id' => $this->operator->id, 'creation_token' => (string) Str::uuid(), 'creation_fingerprint' => str_repeat('a', 64), 'type' => 'feeder', 'status' => 'draft']);
        $this->actingAs($this->operator)->put(route('hub.fleet.update', $vehicle), $this->input($vehicle))->assertSessionHasErrors('vehicle');
        $this->assertSame($this->mother->id, $vehicle->fresh()->hub_id);
    }
}
