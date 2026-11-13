<?php

namespace App\Services\Logistics;

use App\Models\LogisticsFleet;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsTransportTrip;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\ShipmentLossAccountingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

/** Actual vehicle arrival remains independent from parcel and outer-sack receipts. */
class TransportArrivalService
{
    public function presentation(User $actor, LogisticsManifest $manifest): array
    {
        $event = $manifest->events()->where('event_type', 'transport-arrived')->first();

        return ['record' => $event ? ['reference' => $event->reference, 'created_at' => $event->created_at->toIso8601String()] : null,
            'can_record' => ! $event && $manifest->dispatched_at && in_array($manifest->status, ['dispatched', 'received', 'closed'], true)
                && $actor->isLogistics() && $actor->canAccessPortal() && $actor->id !== $manifest->driver_id
                && app(LogisticsEligibilityService::class)->canScan($actor, $manifest->destinationHub)
                && app(ShipmentLossAccountingService::class)->members($manifest)->isNotEmpty()];
    }

    public function record(User $original, LogisticsManifest $snapshot, array $input): LogisticsManifestEvent
    {
        abort_if(array_diff(array_keys($input), ['version', 'request_token', 'vehicle_plate', 'reason', 'arrival_confirmed', 'driver_identity_confirmed']), 422,
            'Only the original vehicle arrival and its explicit physical checks can be submitted here.');
        foreach (['reason', 'vehicle_plate'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $data = Validator::make($input, ['version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => ['required', 'uuid'],
            'vehicle_plate' => ['required', 'string', 'max:40'], 'reason' => ['required', new ApplicationText('notes', 10, 1000)],
            'arrival_confirmed' => ['required', 'accepted'], 'driver_identity_confirmed' => ['required', 'accepted']])->validate();
        $data['vehicle_plate'] = strtoupper($data['vehicle_plate']);
        $data['request_token'] = strtolower($data['request_token']);
        $fingerprint = hash('sha256', json_encode([$snapshot->id, $original->id, (int) $data['version'], $data['vehicle_plate'], $data['reason']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($original, $snapshot, $data, $fingerprint) {
            $manifest = LogisticsManifest::findOrFail($snapshot->id);
            $actor = User::findOrFail($original->id);
            abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $actor->id !== $manifest->driver_id
                && app(LogisticsEligibilityService::class)->canScan($actor, $manifest->destinationHub), 403,
                'The assigned destination handler must independently observe the original vehicle and driver.');
            $tripId = Schema::hasTable('logistics_trip_manifests')
                ? DB::table('logistics_trip_manifests')->where('manifest_id', $manifest->id)->whereNotNull('active_manifest_id')->value('trip_id') : null;
            $ids = $tripId ? DB::table('logistics_trip_manifests')->where('trip_id', $tripId)->pluck('manifest_id')->sort()->values()->all() : [$manifest->id];
            $manifests = LogisticsManifest::whereIn('id', $ids)->orderBy('id')->get();
            app(LogisticsTripService::class)->lockCargo($actor, $manifests);
            $trip = $tripId ? LogisticsTransportTrip::whereKey($tripId)->lockForUpdate()->firstOrFail() : null;
            $manifests = LogisticsManifest::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $manifest = $manifests->firstWhere('id', $manifest->id);
            $actor = User::findOrFail($actor->id);
            abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $actor->id !== $manifest->driver_id
                && app(LogisticsEligibilityService::class)->canScan($actor, $manifest->destinationHub), 403,
                'The assigned destination handler must still be independently eligible to receive this vehicle.');
            if ($prior = $manifest->events()->where('request_token', $data['request_token'])->first()) {
                abort_unless($prior->event_type === 'transport-arrived' && $prior->actor_id === $actor->id
                    && hash_equals($prior->request_fingerprint, $fingerprint), 409, 'Retain the exact original vehicle-arrival request.');

                return $prior;
            }
            abort_unless($manifest->version === (int) $data['version'] && (! $trip || $trip->status === 'dispatched')
                && $this->presentation($actor, $manifest)['can_record'], 409, 'Review the current original loss accounting and actual transport before recording arrival.');
            $fleet = LogisticsFleet::whereKey($manifest->vehicle_id)->lockForUpdate()->firstOrFail();
            $driver = User::findOrFail($manifest->driver_id);
            abort_unless($fleet->logistics_company_id === $manifest->logistics_company_id && $fleet->assigned_driver_id === $driver->id
                && $fleet->status === 'active' && $driver->isCourier()
                && app(LogisticsEligibilityService::class)->isOperational($driver->courierProfile)
                && $driver->courierProfile->logistics_company_id === $manifest->logistics_company_id
                && $driver->courierProfile->assigned_hub_id === $manifest->source_hub_id
                && $driver->courierProfile->vehicle_id === $fleet->id
                && strtoupper($fleet->plate_number) === $data['vehicle_plate'], 409,
                'Check the actual original vehicle plate and its distinct assigned driver; do not substitute another transport.');
            $root = null;
            foreach ($manifests as $related) {
                foreach (['logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id'] as $field) {
                    abort_unless($related->{$field} === $manifest->{$field}, 409, 'Retain the exact original shared transport identities.');
                }
                abort_unless($related->dispatched_at && in_array($related->status, ['dispatched', 'received', 'closed'], true)
                    && ! $related->events()->where('event_type', 'transport-arrived')->exists()
                    && ! $related->events()->where('request_token', $data['request_token'])->exists(), 409,
                    'This original transport already has an arrival or its retained departure changed.');
                $event = LogisticsManifestEvent::create(['manifest_id' => $related->id, 'actor_id' => $actor->id,
                    'hub_id' => $related->destination_hub_id, 'event_type' => 'transport-arrived', 'barcode_scanned' => null,
                    'manifest_parcel_id' => null, 'reason' => $data['reason'], 'source_state' => $related->state(), 'target_state' => $related->state(),
                    'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint,
                    'payload' => ['vehicle_id' => $fleet->id, 'driver_id' => $driver->id, 'vehicle_plate' => $data['vehicle_plate'],
                        'trip_id' => $trip?->id, 'manifest_ids' => $ids, 'no_parcel_receipt' => true, 'no_bag_receipt' => true, 'no_money_moved' => true]]);
                $related->increment('version');
                if ($related->id === $manifest->id) {
                    $root = $event;
                }
            }

            return $root;
        }, 3);
    }
}
