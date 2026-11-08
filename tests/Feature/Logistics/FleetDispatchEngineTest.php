<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Logistics\FleetDispatchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class FleetDispatchEngineTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function createLogisticsSetup(): array
    {
        $order = $this->newFlowOrder();
        $originBayan = $order->delivery->originBayanHub;
        $originMother = $order->delivery->originMotherHub;
        $companyId = $originBayan->logistics_company_id;
        $company = $originBayan->company;

        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $delivery->update([
            'origin_bayan_hub_id' => $originBayan->id,
            'origin_mother_hub_id' => $originMother->id,
            'current_hub_id' => $originBayan->id,
            'route_hub_ids' => [$originBayan->id, $originMother->id],
        ]);
        $delivery->refresh();

        // Create driver and fleet vehicle for origin Bayan Hub
        $driverUser = User::create([
            'name' => 'Feeder Van Driver',
            'email' => 'driver-feeder@bagoo.test',
            'password' => 'Password1234',
            'role' => 'courier',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $fleet = LogisticsFleet::create([
            'logistics_company_id' => $companyId,
            'hub_id' => $originBayan->id,
            'plate_number' => 'BGR-VAN-01',
            'vehicle_type' => 'l300_van',
            'model' => 'Mitsubishi L300 Cargo',
            'capacity_kg' => 1000.0,
            'assigned_driver_id' => $driverUser->id,
            'status' => 'active',
        ]);

        CourierProfile::create([
            'user_id' => $driverUser->id,
            'logistics_company_id' => $companyId,
            'assigned_hub_id' => $originBayan->id,
            'vehicle_id' => $fleet->id,
            'vehicle_type' => 'l300_van',
            'plate_number' => 'BGR-VAN-01',
            'license_number' => 'N01-12-888999',
            'is_available' => true,
        ]);

        return [
            'order' => $order,
            'delivery' => $delivery,
            'originBayan' => $originBayan,
            'originMother' => $originMother,
            'handler' => $this->flowHandler($originBayan),
            'fleet' => $fleet,
            'driver' => $driverUser,
        ];
    }

    public function test_dws_profiling_and_volumetric_calculation(): void
    {
        $setup = $this->createLogisticsSetup();
        $engine = app(FleetDispatchEngine::class);

        $dws = $engine->getDwsProfile($setup['delivery']);

        $this->assertArrayHasKey('length_cm', $dws);
        $this->assertArrayHasKey('width_cm', $dws);
        $this->assertArrayHasKey('height_cm', $dws);
        $this->assertArrayHasKey('actual_weight_kg', $dws);
        $this->assertArrayHasKey('volumetric_weight_kg', $dws);
        $this->assertArrayHasKey('volume_cbm', $dws);
        $this->assertArrayHasKey('is_baggable', $dws);
        $this->assertArrayHasKey('chute', $dws);
        $this->assertTrue($dws['is_baggable']);
        $this->assertStringStartsWith('CHUTE-', $dws['chute']);
    }

    public function test_automated_mother_bag_slotting(): void
    {
        $setup = $this->createLogisticsSetup();
        $engine = app(FleetDispatchEngine::class);

        $result = $engine->slotIntoMotherBag(
            $setup['delivery'],
            $setup['originBayan'],
            $setup['originMother'],
            $setup['handler']
        );

        $this->assertTrue($result['bagged']);
        $this->assertEquals('mother_bag', $result['classification']);
        $this->assertNotNull($result['reference']);
        $this->assertEquals(1, $result['parcel_count']);
        $this->assertEquals('open', $result['status']);

        // Verify MotherBag record exists in database
        $bag = MotherBag::where('reference', $result['reference'])->first();
        $this->assertNotNull($bag);
        $this->assertEquals($setup['originBayan']->id, $bag->source_hub_id);
        $this->assertEquals($setup['originMother']->id, $bag->destination_hub_id);
        $this->assertDatabaseHas('mother_bag_parcels', [
            'mother_bag_id' => $bag->id,
            'delivery_id' => $setup['delivery']->id,
        ]);
    }

    public function test_corridor_staging_summary_and_vehicle_matching(): void
    {
        $setup = $this->createLogisticsSetup();
        $engine = app(FleetDispatchEngine::class);

        // Slot delivery into mother bag
        $engine->slotIntoMotherBag(
            $setup['delivery'],
            $setup['originBayan'],
            $setup['originMother'],
            $setup['handler']
        );

        $summaries = $engine->corridorStagingSummary($setup['originBayan']);
        $this->assertNotEmpty($summaries);

        $targetCorridor = $summaries->firstWhere('destination_hub.id', $setup['originMother']->id);
        $this->assertNotNull($targetCorridor);
        $this->assertEquals('feeder', $targetCorridor['leg_type']);
        $this->assertEquals('l300_van', $targetCorridor['sizing']['required_type']);
        $this->assertNotNull($targetCorridor['matched_vehicle']);
        $this->assertEquals('BGR-VAN-01', $targetCorridor['matched_vehicle']['plate_number']);
        $this->assertTrue($targetCorridor['can_dispatch']);
    }

    public function test_automated_corridor_dispatch_and_gate_pass_generation(): void
    {
        $setup = $this->createLogisticsSetup();

        // 1. Scan parcel at workstation
        $scanRes = $this->actingAs($setup['handler'])
            ->postJson('/hub/scan', [
                'barcode' => $setup['delivery']->tracking_number,
                'hub_id' => $setup['originBayan']->id,
                'mode' => 'inspect',
            ]);
        $scanRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'delivery' => [
                    'dws' => ['length_cm', 'width_cm', 'actual_weight_kg', 'volumetric_weight_kg', 'chute'],
                    'mother_bag_slotting',
                ],
            ]);

        // 2. Trigger Automated Corridor Dispatch
        $dispatchRes = $this->actingAs($setup['handler'])
            ->postJson('/hub/scan/fast-dispatch', [
                'destination_hub_id' => $setup['originMother']->id,
                'corridor_dispatch' => true,
            ]);

        $dispatchRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'manifest_reference',
                    'parcel_count',
                    'destination_hub',
                    'vehicle_plate',
                    'gate_pass' => [
                        'gate_pass_code',
                        'issued_at',
                        'departure_gate',
                        'manifest_reference',
                        'driver_name',
                        'vehicle_plate',
                        'total_parcels_enclosed',
                        'total_weight_kg',
                        'utilization_pct',
                        'seals',
                        'verification_hash',
                    ],
                ],
            ]);

        // Verify parcel transitioned to in-transit status
        $setup['delivery']->refresh();
        $this->assertStringContainsString('in_transit', $setup['delivery']->status);
    }
}
