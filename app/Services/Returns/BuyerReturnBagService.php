<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCargoReading;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnParcel;
use App\Models\BuyerReturnRecovery;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsTransportTrip;
use App\Models\MotherBag;
use App\Models\MotherBagReturnParcel;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\CargoHandlingService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsTripService;
use App\Services\Logistics\MotherBagService;
use App\Services\Logistics\WaybillScanInputService;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BuyerReturnBagService
{
    public function available(): bool
    {
        return Schema::hasTable('mother_bag_return_parcels');
    }

    public function pack(LogisticsTransportTrip $snapshot, User $actor, array $input): MotherBag
    {
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input), [
            'version' => ['required', new AsciiPositiveInteger, 'integer'], 'request_token' => 'required|uuid',
            'barcode' => app(WaybillScanInputService::class)->barcodeRules(true),
            'physical_tag' => ['required', 'string', 'max:80', 'regex:/\A[A-Z0-9-]+\z/'], 'physical_checked' => 'required|accepted',
        ])->validate();
        $hash = hash('sha256', json_encode(['return-pack', $snapshot->id, $data], JSON_THROW_ON_ERROR));

        return $this->locked($snapshot, $actor, false, function ($trip, $actor) use ($data, $hash) {
            if ($bag = $this->replay($trip, $actor, $data['request_token'], $hash)) {
                return $bag;
            }
            $this->readyTrip($trip, $data);
            $member = BuyerReturnManifestParcel::where('included', true)->whereNotNull('active_return_parcel_id')
                ->whereHas('parcel', fn ($query) => $query->where('barcode', $data['barcode']))
                ->whereIn('manifest_id', $trip->manifests()->select('logistics_manifests.id'))->with('parcel')->firstOrFail();
            $parcel = $member->parcel;
            $this->requireState($parcel->current_hub_id === $trip->source_hub_id && $parcel->status === 'at_hub'
                && $member->source_state === $parcel->state() && $parcel->logistics_company_id === $trip->logistics_company_id,
                'Scan an actual loaded buyer return on this trip at its recorded source hub.');
            $profile = app(BuyerReturnCargoService::class)->profile($parcel);
            $this->requireState($profile['baggable'], 'This package exceeds the bag limits. Retain it as individually scanned loose cargo.');
            $this->requireState(! MotherBagReturnParcel::where('active_return_parcel_id', $parcel->id)->exists(), 'This return already belongs to an active sack.');
            $bag = MotherBag::where('return_trip_id', $trip->id)->where('physical_tag', $data['physical_tag'])->where('status', 'open')->lockForUpdate()->first();
            if (! $bag) {
                $bag = app(MotherBagService::class)->create($actor, ['source_hub_id' => $trip->source_hub_id,
                    'destination_hub_id' => $trip->destination_hub_id, 'physical_tag' => $data['physical_tag'],
                    'physical_checked' => true, 'creation_token' => (string) Str::uuid()]);
                $bag->update(['return_trip_id' => $trip->id]);
            }
            app(CargoHandlingService::class)->assertFits($bag, $profile);
            $packed = MotherBagReturnParcel::create(['mother_bag_id' => $bag->id, 'buyer_return_parcel_id' => $parcel->id,
                'active_return_parcel_id' => $parcel->id, 'buyer_return_manifest_parcel_id' => $member->id,
                'buyer_return_cargo_reading_id' => app(BuyerReturnCargoService::class)->reading($parcel)->id,
                'loaded_by_id' => $actor->id, 'barcode_scanned' => $parcel->barcode, 'source_state' => $parcel->state(), 'handling_snapshot' => $profile]);
            $bag->update(['version' => $bag->version + 1]);
            $this->event($bag, $actor, 'return-packed', $parcel->barcode, ['return_member_id' => $packed->id]);
            $this->request($bag, $actor, $data['request_token'], $hash);

            return $bag;
        });
    }

    public function command(LogisticsTransportTrip $snapshot, MotherBag $selected, User $actor, string $action, array $input): MotherBag
    {
        abort_unless(in_array($action, ['seal', 'reopen', 'remove', 'open'], true), 422);
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input), [
            'version' => ['required', new AsciiPositiveInteger, 'integer'], 'bag_version' => ['required', new AsciiPositiveInteger, 'integer'],
            'request_token' => 'required|uuid', 'barcode' => app(WaybillScanInputService::class)->barcodeRules(true),
            'return_barcode' => [$action === 'remove' ? 'required' : 'prohibited', 'string', 'max:40', 'regex:/\ABRT-[A-Z0-9-]+\z/'],
            'seal_number' => [in_array($action, ['seal', 'open'], true) ? 'required' : 'nullable', 'string', 'max:80', 'regex:/\A[A-Z0-9-]+\z/'],
            'seal_intact' => $action === 'open' ? 'required|accepted' : 'prohibited',
            'notes' => [in_array($action, ['remove', 'reopen'], true) ? 'required' : 'nullable', new ApplicationText('notes', 10, 1000)],
        ])->validate();
        $hash = hash('sha256', json_encode(['return-bag', $snapshot->id, $selected->id, $action, $data], JSON_THROW_ON_ERROR));

        return $this->locked($snapshot, $actor, $action === 'open', function ($trip, $actor) use ($selected, $action, $data, $hash) {
            $bag = MotherBag::whereKey($selected->id)->where('return_trip_id', $trip->id)->lockForUpdate()->firstOrFail();
            if ($prior = $this->replay($trip, $actor, $data['request_token'], $hash)) {
                $this->requireState($prior->id === $bag->id, 'This request belongs to a different sack.');

                return $bag;
            }
            $this->requireState($trip->version === (int) $data['version'] && $bag->version === (int) $data['bag_version'], 'This trip or sack changed. Reload its retained list.');
            $this->requireState(hash_equals($bag->reference, $data['barcode']), 'Scan the actual master tag of this sack.');
            $payload = ['notes' => $data['notes'] ?? null];
            if ($action === 'open') {
                $departure = DB::table('mother_bag_events')->where('mother_bag_id', $bag->id)->where('event_type', 'return-departed')->first();
                $this->requireState($trip->status === 'dispatched' && $bag->status === 'in_transit' && $departure && $departure->actor_id !== $actor->id
                    && hash_equals((string) $bag->seal_number, $data['seal_number']), 'The separate destination handler must verify this arriving sack and its actual intact numbered seal.');
                $bag->fill(['status' => 'opened', 'opened_at' => now(), 'opened_by_id' => $actor->id]);
            } else {
                $this->readyTrip($trip, $data);
                if ($action === 'reopen') {
                    $this->requireState($bag->status === 'sealed', 'Reopen only this unstarted sealed sack, with its actual reason.');
                    $bag->fill(['status' => 'open', 'sealed_at' => null, 'seal_number' => null]);
                } elseif ($action === 'remove') {
                    $this->requireState($bag->status === 'open', 'Open the unstarted sack before removing a return.');
                    $member = $bag->returnParcels()->where('barcode_scanned', $data['return_barcode'])->whereNotNull('active_return_parcel_id')->lockForUpdate()->firstOrFail();
                    $member->update(['active_return_parcel_id' => null, 'removed_at' => now()]);
                    $payload['return_member_id'] = $member->id;
                    if (! $bag->returnParcels()->whereNull('removed_at')->exists()) {
                        $bag->fill(['status' => 'emptied_at_source', 'active_physical_key' => null]);
                    }
                } else {
                    $this->requireState($bag->status === 'open' && $bag->returnParcels()->whereNull('removed_at')->exists(), 'Pack actual eligible returns before sealing this sack.');
                    $this->verifyMembers($bag);
                    $this->requireState(! DB::table('mother_bag_seals')->where('logistics_company_id', $bag->logistics_company_id)->where('seal_number', $data['seal_number'])->exists(), 'That numbered seal was already used. Attach a new seal.');
                    DB::table('mother_bag_seals')->insert(['mother_bag_id' => $bag->id, 'logistics_company_id' => $bag->logistics_company_id, 'seal_number' => $data['seal_number']]);
                    $bag->fill(['status' => 'sealed', 'sealed_at' => now(), 'seal_number' => $data['seal_number']]);
                    $payload['return_members'] = $this->members($bag);
                    $payload['seal_number'] = $data['seal_number'];
                }
            }
            $bag->version++;
            $bag->save();
            $this->event($bag, $actor, 'return-'.$action, $data['barcode'], $payload);
            if ($action === 'open') {
                $this->finishSack($bag, $actor);
            }
            $this->request($bag, $actor, $data['request_token'], $hash);

            return $bag;
        });
    }

    public function assertReady(LogisticsTransportTrip $trip): void
    {
        if (! $this->available()) {
            $this->requireState(! $trip->manifests()->where('direction', 'buyer_return')->exists(), 'Apply the additive return-sack migration before co-loading buyer returns.');

            return;
        }
        $verifiedBags = [];
        foreach (BuyerReturnManifestParcel::whereIn('manifest_id', $trip->manifests()->select('logistics_manifests.id'))->where('included', true)->with('parcel')->get() as $member) {
            $profile = app(BuyerReturnCargoService::class)->profile($member->parcel);
            $packed = MotherBagReturnParcel::where('active_return_parcel_id', $member->buyer_return_parcel_id)->with('bag')->first();
            if (! $profile['baggable']) {
                $this->requireState(! $packed, 'Oversized returns must remain individually identified loose cargo.');

                continue;
            }
            $this->requireState($packed && $packed->buyer_return_manifest_parcel_id === $member->id && $packed->bag->return_trip_id === $trip->id
                && $packed->bag->status === 'sealed', 'Pack baggable buyer returns into this trip’s actual sealed sacks before departure.');
            if (isset($verifiedBags[$packed->mother_bag_id])) {
                continue;
            }
            $this->verifyMembers($packed->bag);
            $seal = DB::table('mother_bag_events')->where('mother_bag_id', $packed->mother_bag_id)->where('event_type', 'return-seal')->latest('id')->first();
            $payload = $seal ? json_decode($seal->payload, true, flags: JSON_THROW_ON_ERROR) : [];
            $this->requireState(($payload['return_members'] ?? null) === $this->members($packed->bag)
                && ($payload['seal_number'] ?? null) === $packed->bag->seal_number, 'The sack contents differ from its retained numbered seal.');
            $verifiedBags[$packed->mother_bag_id] = true;
        }
    }

    public function departed(LogisticsTransportTrip $trip, User $actor): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        if (! $this->available()) {
            return;
        }
        foreach (MotherBag::where('return_trip_id', $trip->id)->where('status', 'sealed')->lockForUpdate()->get() as $bag) {
            $bag->update(['status' => 'in_transit', 'dispatched_at' => now(), 'version' => $bag->version + 1]);
            $this->event($bag, $actor, 'return-departed', $bag->reference, ['trip_reference' => $trip->reference, 'seal_number' => $bag->seal_number]);
        }
    }

    public function assertReceipt(LogisticsManifest $manifest, BuyerReturnParcel $parcel): void
    {
        if (! $this->available()) {
            return;
        }
        $tripId = DB::table('logistics_trip_manifests')->where('active_manifest_id', $manifest->id)->value('trip_id');
        if (! $tripId) {
            return;
        }
        $profile = app(BuyerReturnCargoService::class)->profile($parcel);
        $packed = MotherBagReturnParcel::where('active_return_parcel_id', $parcel->id)->with('bag')->first();
        $this->requireState(! $profile['baggable'] || ($packed && $packed->bag->return_trip_id === $tripId && $packed->bag->status === 'opened'
            && $packed->bag->opened_at), 'Verify and open this return’s actual sack and numbered seal before scanning its individual receipt.');
    }

    public function received(LogisticsManifest $manifest, BuyerReturnParcel $parcel, User $actor): void
    {
        if (! $this->available()) {
            return;
        }
        $member = MotherBagReturnParcel::where('active_return_parcel_id', $parcel->id)->lockForUpdate()->first();
        if (! $member) {
            return;
        }
        abort_unless(DB::transactionLevel() > 0, 409);
        $bag = MotherBag::whereKey($member->mother_bag_id)->lockForUpdate()->firstOrFail();
        $original = BuyerReturnManifestParcel::whereKey($member->buyer_return_manifest_parcel_id)->where('manifest_id', $manifest->id)
            ->where('received_by_id', $actor->id)->whereNotNull('received_at')->first();
        $receipt = $manifest->events()->where('event_type', 'buyer-return-received')->where('barcode_scanned', $parcel->barcode)->where('actor_id', $actor->id)->first();
        $this->requireState($bag->status === 'opened' && $bag->destination_hub_id === $manifest->destination_hub_id && $original && $receipt
            && ($receipt->payload['return_member_id'] ?? null) === $original->id, 'Use the return’s actual opened sack and original receiving manifest.');
        $member->update(['active_return_parcel_id' => null, 'received_at' => now(), 'received_by_id' => $actor->id]);
        $this->event($bag, $actor, 'return-received', $parcel->barcode, ['return_member_id' => $member->id]);
        $this->finishSack($bag, $actor);
    }

    public function recovered(BuyerReturnRecovery $recovery, BuyerReturnParcel $parcel, User $actor, LogisticsManifestEvent $receipt): void
    {
        if (! $recovery->original_bag_member_id) {
            return;
        }
        abort_unless(DB::transactionLevel() > 0, 409);
        $member = MotherBagReturnParcel::whereKey($recovery->original_bag_member_id)->lockForUpdate()->firstOrFail();
        $bag = MotherBag::whereKey($member->mother_bag_id)->lockForUpdate()->firstOrFail();
        $this->requireState($recovery->state === 'completed' && $member->buyer_return_parcel_id === $parcel->id
            && $member->buyer_return_manifest_parcel_id === $recovery->original_member_id && $receipt->actor_id === $actor->id
            && $receipt->hub_id === $bag->destination_hub_id && ($receipt->payload['return_recovery_id'] ?? null) === $recovery->id
            && DB::table('buyer_return_bag_extractions')->where('original_bag_member_id', $member->id)->where('recovery_id', $recovery->id)->exists(),
            'Link the actual completed recovery and expected receipt before accounting for its original sack member.');
        $member->update(['received_at' => now(), 'received_by_id' => $actor->id]);
        $this->event($bag, $actor, 'return-recovered', $parcel->barcode, ['return_recovery_id' => $recovery->id, 'original_receipt_reference' => $receipt->reference]);
        $this->finishSack($bag, $actor);
    }

    private function finishSack(MotherBag $bag, User $actor): void
    {
        if ($bag->status === 'opened' && $bag->opened_at && ! $bag->returnParcels()->whereNull('removed_at')->whereNull('received_at')->exists()) {
            $bag->update(['status' => 'received', 'active_physical_key' => null, 'version' => $bag->version + 1]);
            $this->event($bag, $actor, 'return-sack-received', $bag->reference, ['all_members_accounted' => true]);
        }
    }

    public function assertClose(LogisticsTransportTrip $trip): void
    {
        if ($this->available()) {
            $this->requireState(! MotherBag::where('return_trip_id', $trip->id)->whereNotIn('status', ['received', 'emptied_at_source'])->exists(),
                'Verify the actual arriving return sacks before closing this trip. Recovered parcels do not prove their original outer sack arrived.');
        }
    }

    public function assertCancellable(LogisticsTransportTrip $trip): void
    {
        if ($this->available()) {
            $this->requireState(! MotherBagReturnParcel::whereIn('mother_bag_id', MotherBag::where('return_trip_id', $trip->id)->select('id'))
                ->whereNotNull('active_return_parcel_id')->exists(), 'Unpack this trip’s actual return sacks before cancelling the grouping.');
        }
    }

    public function presentation(LogisticsTransportTrip $trip): array
    {
        if (! $this->available()) {
            return ['returnBags' => [], 'returnParcels' => [], 'readyToDepart' => ! $trip->manifests()->where('direction', 'buyer_return')->exists(),
                'packingReason' => 'Apply the additive return-sack migration before packing.'];
        }
        $parcels = BuyerReturnManifestParcel::whereIn('manifest_id', $trip->manifests()->select('logistics_manifests.id'))->where('included', true)->with('parcel')->get()
            ->map(function ($member) {
                $manifest = $member->manifest;
                $reading = BuyerReturnCargoReading::where('buyer_return_parcel_id', $member->buyer_return_parcel_id)
                    ->where('route_position', $member->source_state['route_position'])->where('hub_id', $manifest->source_hub_id)
                    ->when(Schema::hasColumn('buyer_return_cargo_readings', 'recovery_id'), fn ($query) => $query->where('recovery_id', $manifest->buyer_return_recovery_id))->latest('id')->first();

                return ['barcode' => $member->barcode_snapshot, 'status' => $member->parcel->status, 'baggable' => $reading?->profile['baggable'] ?? null,
                    'weight_kg' => $reading?->profile['weight_kg'] ?? null, 'manifest_id' => $member->manifest_id];
            });
        $ready = false;
        $reason = null;
        if ($trip->status === 'ready') {
            try {
                $this->assertReady($trip);
                $ready = true;
            } catch (DomainException $error) {
                $reason = $error->getMessage();
            }
        }

        return ['returnParcels' => $parcels, 'readyToDepart' => $ready, 'packingReason' => $reason,
            'returnBags' => MotherBag::where('return_trip_id', $trip->id)->orderBy('created_at')->get()->map(fn ($bag) => [
                ...$bag->only(['id', 'reference', 'physical_tag', 'seal_number', 'status', 'version']),
                'capacity' => app(CargoHandlingService::class)->capacity($bag),
                'barcodes' => $bag->returnParcels()->whereNull('removed_at')->orderBy('id')->pluck('barcode_scanned'),
            ])];
    }

    private function locked(LogisticsTransportTrip $snapshot, User $actor, bool $destination, Closure $operation): MotherBag
    {
        $this->requireState($this->available(), 'Apply the additive return-sack migration before packing.');

        return DB::transaction(function () use ($snapshot, $actor, $destination, $operation) {
            app(LogisticsTripService::class)->lockCargo($actor, $snapshot->manifests()->orderBy('logistics_manifests.id')->get());
            $trip = LogisticsTransportTrip::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $trip->manifests()->orderBy('logistics_manifests.id')->lockForUpdate()->get();
            $actor = User::findOrFail($actor->id);
            $hub = LogisticsHub::findOrFail($destination ? $trip->destination_hub_id : $trip->source_hub_id);
            abort_unless($actor->isLogistics() && $actor->canAccessPortal() && app(LogisticsEligibilityService::class)->canScan($actor, $hub), 403);

            return $operation($trip, $actor);
        }, 3);
    }

    private function readyTrip(LogisticsTransportTrip $trip, array $data): void
    {
        $this->requireState($trip->status === 'ready' && $trip->version === (int) $data['version'], 'This trip changed or already departed. Reload its actual state.');
    }

    private function verifyMembers(MotherBag $bag): void
    {
        $capacity = app(CargoHandlingService::class)->capacity($bag);
        $this->requireState($capacity['weight_kg'] !== null && $capacity['count'] > 0 && $capacity['count'] <= CargoHandlingService::BAG_PARCELS
            && $capacity['weight_kg'] <= CargoHandlingService::BAG_WEIGHT_KG && $capacity['volume_cm3'] <= CargoHandlingService::BAG_VOLUME_CM3,
            'This sack exceeds its actual parcel, weight or volume limit.');
        foreach ($bag->returnParcels()->whereNull('removed_at')->with('parcel')->get() as $member) {
            $profile = app(BuyerReturnCargoService::class)->profile($member->parcel);
            $physical = app(BuyerReturnRecoveryService::class)->context($member->parcel);
            $this->requireState($member->active_return_parcel_id === $member->buyer_return_parcel_id && $profile['baggable']
                && $member->handling_snapshot === $profile && $member->source_state === $member->parcel->state()
                && $member->parcel->current_hub_id === $bag->source_hub_id
                && ($physical['hub_ids'][$physical['position'] + 1] ?? null) === $bag->destination_hub_id,
                'A return changed from this sack’s retained physical contents. Recheck it before sealing or departure.');
        }
    }

    private function members(MotherBag $bag): array
    {
        return $bag->returnParcels()->whereNull('removed_at')->orderBy('id')->get(['id', 'buyer_return_parcel_id',
            'buyer_return_manifest_parcel_id', 'buyer_return_cargo_reading_id', 'barcode_scanned', 'source_state', 'handling_snapshot'])->toArray();
    }

    private function replay(LogisticsTransportTrip $trip, User $actor, string $token, string $hash): ?MotherBag
    {
        $previous = DB::table('bag_packing_requests')->where('actor_id', $actor->id)->where('request_token', $token)->first();
        if (! $previous) {
            return null;
        }
        $this->requireState(hash_equals($previous->fingerprint, $hash), 'This sack request already recorded different details. Reload before continuing.');

        return MotherBag::whereKey($previous->mother_bag_id)->where('return_trip_id', $trip->id)->firstOrFail();
    }

    private function request(MotherBag $bag, User $actor, string $token, string $hash): void
    {
        DB::table('bag_packing_requests')->insert(['actor_id' => $actor->id, 'request_token' => $token, 'fingerprint' => $hash, 'mother_bag_id' => $bag->id]);
    }

    private function event(MotherBag $bag, User $actor, string $type, string $barcode, array $payload): void
    {
        DB::table('mother_bag_events')->insert(['mother_bag_id' => $bag->id, 'actor_id' => $actor->id, 'event_type' => $type,
            'barcode_scanned' => $barcode, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    private function requireState(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }
}
