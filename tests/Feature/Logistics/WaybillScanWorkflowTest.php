<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class WaybillScanWorkflowTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return ['root' => ['/hub', '/hub'], 'subdomain' => ['http://hub.localhost', '']];
    }

    #[DataProvider('portals')]
    public function test_first_scanner_visit_and_old_qr_work_without_overview_and_require_real_confirmation(string $base, string $path): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->flushSession();
        $before = $this->snapshot($parcel);
        $this->actingAs($handler)->get($base.'/scan')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Hub/ScanStation')->where('activeHub.id', $hub->id)->where('basePath', $path));
        config(['app.url' => 'https://bagoo.test']);
        $this->postJson($base.'/scan', ['barcode' => 'https://bagoo.test/track/'.$parcel->tracking_number, 'mode' => 'inspect', 'hub_id' => $hub->id])
            ->assertOk()->assertJsonPath('delivery.tracking_number', $parcel->tracking_number)
            ->assertJsonPath('prompt.requires_confirmation', true)->assertJsonPath('confirmed', false);
        $this->assertSame($before, $this->snapshot($parcel));
        $confirm = ['barcode' => $parcel->tracking_number, 'mode' => 'confirm', 'hub_id' => $hub->id,
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'picked_up'];
        $this->postJson($base.'/scan', $confirm)->assertOk()->assertJsonPath('confirmed', true)
            ->assertJsonPath('delivery.status', 'arrived_at_origin_hub')->assertJsonPath('delivery.guidance.action', 'manifest')
            ->assertJsonPath('delivery.guidance.href', $path.'/manifests?hub_id='.$hub->id)
            ->assertJsonPath('prompt.manifest_url', $path.'/manifests?hub_id='.$hub->id);
        $recorded = $this->snapshot($parcel);
        $this->postJson($base.'/scan', $confirm)->assertOk()->assertJsonPath('already_received', true)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Already received'));
        $this->assertSame($recorded, $this->snapshot($parcel), 'A repeated confirmation must retain the original handover.');
        $this->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('already_received', true)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'Already received'));
        $this->assertSame($recorded, $this->snapshot($parcel));
        $this->assertSame(1, DB::table('delivery_checkpoints')->where('delivery_id', $parcel->id)
            ->where('checkpoint_type', 'arrived_at_origin_hub')->count());
    }

    public static function nextSteps(): array
    {
        return [
            'destination sorting' => ['arrived_at_destination_hub', 'sort'],
            'rider assignment' => ['sorted_to_barangay_bin', 'assign'],
            'assigned rider departure' => ['assigned_to_rider', 'wait'],
        ];
    }

    #[DataProvider('nextSteps')]
    public function test_scanned_parcels_expose_their_actual_next_step_without_rewriting_custody(string $stage, string $action): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        if ($action === 'assign') {
            $this->flowRider($hub)->courierProfile->update(['is_available' => true]);
        }
        $this->actingAs($this->flowHandler($hub));
        $before = $this->snapshot($parcel);
        $result = $this->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('delivery.guidance.action', $action)->assertJsonPath('prompt.requires_confirmation', false)
            ->assertJsonPath('delivery.registry_url', '/hub/deliveries?hub_id='.$hub->id.'&search='.$parcel->tracking_number);
        if ($action !== 'wait') {
            $result->assertJsonPath('delivery.guidance.can_act', true)
                ->assertJsonPath('delivery.guidance.href', '/hub/deliveries?hub_id='.$hub->id.'&search='.$parcel->tracking_number);
        } else {
            $result->assertJsonPath('delivery.guidance.can_act', false)->assertJsonPath('delivery.guidance.href', null);
        }
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_pending_pickup_explains_who_must_act_and_cannot_be_received_by_the_hub(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub));
        $before = $this->snapshot($parcel);
        $this->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.requires_confirmation', false)->assertJsonPath('delivery.guidance.can_act', false)
            ->assertJsonPath('delivery.guidance.next_step', 'Pickup rider must confirm collection');
        $this->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FROM_PICKUP_RIDER', 'expected_status' => 'assigned_pickup'])->assertStatus(409);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    #[DataProvider('portals')]
    public function test_inbound_transport_opens_the_actual_manifest_without_permitting_a_loose_receipt(string $base, string $path): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $hub = LogisticsHub::findOrFail($parcel->origin_mother_hub_id);
        $manifestId = $parcel->latestCustodyCheckpoint->custody_after['manifest_id'];
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($hub))->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id])
            ->assertOk()->assertJsonPath('delivery.guidance.action', 'manifest')->assertJsonPath('delivery.guidance.can_act', true)
            ->assertJsonPath('delivery.guidance.href', $path.'/manifests/'.$manifestId.'?hub_id='.$hub->id)
            ->assertJsonPath('prompt.requires_confirmation', false);
        $this->postJson($base.'/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_AT_MOTHER_HUB', 'expected_status' => 'in_transit_to_mother_hub'])->assertStatus(409);
        $this->assertSame($before, $this->snapshot($parcel));
    }

    public function test_scanned_next_steps_do_not_grant_access_at_another_hub_or_to_company_management(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'sorted_to_barangay_bin');
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $destination = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $before = $this->snapshot($parcel);
        $this->actingAs($this->flowHandler($origin))->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id])->assertStatus(409);
        $this->actingAs($destination->company->user)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $destination->id])->assertForbidden();
        $this->assertSame($before, $this->snapshot($parcel));
    }

    private function snapshot(Delivery $parcel): array
    {
        return ['parcel' => $parcel->fresh()->getRawOriginal(), 'order' => $parcel->order->fresh()->getRawOriginal(),
            'sources' => collect(['delivery_checkpoints', 'pickup_claims', 'pickup_claim_events', 'cod_custody_entries'])
                ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all()];
    }
}
