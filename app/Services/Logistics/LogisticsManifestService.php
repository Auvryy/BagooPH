<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LogisticsManifestService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility, private readonly WaybillScanInputService $inputs) {}

    public function readable(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        $query = LogisticsManifest::query();
        if (! $actor->canAccessPortal()) {
            throw new AuthorizationException('An active approved account is required to read manifests.');
        }
        if ($actor->isAdmin()) {
            return $query;
        }
        if (! $actor->isLogistics()) {
            throw new AuthorizationException('Manifest records belong to logistics operations.');
        }
        $company = LogisticsCompany::eligible()->where('user_id', $actor->id)->first();
        if ($company) {
            return $query->where('logistics_company_id', $company->id);
        }
        $hubIds = $this->eligibility->accessibleHubs($actor)->pluck('id');

        return $query->where(fn (Builder $scope) => $scope->whereIn('source_hub_id', $hubIds)->orWhereIn('destination_hub_id', $hubIds));
    }

    public function create(User $actor, array $input, ?string $issuedToken): LogisticsManifest
    {
        $actor = User::findOrFail($actor->id);
        $company = LogisticsCompany::eligible()->where('user_id', $actor->id)->first();
        if (! $actor->isLogistics() || ! $actor->canAccessPortal() || ! $company) {
            throw new AuthorizationException('Only the current company administrator may prepare its manifests.');
        }
        $input = Validator::make($input, [
            'source_hub_id' => [...$this->idRules(), 'exists:logistics_hubs,id'],
            'destination_hub_id' => [...$this->idRules(), 'exists:logistics_hubs,id'],
            'vehicle_id' => [...$this->idRules(), 'exists:logistics_fleet,id'],
            'creation_token' => ['required', 'string', 'uuid'],
        ])->validate();
        $fingerprint = $this->fingerprint($input);

        return DB::transaction(function () use ($actor, $company, $input, $issuedToken, $fingerprint) {
            $driverId = LogisticsFleet::whereKey($input['vehicle_id'])->value('assigned_driver_id');
            $this->eligibility->lockNetwork($company->id, [$actor->id, $driverId], [$input['source_hub_id'], $input['destination_hub_id']]);
            $actor = User::findOrFail($actor->id);
            if (! $this->eligibility->isCompanyAdministrator($actor, $company->id)) {
                throw new AuthorizationException('This company is not managed by the current account.');
            }
            $existing = LogisticsManifest::where('created_by_id', $actor->id)->where('creation_token', $input['creation_token'])->first();
            if ($existing) {
                if (! hash_equals($existing->creation_fingerprint, $fingerprint)) {
                    throw new DomainException('This creation request already prepared another manifest. Refresh before creating a new one.');
                }

                return $existing;
            }
            if (! is_string($issuedToken) || ! hash_equals($issuedToken, $input['creation_token'])) {
                throw ValidationException::withMessages(['creation_token' => 'Refresh the manifest form before creating it.']);
            }
            $source = LogisticsHub::findOrFail($input['source_hub_id']);
            $destination = LogisticsHub::findOrFail($input['destination_hub_id']);
            $type = $this->legType($source, $destination);
            $vehicle = $this->vehicle($company->id, $source->id, (int) $input['vehicle_id'], $driverId, newWork: true, legType: $type);
            $manifest = LogisticsManifest::create([
                'logistics_company_id' => $company->id, 'source_hub_id' => $source->id, 'destination_hub_id' => $destination->id,
                'vehicle_id' => $vehicle->id, 'driver_id' => $driverId, 'created_by_id' => $actor->id,
                'creation_token' => $input['creation_token'], 'creation_fingerprint' => $fingerprint, 'type' => $type,
                'status' => 'draft', 'version' => 1,
            ]);
            $this->event($manifest, $actor, 'created', [], $manifest->state(), ['vehicle_plate' => $vehicle->plate_number, 'vehicle_type' => $vehicle->vehicle_type], hubId: $source->id);

            return $manifest;
        });
    }

    public function command(LogisticsManifest $snapshot, User $actor, string $action, array $input): array
    {
        $snapshot = LogisticsManifest::findOrFail($snapshot->id);
        $actor = User::findOrFail($actor->id);
        if (! $actor->isLogistics() || ! $actor->canAccessPortal()) {
            throw new AuthorizationException('Only active logistics accounts may operate manifests.');
        }
        if (! $this->readable($actor)->whereKey($snapshot->id)->exists()) {
            throw new AuthorizationException('This manifest is outside your company or assigned facilities.');
        }
        $input = $this->inputs->normalize($input);
        $rules = ['version' => [...$this->idRules(), 'max:2147483647'], 'request_token' => ['required', 'string', 'uuid'],
            'notes' => $this->inputs->notesRules(), 'barcode' => $this->inputs->barcodeRules(in_array($action, ['load', 'receive'], true)),
            'parcel_id' => $action === 'remove' ? $this->idRules() : ['nullable', new AsciiPositiveInteger, 'integer'],
            'condition' => ['nullable', Rule::in(['intact', 'damaged'])],
            'kind' => ['nullable', Rule::in(['missing', 'extra', 'duplicate', 'damaged', 'wrong_hub'])],
            'source_event_id' => ['nullable', new AsciiPositiveInteger, 'integer', 'min:1']];
        $input = Validator::make($input, $rules)->validate() + ['notes' => null, 'barcode' => null, 'parcel_id' => null, 'condition' => null, 'kind' => null, 'source_event_id' => null];
        if (! in_array($action, ['load', 'remove', 'seal', 'reopen', 'dispatch', 'receive', 'close', 'report-discrepancy', 'correct-discrepancy', 'resolve-discrepancy'], true)) {
            throw ValidationException::withMessages(['action' => 'Choose a supported manifest action.']);
        }
        $candidate = in_array($action, ['load', 'receive'], true) ? $this->parcelForBarcode($input['barcode']) : null;
        $fingerprint = $this->fingerprint(['action' => $action, ...$input]);

        return DB::transaction(function () use ($snapshot, $actor, $action, $input, $candidate, $fingerprint) {
            $initialIds = $snapshot->parcels()->orderBy('delivery_id')->pluck('delivery_id')->all();
            $deliveryIds = collect([...$initialIds, $candidate?->id])->filter()->unique()->sort()->values();
            $orderIds = Delivery::whereIn('id', $deliveryIds)->pluck('order_id')->unique()->sort()->values();
            Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            $deliveries = Delivery::whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->eligibility->lockNetwork($snapshot->logistics_company_id, [$actor->id, $snapshot->driver_id], [$snapshot->source_hub_id, $snapshot->destination_hub_id]);
            $manifest = LogisticsManifest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($manifest->parcels()->orderBy('delivery_id')->pluck('delivery_id')->all() !== $initialIds) {
                throw new DomainException('The manifest parcel list changed. Refresh before continuing.');
            }
            $actor = User::findOrFail($actor->id);
            $this->authorizeCommand($manifest, $actor, $action);
            $original = $manifest->events()->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This request already recorded a different manifest action. Refresh before continuing.');
                }

                return ['manifest' => $manifest, 'event' => $original];
            }
            if ($manifest->version !== (int) $input['version']) {
                throw new DomainException('The manifest changed. Refresh its current list and state before continuing.');
            }
            $before = $manifest->state();
            $parcel = null;
            $payload = [];
            $hubId = $manifest->source_hub_id;
            switch ($action) {
                case 'load':
                    $this->requireStatus($manifest, 'draft');
                    $delivery = $deliveries->get($candidate->id);
                    if (! $delivery || $this->nextHub($delivery) !== $manifest->destination_hub_id || $delivery->current_hub_id !== $manifest->source_hub_id
                        || $delivery->logistics_company_id !== $manifest->logistics_company_id) {
                        throw new DomainException('This parcel is not expected on this source and destination route.');
                    }
                    if (LogisticsManifestParcel::where('active_delivery_id', $delivery->id)->exists()) {
                        throw new DomainException('This parcel already belongs to an active manifest.');
                    }
                    $parcel = $manifest->parcels()->where('delivery_id', $delivery->id)->first();
                    if ($parcel) {
                        $parcel->update(['included' => true, 'active_delivery_id' => $delivery->id]);
                    } else {
                        $parcel = $manifest->parcels()->create(['delivery_id' => $delivery->id, 'active_delivery_id' => $delivery->id,
                            'tracking_number_snapshot' => $delivery->tracking_number, 'route_snapshot' => $this->route($delivery),
                            'source_state' => DeliveryCheckpoint::state($delivery), 'included' => true]);
                    }
                    $payload = ['delivery_id' => $delivery->id, 'custody' => DeliveryCheckpoint::lastCustody($delivery)];
                    break;
                case 'remove':
                    $this->requireStatus($manifest, 'draft');
                    $this->requireNotes($input);
                    $parcel = $manifest->parcels()->whereKey($input['parcel_id'])->where('included', true)->first();
                    if (! $parcel) {
                        throw new DomainException('Choose a parcel currently included in this draft.');
                    }
                    $parcel->update(['included' => false, 'active_delivery_id' => null]);
                    break;
                case 'seal':
                    $this->requireStatus($manifest, 'draft');
                    $this->verifyList($manifest, $deliveries);
                    $manifest->fill(['status' => 'sealed', 'sealed_at' => now()]);
                    break;
                case 'reopen':
                    $this->requireStatus($manifest, 'sealed');
                    $this->requireNotes($input);
                    $manifest->fill(['status' => 'draft', 'sealed_at' => null]);
                    break;
                case 'dispatch':
                    $this->requireStatus($manifest, 'sealed');
                    $this->verifyList($manifest, $deliveries);
                    $vehicle = $this->vehicle($manifest->logistics_company_id, $manifest->source_hub_id, $manifest->vehicle_id, $manifest->driver_id, newWork: true, legType: $manifest->type);
                    if (LogisticsManifest::where('status', 'dispatched')->whereKeyNot($manifest->id)
                        ->where(fn ($query) => $query->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $manifest->driver_id))->exists()) {
                        throw new DomainException('This vehicle or driver already has a manifest in transit.');
                    }
                    $manifest->fill(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatcher_id' => $actor->id])->save();
                    foreach ($manifest->parcels()->where('included', true)->orderBy('delivery_id')->get() as $member) {
                        $delivery = $deliveries->get($member->delivery_id);
                        $load = $manifest->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'load')->latest('id')->firstOrFail();
                        $scan = $this->event($manifest, $actor, 'parcel_dispatched', DeliveryCheckpoint::state($delivery),
                            ['delivery_status' => $this->transitStatus($manifest)], ['source_scan_reference' => $load->reference, 'vehicle_plate' => $vehicle->plate_number, 'vehicle_type' => $vehicle->vehicle_type],
                            $member, $load->barcode_scanned, $manifest->source_hub_id);
                        $updated = app(OrderStateMachineService::class)->transition($delivery, $this->transitStatus($manifest), $actor, [
                            'hub_id' => $manifest->source_hub_id, 'barcode' => $load->barcode_scanned, 'manifest_scan_event_id' => $scan->id,
                            'manifest_number' => $manifest->reference, 'expected_status' => $delivery->status,
                            'notes' => $input['notes'] ?? 'Departed on the recorded manifest.',
                        ]);
                        $payload['parcels'][] = ['delivery_id' => $updated->id, 'scan_reference' => $scan->reference];
                    }
                    break;
                case 'receive':
                    $this->requireStatus($manifest, 'dispatched');
                    if (($input['condition'] ?? 'intact') !== 'intact') {
                        throw ValidationException::withMessages(['condition' => 'A damaged parcel requires its recorded discrepancy evidence.']);
                    }
                    $parcel = $manifest->parcels()->where('delivery_id', $candidate->id)->where('included', true)->first();
                    if (! $parcel) {
                        throw ValidationException::withMessages(['barcode' => 'This parcel is not on the arriving manifest. Record the discrepancy separately.']);
                    }
                    if ($parcel->received_at) {
                        throw new DomainException('This parcel already has its original receiving scan.');
                    }
                    $delivery = $deliveries->get($parcel->delivery_id);
                    if (! $delivery || $this->route($delivery) !== $parcel->route_snapshot) {
                        throw new DomainException('The parcel route changed and needs review before receipt.');
                    }
                    if ($this->openDiscrepancies($manifest)->contains(fn ($event) => $event->manifest_parcel_id === $parcel->id && $event->payload['kind'] === 'damaged')) {
                        $this->requireNotes($input);
                    }
                    $this->verifyRecordedLeg($manifest, $parcel);
                    $hubId = $manifest->destination_hub_id;
                    $parcel->update(['received_at' => now(), 'received_by_id' => $actor->id, 'receipt_condition' => 'intact']);
                    if (! $manifest->parcels()->where('included', true)->whereNull('received_at')->exists()) {
                        $manifest->fill(['status' => 'received', 'received_at' => now(), 'receiver_id' => $actor->id])->save();
                    }
                    $target = LogisticsHub::findOrFail($hubId)->isMotherHub()
                        ? OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB : OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB;
                    $scan = $this->event($manifest, $actor, 'parcel_received', DeliveryCheckpoint::state($delivery),
                        ['delivery_status' => $target], [], $parcel, $input['barcode'], $hubId, $input['notes']);
                    app(OrderStateMachineService::class)->transition($delivery, $target, $actor, [
                        'hub_id' => $hubId, 'barcode' => $input['barcode'], 'manifest_scan_event_id' => $scan->id,
                        'manifest_number' => $manifest->reference, 'expected_status' => $delivery->status,
                        'notes' => $input['notes'] ?? 'Received at the recorded destination hub.',
                    ]);
                    $payload = ['physical_scan_reference' => $scan->reference];
                    break;
                case 'report-discrepancy':
                    $this->requireArriving($manifest);
                    $this->requireNotes($input);
                    Validator::make($input, ['kind' => ['required'], 'barcode' => $this->inputs->barcodeRules($input['kind'] !== 'missing'),
                        'parcel_id' => $input['kind'] === 'missing' ? $this->idRules() : ['nullable']])->validate();
                    $hubId = $manifest->destination_hub_id;
                    if ($input['kind'] === 'missing') {
                        if ($input['barcode'] !== null) {
                            throw ValidationException::withMessages(['barcode' => 'A missing parcel has no receiving scan. Choose its expected list entry.']);
                        }
                        $parcel = $manifest->parcels()->whereKey($input['parcel_id'])->where('included', true)->whereNull('received_at')->first();
                    } elseif (in_array($input['kind'], ['damaged', 'duplicate'], true)) {
                        $parcel = $manifest->parcels()->where('tracking_number_snapshot', $input['barcode'])->where('included', true)->first();
                        if ($parcel && (($input['kind'] === 'duplicate') !== (bool) $parcel->received_at)) {
                            throw new DomainException('This observation does not match the original parcel receipt.');
                        }
                    } else {
                        if ($manifest->parcels()->where('tracking_number_snapshot', $input['barcode'])->where('included', true)->exists()) {
                            throw new DomainException('This waybill is already expected on this arriving list.');
                        }
                    }
                    if (in_array($input['kind'], ['missing', 'damaged', 'duplicate'], true) && ! $parcel) {
                        throw ValidationException::withMessages(['parcel_id' => 'Choose the actual included parcel for this observation.']);
                    }
                    if ($this->openDiscrepancies($manifest)->contains(fn ($event) => $event->manifest_parcel_id === $parcel?->id
                        && $event->payload['kind'] === $input['kind'] && $event->barcode_scanned === $input['barcode'])) {
                        throw new DomainException('This discrepancy already has an unresolved original record.');
                    }
                    $payload = ['kind' => $input['kind']];
                    // Observations do not change parcel state or prove a physical handoff.
                    break;
                case 'correct-discrepancy':
                    $this->requireArriving($manifest);
                    $this->requireNotes($input);
                    Validator::make($input, ['barcode' => $this->inputs->barcodeRules(true)])->validate();
                    $source = $this->discrepancy($manifest, $input);
                    if (! in_array($source->payload['kind'], ['extra', 'wrong_hub'], true)) {
                        throw new DomainException('Only an observed waybill recording error uses a correction scan.');
                    }
                    $parcel = $manifest->parcels()->where('tracking_number_snapshot', $input['barcode'])->where('included', true)->whereNotNull('received_at')->first();
                    if (! $parcel) {
                        throw new DomainException('A correction requires the actual correctly identified parcel and its receiving record. Extra parcels remain unresolved until supported handling exists.');
                    }
                    $hubId = $manifest->destination_hub_id;
                    $payload = ['source_event_id' => $source->id, 'kind' => 'waybill_recording_error'];
                    break;
                case 'resolve-discrepancy':
                    $this->requireArriving($manifest);
                    $this->requireNotes($input);
                    $source = $this->discrepancy($manifest, $input);
                    $parcel = $source->manifest_parcel_id ? $manifest->parcels()->findOrFail($source->manifest_parcel_id) : null;
                    $support = null;
                    if ($parcel?->received_at) {
                        $support = $manifest->events()->where('manifest_parcel_id', $parcel->id)->where('event_type', 'parcel_received')->first();
                        if ($source->payload['kind'] !== 'duplicate' && $support?->id <= $source->id) {
                            $support = null;
                        }
                    } elseif (! $parcel) {
                        $correction = $manifest->events()->where('event_type', 'correct-discrepancy')->get()
                            ->first(fn ($event) => ($event->payload['source_event_id'] ?? null) === $source->id);
                        if ($correction) {
                            $parcel = $manifest->parcels()->whereKey($correction->manifest_parcel_id)->whereNotNull('received_at')->first();
                            $support = $parcel ? $correction : null;
                        }
                    }
                    if (! $support) {
                        throw new DomainException('Resolution requires the actual supporting receipt or handler correction. A reason alone cannot resolve missing custody.');
                    }
                    $hubId = $manifest->destination_hub_id;
                    $payload = ['source_event_id' => $source->id, 'support_event_id' => $support->id, 'support_reference' => $support->reference];
                    break;
                case 'close':
                    $this->requireStatus($manifest, 'received');
                    if ($manifest->parcels()->where('included', true)->whereNull('received_at')->exists()) {
                        throw new DomainException('Every included parcel needs its actual receiving scan before closure.');
                    }
                    if ($this->openDiscrepancies($manifest)->isNotEmpty()) {
                        throw new DomainException('Resolve every recorded discrepancy with supporting evidence before closure.');
                    }
                    foreach ($manifest->parcels()->where('included', true)->get() as $member) {
                        $receipt = $manifest->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'parcel_received')->first();
                        if (! $receipt || $receipt->actor_id !== $member->received_by_id || $receipt->hub_id !== $manifest->destination_hub_id
                            || ! DeliveryCheckpoint::where('delivery_id', $member->delivery_id)->where('manifest_number', $manifest->reference)
                                ->get()->contains(fn ($checkpoint) => ($checkpoint->custody_after['manifest_scan_reference'] ?? null) === $receipt->reference)) {
                            throw new DomainException('The original receiving event and custody checkpoint must support every parcel receipt before closure.');
                        }
                    }
                    $manifest->fill(['status' => 'closed', 'closed_at' => now()]);
                    foreach ($manifest->parcels()->where('included', true)->get() as $member) {
                        $member->update(['active_delivery_id' => null]);
                    }
                    break;
            }
            $manifest->version++;
            $manifest->save();
            $record = $this->event($manifest, $actor, $action, $before, $manifest->state(), $payload, $parcel,
                $input['barcode'] ?? null, $hubId, $input['notes'] ?? null, $input['request_token'], $fingerprint);

            return ['manifest' => $manifest, 'event' => $record];
        });
    }

    public function nextHub(Delivery $delivery): int
    {
        $delivery->loadMissing('order');
        $custody = DeliveryCheckpoint::lastCustody($delivery);
        $source = LogisticsHub::eligible()->where('logistics_company_id', $delivery->logistics_company_id)->find($delivery->current_hub_id);
        if (in_array($delivery->order->status, ['cancelled', 'completed', 'returned'], true)
            || ! $source || ($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $delivery->current_hub_id) {
            throw new DomainException('Recorded source-hub custody is required before manifest loading.');
        }
        if ($delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB && $delivery->current_hub_id === $delivery->origin_bayan_hub_id && $source->tier === 'local_bayan_hub') {
            return $this->expectedHub($delivery, $delivery->origin_mother_hub_id, 'regional_mother_hub');
        }
        if ($delivery->status === OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL && $source->isMotherHub()) {
            if (! LogisticsManifestEvent::where('event_type', 'parcel_received')->where('hub_id', $source->id)
                ->whereHas('parcel', fn ($query) => $query->where('delivery_id', $delivery->id))->exists()) {
                throw new DomainException('The Mother Hub needs its actual manifest receipt before further departure.');
            }
            if ($delivery->current_hub_id === $delivery->origin_mother_hub_id) {
                return $delivery->destination_mother_hub_id !== $delivery->origin_mother_hub_id
                    ? $this->expectedHub($delivery, $delivery->destination_mother_hub_id, 'regional_mother_hub')
                    : $this->expectedHub($delivery, $delivery->destination_bayan_hub_id, 'local_bayan_hub');
            }
            if ($delivery->current_hub_id === $delivery->destination_mother_hub_id) {
                return $this->expectedHub($delivery, $delivery->destination_bayan_hub_id, 'local_bayan_hub');
            }
        }

        throw new DomainException('The parcel must complete its expected inbound receipt and Mother Hub sorting before departure.');
    }

    private function expectedHub(Delivery $delivery, ?int $id, string $tier): int
    {
        if (! $id || ! LogisticsHub::eligible()->whereKey($id)->where('logistics_company_id', $delivery->logistics_company_id)->where('tier', $tier)->exists()) {
            throw new DomainException('The next required facility no longer has its active route scope and hub tier.');
        }

        return $id;
    }

    public function transitStatus(LogisticsManifest $manifest): string
    {
        return LogisticsHub::findOrFail($manifest->destination_hub_id)->isMotherHub()
            ? OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB : OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB;
    }

    public function route(Delivery $delivery): array
    {
        return $delivery->only(['order_id', 'logistics_company_id', 'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id']);
    }

    private function authorizeCommand(LogisticsManifest $manifest, User $actor, string $action): void
    {
        if (in_array($action, ['remove', 'seal', 'reopen', 'close', 'resolve-discrepancy'], true)) {
            if (! $this->eligibility->isCompanyAdministrator($actor, $manifest->logistics_company_id)) {
                throw new AuthorizationException('Only this company administrator may manage its manifest list and closure.');
            }
        } else {
            $hubId = in_array($action, ['receive', 'report-discrepancy', 'correct-discrepancy'], true) ? $manifest->destination_hub_id : $manifest->source_hub_id;
            $hub = LogisticsHub::findOrFail($hubId);
            if (! $this->eligibility->canScan($actor, $hub)) {
                throw new AuthorizationException('An active assignment to the actual scan facility is required.');
            }
        }
    }

    public function openDiscrepancies(LogisticsManifest $manifest): Collection
    {
        $resolved = $manifest->events()->where('event_type', 'resolve-discrepancy')->get()->pluck('payload.source_event_id');

        return $manifest->events()->where('event_type', 'report-discrepancy')->orderBy('id')->get()
            ->reject(fn ($event) => $resolved->contains($event->id))->values();
    }

    private function requireArriving(LogisticsManifest $manifest): void
    {
        if (! in_array($manifest->status, ['dispatched', 'received'], true)) {
            throw new DomainException('Discrepancy handling requires an arriving or received manifest.');
        }
    }

    private function discrepancy(LogisticsManifest $manifest, array $input): LogisticsManifestEvent
    {
        Validator::make($input, ['source_event_id' => $this->idRules()])->validate();
        $source = $this->openDiscrepancies($manifest)->firstWhere('id', (int) $input['source_event_id']);
        if (! $source) {
            throw new DomainException('Choose an unresolved discrepancy on this manifest.');
        }

        return $source;
    }

    private function verifyList(LogisticsManifest $manifest, $deliveries): void
    {
        $parcels = $manifest->parcels()->where('included', true)->get();
        if ($parcels->isEmpty()) {
            throw new DomainException('Scan at least one actual parcel before sealing or dispatching.');
        }
        foreach ($parcels as $parcel) {
            $delivery = $deliveries->get($parcel->delivery_id);
            if (! $delivery || $parcel->active_delivery_id !== $delivery->id || $delivery->current_hub_id !== $manifest->source_hub_id
                || $this->route($delivery) !== $parcel->route_snapshot || $this->nextHub($delivery) !== $manifest->destination_hub_id
                || ! $manifest->events()->where('manifest_parcel_id', $parcel->id)->where('event_type', 'load')->exists()) {
                throw new DomainException('The current parcel route, source custody or outbound scan changed. Review the draft.');
            }
        }
    }

    private function verifyRecordedLeg(LogisticsManifest $manifest, LogisticsManifestParcel $parcel): void
    {
        $route = $parcel->route_snapshot;
        $sourceTier = ($parcel->source_state['delivery_status'] ?? null) === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB
            ? 'local_bayan_hub' : 'regional_mother_hub';
        $destinationTier = in_array($manifest->destination_hub_id, [$route['origin_mother_hub_id'], $route['destination_mother_hub_id']], true)
            ? 'regional_mother_hub' : 'local_bayan_hub';
        if ($manifest->sourceHub->tier !== $sourceTier || $manifest->destinationHub->tier !== $destinationTier
            || $this->legType($manifest->sourceHub, $manifest->destinationHub) !== $manifest->type) {
            throw new DomainException('The recorded transport leg no longer matches the required Bayan and Mother Hub facility types.');
        }
    }

    private function vehicle(int $companyId, int $hubId, int $vehicleId, ?int $driverId, bool $newWork, string $legType): LogisticsFleet
    {
        $profile = $driverId ? CourierProfile::where('user_id', $driverId)->lockForUpdate()->first() : null;
        $vehicle = LogisticsFleet::whereKey($vehicleId)->lockForUpdate()->first();
        if (! $vehicle || ! $driverId || $vehicle->assigned_driver_id !== $driverId || $vehicle->logistics_company_id !== $companyId
            || $vehicle->hub_id !== $hubId || ! LogisticsFleet::ready()->whereKey($vehicleId)->exists() || $profile?->vehicle_id !== $vehicleId
            || $vehicle->vehicle_type !== ($legType === 'line_haul' ? 'wing_truck' : 'l300_van')) {
            throw new DomainException('Choose an active source-hub closed van for feeder transport or wing truck for line-haul, with its current responsible driver.');
        }
        $this->eligibility->assertCourierScope($profile, $companyId, $hubId, newWork: $newWork);
        if ($newWork && Delivery::riderHasActiveWork($driverId)) {
            throw new DomainException('This driver must finish the existing pickup or final-mile work before accepting manifest transport.');
        }

        return $vehicle;
    }

    private function legType(LogisticsHub $source, LogisticsHub $destination): string
    {
        if ($source->id === $destination->id || $source->logistics_company_id !== $destination->logistics_company_id
            || (! $source->isMotherHub() && ! $destination->isMotherHub())) {
            throw new DomainException('A manifest needs distinct same-company hubs and a Mother Hub on its leg.');
        }

        return $source->isMotherHub() && $destination->isMotherHub() ? 'line_haul' : 'feeder';
    }

    private function parcelForBarcode(string $barcode): Delivery
    {
        $matches = Delivery::where('tracking_number', $barcode)->orWhereHas('order', fn ($query) => $query->where('order_number', $barcode))->limit(2)->get();
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['barcode' => 'Scan a unique recorded parcel waybill.']);
        }

        return $matches->sole();
    }

    private function idRules(): array
    {
        return ['bail', 'required', new AsciiPositiveInteger, 'integer', 'min:1'];
    }

    private function requireStatus(LogisticsManifest $manifest, string $status): void
    {
        if ($manifest->status !== $status) {
            throw new DomainException("The manifest must be {$status} for this action.");
        }
    }

    private function requireNotes(array $input): void
    {
        Validator::make($input, ['notes' => ['required', ...$this->inputs->notesRules()]])->validate();
    }

    private function fingerprint(array $input): string
    {
        foreach (['source_hub_id', 'destination_hub_id', 'vehicle_id', 'version', 'parcel_id', 'source_event_id'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = (int) $input[$key];
            }
        }
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function event(LogisticsManifest $manifest, User $actor, string $type, array $source, array $target, array $payload = [],
        ?LogisticsManifestParcel $parcel = null, ?string $barcode = null, ?int $hubId = null, ?string $reason = null, ?string $requestToken = null, ?string $requestFingerprint = null): LogisticsManifestEvent
    {
        return LogisticsManifestEvent::create(['manifest_id' => $manifest->id, 'manifest_parcel_id' => $parcel?->id, 'actor_id' => $actor->id,
            'hub_id' => $hubId, 'event_type' => $type, 'barcode_scanned' => $barcode, 'reason' => $reason,
            'source_state' => $source, 'target_state' => $target, 'payload' => $payload, 'request_token' => $requestToken, 'request_fingerprint' => $requestFingerprint]);
    }
}
