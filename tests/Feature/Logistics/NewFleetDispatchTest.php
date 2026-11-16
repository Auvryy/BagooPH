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
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\MotherBagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NewFleetDispatchTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $driver;

    private LogisticsHub $source;

    private LogisticsHub $destination;

    private LogisticsHub $child;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = User::factory()->create(['role' => 'logistics']);
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Truck Test', 'code' => 'TRUCK', 'slug' => 'truck-test', 'status' => 'active', 'is_active' => true]);
        foreach (['source', 'destination', 'child'] as $name) {
            $this->{$name} = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => $name.' hub', 'code' => 'TRUCK-'.strtoupper($name),
                'tier' => $name === 'child' ? 'local_bayan_hub' : 'regional_mother_hub', 'is_active' => true, 'province' => 'Laguna', 'city_municipality' => 'Calamba City', 'address' => '100 Truck Street']);
        }
        $this->child->update(['parent_hub_id' => $this->source->id]);
        $this->operator = User::factory()->create(['role' => 'logistics']);
        HubHandler::create(['user_id' => $this->operator->id, 'hub_id' => $this->source->id, 'is_active' => true, 'job_role' => 'all', 'role_title' => 'Operator']);
        $this->driver = User::factory()->create(['role' => 'courier']);
        CourierProfile::factory()->create(['user_id' => $this->driver->id, 'logistics_company_id' => $company->id,
            'assigned_hub_id' => $this->source->id, 'vehicle_id' => null, 'is_available' => true]);
    }

    private function createTruck(array $overrides = []): LogisticsFleet
    {
        $this->actingAs($this->operator)->post(route('hub.fleet.store'), ['hub_id' => $this->source->id,
            'plate_number' => 'NEW-WING-001', 'vehicle_type' => 'wing_truck', 'capacity_kg' => 8000,
            'driver_id' => $this->driver->id, ...$overrides])->assertSessionHasNoErrors();

        return LogisticsFleet::where('plate_number', $overrides['plate_number'] ?? 'NEW-WING-001')->sole();
    }

    public function test_new_truck_is_selectable_in_manifests_and_mother_bags_for_correct_route(): void
    {
        $truck = $this->createTruck();
        $this->assertSame($this->driver->id, $truck->assigned_driver_id);
        $this->assertSame($truck->id, $this->driver->courierProfile->fresh()->vehicle_id);
        $this->assertTrue($this->driver->courierProfile->fresh()->is_available);
        $this->get(route('hub.manifests.index', ['hub_id' => $this->source->id]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vehicles', fn ($rows) => collect($rows)->contains('id', $truck->id)));
        $this->assertSame($truck->id, MotherBagService::dispatchVehicles($this->source, $this->destination)[0]['id']);
        $this->assertSame([], MotherBagService::dispatchVehicles($this->source, $this->child));
        $this->assertSame([], MotherBagService::dispatchVehicles($this->destination, $this->source));
        $token = (string) Str::uuid();
        $this->post('/hub/manifests', ['source_hub_id' => $this->source->id, 'destination_hub_id' => $this->destination->id,
            'vehicle_id' => $truck->id, 'creation_token' => $this->app['session']->get('manifest_creation_token')])->assertSessionHasNoErrors();
        $manifest = LogisticsManifest::sole();
        $this->assertSame([$this->source->id, $this->destination->id, $truck->id, $this->driver->id],
            [$manifest->source_hub_id, $manifest->destination_hub_id, $manifest->vehicle_id, $manifest->driver_id]);
        $this->assertSame('line_haul', $manifest->type);
        $this->assertSame($this->source->id, $truck->fresh()->hub_id);
    }

    public function test_multiple_trucks_and_drivers_can_prepare_the_same_hub_route(): void
    {
        $first = $this->createTruck();
        $secondDriver = User::factory()->create(['role' => 'courier']);
        CourierProfile::factory()->create(['user_id' => $secondDriver->id, 'logistics_company_id' => $first->logistics_company_id,
            'assigned_hub_id' => $this->source->id, 'vehicle_id' => null, 'is_available' => true]);
        $second = $this->createTruck(['plate_number' => 'PARALLEL-WING', 'driver_id' => $secondDriver->id]);
        $this->assertCount(2, MotherBagService::dispatchVehicles($this->source, $this->destination));
        foreach ([$first, $second] as $truck) {
            $token = (string) Str::uuid();
            $manifest = app(LogisticsManifestService::class)->create($this->operator, ['source_hub_id' => $this->source->id,
                'destination_hub_id' => $this->destination->id, 'vehicle_id' => $truck->id, 'creation_token' => $token], $token);
            $this->assertSame($truck->assigned_driver_id, $manifest->driver_id);
            $this->assertSame($this->destination->id, $manifest->destination_hub_id);
        }
        $this->assertDatabaseCount('logistics_manifests', 2);
    }

    public function test_existing_unassigned_truck_can_use_an_existing_on_duty_driver_without_broken_old_links(): void
    {
        $old = $this->createTruck();
        $new = $this->createTruck(['plate_number' => 'SECOND-WING', 'driver_id' => null]);
        $this->put(route('hub.fleet.update', $new), [...$new->only(['plate_number', 'vehicle_type', 'capacity_kg', 'model']),
            'hub_id' => $this->source->id, 'target_hub_id' => $this->source->id, 'driver_id' => $this->driver->id,
            'expected_state' => HubFleetService::stateToken($new)])->assertSessionHasNoErrors();
        $this->assertNull($old->fresh()->assigned_driver_id);
        $this->assertSame($this->driver->id, $new->fresh()->assigned_driver_id);
        $this->assertSame($new->id, $this->driver->courierProfile->fresh()->vehicle_id);
        $this->assertTrue($this->driver->courierProfile->fresh()->is_available);
        $this->assertSame($new->id, MotherBagService::dispatchVehicles($this->source, $this->destination)[0]['id']);
    }

    public function test_off_duty_driver_does_not_appear_in_either_dispatch_picker(): void
    {
        $this->driver->courierProfile->update(['is_available' => false]);
        $truck = $this->createTruck();
        $this->assertFalse($this->driver->courierProfile->fresh()->is_available);
        $this->assertSame([], MotherBagService::dispatchVehicles($this->source, $this->destination));
        $this->get(route('hub.manifests.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('vehicles', 0));
    }

    public function test_driver_with_prepared_transport_cannot_be_moved_to_another_truck(): void
    {
        $truck = $this->createTruck();
        LogisticsManifest::create(['logistics_company_id' => $truck->logistics_company_id, 'source_hub_id' => $this->source->id,
            'destination_hub_id' => $this->destination->id, 'vehicle_id' => $truck->id, 'driver_id' => $this->driver->id,
            'created_by_id' => $this->operator->id, 'creation_token' => (string) Str::uuid(), 'creation_fingerprint' => str_repeat('a', 64),
            'type' => 'line_haul', 'status' => 'draft']);
        $this->post(route('hub.fleet.store'), ['hub_id' => $this->source->id, 'plate_number' => 'BUSY-REPLACEMENT',
            'vehicle_type' => 'wing_truck', 'capacity_kg' => 8000, 'driver_id' => $this->driver->id])->assertSessionHasErrors('driver_id');
        $this->assertDatabaseMissing('logistics_fleet', ['plate_number' => 'BUSY-REPLACEMENT']);
        $this->assertSame($this->driver->id, $truck->fresh()->assigned_driver_id);
        $this->assertSame($truck->id, $this->driver->courierProfile->fresh()->vehicle_id);
    }

    public function test_cannot_borrow_driver_from_another_hub_and_failed_creation_rolls_back(): void
    {
        $this->driver->courierProfile->update(['assigned_hub_id' => $this->destination->id]);
        $this->actingAs($this->operator)->post(route('hub.fleet.store'), ['hub_id' => $this->source->id,
            'plate_number' => 'WRONG-HUB', 'vehicle_type' => 'wing_truck', 'capacity_kg' => 8000, 'driver_id' => $this->driver->id])->assertSessionHasErrors('driver_id');
        $this->assertDatabaseMissing('logistics_fleet', ['plate_number' => 'WRONG-HUB']);
    }
}
