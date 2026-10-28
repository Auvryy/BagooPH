<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class MisrouteTrapService
{
    public function inspect(Delivery $parcel, LogisticsHub $hub, User $actor): ?string
    {
        if ($parcel->logistics_company_id !== $hub->logistics_company_id) {
            return null;
        }
        if (in_array($parcel->status, ['picked_up', 'assigned_pickup'], true) && $hub->id !== $parcel->origin_bayan_hub_id) {
            return 'Wrong hub. This pickup belongs at '.$parcel->originBayanHub?->name.'. Set the parcel aside and contact the origin hub before any custody update.';
        }
        $member = LogisticsManifestParcel::with('manifest')->where('active_delivery_id', $parcel->id)->whereNull('received_at')->first();
        $manifest = $member?->manifest;
        if (! $manifest || $manifest->status !== 'dispatched' || $manifest->direction === 'recovery'
            || $manifest->destination_hub_id === $hub->id || app(ParcelRecoveryService::class)->active($parcel)) {
            return null;
        }
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if (($custody['kind'] ?? null) !== 'manifest' || ($custody['manifest_id'] ?? null) !== $manifest->id) {
            return null;
        }

        return DB::transaction(function () use ($parcel, $hub, $actor, $member, $manifest) {
            Order::whereKey($parcel->order_id)->lockForUpdate()->firstOrFail();
            $parcel = Delivery::with('order')->whereKey($parcel->id)->lockForUpdate()->firstOrFail();
            app(LogisticsEligibilityService::class)->lockNetwork($parcel->logistics_company_id, [$actor->id], [$hub->id]);
            abort_unless(app(LogisticsEligibilityService::class)->canScan($actor, $hub), 403);
            $manifest = LogisticsManifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            if ($manifest->status !== 'dispatched' || $member->fresh()->received_at) {
                throw new DomainException('This parcel movement changed. Scan its current waybill again.');
            }
            $observation = $manifest->events()->where('manifest_parcel_id', $member->id)->where('hub_id', $hub->id)
                ->where('event_type', 'report-discrepancy')->where('payload->scanner_misroute', true)->first();
            if (! $observation) {
                $observation = LogisticsManifestEvent::create(['manifest_id' => $manifest->id, 'manifest_parcel_id' => $member->id,
                    'actor_id' => $actor->id, 'hub_id' => $hub->id, 'event_type' => 'report-discrepancy', 'barcode_scanned' => $parcel->tracking_number,
                    'reason' => 'Parcel physically scanned at an unexpected hub. Set aside for recorded Mother Hub recovery.',
                    'source_state' => DeliveryCheckpoint::state($parcel), 'target_state' => DeliveryCheckpoint::state($parcel),
                    'payload' => ['kind' => 'wrong_hub', 'scanner_misroute' => true, 'delivery_id' => $parcel->id,
                        'expected_hub_id' => $manifest->destination_hub_id, 'source_checkpoint_reference' => $parcel->latestCustodyCheckpoint?->record_reference]]);
                $manifest->increment('version');
            }
            $expected = LogisticsHub::findOrFail($manifest->destination_hub_id);

            return 'Wrong hub. The next scheduled stop is '.$expected->name.'. Set this parcel aside. A misroute hold was recorded; open Parcel recovery to authorize its actual intake and return through the Mother Hub. Reference '.$observation->reference.'.';
        });
    }

    public function assertMovable(Delivery $parcel): void
    {
        if (LogisticsManifestEvent::where('event_type', 'report-discrepancy')->where('payload->scanner_misroute', true)
            ->where('payload->delivery_id', $parcel->id)->whereDoesntHave('recoveryCase', fn ($q) => $q->where('state', 'completed'))->exists()) {
            throw new DomainException('This parcel has a wrong-hub scan. Complete the recorded Parcel recovery before its normal route resumes.');
        }
    }
}
