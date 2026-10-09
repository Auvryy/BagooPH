<?php

namespace App\Services\Courier;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Support\Facades\DB;

class CourierOutcomeService
{
    public function record(User $actor, Delivery $parcel, string $target, array $evidence): Delivery
    {
        return DB::transaction(function () use ($actor, $parcel, $target, $evidence) {
            $updated = app(OrderStateMachineService::class)->transition($parcel, $target, $actor, $evidence);
            if (! $updated->wasChanged('status')) {
                return $updated;
            }
            $note = $evidence['notes'] ?? null;
            if ($note) {
                $updated->update(['courier_notes' => $note]);
            }
            if ($target === OrderStateMachineService::STATUS_PICKED_UP) {
                if ($note) {
                    app(CourierMessagingService::class)->recordPickupNote($actor, $updated, $note);
                }
                $source = $updated->checkpoints()->where('checkpoint_type', 'picked_up')->whereNull('source_checkpoint_id')->firstOrFail();
                DeliveryCheckpoint::firstOrCreate(['delivery_id' => $updated->id, 'checkpoint_type' => 'courier_pickup'], [
                    'location_name' => $updated->pickup_store_name ?? 'Merchant store', 'barcode_scanned' => $source->barcode_scanned,
                    'scan_provenance' => 'source_alias', 'source_checkpoint_id' => $source->id,
                    'source_state' => $source->source_state, 'target_state' => $source->target_state,
                    'custody_before' => $source->custody_before, 'custody_after' => $source->custody_after,
                    'notes' => $note ?: 'Pickup rider matched the waybill and collected the seller parcel.', 'scanned_by_id' => $actor->id,
                ]);
            }

            return $updated;
        });
    }
}
