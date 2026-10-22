<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnManifestParcel;
use App\Models\BuyerReturnManifestRoute;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\WaybillScanInputService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BuyerReturnManifestService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility, private readonly LogisticsManifestService $manifests) {}

    public function create(User $actor, array $input, ?string $issuedToken): LogisticsManifest
    {
        $actor = User::findOrFail($actor->id);
        Validator::make($input, ['source_hub_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1']])->validate();
        $source = LogisticsHub::eligible()->find($input['source_hub_id']);
        abort_unless($source && $this->manifests->canManageAtHub($actor, $source), 403);
        $company = LogisticsCompany::eligible()->findOrFail($source->logistics_company_id);
        $data = Validator::make($input, ['source_hub_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'destination_hub_id' => ['required', new AsciiPositiveInteger, 'integer'], 'vehicle_id' => ['required', new AsciiPositiveInteger, 'integer'],
            'buyer_return_case_id' => ['required', new AsciiPositiveInteger, 'integer'], 'creation_token' => ['required', 'uuid'],
            'direction' => ['required', 'in:buyer_return'],
        ])->validate();
        foreach (['source_hub_id', 'destination_hub_id', 'vehicle_id', 'buyer_return_case_id'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        $fingerprint = $this->fingerprint($data);

        return DB::transaction(function () use ($actor, $company, $data, $issuedToken, $fingerprint) {
            $case = BuyerReturnCase::findOrFail($data['buyer_return_case_id']);
            Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
            $driverId = LogisticsFleet::whereKey($data['vehicle_id'])->value('assigned_driver_id');
            $this->eligibility->lockNetwork($company->id, [$actor->id, $driverId], [$data['source_hub_id'], $data['destination_hub_id']]);
            $actor = User::findOrFail($actor->id);
            abort_unless($this->manifests->canManageAtHub($actor, LogisticsHub::findOrFail($data['source_hub_id'])), 403);
            $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $parcel = BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
            abort_unless($parcel->logistics_company_id === $company->id, 403);
            $existing = LogisticsManifest::where('created_by_id', $actor->id)->where('creation_token', $data['creation_token'])->first();
            if ($existing) {
                if ($existing->direction !== 'buyer_return' || ! hash_equals($existing->creation_fingerprint, $fingerprint)) {
                    throw new DomainException('This creation request already prepared different transport. Continue its original manifest or reload the form.');
                }

                return $existing;
            }
            if (! is_string($issuedToken) || ! hash_equals($issuedToken, $data['creation_token'])) {
                throw ValidationException::withMessages(['creation_token' => 'Reload the manifest form before preparing this transfer.']);
            }
            app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
            if ($parcel->status !== 'at_hub' || ! in_array($case->status, ['at_destination_hub', 'in_transit'], true)
                || $parcel->current_hub_id !== $data['source_hub_id'] || ($parcel->custody['kind'] ?? null) !== 'hub'
                || ($parcel->custody['hub_id'] ?? null) !== $data['source_hub_id']
                || ($parcel->hub_ids[$parcel->route_position + 1] ?? null) !== $data['destination_hub_id']) {
                throw new DomainException('Prepare only the next original reverse leg from the actual recorded hub holder.');
            }
            if (BuyerReturnManifestRoute::where('buyer_return_case_id', $case->id)->where('leg_index', $parcel->route_position)->exists()) {
                throw new DomainException('This return leg already has a retained manifest. Continue that original transfer.');
            }
            $source = LogisticsHub::findOrFail($data['source_hub_id']);
            $destination = LogisticsHub::findOrFail($data['destination_hub_id']);
            $type = $this->manifests->legType($source, $destination);
            $vehicle = $this->manifests->vehicle($company->id, $source->id, $data['vehicle_id'], $driverId, true, $type);
            $this->capacity($vehicle, $parcel);
            $manifest = LogisticsManifest::create(['logistics_company_id' => $company->id, 'source_hub_id' => $source->id,
                'destination_hub_id' => $destination->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driverId, 'created_by_id' => $actor->id,
                'creation_token' => $data['creation_token'], 'creation_fingerprint' => $fingerprint, 'direction' => 'buyer_return', 'type' => $type,
                'status' => 'draft', 'version' => 1]);
            BuyerReturnManifestRoute::create(['manifest_id' => $manifest->id, 'buyer_return_case_id' => $case->id,
                'leg_index' => $parcel->route_position, 'created_at' => now()]);
            $this->record($manifest, $actor, 'created', [], $manifest->state(), ['buyer_return_case_id' => $case->id, 'return_barcode' => $parcel->barcode], null, null, $source->id);

            return $manifest;
        }, 3);
    }

    public function command(LogisticsManifest $snapshot, User $actor, string $action, array $input): array
    {
        $input = app(WaybillScanInputService::class)->normalize($input);
        $data = Validator::make(array_replace($input, ['action' => $action]), [
            'action' => ['required', Rule::in(['load', 'remove', 'seal', 'reopen', 'dispatch', 'receive', 'close', 'report-discrepancy', 'correct-discrepancy', 'resolve-discrepancy'])],
            'version' => ['required', new AsciiPositiveInteger, 'integer', 'max:2147483647'], 'request_token' => ['required', 'uuid'],
            'barcode' => app(WaybillScanInputService::class)->barcodeRules(in_array($action, ['load', 'receive'], true)),
            'notes' => ['nullable', new ApplicationText('notes', 1, 1000)], 'condition' => ['nullable', Rule::in(['intact', 'damaged'])],
            'parcel_id' => ['nullable', new AsciiPositiveInteger, 'integer'], 'source_event_id' => ['nullable', new AsciiPositiveInteger, 'integer'],
            'kind' => ['nullable', Rule::in(['missing', 'extra', 'duplicate', 'damaged', 'wrong_hub'])],
        ])->validate();
        foreach (['version', 'parcel_id', 'source_event_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        $fingerprint = $this->fingerprint($data);

        return DB::transaction(function () use ($snapshot, $actor, $action, $data, $fingerprint) {
            $case = BuyerReturnCase::findOrFail($snapshot->buyer_return_case_id);
            Order::whereKey($case->order_id)->lockForUpdate()->firstOrFail();
            Delivery::whereKey($case->delivery_id)->lockForUpdate()->firstOrFail();
            $this->eligibility->lockNetwork($snapshot->logistics_company_id, [$actor->id, $snapshot->driver_id], [$snapshot->source_hub_id, $snapshot->destination_hub_id]);
            $actor = User::findOrFail($actor->id);
            $case = BuyerReturnCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $parcel = BuyerReturnParcel::where('buyer_return_case_id', $case->id)->lockForUpdate()->firstOrFail();
            $manifest = LogisticsManifest::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $manifest->direction === 'buyer_return'
                && $manifest->buyer_return_case_id === $case->id && $parcel->logistics_company_id === $manifest->logistics_company_id, 403);
            $manager = in_array($action, ['remove', 'seal', 'reopen', 'close', 'resolve-discrepancy'], true);
            $hubId = in_array($action, ['receive', 'close', 'report-discrepancy', 'correct-discrepancy', 'resolve-discrepancy'], true) ? $manifest->destination_hub_id : $manifest->source_hub_id;
            abort_unless($manager ? $this->manifests->canManageAtHub($actor, LogisticsHub::findOrFail($hubId))
                : $this->eligibility->canScan($actor, LogisticsHub::findOrFail($hubId)), 403);
            $prior = $manifest->events()->where('request_token', $data['request_token'])->first();
            if ($prior) {
                if ($prior->actor_id !== $actor->id || ! hash_equals($prior->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This scan request already recorded different evidence. Reload the retained manifest before continuing.');
                }

                return ['manifest' => $manifest, 'event' => $prior];
            }
            if ($manifest->version !== $data['version']) {
                throw new DomainException('This manifest changed. Reload its current list and receipts.');
            }
            app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
            $before = $manifest->state();
            $parcelBefore = $parcel->state();
            $member = $manifest->buyerReturnParcels()->lockForUpdate()->first();
            $payload = ['buyer_return_case_id' => $case->id, 'return_parcel_id' => $parcel->id];
            $scan = null;
            if (in_array($action, ['load', 'remove', 'seal', 'reopen', 'dispatch'], true) && $this->manifests->openDiscrepancies($manifest)->isNotEmpty()) {
                throw new DomainException('Resolve the actual recorded discrepancy before changing the list or departing.');
            }
            switch ($action) {
                case 'load':
                    $this->status($manifest, 'draft');
                    $this->route($manifest, $case, $parcel);
                    $this->barcode($parcel, $data);
                    if ($parcel->status !== 'at_hub' || $parcel->current_hub_id !== $manifest->source_hub_id || ($parcel->custody['kind'] ?? null) !== 'hub') {
                        throw new DomainException('This return must be physically recorded at the source hub before its load scan.');
                    }
                    if (BuyerReturnManifestParcel::where('active_return_parcel_id', $parcel->id)->where('manifest_id', '!=', $manifest->id)->exists()) {
                        throw new DomainException('This return already belongs to another active manifest.');
                    }
                    if ($member) {
                        $member->update(['included' => true, 'active_return_parcel_id' => $parcel->id]);
                    } else {
                        $member = $manifest->buyerReturnParcels()->create(['buyer_return_parcel_id' => $parcel->id, 'active_return_parcel_id' => $parcel->id,
                            'barcode_snapshot' => $parcel->barcode, 'leg_index' => $parcel->route_position, 'source_state' => $parcel->state(), 'included' => true]);
                    }
                    $payload['return_member_id'] = $member->id;
                    break;
                case 'remove':
                    $this->status($manifest, 'draft');
                    $this->notes($data);
                    if (! $member?->included || ($data['parcel_id'] ?? null) !== $member->id) {
                        throw new DomainException('Choose this draft’s actual included return parcel.');
                    }
                    $member->update(['included' => false, 'active_return_parcel_id' => null]);
                    break;
                case 'seal':
                    $this->status($manifest, 'draft');
                    $this->ready($manifest, $case, $parcel, $member);
                    $manifest->fill(['status' => 'sealed', 'sealed_at' => now()]);
                    break;
                case 'reopen':
                    $this->status($manifest, 'sealed');
                    $this->notes($data);
                    $manifest->fill(['status' => 'draft', 'sealed_at' => null]);
                    break;
                case 'dispatch':
                    $this->status($manifest, 'sealed');
                    $this->ready($manifest, $case, $parcel, $member);
                    $vehicle = $this->manifests->vehicle($manifest->logistics_company_id, $manifest->source_hub_id, $manifest->vehicle_id, $manifest->driver_id, true, $manifest->type);
                    $this->capacity($vehicle, $parcel);
                    if (LogisticsManifest::where('status', 'dispatched')->whereKeyNot($manifest->id)
                        ->where(fn ($query) => $query->where('vehicle_id', $vehicle->id)->orWhere('driver_id', $manifest->driver_id))->exists()) {
                        throw new DomainException('This driver or vehicle already has transport in progress.');
                    }
                    $manifest->fill(['status' => 'dispatched', 'dispatched_at' => now(), 'dispatcher_id' => $actor->id]);
                    $parcel->fill(['status' => 'in_transit', 'current_hub_id' => null,
                        'custody' => ['kind' => 'manifest', 'manifest_id' => $manifest->id, 'driver_id' => $manifest->driver_id]]);
                    $payload += ['return_member_id' => $member->id, 'weight_grams' => $parcel->weight_grams];
                    break;
                case 'receive':
                    $this->status($manifest, 'dispatched');
                    $this->barcode($parcel, $data);
                    $this->route($manifest, $case, $parcel);
                    if (! $member?->included || $member->received_at || $member->active_return_parcel_id !== $parcel->id
                        || $parcel->status !== 'in_transit' || ($parcel->custody['manifest_id'] ?? null) !== $manifest->id
                        || $actor->id === $manifest->dispatcher_id) {
                        throw new DomainException('The assigned destination handler must record this dispatched return’s actual first receipt.');
                    }
                    $condition = $data['condition'] ?? 'intact';
                    $member->fill(['received_at' => now(), 'received_by_id' => $actor->id, 'receipt_condition' => $condition, 'active_return_parcel_id' => null])->save();
                    $manifest->fill(['status' => 'received', 'received_at' => now(), 'receiver_id' => $actor->id]);
                    $parcel->fill(['status' => 'at_hub', 'route_position' => $parcel->route_position + 1, 'current_hub_id' => $manifest->destination_hub_id,
                        'custody' => ['kind' => 'hub', 'hub_id' => $manifest->destination_hub_id, 'received_by_id' => $actor->id, 'manifest_id' => $manifest->id]]);
                    $payload += ['return_member_id' => $member->id, 'condition' => $condition];
                    break;
                case 'close':
                    $this->status($manifest, 'received');
                    if (! $member?->received_at || $this->manifests->openDiscrepancies($manifest)->isNotEmpty()) {
                        throw new DomainException('Record the actual expected receipt and resolve any discrepancy before closing.');
                    }
                    $manifest->fill(['status' => 'closed', 'closed_at' => now()]);
                    break;
                case 'report-discrepancy':
                    if (! in_array($manifest->status, ['dispatched', 'received'], true)) {
                        throw new DomainException('Arrival discrepancies require the actual retained departure.');
                    }
                    $this->notes($data);
                    if (! isset($data['kind'])) {
                        throw ValidationException::withMessages(['kind' => 'Choose the actual observed issue.']);
                    }
                    if ($data['kind'] === 'missing' && (! $member?->included || ($data['parcel_id'] ?? null) !== $member->id || $member->received_at)) {
                        throw new DomainException('Choose the expected return that still lacks a receipt.');
                    }
                    if ($data['kind'] !== 'missing' && empty($data['barcode'])) {
                        throw ValidationException::withMessages(['barcode' => 'Record the actual observed barcode.']);
                    }
                    $payload += ['kind' => $data['kind'], 'return_member_id' => $member?->id];
                    break;
                case 'correct-discrepancy':
                    $this->notes($data);
                    $this->barcode($parcel, $data);
                    $original = $manifest->events()->whereKey($data['source_event_id'] ?? 0)->where('event_type', 'report-discrepancy')->first();
                    $receipt = $manifest->events()->where('event_type', 'buyer-return-received')->where('barcode_scanned', $parcel->barcode)->first();
                    if (! $original || ! in_array($original->payload['kind'] ?? null, ['extra', 'wrong_hub'], true) || ! $receipt || ! $member?->received_at) {
                        throw new DomainException('A receiving handler may correct a mistaken observed code only against this return’s actual retained receipt.');
                    }
                    $payload += ['source_event_id' => $original->id, 'receipt_reference' => $receipt->reference, 'original_barcode' => $original->barcode_scanned];
                    break;
                case 'resolve-discrepancy':
                    $this->notes($data);
                    $original = $manifest->events()->whereKey($data['source_event_id'] ?? 0)->where('event_type', 'report-discrepancy')->first();
                    $receipt = $manifest->events()->where('event_type', 'buyer-return-received')->where('barcode_scanned', $parcel->barcode)->first();
                    $correction = $original ? $manifest->events()->where('event_type', 'correct-discrepancy')->where('payload->source_event_id', $original->id)
                        ->where('barcode_scanned', $parcel->barcode)->where('payload->receipt_reference', $receipt?->reference)->latest('id')->first() : null;
                    if (! $original || ! $receipt || ! $member?->received_at || $receipt->actor_id !== $member->received_by_id
                        || (! empty($original->barcode_scanned) && $original->barcode_scanned !== $parcel->barcode && ! $correction)
                        || $manifest->events()->where('event_type', 'resolve-discrepancy')->where('payload->source_event_id', $original->id)->exists()) {
                        throw new DomainException('Resolve only an unclosed observation supported by this return’s actual destination receipt. Unlisted parcels remain under investigation.');
                    }
                    $payload += ['source_event_id' => $original->id, 'receipt_reference' => $receipt->reference];
                    break;
            }
            $manifest->version++;
            $manifest->save();
            if (in_array($action, ['dispatch', 'receive'], true)) {
                $parcel->version++;
                $parcel->save();
                $type = $action === 'dispatch' ? 'buyer-return-departed' : 'buyer-return-received';
                $scan = $this->record($manifest, $actor, $type, $parcelBefore, $parcel->state(), $payload, null, $parcel->barcode, $hubId);
                $source = $case->status;
                $case->status = $action === 'receive' && $parcel->route_position === count($parcel->hub_ids) - 1 ? 'at_origin_hub' : 'in_transit';
                $case->version++;
                $case->save();
                app(BuyerReturnService::class)->append($case, $actor, $type, $source, $data['notes'] ?? 'The actual return waybill was scanned at its authorized facility.',
                    hash('sha256', 'buyer-return-manifest:'.$manifest->id.':'.$data['request_token']), $fingerprint,
                    ['manifest_scan_reference' => $scan->reference, 'barcode' => $parcel->barcode, 'source_state' => $parcelBefore, 'target_state' => $parcel->state()]);
            }
            $event = $this->record($manifest, $actor, $action, $before, $manifest->state(), $payload + ['custody_scan_reference' => $scan?->reference], $data,
                $data['barcode'] ?? null, $hubId);

            return ['manifest' => $manifest, 'event' => $event];
        }, 3);
    }

    private function route(LogisticsManifest $manifest, BuyerReturnCase $case, BuyerReturnParcel $parcel): void
    {
        app(BuyerReturnCustodyService::class)->assertSource($case, $parcel);
        if ($manifest->buyer_return_leg_index !== $parcel->route_position || ($parcel->hub_ids[$parcel->route_position] ?? null) !== $manifest->source_hub_id
            || ($parcel->hub_ids[$parcel->route_position + 1] ?? null) !== $manifest->destination_hub_id) {
            throw new DomainException('Use only this return’s next retained reverse leg. Earlier and direct Bayan-to-Bayan legs cannot be used.');
        }
        $this->manifests->legType($manifest->sourceHub, $manifest->destinationHub);
    }

    private function ready(LogisticsManifest $manifest, BuyerReturnCase $case, BuyerReturnParcel $parcel, ?BuyerReturnManifestParcel $member): void
    {
        $this->route($manifest, $case, $parcel);
        if (! $member?->included || $member->active_return_parcel_id !== $parcel->id || $member->received_at
            || $member->barcode_snapshot !== $parcel->barcode || $member->source_state !== $parcel->state()
            || ! $manifest->events()->where('event_type', 'load')->where('barcode_scanned', $parcel->barcode)->exists()
            || $parcel->status !== 'at_hub' || $parcel->current_hub_id !== $manifest->source_hub_id) {
            throw new DomainException('Scan and retain the actual original return parcel before sealing or departing.');
        }
        $this->capacity(LogisticsFleet::findOrFail($manifest->vehicle_id), $parcel);
    }

    private function capacity(LogisticsFleet $vehicle, BuyerReturnParcel $parcel): void
    {
        if (! $parcel->weight_grams || ! is_finite($vehicle->capacity_kg) || $vehicle->capacity_kg <= 0
            || $parcel->weight_grams > (int) round($vehicle->capacity_kg * 1000)) {
            throw new DomainException('The vehicle must have enough recorded capacity for the actual accepted return weight.');
        }
    }

    private function barcode(BuyerReturnParcel $parcel, array $data): void
    {
        if (($data['barcode'] ?? null) !== $parcel->barcode) {
            throw ValidationException::withMessages(['barcode' => 'Scan this case’s separate return barcode. The original delivery waybill is not a return receipt.']);
        }
    }

    private function status(LogisticsManifest $manifest, string $status): void
    {
        if ($manifest->status !== $status) {
            throw new DomainException('The return manifest must be '.$status.' for this action.');
        }
    }

    private function notes(array $data): void
    {
        Validator::make($data, ['notes' => ['required', new ApplicationText('notes', 10, 1000)]])->validate();
    }

    private function fingerprint(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function record(LogisticsManifest $manifest, User $actor, string $type, array $before, array $after, array $payload, ?array $command, ?string $barcode, int $hubId): LogisticsManifestEvent
    {
        return $manifest->events()->create(['actor_id' => $actor->id, 'hub_id' => $hubId, 'event_type' => $type,
            'barcode_scanned' => $barcode, 'reason' => $command['notes'] ?? null, 'source_state' => $before, 'target_state' => $after,
            'payload' => $payload, 'request_token' => $command['request_token'] ?? null,
            'request_fingerprint' => $command ? $this->fingerprint($command) : null]);
    }
}
