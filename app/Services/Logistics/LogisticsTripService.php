<?php

namespace App\Services\Logistics;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsTransportTrip;
use App\Models\MotherBag;
use App\Models\Order;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\Returns\BuyerReturnBagService;
use App\Services\Returns\BuyerReturnCargoService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Groups original manifests into one physical journey, without merging their protocols. */
class LogisticsTripService
{
    private array $departing = [];

    public function create(User $actor, array $input): LogisticsTransportTrip
    {
        $data = Validator::make($input, ['manifest_ids' => 'required|array|min:1|max:100',
            'manifest_ids.*' => ['required', new AsciiPositiveInteger, 'integer', 'distinct'],
            'request_token' => 'required|uuid'])->validate();
        $ids = collect($data['manifest_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $fingerprint = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $ids, $fingerprint) {
            $manifests = LogisticsManifest::whereIn('id', $ids)->orderBy('id')->get();
            abort_unless($manifests->count() === count($ids), 404);
            $this->lockCargo($actor, $manifests);
            $manifests = LogisticsManifest::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $first = $manifests->first();
            $this->authorize($actor, $first->source_hub_id);
            $this->requireState($manifests->count() > 1 || $first->direction === 'buyer_return', 'Choose at least two compatible manifests, or the single buyer-return load requiring its own sack or loose-cargo trip.');
            $this->requireState(! $manifests->contains(fn ($manifest) => $manifest->buyer_return_recovery_id !== null) || $manifests->count() === 1,
                'An authorized return recovery uses its own dedicated physical trip.');
            $previous = LogisticsTransportTrip::where('created_by_id', $actor->id)->where('creation_token', $data['request_token'])->first();
            if ($previous) {
                $this->requireState(hash_equals($previous->creation_fingerprint, $fingerprint), 'This request already prepared a different trip.');

                return $previous;
            }
            foreach ($manifests as $manifest) {
                foreach (['logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id', 'type'] as $key) {
                    if ($manifest->$key !== $first->$key) {
                        throw new DomainException('Co-loaded manifests must share the exact company, next hub, source, vehicle and driver.');
                    }
                }
                if ($manifest->status !== 'sealed' || ! in_array($manifest->direction, ['outbound', 'return', 'buyer_return'], true)
                    || DB::table('logistics_trip_manifests')->where('active_manifest_id', $manifest->id)->exists()) {
                    throw new DomainException('Choose sealed original manifests that do not already belong to an active trip.');
                }
            }
            $capacity = $this->capacity($manifests);
            app(LogisticsManifestService::class)->vehicle($first->logistics_company_id, $first->source_hub_id, $first->vehicle_id, $first->driver_id, true, $first->type);
            $trip = LogisticsTransportTrip::create($first->only(['logistics_company_id', 'source_hub_id', 'destination_hub_id', 'vehicle_id', 'driver_id'])
                + ['created_by_id' => $actor->id, 'creation_token' => $data['request_token'], 'creation_fingerprint' => $fingerprint, 'status' => 'ready', 'version' => 1]);
            foreach ($manifests as $manifest) {
                DB::table('logistics_trip_manifests')->insert(['trip_id' => $trip->id, 'manifest_id' => $manifest->id,
                    'active_manifest_id' => $manifest->id, 'source_snapshot' => json_encode($manifest->state(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
            }
            $this->record($trip, $actor, 'created', $data['request_token'], $fingerprint, ['capacity' => $capacity, 'manifest_ids' => $ids]);

            return $trip;
        }, 3);
    }

    public function command(LogisticsTransportTrip $snapshot, User $actor, string $action, array $input): LogisticsTransportTrip
    {
        $data = Validator::make($input, ['version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => 'required|uuid'])->validate();
        abort_unless(in_array($action, ['dispatch', 'cancel', 'close'], true), 422);
        $fingerprint = hash('sha256', json_encode([$action, (int) $data['version']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($snapshot, $actor, $action, $data, $fingerprint) {
            $manifests = $snapshot->manifests()->orderBy('logistics_manifests.id')->get();
            $this->lockCargo($actor, $manifests);
            $trip = LogisticsTransportTrip::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $manifests = $trip->manifests()->orderBy('logistics_manifests.id')->lockForUpdate()->get();
            $this->authorize($actor, $action === 'close' ? $trip->destination_hub_id : $trip->source_hub_id);
            if ($prior = DB::table('logistics_trip_events')->where('trip_id', $trip->id)->where('request_token', $data['request_token'])->first()) {
                $this->requireState($prior->actor_id === $actor->id && hash_equals($prior->fingerprint, $fingerprint), 'This trip request already recorded different details.');

                return $trip;
            }
            $this->requireState($trip->version === (int) $data['version'], 'This trip changed. Reload its saved result.');
            $this->requireState($trip->status === ($action === 'close' ? 'dispatched' : 'ready'), 'This action is not available for the current trip.');
            $context = [];
            if ($action === 'dispatch') {
                $context['capacity'] = $this->capacity($manifests);
                app(BuyerReturnBagService::class)->assertReady($trip);
                $first = $manifests->firstOrFail();
                app(LogisticsManifestService::class)->vehicle($trip->logistics_company_id, $trip->source_hub_id, $trip->vehicle_id, $trip->driver_id, true, $first->type);
                $this->departing = $manifests->pluck('id')->all();
                try {
                    foreach ($manifests as $manifest) {
                        $source = DB::table('logistics_trip_manifests')->where('trip_id', $trip->id)->where('manifest_id', $manifest->id)->first();
                        $this->requireState($source->active_manifest_id === $manifest->id
                            && json_decode($source->source_snapshot, true) === json_decode(json_encode($manifest->state(), JSON_THROW_ON_ERROR), true),
                            'A grouped manifest changed. Cancel this unstarted trip and review its original list.');
                        app(LogisticsManifestService::class)->command($manifest, $actor, 'dispatch', ['version' => $manifest->version,
                            'request_token' => (string) Str::uuid(), 'notes' => 'Departed on shared trip '.$trip->reference.'.']);
                    }
                } finally {
                    $this->departing = [];
                }
                app(BuyerReturnBagService::class)->departed($trip, $actor);
            } elseif ($action === 'close') {
                app(BuyerReturnBagService::class)->assertClose($trip);
                foreach ($manifests as $manifest) {
                    $this->requireState(! MotherBag::where('manifest_id', $manifest->id)->whereNotNull('dispatched_at')
                        ->whereNotIn('status', ['received', 'emptied_at_source'])->exists(),
                        'Record the actual arrival and member accountability of each original bag before closing the trip.');
                    $this->requireState(! LogisticsManifest::awaitingPhysicalReceipt()->whereKey($manifest->id)->exists(),
                        'Account for the actual vehicle arrival separately; a confirmed parcel loss is not a transport receipt.');
                    $this->requireState(in_array($manifest->status, ['received', 'closed'], true)
                        && app(LogisticsManifestService::class)->receiptCounts($manifest)['outstanding'] === 0
                        && app(LogisticsManifestService::class)->openDiscrepancies($manifest)->isEmpty(),
                        'Record every original parcel receipt and resolve observations before closing the shared trip.');
                }
            } elseif ($action === 'cancel') {
                app(BuyerReturnBagService::class)->assertCancellable($trip);
            }
            $trip->update(['status' => match ($action) {
                'dispatch' => 'dispatched', 'cancel' => 'cancelled', 'close' => 'closed'
            }, 'version' => $trip->version + 1]);
            if ($action !== 'dispatch') {
                DB::table('logistics_trip_manifests')->where('trip_id', $trip->id)->update(['active_manifest_id' => null]);
            }
            $this->record($trip, $actor, $action, $data['request_token'], $fingerprint, $context);

            return $trip;
        }, 3);
    }

    public function assertManifestCommand(LogisticsManifest $manifest, string $action): void
    {
        if (Schema::hasTable('logistics_trip_manifests') && DB::table('logistics_trip_manifests')->where('active_manifest_id', $manifest->id)->exists()
            && in_array($action, ['load', 'remove', 'seal', 'reopen', 'dispatch'], true) && ! in_array($manifest->id, $this->departing, true)) {
            throw new DomainException('Use the shared trip to depart, or cancel its unstarted grouping before changing a manifest.');
        }
    }

    public function departingManifestIds(): array
    {
        return $this->departing;
    }

    public function departingVehicle(int $vehicleId): bool
    {
        return $this->departing !== [] && LogisticsManifest::whereIn('id', $this->departing)->where('vehicle_id', $vehicleId)->count() === count($this->departing);
    }

    public function lockCargo(User $actor, Collection $manifests): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $this->requireState($manifests->isNotEmpty(), 'Select the original manifests for this trip.');
        abort_unless($manifests->pluck('logistics_company_id')->unique()->count() === 1, 403);
        $ids = $manifests->pluck('id');
        $returns = BuyerReturnParcel::whereIn('id', DB::table('buyer_return_manifest_parcels')->whereIn('manifest_id', $ids)->select('buyer_return_parcel_id'))->get();
        $caseIds = $returns->pluck('buyer_return_case_id');
        $deliveryIds = DB::table('logistics_manifest_parcels')->whereIn('manifest_id', $ids)->pluck('delivery_id')
            ->merge(BuyerReturnCase::whereIn('id', $caseIds)->pluck('delivery_id'))->unique()->sort();
        Order::whereIn('id', Delivery::whereIn('id', $deliveryIds)->select('order_id'))->orderBy('id')->lockForUpdate()->get();
        Delivery::whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
        app(LogisticsEligibilityService::class)->lockNetwork($manifests->first()->logistics_company_id,
            [$actor->id, ...$manifests->pluck('driver_id')->all()], $manifests->pluck('source_hub_id')->merge($manifests->pluck('destination_hub_id'))->unique()->all());
        BuyerReturnCase::whereIn('id', $caseIds)->orderBy('id')->lockForUpdate()->get();
        BuyerReturnParcel::whereIn('id', $returns->pluck('id'))->orderBy('id')->lockForUpdate()->get();
    }

    private function authorize(User $actor, int $hubId): void
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isLogistics() && app(LogisticsManifestService::class)->canManageAtHub($actor, LogisticsHub::findOrFail($hubId)), 403);
    }

    private function capacity(Collection $manifests): array
    {
        $grams = 0;
        $count = 0;
        foreach ($manifests as $manifest) {
            if ($manifest->direction === 'buyer_return') {
                $members = $manifest->buyerReturnParcels()->where('included', true)->with('parcel')->get();
                foreach ($members as $member) {
                    $profile = app(BuyerReturnCargoService::class)->profile($member->parcel);
                    $grams += (int) round($profile['weight_kg'] * 1000);
                    $count++;
                }
            } else {
                $capacity = app(CargoHandlingService::class)->manifestCapacity($manifest);
                $this->requireState($capacity['weight_kg'] !== null && $capacity['count'] > 0, 'Each co-loaded parcel needs retained handling readings.');
                $grams += (int) round($capacity['weight_kg'] * 1000);
                $count += $capacity['count'];
            }
        }
        $max = (int) round($manifests->first()->vehicle->capacity_kg * 1000);
        $this->requireState($count > 0 && $max > 0 && $grams <= $max, 'The combined cargo exceeds this vehicle’s actual capacity.');

        return ['weight_grams' => $grams, 'max_weight_grams' => $max, 'parcel_count' => $count];
    }

    private function requireState(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new DomainException($message);
        }
    }

    private function record(LogisticsTransportTrip $trip, User $actor, string $action, string $token, string $fingerprint, array $context): void
    {
        DB::table('logistics_trip_events')->insert(['trip_id' => $trip->id, 'actor_id' => $actor->id, 'event_type' => $action,
            'sequence' => $trip->version, 'request_token' => $token, 'fingerprint' => $fingerprint,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
