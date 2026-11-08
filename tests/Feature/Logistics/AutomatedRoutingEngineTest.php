<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class AutomatedRoutingEngineTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function createFourHubNetwork(): array
    {
        $order = $this->newFlowOrder();
        $originBayan = $order->delivery->originBayanHub;
        $originMother = $order->delivery->originMotherHub;
        $companyId = $originBayan->logistics_company_id;
        $company = $originBayan->company;

        $destMother = LogisticsHub::create([
            'logistics_company_id' => $companyId,
            'tier' => 'regional_mother_hub',
            'code' => 'MTR-DEST-01',
            'name' => 'Destination Regional Mother Hub',
            'province' => 'Metro Manila',
            'city_municipality' => 'Quezon City',
            'address' => '100 North Line Haul Expressway',
            'is_active' => true,
        ]);

        $destBayan = LogisticsHub::create([
            'logistics_company_id' => $companyId,
            'tier' => 'local_bayan_hub',
            'code' => 'BAYAN-DEST-01',
            'name' => 'Destination Bayan Station',
            'province' => 'Metro Manila',
            'city_municipality' => 'Quezon City',
            'address' => '200 Diliman Delivery Center',
            'is_active' => true,
        ]);

        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $delivery->update([
            'origin_bayan_hub_id' => $originBayan->id,
            'origin_mother_hub_id' => $originMother->id,
            'destination_mother_hub_id' => $destMother->id,
            'destination_bayan_hub_id' => $destBayan->id,
            'route_hub_ids' => [$originBayan->id, $originMother->id, $destMother->id, $destBayan->id],
            'destination_bin' => 'LOC-BIN-04',
        ]);
        $delivery->refresh();

        return [
            'order' => $order,
            'company' => $company,
            'delivery' => $delivery,
            'originBayan' => $originBayan,
            'originMother' => $originMother,
            'destMother' => $destMother,
            'destBayan' => $destBayan,
            'originHandler' => $this->flowHandler($originBayan),
            'originMotherHandler' => $this->flowHandler($originMother),
            'destMotherHandler' => $this->flowHandler($destMother),
            'destBayanHandler' => $this->flowHandler($destBayan),
        ];
    }

    public function test_automated_next_hop_computation_across_all_chain_facilities(): void
    {
        $net = $this->createFourHubNetwork();
        $delivery = $net['delivery'];
        $routingEngine = app(LogisticsRoutingEngine::class);

        // 1. At Origin Bayan Hub: Next hop is Origin Mother Hub
        $hop1 = $routingEngine->computeNextHop($delivery, $net['originBayan']);
        $this->assertNotNull($hop1);
        $this->assertEquals($net['originMother']->id, $hop1['hub_id']);
        $this->assertEquals($net['originMother']->name, $hop1['hub_name']);
        $this->assertEquals($net['originMother']->code, $hop1['hub_code']);
        $this->assertEquals('regional_mother_hub', $hop1['tier']);
        $this->assertEquals('BIN-'.$net['originMother']->code, $hop1['recommended_bin']);

        // Verify scan response at Origin Bayan Hub
        $scanRes1 = $this->actingAs($net['originHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['originBayan']->id,
                'mode' => 'inspect',
            ]);
        $scanRes1->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('next_hop.hub_id', $net['originMother']->id)
            ->assertJsonPath('next_hop.hub_code', $net['originMother']->code)
            ->assertJsonPath('next_hop.recommended_bin', 'BIN-'.$net['originMother']->code);

        // 2. At Origin Mother Hub (when dest mother is different): Next hop is Destination Mother Hub
        $hop2 = $routingEngine->computeNextHop($delivery, $net['originMother']);
        $this->assertNotNull($hop2);
        $this->assertEquals($net['destMother']->id, $hop2['hub_id']);
        $this->assertEquals($net['destMother']->name, $hop2['hub_name']);
        $this->assertEquals($net['destMother']->code, $hop2['hub_code']);
        $this->assertEquals('regional_mother_hub', $hop2['tier']);
        $this->assertEquals('BIN-'.$net['destMother']->code, $hop2['recommended_bin']);

        // 3. At Destination Mother Hub: Next hop is Destination Bayan Hub
        $hop3 = $routingEngine->computeNextHop($delivery, $net['destMother']);
        $this->assertNotNull($hop3);
        $this->assertEquals($net['destBayan']->id, $hop3['hub_id']);
        $this->assertEquals($net['destBayan']->name, $hop3['hub_name']);
        $this->assertEquals($net['destBayan']->code, $hop3['hub_code']);
        $this->assertEquals('local_bayan_hub', $hop3['tier']);
        $this->assertEquals('BIN-'.$net['destBayan']->code, $hop3['recommended_bin']);

        // 4. At Destination Bayan Hub: Arrived at delivery station! Destination bin is the local sorting shelf
        $hop4 = $routingEngine->computeNextHop($delivery, $net['destBayan']);
        $this->assertNotNull($hop4);
        $this->assertEquals($net['destBayan']->id, $hop4['hub_id']);
        $this->assertEquals($net['destBayan']->name, $hop4['hub_name']);
        $this->assertEquals($net['destBayan']->code, $hop4['hub_code']);
        $this->assertEquals('LOC-BIN-04', $hop4['recommended_bin']);

        // Single Mother Hub Route: Origin Mother Hub == Destination Mother Hub
        $singleDelivery = Delivery::find($delivery->id);
        $singleDelivery->update([
            'destination_mother_hub_id' => $net['originMother']->id,
            'route_hub_ids' => [$net['originBayan']->id, $net['originMother']->id, $net['destBayan']->id],
        ]);
        $singleDelivery->refresh();

        $singleHop = $routingEngine->computeNextHop($singleDelivery, $net['originMother']);
        $this->assertNotNull($singleHop);
        $this->assertEquals($net['destBayan']->id, $singleHop['hub_id']);
        $this->assertEquals('BIN-'.$net['destBayan']->code, $singleHop['recommended_bin']);
    }

    public function test_anti_backhaul_guard_rejects_backward_dispatch_and_routing(): void
    {
        $net = $this->createFourHubNetwork();
        $delivery = $net['delivery'];

        // Position fleet vehicle at Destination Mother Hub
        $driver = $this->createApprovedUser('logistics_handler');
        LogisticsFleet::create([
            'logistics_company_id' => $net['company']->id,
            'hub_id' => $net['destMother']->id,
            'plate_number' => 'BGO-TRK-01',
            'vehicle_type' => '10_wheeler_wing_van',
            'model' => 'Isuzu Giga Line Haul',
            'capacity_kg' => 12000,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);

        // Place parcel at Destination Mother Hub
        $delivery->update([
            'current_hub_id' => $net['destMother']->id,
            'status' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
        ]);
        $delivery->refresh();

        // 1. Attempt backward fast dispatch from Destination Mother Hub to Origin Bayan Hub
        $backhaulRes1 = $this->actingAs($net['destMotherHandler'])
            ->postJson('/hub/scan/fast-dispatch', [
                'destination_hub_id' => $net['originBayan']->id,
                'delivery_ids' => [$delivery->id],
            ]);

        $backhaulRes1->assertStatus(422)
            ->assertJsonPath('sound', 'buzz')
            ->assertJsonPath(
                'error',
                "Routing Violation: Parcel tracking {$delivery->tracking_number} cannot be routed backward to {$net['originBayan']->name}. Next authorized facility is {$net['destBayan']->name}."
            );

        // 2. Attempt backward fast dispatch from Destination Mother Hub to Origin Mother Hub
        $backhaulRes2 = $this->actingAs($net['destMotherHandler'])
            ->postJson('/hub/scan/fast-dispatch', [
                'destination_hub_id' => $net['originMother']->id,
                'delivery_ids' => [$delivery->id],
            ]);

        $backhaulRes2->assertStatus(422)
            ->assertJsonPath('sound', 'buzz')
            ->assertJsonPath(
                'error',
                "Routing Violation: Parcel tracking {$delivery->tracking_number} cannot be routed backward to {$net['originMother']->name}. Next authorized facility is {$net['destBayan']->name}."
            );

        // 3. Attempt backward intake scan: Operator at Origin Mother Hub scans parcel that is already at Dest Mother Hub
        $scanBackwardRes = $this->actingAs($net['originMotherHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['originMother']->id,
                'mode' => 'inspect',
            ]);

        $scanBackwardRes->assertStatus(422)
            ->assertJsonPath('sound', 'buzz')
            ->assertJsonPath(
                'error',
                "Routing Violation: Parcel tracking {$delivery->tracking_number} cannot be routed backward to {$net['originMother']->name}. Next authorized facility is {$net['destBayan']->name}."
            );
    }

    public function test_two_way_poka_yoke_location_scan_accepts_correct_bin_and_rejects_incorrect_bin(): void
    {
        $net = $this->createFourHubNetwork();
        $delivery = $net['delivery'];

        // At Origin Bayan Hub: next hop is Origin Mother Hub -> expected bin is BIN-{originMother->code}
        $expectedNextBin = 'BIN-'.$net['originMother']->code;

        // 1. Correct location scan accepted
        $correctScan = $this->actingAs($net['originHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['originBayan']->id,
                'location_barcode' => $expectedNextBin,
                'mode' => 'inspect',
            ]);

        $correctScan->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('location_verified', true)
            ->assertJsonPath('scanned_location', $expectedNextBin);

        // 2. Incorrect location scan rejected with buzzer and exact mis-sort error message
        $incorrectScan = $this->actingAs($net['originHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['originBayan']->id,
                'location_barcode' => 'LOC-WRONG-BIN',
                'mode' => 'inspect',
            ]);

        $incorrectScan->assertStatus(422)
            ->assertJsonPath('sound', 'buzz')
            ->assertJsonPath(
                'error',
                "Mis-sort Error: Expected slotting into {$expectedNextBin}, but scanned LOC-WRONG-BIN. Please place parcel in the correct bin."
            );

        // 3. At Destination Bayan Hub: expected bin is assigned destination_bin (LOC-BIN-04)
        $delivery->update([
            'current_hub_id' => $net['destBayan']->id,
            'status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'destination_bin' => 'LOC-BIN-04',
        ]);
        $delivery->refresh();

        $destCorrect = $this->actingAs($net['destBayanHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['destBayan']->id,
                'location_barcode' => 'LOC-BIN-04',
                'mode' => 'inspect',
            ]);

        $destCorrect->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('location_verified', true);

        $destIncorrect = $this->actingAs($net['destBayanHandler'])
            ->postJson('/hub/scan', [
                'barcode' => $delivery->tracking_number,
                'hub_id' => $net['destBayan']->id,
                'location_barcode' => 'LOC-BIN-08',
                'mode' => 'inspect',
            ]);

        $destIncorrect->assertStatus(422)
            ->assertJsonPath('sound', 'buzz')
            ->assertJsonPath(
                'error',
                'Mis-sort Error: Expected slotting into LOC-BIN-04, but scanned LOC-BIN-08. Please place parcel in the correct bin.'
            );
    }

    public function test_printable_command_sheet_route(): void
    {
        $net = $this->createFourHubNetwork();

        $response = $this->actingAs($net['originHandler'])
            ->get('/hub/command-sheet');

        $response->assertOk();
        $response->assertSee('CMD-DISPATCH');
        $response->assertSee('CMD-CONFIRM');
        $response->assertSee('CMD-CLEAR');
        $response->assertSee('LOC-BIN-01');
        $response->assertSee('LOC-BIN-12');

        // JSON API format check
        $jsonResponse = $this->actingAs($net['originHandler'])
            ->getJson('/hub/command-sheet');

        $jsonResponse->assertOk()
            ->assertJsonStructure([
                'commands' => [
                    '*' => ['code', 'label', 'description'],
                ],
                'bins' => [
                    '*' => ['code', 'label', 'description'],
                ],
            ]);
    }
}
