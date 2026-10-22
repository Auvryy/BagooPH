<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\HubScanTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class HubScanTransferTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return [['/hub'], ['http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_direct_receipt_cannot_bypass_manifest_transport(string $base): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $before = $parcel->checkpoints()->count();
        $this->actingAs($this->flowHandler($hub))->postJson($base.'/scan', [
            'barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB',
            'expected_status' => $parcel->status,
        ])->assertConflict();
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertSame($before, $parcel->checkpoints()->count());
        $this->assertDatabaseCount('logistics_manifests', 0);
    }

    public function test_destination_cannot_skip_the_required_mother_hub(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $parcel->checkpoints()->count();
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_BAYAN_HUB', 'expected_status' => $parcel->status])->assertConflict();
        $this->assertSame($before, $parcel->checkpoints()->count());
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_origin_receipt_is_required_before_a_mother_hub_can_receive(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => 'picked_up'])->assertConflict();
        $this->assertSame('picked_up', $parcel->fresh()->status);
    }

    public function test_an_unassigned_account_cannot_receive_at_a_hub(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $outsider = $this->createApprovedUser('logistics');
        $this->actingAs($outsider)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => $parcel->status])->assertForbidden();
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
    }

    public function test_missing_source_evidence_cannot_be_replaced_by_a_status_or_assignment(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $parcel->update(['assigned_rider_id' => $this->flowRider(LogisticsHub::findOrFail($parcel->destination_bayan_hub_id))->id]);
        $count = $parcel->checkpoints()->count();
        $this->actingAs($this->flowHandler($hub))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RECEIVE_NEXT_MOTHER_HUB', 'expected_status' => $parcel->status])->assertConflict();
        $this->assertSame($count, $parcel->checkpoints()->count());
    }

    public function test_retained_pre_manifest_receipt_evidence_still_supports_the_original_return_route(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $route = app(DeliveryReturnService::class)->forwardRoute($parcel);
        $previousHub = $parcel->origin_bayan_hub_id;
        foreach ([$parcel->origin_mother_hub_id, $parcel->destination_bayan_hub_id] as $hubId) {
            $hub = LogisticsHub::findOrFail($hubId);
            $previous = $parcel->checkpoints()->whereNotNull('custody_after')->latest('id')->firstOrFail();
            $sourceState = DeliveryCheckpoint::state($parcel->fresh());
            $parcel->update(['status' => $hub->isMotherHub() ? 'arrived_at_mother_hub' : 'arrived_at_destination_hub',
                'current_hub_id' => $hub->id]);
            // Model pre-existing immutable receipt records; the public endpoint cannot create new ones.
            $checkpoint = DeliveryCheckpoint::record($parcel, $parcel->status,
                actor: $this->flowHandler($hub), hub: $hub, barcodeScanned: $parcel->tracking_number,
                evidence: ['source_state' => $sourceState, 'target_state' => DeliveryCheckpoint::state($parcel),
                    'custody_before' => $previous->custody_after,
                    'custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id,
                        'hub_receipt' => ['from_hub_id' => $previousHub, 'source_checkpoint_id' => $previous->id,
                            'source_reference' => $previous->record_reference, 'route' => $route]]], scanProvenance: 'hub_receipt');
            $this->assertTrue(app(HubScanTransferService::class)
                ->supportsReceipt($checkpoint, $route, $previousHub, $hubId));
            $previousHub = $hubId;
        }
        $this->assertSame([$parcel->destination_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->origin_bayan_hub_id],
            app(DeliveryReturnService::class)->evidencedForwardRoute($parcel->fresh(), PHP_INT_MAX)['hub_ids']);
        $this->assertDatabaseCount('logistics_manifests', 0);
    }
}
