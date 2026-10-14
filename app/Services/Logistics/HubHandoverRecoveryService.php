<?php

namespace App\Services\Logistics;

use App\Exceptions\HandoverHoldException;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HandoverIssueEvent;
use App\Models\HandoverManifestReceipt;
use App\Models\HandoverRecoveryGrant;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\ParcelRecoveryCase;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HubHandoverRecoveryService
{
    public const MODES = ['hub_intake', 'hub_manifest', 'hub_failed_return', 'hub_existing'];

    public function __construct(private readonly HandoverIssueService $issues, private readonly LogisticsEligibilityService $eligibility) {}

    private function core(Delivery $parcel): array
    {
        $items = $parcel->order->items()->orderBy('id')->get();
        abort_unless($items->isNotEmpty() && $items->pluck('shop_id')->filter()->unique()->count() === 1
            && ! $items->contains(fn ($item) => $item->shop_id === null), 409, 'Recovery requires the original purchased items and one known owning shop.');

        return $this->canonical([
            'parcel' => $parcel->only(['id', 'order_id', 'logistics_company_id', 'tracking_number', 'delivery_type',
                'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id',
                'pickup_address', 'delivery_address', 'delivery_recipient_name', 'delivery_phone']),
            'order' => $parcel->order->only(['id', 'buyer_id', 'subtotal', 'voucher_id', 'voucher_discount', 'shipping_fee', 'total_amount',
                'payment_method', 'payment_status', 'delivery_type', 'pickup_hub_id', 'destination_barangay', 'recipient_name',
                'recipient_phone', 'shipping_address', 'shipping_city', 'shipping_province', 'shipping_postal_code']),
            'items' => $items->map(fn ($item) => $item->only(['id', 'product_id', 'shop_id', 'quantity', 'unit_price', 'subtotal', 'sku_snapshot', 'color', 'size']))->all(),
            'shops' => Shop::whereIn('id', $items->pluck('shop_id'))->orderBy('id')->get()
                ->map(fn ($shop) => $shop->only(['id', 'user_id', 'root_category_id', 'status', 'review_status', 'review_version', 'review_decision_id']))->all(),
        ]);
    }

    private function canonical(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function manifestSnapshot(LogisticsManifest $manifest): array
    {
        return $this->canonical(['state' => $manifest->state(),
            'members' => $manifest->parcels()->orderBy('id')->get()->map(fn ($member) => $member->only(['id', 'delivery_id', 'active_delivery_id',
                'tracking_number_snapshot', 'route_snapshot', 'source_state', 'included', 'received_at', 'received_by_id', 'receipt_condition']))->all(),
            'last_event_id' => $manifest->events()->max('id'),
            'corrective_receipts' => $manifest->correctiveReceipts()->orderBy('id')->pluck('id')->all()]);
    }

    private function route(Delivery $parcel): void
    {
        $ids = array_filter([$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id, $parcel->destination_bayan_hub_id]);
        abort_unless(count($ids) === 4
            && LogisticsHub::eligible()->where('logistics_company_id', $parcel->logistics_company_id)->whereIn('id', $ids)->count() === count(array_unique($ids))
            && LogisticsHub::whereIn('id', [$ids[0], $ids[3]])->where('tier', 'local_bayan_hub')->count() === count(array_unique([$ids[0], $ids[3]]))
            && LogisticsHub::whereIn('id', [$ids[1], $ids[2]])->where('tier', 'regional_mother_hub')->count() === count(array_unique([$ids[1], $ids[2]])), 409,
            'Recovery needs the retained eligible Bayan and Mother Hub network.');
        abort_if($parcel->delivered_at || in_array($parcel->order->status, ['cancelled', 'delivered', 'completed', 'returned'], true), 409,
            'Recovery cannot replace a terminal commercial outcome.');
    }

    private function context(Delivery $parcel, string $mode, ?int $reference, User $actor): array
    {
        $this->route($parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $context = ['mode' => $mode, 'reference_id' => $reference];
        if ($mode === 'hub_intake') {
            $origin = $parcel->status === 'picked_up' && $parcel->order->status === 'picked_up'
                && ! $parcel->current_hub_id && ($custody['kind'] ?? null) === 'courier'
                && ($custody['user_id'] ?? null) === $parcel->courier_id;
            $atHub = in_array($parcel->status, ['arrived_at_origin_hub', 'arrived_at_destination_hub', 'sorted_to_barangay_bin', 'assigned_to_rider'], true)
                && in_array($parcel->order->status, ['at_sorting_center', 'sorted', 'assigned_to_rider'], true)
                && in_array($parcel->current_hub_id, [$parcel->origin_bayan_hub_id, $parcel->destination_bayan_hub_id], true)
                && ($custody['kind'] ?? null) === 'hub' && ($custody['hub_id'] ?? null) === $parcel->current_hub_id;
            abort_unless(($origin || $atHub) && ! $parcel->failure_attempts
                && ! LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->exists() && ! app(ParcelRecoveryService::class)->active($parcel), 409,
                'This intake needs the original pickup or correct Bayan Hub custody.');
            $context += ['hub_id' => $origin ? $parcel->origin_bayan_hub_id : $parcel->current_hub_id,
                'holder_hub_id' => $origin ? $parcel->origin_bayan_hub_id : $parcel->current_hub_id,
                'driver_id' => $origin ? $parcel->courier_id : null, 'source_kind' => $origin ? 'pickup' : 'hub',
                'target_status' => $origin || $parcel->status === 'arrived_at_origin_hub' ? 'arrived_at_origin_hub' : 'arrived_at_destination_hub'];
        } elseif ($mode === 'hub_failed_return') {
            $attempt = app(DeliveryRecoveryService::class)->latestAttempt($parcel);
            abort_unless($parcel->status === 'delivery_failed' && $parcel->order->status === 'delivery_failed'
                && ! $parcel->current_hub_id && ($custody['kind'] ?? null) === 'courier'
                && ($custody['user_id'] ?? null) === $attempt->rider_id && $parcel->assigned_rider_id === $attempt->rider_id
                && ! $attempt->events()->where('event_type', 'hub_return')->exists(), 409, 'Use the actual failed attempt and its returning rider.');
            $context += ['hub_id' => $parcel->destination_bayan_hub_id, 'holder_hub_id' => $parcel->destination_bayan_hub_id,
                'driver_id' => $attempt->rider_id, 'attempt' => $this->canonical($attempt->getAttributes())];
        } elseif ($mode === 'hub_manifest') {
            $manifest = LogisticsManifest::findOrFail($reference);
            abort_unless($manifest->logistics_company_id === $parcel->logistics_company_id
                && in_array($manifest->status, ['draft', 'sealed', 'dispatched'], true)
                && $manifest->source_hub_id !== $manifest->destination_hub_id
                && ($manifest->sourceHub->isMotherHub() || $manifest->destinationHub->isMotherHub()), 409, 'Choose the original same-company Mother Hub transport leg.');
            $service = app(LogisticsManifestService::class);
            abort_if($manifest->direction !== 'recovery' && app(ParcelRecoveryService::class)->active($parcel), 409,
                'This parcel has an original physical recovery case. Continue that route.');
            $member = $manifest->parcels()->where('delivery_id', $parcel->id)->first();
            $activeMember = LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->first();
            abort_if($manifest->correctiveReceipts()->where('delivery_id', $parcel->id)->exists()
                || ($member && (! $member->included || $member->received_at || $member->active_delivery_id !== $parcel->id))
                || ($activeMember && $activeMember->manifest_id !== $manifest->id), 409, 'Retain the original membership and its existing receiving obligation.');
            $route = $service->route($parcel);
            abort_unless($service->direction($parcel) === $manifest->direction && (! $member || ($member->route_snapshot === $route
                && $member->tracking_number_snapshot === $parcel->tracking_number)), 409, 'The transport direction or original parcel route changed.');
            $inTransit = $manifest->status === 'dispatched' && $member
                && $parcel->status === $service->transitStatus($manifest) && ! $parcel->current_hub_id
                && ($custody['kind'] ?? null) === 'manifest' && ($custody['manifest_id'] ?? null) === $manifest->id;
            if ($inTransit) {
                $dispatch = $manifest->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'parcel_dispatched')->first();
                abort_unless($dispatch && ($custody['manifest_scan_reference'] ?? null) === $dispatch->reference, 409, 'The original departure evidence is missing.');
            } else {
                abort_unless($parcel->current_hub_id === $manifest->source_hub_id && ($custody['kind'] ?? null) === 'hub'
                    && ($custody['hub_id'] ?? null) === $manifest->source_hub_id
                    && $service->nextHub($parcel) === $manifest->destination_hub_id, 409,
                    'The actual source custody does not support this next transport leg.');
                if ($member) {
                    abort_unless($manifest->events()->where('manifest_parcel_id', $member->id)->where('event_type', 'load')->exists(), 409,
                        'A listed parcel needs its retained original load.');
                }
            }
            $vehicle = $manifest->vehicle;
            $profile = CourierProfile::where('user_id', $manifest->driver_id)->first();
            $driver = $manifest->driver;
            abort_unless($driver?->isCourier() && ! $driver->closed_at && $driver->email_verified_at && $driver->isKycApproved()
                && $driver->hasEligibleBirthDate() && in_array($driver->status, ['active', 'inactive', 'suspended'], true)
                && $vehicle && $profile && $profile->logistics_company_id === $parcel->logistics_company_id
                && $vehicle->logistics_company_id === $parcel->logistics_company_id
                && $vehicle->hub_id === $manifest->source_hub_id && $vehicle->assigned_driver_id === $manifest->driver_id
                && $profile->vehicle_id === $vehicle->id && $profile->assigned_hub_id === $manifest->source_hub_id
                && $vehicle->vehicle_type === ($manifest->type === 'line_haul' ? 'wing_truck' : 'l300_van')
                && $manifest->type === ($manifest->sourceHub->isMotherHub() && $manifest->destinationHub->isMotherHub() ? 'line_haul' : 'feeder'), 409,
                'Retain the original responsible driver and correct vehicle; do not guess a replacement transport.');
            $context += ['hub_id' => $manifest->destination_hub_id, 'holder_hub_id' => $manifest->source_hub_id,
                'handler_hub_id' => $manifest->destination_hub_id, 'driver_id' => $manifest->driver_id,
                'member_id' => $member?->id, 'manifest' => $this->manifestSnapshot($manifest), 'route' => $route];
        } else {
            abort_unless($mode === 'hub_existing', 422);
            $observation = LogisticsManifestEvent::findOrFail($reference);
            $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->first();
            abort_if($case?->state === 'withdrawn', 409, 'This observation already has a withdrawn case. Record a fresh observation before recovery.');
            $contract = $case ? $case->only(['observation_event_id', 'original_manifest_parcel_id', 'delivery_id', 'source_checkpoint_id',
                'logistics_company_id', 'observed_hub_id', 'expected_hub_id', 'original_direction', 'original_transit_status',
                'route_snapshot', 'hub_ids', 'source_state', 'custody_snapshot'])
                : app(ParcelRecoveryService::class)->proposal($actor, $observation)['facts'];
            abort_unless(($contract['delivery_id'] ?? null) === $parcel->id
                && ($contract['logistics_company_id'] ?? null) === $parcel->logistics_company_id
                && (! $case || ($case->active_delivery_id === $parcel->id && in_array($case->state, ['authorized', 'held', 'in_transit'], true))), 409,
                'Continue only this parcel original open physical recovery.');
            $carrier = ($custody['kind'] ?? null) === 'manifest' ? LogisticsManifest::find($custody['manifest_id'] ?? 0) : null;
            $holderHub = $parcel->current_hub_id ?? $carrier?->source_hub_id;
            abort_unless($holderHub, 409, 'The actual holder has no retained source facility.');
            abort_unless(LogisticsHub::eligible()->where('logistics_company_id', $parcel->logistics_company_id)
                ->whereIn('id', $contract['hub_ids'])->count() === count(array_unique($contract['hub_ids'])), 409,
                'Every original physical recovery facility must retain its eligible scope.');
            $context += ['hub_id' => $contract['expected_hub_id'], 'holder_hub_id' => $holderHub,
                'handler_hub_id' => $parcel->current_hub_id ?? $contract['observed_hub_id'], 'driver_id' => $carrier?->driver_id,
                'case_contract' => $contract, 'case' => $case?->only(['id', 'state', 'version', 'leg_index']),
                'observation' => $observation->only(['id', 'manifest_id', 'event_type', 'hub_id', 'barcode_scanned', 'payload']),
                'current_manifest' => $carrier ? $this->manifestSnapshot($carrier) : null];
        }

        return $this->canonical($context);
    }

    private function source(Delivery $parcel, array $context): array
    {
        return $this->canonical(['core' => $this->core($parcel), 'current' => $this->issues->snapshot($parcel),
            'physical' => $parcel->only(['failure_attempts', 'assigned_at', 'picked_up_at', 'delivered_at', 'destination_bin']),
            'context' => $context]);
    }

    private function account(User $user): array
    {
        return $user->only(['id', 'role', 'status', 'kyc_status', 'email_verified_at', 'birthday', 'identity_version', 'restriction_version', 'closed_at']);
    }

    private function holder(User $holder, Delivery $parcel, array $context): void
    {
        abort_unless(! $holder->closed_at && $holder->email_verified_at && $holder->isKycApproved() && $holder->hasEligibleBirthDate(), 403,
            'The actual holder must retain an approved verified identity.');
        if ($holder->isCourier()) {
            $profile = CourierProfile::where('user_id', $holder->id)->first();
            abort_unless($holder->id === $context['driver_id'] && in_array($holder->status, ['active', 'inactive', 'suspended'], true)
                && $profile?->logistics_company_id === $parcel->logistics_company_id
                && $profile->assigned_hub_id === $context['holder_hub_id'], 403, 'Only the retained responsible rider may acknowledge this transport.');
        } else {
            $hub = LogisticsHub::findOrFail($context['handler_hub_id'] ?? $context['hub_id']);
            abort_unless($holder->isLogistics() && $holder->canAccessPortal() && $this->eligibility->canScan($holder, $hub), 403,
                'The known handler needs a current assignment to the actual receiving facility.');
        }
    }

    private function authority(User $authorizer, User $holder, Delivery $parcel, array $context): array
    {
        $this->holder($holder, $parcel, $context);
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        $hubIds = array_unique(array_filter([$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id,
            $parcel->destination_bayan_hub_id, $context['holder_hub_id'], $context['handler_hub_id'] ?? null, ...($context['case_contract']['hub_ids'] ?? [])]));
        $profile = CourierProfile::where('user_id', $holder->id)->first();
        $vehicleId = $context['manifest']['state']['vehicle_id'] ?? $profile?->vehicle_id;
        $driver = isset($context['driver_id']) ? User::find($context['driver_id']) : null;

        return $this->canonical(['authorizer' => $this->account($authorizer), 'holder' => $this->account($holder),
            'company' => $company->only(['id', 'user_id', 'is_active', 'status', 'restriction_version']), 'owner' => $this->account($company->user),
            'hubs' => LogisticsHub::whereIn('id', $hubIds)->orderBy('id')->get()->map(fn ($hub) => $hub->only(['id', 'logistics_company_id', 'tier',
                'is_active', 'restriction_version', 'code', 'province', 'city_municipality', 'barangay', 'address', 'latitude', 'longitude']))->all(),
            'profile' => $profile?->only(['id', 'user_id', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'plate_number', 'license_number']),
            'transport_driver' => $driver ? $this->account($driver) : null,
            'transport_profile' => $driver ? CourierProfile::where('user_id', $driver->id)->first()?->only(['id', 'user_id', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'plate_number', 'license_number']) : null,
            'vehicle' => $vehicleId ? LogisticsFleet::find($vehicleId)?->only(['id', 'logistics_company_id', 'hub_id', 'assigned_driver_id', 'vehicle_type', 'status', 'is_active', 'restriction_version']) : null,
            'handlers' => HubHandler::where('user_id', $holder->id)->orderBy('id')->get()->map(fn ($handler) => $handler->getAttributes())->all()]);
    }

    private function lock(HandoverIssue $issue, array $users, array $context = []): array
    {
        $order = Order::whereKey(Delivery::whereKey($issue->delivery_id)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($issue->delivery_id)->lockForUpdate()->firstOrFail()->setRelation('order', $order);
        $members = LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->get();
        $manifests = LogisticsManifest::whereIn('id', array_filter([($context['mode'] ?? null) === 'hub_manifest' ? ($context['reference_id'] ?? null) : null,
            ...$members->pluck('manifest_id')->all()]))->get();
        $observation = ($context['mode'] ?? null) === 'hub_existing' ? LogisticsManifestEvent::find($context['reference_id'] ?? 0) : null;
        $case = $observation ? ParcelRecoveryCase::where('observation_event_id', $observation->id)->first() : null;
        $users = array_unique(array_filter([...$users, ...$manifests->pluck('driver_id')->all(), ...$order->items()->with('shop')->get()->pluck('shop.user_id')->all()]));
        $hubIds = array_unique(array_filter([$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id,
            $parcel->destination_bayan_hub_id, $observation?->hub_id, ...($case?->hub_ids ?? []), ...($context['case_contract']['hub_ids'] ?? [])]));
        $this->eligibility->lockNetwork($parcel->logistics_company_id, $users, $hubIds);
        CourierProfile::whereIn('user_id', $users)->orderBy('id')->lockForUpdate()->get();
        LogisticsFleet::whereIn('id', CourierProfile::whereIn('user_id', $users)->pluck('vehicle_id')->filter())->orderBy('id')->lockForUpdate()->get();
        HubHandler::whereIn('user_id', $users)->orderBy('id')->lockForUpdate()->get();
        LogisticsManifest::whereIn('id', array_filter([...$manifests->pluck('id')->all(), $observation?->manifest_id]))->orderBy('id')->lockForUpdate()->get();
        LogisticsManifestParcel::whereIn('manifest_id', $manifests->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        if ($case) {
            ParcelRecoveryCase::whereKey($case->id)->lockForUpdate()->first();
        }
        $issue = HandoverIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail()->setRelation('delivery', $parcel);

        return [$issue, $parcel];
    }

    private function input(array $input, array $rules = []): array
    {
        $input = app(WaybillScanInputService::class)->normalize($input);
        if (is_string($input['notes'] ?? null)) {
            $input['notes'] = trim(\Normalizer::normalize($input['notes'], \Normalizer::FORM_KC) ?: $input['notes'], ' ');
        }

        $data = Validator::make($input, ['notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'expected_version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000'],
            'request_token' => ['required', 'uuid'], ...$rules])->validate();
        foreach (['expected_version', 'holder_id', 'reference_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        foreach (['holder_verified', 'evidence_reviewed', 'possession_verified'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = true;
            }
        }

        return $data;
    }

    private function held(HandoverIssue $issue, Delivery $parcel): void
    {
        abort_unless($issue->state === 'needs_recovery' && $issue->active_delivery_id === $parcel->id
            && $issue->logistics_company_id === $parcel->logistics_company_id, 409, 'This is not the current reviewed parcel hold.');
    }

    private function reviewer(User $actor, HandoverIssue $issue, ?int $holder = null): void
    {
        abort_unless($actor->canAccessPortal() && $this->issues->canReview($actor, $issue)
            && ($actor->isAdmin() || ($actor->isLogistics() && $this->eligibility->isCompanyAdministrator($actor, $issue->logistics_company_id)))
            && $actor->id !== $holder, 403, 'An independent current company administrator or platform reviewer must authorize this hub recovery.');
    }

    private function latest(HandoverIssue $issue): ?HandoverRecoveryGrant
    {
        return $issue->recoveryGrants()->where('expires_at', '>', now())
            ->whereDoesntHave('events', fn ($events) => $events->whereIn('event_type', ['recovery_received', 'recovery_withdrawn']))->reorder()->latest('id')->first();
    }

    private function replay(HandoverIssue $issue, User $actor, array $data, string $type, string $fingerprint): ?HandoverIssueEvent
    {
        $event = $issue->events()->where('actor_id', $actor->id)->where('request_token', $data['request_token'])->first();
        abort_if($event && ($event->event_type !== $type || ! hash_equals($event->request_fingerprint, $fingerprint)), 409, 'This request already recorded different recovery evidence.');

        return $event;
    }

    private function event(HandoverIssue $issue, Delivery $parcel, User $actor, string $type, array $data, string $fingerprint, HandoverRecoveryGrant $grant, array $extra = []): HandoverIssueEvent
    {
        $issue->update(['version' => $issue->version + 1]);

        return $issue->events()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role, 'event_type' => $type, 'notes' => $data['notes'],
            'parcel_snapshot' => $this->canonical([...$this->issues->snapshot($parcel), ...$extra]), 'handover_recovery_grant_id' => $grant->id,
            'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint]);
    }

    public function authorize(User $user, HandoverIssue $issue, array $input): HandoverRecoveryGrant
    {
        $data = $this->input($input, ['mode' => ['required', Rule::in(self::MODES)], 'reference_id' => ['nullable', 'required_if:mode,hub_manifest,hub_existing',
            'prohibited_unless:mode,hub_manifest,hub_existing', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
            'holder_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'], 'holder_verified' => ['required', 'accepted'], 'evidence_reviewed' => ['required', 'accepted']]);
        $fingerprint = $this->issues->sourceToken(['issue' => $issue->id, 'action' => 'hub_recovery_authorized', ...$data]);
        try {
            return DB::transaction(function () use ($user, $issue, $data, $fingerprint) {
                [$issue, $parcel] = $this->lock($issue, [$user->id, (int) $data['holder_id']], $data);
                $actor = $this->issues->actor($user);
                $this->reviewer($actor, $issue, (int) $data['holder_id']);
                $previous = HandoverRecoveryGrant::where('authorized_by_id', $actor->id)->where('request_token', $data['request_token'])->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_fingerprint, $fingerprint), 409, 'This authorization request belongs to another recovery.');

                    return $previous;
                }
                $this->replay($issue, $actor, $data, 'recovery_authorized', $fingerprint);
                $this->held($issue, $parcel);
                $context = $this->context($parcel, $data['mode'], isset($data['reference_id']) ? (int) $data['reference_id'] : null, $actor);
                $source = $this->source($parcel, $context);
                abort_unless($issue->version === (int) $data['expected_version']
                    && hash_equals($this->issues->sourceToken($source), $data['source_token']), 409, 'The parcel, source or issue changed. Review its current facts.');
                $this->issues->evidencePath($actor, $issue);
                abort_if($this->latest($issue), 409, 'Continue or withdraw the existing authorization.');
                $holder = User::findOrFail($data['holder_id']);
                $grant = HandoverRecoveryGrant::create(['handover_issue_id' => $issue->id, 'delivery_id' => $parcel->id, 'logistics_company_id' => $parcel->logistics_company_id,
                    'hub_id' => $context['hub_id'], 'holder_id' => $holder->id, 'authorized_by_id' => $actor->id, 'mode' => $data['mode'],
                    'notes' => $data['notes'], 'source_snapshot' => $source, 'authority_snapshot' => $this->authority($actor, $holder, $parcel, $context),
                    'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint, 'expires_at' => now()->addDay()]);
                if ($grant->mode === 'hub_existing' && ! $context['case'] && $actor->isLogistics()) {
                    app(ParcelRecoveryService::class)->authorize($actor, LogisticsManifestEvent::findOrFail($context['reference_id']),
                        ['notes' => $data['notes'], 'request_token' => (string) Str::uuid(),
                            'source_token' => app(ParcelRecoveryService::class)->proposal($actor, LogisticsManifestEvent::findOrFail($context['reference_id']))['source_token']]);
                    $context = $this->context($parcel, $grant->mode, $context['reference_id'], $actor);
                }
                $issue->refresh()->setRelation('delivery', $parcel);
                $this->event($issue, $parcel, $actor, 'recovery_authorized', $data, $fingerprint, $grant, ['recovery_source' => $this->source($parcel, $context)]);

                return $grant;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This request already has a retained result. Refresh before retrying.');
        }
    }

    private function assertGrant(HandoverRecoveryGrant $grant, HandoverIssue $issue, Delivery $parcel, bool $progress = false): void
    {
        $this->held($issue, $parcel);
        abort_unless(in_array($grant->mode, self::MODES, true) && $grant->expires_at->isFuture() && $this->latest($issue)?->id === $grant->id, 409,
            'This authorization expired, was withdrawn or was replaced. The parcel stays held.');
        $actor = $this->issues->actor(User::findOrFail($grant->authorized_by_id));
        $this->reviewer($actor, $issue, $grant->holder_id);
        $context = $grant->source_snapshot['context'];
        $this->issues->evidencePath($actor, $issue);
        abort_unless(hash_equals($this->issues->sourceToken($grant->authority_snapshot),
            $this->issues->sourceToken($this->authority($actor, User::findOrFail($grant->holder_id), $parcel, $context))), 409,
            'Recovery authority changed. A fresh independent review is required.');
        abort_unless(hash_equals($this->issues->sourceToken($grant->source_snapshot['core']), $this->issues->sourceToken($this->core($parcel))), 409,
            'The original commercial or route source changed.');
        if ($progress && $grant->mode === 'hub_existing') {
            $case = ParcelRecoveryCase::where('observation_event_id', $context['reference_id'])->first();
            $contract = $case?->only(array_keys($context['case_contract']));
            abort_unless($contract && $this->canonical($contract) === $context['case_contract'], 409, 'The original physical recovery contract changed.');

            return;
        }
        $current = $this->context($parcel, $grant->mode, $context['reference_id'], $actor);
        $event = $grant->events()->whereIn('event_type', ['holder_acknowledged', 'recovery_authorized', 'linked_recovery_progress'])->reorder()->latest('id')->first();
        $expected = $event?->parcel_snapshot['recovery_source'] ?? $grant->source_snapshot;
        abort_unless(hash_equals($this->issues->sourceToken($expected), $this->issues->sourceToken($this->source($parcel, $current))), 409,
            'The original custody or transport changed. Refresh for a new authorization.');
    }

    public function acknowledge(User $user, HandoverRecoveryGrant $grant, array $input): HandoverIssueEvent
    {
        return $this->command($user, $grant, $input, false);
    }

    public function receive(User $user, HandoverRecoveryGrant $grant, array $input): HandoverIssueEvent
    {
        return $this->command($user, $grant, $input, true);
    }

    private function command(User $user, HandoverRecoveryGrant $grant, array $input, bool $receive): HandoverIssueEvent
    {
        $data = $this->input($input, ['barcode' => app(WaybillScanInputService::class)->barcodeRules(), 'possession_verified' => ['required', 'accepted']]);
        $type = $receive ? 'recovery_received' : 'holder_acknowledged';
        $fingerprint = $this->issues->sourceToken(['grant' => $grant->id, 'action' => $type, ...$data]);

        return DB::transaction(function () use ($user, $grant, $data, $receive, $type, $fingerprint) {
            [$issue, $parcel] = $this->lock($grant->issue, [$user->id, $grant->holder_id, $grant->authorized_by_id], $grant->source_snapshot['context']);
            $grant = HandoverRecoveryGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($user->id);
            if ($receive) {
                abort_unless($actor->id !== $grant->holder_id && $actor->isLogistics() && $actor->canAccessPortal()
                    && $this->eligibility->canScan($actor, $grant->hub), 403, 'Another eligible handler at the named hub must record actual receipt.');
            } else {
                abort_unless($actor->id === $grant->holder_id, 403);
                $this->holder($actor, $parcel, $grant->source_snapshot['context']);
            }
            if ($previous = $this->replay($issue, $actor, $data, $type, $fingerprint)) {
                abort_unless($previous->handover_recovery_grant_id === $grant->id, 409);

                return $previous;
            }
            $this->assertGrant($grant, $issue, $parcel);
            $context = $this->context($parcel, $grant->mode, $grant->source_snapshot['context']['reference_id'], User::findOrFail($grant->authorized_by_id));
            abort_unless($issue->version === (int) $data['expected_version']
                && hash_equals($this->issues->sourceToken($this->source($parcel, $context)), $data['source_token']), 409, 'The current recovery page changed.');
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($data['barcode'], $parcel);
            abort_if($grant->events()->where('event_type', $type)->exists(), 409, 'Retain the original acknowledgement or receipt.');
            if ($receive) {
                abort_unless($grant->mode !== 'hub_existing' && $grant->events()->where('event_type', 'holder_acknowledged')->where('actor_id', $grant->holder_id)->exists(), 409,
                    'Use the actual holder acknowledgement and original linked recovery route.');
                app(HandoverHoldService::class)->withVerifiedReceipt($parcel, $actor, $grant, function () use ($parcel, $actor, $grant, $data, $barcode, $context) {
                    if ($grant->mode === 'hub_manifest') {
                        $this->manifestReceipt($parcel, $actor, $grant, $context, $barcode, $data['notes']);
                    } elseif ($grant->mode === 'hub_failed_return') {
                        app(DeliveryRecoveryService::class)->receive($parcel, $actor, $grant->hub, $barcode, 'delivery_failed', $context['attempt']['reference']);
                    } else {
                        $before = DeliveryCheckpoint::state($parcel);
                        $custody = DeliveryCheckpoint::lastCustody($parcel);
                        $parcel->fill(['status' => $context['target_status'],
                            'current_hub_id' => $grant->hub_id, 'assigned_rider_id' => null, 'assigned_at' => null])->save();
                        $parcel->order->update(['status' => 'at_sorting_center']);
                        DeliveryCheckpoint::record($parcel, 'handover_recovery_received', actor: $actor, hub: $grant->hub, barcodeScanned: $barcode,
                            notes: 'Actual supervised hub receipt recorded now; original scans are retained.',
                            evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                                'custody_after' => ['kind' => 'hub', 'hub_id' => $grant->hub_id, 'handover_recovery_reference' => $grant->reference,
                                    'handover_issue_id' => $grant->handover_issue_id]]);
                    }
                });
                $parcel->refresh()->load('order');
            }
            $event = $this->event($issue, $parcel, $actor, $type, $data, $fingerprint, $grant,
                ['barcode_scanned' => $barcode, 'recovery_source' => $this->source($parcel, $receive ? $context : $this->context($parcel, $grant->mode, $context['reference_id'], User::findOrFail($grant->authorized_by_id)))]);
            if ($receive) {
                $issue->update(['state' => 'recovered', 'active_delivery_id' => null]);
            }

            return $event;
        }, 3);
    }

    public function verifyReceiptScope(Delivery $parcel, User $actor, HandoverRecoveryGrant $grant): void
    {
        $this->assertGrant($grant, $grant->issue, $parcel);
        abort_unless($grant->mode !== 'hub_existing' && $actor->id !== $grant->holder_id && $actor->canAccessPortal()
            && $actor->isLogistics() && $this->eligibility->canScan($actor, $grant->hub)
            && $grant->events()->where('event_type', 'holder_acknowledged')->where('actor_id', $grant->holder_id)->exists(), 403);
    }

    private function manifestReceipt(Delivery $parcel, User $actor, HandoverRecoveryGrant $grant, array $context, string $barcode, string $notes): void
    {
        $manifest = LogisticsManifest::whereKey($context['reference_id'])->lockForUpdate()->firstOrFail();
        $before = DeliveryCheckpoint::state($parcel);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $target = app(LogisticsManifestService::class)->receiptStatus($manifest);
        $targetState = [...$before, 'delivery_status' => $target, 'current_hub_id' => $manifest->destination_hub_id];
        $scan = LogisticsManifestEvent::create(['manifest_id' => $manifest->id, 'manifest_parcel_id' => $context['member_id'], 'actor_id' => $actor->id,
            'hub_id' => $grant->hub_id, 'event_type' => 'handover-recovery-received', 'barcode_scanned' => $barcode, 'reason' => $notes,
            'source_state' => $before, 'target_state' => $targetState, 'payload' => ['handover_recovery_grant_id' => $grant->id,
                'handover_issue_id' => $grant->handover_issue_id, 'original_member_id' => $context['member_id'], 'unlisted' => $context['member_id'] === null]]);
        if ($manifest->direction === 'recovery') {
            $checkpoint = app(ParcelRecoveryService::class)->receiveCorrective($manifest, $parcel, $actor, $scan);
        } else {
            $parcel->fill(['status' => $target, 'current_hub_id' => $grant->hub_id])->save();
            $checkpoint = DeliveryCheckpoint::record($parcel, $target, actor: $actor, hub: $grant->hub, barcodeScanned: $barcode,
                manifestNumber: $manifest->reference, scanProvenance: 'handover_recovery_receipt', notes: $notes,
                evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                    'custody_after' => ['kind' => 'hub', 'hub_id' => $grant->hub_id, 'manifest_scan_reference' => $scan->reference,
                        'handover_recovery_reference' => $grant->reference, 'handover_issue_id' => $grant->handover_issue_id]]);
        }
        $members = collect($context['manifest']['members'])->where('included', true);
        HandoverManifestReceipt::create(['handover_recovery_grant_id' => $grant->id, 'handover_issue_id' => $grant->handover_issue_id,
            'delivery_id' => $parcel->id, 'manifest_id' => $manifest->id, 'manifest_parcel_id' => $context['member_id'],
            'manifest_event_id' => $scan->id, 'checkpoint_id' => $checkpoint->id, 'hub_id' => $grant->hub_id, 'received_by_id' => $actor->id,
            'tracking_number_snapshot' => $barcode, 'route_snapshot' => $context['route'], 'source_manifest_snapshot' => $context['manifest'],
            'original_expected_count' => $members->count(), 'original_received_count' => $members->whereNotNull('received_at')->count()]);
        if ($context['member_id']) {
            LogisticsManifestParcel::whereKey($context['member_id'])->firstOrFail()->update(['active_delivery_id' => null]);
        }
        $manifest->increment('version');
    }

    public function withdraw(User $user, HandoverRecoveryGrant $grant, array $input): HandoverIssueEvent
    {
        $data = $this->input($input);
        $fingerprint = $this->issues->sourceToken(['grant' => $grant->id, 'action' => 'recovery_withdrawn', ...$data]);

        return DB::transaction(function () use ($user, $grant, $data, $fingerprint) {
            [$issue, $parcel] = $this->lock($grant->issue, [$user->id, $grant->holder_id, $grant->authorized_by_id], $grant->source_snapshot['context']);
            $actor = $this->issues->actor($user);
            $this->reviewer($actor, $issue, $grant->holder_id);
            if ($previous = $this->replay($issue, $actor, $data, 'recovery_withdrawn', $fingerprint)) {
                return $previous;
            }
            $this->held($issue, $parcel);
            abort_unless($issue->version === (int) $data['expected_version']
                && hash_equals($this->withdrawalToken($grant, $issue, $parcel), $data['source_token']), 409, 'Refresh the current recovery before withdrawing.');
            abort_if($grant->events()->whereIn('event_type', ['recovery_received', 'recovery_withdrawn'])->exists(), 409);

            return $this->event($issue, $parcel, $actor, 'recovery_withdrawn', $data, $fingerprint, $grant);
        }, 3);
    }

    private function withdrawalToken(HandoverRecoveryGrant $grant, HandoverIssue $issue, Delivery $parcel): string
    {
        return $this->issues->sourceToken(['grant' => $grant->id, 'issue' => $issue->id, 'version' => $issue->version, 'current' => $this->issues->snapshot($parcel)]);
    }

    public function runLinkedRecovery(Delivery $delivery, User $actor, LogisticsManifestEvent $observation, callable $command, array $accounts = [], bool $authorizationOnly = false): mixed
    {
        $active = app(HandoverHoldService::class)->active($delivery);
        abort_unless($active, 409);
        $grant = $this->latest($active);
        if ($grant?->mode !== 'hub_existing' || ($grant->source_snapshot['context']['reference_id'] ?? null) !== $observation->id) {
            throw new HandoverHoldException;
        }

        return DB::transaction(function () use ($active, $actor, $observation, $command, $accounts, $grant, $authorizationOnly) {
            [$issue, $parcel] = $this->lock($active, [$actor->id, $grant->holder_id, $grant->authorized_by_id, ...$accounts], $grant->source_snapshot['context']);
            $grant = HandoverRecoveryGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $context = $grant->source_snapshot['context'];
            $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->first();
            $hadCase = $case !== null;
            $this->assertGrant($grant, $issue, $parcel, $case !== null);
            abort_unless(! $case || $authorizationOnly || $grant->events()->where('event_type', 'holder_acknowledged')->where('actor_id', $grant->holder_id)->exists(), 409,
                'The actual holder must acknowledge before the original recovery route continues.');
            $this->issues->actor($actor);
            $before = $this->issues->snapshot($parcel);
            $result = $command();
            $parcel->refresh()->load('order');
            abort_unless(hash_equals($this->issues->sourceToken($grant->source_snapshot['core']), $this->issues->sourceToken($this->core($parcel))), 409);
            $case = ParcelRecoveryCase::where('observation_event_id', $observation->id)->firstOrFail();
            $contract = $this->canonical($case->only(array_keys($context['case_contract'])));
            abort_unless($contract === $context['case_contract'], 409, 'The original recovery route changed.');
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $received = $case->state === 'completed' && $case->active_delivery_id === null && $parcel->current_hub_id === $grant->hub_id
                && ($custody['kind'] ?? null) === 'hub' && ($custody['hub_id'] ?? null) === $grant->hub_id
                && $case->events()->where('event_type', 'completed')->whereHas('manifestEvent', fn ($query) => $query->where('event_type', 'parcel_received')->where('hub_id', $grant->hub_id))->exists();
            if ($received || ! $hadCase || $before['checkpoint_id'] !== $this->issues->snapshot($parcel)['checkpoint_id']) {
                $data = ['notes' => $received ? 'The original physical recovery recorded actual receipt at its correct receiving hub.'
                    : (! $hadCase ? 'The original physical recovery was authorized. This did not record a custody transfer.' : 'The original authorized physical recovery recorded its next actual custody step.'),
                    'request_token' => (string) Str::uuid()];
                $this->event($issue, $parcel, User::findOrFail($actor->id), $received ? 'recovery_received' : 'linked_recovery_progress',
                    $data, $this->issues->sourceToken(['grant' => $grant->id, 'case' => $case->id, 'version' => $case->version, ...$data]), $grant,
                    ['parcel_recovery_case_id' => $case->id, 'recovery_source' => $this->source($parcel, $received ? $context
                        : $this->context($parcel, $grant->mode, $context['reference_id'], User::findOrFail($grant->authorized_by_id)))]);
            }
            if ($received) {
                $issue->update(['state' => 'recovered', 'active_delivery_id' => null]);
            }

            return $result;
        }, 3);
    }

    public function detail(User $user, HandoverIssue $issue): array
    {
        $actor = $this->issues->actor($user);
        $parcel = $issue->delivery;
        $latest = $issue->recoveryGrants()->reorder()->latest('id')->first();
        $options = [];
        if ($issue->state === 'needs_recovery' && ! $this->latest($issue)
            && ($actor->isAdmin() || ($actor->isLogistics() && $this->eligibility->isCompanyAdministrator($actor, $issue->logistics_company_id)))
            && $this->issues->canReview($actor, $issue)) {
            $references = [['hub_intake', null], ['hub_failed_return', null]];
            foreach (LogisticsManifest::where('logistics_company_id', $parcel->logistics_company_id)->whereIn('status', ['draft', 'sealed', 'dispatched'])
                ->where(fn ($query) => $query->where('source_hub_id', $parcel->current_hub_id)->orWhereHas('parcels', fn ($members) => $members->where('active_delivery_id', $parcel->id)))
                ->latest('id')->limit(20)->pluck('id') as $id) {
                $references[] = ['hub_manifest', $id];
            }
            foreach (LogisticsManifestEvent::where('event_type', 'report-discrepancy')->where('barcode_scanned', $parcel->tracking_number)
                ->whereHas('manifest', fn ($query) => $query->where('logistics_company_id', $parcel->logistics_company_id))->latest('id')->limit(10)->pluck('id') as $id) {
                $references[] = ['hub_existing', $id];
            }
            foreach ($references as [$mode, $reference]) {
                try {
                    $context = $this->context($parcel, $mode, $reference, $actor);
                    $holderIds = HubHandler::eligible()->where('hub_id', $context['handler_hub_id'] ?? $context['hub_id'])->pluck('user_id')->all();
                    $holders = User::whereIn('id', array_filter([$context['driver_id'], ...$holderIds]))->orderBy('id')->get()
                        ->filter(function ($holder) use ($parcel, $context, $actor) {
                            try {
                                $this->holder($holder, $parcel, $context);

                                return $holder->id !== $actor->id;
                            } catch (HttpExceptionInterface) {
                                return false;
                            }
                        })->map(fn ($holder) => ['id' => $holder->id, 'name' => $holder->name, 'status' => $holder->status])->values()->all();
                    $options[] = ['key' => $mode.'-'.($reference ?? 'current'), 'mode' => $mode, 'reference_id' => $reference,
                        'label' => match ($mode) {
                            'hub_intake' => 'Actual Bayan Hub receipt', 'hub_failed_return' => 'Actual failed-attempt hub return',
                            'hub_manifest' => 'Corrective receipt: '.LogisticsManifest::findOrFail($reference)->reference,
                            default => 'Continue original physical recovery: '.LogisticsManifestEvent::findOrFail($reference)->reference
                        },
                        'source_token' => $this->issues->sourceToken($this->source($parcel, $context)), 'holder_options' => $holders];
                } catch (DomainException|HttpExceptionInterface) {
                    continue;
                }
            }
        }

        return ['options' => $options, 'grant' => $latest && in_array($latest->mode, self::MODES, true) ? $this->present($latest, $actor) : null];
    }

    public function present(HandoverRecoveryGrant $grant, User $actor): array
    {
        $issue = $grant->issue->load('delivery.order');
        $context = $grant->source_snapshot['context'];
        $valid = false;
        $source = null;
        try {
            $this->assertGrant($grant, $issue, $issue->delivery, $grant->mode === 'hub_existing' && $grant->events()->where('event_type', 'holder_acknowledged')->exists());
            $current = $this->context($issue->delivery, $grant->mode, $context['reference_id'], User::findOrFail($grant->authorized_by_id));
            $source = $this->source($issue->delivery, $current);
            $valid = true;
        } catch (DomainException|HttpExceptionInterface $error) {
            $blocker = $error->getMessage();
        }
        $ack = $grant->events()->where('event_type', 'holder_acknowledged')->exists();
        $closed = $grant->events()->whereIn('event_type', ['recovery_received', 'recovery_withdrawn'])->exists();
        $case = $grant->mode === 'hub_existing' ? ParcelRecoveryCase::where('observation_event_id', $context['reference_id'])->first() : null;

        return ['id' => $grant->id, 'reference' => $grant->reference, 'holder_name' => $grant->holder->name, 'holder_id' => $grant->holder_id,
            'hub' => $grant->hub->only(['id', 'name', 'address']), 'expires_at' => $grant->expires_at->toIso8601String(), 'mode' => $grant->mode,
            'acknowledged' => $ack, 'closed' => $closed, 'blocker' => $blocker ?? null,
            'can_acknowledge' => $valid && ! $ack && $actor->id === $grant->holder_id,
            'can_receive' => $valid && $ack && $grant->mode !== 'hub_existing' && $actor->id !== $grant->holder_id
                && $actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->canScan($actor, $grant->hub),
            'can_withdraw' => ! $closed && $issue->state === 'needs_recovery' && $actor->canAccessPortal()
                && $this->issues->canReview($actor, $issue) && $actor->id !== $grant->holder_id,
            'expected_version' => $issue->version, 'source_token' => $source ? $this->issues->sourceToken($source) : '',
            'withdraw_source_token' => $this->withdrawalToken($grant, $issue, $issue->delivery),
            'order_number' => $issue->delivery->order->order_number, 'tracking_number' => $issue->delivery->tracking_number,
            'manifest_reference' => isset($context['manifest']) ? LogisticsManifest::find($context['reference_id'])?->reference : null,
            'linked_recovery_reference' => $case?->reference,
            'continuation_url' => $valid && $grant->mode === 'hub_existing' && $actor->isLogistics() && app(ParcelRecoveryService::class)->observations($actor)->whereKey($context['reference_id'])->exists()
                ? '/hub/parcel-recovery/'.$context['reference_id'] : null];
    }
}
