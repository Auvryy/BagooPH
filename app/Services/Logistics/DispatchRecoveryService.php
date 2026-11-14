<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\DispatchEscalation;
use App\Models\DispatchEscalationEvent;
use App\Models\HandoverIssue;
use App\Models\HubPickupPause;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\SlaClock;
use App\Models\User;
use App\Services\Monitoring\EtaCalculationService;
use App\Services\Monitoring\SlaClockService;
use App\Services\Monitoring\SlaPauseService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Orders\OrderLifecycleService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DispatchRecoveryService
{
    public function __construct(
        private LogisticsEligibilityService $eligibility,
        private NotificationDeliveryService $notifications,
        private SlaClockService $clocks,
        private SlaPauseService $pauses,
        private EtaCalculationService $etaService,
        private OrderLifecycleService $orderLifecycle,
        private BarangayRiderDensityEngine $density,
    ) {}

    /**
     * Evaluates operational rider capacity and explicit hub pickup pauses without conflating them with out-of-coverage.
     *
     * @return array<string, mixed>
     */
    public function coverageAssessment(
        LogisticsCompany $company,
        ?LogisticsHub $originHub,
        ?string $pickupBarangay = null,
    ): array {
        if (! $originHub) {
            return [
                'coverage_status' => 'no_service_coverage',
                'operational_riders' => 0,
                'active_pause' => null,
                'message' => 'No active origin Bayan Hub is mapped for this shop and carrier.',
            ];
        }

        if (Schema::hasTable('hub_pickup_pauses')) {
            $activePauses = HubPickupPause::query()
                ->where('logistics_hub_id', $originHub->id)
                ->where('status', 'active')
                ->orderByDesc('paused_at')
                ->get();

            $matchingPause = $activePauses->first(fn (HubPickupPause $p) => $p->matchesBarangay($pickupBarangay));
            if ($matchingPause) {
                return [
                    'coverage_status' => 'pickup_paused',
                    'operational_riders' => 0,
                    'active_pause' => [
                        'id' => $matchingPause->id,
                        'reference' => $matchingPause->reference,
                        'barangay' => $matchingPause->barangay,
                        'reason' => $matchingPause->reason,
                        'paused_at' => $matchingPause->paused_at?->toIso8601String(),
                    ],
                    'message' => "Pickup at {$originHub->name} is temporarily paused: {$matchingPause->reason}",
                ];
            }
        }

        $operationalRiders = $this->availableFirstMileRiders($company->id, $originHub->id)->count();

        if ($operationalRiders === 0) {
            return [
                'coverage_status' => 'no_available_rider',
                'operational_riders' => 0,
                'active_pause' => null,
                'message' => "Route coverage at {$originHub->name} is valid, but zero riders currently have pickup capacity.",
            ];
        }

        return [
            'coverage_status' => 'available',
            'operational_riders' => $operationalRiders,
            'active_pause' => null,
            'message' => "{$operationalRiders} eligible rider(s) available for dispatch.",
        ];
    }

    public function pausePickup(User $actor, LogisticsHub $hub, ?string $barangay, string $reason): HubPickupPause
    {
        if (! $actor->canAccessPortal() || (! $actor->isAdmin() && ! $this->eligibility->isCompanyManager($actor, $hub->logistics_company_id) && ! $this->eligibility->canScan($actor, $hub))) {
            throw new DomainException('Only authorized hub managers, company administrators, or Platform Admins may pause hub pickup.');
        }

        return HubPickupPause::create([
            'logistics_hub_id' => $hub->id,
            'logistics_company_id' => $hub->logistics_company_id,
            'barangay' => ($barangay !== null && trim($barangay) !== '') ? trim($barangay) : null,
            'reason' => trim($reason),
            'status' => 'active',
            'paused_at' => now(),
            'created_by_id' => $actor->id,
        ]);
    }

    public function resumePickup(HubPickupPause $pause, User $actor): HubPickupPause
    {
        if (! $actor->canAccessPortal() || (! $actor->isAdmin() && ! $this->eligibility->isCompanyManager($actor, $pause->logistics_company_id) && ! $this->eligibility->canScan($actor, $pause->hub))) {
            throw new DomainException('Only authorized hub managers, company administrators, or Platform Admins may resume hub pickup.');
        }

        if ($pause->status === 'resumed') {
            return $pause;
        }

        $pause->update([
            'status' => 'resumed',
            'resumed_at' => now(),
            'resumed_by_id' => $actor->id,
        ]);

        return $pause->fresh();
    }

    /**
     * Evaluates unpicked ready_for_pickup parcels across the 4-step recovery ladder.
     *
     * @return array<string, mixed>
     */
    public function processEscalations(bool $dryRun = false, int $limit = 100): array
    {
        $summary = [
            'dry_run' => $dryRun,
            'checked' => 0,
            'assigned' => [],
            'urgent_flagged' => [],
            'hub_sweeps' => [],
            'auto_cancelled' => [],
            'manual_review' => [],
            'skipped' => 0,
        ];

        if (! Schema::hasTable('dispatch_escalations')) {
            return $summary;
        }

        $deliveries = Delivery::query()
            ->whereIn('status', ['unassigned', 'assigned', 'assigned_pickup'])
            ->whereHas('order', fn ($q) => $q->where('status', 'ready_for_pickup'))
            ->with(['order.shop', 'originBayanHub', 'company'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($deliveries as $delivery) {
            $summary['checked']++;
            $res = $this->evaluateDelivery($delivery, $dryRun);
            if (! $res) {
                $summary['skipped']++;

                continue;
            }

            if ($res['action'] !== 'none') {
                $bucket = match ($res['action']) {
                    'assigned' => 'assigned',
                    'urgent' => 'urgent_flagged',
                    'sweep' => 'hub_sweeps',
                    'auto_cancelled' => 'auto_cancelled',
                    default => 'manual_review',
                };
                $summary[$bucket][] = $res;
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function evaluateDelivery(Delivery $delivery, bool $dryRun = false): ?array
    {
        $delivery->loadMissing(['order.shop', 'originBayanHub', 'company']);
        $order = $delivery->order;
        if (! $order || $order->status !== 'ready_for_pickup' || ! in_array($delivery->status, ['unassigned', 'assigned', 'assigned_pickup'], true)) {
            return null;
        }

        if ($this->hasPendingHoldOrPause($order, $delivery)) {
            return null;
        }

        $clock = SlaClock::query()
            ->where('subject_type', 'delivery')
            ->where('subject_id', $delivery->id)
            ->where('clock_kind', 'pickup_dispatch_wait')
            ->first();

        $startedAt = $clock?->started_at
            ?? $delivery->checkpoints()->where('checkpoint_type', 'ready_for_pickup')->value('created_at')
            ?? $order->updated_at
            ?? now();

        $pausedSeconds = (int) ($clock?->paused_seconds ?? 0);
        $effectiveHours = max(0, ((int) $startedAt->diffInSeconds(now()) - $pausedSeconds) / 3600);

        $targetTier = match (true) {
            $effectiveHours >= 48 => 'unpicked_fallback',
            $effectiveHours >= 36 => 'hub_emergency_sweep',
            $effectiveHours >= 24 => 'priority_bounty_deferred',
            $effectiveHours >= 12 => 'direct_assignment',
            default => 'open_pool',
        };

        $coverage = $delivery->company
            ? $this->coverageAssessment($delivery->company, $delivery->originBayanHub, $order->shop?->city ?? null)
            : ['coverage_status' => 'no_service_coverage'];

        if ($dryRun) {
            $action = match (true) {
                $effectiveHours >= 72 && $delivery->status === 'unassigned' && ! $delivery->courier_id => 'auto_cancelled',
                $effectiveHours >= 72 => 'manual_review',
                $targetTier === 'hub_emergency_sweep' => 'sweep',
                $targetTier === 'priority_bounty_deferred' => 'urgent',
                $targetTier === 'direct_assignment' && ($coverage['operational_riders'] ?? 0) > 0 => 'assigned',
                default => 'none',
            };

            return [
                'delivery_id' => $delivery->id,
                'tracking_number' => $delivery->tracking_number,
                'effective_hours' => round($effectiveHours, 2),
                'target_tier' => $targetTier,
                'coverage_outcome' => $coverage['coverage_status'],
                'action' => $action,
            ];
        }

        return DB::transaction(function () use ($delivery, $effectiveHours, $targetTier, $coverage, $startedAt): array {
            $lockedOrder = Order::whereKey($delivery->order_id)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $lockedDelivery->setRelation('order', $lockedOrder);

            if ($lockedOrder->status !== 'ready_for_pickup' || ! in_array($lockedDelivery->status, ['unassigned', 'assigned', 'assigned_pickup'], true)) {
                return ['action' => 'none'];
            }

            if ($this->hasPendingHoldOrPause($lockedOrder, $lockedDelivery)) {
                return ['action' => 'none'];
            }

            $escalation = DispatchEscalation::query()->where('delivery_id', $lockedDelivery->id)->lockForUpdate()->first();
            if (! $escalation) {
                $escalation = DispatchEscalation::create([
                    'delivery_id' => $lockedDelivery->id,
                    'order_id' => $lockedOrder->id,
                    'origin_bayan_hub_id' => $lockedDelivery->origin_bayan_hub_id,
                    'logistics_company_id' => $lockedDelivery->logistics_company_id,
                    'current_tier' => 'open_pool',
                    'status' => 'monitoring',
                    'coverage_outcome' => $coverage['coverage_status'],
                    'pickup_sla_started_at' => $startedAt,
                ]);
            }

            $escalation->coverage_outcome = $coverage['coverage_status'];
            $action = 'none';

            // 72h verified penalty-exempt auto-cancel path (strictly unclaimed + seller custody only)
            if ($effectiveHours >= 72 && ! in_array($escalation->status, ['auto_cancelled', 'resolved', 'manual_review'], true)) {
                $custody = DeliveryCheckpoint::lastCustody($lockedDelivery);
                $sellerOnly = ! isset($custody['kind']) || $custody['kind'] === 'seller';
                $unclaimed = $lockedDelivery->status === 'unassigned' && $lockedDelivery->courier_id === null;
                $verifiedPay = ($lockedOrder->payment_method === 'cod' && $lockedOrder->payment_status === 'pending')
                    || ($lockedOrder->payment_method === 'wallet' && in_array($lockedOrder->payment_status, ['paid', 'pending'], true));

                if ($unclaimed && $sellerOnly && $verifiedPay) {
                    $reason = "Verified 72h unpicked timeout ({$coverage['coverage_status']}); penalty-exempt auto-cancellation.";
                    $this->orderLifecycle->cancelExpiredUnpickedOrder($lockedOrder, $reason);

                    $escalation->fill([
                        'current_tier' => 'unpicked_fallback',
                        'status' => 'auto_cancelled',
                        'penalty_exempt' => true,
                        'resolved_at' => now(),
                        'resolution_action' => 'penalty_exempt_auto_cancel',
                        'resolution_notes' => $reason,
                    ])->save();

                    $this->recordEscalationEvent($escalation, 'auto_cancelled', $escalation->current_tier, 'unpicked_fallback', "esc-cancel:{$lockedDelivery->id}", [
                        'coverage_outcome' => $coverage['coverage_status'],
                        'penalty_exempt' => true,
                    ]);

                    return ['delivery_id' => $lockedDelivery->id, 'tracking_number' => $lockedDelivery->tracking_number, 'action' => 'auto_cancelled'];
                }

                $escalation->fill([
                    'current_tier' => 'unpicked_fallback',
                    'status' => 'manual_review',
                    'penalty_exempt' => true,
                    'resolution_action' => 'flagged_manual_hub_admin_review',
                    'resolution_notes' => 'Reached 72h wait with existing claim or mixed state; flagged for manual hub/admin inspection.',
                ])->save();

                return ['delivery_id' => $lockedDelivery->id, 'tracking_number' => $lockedDelivery->tracking_number, 'action' => 'manual_review'];
            }

            if ($this->tierRank($targetTier) > $this->tierRank($escalation->current_tier)) {
                $fromTier = $escalation->current_tier;
                $escalation->current_tier = $targetTier;

                if ($targetTier === 'priority_bounty_deferred') {
                    $escalation->is_urgent = true;
                    $escalation->dispatch_delay_label = 'Pickup Delayed — Reassigning Rider';
                    $escalation->surge_deferred_note = 'Elevated to priority pickup at 24h wait; surge payouts deferred without unapproved payout adjustments (B15).';
                    $escalation->delayed_notice_sent_at = $escalation->delayed_notice_sent_at ?? now();
                    $action = 'urgent';
                    $this->notifyDelay($lockedOrder, $lockedDelivery);
                } elseif ($targetTier === 'hub_emergency_sweep') {
                    $escalation->status = 'escalated';
                    $escalation->dispatch_delay_label = $escalation->dispatch_delay_label ?: 'Pickup Delayed — Reassigning Rider';
                    $escalation->escalated_at = $escalation->escalated_at ?? now();
                    $action = 'sweep';
                    $this->notifyEmergencySweep($lockedDelivery);
                } elseif ($targetTier === 'unpicked_fallback') {
                    $escalation->status = 'escalated';
                }

                $escalation->save();
                $this->recordEscalationEvent($escalation, "tier_{$targetTier}", $fromTier, $targetTier, "esc-tier:{$lockedDelivery->id}:{$targetTier}", [
                    'coverage_outcome' => $coverage['coverage_status'],
                    'effective_hours' => round($effectiveHours, 2),
                ]);
            } elseif ($escalation->isDirty()) {
                $escalation->save();
            }

            // Capacity-aware direct rider push assignment at >= 12h while still unassigned
            if ($this->tierRank($escalation->current_tier) >= $this->tierRank('direct_assignment')
                && $lockedDelivery->status === 'unassigned'
                && $lockedDelivery->courier_id === null
                && $lockedDelivery->logistics_company_id
                && $lockedDelivery->origin_bayan_hub_id) {
                $candidate = $this->findBestFirstMileRider((int) $lockedDelivery->logistics_company_id, (int) $lockedDelivery->origin_bayan_hub_id, $lockedDelivery->id);
                if ($candidate) {
                    $source = DeliveryCheckpoint::state($lockedDelivery);
                    $custody = DeliveryCheckpoint::lastCustody($lockedDelivery);
                    $lockedDelivery->fill([
                        'courier_id' => $candidate->user_id,
                        'status' => 'assigned_pickup',
                        'assigned_at' => now(),
                    ])->save();

                    DeliveryCheckpoint::record(
                        delivery: $lockedDelivery,
                        type: 'assigned_pickup',
                        location: $lockedDelivery->pickup_store_name ?? 'Merchant store',
                        notes: "Automated capacity-aware direct pickup assignment ({$escalation->current_tier}) to rider {$candidate->user->name}.",
                        hub: $lockedDelivery->originBayanHub,
                        barcodeScanned: $lockedDelivery->tracking_number,
                        evidence: [
                            'source_state' => $source,
                            'target_state' => DeliveryCheckpoint::state($lockedDelivery),
                            'custody_before' => $custody,
                            'custody_after' => $custody,
                        ],
                    );

                    $escalation->update(['assigned_rider_id' => $candidate->user_id]);
                    $this->etaService->applyToDelivery($lockedDelivery, $lockedOrder);
                    $this->clocks->syncForDelivery($lockedDelivery, $lockedOrder);

                    $this->notifications->record(
                        $candidate->user_id,
                        'parcel-event',
                        "esc-assign:{$lockedDelivery->id}:r:{$candidate->user_id}:{$escalation->current_tier}",
                        [
                            'title' => "Urgent Pickup Assigned ({$lockedDelivery->tracking_number})",
                            'body' => 'A waiting seller pickup was assigned directly to your active queue.',
                            'href' => '/courier/dashboard',
                            'delivery_id' => $lockedDelivery->id,
                        ]
                    );

                    $action = 'assigned';
                }
            }

            return ['delivery_id' => $lockedDelivery->id, 'tracking_number' => $lockedDelivery->tracking_number, 'action' => $action];
        });
    }

    /**
     * Controlled capacity-aware rider reassignment before physical courier handover (first-mile)
     * or before physical final-mile departure (`out_for_delivery`).
     *
     * @return array{delivery: Delivery, phase: string}
     */
    public function reassignRider(Delivery $delivery, User $actor, ?int $newRiderId, string $reason): array
    {
        $cleanReason = trim($reason);
        if (mb_strlen($cleanReason) < 5) {
            throw new DomainException('Provide an operational reason (at least 5 characters) before reassigning a rider.');
        }

        return DB::transaction(function () use ($delivery, $actor, $newRiderId, $cleanReason): array {
            // Deterministic lock order: Order -> Delivery -> Network -> Riders sorted by user_id ASC
            $orderId = Delivery::whereKey($delivery->id)->value('order_id');
            $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $lockedDelivery->setRelation('order', $lockedOrder);

            $isFinalMile = in_array($lockedDelivery->status, ['sorted_to_barangay_bin', 'assigned_to_rider'], true);
            $isFirstMile = in_array($lockedDelivery->status, ['unassigned', 'assigned', 'assigned_pickup'], true);

            if (! $isFinalMile && ! $isFirstMile) {
                throw new DomainException('Parcel already entered courier or transit custody. Reassignment is forbidden once physical handover occurs; use supervised recovery.');
            }

            $custody = DeliveryCheckpoint::lastCustody($lockedDelivery);
            if ($isFirstMile && ($custody['kind'] ?? null) === 'courier') {
                throw new DomainException('Rider already scanned pickup custody. Complete arrival or use supervised handover recovery.');
            }

            $targetHubId = $isFinalMile ? $lockedDelivery->destination_bayan_hub_id : $lockedDelivery->origin_bayan_hub_id;
            $targetHub = $targetHubId ? LogisticsHub::find($targetHubId) : null;

            $authorized = $actor->canAccessPortal() && (
                $actor->isAdmin()
                || $this->eligibility->isCompanyManager($actor, $lockedDelivery->logistics_company_id)
                || ($targetHub && $this->eligibility->canScan($actor, $targetHub))
            );
            if (! $authorized) {
                throw new DomainException('Only authorized hub managers, company managers, or Platform Admins may reassign parcel riders.');
            }

            app(HandoverHoldService::class)->assertMovable($lockedDelivery);
            if (OrderCancellationRequest::query()->where('active_order_id', $lockedOrder->id)->where('status', 'pending')->where('respond_by', '>', now())->exists()) {
                throw new DomainException('Parcel has a pending buyer cancellation request awaiting review.');
            }

            $lockUserIds = array_values(array_unique(array_filter([
                $actor->id,
                $lockedDelivery->courier_id,
                $lockedDelivery->assigned_rider_id,
                $newRiderId,
            ])));
            sort($lockUserIds);

            $this->eligibility->lockNetwork((int) $lockedDelivery->logistics_company_id, $lockUserIds, array_filter([$targetHubId]));

            if ($isFinalMile) {
                if (! $newRiderId) {
                    throw new DomainException('Final-mile reassignment requires a replacement destination rider.');
                }

                $previousRiderId = $lockedDelivery->assigned_rider_id;
                $updated = app(OrderStateMachineService::class)->transition(
                    delivery: $lockedDelivery,
                    targetStatus: OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                    actor: $actor,
                    scanMetadata: [
                        'hub_id' => $lockedDelivery->destination_bayan_hub_id,
                        'rider_id' => $newRiderId,
                        'reassign' => true,
                        'notes' => "Final-mile rider reassigned: {$cleanReason}",
                    ]
                );

                $this->notifyReassignment($updated, $previousRiderId, $newRiderId, 'final_mile', $cleanReason);

                return ['delivery' => $updated->fresh(), 'phase' => 'final_mile'];
            }

            $previousRiderId = $lockedDelivery->courier_id;
            $newRider = null;
            if ($newRiderId !== null) {
                $newRider = User::findOrFail($newRiderId);
                $profile = CourierProfile::where('user_id', $newRiderId)->lockForUpdate()->first();
                if (! $profile || ! $profile->is_available) {
                    throw new DomainException('Replacement rider must be active and marked available for pickup.');
                }
                $this->eligibility->assertCourierScope($profile, (int) $lockedDelivery->logistics_company_id, (int) $lockedDelivery->origin_bayan_hub_id, newWork: true);
            }

            $source = DeliveryCheckpoint::state($lockedDelivery);
            $lockedDelivery->fill([
                'courier_id' => $newRiderId,
                'status' => $newRiderId ? 'assigned_pickup' : 'unassigned',
                'assigned_at' => $newRiderId ? now() : null,
            ])->save();

            DeliveryCheckpoint::record(
                delivery: $lockedDelivery,
                type: 'pickup_reassigned',
                location: $targetHub?->name ?? 'Origin Hub Dispatch',
                notes: $newRider
                    ? "Pre-handover pickup reassigned to {$newRider->name}: {$cleanReason}"
                    : "Returned pickup to open dispatch pool: {$cleanReason}",
                actor: $actor,
                hub: $targetHub,
                barcodeScanned: $lockedDelivery->tracking_number,
                evidence: [
                    'source_state' => $source,
                    'target_state' => DeliveryCheckpoint::state($lockedDelivery),
                    'custody_before' => $custody,
                    'custody_after' => $custody,
                ],
            );

            if ($newRider) {
                DeliveryCheckpoint::record(
                    delivery: $lockedDelivery,
                    type: 'assigned_pickup',
                    location: $lockedDelivery->pickup_store_name ?? ($targetHub?->name ?? 'Merchant store'),
                    notes: "Reassigned pickup rider {$newRider->name}: {$cleanReason}",
                    actor: $actor,
                    hub: $targetHub,
                    barcodeScanned: $lockedDelivery->tracking_number,
                    evidence: [
                        'source_state' => $source,
                        'target_state' => DeliveryCheckpoint::state($lockedDelivery),
                        'custody_before' => $custody,
                        'custody_after' => $custody,
                    ],
                );
            }

            DispatchEscalation::query()->where('delivery_id', $lockedDelivery->id)->update([
                'assigned_rider_id' => $newRiderId,
            ]);

            $this->etaService->applyToDelivery($lockedDelivery, $lockedOrder);
            $this->clocks->syncForDelivery($lockedDelivery, $lockedOrder);
            $this->notifyReassignment($lockedDelivery, $previousRiderId, $newRiderId, 'first_mile', $cleanReason);

            return ['delivery' => $lockedDelivery->fresh(), 'phase' => 'first_mile'];
        });
    }

    /**
     * Aggregated telemetry for Hub & Admin monitoring dashboards.
     *
     * @return array<string, mixed>
     */
    public function telemetry(?int $companyId = null, ?int $hubId = null): array
    {
        $clocksQuery = SlaClock::query()->whereIn('status', ['running', 'paused'])
            ->when($companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
            ->when($hubId, fn ($q) => $q->where('hub_id', $hubId))
            ->with(['delivery:id,tracking_number,status,current_hub_id,origin_bayan_hub_id,destination_bayan_hub_id', 'hub:id,name,code,tier', 'order:id,order_number,status'])
            ->orderBy('breach_at');

        $clocks = $clocksQuery->limit(150)->get();
        $now = now();

        $stalledHubAlerts = $clocks->filter(
            fn (SlaClock $c) => $c->clock_kind === 'hub_idle_sorting' && $c->status === 'running' && $c->breach_at && $c->breach_at->lessThanOrEqualTo($now)
        )->values()->all();

        $escalations = Schema::hasTable('dispatch_escalations')
            ? DispatchEscalation::query()
                ->when($companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when($hubId, fn ($q) => $q->where('origin_bayan_hub_id', $hubId))
                ->whereNotIn('status', ['resolved', 'auto_cancelled'])
                ->with(['delivery:id,tracking_number,status,courier_id', 'order:id,order_number,status', 'originHub:id,name,code'])
                ->orderByDesc('is_urgent')
                ->orderBy('pickup_sla_started_at')
                ->limit(80)
                ->get()
            : collect();

        $pickupPauses = Schema::hasTable('hub_pickup_pauses')
            ? HubPickupPause::query()
                ->where('status', 'active')
                ->when($companyId, fn ($q) => $q->where('logistics_company_id', $companyId))
                ->when($hubId, fn ($q) => $q->where('logistics_hub_id', $hubId))
                ->with('hub:id,name,code')
                ->get()
            : collect();

        $overloadAlerts = [];
        if ($companyId) {
            $hubs = LogisticsHub::eligible()
                ->where('logistics_company_id', $companyId)
                ->where('tier', 'local_bayan_hub')
                ->when($hubId, fn ($q) => $q->whereKey($hubId))
                ->get();

            foreach ($hubs as $hub) {
                foreach ($this->density->calculateForHub($hub->id) as $brgyRow) {
                    if (($brgyRow['parcel_count'] ?? 0) >= 40) {
                        $overloadAlerts[] = [
                            'hub_id' => $hub->id,
                            'hub_name' => $hub->name,
                            ...$brgyRow,
                        ];
                    }
                }
            }
        }

        return [
            'counts' => [
                'running_clocks' => $clocks->where('status', 'running')->count(),
                'paused_clocks' => $clocks->where('status', 'paused')->count(),
                'breached_clocks' => $clocks->filter(fn (SlaClock $c) => $c->status === 'running' && $c->breach_at && $c->breach_at->lessThanOrEqualTo($now))->count(),
                'stalled_hub_alerts' => count($stalledHubAlerts),
                'urgent_pickups' => $escalations->where('is_urgent', true)->count(),
                'active_pickup_pauses' => $pickupPauses->count(),
                'overloaded_barangays' => count($overloadAlerts),
            ],
            'stalled_hub_alerts' => $stalledHubAlerts,
            'escalations' => $escalations,
            'pickup_pauses' => $pickupPauses,
            'overload_alerts' => $overloadAlerts,
        ];
    }

    /**
     * @return Collection<int, CourierProfile>
     */
    private function availableFirstMileRiders(int $companyId, int $originHubId)
    {
        return CourierProfile::operational()
            ->where('logistics_company_id', $companyId)
            ->where('assigned_hub_id', $originHubId)
            ->where('is_available', true)
            ->with('user')
            ->get()
            ->filter(function (CourierProfile $profile): bool {
                if (! $profile->user || ! $profile->user->isEligibleCourier()) {
                    return false;
                }
                if (Delivery::activePickupCount($profile->user_id) >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) {
                    return false;
                }

                return ! Delivery::riderHasActiveWork($profile->user_id);
            })
            ->values();
    }

    private function findBestFirstMileRider(int $companyId, int $originHubId, int $exceptDeliveryId): ?CourierProfile
    {
        return CourierProfile::operational()
            ->where('logistics_company_id', $companyId)
            ->where('assigned_hub_id', $originHubId)
            ->where('is_available', true)
            ->with('user')
            ->get()
            ->filter(function (CourierProfile $profile) use ($exceptDeliveryId): bool {
                if (! $profile->user || ! $profile->user->isEligibleCourier()) {
                    return false;
                }
                if (Delivery::activePickupCount($profile->user_id) >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) {
                    return false;
                }

                return ! Delivery::riderHasActiveWork($profile->user_id, $exceptDeliveryId);
            })
            ->sortBy(fn (CourierProfile $profile) => [Delivery::activePickupCount($profile->user_id), $profile->user_id])
            ->first();
    }

    private function hasPendingHoldOrPause(Order $order, Delivery $delivery): bool
    {
        if (HandoverIssue::query()->where('active_delivery_id', $delivery->id)->where('state', 'needs_recovery')->exists()) {
            return true;
        }

        if (OrderCancellationRequest::query()->where('active_order_id', $order->id)->where('status', 'pending')->where('respond_by', '>', now())->exists()) {
            return true;
        }

        return $this->pauses->matchingActive($delivery->logistics_company_id, [$delivery->origin_bayan_hub_id])->isNotEmpty();
    }

    private function tierRank(string $tier): int
    {
        $pos = array_search($tier, DispatchEscalation::TIERS, true);

        return $pos === false ? 0 : (int) $pos;
    }

    private function recordEscalationEvent(DispatchEscalation $escalation, string $type, ?string $fromTier, ?string $toTier, string $key, array $payload = []): void
    {
        if (DispatchEscalationEvent::where('idempotency_key', $key)->exists()) {
            return;
        }

        DispatchEscalationEvent::create([
            'dispatch_escalation_id' => $escalation->id,
            'event_type' => $type,
            'from_tier' => $fromTier,
            'to_tier' => $toTier,
            'idempotency_key' => $key,
            'payload' => $payload,
        ]);
    }

    private function notifyDelay(Order $order, Delivery $delivery): void
    {
        foreach (array_unique(array_filter([$order->buyer_id, $order->shop?->user_id])) as $userId) {
            $this->notifications->record(
                (int) $userId,
                'parcel-event',
                "pickup-delay-notice:{$delivery->id}:u:{$userId}",
                [
                    'title' => "Pickup Delayed — Reassigning Rider ({$delivery->tracking_number})",
                    'body' => 'Seller pickup exceeded 24 hours; Bagoo escalated dispatch priority to assign the nearest eligible rider.',
                    'href' => $userId === $order->buyer_id ? "/buyer/orders/{$order->id}" : '/seller/orders',
                    'order_id' => $order->id,
                    'delivery_id' => $delivery->id,
                ]
            );
        }
    }

    private function notifyEmergencySweep(Delivery $delivery): void
    {
        if (! $delivery->logistics_company_id) {
            return;
        }

        $opsIds = $this->eligibility->companyOperatorIds((int) $delivery->logistics_company_id)->take(15);

        foreach ($opsIds as $userId) {
            $this->notifications->record(
                (int) $userId,
                'parcel-event',
                "pickup-sweep:{$delivery->id}:u:{$userId}",
                [
                    'title' => "Origin Sweep Needed ({$delivery->tracking_number})",
                    'body' => 'Ready seller parcel reached 36h pickup wait. Activate or assign an eligible company courier.',
                    'href' => '/hub/deliveries',
                    'delivery_id' => $delivery->id,
                ]
            );
        }
    }

    private function notifyReassignment(Delivery $delivery, ?int $previousRiderId, ?int $newRiderId, string $phase, string $reason): void
    {
        $titlePrefix = $phase === 'final_mile' ? 'Final-Mile' : 'Pickup';
        if ($previousRiderId && $previousRiderId !== $newRiderId) {
            $this->notifications->record(
                $previousRiderId,
                'parcel-event',
                "reassign-out:{$delivery->id}:r:{$previousRiderId}:".md5($reason.now()->timestamp),
                [
                    'title' => "{$titlePrefix} Parcel Reassigned ({$delivery->tracking_number})",
                    'body' => "Hub operations reassigned this parcel before handover: {$reason}",
                    'href' => '/courier/dashboard',
                    'delivery_id' => $delivery->id,
                ]
            );
        }

        if ($newRiderId) {
            $this->notifications->record(
                $newRiderId,
                'parcel-event',
                "reassign-in:{$delivery->id}:r:{$newRiderId}:".md5($reason.now()->timestamp),
                [
                    'title' => "Assigned {$titlePrefix} Parcel ({$delivery->tracking_number})",
                    'body' => "Reassigned to your queue: {$reason}",
                    'href' => '/courier/dashboard',
                    'delivery_id' => $delivery->id,
                ]
            );
        }

        if ($phase === 'first_mile' && $delivery->order?->shop?->user_id) {
            $this->notifications->record(
                $delivery->order->shop->user_id,
                'order-event',
                "reassign-seller:{$delivery->id}:r:".($newRiderId ?? 0),
                [
                    'title' => "Pickup Courier Updated ({$delivery->tracking_number})",
                    'body' => "Carrier updated the assigned pickup rider: {$reason}",
                    'href' => '/seller/orders',
                    'order_id' => $delivery->order_id,
                ]
            );
        }
    }
}
