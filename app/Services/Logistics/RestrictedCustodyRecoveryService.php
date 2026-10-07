<?php

namespace App\Services\Logistics;

use App\Models\CustodyRecoveryGrant;
use App\Models\CustodyRecoveryReceipt;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\AccountRestrictionService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RestrictedCustodyRecoveryService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function authorizeActor(User $actor, int $companyId): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && ($actor->isAdmin() || $this->eligibility->isCompanyAdministrator($actor, $companyId)), 403);

        return $actor;
    }

    public function proposal(User $actor, RestrictionAffectedWork $work): array
    {
        if (! $work->delivery_id) {
            throw new DomainException('This affected work has no recorded parcel custody to recover.');
        }
        $parcel = Delivery::with('order')->findOrFail($work->delivery_id);
        $this->authorizeActor($actor, $parcel->logistics_company_id);
        $facts = $this->facts($work, $parcel);

        return ['source_token' => app(AccountRestrictionService::class)->token($facts), 'facts' => $facts];
    }

    private function facts(RestrictionAffectedWork $work, Delivery $parcel): array
    {
        $decision = RestrictionDecision::findOrFail($work->restriction_decision_id);
        $holder = DeliveryCheckpoint::lastCustody($parcel);
        $courier = User::find($holder['user_id'] ?? null);
        $phase = $parcel->status === 'picked_up' && $parcel->order->status === 'picked_up' ? 'pickup'
            : ($parcel->status === 'out_for_delivery' && $parcel->order->status === 'out_for_delivery' && $parcel->delivery_type === 'doorstep' ? 'final_mile' : null);
        $hubId = $phase === 'pickup' ? $parcel->origin_bayan_hub_id : $parcel->destination_bayan_hub_id;
        $checkpoint = $parcel->checkpoints()->whereNull('source_checkpoint_id')->whereNotNull('custody_after')->latest('id')->first();
        $hub = LogisticsHub::eligible()->whereKey($hubId)->where('tier', 'local_bayan_hub')->where('logistics_company_id', $parcel->logistics_company_id)->first();
        if (! $phase || ($holder['kind'] ?? null) !== 'courier' || ! $courier?->isCourier() || $courier->closed_at
            || ! in_array($courier->status, ['suspended', 'inactive'], true) || ! $checkpoint?->barcode_scanned
            || $checkpoint->checkpoint_type !== ($phase === 'pickup' ? 'picked_up' : 'out_for_delivery') || $checkpoint->scanned_by_id !== $courier->id
            || $courier->id !== ($phase === 'pickup' ? $parcel->courier_id : $parcel->assigned_rider_id)
            || $decision->subject_type !== 'account' || $decision->subject_id !== $courier->id
            || $decision->after_state['subject']['restriction_version'] !== $courier->restriction_version
            || $work->order_id !== $parcel->order_id || ! $hub
            || $courier->courierProfile?->logistics_company_id !== $parcel->logistics_company_id
            || $courier->courierProfile?->assigned_hub_id !== $hubId) {
            throw new DomainException('This recovery requires recorded restricted-rider custody and its eligible original receiving hub. Other source or facility blockers remain open for review.');
        }

        return ['work_id' => $work->id, 'delivery_id' => $parcel->id, 'company_id' => $parcel->logistics_company_id,
            'source_checkpoint_id' => $checkpoint->id, 'courier_id' => $courier->id, 'phase' => $phase, 'hub_id' => $hubId,
            'parcel' => DeliveryCheckpoint::state($parcel), 'custody' => $holder,
            'restriction' => $courier->only(['id', 'role', 'status', 'restriction_version', 'identity_version', 'closed_at']),
            'hub' => $hub->only(['id', 'is_active', 'restriction_version', 'logistics_company_id']),
            'company' => $hub->company->only(['id', 'is_active', 'status', 'restriction_version'])];
    }

    public function grant(User $actor, RestrictionAffectedWork $work, array $input): CustodyRecoveryGrant
    {
        if (! $work->delivery_id) {
            throw new DomainException('This affected work has no recorded parcel custody to recover.');
        }
        $input = Validator::make($input, ['source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'request_token' => ['required', 'uuid'],
            'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)]])->validate();

        return DB::transaction(function () use ($actor, $work, $input) {
            $parcel = $this->lock($work->delivery_id, [$actor->id]);
            $actor = $this->authorizeActor($actor, $parcel->logistics_company_id);
            $fingerprint = hash('sha256', json_encode([$actor->id, $work->id, $input], JSON_THROW_ON_ERROR));
            $original = CustodyRecoveryGrant::where('authorized_by_id', $actor->id)->where('request_token', $input['request_token'])->first();
            if ($original) {
                if (! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This authorization request already recorded different evidence.');
                }

                return $original;
            }
            $facts = $this->facts($work, $parcel);
            if (! hash_equals(app(AccountRestrictionService::class)->token($facts), $input['source_token'])) {
                throw new DomainException('The restriction or parcel custody changed. Review its current source before authorizing recovery.');
            }
            if (CustodyRecoveryGrant::where('delivery_id', $parcel->id)->where('expires_at', '>', now())
                ->whereNotIn('id', CustodyRecoveryReceipt::where('event_type', 'received')->select('custody_recovery_grant_id'))->exists()) {
                throw new DomainException('This parcel already has an unexpired recovery authorization. Use its original reference.');
            }

            return CustodyRecoveryGrant::create(['restriction_affected_work_id' => $work->id, 'delivery_id' => $parcel->id,
                'source_checkpoint_id' => $facts['source_checkpoint_id'], 'logistics_company_id' => $parcel->logistics_company_id,
                'hub_id' => $facts['hub_id'], 'courier_id' => $facts['courier_id'], 'authorized_by_id' => $actor->id, 'phase' => $facts['phase'],
                'reason' => $input['reason'], 'source_state' => $facts['parcel'], 'custody_snapshot' => $facts['custody'],
                'restriction_snapshot' => $facts['restriction'], 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint,
                'expires_at' => now()->addDay()]);
        });
    }

    public function ownGrants(User $actor): array
    {
        $actor = User::findOrFail($actor->id);
        if (! $actor->isCourier() || $actor->closed_at || ! in_array($actor->status, ['suspended', 'inactive'], true)) {
            return [];
        }

        return CustodyRecoveryGrant::where('courier_id', $actor->id)->where('expires_at', '>', now())->orderBy('id')->get()->filter(function ($grant) {
            try {
                $this->assertSource($grant, Delivery::with('order')->findOrFail($grant->delivery_id));

                return true;
            } catch (DomainException) {
                return false;
            }
        })->map(fn ($grant) => $this->present($grant))->values()->all();
    }

    public function present(CustodyRecoveryGrant $grant): array
    {
        $parcel = Delivery::findOrFail($grant->delivery_id);
        $hub = LogisticsHub::findOrFail($grant->hub_id);

        return ['id' => $grant->id, 'reference' => $grant->reference, 'order_number' => $parcel->order->order_number,
            'tracking_number' => $parcel->tracking_number, 'hub' => $hub->only(['id', 'name', 'address']), 'expires_at' => $grant->expires_at,
            'acknowledged' => CustodyRecoveryReceipt::where('custody_recovery_grant_id', $grant->id)->where('event_type', 'acknowledged')->exists()];
    }

    public function receiving(User $actor): array
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->isLogistics() && $actor->canAccessPortal(), 403);
        $hubs = $this->eligibility->accessibleHubs($actor)->get()->filter(fn ($hub) => $this->eligibility->canScan($actor, $hub))->pluck('id');

        return CustodyRecoveryGrant::whereIn('hub_id', $hubs)->where('expires_at', '>', now())->orderBy('id')->get()->filter(function ($grant) {
            try {
                $this->assertSource($grant, Delivery::with('order')->findOrFail($grant->delivery_id));

                return true;
            } catch (DomainException) {
                return false;
            }
        })->map(fn ($grant) => $this->present($grant))->values()->all();
    }

    public function acknowledge(User $actor, CustodyRecoveryGrant $grant, array $input): CustodyRecoveryReceipt
    {
        return $this->command($actor, $grant, $input, false);
    }

    public function receive(User $actor, CustodyRecoveryGrant $grant, array $input): CustodyRecoveryReceipt
    {
        return $this->command($actor, $grant, $input, true);
    }

    private function command(User $actor, CustodyRecoveryGrant $grant, array $input, bool $receive): CustodyRecoveryReceipt
    {
        $scans = app(WaybillScanInputService::class);
        $input = $scans->normalize($input);
        $input = Validator::make($input, ['barcode' => $scans->barcodeRules(), 'notes' => ['required', 'string', new ApplicationText('notes', 5, 1000)], 'request_token' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $grant, $input, $receive, $scans) {
            $parcel = $this->lock($grant->delivery_id, [$actor->id, $grant->courier_id]);
            $grant = CustodyRecoveryGrant::whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $actor = User::findOrFail($actor->id);
            $hub = LogisticsHub::findOrFail($grant->hub_id);
            if ($receive) {
                abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $this->eligibility->canScan($actor, $hub), 403);
            } else {
                abort_unless($actor->id === $grant->courier_id && $actor->isCourier() && ! $actor->closed_at
                    && in_array($actor->status, ['suspended', 'inactive'], true), 403);
            }
            $barcode = $scans->matchedBarcode($input['barcode'], $parcel);
            $type = $receive ? 'received' : 'acknowledged';
            $fingerprint = hash('sha256', json_encode([$actor->id, $type, $barcode, $input['notes']], JSON_THROW_ON_ERROR));
            $original = CustodyRecoveryReceipt::where('custody_recovery_grant_id', $grant->id)->where('request_token', $input['request_token'])->first();
            if ($original) {
                if ($original->actor_id !== $actor->id || $original->event_type !== $type || ! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This recovery request already recorded different evidence.');
                }

                return $original;
            }
            $this->assertSource($grant, $parcel);
            if (CustodyRecoveryReceipt::where('custody_recovery_grant_id', $grant->id)->where('event_type', $type)->exists()) {
                throw new DomainException('This action was already recorded. Retain its original request and receipt.');
            }
            if ($receive && ! CustodyRecoveryReceipt::where('custody_recovery_grant_id', $grant->id)->where('actor_id', $grant->courier_id)->where('event_type', 'acknowledged')->exists()) {
                throw new DomainException('The original restricted rider must acknowledge the owned handover before the receiving handler scans it.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            if ($receive) {
                $parcel->status = $grant->phase === 'pickup' ? 'arrived_at_origin_hub' : 'arrived_at_destination_hub';
                $parcel->current_hub_id = $hub->id;
                if ($grant->phase === 'final_mile') {
                    $parcel->assigned_rider_id = null;
                    $parcel->assigned_at = null;
                }
                $parcel->save();
                $parcel->order->update(['status' => 'at_sorting_center']);
            }
            $receipt = CustodyRecoveryReceipt::create(['custody_recovery_grant_id' => $grant->id, 'actor_id' => $actor->id,
                'event_type' => $type, 'barcode_scanned' => $barcode, 'notes' => $input['notes'], 'source_state' => $source,
                'target_state' => DeliveryCheckpoint::state($parcel), 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint]);
            if ($receive) {
                DeliveryCheckpoint::record($parcel, 'restricted_custody_received', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                    notes: 'Actual restricted-rider handover received. Evidence: '.$receipt->reference,
                    evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $grant->custody_snapshot,
                        'custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id, 'restricted_recovery_reference' => $receipt->reference]]);
            }

            return $receipt;
        });
    }

    private function assertSource(CustodyRecoveryGrant $grant, Delivery $parcel): void
    {
        $facts = $this->facts(RestrictionAffectedWork::findOrFail($grant->restriction_affected_work_id), $parcel);
        if (now()->greaterThanOrEqualTo($grant->expires_at) || $facts['source_checkpoint_id'] !== $grant->source_checkpoint_id
            || $facts['parcel'] !== $grant->source_state || $facts['custody'] !== $grant->custody_snapshot
            || $facts['restriction'] !== $grant->restriction_snapshot || $facts['hub_id'] !== $grant->hub_id) {
            throw new DomainException('This recovery authority is expired or its original custody/restriction changed. Review a fresh source.');
        }
    }

    private function lock(int $deliveryId, array $users): Delivery
    {
        $order = Order::whereKey(Delivery::whereKey($deliveryId)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($deliveryId)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);
        $this->eligibility->lockNetwork($parcel->logistics_company_id, [...$users, $parcel->courier_id, $parcel->assigned_rider_id], [$parcel->origin_bayan_hub_id, $parcel->destination_bayan_hub_id]);

        return $parcel;
    }
}
