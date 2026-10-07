<?php

namespace App\Services;

use App\Models\CodCustodyEntry;
use App\Models\CourierProfile;
use App\Models\CustodyRecoveryGrant;
use App\Models\CustodyRecoveryReceipt;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryRecoveryEvent;
use App\Models\DeliveryReturnEvent;
use App\Models\DeliveryReturnRoute;
use App\Models\ExceptionDecision;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\PickupClaimEvent;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExceptionOversightService
{
    public const SOURCES = [
        'restriction' => [RestrictionAffectedWork::class, 'restriction_affected_work_id'],
        'attempt' => [DeliveryAttempt::class, 'delivery_attempt_id'],
        'manifest' => [LogisticsManifestEvent::class, 'logistics_manifest_event_id'],
        'pickup' => [PickupClaim::class, 'pickup_claim_id'],
    ];

    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function actor(User $actor, ?int $companyId = null): User
    {
        $actor = User::findOrFail($actor->id);
        abort_unless($actor->canAccessPortal() && ($actor->isAdmin()
            || ($actor->isLogistics() && $this->eligibility->isCompanyAdministrator($actor, $companyId))), 403);
        if (! $actor->isAdmin()) {
            abort_unless($companyId === null || LogisticsCompany::whereKey($companyId)->where('user_id', $actor->id)->exists(), 403);
        }

        return $actor;
    }

    private function context(string $kind, int $id): array
    {
        abort_unless(isset(self::SOURCES[$kind]), 404);
        $source = self::SOURCES[$kind][0]::findOrFail($id);
        $manifest = $kind === 'manifest' ? $source->manifest : null;
        abort_if($kind === 'manifest' && $source->event_type !== 'report-discrepancy', 404);
        $parcel = $kind === 'manifest' ? $source->parcel?->delivery : Delivery::find($source->delivery_id);
        $order = $parcel?->order ?? ($kind === 'restriction' ? Order::find($source->order_id) : null);
        $companyId = $manifest?->logistics_company_id ?? $parcel?->logistics_company_id;

        return compact('source', 'manifest', 'parcel', 'order', 'companyId');
    }

    private function history(string $kind, int $id)
    {
        return ExceptionDecision::where(self::SOURCES[$kind][1], $id)->orderBy('id')->get();
    }

    public function queue(User $actor, array $filters): LengthAwarePaginator
    {
        $actor = $this->actor($actor);
        $companyId = $actor->isAdmin() ? null : LogisticsCompany::where('user_id', $actor->id)->value('id');
        $rows = collect();
        foreach (self::SOURCES as $kind => [$class]) {
            if (($filters['kind'] ?? '') !== '' && $filters['kind'] !== $kind) {
                continue;
            }
            $query = $class::query();
            if ($kind === 'manifest') {
                $query->where('event_type', 'report-discrepancy')->when($companyId, fn ($q) => $q->whereHas('manifest', fn ($m) => $m->where('logistics_company_id', $companyId)));
            } elseif ($companyId) {
                $query->whereIn('delivery_id', Delivery::where('logistics_company_id', $companyId)->select('id'));
            }
            foreach ($query->get(['id']) as $source) {
                $latest = $this->history($kind, $source->id)->last();
                $status = $latest?->action === 'resolve' ? 'resolved' : 'open';
                if (($filters['status'] ?? 'open') !== 'all' && ($filters['status'] ?? 'open') !== $status) {
                    continue;
                }
                $context = $this->context($kind, $source->id);
                $rows->push(['kind' => $kind, 'id' => $source->id, 'at' => $context['source']->created_at ?? $context['source']->recorded_at]);
            }
        }
        $rows = $rows->sortByDesc('at')->values();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $items = $rows->slice(($page - 1) * 15, 15)->map(fn ($row) => $this->detail($actor, $row['kind'], $row['id'], false))->values();

        return (new LengthAwarePaginator($items, $rows->count(), 15, $page, ['path' => url('/exceptions')]))->appends($filters);
    }

    public function detail(User $actor, string $kind, int $id, bool $withCandidates = true): array
    {
        $context = $this->context($kind, $id);
        $this->actor($actor, $context['companyId']);
        abort_unless($context['companyId'] !== null || $actor->isAdmin(), 403);
        $state = $this->state($kind, $id, $context);
        $history = $this->history($kind, $id);
        $latest = $history->last();
        $work = $kind === 'restriction' ? $context['source'] : null;
        $recoveryUrl = null;
        if ($work && $context['parcel']) {
            try {
                app(RestrictedCustodyRecoveryService::class)->proposal($actor, $work);
                $recoveryUrl = '/custody-recovery/work/'.$work->id;
            } catch (DomainException) {
                // Unsupported resource/facility recovery stays visible as a source blocker.
            }
        }

        return [
            'kind' => $kind, 'id' => $id, 'reference' => $state['source']['reference'],
            'reason' => $state['source']['reason'], 'companyName' => $context['companyId'] ? LogisticsCompany::find($context['companyId'])?->name : null,
            'orderNumber' => $context['order']?->order_number, 'trackingNumber' => $context['parcel']?->tracking_number,
            'status' => $latest?->action === 'resolve' ? 'resolved' : 'open',
            'responsibleName' => $latest?->responsible_name ?? ($work?->responsible_user_id ? User::find($work->responsible_user_id)?->name : null),
            'state' => $state, 'sourceToken' => app(AccountRestrictionService::class)->token($state),
            'canResolve' => filled($state['support']) && $latest?->action !== 'resolve',
            'blocker' => $state['support'] ? null : $this->blocker($kind, $context),
            'recoveryUrl' => $recoveryUrl,
            'history' => $history->map(fn ($event) => $event->only(['reference', 'action', 'reason', 'actor_name', 'actor_role', 'responsible_name', 'supporting_evidence', 'created_at']))->all(),
            'candidates' => $withCandidates ? array_values(array_filter($this->candidates($context['companyId']), fn ($candidate) => $actor->isAdmin() || $candidate['role'] !== 'admin')) : [],
        ];
    }

    public function sourceGaps(User $actor): array
    {
        $actor = $this->actor($actor);
        $companyId = $actor->isAdmin() ? null : LogisticsCompany::where('user_id', $actor->id)->value('id');
        $query = Delivery::when($companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
            ->where(function ($q) {
                $q->where(fn ($failed) => $failed->where('status', 'delivery_failed')->whereNotIn('id', DeliveryAttempt::select('delivery_id')))
                    ->orWhere(fn ($pickup) => $pickup->where('status', 'ready_for_hub_pickup')->whereNotIn('id', PickupClaim::select('delivery_id')))
                    ->orWhere(fn ($returned) => $returned->where('status', 'return_to_sender')->whereNotIn('id', DeliveryReturnRoute::select('delivery_id')));
            });

        return ['total' => $query->count(), 'data' => $query->with('order')->orderBy('id')->limit(15)->get()->map(fn ($parcel) => [
            'orderNumber' => $parcel->order->order_number, 'trackingNumber' => $parcel->tracking_number,
            'reason' => 'A legacy '.$parcel->status.' label has no retained source. Source-specific recovery is required; admin review cannot invent its missing records.',
        ])->all()];
    }

    private function state(string $kind, int $id, array $context): array
    {
        ['source' => $source, 'manifest' => $manifest, 'parcel' => $parcel, 'order' => $order, 'companyId' => $companyId] = $context;
        $checkpoint = $parcel?->checkpoints()->whereNull('source_checkpoint_id')->latest('id')->first();
        $decision = $kind === 'restriction' ? RestrictionDecision::findOrFail($source->restriction_decision_id) : null;
        $cash = $parcel ? CodCustodyEntry::where('delivery_id', $parcel->id)->where('entry_type', 'counter_collection')->latest('id')->first() : null;
        $events = $parcel ? [
            'attempts' => DeliveryAttempt::where('delivery_id', $parcel->id)->orderBy('id')->get()->map(fn ($a) => $a->only(['id', 'reference', 'attempt_number', 'rider_id', 'departure_checkpoint_id', 'reason_code', 'notes', 'location_name', 'attempted_at']) + ['actorName' => User::find($a->rider_id)?->name])->all(),
            'recovery' => DeliveryRecoveryEvent::where('delivery_id', $parcel->id)->orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'reference', 'delivery_attempt_id', 'actor_id', 'hub_id', 'event_type', 'barcode_scanned', 'retry_at', 'notes', 'created_at']) + ['actorName' => $e->actor_id ? User::find($e->actor_id)?->name : 'System'])->all(),
            'returns' => DeliveryReturnEvent::where('delivery_id', $parcel->id)->orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'reference', 'event_type', 'actor_id', 'hub_id', 'barcode_scanned', 'created_at']) + ['actorName' => $e->actor_id ? User::find($e->actor_id)?->name : 'System'])->all(),
            'pickup' => PickupClaimEvent::whereIn('pickup_claim_id', PickupClaim::where('delivery_id', $parcel->id)->select('id'))->orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'reference', 'pickup_claim_id', 'event_type', 'actor_id', 'barcode_scanned', 'created_at']) + ['actorName' => $e->actor_id ? User::find($e->actor_id)?->name : 'System'])->all(),
            'restricted' => CustodyRecoveryReceipt::whereIn('custody_recovery_grant_id', CustodyRecoveryGrant::where('delivery_id', $parcel->id)->select('id'))->orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'reference', 'custody_recovery_grant_id', 'event_type', 'actor_id', 'created_at']) + ['actorName' => $e->actor_id ? User::find($e->actor_id)?->name : 'System'])->all(),
        ] : [];
        if ($manifest) {
            $events['manifest'] = $manifest->events()->orderBy('id')->get()->map(fn ($e) => $e->only(['id', 'reference', 'manifest_parcel_id', 'event_type', 'actor_id', 'hub_id', 'barcode_scanned', 'reason', 'payload', 'created_at']) + ['actorName' => $e->actor_id ? User::find($e->actor_id)?->name : 'System'])->all();
        }
        $custody = $parcel ? DeliveryCheckpoint::lastCustody($parcel) : null;
        $holder = isset($custody['user_id']) ? User::find($custody['user_id']) : null;
        $hubIds = collect([$parcel?->current_hub_id, $parcel?->origin_bayan_hub_id, $parcel?->destination_bayan_hub_id, $manifest?->source_hub_id, $manifest?->destination_hub_id])->filter()->unique()->sort()->values();
        $subject = $decision?->subject_type === 'account' ? User::find($decision->subject_id) : null;
        $company = $companyId ? LogisticsCompany::find($companyId) : null;

        return [
            'kind' => $kind, 'id' => $id, 'company_id' => $companyId,
            'source' => ['reference' => $decision ? 'Restriction #'.$decision->id.' / work #'.$id : $source->reference,
                'reason' => $decision?->reason ?? ($source->reason_code ?? $source->reason ?? 'Secure counter holding'),
                'details' => match ($kind) {
                    'restriction' => ['decision_id' => $decision->id, 'subject_type' => $decision->subject_type, 'subject_id' => $decision->subject_id, 'original_responsible_user_id' => $source->responsible_user_id],
                    'attempt' => $source->only(['attempt_number', 'departure_checkpoint_id', 'reason_code', 'rider_id']) + ['proofAvailable' => $this->proofAvailable($source)],
                    'manifest' => ['manifest' => $manifest->state(), 'observation' => $source->payload],
                    'pickup' => $source->state() + ['expired' => now()->greaterThanOrEqualTo($source->expires_at), 'locked' => $source->locked_until && now()->lessThan($source->locked_until)],
                }],
            'parcel' => $parcel ? DeliveryCheckpoint::state($parcel) : null,
            'payment' => $order?->only(['payment_method', 'payment_status', 'total_amount']),
            'last_checkpoint' => $checkpoint?->only(['id', 'checkpoint_type', 'scanned_by_id', 'hub_id', 'barcode_scanned']),
            'custody' => $custody, 'holderName' => $holder?->name ?? (isset($custody['hub_id']) ? LogisticsHub::find($custody['hub_id'])?->name : ($custody['kind'] ?? 'Unverified')),
            'accounts' => User::whereIn('id', collect([$holder?->id, $subject?->id, $company?->user_id, $this->history($kind, $id)->last()?->responsible_user_id])->filter()->unique())->orderBy('id')->get()
                ->map(fn ($u) => $u->only(['id', 'role', 'status', 'kyc_status', 'identity_version', 'restriction_version', 'closed_at']))->all(),
            'company' => $company?->only(['id', 'is_active', 'status', 'restriction_version']),
            'hubs' => LogisticsHub::whereIn('id', $hubIds)->orderBy('id')->get()->map(fn ($h) => $h->only(['id', 'name', 'tier', 'is_active', 'restriction_version']))->all(),
            'cash' => ['reference' => $cash?->reference, 'amount_cents' => $cash?->amount_cents, 'holderName' => $cash ? User::find($cash->holder_user_id)?->name : null,
                'holder_user_id' => $cash?->holder_user_id, 'evidence' => $cash ? 'counter_collection' : ($order?->payment_method === 'cod' ? 'unverified' : 'not_cod'), 'reconciliation' => 'unverified'],
            'events' => $events, 'last_decision_id' => $this->history($kind, $id)->last()?->id,
            'support' => $this->support($kind, $context),
        ];
    }

    private function support(string $kind, array $context): array
    {
        ['source' => $source, 'manifest' => $manifest, 'parcel' => $parcel] = $context;
        if ($kind === 'manifest') {
            $resolution = $manifest->events()->where('event_type', 'resolve-discrepancy')->get()->first(fn ($e) => ($e->payload['source_event_id'] ?? null) === $source->id);
            $evidence = $resolution ? $manifest->events()->find($resolution->payload['support_event_id'] ?? null) : null;
            $member = $evidence?->parcel;
            if ($member?->received_at && in_array($evidence->event_type, ['parcel_received', 'correct-discrepancy'], true)) {
                return ['manifest_resolution' => $resolution->reference, 'supporting_receipt' => $evidence->reference];
            }

            return [];
        }
        if (! $parcel) {
            return [];
        }
        if ($kind === 'restriction') {
            $receipt = CustodyRecoveryReceipt::whereIn('custody_recovery_grant_id', CustodyRecoveryGrant::where('restriction_affected_work_id', $source->id)->select('id'))->where('event_type', 'received')->first();
            if ($receipt && $parcel->checkpoints()->whereNull('source_checkpoint_id')->where('checkpoint_type', 'restricted_custody_received')->where('scanned_by_id', $receipt->actor_id)->where('barcode_scanned', $parcel->tracking_number)
                ->where('custody_after->restricted_recovery_reference', $receipt->reference)->exists()) {
                return ['restricted_receipt' => $receipt->reference];
            }

            return [];
        }
        $returned = DeliveryReturnEvent::where('delivery_id', $parcel->id)->where('event_type', 'seller_received')->first();
        $route = $returned ? DeliveryReturnRoute::find($returned->delivery_return_route_id) : null;
        $origin = $route ? DeliveryCheckpoint::find($route->source_checkpoint_id) : null;
        $actualReturn = $returned && $parcel->status === 'returned' && $parcel->order->status === 'returned'
            && $parcel->checkpoints()->where('checkpoint_type', 'parcel_returned')->where('scanned_by_id', $returned->actor_id)->where('barcode_scanned', $parcel->tracking_number)->where('custody_after->seller_receipt_reference', $returned->reference)->exists();
        if ($kind === 'pickup') {
            if (app(PickupClaimService::class)->hasCollectionEvidence($parcel) && $source->status === 'collected' && $source->consumed_at) {
                return ['counter_receipt' => PickupClaimEvent::where('pickup_claim_id', $source->id)->where('event_type', 'collected')->value('reference')];
            }
            $started = PickupClaimEvent::where('pickup_claim_id', $source->id)->where('event_type', 'return_started')->first();
            if ($actualReturn && $started && $origin?->checkpoint_type === 'expired_pickup_return_started' && $origin->scanned_by_id === $started->actor_id && $origin->barcode_scanned === $started->barcode_scanned) {
                return ['expired_pickup_return' => $started->reference, 'seller_receipt' => $returned->reference];
            }

            return [];
        }
        $events = DeliveryRecoveryEvent::where('delivery_attempt_id', $source->id)->orderBy('id')->get()->keyBy('event_type');
        if (! $this->proofAvailable($source)) {
            return [];
        }
        $received = $events->get('hub_return');
        $hubCheckpoint = $received ? $parcel->checkpoints()->where('checkpoint_type', 'failed_delivery_hub_return')->where('custody_after->recovery_reference', $received->reference)->where('barcode_scanned', $parcel->tracking_number)->first() : null;
        if (! $received || ! $hubCheckpoint) {
            return [];
        }
        if ($source->attempt_number === 3 || $source->reason_code === 'customer_refused') {
            return $actualReturn && $origin?->id === $hubCheckpoint->id ? ['hub_receipt' => $received->reference, 'seller_receipt' => $returned->reference] : [];
        }
        $approved = $events->get('retry_approved');
        $started = $events->get('retry_started');
        $departure = $started ? $parcel->checkpoints()->whereNull('source_checkpoint_id')->where('checkpoint_type', 'out_for_delivery')->where('id', '>', $hubCheckpoint->id)->where('barcode_scanned', $parcel->tracking_number)->whereNotNull('scanned_by_id')->first() : null;

        return $approved && $started && $departure ? ['hub_receipt' => $received->reference, 'retry_review' => $approved->reference, 'retry_started' => $started->reference, 'departure_checkpoint_id' => $departure->id] : [];
    }

    private function blocker(string $kind, array $context): string
    {
        if (! $context['parcel'] && $kind !== 'manifest') {
            return 'No recorded parcel custody. Keep this work open for source-specific recovery; an order label cannot prove completion.';
        }

        if ($kind === 'attempt' && ! $this->proofAvailable($context['source'])) {
            return 'The retained original attempt proof is missing or changed. Its verified source is required before resolution.';
        }

        return match ($kind) {
            'restriction' => 'A current original-hub recovery authorization and actual handler receipt are required. Unsupported seller, resource or carrier recovery remains open for controlled review.',
            'attempt' => $context['source']->attempt_number === 3 || $context['source']->reason_code === 'customer_refused'
                ? 'Require the actual destination-hub return, reverse-route manifests and owning seller receipt.' : 'Require actual hub return, the approved retry date, handler retry scan and a new rider departure.',
            'manifest' => 'The manifest discrepancy needs its actual receiving/correction record and source-owned resolution. Admin review cannot supply a receipt.',
            'pickup' => 'Require actual verified counter collection or expired-holding reverse custody ending in seller receipt. A code, deadline or status label alone cannot close holding.',
        };
    }

    private function proofAvailable(DeliveryAttempt $attempt): bool
    {
        return preg_match('/\Adelivery-attempt-proofs\/[A-Za-z0-9._-]+\z/', $attempt->proof_path) === 1
            && Storage::disk('local')->exists($attempt->proof_path)
            && hash_equals($attempt->proof_hash, hash('sha256', Storage::disk('local')->get($attempt->proof_path)));
    }

    private function eligibleResponsible(User $user, ?int $companyId): bool
    {
        if (! $user->canAccessPortal()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if (! $companyId) {
            return false;
        }

        return ($user->isLogistics() && ($this->eligibility->isCompanyAdministrator($user, $companyId)
            || HubHandler::eligible()->where('user_id', $user->id)->where('logistics_company_id', $companyId)->exists()))
            || ($user->isCourier() && CourierProfile::operational()->where('user_id', $user->id)->where('logistics_company_id', $companyId)->exists());
    }

    private function candidates(?int $companyId): array
    {
        return User::whereIn('role', ['admin', 'logistics', 'courier'])->orderBy('name')->get()->filter(fn ($u) => $this->eligibleResponsible($u, $companyId))
            ->map(fn ($u) => $u->only(['id', 'name', 'role']))->values()->all();
    }

    public function decide(User $actor, string $kind, int $id, array $input): ExceptionDecision
    {
        $input = Validator::make($input, [
            'action' => ['required', 'in:assign,resolve'], 'reason' => ['required', 'string', new ApplicationText('notes', 5, 1000)],
            'source_token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'request_token' => ['required', 'uuid'],
            'responsible_user_id' => ['required_if:action,assign', 'prohibited_if:action,resolve', 'nullable', 'integer', 'min:1'],
            'status' => ['prohibited'], 'custody' => ['prohibited'], 'actor_id' => ['prohibited'], 'code' => ['prohibited'], 'payment_status' => ['prohibited'],
        ])->validate();
        $context = $this->context($kind, $id);
        $this->actor($actor, $context['companyId']);
        abort_unless($context['companyId'] !== null || $actor->isAdmin(), 403);

        return DB::transaction(function () use ($actor, $kind, $id, $input, $context) {
            $this->lock($context, [$actor->id, $input['responsible_user_id'] ?? null]);
            $fresh = $this->context($kind, $id);
            if ($fresh['companyId'] !== $context['companyId']) {
                throw new DomainException('The owning company changed. Refresh and review its actual source.');
            }
            $context = $fresh;
            $actor = $this->actor($actor, $context['companyId']);
            $fingerprint = hash('sha256', json_encode([$kind, $id, $input], JSON_THROW_ON_ERROR));
            $original = ExceptionDecision::where('actor_id', $actor->id)->where('request_token', $input['request_token'])->first();
            if ($original) {
                if (! hash_equals($original->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This request already recorded a different exception decision.');
                }

                return $original;
            }
            $state = $this->state($kind, $id, $context);
            if (! hash_equals(app(AccountRestrictionService::class)->token($state), $input['source_token'])) {
                throw new DomainException('The source, custody, restriction, cash evidence or responsibility changed. Refresh before deciding.');
            }
            $latest = $this->history($kind, $id)->last();
            if ($latest?->action === 'resolve') {
                throw new DomainException('This source obligation already has its original resolution.');
            }
            $responsible = $input['action'] === 'assign' ? User::find($input['responsible_user_id']) : null;
            if ($input['action'] === 'assign' && (! $responsible || ! $this->eligibleResponsible($responsible, $context['companyId'])
                || (! $actor->isAdmin() && $responsible->isAdmin()))) {
                throw new DomainException('Choose a currently eligible responsible person in your company scope.');
            }
            if ($input['action'] === 'resolve' && ! $state['support']) {
                throw new DomainException($this->blocker($kind, $context));
            }

            return ExceptionDecision::create([
                self::SOURCES[$kind][1] => $id, 'logistics_company_id' => $context['companyId'], 'actor_id' => $actor->id,
                'responsible_user_id' => $responsible?->id ?? $latest?->responsible_user_id ?? ($kind === 'restriction' ? $context['source']->responsible_user_id : null),
                'action' => $input['action'], 'reason' => $input['reason'], 'source_state' => $state,
                'supporting_evidence' => $input['action'] === 'resolve' ? $state['support'] : [],
                'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint,
            ]);
        });
    }

    private function lock(array $context, array $accountIds): void
    {
        ['parcel' => $parcel, 'order' => $order, 'manifest' => $manifest, 'companyId' => $companyId] = $context;
        $initialIds = $manifest?->parcels()->orderBy('delivery_id')->pluck('delivery_id')->all() ?? ($parcel ? [$parcel->id] : []);
        $orderIds = Delivery::whereIn('id', $initialIds)->pluck('order_id')->push($order?->id)->filter()->unique()->sort()->values();
        Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
        Delivery::whereIn('id', $initialIds)->orderBy('id')->lockForUpdate()->get();
        $company = $companyId ? LogisticsCompany::find($companyId) : null;
        $restriction = $context['source'] instanceof RestrictionAffectedWork ? RestrictionDecision::find($context['source']->restriction_decision_id) : null;
        $custody = $parcel ? DeliveryCheckpoint::lastCustody($parcel->fresh()) : null;
        $cashHolderId = $parcel ? CodCustodyEntry::where('delivery_id', $parcel->id)->value('holder_user_id') : null;
        $responsibleId = null;
        foreach (self::SOURCES as $kind => [$class]) {
            if ($context['source'] instanceof $class) {
                $responsibleId = $this->history($kind, $context['source']->id)->last()?->responsible_user_id;
            }
        }
        $ids = collect([...$accountIds, $company?->user_id, $parcel?->courier_id, $parcel?->assigned_rider_id, $manifest?->driver_id,
            $restriction?->subject_type === 'account' ? $restriction->subject_id : null, $custody['user_id'] ?? null, $cashHolderId, $responsibleId])->filter()->unique()->sort()->values();
        User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($company) {
            LogisticsCompany::whereKey($company->id)->lockForUpdate()->firstOrFail();
            LogisticsHub::where('logistics_company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
            CourierProfile::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
            HubHandler::whereIn('user_id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
        if ($manifest) {
            LogisticsManifest::whereKey($manifest->id)->lockForUpdate()->firstOrFail();
            if ($manifest->parcels()->orderBy('delivery_id')->pluck('delivery_id')->all() !== $initialIds) {
                throw new DomainException('The source manifest membership changed. Refresh before deciding.');
            }
        }
        if ($context['source'] instanceof PickupClaim) {
            PickupClaim::whereKey($context['source']->id)->lockForUpdate()->firstOrFail();
        }
    }
}
