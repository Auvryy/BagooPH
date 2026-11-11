<?php

namespace App\Services\Logistics;

use App\Models\Delivery;

class ParcelScanEvidenceService
{
    /** Count retained evidence, never infer a number of physical scans from aliases or statuses. */
    public function summary(Delivery $parcel): array
    {
        $records = $parcel->checkpoints()->whereNull('source_checkpoint_id')->orderBy('id')->with('scannedBy:id,name')->get();
        $last = $records->last();

        return ['route_hub_count' => count($parcel->route_hub_ids ?? []),
            'submitted_waybill_records' => $records->filter(fn ($record) => $record->barcode_scanned
                && in_array($record->scan_provenance, ['submitted', 'hub_receipt'], true))->count(),
            'inherited_departure_records' => $records->where('scan_provenance', 'manifest_load_event')->count(),
            'derived_local_records' => $records->where('scan_provenance', 'local_hub_bypass')->count(),
            'latest_record' => $last ? ['reference' => $last->record_reference, 'type' => $last->checkpoint_type,
                'provenance' => $last->scan_provenance, 'actor' => $last->scannedBy?->name,
                'actor_role' => $last->actor_role, 'recorded_at' => $last->created_at?->toISOString()] : null];
    }
}
