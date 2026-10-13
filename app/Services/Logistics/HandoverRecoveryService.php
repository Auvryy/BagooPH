<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HandoverIssue;
use App\Models\HandoverIssueEvent;
use App\Models\HandoverRecoveryGrant;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Rules\AsciiPositiveInteger;
use App\Services\ShopEligibilityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class HandoverRecoveryService
{
    public function __construct(private readonly HandoverIssueService $issues, private readonly LogisticsEligibilityService $eligibility) {}

    private function source(Delivery $parcel): array
    {
        $items = $parcel->order->items()->orderBy('id')->get();

        return [...$this->issues->snapshot($parcel), 'parcel' => $parcel->only(['tracking_number', 'delivery_type', 'destination_bin',
            'shuttle_manifest_number', 'truck_manifest_number', 'failure_attempts', 'picked_up_at', 'delivered_at', 'assigned_at',
            'pickup_address', 'delivery_address', 'delivery_recipient_name', 'delivery_phone']),
            'order' => $parcel->order->only(['id', 'buyer_id', 'subtotal', 'voucher_id', 'voucher_discount', 'shipping_fee',
                'total_amount', 'payment_method', 'payment_status', 'delivery_type', 'pickup_hub_id', 'destination_barangay',
                'recipient_name', 'recipient_phone', 'shipping_address', 'shipping_city', 'shipping_province', 'shipping_postal_code']),
            'items' => $items->map(fn ($item) => $item->only(['id', 'product_id', 'shop_id', 'quantity', 'unit_price', 'subtotal', 'sku_snapshot', 'color', 'size']))->all(),
            'shops' => Shop::whereIn('id', $items->pluck('shop_id'))->orderBy('id')->get()
                ->map(fn ($shop) => $shop->only(['id', 'user_id', 'root_category_id', 'status', 'review_status', 'review_version', 'review_decision_id']))->all()];
    }

    private function account(User $user): array
    {
        return $user->only(['id', 'role', 'status', 'kyc_status', 'email_verified_at', 'birthday', 'identity_version', 'restriction_version', 'closed_at']);
    }

    private function mode(Delivery $parcel, HandoverIssue $issue): ?string
    {
        if (($parcel->order->status === 'ready_for_pickup' && in_array($parcel->status, ['unassigned', 'assigned_pickup'], true))
            || ($parcel->order->status === 'picked_up' && $parcel->status === 'picked_up')) {
            return 'pickup';
        }

        if ($parcel->delivery_type !== 'doorstep') {
            return null;
        }
        if ($parcel->status === 'assigned_to_rider' && $parcel->order->status === 'assigned_to_rider') {
            return 'departure';
        }
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($parcel->status === 'out_for_delivery' && $parcel->order->status === 'out_for_delivery'
            && ($custody['handover_issue_id'] ?? null) === $issue->id
            && HandoverRecoveryGrant::where('reference', $custody['handover_recovery_reference'] ?? '')
                ->where('handover_issue_id', $issue->id)->where('delivery_id', $parcel->id)->where('mode', 'departure')
                ->where('holder_id', $parcel->assigned_rider_id)->whereHas('events', fn ($events) => $events->where('event_type', 'holder_acknowledged'))->exists()) {
            return 'departure';
        }

        return null;
    }

    private function holder(User $holder, Delivery $parcel, int $hubId): CourierProfile
    {
        abort_unless($holder->isCourier() && ! $holder->closed_at && $holder->email_verified_at && $holder->isKycApproved()
            && $holder->hasEligibleBirthDate() && in_array($holder->status, ['active', 'inactive', 'suspended'], true), 403, 'The named holder is not eligible for this recovery.');
        $profile = CourierProfile::where('user_id', $holder->id)->first();
        abort_unless($profile && $profile->logistics_company_id === $parcel->logistics_company_id && $profile->assigned_hub_id === $hubId, 403,
            'The named holder must belong to this company and receiving hub.');
        abort_if($holder->status === 'active' && ! $this->eligibility->isOperational($profile), 403, 'This holder has lost operational authority.');

        return $profile;
    }

    private function authority(User $authorizer, User $holder, Delivery $parcel, int $hubId): array
    {
        $profile = $this->holder($holder, $parcel, $hubId);
        $company = LogisticsCompany::findOrFail($parcel->logistics_company_id);
        $hubs = LogisticsHub::whereIn('id', [$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id,
            $parcel->destination_mother_hub_id, $parcel->destination_bayan_hub_id])->orderBy('id')->get();

        return ['authorizer' => $this->account($authorizer), 'holder' => $this->account($holder),
            'profile' => $profile->only(['id', 'user_id', 'logistics_company_id', 'assigned_hub_id', 'vehicle_id', 'vehicle_type', 'plate_number', 'license_number']),
            'vehicle' => $profile->vehicle?->only(['id', 'is_active', 'status', 'restriction_version', 'assigned_driver_id', 'hub_id', 'logistics_company_id']),
            'company' => $company->only(['id', 'user_id', 'is_active', 'status', 'restriction_version']),
            'owner' => $this->account($company->user),
            'hubs' => $hubs->map(fn ($hub) => $hub->only(['id', 'tier', 'is_active', 'restriction_version', 'logistics_company_id']))->all()];
    }

    private function requireHeld(HandoverIssue $issue, Delivery $parcel): void
    {
        abort_unless($issue->state === 'needs_recovery' && $issue->active_delivery_id === $parcel->id
            && $issue->logistics_company_id === $parcel->logistics_company_id, 409, 'This report is not a current reviewed handover hold.');
    }

    private function lock(HandoverIssue $issue, array $accounts = []): array
    {
        $order = Order::whereKey(Delivery::whereKey($issue->delivery_id)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($issue->delivery_id)->lockForUpdate()->firstOrFail()->setRelation('order', $order);
        $shops = $order->items()->pluck('shop_id')->unique();
        $accounts = array_unique([...$accounts, ...Shop::whereIn('id', $shops)->pluck('user_id')->all()]);
        $this->eligibility->lockNetwork((int) $parcel->logistics_company_id, $accounts,
            [$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id, $parcel->destination_bayan_hub_id]);
        $profiles = CourierProfile::whereIn('user_id', array_filter($accounts))->orderBy('id')->lockForUpdate()->get();
        LogisticsFleet::whereIn('id', $profiles->pluck('vehicle_id')->filter())->orderBy('id')->lockForUpdate()->get();
        HubHandler::whereIn('user_id', array_filter($accounts))->orderBy('id')->lockForUpdate()->get();
        Shop::whereIn('id', $shops)->orderBy('id')->lockForUpdate()->get();
        $locked = HandoverIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('delivery', $parcel);

        return [$locked, $parcel];
    }

    private function input(array $input, array $rules = []): array
    {
        $input = app(WaybillScanInputService::class)->normalize($input);
        if (is_string($input['notes'] ?? null)) {
            $input['notes'] = trim(\Normalizer::normalize($input['notes'], \Normalizer::FORM_KC) ?: $input['notes'], ' ');
        }

        return Validator::make($input, ['notes' => ['required', new ApplicationText('notes', 10, 1000)],
            'source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'expected_version' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:1000000'],
            'request_token' => ['required', 'uuid'], ...$rules])->validate();
    }

    private function current(HandoverIssue $issue, Delivery $parcel, array $input): void
    {
        $this->requireHeld($issue, $parcel);
        abort_unless($issue->version === (int) $input['expected_version']
            && hash_equals($this->issues->sourceToken($this->source($parcel)), $input['source_token']), 409,
            'The issue or parcel changed. Refresh its current facts before continuing.');
    }

    private function replay(HandoverIssue $issue, User $actor, array $input, string $type, string $fingerprint): ?HandoverIssueEvent
    {
        $event = $issue->events()->where('actor_id', $actor->id)->where('request_token', $input['request_token'])->first();
        if ($event) {
            abort_unless($event->event_type === $type && hash_equals($event->request_fingerprint, $fingerprint), 409,
                'This request already recorded a different action. Retain its original result or refresh for a new action.');
        }

        return $event;
    }

    private function event(HandoverIssue $issue, Delivery $parcel, User $actor, string $type, array $input, string $fingerprint,
        ?HandoverRecoveryGrant $grant = null, array $extra = []): HandoverIssueEvent
    {
        $issue->update(['version' => $issue->version + 1]);

        return $issue->events()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role, 'event_type' => $type,
            'notes' => $input['notes'], 'parcel_snapshot' => [...$this->issues->snapshot($parcel), ...$extra],
            'handover_recovery_grant_id' => $grant?->id, 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint]);
    }

    public function confirmFulfillment(User $user, HandoverIssue $issue, array $input): HandoverIssueEvent
    {
        $data = $this->input($input, ['fulfillment_verified' => ['required', 'accepted'], 'barcode' => app(WaybillScanInputService::class)->barcodeRules()]);
        $fingerprint = $this->issues->sourceToken(['issue' => $issue->id, 'action' => 'fulfillment_confirmed', ...$data]);

        return DB::transaction(function () use ($user, $issue, $data, $fingerprint) {
            [$issue, $parcel] = $this->lock($issue, [$user->id]);
            $actor = $this->issues->actor($user);
            $shops = $parcel->order->items()->pluck('shop_id')->unique();
            $shop = $shops->count() === 1 ? Shop::whereKey($shops->first())->lockForUpdate()->first() : null;
            abort_unless($actor->isSeller() && $shop?->user_id === $actor->id, 403);
            app(ShopEligibilityService::class)->lockCategories();
            app(ShopEligibilityService::class)->assertEligible($shop);
            if ($previous = $this->replay($issue, $actor, $data, 'fulfillment_confirmed', $fingerprint)) {
                return $previous;
            }
            $this->current($issue, $parcel, $data);
            $this->issues->evidencePath($actor, $issue);
            app(WaybillScanInputService::class)->matchedBarcode($data['barcode'], $parcel);
            abort_unless(in_array($parcel->order->status, ['placed', 'confirmed', 'preparing'], true)
                && $parcel->status === 'unassigned' && ! $parcel->courier_id && ! $parcel->assigned_rider_id && ! $parcel->current_hub_id
                && ! $this->latestActive($issue), 409, 'Only omitted, unassigned seller fulfillment can use this confirmation.');
            $before = $this->source($parcel);
            $steps = ['placed' => 'confirmed', 'confirmed' => 'preparing', 'preparing' => 'ready_for_pickup'];
            while (isset($steps[$parcel->order->status])) {
                $from = DeliveryCheckpoint::state($parcel);
                $parcel->order->update(['status' => $steps[$parcel->order->status]]);
                DeliveryCheckpoint::record($parcel, 'handover_fulfillment_confirmed', actor: $actor,
                    notes: 'Seller confirmed an omitted commercial fulfillment step during reviewed recovery. This is not a past packing or staging scan.',
                    evidence: ['source_state' => $from, 'target_state' => DeliveryCheckpoint::state($parcel)]);
            }

            return $this->event($issue, $parcel, $actor, 'fulfillment_confirmed', $data, $fingerprint, extra: ['recovery_source_before' => $before]);
        }, 3);
    }

    private function eligibleSource(Delivery $parcel, User $holder, HandoverIssue $issue): array
    {
        $mode = $this->mode($parcel, $issue);
        abort_unless($parcel->order->items()->exists() && $parcel->order->items()->pluck('shop_id')->unique()->filter()->count() === 1
            && ! $parcel->order->items()->whereNull('shop_id')->exists(), 409, 'Recovery requires one known owning shop and the original purchased items.');
        abort_unless($mode && ! $parcel->delivered_at && ! $parcel->failure_attempts && ! LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->exists(), 409,
            'This source needs another recovery review. H02 covers seller/pickup and assigned missed departure only.');
        $route = [$parcel->origin_bayan_hub_id, $parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id, $parcel->destination_bayan_hub_id];
        abort_unless(! in_array(null, $route, true) && $route[0] !== $route[3]
            && LogisticsHub::eligible()->whereIn('id', [$route[0], $route[3]])->where('tier', 'local_bayan_hub')->count() === 2
            && LogisticsHub::eligible()->whereIn('id', [$route[1], $route[2]])->where('tier', 'regional_mother_hub')->count() === count(array_unique([$route[1], $route[2]])), 409,
            'Recovery requires the original eligible Bayan and Mother Hub route.');
        $hubId = $mode === 'pickup' ? $parcel->origin_bayan_hub_id : $parcel->destination_bayan_hub_id;
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($mode === 'pickup') {
            abort_unless(! $parcel->assigned_rider_id && ! $parcel->current_hub_id && (! $parcel->courier_id || $parcel->courier_id === $holder->id), 409,
                'The original pickup assignment fixes the holder; another rider needs a separate source review.');
            abort_unless($parcel->status !== 'picked_up' || (($custody['kind'] ?? null) === 'courier' && ($custody['user_id'] ?? null) === $holder->id), 409,
                'The recorded pickup custody does not match this holder.');
            abort_unless($parcel->status === 'picked_up' || in_array($custody['kind'] ?? 'unknown', ['seller', 'unknown'], true), 409,
                'This parcel has another recorded custodian.');
        } else {
            $atHub = $parcel->status === 'assigned_to_rider' && $parcel->current_hub_id === $hubId
                && ($custody['kind'] ?? null) === 'hub' && ($custody['hub_id'] ?? null) === $hubId;
            $retainedAcknowledgement = $parcel->status === 'out_for_delivery' && ! $parcel->current_hub_id
                && ($custody['kind'] ?? null) === 'courier' && ($custody['user_id'] ?? null) === $holder->id;
            abort_unless($parcel->assigned_rider_id === $holder->id && ($atHub || $retainedAcknowledgement), 409,
                'Missed departure recovery needs the original rider assignment and destination-hub custody.');
        }
        $this->holder($holder, $parcel, $hubId);

        return [$mode, $hubId];
    }

    public function authorize(User $user, HandoverIssue $issue, array $input): HandoverRecoveryGrant
    {
        $data = $this->input($input, ['holder_id' => ['required', new AsciiPositiveInteger, 'integer', 'min:1', 'max:2147483647'],
            'holder_verified' => ['required', 'accepted'], 'evidence_reviewed' => ['required', 'accepted']]);
        $fingerprint = $this->issues->sourceToken(['issue' => $issue->id, 'action' => 'recovery_authorized', ...$data]);

        try {
            return DB::transaction(function () use ($user, $issue, $data, $fingerprint) {
                [$issue, $parcel] = $this->lock($issue, [$user->id, (int) $data['holder_id']]);
                $actor = $this->issues->actor($user);
                abort_unless($this->issues->canReview($actor, $issue) && $actor->id !== (int) $data['holder_id'], 403,
                    'A different eligible company administrator or platform admin must authorize the known holder.');
                $previous = HandoverRecoveryGrant::where('authorized_by_id', $actor->id)->where('request_token', $data['request_token'])->first();
                if ($previous) {
                    abort_unless(hash_equals($previous->request_fingerprint, $fingerprint), 409, 'This authorization request was used for another recovery.');

                    return $previous;
                }
                $this->replay($issue, $actor, $data, 'recovery_authorized', $fingerprint);
                $this->current($issue, $parcel, $data);
                $this->issues->evidencePath($actor, $issue);
                abort_if($this->latestActive($issue), 409, 'Use or withdraw the existing unexpired authorization before creating another.');
                $holder = User::findOrFail($data['holder_id']);
                [$mode, $hubId] = $this->eligibleSource($parcel, $holder, $issue);
                $grant = HandoverRecoveryGrant::create(['handover_issue_id' => $issue->id, 'delivery_id' => $parcel->id,
                    'logistics_company_id' => $parcel->logistics_company_id, 'hub_id' => $hubId, 'holder_id' => $holder->id,
                    'authorized_by_id' => $actor->id, 'mode' => $mode, 'notes' => $data['notes'],
                    'source_snapshot' => $this->source($parcel), 'authority_snapshot' => $this->authority($actor, $holder, $parcel, $hubId),
                    'request_token' => $data['request_token'], 'request_fingerprint' => $fingerprint, 'expires_at' => now()->addDay()]);
                $this->event($issue, $parcel, $actor, 'recovery_authorized', $data, $fingerprint, $grant);

                return $grant;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            abort(409, 'This request already has a recorded result. Refresh before retrying.');
        }
    }

    private function latestActive(HandoverIssue $issue): ?HandoverRecoveryGrant
    {
        return HandoverRecoveryGrant::where('handover_issue_id', $issue->id)->where('expires_at', '>', now())
            ->whereDoesntHave('events', fn ($events) => $events->whereIn('event_type', ['recovery_received', 'recovery_withdrawn']))->latest('id')->first();
    }

    private function assertGrant(HandoverRecoveryGrant $grant, HandoverIssue $issue, Delivery $parcel): void
    {
        $this->requireHeld($issue, $parcel);
        abort_unless($grant->expires_at->isFuture() && $this->latestActive($issue)?->id === $grant->id, 409,
            'The authorization expired, was withdrawn or was replaced. The parcel remains on hold.');
        $authorizer = $this->issues->actor(User::findOrFail($grant->authorized_by_id));
        abort_unless($this->issues->canReview($authorizer, $issue) && $authorizer->id !== $grant->holder_id, 403);
        $holder = User::findOrFail($grant->holder_id);
        abort_unless(hash_equals($this->issues->sourceToken($grant->authority_snapshot),
            $this->issues->sourceToken($this->authority($authorizer, $holder, $parcel, $grant->hub_id))), 409,
            'The holder or reviewing network changed. A fresh independent authorization is required.');
        $ack = $grant->events()->where('event_type', 'holder_acknowledged')->first();
        $expected = $ack?->parcel_snapshot['recovery_source'] ?? $grant->source_snapshot;
        abort_unless(hash_equals($this->issues->sourceToken($expected), $this->issues->sourceToken($this->source($parcel))), 409,
            'The parcel or original custody changed. Review a fresh source.');
        $this->issues->evidencePath($authorizer, $issue);
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

        return DB::transaction(function () use ($user, $grant, $data, $type, $receive, $fingerprint) {
            [$issue, $parcel] = $this->lock($grant->issue, [$user->id, $grant->holder_id, $grant->authorized_by_id]);
            $grant = HandoverRecoveryGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($user->id);
            $hub = LogisticsHub::findOrFail($grant->hub_id);
            if ($receive) {
                abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->canScan($actor, $hub), 403,
                    'Only an eligible handler at the named receiving hub may record actual receipt.');
            } else {
                abort_unless($actor->id === $grant->holder_id, 403);
                $this->holder($actor, $parcel, $grant->hub_id);
            }
            if ($previous = $this->replay($issue, $actor, $data, $type, $fingerprint)) {
                abort_unless($previous->handover_recovery_grant_id === $grant->id, 409);

                return $previous;
            }
            $this->current($issue, $parcel, $data);
            $this->assertGrant($grant, $issue, $parcel);
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($data['barcode'], $parcel);
            abort_if($grant->events()->where('event_type', $type)->exists(), 409, 'This action already has its original evidence. Retain that request and receipt.');
            $before = $this->source($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            if ($receive) {
                $ack = $grant->events()->where('event_type', 'holder_acknowledged')->where('actor_id', $grant->holder_id)->first();
                abort_unless($ack, 409, 'The actual holder must acknowledge the matching parcel before its receiving scan.');
                $parcel->fill(['status' => $grant->mode === 'pickup' ? 'arrived_at_origin_hub' : 'arrived_at_destination_hub', 'current_hub_id' => $hub->id]);
                if ($grant->mode === 'departure') {
                    $parcel->fill(['assigned_rider_id' => null, 'assigned_at' => null]);
                }
                $parcel->save();
                $parcel->order->update(['status' => 'at_sorting_center']);
                $after = ['kind' => 'hub', 'hub_id' => $hub->id, 'handover_recovery_reference' => $grant->reference, 'handover_issue_id' => $issue->id];
            } else {
                if ($grant->mode === 'pickup' && $parcel->status !== 'picked_up') {
                    $attributes = ['status' => 'picked_up', 'picked_up_at' => now(), 'courier_id' => $actor->id];
                    if (! $parcel->courier_id) {
                        $attributes['assigned_at'] = now();
                    }
                    $parcel->fill($attributes)->save();
                    $parcel->order->update(['status' => 'picked_up']);
                } elseif ($grant->mode === 'departure') {
                    $parcel->fill(['status' => 'out_for_delivery', 'current_hub_id' => null])->save();
                    $parcel->order->update(['status' => 'out_for_delivery']);
                }
                $after = ['kind' => 'courier', 'user_id' => $actor->id, 'phase' => $grant->mode === 'pickup' ? 'pickup' : 'final_mile',
                    'handover_recovery_reference' => $grant->reference, 'handover_issue_id' => $issue->id];
            }
            DeliveryCheckpoint::record($parcel, $receive ? 'handover_recovery_received' : 'handover_holder_acknowledged', actor: $actor,
                hub: $receive ? $hub : null, barcodeScanned: $barcode,
                notes: $receive ? 'Actual parcel received at the independently authorized recovery hub.' : 'Named holder confirmed current possession during recovery. This does not backdate a missing scan.',
                evidence: ['source_state' => $before, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                    'custody_after' => [...$after, 'original_checkpoint_id' => $grant->source_snapshot['checkpoint_id'] ?? null]]);
            $parcel->refresh()->load('order');
            $event = $this->event($issue, $parcel, $actor, $type, $data, $fingerprint, $grant,
                ['recovery_source_before' => $before, 'recovery_source' => $this->source($parcel), 'barcode_scanned' => $barcode]);
            if ($receive) {
                $issue->update(['state' => 'recovered', 'active_delivery_id' => null]);
            }

            return $event;
        }, 3);
    }

    public function withdraw(User $user, HandoverRecoveryGrant $grant, array $input): HandoverIssueEvent
    {
        $data = $this->input($input);
        $fingerprint = $this->issues->sourceToken(['grant' => $grant->id, 'action' => 'recovery_withdrawn', ...$data]);

        return DB::transaction(function () use ($user, $grant, $data, $fingerprint) {
            [$issue, $parcel] = $this->lock($grant->issue, [$user->id, $grant->holder_id, $grant->authorized_by_id]);
            $actor = $this->issues->actor($user);
            abort_unless($this->issues->canReview($actor, $issue) && $actor->id !== $grant->holder_id, 403);
            if ($previous = $this->replay($issue, $actor, $data, 'recovery_withdrawn', $fingerprint)) {
                abort_unless($previous->handover_recovery_grant_id === $grant->id, 409);

                return $previous;
            }
            $this->current($issue, $parcel, $data);
            abort_if($grant->events()->whereIn('event_type', ['recovery_received', 'recovery_withdrawn'])->exists(), 409, 'This authorization already has its final recorded outcome.');

            return $this->event($issue, $parcel, $actor, 'recovery_withdrawn', $data, $fingerprint, $grant);
        }, 3);
    }

    public function detail(User $user, HandoverIssue $issue): array
    {
        $actor = $this->issues->actor($user);
        $parcel = $issue->delivery;
        $held = $issue->state === 'needs_recovery' && $issue->active_delivery_id === $parcel->id;
        $grant = HandoverRecoveryGrant::where('handover_issue_id', $issue->id)->latest('id')->first();
        $active = $held ? $this->latestActive($issue) : null;
        $mode = $this->mode($parcel, $issue);
        $hubId = $mode === 'departure' ? $parcel->destination_bayan_hub_id : $parcel->origin_bayan_hub_id;
        $fixedHolder = $mode === 'departure' ? $parcel->assigned_rider_id : $parcel->courier_id;
        $canAuthorize = $held && ! $active && $this->issues->canReview($actor, $issue);
        $holders = $canAuthorize && $mode ? CourierProfile::with('user')->where('logistics_company_id', $parcel->logistics_company_id)->where('assigned_hub_id', $hubId)
            ->when($fixedHolder, fn ($query) => $query->where('user_id', $fixedHolder))->orderBy('user_id')->get()
            ->filter(fn ($profile) => $profile->user?->isCourier() && ! $profile->user->closed_at && $profile->user->isKycApproved()
                && $profile->user->email_verified_at && $profile->user->hasEligibleBirthDate()
                && in_array($profile->user->status, ['active', 'inactive', 'suspended'], true)
                && ($profile->user->status !== 'active' || $this->eligibility->isOperational($profile)))
            ->map(fn ($profile) => ['id' => $profile->user_id, 'name' => $profile->user->name, 'status' => $profile->user->status])->values()->all() : [];
        $shop = $parcel->order->shop;
        $needsFulfillment = $held && $parcel->status === 'unassigned' && in_array($parcel->order->status, ['placed', 'confirmed', 'preparing'], true);

        return ['held' => $held, 'source_token' => $this->issues->sourceToken($this->source($parcel)), 'mode' => $mode,
            'fulfillment_required' => $needsFulfillment,
            'can_confirm_fulfillment' => $needsFulfillment && $actor->isSeller() && $shop?->user_id === $actor->id && app(ShopEligibilityService::class)->isEligible($shop),
            'can_authorize' => $canAuthorize && $mode !== null, 'holder_options' => $holders,
            'grant' => $grant ? $this->present($grant, $actor, $issue) : null];
    }

    private function present(HandoverRecoveryGrant $grant, User $actor, ?HandoverIssue $issue = null): array
    {
        $issue ??= $grant->issue->load('delivery.order');
        $valid = false;
        try {
            $this->assertGrant($grant, $issue, $issue->delivery);
            $valid = true;
        } catch (HttpExceptionInterface $error) {
            $blocker = $error->getMessage();
        }
        $ack = $grant->events()->where('event_type', 'holder_acknowledged')->exists();
        $closed = $grant->events()->whereIn('event_type', ['recovery_received', 'recovery_withdrawn'])->exists();

        return ['id' => $grant->id, 'reference' => $grant->reference, 'holder_name' => $grant->holder->name,
            'holder_id' => $grant->holder_id, 'hub' => $grant->hub->only(['id', 'name', 'address']), 'expires_at' => $grant->expires_at->toIso8601String(),
            'mode' => $grant->mode, 'acknowledged' => $ack, 'closed' => $closed, 'blocker' => $blocker ?? null,
            'can_acknowledge' => $valid && ! $ack && $actor->id === $grant->holder_id,
            'can_receive' => $valid && $ack && $actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->canScan($actor, $grant->hub),
            'can_withdraw' => ! $closed && $issue->state === 'needs_recovery' && $actor->canAccessPortal()
                && $this->issues->canReview($actor, $issue) && $actor->id !== $grant->holder_id,
            'expected_version' => $issue->version, 'source_token' => $this->issues->sourceToken($this->source($issue->delivery)),
            'order_number' => $issue->delivery->order->order_number, 'tracking_number' => $issue->delivery->tracking_number];
    }

    public function restrictedOwn(User $user): array
    {
        $actor = User::findOrFail($user->id);
        if (! $actor->isCourier() || $actor->closed_at || ! in_array($actor->status, ['inactive', 'suspended'], true)) {
            return [];
        }

        return HandoverRecoveryGrant::where('holder_id', $actor->id)->where('expires_at', '>', now())
            ->with(['issue.delivery.order', 'holder', 'hub'])->latest('id')->get()
            ->map(fn ($grant) => $this->present($grant, $actor))
            ->filter(fn ($grant) => ! $grant['closed'] && ! $grant['blocker'] && ($grant['can_acknowledge'] || $grant['acknowledged']))->values()->all();
    }
}
