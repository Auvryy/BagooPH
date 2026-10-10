<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\ParcelRecoveryCase;
use App\Models\ParcelRecoveryEvent;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ParcelRecoveryService
{
    public const HELD = 'parcel_recovery_held';

    public const IN_TRANSIT = 'parcel_recovery_in_transit';

    public function __construct(private readonly LogisticsEligibilityService $eligibility, private readonly WaybillScanInputService $inputs) {}

    public function observations(User $actor): Builder
    {
        $actor = User::findOrFail($actor->id);
        $manifests = app(LogisticsManifestService::class)->readable($actor);
        $handlerHubs = null;
        if (! $actor->isAdmin() && ! $this->eligibility->isCompanyAdministrator($actor)) {
            $handlerHubs = $this->eligibility->accessibleHubs($actor)->pluck('logistics_hubs.id');
            $manifests->whereIn('destination_hub_id', $this->eligibility->accessibleHubs($actor)->select('logistics_hubs.id'));
        }

        return LogisticsManifestEvent::query()->where('event_type', 'report-discrepancy')
            ->whereIn('payload->kind', ['extra', 'wrong_hub'])->where(function ($query) use ($manifests, $handlerHubs) {
                $query->whereIn('manifest_id', $manifests->select('id'));
                if ($handlerHubs) {
                    $query->orWhereHas('recoveryCase', function ($cases) use ($handlerHubs) {
                        $cases->where(function ($scope) use ($handlerHubs) {
                            foreach ($handlerHubs as $hubId) {
                                $scope->orWhereJsonContains('hub_ids', $hubId);
                            }
                            if ($handlerHubs->isEmpty()) {
                                $scope->whereRaw('1 = 0');
                            }
                        });
                    });
                }
            });
    }

    public function active(Delivery $delivery): ?ParcelRecoveryCase
    {
        return ParcelRecoveryCase::where('active_delivery_id', $delivery->id)->first();
    }

    public function physical(Delivery $delivery): ?ParcelRecoveryCase
    {
        $case = $this->active($delivery);

        return $case && in_array($case->state, ['held', 'in_transit'], true) ? $case : null;
    }

    public function proposal(User $actor, LogisticsManifestEvent $observation): array
    {
        abort_unless($this->observations($actor)->whereKey($observation->id)->exists(), 404);
        $facts = $this->facts($observation);

        return ['source_token' => $this->token($facts), 'facts' => $facts];
    }

    private function facts(LogisticsManifestEvent $observation): array
    {
        $observedManifest = $observation->manifest;
        if ($observation->event_type !== 'report-discrepancy' || ! in_array($observation->payload['kind'] ?? null, ['extra', 'wrong_hub'], true)
            || ! in_array($observedManifest->status, ['dispatched', 'received'], true)
            || ! app(LogisticsManifestService::class)->openDiscrepancies($observedManifest)->contains('id', $observation->id)) {
            throw new DomainException('Choose an unresolved unexpected-parcel observation on an arriving manifest.');
        }
        $delivery = Delivery::with('order')->where('logistics_company_id', $observedManifest->logistics_company_id)
            ->where('tracking_number', $observation->barcode_scanned)->first();
        $member = $delivery ? LogisticsManifestParcel::with('manifest')->where('active_delivery_id', $delivery->id)->where('included', true)->whereNull('received_at')->first() : null;
        $original = $member?->manifest;
        $checkpoint = $delivery?->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
        if (! $delivery || ! $original || $original->id === $observedManifest->id || $original->direction === 'recovery'
            || $original->logistics_company_id !== $observedManifest->logistics_company_id || $original->status !== 'dispatched'
            || $delivery->status !== app(LogisticsManifestService::class)->transitStatus($original)
            || $delivery->current_hub_id !== null || ! $checkpoint
            || ($checkpoint->custody_after['kind'] ?? null) !== 'manifest' || ($checkpoint->custody_after['manifest_id'] ?? null) !== $original->id
            || $checkpoint->barcode_scanned !== $delivery->tracking_number
            || $delivery->order->status !== ($original->direction === 'return' ? 'delivery_failed' : 'at_sorting_center')
            || app(LogisticsManifestService::class)->route($delivery) !== $member->route_snapshot) {
            throw new DomainException('Recovery needs a recognized same-company waybill with its original dispatched manifest and actual transport custody. Unknown or unsupported parcels remain open for review.');
        }
        $dispatch = $original->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'parcel_dispatched')->first();
        if (! $dispatch || ($checkpoint->custody_after['manifest_scan_reference'] ?? null) !== $dispatch->reference) {
            throw new DomainException('The original departure record does not support this parcel custody.');
        }
        $observed = LogisticsHub::eligible()->where('logistics_company_id', $delivery->logistics_company_id)->find($observation->hub_id);
        $expected = LogisticsHub::eligible()->where('logistics_company_id', $delivery->logistics_company_id)->find($original->destination_hub_id);
        $source = LogisticsHub::eligible()->where('logistics_company_id', $delivery->logistics_company_id)->find($original->source_hub_id);
        if (! $observed || ! $expected || ! $source || $observation->hub_id !== $observedManifest->destination_hub_id) {
            throw new DomainException('The observed facility and original route facilities must be active in this company.');
        }
        $hubs = [$observed->id];
        if ($observed->id !== $expected->id) {
            if (! $observed->isMotherHub() && ! $expected->isMotherHub()) {
                if (! $source->isMotherHub()) {
                    throw new DomainException('This recovery has no recorded Mother Hub for its required transport leg.');
                }
                $hubs[] = $source->id;
            }
            $hubs[] = $expected->id;
        }
        if ($this->active($delivery)) {
            throw new DomainException('This parcel already has an active recovery case. Continue its original case.');
        }

        return ['observation_event_id' => $observation->id, 'original_manifest_parcel_id' => $member->id, 'delivery_id' => $delivery->id,
            'source_checkpoint_id' => $checkpoint->id, 'logistics_company_id' => $delivery->logistics_company_id,
            'observed_hub_id' => $observed->id, 'expected_hub_id' => $expected->id, 'original_direction' => $original->direction,
            'original_transit_status' => $delivery->status, 'route_snapshot' => $member->route_snapshot,
            'hub_ids' => $hubs, 'source_state' => DeliveryCheckpoint::state($delivery), 'custody_snapshot' => $checkpoint->custody_after];
    }

    public function authorize(User $actor, LogisticsManifestEvent $observation, array $input): ParcelRecoveryCase
    {
        abort_unless($this->observations($actor)->whereKey($observation->id)->exists(), 404);
        $this->administrator($actor, $observation->manifest->logistics_company_id);
        $input = Validator::make($this->inputs->normalize($input), ['source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'request_token' => ['required', 'uuid'], 'notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)]])->validate();
        $delivery = Delivery::where('logistics_company_id', $observation->manifest->logistics_company_id)->where('tracking_number', $observation->barcode_scanned)->first();
        if (! $delivery) {
            throw new DomainException('Recovery needs a recognized same-company waybill with recorded transport custody.');
        }

        return DB::transaction(function () use ($actor, $observation, $input, $delivery) {
            $parcel = $this->lockParcel($delivery->id);
            $originalMember = LogisticsManifestParcel::with('manifest')->where('active_delivery_id', $parcel->id)->first();
            $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id], [$observation->hub_id,
                $originalMember?->manifest->source_hub_id, $originalMember?->manifest->destination_hub_id]);
            $actor = $this->administrator($actor, $parcel->logistics_company_id);
            $fingerprint = $this->fingerprint($input);
            $original = ParcelRecoveryCase::where('authorized_by_id', $actor->id)->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->observation_event_id !== $observation->id || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This request already authorized different recovery evidence.');
                }

                return $original;
            }
            LogisticsManifest::whereIn('id', [$observation->manifest_id, $originalMember?->manifest_id])->orderBy('id')->lockForUpdate()->get();
            $observation = LogisticsManifestEvent::with('manifest')->findOrFail($observation->id);
            $facts = $this->facts($observation);
            if (! hash_equals($this->token($facts), $input['source_token'])) {
                throw new DomainException('The parcel, route or original custody changed. Review the current source before authorizing recovery.');
            }
            if (ParcelRecoveryCase::where('observation_event_id', $observation->id)->exists()) {
                throw new DomainException('This observation already has its retained recovery case.');
            }
            $case = ParcelRecoveryCase::create($facts + ['active_delivery_id' => $parcel->id, 'authorized_by_id' => $actor->id,
                'reason' => $input['notes'], 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint,
                'state' => 'authorized', 'version' => 1, 'leg_index' => 0]);
            $this->event($case, $actor, 'authorized', $facts['source_state'], $facts['source_state'], $input['notes'], payload: ['source_checkpoint_id' => $facts['source_checkpoint_id']]);

            return $case;
        });
    }

    public function intake(User $actor, ParcelRecoveryCase $snapshot, array $input): ParcelRecoveryCase
    {
        $input = Validator::make($this->inputs->normalize($input), ['version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
            'request_token' => ['required', 'uuid'], 'barcode' => $this->inputs->barcodeRules(), 'notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)]])->validate();

        return DB::transaction(function () use ($actor, $snapshot, $input) {
            $parcel = $this->lockParcel($snapshot->delivery_id);
            $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id], $snapshot->hub_ids);
            $case = ParcelRecoveryCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            $hub = LogisticsHub::findOrFail($case->observed_hub_id);
            abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->canScan($actor, $hub), 403);
            $barcode = $this->inputs->matchedBarcode($input['barcode'], $parcel);
            $input['barcode'] = $barcode;
            $input['version'] = (int) $input['version'];
            $fingerprint = $this->fingerprint($input);
            $original = $case->events()->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || $original->event_type !== 'intake' || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This request already recorded a different recovery scan.');
                }

                return $case;
            }
            if ($case->version !== $input['version'] || $case->state !== 'authorized') {
                throw new DomainException('The recovery changed. Refresh its current custody and next action.');
            }
            $this->assertOriginal($case, $parcel);
            $checkpoint = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
            if (DeliveryCheckpoint::state($parcel) !== $case->source_state || DeliveryCheckpoint::lastCustody($parcel) !== $case->custody_snapshot
                || $checkpoint?->id !== $case->source_checkpoint_id) {
                throw new DomainException('The original transport custody changed; this authorization cannot receive the parcel.');
            }
            $before = DeliveryCheckpoint::state($parcel);
            $case->originalMember->update(['active_delivery_id' => null]);
            $case->fill(['state' => 'held', 'version' => $case->version + 1])->save();
            $parcel->fill(['status' => self::HELD, 'current_hub_id' => $hub->id])->save();
            $event = $this->event($case, $actor, 'intake', $before, DeliveryCheckpoint::state($parcel), $input['notes'], $hub->id, $barcode,
                payload: ['source_checkpoint_id' => $case->source_checkpoint_id], token: $input['request_token'], fingerprint: $fingerprint);
            $this->checkpoint($case, $parcel, $actor, $hub, $event, 'parcel_recovery_intake', $before, $case->custody_snapshot,
                ['kind' => 'hub', 'hub_id' => $hub->id, 'parcel_recovery_reference' => $case->reference]);
            if (count($case->hub_ids) === 1) {
                $this->complete($case, $parcel, $actor, $hub, $input['notes']);
            }

            return $case;
        });
    }

    public function recoveryRoute(ParcelRecoveryCase $case): array
    {
        return $case->route_snapshot + ['parcel_recovery_reference' => $case->reference, 'recovery_hub_ids' => $case->hub_ids];
    }

    public function withdraw(User $actor, ParcelRecoveryCase $snapshot, array $input): ParcelRecoveryCase
    {
        $this->administrator($actor, $snapshot->logistics_company_id);
        $input = Validator::make($this->inputs->normalize($input), ['version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
            'request_token' => ['required', 'uuid'], 'notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)]])->validate();

        return DB::transaction(function () use ($actor, $snapshot, $input) {
            $parcel = $this->lockParcel($snapshot->delivery_id);
            $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id]);
            $actor = $this->administrator($actor, $parcel->logistics_company_id);
            $case = ParcelRecoveryCase::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            $input['version'] = (int) $input['version'];
            $fingerprint = $this->fingerprint($input);
            $original = $case->events()->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || $original->event_type !== 'withdrawn' || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This request already recorded different recovery evidence.');
                }

                return $case;
            }
            if ($case->state !== 'authorized' || $case->version !== $input['version']) {
                throw new DomainException('Only an unchanged authorization with no physical intake can be withdrawn. Received parcels require actual recovery transfers.');
            }
            $before = DeliveryCheckpoint::state($parcel);
            $case->fill(['state' => 'withdrawn', 'active_delivery_id' => null, 'version' => $case->version + 1])->save();
            $this->event($case, $actor, 'withdrawn', $before, $before, $input['notes'], token: $input['request_token'], fingerprint: $fingerprint);

            return $case;
        });
    }

    public function nextHub(ParcelRecoveryCase $case, Delivery $parcel): int
    {
        $this->assertOriginal($case, $parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $next = $case->hub_ids[$case->leg_index + 1] ?? null;
        if ($case->state !== 'held' || $parcel->status !== self::HELD || $parcel->current_hub_id !== ($case->hub_ids[$case->leg_index] ?? null)
            || ($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $parcel->current_hub_id
            || ($custody['parcel_recovery_reference'] ?? null) !== $case->reference || ! $next) {
            throw new DomainException('The recovery needs its actual current hub receipt before the next planned transfer.');
        }

        return $next;
    }

    public function depart(LogisticsManifest $manifest, Delivery $parcel, User $actor, LogisticsManifestEvent $scan): void
    {
        $case = ParcelRecoveryCase::whereKey($manifest->parcel_recovery_case_id)->lockForUpdate()->firstOrFail();
        if ($this->nextHub($case, $parcel) !== $manifest->destination_hub_id || $case->leg_index !== $manifest->recovery_leg_index
            || $parcel->current_hub_id !== $manifest->source_hub_id) {
            throw new DomainException('This manifest does not follow the current authorized recovery leg.');
        }
        $before = DeliveryCheckpoint::state($parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $case->fill(['state' => 'in_transit', 'version' => $case->version + 1])->save();
        $parcel->fill(['status' => self::IN_TRANSIT, 'current_hub_id' => null])->save();
        $event = $this->event($case, $actor, 'transfer_departed', $before, DeliveryCheckpoint::state($parcel), $scan->reason ?? 'Scanned onto the authorized recovery transfer.', $manifest->source_hub_id, $scan->barcode_scanned, $scan);
        $this->checkpoint($case, $parcel, $actor, $manifest->sourceHub, $event, 'parcel_recovery_departed', $before, $custody,
            ['kind' => 'manifest', 'manifest_id' => $manifest->id, 'manifest_reference' => $manifest->reference,
                'vehicle_id' => $manifest->vehicle_id, 'driver_id' => $manifest->driver_id, 'manifest_scan_reference' => $scan->reference,
                'source_scan_reference' => $scan->payload['source_scan_reference'], 'parcel_recovery_reference' => $case->reference], $manifest);
    }

    public function receive(LogisticsManifest $manifest, Delivery $parcel, User $actor, LogisticsManifestEvent $scan): void
    {
        $case = ParcelRecoveryCase::whereKey($manifest->parcel_recovery_case_id)->lockForUpdate()->firstOrFail();
        $this->assertOriginal($case, $parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($case->state !== 'in_transit' || $parcel->status !== self::IN_TRANSIT || $case->leg_index !== $manifest->recovery_leg_index
            || $manifest->source_hub_id !== ($case->hub_ids[$case->leg_index] ?? null)
            || $manifest->destination_hub_id !== ($case->hub_ids[$case->leg_index + 1] ?? null)
            || ($custody['kind'] ?? null) !== 'manifest' || ($custody['manifest_id'] ?? null) !== $manifest->id
            || ($custody['parcel_recovery_reference'] ?? null) !== $case->reference) {
            throw new DomainException('This parcel has no current custody in the arriving recovery manifest.');
        }
        $before = DeliveryCheckpoint::state($parcel);
        $case->fill(['state' => 'held', 'leg_index' => $case->leg_index + 1, 'version' => $case->version + 1])->save();
        $hub = $manifest->destinationHub;
        $parcel->fill(['status' => self::HELD, 'current_hub_id' => $hub->id])->save();
        $event = $this->event($case, $actor, 'transfer_received', $before, DeliveryCheckpoint::state($parcel), $scan->reason ?? 'Received the actual recovery transfer.', $hub->id, $scan->barcode_scanned, $scan);
        $this->checkpoint($case, $parcel, $actor, $hub, $event, 'parcel_recovery_received', $before, $custody,
            ['kind' => 'hub', 'hub_id' => $hub->id, 'manifest_scan_reference' => $scan->reference, 'parcel_recovery_reference' => $case->reference], $manifest);
        if ($case->leg_index === count($case->hub_ids) - 1) {
            $this->complete($case, $parcel, $actor, $hub, $event->reason);
        }
    }

    public function assertOriginal(ParcelRecoveryCase $case, Delivery $parcel): void
    {
        $member = $case->originalMember()->with('manifest')->firstOrFail();
        $original = $member->manifest;
        $route = app(DeliveryReturnService::class)->forwardRoute($parcel);
        if ($case->original_direction === 'return') {
            $return = app(DeliveryReturnService::class)->route($parcel);
            $route += ['return_route_reference' => $return->reference, 'return_hub_ids' => $return->hub_ids];
        }
        if ($case->delivery_id !== $parcel->id || $case->active_delivery_id !== $parcel->id || $case->state === 'completed'
            || $case->logistics_company_id !== $parcel->logistics_company_id || $route !== $case->route_snapshot
            || $member->delivery_id !== $parcel->id || ! $member->included || $member->received_at
            || $member->tracking_number_snapshot !== $parcel->tracking_number || $member->route_snapshot !== $case->route_snapshot
            || $original->status !== 'dispatched' || $original->destination_hub_id !== $case->expected_hub_id
            || $original->direction !== $case->original_direction || $parcel->order->status !== $case->source_state['order_status']) {
            throw new DomainException('The original parcel, route or receiving obligation changed. Recovery cannot replace its missing source evidence.');
        }
        $sourceTier = in_array($original->source_hub_id, [$route['origin_mother_hub_id'], $route['destination_mother_hub_id']], true) ? 'regional_mother_hub' : 'local_bayan_hub';
        $targetTier = in_array($original->destination_hub_id, [$route['origin_mother_hub_id'], $route['destination_mother_hub_id']], true) ? 'regional_mother_hub' : 'local_bayan_hub';
        if ($original->sourceHub->tier !== $sourceTier || $original->destinationHub->tier !== $targetTier) {
            throw new DomainException('The original route facilities no longer have their recorded Bayan or Mother Hub types.');
        }
        if (LogisticsHub::eligible()->where('logistics_company_id', $case->logistics_company_id)
            ->whereIn('id', [$original->source_hub_id, $original->destination_hub_id])->count() !== 2) {
            throw new DomainException('The original transport facilities need their current approval and company scope before this recovery can proceed.');
        }
    }

    private function complete(ParcelRecoveryCase $case, Delivery $parcel, User $actor, LogisticsHub $hub, string $notes): void
    {
        $this->assertOriginal($case, $parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($case->state !== 'held' || $case->leg_index !== count($case->hub_ids) - 1 || $hub->id !== $case->expected_hub_id
            || $parcel->status !== self::HELD || $parcel->current_hub_id !== $hub->id || ($custody['kind'] ?? null) !== 'hub'
            || ($custody['hub_id'] ?? null) !== $hub->id || ($custody['parcel_recovery_reference'] ?? null) !== $case->reference) {
            throw new DomainException('Actual receipt at the original expected hub is required before resuming the parcel.');
        }
        $member = $case->originalMember()->lockForUpdate()->firstOrFail();
        $manifest = LogisticsManifest::whereKey($member->manifest_id)->lockForUpdate()->firstOrFail();
        $before = DeliveryCheckpoint::state($parcel);
        $target = app(LogisticsManifestService::class)->receiptStatus($manifest);
        $scan = LogisticsManifestEvent::create(['manifest_id' => $manifest->id, 'manifest_parcel_id' => $member->id, 'actor_id' => $actor->id,
            'hub_id' => $hub->id, 'event_type' => 'parcel_received', 'barcode_scanned' => $parcel->tracking_number, 'reason' => $notes,
            'source_state' => $before, 'target_state' => ['delivery_status' => $target], 'payload' => ['parcel_recovery_reference' => $case->reference]]);
        $member->update(['received_at' => now(), 'received_by_id' => $actor->id, 'receipt_condition' => 'intact', 'active_delivery_id' => null]);
        if (! $manifest->parcels()->where('included', true)->whereNull('received_at')->exists()) {
            $manifest->fill(['status' => 'received', 'received_at' => now(), 'receiver_id' => $actor->id]);
        }
        $manifest->version++;
        $manifest->save();
        $parcel->status = $target;
        $parcel->save();
        DeliveryCheckpoint::record(delivery: $parcel, type: $target, notes: $notes, actor: $actor, hub: $hub,
            manifestNumber: $manifest->reference, barcodeScanned: $parcel->tracking_number,
            evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                'custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id, 'manifest_scan_reference' => $scan->reference, 'parcel_recovery_reference' => $case->reference]]);
        $case->fill(['state' => 'completed', 'active_delivery_id' => null, 'version' => $case->version + 1])->save();
        $event = $this->event($case, $actor, 'completed', $before, DeliveryCheckpoint::state($parcel), $notes, $hub->id, $parcel->tracking_number, $scan);
        $observation = $case->observation;
        LogisticsManifestEvent::create(['manifest_id' => $observation->manifest_id, 'actor_id' => $actor->id, 'hub_id' => $hub->id,
            'event_type' => 'recovery-completed', 'barcode_scanned' => $parcel->tracking_number, 'reason' => $notes,
            'source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel),
            'payload' => ['source_event_id' => $observation->id, 'parcel_recovery_case_id' => $case->id,
                'recovery_event_reference' => $event->reference, 'original_receipt_event_id' => $scan->id]]);
        LogisticsManifest::whereKey($observation->manifest_id)->increment('version');
    }

    public function completionSupport(LogisticsManifestEvent $event): ?LogisticsManifestEvent
    {
        if ($event->event_type !== 'recovery-completed') {
            return null;
        }
        $case = ParcelRecoveryCase::find($event->payload['parcel_recovery_case_id'] ?? null);
        $receipt = LogisticsManifestEvent::with('parcel')->find($event->payload['original_receipt_event_id'] ?? null);
        if (! $case || $case->state !== 'completed' || $case->observation_event_id !== ($event->payload['source_event_id'] ?? null)
            || $case->observation->manifest_id !== $event->manifest_id || $receipt?->event_type !== 'parcel_received'
            || $receipt->manifest_parcel_id !== $case->original_manifest_parcel_id || ! $receipt->parcel?->received_at
            || $receipt->hub_id !== $case->expected_hub_id || $receipt->barcode_scanned !== $event->barcode_scanned
            || ! $case->events()->where('reference', $event->payload['recovery_event_reference'] ?? null)->where('event_type', 'completed')->where('manifest_event_id', $receipt->id)->exists()
            || ! DeliveryCheckpoint::where('delivery_id', $case->delivery_id)->where('hub_id', $case->expected_hub_id)
                ->where('custody_after->manifest_scan_reference', $receipt->reference)->exists()) {
            return null;
        }

        return $receipt;
    }

    private function lockParcel(int $id): Delivery
    {
        $orderId = Delivery::whereKey($id)->value('order_id');
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($id)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);

        return $parcel;
    }

    private function administrator(User $actor, int $companyId): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->isCompanyAdministrator($actor, $companyId), 403);

        return $actor;
    }

    private function token(array $facts): string
    {
        return hash_hmac('sha256', json_encode($facts, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function fingerprint(array $input): string
    {
        ksort($input);

        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }

    private function event(ParcelRecoveryCase $case, User $actor, string $type, array $before, array $after, string $reason, ?int $hub = null,
        ?string $barcode = null, ?LogisticsManifestEvent $scan = null, array $payload = [], ?string $token = null, ?string $fingerprint = null): ParcelRecoveryEvent
    {
        return $case->events()->create(['actor_id' => $actor->id, 'hub_id' => $hub, 'manifest_event_id' => $scan?->id,
            'event_type' => $type, 'barcode_scanned' => $barcode, 'reason' => $reason, 'source_state' => $before, 'target_state' => $after,
            'payload' => $payload, 'request_token' => $token, 'request_fingerprint' => $fingerprint]);
    }

    private function checkpoint(ParcelRecoveryCase $case, Delivery $parcel, User $actor, LogisticsHub $hub, ParcelRecoveryEvent $event, string $type,
        array $before, array $custodyBefore, array $custodyAfter, ?LogisticsManifest $manifest = null): void
    {
        DeliveryCheckpoint::record(delivery: $parcel, type: $type, notes: $event->reason, actor: $actor, hub: $hub,
            manifestNumber: $manifest?->reference, barcodeScanned: $event->barcode_scanned,
            evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custodyBefore,
                'custody_after' => $custodyAfter + ['parcel_recovery_event_reference' => $event->reference]]);
    }
}
