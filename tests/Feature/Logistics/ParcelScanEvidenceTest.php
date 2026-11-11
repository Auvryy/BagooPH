<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Services\Logistics\ParcelScanEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParcelScanEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_counters_distinguish_original_submitted_inherited_and_derived_evidence(): void
    {
        // Read-only evidence fixtures; these are not operational handover writers.
        $parcel = Delivery::factory()->create(['route_hub_ids' => [1, 2, 3]]);
        $record = DeliveryCheckpoint::record($parcel, 'picked_up', barcodeScanned: $parcel->tracking_number);
        DeliveryCheckpoint::record($parcel, 'courier_pickup', barcodeScanned: $parcel->tracking_number, evidence: ['source_checkpoint_id' => $record->id]);
        DeliveryCheckpoint::record($parcel, 'in_transit_to_mother_hub', barcodeScanned: $parcel->tracking_number, scanProvenance: 'manifest_load_event');
        DeliveryCheckpoint::record($parcel, 'arrived_at_destination_hub', barcodeScanned: $parcel->tracking_number, scanProvenance: 'local_hub_bypass');
        DeliveryCheckpoint::record($parcel, 'seller_pack', barcodeScanned: $parcel->tracking_number, scanProvenance: 'prepared_waybill');
        $summary = app(ParcelScanEvidenceService::class)->summary($parcel);
        $this->assertSame(3, $summary['route_hub_count']);
        $this->assertSame(1, $summary['submitted_waybill_records']);
        $this->assertSame(1, $summary['inherited_departure_records']);
        $this->assertSame(1, $summary['derived_local_records']);
    }

    public function test_missing_legacy_actor_and_time_remain_unknown_without_rewriting_history(): void
    {
        $parcel = Delivery::factory()->create();
        $this->assertNull(app(ParcelScanEvidenceService::class)->summary($parcel)['latest_record']);
        DB::table('delivery_checkpoints')->insert(['delivery_id' => $parcel->id, 'checkpoint_type' => 'legacy_record',
            'record_reference' => 'CP-UNKNOWN-LEGACY', 'scan_provenance' => 'not_scanned', 'created_at' => null, 'updated_at' => null]);
        $before = DB::table('delivery_checkpoints')->where('delivery_id', $parcel->id)->first();
        $summary = app(ParcelScanEvidenceService::class)->summary($parcel);
        $this->assertNull($summary['latest_record']['actor']);
        $this->assertNull($summary['latest_record']['recorded_at']);
        $this->assertSame(0, $summary['submitted_waybill_records']);
        $this->assertEquals($before, DB::table('delivery_checkpoints')->where('delivery_id', $parcel->id)->first());
    }
}
