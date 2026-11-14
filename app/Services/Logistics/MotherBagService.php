<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\Order;
use App\Models\ParcelRecoveryCase;
use App\Models\ParcelRecoveryEvent;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\ShipmentLossAccountingService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MotherBagService
{
    public function readable(User $actor): Builder
    {
        $hubs = app(LogisticsEligibilityService::class)->accessibleHubs($actor)->pluck('id');

        return MotherBag::query()->when(Schema::hasColumn('mother_bags', 'return_trip_id'), fn ($query) => $query->whereNull('return_trip_id'))
            ->where(fn ($q) => $q->whereIn('source_hub_id', $hubs)->orWhereIn('destination_hub_id', $hubs));
    }

    public function create(User $actor, array $input): MotherBag
    {
        $data = Validator::make($input, [
            'source_hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'], 'destination_hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'different:source_hub_id'],
            'creation_token' => 'required|uuid',
            'physical_tag' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Z0-9-]+\z/'],
            'physical_checked' => 'sometimes|boolean',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $source = LogisticsHub::eligible()->findOrFail($data['source_hub_id']);
            $destination = LogisticsHub::eligible()->where('logistics_company_id', $source->logistics_company_id)->findOrFail($data['destination_hub_id']);
            app(LogisticsEligibilityService::class)->lockNetwork($source->logistics_company_id, [$actor->id], [$source->id, $destination->id]);
            $this->operator($actor, $source);
            app(LogisticsManifestService::class)->legType($source, $destination);
            $existing = MotherBag::where('created_by_id', $actor->id)->where('creation_token', $data['creation_token'])->first();
            if ($existing) {
                if ($existing->source_hub_id !== $source->id || $existing->destination_hub_id !== $destination->id || ($existing->physical_tag ?? null) !== ($data['physical_tag'] ?? null)) {
                    throw new DomainException('This bag request was already used for another route. Refresh before creating another bag.');
                }

                return $existing;
            }
            $extra = [];
            if (app(CargoHandlingService::class)->available()) {
                $extra = ['packing_policy_version' => 1, ...$this->physicalTag($source, $data)];
            } elseif (! empty($data['physical_tag'])) {
                throw new DomainException('Apply the additive packing migration before registering a reusable sack.');
            }
            $bag = MotherBag::create([...$extra, 'reference' => 'BAG-'.strtoupper(Str::random(12)),
                'logistics_company_id' => $source->logistics_company_id, 'source_hub_id' => $source->id,
                'destination_hub_id' => $destination->id, 'created_by_id' => $actor->id, 'creation_token' => $data['creation_token']]);
            $this->event($bag, $actor, 'created');

            return $bag;
        });
    }

    public function pack(User $actor, array $input): MotherBag
    {
        if (! app(CargoHandlingService::class)->available()) {
            throw new DomainException('Apply the additive packing migration before using automatic packing.');
        }
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input), [
            'source_hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1'], 'barcode' => 'required|string|max:255|regex:/\A[A-Za-z0-9-]+\z/',
            'request_token' => 'required|uuid', 'physical_tag' => 'nullable|string|max:80|regex:/\A[A-Z0-9-]+\z/',
            'physical_checked' => 'sometimes|boolean', ...app(CargoHandlingService::class)->rules(),
        ])->validate();
        $candidate = Delivery::where('tracking_number', $data['barcode'])->first();
        if (! $candidate) {
            throw new DomainException('Scan an existing parcel waybill.');
        }
        $source = LogisticsHub::eligible()->findOrFail($data['source_hub_id']);
        $this->operator($actor, $source);
        $fingerprint = hash('sha256', json_encode(['pack', $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($candidate, $source, $actor, $data, $fingerprint) {
            // Include the observed lane before network locking; a competing first scan must retry, not create another bag.
            $next = app(LogisticsManifestService::class)->nextHub($candidate);
            $lane = hash('sha256', $source->logistics_company_id.':'.$source->id.':'.$next);
            $observed = MotherBag::where('automatic_lane', $lane)->first();
            $ids = collect([$candidate->id])->merge($observed?->parcels()->whereNull('removed_at')->pluck('delivery_id') ?? [])->unique()->sort();
            Order::whereIn('id', Delivery::whereIn('id', $ids)->select('order_id'))->orderBy('id')->lockForUpdate()->get();
            Delivery::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            app(LogisticsEligibilityService::class)->lockNetwork($source->logistics_company_id, [$actor->id], [$source->id, $next]);
            $this->operator($actor, $source->fresh());
            if ($previous = DB::table('bag_packing_requests')->where('actor_id', $actor->id)->where('request_token', $data['request_token'])->first()) {
                if (! hash_equals($previous->fingerprint, $fingerprint)) {
                    throw new DomainException('This packing request was used for a different parcel or reading.');
                }

                return MotherBag::findOrFail($previous->mother_bag_id);
            }
            $parcel = $candidate->fresh();
            app(HandoverHoldService::class)->assertMovable($parcel);
            if ($parcel->current_hub_id !== $source->id || $parcel->logistics_company_id !== $source->logistics_company_id
                || app(LogisticsManifestService::class)->nextHub($parcel) !== $next) {
                throw new DomainException('The parcel must be held at the selected sending hub on its retained next leg.');
            }
            if (MotherBagParcel::where('active_delivery_id', $parcel->id)->exists() || LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->exists()) {
                throw new DomainException('This parcel already belongs to an active bag or manifest. Open its original packing record.');
            }
            $profile = app(CargoHandlingService::class)->profile($parcel, $actor, $data);
            if (! $profile['baggable']) {
                throw new DomainException('This parcel exceeds 15 kg or a 60 cm side. Load it individually as loose cargo in the manifest.');
            }
            $bag = MotherBag::where('automatic_lane', $lane)->lockForUpdate()->first();
            if ($bag && ($bag->id !== $observed?->id || $bag->parcels()->whereNull('removed_at')->pluck('delivery_id')->diff($ids)->isNotEmpty())) {
                throw new DomainException('Another packing scan opened or changed this lane. Retry your saved scan to use its current bag.');
            }
            if ($bag && ! empty($data['physical_tag']) && $bag->physical_tag !== $data['physical_tag']) {
                throw new DomainException('The open lane uses a different sack. Open that bag, or prepare a separate bag with the actual sack tag.');
            }
            if ($bag) {
                $c = app(CargoHandlingService::class)->capacity($bag);
                if ($c['weight_kg'] !== null && ($c['count'] >= CargoHandlingService::BAG_PARCELS
                    || $c['weight_kg'] + $profile['weight_kg'] > CargoHandlingService::BAG_WEIGHT_KG
                    || $c['volume_cm3'] + $profile['volume_cm3'] > CargoHandlingService::BAG_VOLUME_CM3)) {
                    if (! empty($data['physical_tag'])) {
                        throw new DomainException('This sack is full for the scanned parcel. Seal its current contents, then use another physical sack.');
                    }
                    $bag->update(['automatic_lane' => null]);
                    $this->event($bag, $actor, 'capacity_turnover', null, ['capacity' => $c]);
                    $bag = null;
                }
            }
            if (! $bag) {
                $bag = $this->create($actor, ['source_hub_id' => $source->id, 'destination_hub_id' => $next,
                    'creation_token' => $data['request_token'], 'physical_tag' => $data['physical_tag'] ?? null,
                    'physical_checked' => $data['physical_checked'] ?? false]);
                $bag->update(['automatic_lane' => $lane]);
            }
            $bag = $this->command($bag, $actor, 'add', ['version' => $bag->version, 'barcode' => $data['barcode'], ...array_intersect_key($data, ['handling' => true])]);
            DB::table('bag_packing_requests')->insert(['actor_id' => $actor->id, 'request_token' => $data['request_token'],
                'fingerprint' => $fingerprint, 'mother_bag_id' => $bag->id]);

            return $bag;
        });
    }

    private function physicalTag(LogisticsHub $source, array $data): array
    {
        if (empty($data['physical_tag'])) {
            return [];
        }
        if (empty($data['physical_checked'])) {
            throw new DomainException('Confirm the permanent tag on the physical sack before assigning it to this trip.');
        }
        $previous = MotherBag::where('logistics_company_id', $source->logistics_company_id)->where('physical_tag', $data['physical_tag'])->orderByDesc('created_at')->orderByDesc('id')->first();
        if (MotherBag::where('active_physical_key', hash('sha256', $source->logistics_company_id.':'.$data['physical_tag']))->exists()) {
            throw new DomainException('This sack is still in use. Finish its actual receipts before another trip.');
        }
        if ($previous && ! (($previous->status === 'received' && $previous->destination_hub_id === $source->id)
            || ($previous->status === 'emptied_at_source' && $previous->source_hub_id === $source->id))) {
            throw new DomainException('This sack is still in use or was last received at another hub. Use its actual current location.');
        }

        return ['physical_tag' => $data['physical_tag'], 'active_physical_key' => hash('sha256', $source->logistics_company_id.':'.$data['physical_tag'])];
    }

    public function command(MotherBag $snapshot, User $actor, string $action, array $input): MotherBag
    {
        abort_unless($this->readable($actor)->whereKey($snapshot->id)->exists(), 403);
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input), [
            'version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'], 'request_token' => 'sometimes|uuid',
            'barcode' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'seal_number' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'manifest_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1'], 'notes' => app(WaybillScanInputService::class)->notesRules(), 'seal_intact' => 'sometimes|boolean',
            ...app(CargoHandlingService::class)->rules(),
        ])->validate();
        abort_unless(in_array($action, ['add', 'remove', 'seal', 'reopen', 'load', 'unload', 'open'], true), 404);
        $candidate = in_array($action, ['add', 'remove'], true) ? Delivery::where('tracking_number', $data['barcode'] ?? '')->first() : null;

        return DB::transaction(function () use ($snapshot, $actor, $action, $data, $candidate) {
            $ids = $snapshot->parcels()->whereNull('removed_at')->pluck('delivery_id')->push($candidate?->id)->filter()->unique()->sort()->values();
            Order::whereIn('id', Delivery::whereIn('id', $ids)->select('order_id'))->orderBy('id')->lockForUpdate()->get();
            Delivery::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $eligibility = app(LogisticsEligibilityService::class);
            $eligibility->lockNetwork($snapshot->logistics_company_id, [$actor->id], [$snapshot->source_hub_id, $snapshot->destination_hub_id]);
            if ($manifestId = ($data['manifest_id'] ?? $snapshot->manifest_id)) {
                LogisticsManifest::whereKey($manifestId)->lockForUpdate()->firstOrFail();
            }
            $bag = MotherBag::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($bag->parcels()->whereNull('removed_at')->pluck('delivery_id')->diff($ids)->isNotEmpty()) {
                throw new DomainException('The bag membership changed. Refresh before continuing.');
            }
            $hub = LogisticsHub::eligible()->findOrFail($action === 'open' ? $bag->destination_hub_id : $bag->source_hub_id);
            $this->operator($actor, $hub);
            $fingerprint = hash('sha256', json_encode([$bag->id, $action, $data], JSON_THROW_ON_ERROR));
            if (! empty($data['request_token']) && app(CargoHandlingService::class)->available()) {
                if ($previous = DB::table('bag_packing_requests')->where('actor_id', $actor->id)->where('request_token', $data['request_token'])->first()) {
                    if (! hash_equals($previous->fingerprint, $fingerprint)) {
                        throw new DomainException('This request already recorded a different bag action. Refresh before continuing.');
                    }

                    return $bag;
                }
            }
            if (in_array($action, ['load', 'unload', 'open'], true)) {
                $this->masterTag($bag, $data);
            }
            if ($action === 'add' && $candidate && $bag->parcels()->where('active_delivery_id', $candidate->id)->exists()) {
                if ($bag->packing_policy_version && ! empty($data['request_token'])) {
                    throw new DomainException('This waybill is already in this bag. Its original handling readings are retained; remove it with a reason before repacking.');
                }

                return $bag;
            }
            if ($action === 'open' && $bag->opened_at && hash_equals((string) $bag->seal_number, (string) ($data['seal_number'] ?? ''))) {
                return $bag;
            }
            if ($action === 'load' && $bag->manifest_id && $bag->manifest_id === (int) ($data['manifest_id'] ?? 0) && $bag->loaded_at) {
                return $bag;
            }
            if ($bag->version !== (int) $data['version']) {
                throw new DomainException('The bag changed. Refresh its list and scan again.');
            }
            if (in_array($action, ['add', 'remove', 'seal', 'reopen', 'load', 'unload'], true)) {
                foreach (Delivery::whereIn('id', $ids)->get() as $held) {
                    app(HandoverHoldService::class)->assertMovable($held);
                }
            }
            if (in_array($action, ['add', 'remove'], true)) {
                if ($bag->status !== 'open' || ! $candidate) {
                    throw new DomainException('Scan a known parcel into an open bag.');
                }
                $parcel = Delivery::with('order')->findOrFail($candidate->id);
                if ($parcel->logistics_company_id !== $bag->logistics_company_id || $parcel->current_hub_id !== $hub->id
                    || app(LogisticsManifestService::class)->nextHub($parcel) !== $bag->destination_hub_id) {
                    throw new DomainException('This parcel belongs on a different route. Keep it at its recorded hub and check the next stop.');
                }
                if ($action === 'add') {
                    if (MotherBagParcel::where('active_delivery_id', $parcel->id)->exists()) {
                        throw new DomainException('This parcel is already inside another active Mother Bag.');
                    }
                    if (LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->exists()) {
                        throw new DomainException('This parcel already belongs to an active manifest. Unload it before packing.');
                    }
                    $handling = [];
                    if ($bag->packing_policy_version) {
                        $profile = app(CargoHandlingService::class)->profile($parcel, $actor, $data);
                        app(CargoHandlingService::class)->assertFits($bag, $profile);
                        $handling['handling_snapshot'] = $profile;
                    }
                    $bag->parcels()->updateOrCreate(['delivery_id' => $parcel->id], [...$handling, 'active_delivery_id' => $parcel->id,
                        'loaded_by_id' => $actor->id, 'barcode_scanned' => $data['barcode'],
                        'source_state' => DeliveryCheckpoint::state($parcel), 'removed_at' => null]);
                } else {
                    if (empty(trim($data['notes'] ?? ''))) {
                        throw new DomainException('Give the reason for removing this parcel from the bag.');
                    }
                    $bag->parcels()->where('delivery_id', $parcel->id)->whereNull('removed_at')->firstOrFail()
                        ->update(['active_delivery_id' => null, 'removed_at' => now()]);
                }
            } elseif ($action === 'seal') {
                if ($bag->status !== 'open' || ! $bag->parcels()->whereNull('removed_at')->exists() || empty($data['seal_number'])) {
                    throw new DomainException('Add parcels to the open bag and enter the numbered seal before sealing.');
                }
                if ($bag->packing_policy_version) {
                    $capacity = app(CargoHandlingService::class)->capacity($bag);
                    if ($capacity['weight_kg'] === null || $capacity['weight_kg'] > CargoHandlingService::BAG_WEIGHT_KG || $capacity['volume_cm3'] > CargoHandlingService::BAG_VOLUME_CM3 || $capacity['count'] > CargoHandlingService::BAG_PARCELS) {
                        throw new DomainException('Recheck bag capacity and missing readings before sealing.');
                    }
                    foreach ($bag->parcels()->whereNull('removed_at')->with('delivery')->get() as $packed) {
                        if ($packed->active_delivery_id !== $packed->delivery_id || ! ($packed->handling_snapshot['baggable'] ?? false) || $packed->source_state !== DeliveryCheckpoint::state($packed->delivery)) {
                            throw new DomainException('A bag member changed. Recheck the actual contents before sealing.');
                        }
                    }
                }
                if (DB::table('mother_bag_seals')->where('logistics_company_id', $bag->logistics_company_id)->where('seal_number', $data['seal_number'])->exists()) {
                    throw new DomainException('This numbered seal was already used. Attach a new seal.');
                }
                DB::table('mother_bag_seals')->insert(['mother_bag_id' => $bag->id, 'logistics_company_id' => $bag->logistics_company_id, 'seal_number' => $data['seal_number']]);
                $bag->fill(['status' => 'sealed', 'seal_number' => $data['seal_number'], 'sealed_by_id' => $actor->id, 'sealed_at' => now(), ...($bag->packing_policy_version ? ['automatic_lane' => null] : [])]);
            } elseif ($action === 'reopen') {
                if ($bag->status !== 'sealed' || $bag->manifest_id || empty(trim($data['notes'] ?? ''))) {
                    throw new DomainException('Only an unloaded sealed bag can be reopened with a reason.');
                }
                $bag->fill(['status' => 'open', 'sealed_at' => null, 'sealed_by_id' => null]);
            } elseif ($action === 'load') {
                $this->masterTag($bag, $data);
                $manifest = LogisticsManifest::findOrFail($data['manifest_id'] ?? 0);
                if ($bag->status !== 'sealed' || $bag->manifest_id || $manifest->status !== 'draft'
                    || $manifest->logistics_company_id !== $bag->logistics_company_id
                    || $manifest->source_hub_id !== $bag->source_hub_id || $manifest->destination_hub_id !== $bag->destination_hub_id) {
                    throw new DomainException('Load this sealed bag onto a draft manifest with the same company and next hub.');
                }
                $bag->fill(['manifest_id' => $manifest->id, 'loaded_by_id' => $actor->id, 'loaded_at' => now(), 'master_barcode_scanned' => $data['barcode']])->save();
                foreach ($bag->parcels()->whereNull('removed_at')->with('delivery.order')->orderBy('delivery_id')->get() as $member) {
                    if ($member->source_state !== DeliveryCheckpoint::state($member->delivery)) {
                        throw new DomainException('A bag member changed after its packing scan. Recheck the original parcel before loading.');
                    }
                    app(LogisticsManifestService::class)->command($manifest->fresh(), $actor, 'load', [
                        'version' => $manifest->fresh()->version, 'request_token' => (string) Str::uuid(),
                        'barcode' => $member->barcode_scanned, 'notes' => 'Loaded by master bag scan '.$bag->reference.'.',
                    ], $bag);
                }
            } elseif ($action === 'unload') {
                $manifest = $bag->manifest;
                if ($bag->status !== 'sealed' || ! $manifest || $manifest->status !== 'draft' || empty(trim($data['notes'] ?? ''))) {
                    throw new DomainException('Only an undeparted bag on a draft manifest can be unloaded, with its actual master tag, seal and reason.');
                }
                foreach ($bag->parcels()->whereNull('removed_at')->orderBy('delivery_id')->get() as $member) {
                    $manifestMember = $manifest->parcels()->where('delivery_id', $member->delivery_id)->where('included', true)->firstOrFail();
                    app(LogisticsManifestService::class)->command($manifest->fresh(), $actor, 'remove', ['version' => $manifest->fresh()->version,
                        'request_token' => (string) Str::uuid(), 'parcel_id' => $manifestMember->id, 'notes' => $data['notes']], $bag);
                }
                $bag->fill(['manifest_id' => null, 'loaded_at' => null, 'loaded_by_id' => null, 'master_barcode_scanned' => null]);
            } elseif ($action === 'open') {
                $this->masterTag($bag, $data);
                if ($bag->status !== 'in_transit' || empty($data['seal_intact']) || ! $bag->manifest_id
                    || ! in_array($bag->manifest?->status, ['dispatched', 'received', 'closed'], true)
                    || ! $bag->dispatched_at || ! $bag->manifest?->dispatched_at) {
                    throw new DomainException('Verify the arriving bag and intact seal before opening. Record a discrepancy for a broken or different seal.');
                }
                $bag->fill(['status' => 'opened', 'opened_by_id' => $actor->id, 'opened_at' => now()]);
            }
            $bag->version++;
            $bag->save();
            $payload = ['seal_number' => $data['seal_number'] ?? $bag->seal_number, 'notes' => $data['notes'] ?? null];
            if (in_array($action, ['seal', 'add', 'remove'], true)) {
                $payload['members'] = $this->members($bag);
            }
            $this->event($bag, $actor, $action, $data['barcode'] ?? null, $payload);
            if ($action === 'open') {
                $this->finishReceivedBag($bag);
            }
            if (! empty($data['request_token']) && app(CargoHandlingService::class)->available()) {
                DB::table('bag_packing_requests')->insert(['actor_id' => $actor->id, 'request_token' => $data['request_token'], 'fingerprint' => $fingerprint, 'mother_bag_id' => $bag->id]);
            }

            return $bag;
        });
    }

    public function assertManifest(LogisticsManifest $manifest, Collection $parcels): void
    {
        foreach ($parcels as $parcel) {
            if ($parcel->order->logistics_flow_version < 2 || ! in_array($manifest->direction, ['outbound', 'return'], true)) {
                continue;
            }
            $cargo = $manifest->parcels()->where('delivery_id', $parcel->id)->where('included', true)->first();
            if ($cargo?->cargo_mode === 'loose' && $cargo->handling_snapshot && ! $cargo->handling_snapshot['baggable']
                && ! MotherBagParcel::where('active_delivery_id', $parcel->id)->exists()) {
                continue;
            }
            $member = MotherBagParcel::where('active_delivery_id', $parcel->id)->with('bag')->first();
            if (! $member || $member->bag->manifest_id !== $manifest->id || $member->bag->status !== 'sealed'
                || $member->bag->source_hub_id !== $manifest->source_hub_id || $member->bag->destination_hub_id !== $manifest->destination_hub_id) {
                throw new DomainException('Pack version-two parcels into sealed Mother Bags and scan their master tags onto this manifest.');
            }
            $seal = DB::table('mother_bag_events')->where('mother_bag_id', $member->bag->id)->where('event_type', 'seal')->latest('id')->value('payload');
            if (! $seal || (json_decode($seal, true, flags: JSON_THROW_ON_ERROR)['members'] ?? null) !== $this->members($member->bag)) {
                throw new DomainException('The bag contents differ from its retained seal record. Recheck packing before departure.');
            }
        }
    }

    public function departed(LogisticsManifest $manifest, User $actor): void
    {
        foreach (MotherBag::where('manifest_id', $manifest->id)->where('status', 'sealed')->lockForUpdate()->get() as $bag) {
            $bag->update(['status' => 'in_transit', 'dispatched_at' => now(), 'version' => $bag->version + 1]);
            $this->event($bag, $actor, 'departed', $bag->master_barcode_scanned, ['manifest_reference' => $manifest->reference]);
        }
    }

    public function assertReceipt(LogisticsManifest $manifest, Delivery $parcel): void
    {
        if ($manifest->direction === 'recovery') {
            return;
        }
        $member = MotherBagParcel::where('active_delivery_id', $parcel->id)->with('bag')->first();
        if ($member && ($member->bag->manifest_id !== $manifest->id || ! $member->bag->opened_at || $member->bag->status !== 'opened')) {
            throw new DomainException('Verify and open this parcel’s Mother Bag seal before scanning individual receipts.');
        }
    }

    public function received(LogisticsManifest $manifest, Delivery $parcel, User $actor, string $barcode): void
    {
        if ($manifest->direction === 'recovery') {
            return;
        }
        $member = MotherBagParcel::where('active_delivery_id', $parcel->id)->lockForUpdate()->first();
        if (! $member) {
            return;
        }
        $bag = MotherBag::whereKey($member->mother_bag_id)->lockForUpdate()->firstOrFail();
        if ($bag->manifest_id !== $manifest->id) {
            throw new DomainException('The receiving record belongs to another Mother Bag manifest.');
        }
        $member->update(['active_delivery_id' => null, 'received_at' => now()]);
        $this->event($bag, $actor, 'parcel_received', $barcode, ['delivery_id' => $parcel->id]);
        $this->finishReceivedBag($bag);
    }

    public function extractForRecovery(ParcelRecoveryCase $case, Delivery $parcel, User $actor, ParcelRecoveryEvent $intake, DeliveryCheckpoint $checkpoint): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $member = MotherBagParcel::where('active_delivery_id', $parcel->id)->lockForUpdate()->first();
        if (! $member) {
            return;
        }
        abort_unless(Schema::hasTable('parcel_recovery_bag_extractions'), 409, 'Apply the additive parcel extraction migration before recovering this sealed bag member.');
        $bag = MotherBag::whereKey($member->mother_bag_id)->lockForUpdate()->firstOrFail();
        $original = $case->originalMember;
        if ($case->state !== 'held' || $case->active_delivery_id !== $parcel->id || $original->delivery_id !== $parcel->id
            || $bag->manifest_id !== $original->manifest_id || ! in_array($bag->status, ['in_transit', 'opened'], true)
            || $member->received_at || $member->removed_at || $intake->parcel_recovery_case_id !== $case->id
            || $intake->event_type !== 'intake' || $intake->actor_id !== $actor->id || $intake->hub_id !== $case->observed_hub_id
            || $intake->barcode_scanned !== $member->barcode_scanned || $intake->barcode_scanned !== $parcel->tracking_number
            || $checkpoint->delivery_id !== $parcel->id || $checkpoint->checkpoint_type !== 'parcel_recovery_intake'
            || $checkpoint->scanned_by_id !== $actor->id || $checkpoint->hub_id !== $intake->hub_id
            || $checkpoint->barcode_scanned !== $intake->barcode_scanned
            || ($checkpoint->custody_after['parcel_recovery_event_reference'] ?? null) !== $intake->reference
            || ($checkpoint->custody_after['parcel_recovery_reference'] ?? null) !== $case->reference) {
            throw new DomainException('Retain the original bag member and the actual authorized loose-parcel intake before extraction.');
        }
        DB::table('parcel_recovery_bag_extractions')->insert(['original_bag_member_id' => $member->id, 'recovery_id' => $case->id,
            'intake_event_id' => $intake->id, 'checkpoint_id' => $checkpoint->id, 'actor_id' => $actor->id, 'created_at' => now()]);
        $member->update(['active_delivery_id' => null]);
        $this->event($bag, $actor, 'parcel_extracted_for_recovery', $parcel->tracking_number,
            ['parcel_recovery_reference' => $case->reference, 'intake_reference' => $intake->reference, 'no_bag_arrival' => true]);
    }

    public function recovered(ParcelRecoveryCase $case, Delivery $parcel, User $actor, LogisticsManifestEvent $receipt): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $extraction = Schema::hasTable('parcel_recovery_bag_extractions')
            ? DB::table('parcel_recovery_bag_extractions')->where('recovery_id', $case->id)->first() : null;
        if (! $extraction) {
            return;
        }
        $member = MotherBagParcel::whereKey($extraction->original_bag_member_id)->lockForUpdate()->firstOrFail();
        $bag = MotherBag::whereKey($member->mother_bag_id)->lockForUpdate()->firstOrFail();
        $original = $case->originalMember->fresh();
        if ($case->state !== 'completed' || $case->active_delivery_id !== null || $member->delivery_id !== $parcel->id
            || $member->active_delivery_id !== null || $member->received_at || $bag->manifest_id !== $original->manifest_id
            || ! $original->received_at || $original->received_by_id !== $actor->id
            || $receipt->manifest_id !== $original->manifest_id || $receipt->manifest_parcel_id !== $original->id
            || $receipt->event_type !== 'parcel_received' || $receipt->actor_id !== $actor->id
            || $receipt->hub_id !== $case->expected_hub_id || $receipt->barcode_scanned !== $member->barcode_scanned
            || ($receipt->payload['parcel_recovery_reference'] ?? null) !== $case->reference) {
            throw new DomainException('Account for this extracted parcel only after its actual completed recovery and original expected receipt.');
        }
        $member->update(['received_at' => $original->received_at]);
        $this->event($bag, $actor, 'parcel_recovered', $parcel->tracking_number,
            ['parcel_recovery_reference' => $case->reference, 'original_receipt_reference' => $receipt->reference, 'no_bag_arrival' => true]);
        $this->finishReceivedBag($bag);
    }

    public function refreshLossAccounting(LogisticsManifest $manifest): void
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        foreach (MotherBag::where('manifest_id', $manifest->id)->where('status', 'opened')->lockForUpdate()->get() as $bag) {
            $this->finishReceivedBag($bag);
        }
    }

    private function finishReceivedBag(MotherBag $bag): void
    {
        $outstanding = $bag->parcels()->whereNull('removed_at')->whereNull('received_at')->get();
        if ($outstanding->isNotEmpty() && $bag->manifest_id) {
            $lost = app(ShipmentLossAccountingService::class)->members($bag->manifest)->keys();
            $deliveries = $bag->manifest->parcels()->whereIn('id', $lost)->pluck('delivery_id');
            $outstanding = $outstanding->reject(fn ($member) => $deliveries->contains($member->delivery_id));
        }
        if ($bag->status === 'opened' && $bag->opened_at && $outstanding->isEmpty()) {
            $bag->update(['status' => 'received', 'version' => $bag->version + 1,
                ...($bag->packing_policy_version ? ['active_physical_key' => null, 'automatic_lane' => null] : [])]);
        }
    }

    private function operator(User $actor, LogisticsHub $hub): void
    {
        abort_unless($actor->isLogistics() && app(LogisticsEligibilityService::class)->canScan($actor, $hub), 403, 'Use an active operator assigned to this physical hub.');
    }

    private function members(MotherBag $bag): array
    {
        return $bag->parcels()->whereNull('removed_at')->orderBy('delivery_id')->get(['delivery_id', 'barcode_scanned', 'source_state', ...($bag->packing_policy_version ? ['handling_snapshot'] : [])])->toArray();
    }

    private function masterTag(MotherBag $bag, array $data): void
    {
        if (! hash_equals($bag->reference, (string) ($data['barcode'] ?? '')) || ! hash_equals((string) $bag->seal_number, (string) ($data['seal_number'] ?? ''))) {
            throw new DomainException('The scanned master bag tag or numbered seal does not match this bag.');
        }
    }

    private function event(MotherBag $bag, User $actor, string $type, ?string $barcode = null, array $payload = []): void
    {
        DB::table('mother_bag_events')->insert(['mother_bag_id' => $bag->id, 'actor_id' => $actor->id,
            'event_type' => $type, 'barcode_scanned' => $barcode, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
}
