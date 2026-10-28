<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MotherBagService
{
    public function readable(User $actor): Builder
    {
        $hubs = app(LogisticsEligibilityService::class)->accessibleHubs($actor)->pluck('id');

        return MotherBag::query()->where(fn ($q) => $q->whereIn('source_hub_id', $hubs)->orWhereIn('destination_hub_id', $hubs));
    }

    public function create(User $actor, array $input): MotherBag
    {
        $data = Validator::make($input, [
            'source_hub_id' => 'required|integer|min:1', 'destination_hub_id' => 'required|integer|min:1|different:source_hub_id',
            'creation_token' => 'required|uuid',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $source = LogisticsHub::eligible()->findOrFail($data['source_hub_id']);
            $destination = LogisticsHub::eligible()->where('logistics_company_id', $source->logistics_company_id)->findOrFail($data['destination_hub_id']);
            app(LogisticsEligibilityService::class)->lockNetwork($source->logistics_company_id, [$actor->id], [$source->id, $destination->id]);
            $this->operator($actor, $source);
            app(LogisticsManifestService::class)->legType($source, $destination);
            $existing = MotherBag::where('created_by_id', $actor->id)->where('creation_token', $data['creation_token'])->first();
            if ($existing) {
                if ($existing->source_hub_id !== $source->id || $existing->destination_hub_id !== $destination->id) {
                    throw new DomainException('This bag request was already used for another route. Refresh before creating another bag.');
                }

                return $existing;
            }
            $bag = MotherBag::create(['reference' => 'BAG-'.strtoupper(Str::random(12)),
                'logistics_company_id' => $source->logistics_company_id, 'source_hub_id' => $source->id,
                'destination_hub_id' => $destination->id, 'created_by_id' => $actor->id, 'creation_token' => $data['creation_token']]);
            $this->event($bag, $actor, 'created');

            return $bag;
        });
    }

    public function command(MotherBag $snapshot, User $actor, string $action, array $input): MotherBag
    {
        abort_unless($this->readable($actor)->whereKey($snapshot->id)->exists(), 403);
        $data = Validator::make(app(WaybillScanInputService::class)->normalize($input), [
            'version' => 'required|integer|min:1',
            'barcode' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'seal_number' => ['nullable', 'string', 'max:80', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'manifest_id' => 'nullable|integer|min:1', 'notes' => app(WaybillScanInputService::class)->notesRules(), 'seal_intact' => 'sometimes|boolean',
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
            $hub = LogisticsHub::eligible()->findOrFail($action === 'open' ? $bag->destination_hub_id : $bag->source_hub_id);
            $this->operator($actor, $hub);
            if (in_array($action, ['load', 'unload', 'open'], true)) {
                $this->masterTag($bag, $data);
            }
            if ($action === 'add' && $candidate && $bag->parcels()->where('active_delivery_id', $candidate->id)->exists()) {
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
                    $bag->parcels()->updateOrCreate(['delivery_id' => $parcel->id], ['active_delivery_id' => $parcel->id,
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
                if (DB::table('mother_bag_seals')->where('logistics_company_id', $bag->logistics_company_id)->where('seal_number', $data['seal_number'])->exists()) {
                    throw new DomainException('This numbered seal was already used. Attach a new seal.');
                }
                DB::table('mother_bag_seals')->insert(['mother_bag_id' => $bag->id, 'logistics_company_id' => $bag->logistics_company_id, 'seal_number' => $data['seal_number']]);
                $bag->fill(['status' => 'sealed', 'seal_number' => $data['seal_number'], 'sealed_by_id' => $actor->id, 'sealed_at' => now()]);
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
                    || ! in_array($bag->manifest?->status, ['dispatched', 'received'], true)) {
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

            return $bag;
        });
    }

    public function assertManifest(LogisticsManifest $manifest, Collection $parcels): void
    {
        foreach ($parcels as $parcel) {
            if ($parcel->order->logistics_flow_version < 2 || ! in_array($manifest->direction, ['outbound', 'return'], true)) {
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
        if (! $bag->parcels()->whereNull('removed_at')->whereNull('received_at')->exists()) {
            $bag->update(['status' => 'received', 'version' => $bag->version + 1]);
        }
    }

    private function operator(User $actor, LogisticsHub $hub): void
    {
        abort_unless($actor->isLogistics() && app(LogisticsEligibilityService::class)->canScan($actor, $hub), 403, 'Use an active operator assigned to this physical hub.');
    }

    private function members(MotherBag $bag): array
    {
        return $bag->parcels()->whereNull('removed_at')->orderBy('delivery_id')->get(['delivery_id', 'barcode_scanned', 'source_state'])->toArray();
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
