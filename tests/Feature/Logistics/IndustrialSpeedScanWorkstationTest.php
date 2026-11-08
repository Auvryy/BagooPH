<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class IndustrialSpeedScanWorkstationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_master_bag_scan_inspection_and_atomic_ingestion_on_workstation(): void
    {
        $order = $this->newFlowOrder();
        $originHub = $order->delivery->originBayanHub;
        $motherHub = $order->delivery->originMotherHub;
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $originHandler = $this->flowHandler($originHub);
        $motherHandler = $this->flowHandler($motherHub);

        // Ensure fleet vehicle is active
        $driver = $this->createApprovedUser('logistics_handler');
        LogisticsFleet::create([
            'logistics_company_id' => $originHub->logistics_company_id,
            'hub_id' => $originHub->id,
            'plate_number' => 'BGO-FAST-01',
            'vehicle_type' => 'l300_van',
            'model' => 'Fast Dispatch L300',
            'capacity_kg' => 1500,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);

        // 1. Fast dispatch from origin hub to mother hub
        $dispatchRes = $this->actingAs($originHandler)
            ->postJson('/hub/scan/fast-dispatch', [
                'destination_hub_id' => $motherHub->id,
                'delivery_ids' => [$delivery->id],
            ]);
        $dispatchRes->assertOk();
        $bagReference = $dispatchRes->json('data.bag_reference');

        // 2. Scan Master Bag at Mother Hub in inspect mode
        $inspectResponse = $this->actingAs($motherHandler)
            ->postJson('/hub/scan', [
                'barcode' => $bagReference,
                'hub_id' => $motherHub->id,
                'mode' => 'inspect',
            ]);

        $inspectResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_master_bag', true)
            ->assertJsonPath('bag.reference', $bagReference)
            ->assertJsonPath('bag.parcel_count', 1)
            ->assertJsonPath('prompt.action', 'INGEST_MASTER_BAG');

        // 3. Confirm atomic ingestion of Master Bag at Mother Hub
        $confirmResponse = $this->actingAs($motherHandler)
            ->postJson('/hub/scan', [
                'barcode' => $bagReference,
                'hub_id' => $motherHub->id,
                'mode' => 'confirm',
                'action' => 'INGEST_MASTER_BAG',
                'expected_status' => 'in_transit',
            ]);

        $confirmResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('bag.status', 'received');

        // Verify parcel transitioned to arrived_at_mother_hub and custody is now Mother Hub!
        $delivery->refresh();
        $this->assertEquals(OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB, $delivery->status);
        $this->assertEquals($motherHub->id, $delivery->current_hub_id);
    }

    public function test_1_click_fast_outbound_dispatch_from_workstation(): void
    {
        $order = $this->newFlowOrder();
        $originHub = $order->delivery->originBayanHub;
        $motherHub = $order->delivery->originMotherHub;
        $delivery = $this->flowDelivery($order, 'arrived_at_origin_hub');
        $handler = $this->flowHandler($originHub);

        // Ensure fleet vehicle is active
        $driver = $this->createApprovedUser('logistics_handler');
        $vehicle = LogisticsFleet::create([
            'logistics_company_id' => $originHub->logistics_company_id,
            'hub_id' => $originHub->id,
            'plate_number' => 'BGO-FAST-01',
            'vehicle_type' => 'l300_van',
            'model' => 'Fast Dispatch L300',
            'capacity_kg' => 1500,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($handler)
            ->postJson('/hub/scan/fast-dispatch', [
                'destination_hub_id' => $motherHub->id,
                'delivery_ids' => [$delivery->id],
                'notes' => 'Automated conveyor batch transfer',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.parcel_count', 1)
            ->assertJsonPath('data.destination_hub', $motherHub->name);

        $delivery->refresh();
        $this->assertEquals(OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB, $delivery->status);
    }
}
