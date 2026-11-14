<?php

namespace App\Services\Monitoring;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryRecoveryEvent;
use App\Models\HandoverIssue;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\PickupClaim;
use App\Models\SlaClock;
use App\Models\SlaClockEvent;
use App\Models\SlaPause;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class SlaClockService
{
    public function __construct(
        private SlaPauseService $pauses,
    ) {}

    public function syncForOrder(Order $order): void
    {
        $order->loadMissing('delivery');
        $status = strtolower((string) $order->status);
        $paymentPending = $order->payment_method === 'wallet' && $order->payment_status !== 'paid';

        if (in_array($status, ['placed', 'confirmed', 'preparing'], true) && ! $paymentPending) {
            $hubIds = array_values(array_filter([$order->delivery?->origin_bayan_hub_id]));
            $companyId = $order->delivery?->logistics_company_id;
            $pauseReason = $this->hasPendingCancellation($order->id) ? 'cancellation_pending' : null;
            $activePause = $pauseReason ? null : $this->pauses->matchingActive($companyId, $hubIds)->first();

            $this->upsertRunningOrPaused(
                subjectType: 'order',
                subjectId: $order->id,
                clockKind: 'seller_acceptance_pack',
                attributes: [
                    'order_id' => $order->id,
                    'delivery_id' => $order->delivery?->id,
                    'logistics_company_id' => $companyId,
                    'hub_id' => $order->delivery?->origin_bayan_hub_id,
                    'started_at' => $order->created_at ?? now(),
                    'warning_hours' => $status === 'preparing' ? 36 : 24,
                    'breach_hours' => 48,
                    'critical_hours' => 72,
                    'leg_signature' => 'seller_prep',
                    'metadata' => ['order_status' => $status],
                ],
                holdReason: $pauseReason,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('order', $order->id, 'seller_acceptance_pack', "order_status:{$status}");
        }

        if ($order->delivery) {
            $this->syncForDelivery($order->delivery, $order);
        }
    }

    public function syncForDelivery(Delivery $delivery, ?Order $order = null): void
    {
        $order ??= $delivery->order;
        $orderStatus = strtolower((string) ($order?->status ?? 'placed'));
        $status = strtolower((string) $delivery->status);
        $terminal = in_array($orderStatus, ['cancelled', 'completed', 'returned'], true)
            || in_array($status, ['delivered', 'customer_collected'], true);

        if ($terminal) {
            foreach ([
                'pickup_dispatch_wait',
                'hub_idle_sorting',
                'final_mile_staging',
                'forward_interhub_transit',
                'reverse_consolidation_transit',
            ] as $kind) {
                $this->stopClock('delivery', $delivery->id, $kind, "terminal:{$orderStatus}/{$status}");
            }

            if ($delivery->pickupClaim) {
                $this->stopClock('pickup_claim', $delivery->pickupClaim->id, 'counter_pickup_holding', "terminal:{$status}");
            }

            return;
        }

        $hasHold = $this->hasOpenHandoverHold($delivery->id);
        $hasPendingCancel = $order ? $this->hasPendingCancellation($order->id) : false;

        // 1. First-mile pickup_dispatch_wait
        if ($orderStatus === 'ready_for_pickup' && in_array($status, ['unassigned', 'assigned', 'assigned_pickup'], true)) {
            $holdReason = $hasPendingCancel ? 'cancellation_pending' : ($hasHold ? 'handover_hold' : null);
            $activePause = $holdReason ? null : $this->pauses->matchingActive($delivery->logistics_company_id, [$delivery->origin_bayan_hub_id])->first();

            $this->upsertRunningOrPaused(
                subjectType: 'delivery',
                subjectId: $delivery->id,
                clockKind: 'pickup_dispatch_wait',
                attributes: [
                    'order_id' => $delivery->order_id,
                    'delivery_id' => $delivery->id,
                    'logistics_company_id' => $delivery->logistics_company_id,
                    'hub_id' => $delivery->origin_bayan_hub_id,
                    'started_at' => now(),
                    'warning_hours' => 12,
                    'breach_hours' => 24,
                    'critical_hours' => 48,
                    'leg_signature' => 'pickup_wait',
                    'metadata' => ['delivery_status' => $status],
                ],
                holdReason: $holdReason,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('delivery', $delivery->id, 'pickup_dispatch_wait', "delivery_status:{$status}");
        }

        // 2. hub_idle_sorting (B14 Topic 27: PAUSE while staged_for_final_mile or ready_for_hub_pickup!)
        if (in_array($status, [
            'arrived_at_origin_hub',
            'arrived_at_mother_hub',
            'arrived_at_destination_hub',
            'sorted_to_line_haul',
            'sorted_to_barangay_bin',
            'ready_for_hub_pickup',
        ], true)) {
            $hubId = $delivery->current_hub_id ?? $delivery->destination_bayan_hub_id ?? $delivery->origin_bayan_hub_id;
            $holdReason = match (true) {
                $status === 'sorted_to_barangay_bin' => 'staged_for_final_mile',
                $status === 'ready_for_hub_pickup' => 'counter_holding',
                $hasHold => 'handover_hold',
                default => null,
            };
            $activePause = $holdReason ? null : $this->pauses->matchingActive($delivery->logistics_company_id, [$hubId])->first();

            $this->upsertRunningOrPaused(
                subjectType: 'delivery',
                subjectId: $delivery->id,
                clockKind: 'hub_idle_sorting',
                attributes: [
                    'order_id' => $delivery->order_id,
                    'delivery_id' => $delivery->id,
                    'logistics_company_id' => $delivery->logistics_company_id,
                    'hub_id' => $hubId,
                    'started_at' => now(),
                    'warning_hours' => 2,
                    'breach_hours' => 24,
                    'critical_hours' => 48,
                    'leg_signature' => "hub:{$hubId}",
                    'metadata' => ['delivery_status' => $status, 'hub_id' => $hubId, 'manager_alert_hours' => 12],
                ],
                holdReason: $holdReason,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('delivery', $delivery->id, 'hub_idle_sorting', "left_hub:{$status}");
        }

        // 3. final_mile_staging
        if (in_array($status, ['sorted_to_barangay_bin', 'assigned_to_rider', 'out_for_delivery', 'delivery_failed'], true)) {
            $retryFuture = $this->hasFutureApprovedRetry($delivery);
            $holdReason = match (true) {
                $hasHold => 'handover_hold',
                $retryFuture => 'retry_scheduled',
                $status === 'delivery_failed' && $delivery->current_hub_id !== null => 'parcel_recovery',
                default => null,
            };
            $activePause = $holdReason ? null : $this->pauses->matchingActive($delivery->logistics_company_id, [$delivery->destination_bayan_hub_id])->first();

            $this->upsertRunningOrPaused(
                subjectType: 'delivery',
                subjectId: $delivery->id,
                clockKind: 'final_mile_staging',
                attributes: [
                    'order_id' => $delivery->order_id,
                    'delivery_id' => $delivery->id,
                    'logistics_company_id' => $delivery->logistics_company_id,
                    'hub_id' => $delivery->destination_bayan_hub_id,
                    'started_at' => now(),
                    'warning_hours' => 12,
                    'breach_hours' => 24,
                    'critical_hours' => 48,
                    'leg_signature' => 'final_mile_'.($status === 'out_for_delivery' ? 'ofd' : 'stage'),
                    'metadata' => ['delivery_status' => $status],
                ],
                holdReason: $holdReason,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('delivery', $delivery->id, 'final_mile_staging', "delivery_status:{$status}");
        }

        // 4. forward_interhub_transit (48h inter-hub, or 24h pickup->origin gap)
        if (in_array($status, ['picked_up', 'in_transit_to_mother_hub', 'in_transit_to_destination_hub'], true)) {
            $activePause = $hasHold
                ? null
                : $this->pauses->matchingActive($delivery->logistics_company_id, [
                    $delivery->origin_bayan_hub_id,
                    $delivery->origin_mother_hub_id,
                    $delivery->destination_mother_hub_id,
                    $delivery->destination_bayan_hub_id,
                ])->first();

            $isPreOriginGap = $status === 'picked_up';
            $this->upsertRunningOrPaused(
                subjectType: 'delivery',
                subjectId: $delivery->id,
                clockKind: 'forward_interhub_transit',
                attributes: [
                    'order_id' => $delivery->order_id,
                    'delivery_id' => $delivery->id,
                    'logistics_company_id' => $delivery->logistics_company_id,
                    'hub_id' => $delivery->origin_bayan_hub_id,
                    'started_at' => now(),
                    'warning_hours' => $isPreOriginGap ? 12 : 36,
                    'breach_hours' => $isPreOriginGap ? 24 : 48,
                    'critical_hours' => $isPreOriginGap ? 48 : 72,
                    'leg_signature' => "transit:{$status}",
                    'metadata' => ['delivery_status' => $status],
                ],
                holdReason: $hasHold ? 'handover_hold' : null,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('delivery', $delivery->id, 'forward_interhub_transit', "delivery_status:{$status}");
        }

        // 5. reverse_consolidation_transit (asymmetric 96h = +48h over forward 48h)
        if (in_array($status, ['return_to_sender', 'return_in_transit'], true)) {
            $pausedAtOriginReturn = $delivery->current_hub_id === $delivery->origin_bayan_hub_id && $status === 'return_to_sender';
            $holdReason = match (true) {
                $hasHold => 'handover_hold',
                $pausedAtOriginReturn => 'seller_return_staged',
                default => null,
            };
            $activePause = $holdReason ? null : $this->pauses->matchingActive($delivery->logistics_company_id, [
                $delivery->destination_bayan_hub_id,
                $delivery->origin_bayan_hub_id,
                $delivery->current_hub_id,
            ])->first();

            $this->upsertRunningOrPaused(
                subjectType: 'delivery',
                subjectId: $delivery->id,
                clockKind: 'reverse_consolidation_transit',
                attributes: [
                    'order_id' => $delivery->order_id,
                    'delivery_id' => $delivery->id,
                    'logistics_company_id' => $delivery->logistics_company_id,
                    'hub_id' => $delivery->current_hub_id ?? $delivery->destination_bayan_hub_id,
                    'started_at' => now(),
                    'warning_hours' => 48,
                    'breach_hours' => 96,
                    'critical_hours' => 144,
                    'leg_signature' => 'reverse_delivery',
                    'metadata' => ['delivery_status' => $status],
                ],
                holdReason: $holdReason,
                activePause: $activePause,
            );
        } else {
            $this->stopClock('delivery', $delivery->id, 'reverse_consolidation_transit', "delivery_status:{$status}");
        }

        if ($delivery->pickupClaim) {
            $this->syncForPickupClaim($delivery->pickupClaim, $delivery);
        }
    }

    public function syncForPickupClaim(PickupClaim $claim, ?Delivery $delivery = null): void
    {
        $delivery ??= $claim->delivery;
        if ($claim->status !== 'ready') {
            $this->stopClock('pickup_claim', $claim->id, 'counter_pickup_holding', "claim_status:{$claim->status}");

            return;
        }

        $activePause = $this->pauses->matchingActive($delivery?->logistics_company_id, [$claim->hub_id])->first();
        $startedAt = $claim->created_at ?? now();

        $this->upsertRunningOrPaused(
            subjectType: 'pickup_claim',
            subjectId: $claim->id,
            clockKind: 'counter_pickup_holding',
            attributes: [
                'order_id' => $delivery?->order_id,
                'delivery_id' => $delivery?->id,
                'logistics_company_id' => $delivery?->logistics_company_id,
                'hub_id' => $claim->hub_id,
                'started_at' => $startedAt,
                'warning_hours' => 72,
                'breach_hours' => 144,
                'critical_hours' => 168,
                'leg_signature' => 'counter_holding',
                'metadata' => ['claim_reference' => $claim->reference],
            ],
            holdReason: null,
            activePause: $activePause,
        );
    }

    /**
     * Effective expiry respects immutable pickup_claims.expires_at PLUS any paused SLA holding seconds.
     */
    public function effectiveExpiresAt(PickupClaim $claim, ?CarbonInterface $at = null): CarbonImmutable
    {
        $point = $at instanceof CarbonImmutable ? $at : CarbonImmutable::instance($at ?? now());
        $clock = SlaClock::query()
            ->where('subject_type', 'pickup_claim')
            ->where('subject_id', $claim->id)
            ->where('clock_kind', 'counter_pickup_holding')
            ->first();

        $extraSeconds = (int) ($clock?->paused_seconds ?? 0);
        if ($clock && $clock->status === 'paused' && $clock->paused_at && $clock->active_pause_id) {
            $extraSeconds += max(0, (int) $clock->paused_at->diffInSeconds($point));
        } elseif (! $clock) {
            $pause = $this->pauses->matchingActive($claim->delivery?->logistics_company_id, [$claim->hub_id], $point)->first();
            if ($pause) {
                $extraSeconds += max(0, (int) $pause->starts_at->diffInSeconds($point)) + 86400;
            }
        }

        return CarbonImmutable::instance($claim->expires_at)->addSeconds($extraSeconds);
    }

    public function syncForBuyerReturnCase(BuyerReturnCase $case): void
    {
        $deadline = match ($case->status) {
            'requested' => $case->response_until,
            'rejected' => $case->appeal_until,
            'approved' => $case->dropoff_until,
            default => null,
        };

        if (! $deadline) {
            $this->stopClock('buyer_return_case', $case->id, 'return_decision_window', "case_status:{$case->status}");

            return;
        }

        $case->loadMissing('delivery');
        $companyId = $case->delivery?->logistics_company_id;
        $hubIds = array_values(array_filter([
            $case->delivery?->origin_bayan_hub_id,
            $case->delivery?->destination_bayan_hub_id,
        ]));
        $activePause = $this->pauses->matchingActive($companyId, $hubIds)->first();

        $deadlineImmutable = CarbonImmutable::instance($deadline);
        $warningAt = $deadlineImmutable->subHours(24);
        if ($warningAt->lessThan($case->updated_at ?? now())) {
            $warningAt = $deadlineImmutable->subHours(6);
        }

        $existing = SlaClock::query()
            ->where('subject_type', 'buyer_return_case')
            ->where('subject_id', $case->id)
            ->where('clock_kind', 'return_decision_window')
            ->first();

        $phaseSig = "case:{$case->status}:".$deadlineImmutable->toIso8601String();
        $samePhase = $existing && ($existing->metadata['leg_signature'] ?? null) === $phaseSig;

        if (! $existing) {
            $clock = SlaClock::create([
                'subject_type' => 'buyer_return_case',
                'subject_id' => $case->id,
                'clock_kind' => 'return_decision_window',
                'order_id' => $case->order_id,
                'delivery_id' => $case->delivery_id,
                'logistics_company_id' => $companyId,
                'hub_id' => $case->delivery?->destination_bayan_hub_id,
                'status' => $activePause ? 'paused' : 'running',
                'pause_reason' => $activePause ? 'force_majeure' : null,
                'active_pause_id' => $activePause?->id,
                'started_at' => now(),
                'warning_at' => $warningAt,
                'breach_at' => $deadlineImmutable,
                'critical_at' => $deadlineImmutable->addHours(24),
                'paused_at' => $activePause ? now() : null,
                'metadata' => ['case_status' => $case->status, 'leg_signature' => $phaseSig],
            ]);

            SlaClockEvent::create([
                'sla_clock_id' => $clock->id,
                'event_type' => 'started',
                'idempotency_key' => "start:{$clock->id}:{$phaseSig}",
                'payload' => ['case_status' => $case->status, 'deadline' => $deadlineImmutable->toIso8601String()],
            ]);

            return;
        }

        if (! $samePhase || $existing->status === 'stopped') {
            $existing->update([
                'status' => $activePause ? 'paused' : 'running',
                'pause_reason' => $activePause ? 'force_majeure' : null,
                'active_pause_id' => $activePause?->id,
                'started_at' => now(),
                'warning_at' => $warningAt,
                'breach_at' => $deadlineImmutable,
                'critical_at' => $deadlineImmutable->addHours(24),
                'paused_at' => $activePause ? now() : null,
                'stopped_at' => null,
                'last_warned_at' => null,
                'last_breached_at' => null,
                'last_critical_at' => null,
                'metadata' => ['case_status' => $case->status, 'leg_signature' => $phaseSig],
            ]);

            return;
        }

        $this->reconcilePauseState($existing, null, $activePause);
    }

    public function syncForBuyerReturnParcel(BuyerReturnParcel $parcel): void
    {
        $parcel->loadMissing('returnCase.delivery');
        $case = $parcel->returnCase;
        $status = strtolower((string) $parcel->status);

        if (in_array($status, ['awaiting_dropoff', 'seller_received', 'closed'], true)
            || ($case && in_array($case->status, BuyerReturnCase::TERMINAL, true))) {
            $this->stopClock('buyer_return_parcel', $parcel->id, 'reverse_consolidation_transit', "parcel_status:{$status}");

            return;
        }

        if (! in_array($status, ['at_hub', 'in_transit', 'ready_for_seller'], true)) {
            $this->stopClock('buyer_return_parcel', $parcel->id, 'reverse_consolidation_transit', "parcel_status:{$status}");

            return;
        }

        $companyId = $case?->delivery?->logistics_company_id;
        $hubIds = array_values(array_filter([$parcel->current_hub_id, ...(is_array($parcel->hub_ids) ? $parcel->hub_ids : [])]));
        $holdReason = $status === 'ready_for_seller' ? 'seller_return_staged' : null;
        $activePause = $holdReason ? null : $this->pauses->matchingActive($companyId, $hubIds)->first();

        $this->upsertRunningOrPaused(
            subjectType: 'buyer_return_parcel',
            subjectId: $parcel->id,
            clockKind: 'reverse_consolidation_transit',
            attributes: [
                'order_id' => $case?->order_id,
                'delivery_id' => $case?->delivery_id,
                'logistics_company_id' => $companyId,
                'hub_id' => $parcel->current_hub_id,
                'started_at' => now(),
                'warning_hours' => 48,
                'breach_hours' => 96,
                'critical_hours' => 144,
                'leg_signature' => 'buyer_return_transit',
                'metadata' => ['parcel_status' => $status],
            ],
            holdReason: $holdReason,
            activePause: $activePause,
        );
    }

    public function nonForceMajeurePauseReason(SlaClock $clock): ?string
    {
        if ($clock->clock_kind === 'seller_acceptance_pack' && $clock->order_id && $this->hasPendingCancellation($clock->order_id)) {
            return 'cancellation_pending';
        }

        if ($clock->clock_kind === 'pickup_dispatch_wait') {
            if ($clock->order_id && $this->hasPendingCancellation($clock->order_id)) {
                return 'cancellation_pending';
            }
            if ($clock->delivery_id && $this->hasOpenHandoverHold($clock->delivery_id)) {
                return 'handover_hold';
            }
        }

        if ($clock->clock_kind === 'hub_idle_sorting' && $clock->delivery) {
            if ($clock->delivery->status === 'sorted_to_barangay_bin') {
                return 'staged_for_final_mile';
            }
            if ($clock->delivery->status === 'ready_for_hub_pickup') {
                return 'counter_holding';
            }
            if ($this->hasOpenHandoverHold($clock->delivery->id)) {
                return 'handover_hold';
            }
        }

        if ($clock->clock_kind === 'final_mile_staging' && $clock->delivery) {
            if ($this->hasOpenHandoverHold($clock->delivery->id)) {
                return 'handover_hold';
            }
            if ($this->hasFutureApprovedRetry($clock->delivery)) {
                return 'retry_scheduled';
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function upsertRunningOrPaused(
        string $subjectType,
        int $subjectId,
        string $clockKind,
        array $attributes,
        ?string $holdReason,
        ?SlaPause $activePause,
    ): SlaClock {
        $now = CarbonImmutable::instance($attributes['started_at'] ?? now());
        $clock = SlaClock::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('clock_kind', $clockKind)
            ->first();

        $sig = (string) ($attributes['leg_signature'] ?? 'default');
        $targetPauseReason = $holdReason ?? ($activePause ? 'force_majeure' : null);
        $targetStatus = $targetPauseReason ? 'paused' : 'running';

        $meta = array_merge($attributes['metadata'] ?? [], ['leg_signature' => $sig]);

        if (! $clock) {
            $clock = SlaClock::create([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'clock_kind' => $clockKind,
                'order_id' => $attributes['order_id'] ?? null,
                'delivery_id' => $attributes['delivery_id'] ?? null,
                'logistics_company_id' => $attributes['logistics_company_id'] ?? null,
                'hub_id' => $attributes['hub_id'] ?? null,
                'status' => $targetStatus,
                'pause_reason' => $targetPauseReason,
                'active_pause_id' => $holdReason ? null : $activePause?->id,
                'started_at' => $now,
                'warning_at' => $now->addHours((int) $attributes['warning_hours']),
                'breach_at' => $now->addHours((int) $attributes['breach_hours']),
                'critical_at' => isset($attributes['critical_hours']) ? $now->addHours((int) $attributes['critical_hours']) : null,
                'paused_at' => $targetStatus === 'paused' ? now() : null,
                'metadata' => $meta,
            ]);

            SlaClockEvent::create([
                'sla_clock_id' => $clock->id,
                'event_type' => 'started',
                'idempotency_key' => "start:{$clock->id}:{$sig}",
                'payload' => ['clock_kind' => $clockKind, 'leg_signature' => $sig],
            ]);

            return $clock;
        }

        $existingSig = $clock->metadata['leg_signature'] ?? null;
        if ($clock->status === 'stopped' || $existingSig !== $sig) {
            $freshStart = now();
            $clock->update([
                'hub_id' => $attributes['hub_id'] ?? $clock->hub_id,
                'status' => $targetStatus,
                'pause_reason' => $targetPauseReason,
                'active_pause_id' => $holdReason ? null : $activePause?->id,
                'started_at' => $freshStart,
                'warning_at' => $freshStart->copy()->addHours((int) $attributes['warning_hours']),
                'breach_at' => $freshStart->copy()->addHours((int) $attributes['breach_hours']),
                'critical_at' => isset($attributes['critical_hours']) ? $freshStart->copy()->addHours((int) $attributes['critical_hours']) : null,
                'paused_at' => $targetStatus === 'paused' ? $freshStart : null,
                'stopped_at' => null,
                'last_warned_at' => null,
                'last_breached_at' => null,
                'last_critical_at' => null,
                'metadata' => $meta,
            ]);

            $cycle = SlaClockEvent::where('sla_clock_id', $clock->id)->count() + 1;
            SlaClockEvent::create([
                'sla_clock_id' => $clock->id,
                'event_type' => 'reopened',
                'idempotency_key' => "reopen:{$clock->id}:{$sig}:{$cycle}",
                'payload' => ['clock_kind' => $clockKind, 'leg_signature' => $sig],
            ]);

            return $clock;
        }

        $clock->metadata = array_merge($clock->metadata ?? [], $meta);
        $this->reconcilePauseState($clock, $holdReason, $activePause);

        return $clock;
    }

    private function reconcilePauseState(SlaClock $clock, ?string $holdReason, ?SlaPause $activePause): void
    {
        $desiredReason = $holdReason ?? ($activePause ? 'force_majeure' : null);

        if ($desiredReason !== null) {
            $changed = $clock->status !== 'paused'
                || $clock->pause_reason !== $desiredReason
                || $clock->active_pause_id !== ($holdReason ? null : $activePause?->id);

            if ($changed) {
                $pausedAt = $clock->paused_at ?? now();
                $clock->update([
                    'status' => 'paused',
                    'pause_reason' => $desiredReason,
                    'active_pause_id' => $holdReason ? null : $activePause?->id,
                    'paused_at' => $pausedAt,
                ]);
            }

            return;
        }

        if ($clock->status === 'paused') {
            $delta = $clock->paused_at ? max(0, (int) $clock->paused_at->diffInSeconds(now())) : 0;
            $clock->update([
                'status' => 'running',
                'pause_reason' => null,
                'active_pause_id' => null,
                'paused_at' => null,
                'paused_seconds' => (int) $clock->paused_seconds + $delta,
                'warning_at' => $clock->warning_at?->copy()->addSeconds($delta),
                'breach_at' => $clock->breach_at?->copy()->addSeconds($delta),
                'critical_at' => $clock->critical_at?->copy()->addSeconds($delta),
            ]);
        } else {
            if ($clock->isDirty('metadata')) {
                $clock->save();
            }
        }
    }

    private function stopClock(string $subjectType, int $subjectId, string $clockKind, string $reason): void
    {
        $clock = SlaClock::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('clock_kind', $clockKind)
            ->where('status', '!=', 'stopped')
            ->first();

        if (! $clock) {
            return;
        }

        $clock->update([
            'status' => 'stopped',
            'stopped_at' => now(),
            'active_pause_id' => null,
            'metadata' => array_merge($clock->metadata ?? [], ['stop_reason' => $reason]),
        ]);

        $count = SlaClockEvent::where('sla_clock_id', $clock->id)->count() + 1;
        SlaClockEvent::create([
            'sla_clock_id' => $clock->id,
            'event_type' => 'stopped',
            'idempotency_key' => "stop:{$clock->id}:{$count}",
            'payload' => ['reason' => $reason],
        ]);
    }

    private function hasPendingCancellation(int $orderId): bool
    {
        return OrderCancellationRequest::query()
            ->where('active_order_id', $orderId)
            ->where('status', 'pending')
            ->where('respond_by', '>', now())
            ->exists();
    }

    private function hasOpenHandoverHold(int $deliveryId): bool
    {
        return HandoverIssue::query()
            ->where('active_delivery_id', $deliveryId)
            ->where('state', 'needs_recovery')
            ->exists();
    }

    private function hasFutureApprovedRetry(Delivery $delivery): bool
    {
        if ($delivery->status !== 'delivery_failed') {
            return false;
        }

        $attempt = DeliveryAttempt::query()->where('delivery_id', $delivery->id)->latest('attempt_number')->first();
        if (! $attempt) {
            return false;
        }

        $approval = DeliveryRecoveryEvent::query()
            ->where('delivery_attempt_id', $attempt->id)
            ->where('event_type', 'retry_approved')
            ->first();

        return (bool) ($approval?->retry_at && $approval->retry_at->isFuture());
    }
}
