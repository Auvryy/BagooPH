<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use App\Services\Logistics\HubFleetService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\MotherBagService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CompanyTransportDriverTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function truck(LogisticsHub $hub, string $plate, string $name = 'Juan Transport'): LogisticsFleet
    {
        $operator = $this->flowHandler($hub);
        $before = [User::count(), CourierProfile::count()];
        $this->actingAs($operator)->post(route('hub.fleet.store'), ['hub_id' => $hub->id, 'plate_number' => $plate,
            'vehicle_type' => $hub->isMotherHub() ? 'wing_truck' : 'l300_van', 'capacity_kg' => $hub->isMotherHub() ? 8000 : 1200,
            'transport_driver_name' => $name])->assertSessionHasNoErrors();
        $this->assertSame($before, [User::count(), CourierProfile::count()]);
        $vehicle = LogisticsFleet::where('plate_number', $plate)->sole();
        $this->assertNull($vehicle->assigned_driver_id);
        $this->assertNotNull($vehicle->transport_driver_id);
        $this->assertSame($name, $vehicle->driverName());

        return $vehicle;
    }

    public function test_name_only_driver_creates_a_usable_manifest_and_bag_dispatch_with_no_account(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $hub = $parcel->originBayanHub;
        $destination = $parcel->originMotherHub;
        $truck = $this->truck($hub, 'NAMED-VAN');
        $operator = $this->flowHandler($hub);
        $this->actingAs($operator)->get(route('hub.manifests.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('vehicles', fn ($rows) => collect($rows)->contains('id', $truck->id)));
        $this->assertTrue(collect(MotherBagService::dispatchVehicles($hub, $destination))->contains('id', $truck->id));
        $bags = app(MotherBagService::class);
        $bag = $bags->create($operator, ['source_hub_id' => $hub->id, 'destination_hub_id' => $destination->id,
            'creation_token' => (string) Str::uuid(), 'capacity_preset' => 'compact']);
        $bag = $bags->command($bag, $operator, 'add', ['version' => $bag->version, 'barcode' => $parcel->tracking_number,
            'handling' => ['weight_kg' => 1, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true]]);
        $this->postJson('/hub/mother-bags/'.$bag->id.'/dispatch', ['vehicle_id' => $truck->id, 'seal_number' => 'SL-NAMED-001',
            'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('manifest.status', 'dispatched');
        $manifest = LogisticsManifest::where('vehicle_id', $truck->id)->sole();
        $this->assertNull($manifest->driver_id);
        $this->assertSame($truck->transport_driver_id, $manifest->transport_driver_id);
        $this->assertSame('Juan Transport', $manifest->driverName());
        try {
            DB::table('logistics_manifests')->where('id', $manifest->id)->update(['transport_driver_name' => 'Rewritten Driver']);
            $this->fail('Original transport identity was rewritten.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('identity', $exception->getMessage());
        }
        $this->assertSame('Juan Transport', $manifest->fresh()->transport_driver_name);

        $this->assertSame('in_transit_to_mother_hub', $parcel->fresh()->status);
        $this->assertFalse(collect(MotherBagService::dispatchVehicles($hub, $destination))->contains('id', $truck->id));
        $this->get('/hub/manifests/'.$manifest->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('selectedManifest.driver_name', 'Juan Transport'));
        $destinationOperator = $this->flowHandler($destination);
        $this->assertTrue($manifest->events()->where('event_type', 'parcel_dispatched')->exists());
        $bags->command($bag->fresh(), $destinationOperator, 'open', ['version' => $bag->fresh()->version, 'seal_number' => 'SL-NAMED-001', 'barcode' => $bag->reference, 'seal_intact' => true]);
        $this->confirmFlowScan($parcel->fresh(), $destination, 'RECEIVE_AT_MOTHER_HUB');
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
    }

    public function test_multiple_named_drivers_can_use_the_same_mother_hub_route(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $source = $parcel->originMotherHub;
        $other = LogisticsHub::create(['logistics_company_id' => $source->logistics_company_id, 'name' => 'Second Mother Hub',
            'code' => 'NAMED-MH-TWO', 'tier' => 'regional_mother_hub', 'is_active' => true, 'province' => 'Laguna', 'city_municipality' => 'Pila', 'address' => '100 Hub Street']);
        $one = $this->truck($source, 'NAMED-WING-A', 'Juan Transport');
        $two = $this->truck($source, 'NAMED-WING-B', 'Maria Transport');
        $operator = $this->flowHandler($source);
        foreach ([$one, $two] as $truck) {
            $this->assertTrue(collect(MotherBagService::dispatchVehicles($source, $other))->contains('id', $truck->id));
            $token = (string) Str::uuid();
            $manifest = app(LogisticsManifestService::class)->create($operator, ['source_hub_id' => $source->id,
                'destination_hub_id' => $other->id, 'vehicle_id' => $truck->id, 'creation_token' => $token], $token);
            $this->assertSame($truck->transport_driver_id, $manifest->transport_driver_id);
            $this->assertNull($manifest->driver_id);
        }
        $this->assertNotSame($one->transport_driver_id, $two->transport_driver_id);
        $this->assertSame(2, LogisticsManifest::where('source_hub_id', $source->id)->count());
        $this->assertFalse(collect(MotherBagService::dispatchVehicles($other, $source))->contains('id', $one->id));
    }

    public function test_legacy_seed_style_assignment_can_be_replaced_by_a_named_transport_person(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $hub = $parcel->originBayanHub;
        $operator = $this->flowHandler($hub);
        $rider = $this->createApprovedUser('courier');
        $rider->courierProfile->update(['logistics_company_id' => $hub->logistics_company_id, 'assigned_hub_id' => $hub->id,
            'vehicle_id' => null, 'is_available' => true]);
        $vehicle = app(HubFleetService::class)->register($operator, $hub, ['plate_number' => 'LEGACY-TO-NAMED',
            'vehicle_type' => 'l300_van', 'capacity_kg' => 1200, 'driver_id' => $rider->id]);
        $before = User::count();
        $this->actingAs($operator)->put(route('hub.fleet.update', $vehicle), [...$vehicle->only(['plate_number', 'vehicle_type', 'capacity_kg', 'model']),
            'hub_id' => $hub->id, 'expected_state' => HubFleetService::stateToken($vehicle), 'transport_driver_name' => 'Ana Transport'])->assertSessionHasNoErrors();
        $this->assertNull($vehicle->fresh()->assigned_driver_id);
        $this->assertSame('Ana Transport', $vehicle->fresh()->driverName());
        $this->assertSame($before, User::count());
        $this->assertNull($rider->courierProfile->fresh()->vehicle_id);
        $this->assertFalse($rider->courierProfile->fresh()->is_available);
        $this->assertSame('courier', $rider->fresh()->role);
    }

    public function test_existing_unassigned_truck_can_receive_a_name_without_email_and_driver_is_scoped(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'arrived_at_origin_hub');
        $hub = $parcel->originBayanHub;
        $operator = $this->flowHandler($hub);
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
            'plate_number' => 'EXISTING-NAMED', 'vehicle_type' => 'l300_van', 'capacity_kg' => 1200, 'status' => 'active']);
        $this->actingAs($operator)->put(route('hub.fleet.update', $vehicle), [...$vehicle->only(['plate_number', 'vehicle_type', 'capacity_kg', 'model']),
            'hub_id' => $hub->id, 'expected_state' => HubFleetService::stateToken($vehicle), 'transport_driver_name' => 'Pedro Transport'])->assertSessionHasNoErrors();
        $this->assertSame('Pedro Transport', $vehicle->fresh()->driverName());
        $before = LogisticsFleet::count();
        $this->post(route('hub.fleet.store'), ['hub_id' => $hub->id, 'plate_number' => 'DUPLICATE-PERSON', 'vehicle_type' => 'l300_van',
            'capacity_kg' => 1200, 'transport_driver_id' => $vehicle->fresh()->transport_driver_id])->assertSessionHasErrors('transport_driver_id');
        $this->assertSame($before, LogisticsFleet::count());
    }
}
