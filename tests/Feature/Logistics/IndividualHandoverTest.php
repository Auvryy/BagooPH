<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Logistics\ParcelScanEvidenceService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class IndividualHandoverTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_origin_receipt_cannot_replace_contradictory_retained_custody(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        DeliveryCheckpoint::record($parcel, 'custody_conflict', evidence: ['custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id]]);
        $before = [$parcel->fresh()->getAttributes(), $parcel->order->fresh()->getAttributes(), $parcel->checkpoints()->count()];
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame($before, [$parcel->fresh()->getAttributes(), $parcel->order->fresh()->getAttributes(), $parcel->checkpoints()->count()]);
    }

    public function test_origin_intake_cannot_advance_an_inconsistent_commercial_order(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $parcel->order->update(['status' => 'preparing']);
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame('preparing', $parcel->order->fresh()->status);
    }

    public function test_pickup_cannot_overwrite_a_retained_hub_holder(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        DeliveryCheckpoint::record($parcel, 'custody_conflict', evidence: ['custody_after' => ['kind' => 'hub', 'hub_id' => $parcel->origin_bayan_hub_id]]);
        $before = $parcel->checkpoints()->count();
        try {
            app(OrderStateMachineService::class)->transition($parcel, 'picked_up', $parcel->courier, ['barcode' => $parcel->tracking_number]);
            $this->fail('A stale assignment cannot replace retained custody.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('custody', $error->getMessage());
        }
        $this->assertSame('assigned_pickup', $parcel->fresh()->status);
        $this->assertSame($before, $parcel->checkpoints()->count());
    }

    public function test_failed_origin_evidence_write_rolls_back_custody_order_and_earnings(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $before = [$parcel->fresh()->getAttributes(), $parcel->order->fresh()->getAttributes(), $parcel->checkpoints()->count()];
        $actor = $this->flowHandler($hub);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($record) {
            if ($record->checkpoint_type === 'arrived_at_origin_hub') {
                throw new \RuntimeException('Evidence storage unavailable.');
            }
        });
        try {
            app(OrderStateMachineService::class)->transition($parcel, 'arrived_at_origin_hub', $actor, ['hub_id' => $hub->id,
                'barcode' => $parcel->tracking_number, 'expected_status' => 'picked_up']);
            $this->fail('A receipt must not survive without its evidence.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Evidence storage unavailable.', $error->getMessage());
        }
        $this->assertSame($before, [$parcel->fresh()->getAttributes(), $parcel->order->fresh()->getAttributes(), $parcel->checkpoints()->count()]);
        $this->assertDatabaseCount('logistics_earnings', 0);
    }

    public function test_one_source_operator_can_receive_pack_seal_and_dispatch_with_a_separate_driver(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = $parcel->originBayanHub;
        $operator = $this->flowHandler($hub);
        $driver = $this->createApprovedUser('courier');
        $vehicle = LogisticsFleet::create(['logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id,
            'plate_number' => 'TEST-OP-01', 'vehicle_type' => 'l300_van', 'model' => 'Test closed van', 'capacity_kg' => 10000,
            'assigned_driver_id' => $driver->id, 'status' => 'active']);
        $driver->courierProfile->update(['logistics_company_id' => $hub->logistics_company_id,
            'assigned_hub_id' => $hub->id, 'vehicle_id' => $vehicle->id, 'is_available' => true]);
        $this->actingAs($operator)->get('/hub/manifests')->assertOk();
        $response = $this->postJson('/hub/manifests', ['source_hub_id' => $hub->id, 'destination_hub_id' => $parcel->origin_mother_hub_id,
            'direction' => 'outbound', 'vehicle_id' => $vehicle->id, 'creation_token' => session()->get('manifest_creation_token')])->assertCreated();
        $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
        $bag = $this->packFlowMotherBag($parcel, $manifest, $operator);
        $this->postJson('/hub/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
        $this->postJson('/hub/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        $this->assertSame($operator->id, $manifest->fresh()->dispatcher_id);
        $this->assertSame($driver->id, DeliveryCheckpoint::lastCustody($parcel)['driver_id']);
        $this->assertNotSame($operator->id, $driver->id);
        $this->assertSame($operator->id, $bag->loaded_by_id);
        $this->assertSame($operator->id, $parcel->checkpoints()->where('checkpoint_type', 'arrived_at_origin_hub')->sole()->scanned_by_id);
    }

    public function test_receiving_another_operators_receipt_is_a_conflict_and_original_feedback_is_stable(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = $parcel->originBayanHub;
        $operator = $this->flowHandler($hub);
        $input = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'];
        $original = $this->actingAs($operator)->postJson('/hub/scan', $input)->assertOk()->json('scan_evidence');
        $this->postJson('/hub/scan', $input)->assertOk()->assertJsonPath('scan_evidence', $original);
        $other = $this->createApprovedUser('logistics');
        HubHandler::create(['user_id' => $other->id, 'hub_id' => $hub->id,
            'logistics_company_id' => $hub->logistics_company_id, 'is_active' => true]);
        $this->actingAs($other)->postJson('/hub/scan', $input)->assertConflict();
        $this->assertSame($original, app(ParcelScanEvidenceService::class)->summary($parcel));
    }

    public function test_ready_fields_cannot_offer_a_new_pickup_claim_over_recorded_hub_custody(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $parcel = $order->delivery;
        DeliveryCheckpoint::record($parcel, 'custody_conflict', evidence: ['custody_after' => ['kind' => 'hub', 'hub_id' => $parcel->origin_bayan_hub_id]]);
        $rider = $this->flowRider($parcel->originBayanHub);
        try {
            app(CourierOperationsService::class)->claimPickup($rider, $parcel);
            $this->fail('An unassigned field cannot replace actual hub custody.');
        } catch (DomainException $error) {
            $this->assertStringContainsString('custody', $error->getMessage());
        }
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertSame('unassigned', $parcel->fresh()->status);
    }
}
