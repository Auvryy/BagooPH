<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Logistics\FleetDispatchEngine;
use App\Services\Logistics\MotherBagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $this->assertSame('BIN-'.$setup['originMother']->code, $dws['chute']);
        $this->assertSame($setup['originMother']->name, $dws['next_facility_name']);
    }

    public function test_automated_mother_bag_slotting(): void
    {
        $setup = $this->createLogisticsSetup();
        $engine = app(FleetDispatchEngine::class);

        $bag = $this->pack($setup);
        $result = $engine->previewMotherBagSlotting($setup['delivery']->fresh(), $setup['originBayan'], $setup['originMother']);

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
        $bag = $this->pack($setup);
        app(MotherBagService::class)->command($bag, $setup['handler'], 'seal', ['version' => $bag->version, 'seal_number' => 'QA-FLEET-SEAL']);

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

    public function test_obsolete_fast_dispatch_cannot_generate_a_gate_pass_or_custody(): void
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

        $dispatchRes->assertConflict()->assertJsonPath('manifest_url', '/hub/manifests?hub_id='.$setup['originBayan']->id);
        $this->assertSame('arrived_at_origin_hub', $setup['delivery']->fresh()->status);
        $this->assertDatabaseCount('logistics_manifests', 0);
        $this->assertDatabaseCount('mother_bag_seals', 0);
    }

    private function pack(array $setup): MotherBag
    {
        return app(MotherBagService::class)->pack($setup['handler'], [
            'source_hub_id' => $setup['originBayan']->id, 'barcode' => $setup['delivery']->tracking_number,
            'request_token' => (string) Str::uuid(), 'handling' => ['weight_kg' => 1.25, 'length_cm' => 20,
                'width_cm' => 20, 'height_cm' => 20, 'confirmed' => true],
        ]);
    }
}
