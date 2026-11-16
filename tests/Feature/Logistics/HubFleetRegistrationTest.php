<?php

namespace Tests\Feature\Logistics;

use App\Models\HubHandler;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class HubFleetRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoutingCheckoutSeeder::class);
    }

    private function input(LogisticsHub $hub, array $overrides = []): array
    {
        return array_replace(['hub_id' => $hub->id, 'plate_number' => 'NEW-123', 'vehicle_type' => 'wing_truck',
            'model' => 'Closed truck', 'capacity_kg' => 8000], $overrides);
    }

    public function test_operator_at_every_hub_can_register_all_existing_vehicle_types(): void
    {
        foreach (LogisticsHub::eligible()->get() as $hub) {
            $operator = User::factory()->create(['role' => 'logistics']);
            HubHandler::create(['user_id' => $operator->id, 'hub_id' => $hub->id,
                'logistics_company_id' => $hub->logistics_company_id, 'role_title' => 'Operator', 'job_role' => 'all', 'is_active' => true]);
            foreach (['wing_truck' => 8000, 'l300_van' => 1200, 'motorcycle' => 40, 'tricycle' => 100] as $type => $capacity) {
                $plate = 'NEW-'.$hub->id.'-'.strtoupper(str_replace('_', '-', $type));
                $this->actingAs($operator)->post(route('hub.fleet.store'), $this->input($hub, [
                    'plate_number' => strtolower($plate), 'vehicle_type' => $type, 'capacity_kg' => $capacity,
                    'assigned_driver_id' => 999, 'logistics_company_id' => 999, 'status' => 'restricted',
                ]))->assertSessionHasNoErrors()->assertRedirect(route('hub.fleet', ['hub_id' => $hub->id]));
                $this->assertDatabaseHas('logistics_fleet', ['plate_number' => $plate, 'hub_id' => $hub->id,
                    'logistics_company_id' => $hub->logistics_company_id, 'vehicle_type' => $type,
                    'capacity_kg' => $capacity, 'assigned_driver_id' => null, 'status' => 'active']);
            }
            $this->flushSession();
        }
    }

    public function test_owner_sees_defaults_and_saves_a_vehicle(): void
    {
        $hub = LogisticsHub::eligible()->firstOrFail();
        $this->actingAs(User::findOrFail($hub->company->user_id));
        $this->get(route('hub.fleet', ['hub_id' => $hub->id]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Hub/Fleet')->where('canRegisterVehicle', true)->where('vehicleDefaults.wing_truck', 8000)->where('vehicleDefaults.l300_van', 1200));
        $this->post(route('hub.fleet.store'), $this->input($hub))->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_duplicate_plates_and_invalid_values_do_not_create_vehicles(): void
    {
        $hub = LogisticsHub::eligible()->firstOrFail();
        $this->actingAs(User::findOrFail($hub->company->user_id));
        $this->post(route('hub.fleet.store'), $this->input($hub))->assertSessionHasNoErrors();
        $count = LogisticsFleet::count();
        $this->post(route('hub.fleet.store'), $this->input($hub, ['plate_number' => ' new-123 ']))->assertSessionHasErrors('plate_number');
        foreach (['capacity_kg' => 0, 'vehicle_type' => 'airplane', 'plate_number' => '<script>'] as $field => $value) {
            $this->post(route('hub.fleet.store'), $this->input($hub, [$field => $value]))->assertSessionHasErrors($field);
        }
        $this->assertSame($count, LogisticsFleet::count());
    }

    public function test_operator_cannot_register_at_another_hub_and_restricted_workers_cannot_register(): void
    {
        $hubs = LogisticsHub::eligible()->take(2)->get();
        $operator = User::factory()->create(['role' => 'logistics']);
        $assignment = HubHandler::create(['user_id' => $operator->id, 'hub_id' => $hubs[0]->id,
            'logistics_company_id' => $hubs[0]->logistics_company_id, 'role_title' => 'Operator', 'job_role' => 'all', 'is_active' => true]);
        $this->actingAs($operator)->post(route('hub.fleet.store'), $this->input($hubs[1]))->assertForbidden();
        $assignment->update(['job_role' => HubHandler::JOB_ROLE_INTAKE]);
        $this->post(route('hub.fleet.store'), $this->input($hubs[0]))->assertForbidden();
        $assignment->update(['is_active' => false]);
        $this->post(route('hub.fleet.store'), $this->input($hubs[0]))->assertForbidden();
        $this->actingAs(User::where('role', 'buyer')->firstOrFail())->post(route('hub.fleet.store'), $this->input($hubs[0]))->assertForbidden();
    }
}
