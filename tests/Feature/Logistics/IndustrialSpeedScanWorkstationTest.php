<?php

namespace Tests\Feature\Logistics;

use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class IndustrialSpeedScanWorkstationTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public function test_bag_identification_cannot_receive_unseen_contents_or_assert_an_intact_seal(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'in_transit_to_mother_hub');
        $bag = MotherBag::where('manifest_id', DeliveryCheckpoint::lastCustody($parcel)['manifest_id'])->sole();
        $hub = $bag->destination;
        $this->actingAs($this->flowHandler($hub));
        $before = [$bag->getAttributes(), $parcel->fresh()->getAttributes(), $parcel->checkpoints()->count(), DB::table('mother_bag_events')->where('mother_bag_id', $bag->id)->count()];
        $input = ['barcode' => $bag->reference, 'hub_id' => $hub->id];
        $this->postJson('/hub/scan', [...$input, 'mode' => 'inspect'])->assertOk()->assertJsonPath('confirmed', false)
            ->assertJsonPath('prompt.requires_confirmation', false)->assertJsonPath('bag.reference', $bag->reference);
        $this->postJson('/hub/scan', [...$input, 'mode' => 'confirm', 'action' => 'INGEST_MASTER_BAG', 'expected_status' => 'in_transit'])->assertConflict();
        $this->assertSame($before, [$bag->fresh()->getAttributes(), $parcel->fresh()->getAttributes(), $parcel->checkpoints()->count(), DB::table('mother_bag_events')->where('mother_bag_id', $bag->id)->count()]);
        $this->openFlowMotherBags(LogisticsManifest::findOrFail($bag->manifest_id), $this->flowHandler($hub));
        $this->assertSame('in_transit_to_mother_hub', $parcel->fresh()->status, 'Opening a verified seal does not receive its contents.');
        $this->confirmFlowManifest($parcel, $hub, false);
        $this->assertSame('arrived_at_mother_hub', $parcel->fresh()->status);
    }

    public function test_old_fast_dispatch_returns_a_clear_next_step_without_generated_scan_records(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'arrived_at_origin_hub');
        $this->actingAs($this->flowHandler($parcel->originBayanHub))->postJson('/hub/scan/fast-dispatch', [
            'destination_hub_id' => $parcel->origin_mother_hub_id, 'delivery_ids' => [$parcel->id]])
            ->assertConflict()->assertJsonStructure(['error', 'manifest_url']);
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertDatabaseCount('mother_bags', 0);
        $this->assertDatabaseCount('logistics_manifests', 0);
    }
}
