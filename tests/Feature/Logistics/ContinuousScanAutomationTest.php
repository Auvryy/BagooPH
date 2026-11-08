<?php

namespace Tests\Feature\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class ContinuousScanAutomationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_continuous_scan_at_mother_hub_auto_receives_manifest_and_sorts_to_line_haul(): void
    {
        $order = $this->newFlowOrder();
        $originHub = $order->delivery->originBayanHub;
        $motherHub = $order->delivery->originMotherHub;
        $delivery = $this->flowDelivery($order, OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB);
        $originHandler = $this->flowHandler($originHub);
        $motherHandler = $this->flowHandler($motherHub);

        // Active driver & vehicle for dispatch
        $driver = $this->createApprovedUser('logistics_handler');
        LogisticsFleet::create([
            'logistics_company_id' => $originHub->logistics_company_id,
            'hub_id' => $originHub->id,
            'plate_number' => 'BGO-MH-01',
            'vehicle_type' => 'l300_van',
            'model' => 'Feeder Van',
            'capacity_kg' => 1200,
            'assigned_driver_id' => $driver->id,
            'status' => 'active',
        ]);

        // Dispatch feeder manifest to Mother Hub
        $dispatchRes = $this->actingAs($originHandler)->postJson('/hub/scan/fast-dispatch', [
            'destination_hub_id' => $motherHub->id,
            'delivery_ids' => [$delivery->id],
        ]);
        $dispatchRes->assertOk();

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB, $delivery->status);

        // Continuous scan at Mother Hub
        $scanResponse = $this->actingAs($motherHandler)->postJson('/hub/scan', [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $motherHub->id,
            'mode' => 'inspect',
            'continuous' => true,
            'auto_advance' => true,
        ]);

        $scanResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('automated_progression', true);

        $steps = $scanResponse->json('steps');
        $this->assertContains('manifest_received', $steps);
        $this->assertContains('manifest_closed', $steps);
        $this->assertContains('sorted_to_line_haul', $steps);

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL, $delivery->status);
        $this->assertSame($motherHub->id, $delivery->current_hub_id);
    }

    public function test_continuous_scan_at_destination_bayan_hub_auto_bins_and_assigns_rider(): void
    {
        $order = $this->newFlowOrder();
        $destHub = $order->delivery->destinationBayanHub;
        $destHandler = $this->flowHandler($destHub);

        // Create on-duty available rider at Destination Bayan Hub
        $rider = $this->flowRider($destHub);

        // Move delivery to in_transit_to_destination_hub on real dispatched transfer manifest
        $delivery = $this->flowDelivery($order, OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB);

        // Continuous scan at Destination Bayan Hub
        $scanResponse = $this->actingAs($destHandler)->postJson('/hub/scan', [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $destHub->id,
            'mode' => 'inspect',
            'continuous' => true,
            'auto_advance' => true,
        ]);

        $scanResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('automated_progression', true);

        $steps = $scanResponse->json('steps');
        $this->assertContains('manifest_received', $steps);
        $this->assertContains('manifest_closed', $steps);
        $this->assertContains('sorted_to_barangay_bin', $steps);
        $this->assertContains('rider_assigned', $steps);

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $delivery->status);
        $this->assertSame($destHub->id, $delivery->current_hub_id);
        $this->assertSame($rider->id, $delivery->assigned_rider_id);
        $this->assertNotNull($delivery->destination_bin);
    }

    public function test_manual_mode_auto_progress_action_endpoint(): void
    {
        $order = $this->newFlowOrder();
        $destHub = $order->delivery->destinationBayanHub;
        $destHandler = $this->flowHandler($destHub);
        $rider = $this->flowRider($destHub);

        $delivery = $order->delivery;
        $delivery->update([
            'status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'current_hub_id' => $destHub->id,
            'destination_barangay' => 'Poblacion III',
        ]);

        // In manual mode, clicking 1-Click Auto-Progress sends action: AUTO_PROGRESS
        $scanResponse = $this->actingAs($destHandler)->postJson('/hub/scan', [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $destHub->id,
            'mode' => 'confirm',
            'action' => 'AUTO_PROGRESS',
        ]);

        $scanResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('automated_progression', true);

        $delivery->refresh();
        $this->assertSame(OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER, $delivery->status);
        $this->assertSame($rider->id, $delivery->assigned_rider_id);
    }

    public function test_continuous_scan_rescan_reports_informative_message(): void
    {
        $order = $this->newFlowOrder();
        $destHub = $order->delivery->destinationBayanHub;
        $destHandler = $this->flowHandler($destHub);
        $rider = $this->flowRider($destHub);

        $delivery = $order->delivery;
        $delivery->update([
            'status' => OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
            'current_hub_id' => $destHub->id,
            'assigned_rider_id' => $rider->id,
            'destination_bin' => 'BIN: BRGY-TEST',
        ]);

        $scanResponse = $this->actingAs($destHandler)->postJson('/hub/scan', [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $destHub->id,
            'mode' => 'inspect',
            'continuous' => true,
        ]);

        $scanResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('confirmed', true);

        $this->assertStringContainsString('Already assigned to Rider', $scanResponse->json('message'));
    }
}
