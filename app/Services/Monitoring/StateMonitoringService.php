<?php

namespace App\Services\Monitoring;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\SlaClock;
use App\Models\SlaClockEvent;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class StateMonitoringService
{
    public function __construct(
        private SlaClockService $clocks,
        private SlaPauseService $pauses,
        private EtaCalculationService $etaService,
        private NotificationDeliveryService $notifications,
    ) {}

    /**
     * Evaluates running SLA clocks and overdue buyer returns.
     * Supports strict read-only dry-run previews and concurrency-safe live scans.
     *
     * @return array<string, mixed>
     */
    public function scan(bool $dryRun = false, int $limit = 100): array
    {
        if (! Schema::hasTable('sla_clocks')) {
            return [
                'dry_run' => $dryRun,
                'scanned_at' => now()->toIso8601String(),
                'checked' => 0,
                'skipped_state_changed' => 0,
                'warnings' => [],
                'breaches' => [],
                'criticals' => [],
                'escalation_candidates' => [],
                'investigation_candidates' => [],
                'return_reminders' => [],
                'return_escalations' => [],
                'return_closures' => [],
            ];
        }

        if (! $dryRun) {
            $this->backfillActiveSubjects(min(150, $limit));
        }

        $now = now();
        $summary = [
            'dry_run' => $dryRun,
            'scanned_at' => $now->toIso8601String(),
            'checked' => 0,
            'skipped_state_changed' => 0,
            'warnings' => [],
            'breaches' => [],
            'criticals' => [],
            'escalation_candidates' => [],
            'investigation_candidates' => [],
            'return_reminders' => [],
            'return_escalations' => [],
            'return_closures' => [],
        ];

        $dueClocks = SlaClock::query()
            ->where('status', 'running')
            ->where(function ($q) use ($now): void {
                $q->where(function ($w) use ($now): void {
                    $w->whereNull('last_warned_at')->where('warning_at', '<=', $now);
                })->orWhere(function ($b) use ($now): void {
                    $b->whereNull('last_breached_at')->where('breach_at', '<=', $now);
                })->orWhere(function ($c) use ($now): void {
                    $c->whereNull('last_critical_at')->whereNotNull('critical_at')->where('critical_at', '<=', $now);
                });
            })
            ->orderBy('breach_at')
            ->limit($limit)
            ->get();

        foreach ($dueClocks as $candidate) {
            $summary['checked']++;
            if ($dryRun) {
                $this->collectDryRunDecision($candidate, $summary);
            } else {
                $this->processClockLive($candidate->id, $summary);
            }
        }

        // Handle return cases even before a clock was created (dry-run or live safety)
        if ($dryRun) {
            $this->collectDryRunReturns($summary, $limit);
        } else {
            // Re-run B15 dispatch ladder sweeps if B15 service is registered
            $recoveryClass = \App\Services\Logistics\DispatchRecoveryService::class;
            if (class_exists($recoveryClass)) {
                app($recoveryClass)->processEscalations(dryRun: false, limit: $limit);
            }
        }

        return $summary;
    }

    private function backfillActiveSubjects(int $limit): void
    {
        Order::query()
            ->whereIn('status', ['placed', 'confirmed', 'preparing', 'ready_for_pickup', 'in_transit', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'delivery_failed'])
            ->whereDoesntHave('delivery', fn ($q) => $q->whereHas('slaClocks', fn ($c) => $c->where('status', '!=', 'stopped')))
            ->limit($limit)
            ->get()
            ->each(fn (Order $order) => $this->clocks->syncForOrder($order));

        BuyerReturnCase::query()
            ->whereIn('status', ['requested', 'rejected', 'approved'])
            ->limit($limit)
            ->get()
            ->each(fn (BuyerReturnCase $case) => $this->clocks->syncForBuyerReturnCase($case));

        PickupClaim::query()
            ->where('status', 'ready')
            ->limit($limit)
            ->get()
            ->each(fn (PickupClaim $claim) => $this->clocks->syncForPickupClaim($claim));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function collectDryRunDecision(SlaClock $clock, array &$summary): void
    {
        if (! $this->isSubjectStillEligibleForAlert($clock)) {
            $summary['skipped_state_changed']++;

            return;
        }

        $now = now();
        $item = [
            'clock_id' => $clock->id,
            'clock_kind' => $clock->clock_kind,
            'subject_type' => $clock->subject_type,
            'subject_id' => $clock->subject_id,
            'order_id' => $clock->order_id,
            'delivery_id' => $clock->delivery_id,
            'breach_at' => $clock->breach_at?->toIso8601String(),
        ];

        if ($clock->warning_at && $clock->warning_at->lessThanOrEqualTo($now) && ! $clock->last_warned_at) {
            if ($clock->clock_kind === 'return_decision_window') {
                $summary['return_reminders'][] = $item;
            } else {
                $summary['warnings'][] = $item;
            }
        }

        if ($clock->breach_at && $clock->breach_at->lessThanOrEqualTo($now) && ! $clock->last_breached_at) {
            if ($clock->clock_kind === 'return_decision_window') {
                $case = BuyerReturnCase::find($clock->subject_id);
                if ($case?->status === 'requested') {
                    $summary['return_escalations'][] = $item;
                } elseif ($case && in_array($case->status, ['rejected', 'approved'], true)) {
                    $summary['return_closures'][] = $item;
                }
            } else {
                $summary['breaches'][] = $item;
                if ($clock->clock_kind === 'pickup_dispatch_wait') {
                    $summary['escalation_candidates'][] = $item;
                }
                if (in_array($clock->clock_kind, ['forward_interhub_transit', 'reverse_consolidation_transit'], true)) {
                    $summary['investigation_candidates'][] = $item;
                }
            }
        }

        if ($clock->critical_at && $clock->critical_at->lessThanOrEqualTo($now) && ! $clock->last_critical_at) {
            $summary['criticals'][] = $item;
            if (in_array($clock->clock_kind, ['hub_idle_sorting', 'forward_interhub_transit', 'reverse_consolidation_transit'], true)) {
                $summary['investigation_candidates'][] = $item;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function collectDryRunReturns(array &$summary, int $limit): void
    {
        $now = now();
        BuyerReturnCase::query()
            ->whereIn('status', ['requested', 'rejected', 'approved'])
            ->limit($limit)
            ->get()
            ->each(function (BuyerReturnCase $case) use ($now, &$summary): void {
                $deadline = match ($case->status) {
                    'requested' => $case->response_until,
                    'rejected' => $case->appeal_until,
                    'approved' => $case->dropoff_until,
                    default => null,
                };
                if (! $deadline) {
                    return;
                }

                $alreadyClocked = SlaClock::query()
                    ->where('subject_type', 'buyer_return_case')
                    ->where('subject_id', $case->id)
                    ->where('clock_kind', 'return_decision_window')
                    ->exists();
                if ($alreadyClocked) {
                    return;
                }

                if ($now->greaterThan($deadline)) {
                    if ($case->status === 'requested') {
                        $summary['return_escalations'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference, 'status' => $case->status];
                    } else {
                        $summary['return_closures'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference, 'status' => $case->status];
                    }
                } elseif ($now->greaterThanOrEqualTo($deadline->copy()->subHours(24))) {
                    $summary['return_reminders'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference, 'status' => $case->status];
                }
            });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function processClockLive(int $clockId, array &$summary): void
    {
        DB::transaction(function () use ($clockId, &$summary): void {
            // Deterministic lock ordering: Order -> Delivery -> SlaClock
            $pre = SlaClock::whereKey($clockId)->first();
            if (! $pre) {
                return;
            }
            if ($pre->order_id) {
                Order::whereKey($pre->order_id)->lockForUpdate()->first();
            }
            if ($pre->delivery_id) {
                Delivery::whereKey($pre->delivery_id)->lockForUpdate()->first();
            }

            $clock = SlaClock::whereKey($clockId)->lockForUpdate()->first();
            if (! $clock || $clock->status !== 'running') {
                $summary['skipped_state_changed']++;

                return;
            }

            // Re-sync live state inside the lock so concurrent scans/cancellations/pauses never double-trigger
            $this->resyncClockUnderLock($clock);
            $clock->refresh();
            if ($clock->status !== 'running') {
                $summary['skipped_state_changed']++;

                return;
            }

            $now = now();
            $sig = (string) ($clock->metadata['leg_signature'] ?? 'default');

            if ($clock->warning_at && $clock->warning_at->lessThanOrEqualTo($now) && ! $clock->last_warned_at) {
                $clock->last_warned_at = $now;
                $clock->save();
                $this->recordAndNotifyThreshold($clock, 'warned', "warn:{$clock->id}:{$sig}", $summary);
            }

            if ($clock->breach_at && $clock->breach_at->lessThanOrEqualTo($now) && ! $clock->last_breached_at) {
                $clock->last_breached_at = $now;
                $clock->save();
                $this->recordAndNotifyThreshold($clock, 'breached', "breach:{$clock->id}:{$sig}", $summary);
                $this->handleBreachAction($clock, $summary);
            }

            if ($clock->critical_at && $clock->critical_at->lessThanOrEqualTo($now) && ! $clock->last_critical_at) {
                $clock->last_critical_at = $now;
                $clock->save();
                $this->recordAndNotifyThreshold($clock, 'critical', "critical:{$clock->id}:{$sig}", $summary);
                $this->handleCriticalAction($clock, $summary);
            }
        });
    }

    private function resyncClockUnderLock(SlaClock $clock): void
    {
        if ($clock->subject_type === 'order') {
            $order = Order::with('delivery')->find($clock->subject_id);
            if ($order) {
                $this->clocks->syncForOrder($order);
            }

            return;
        }

        if ($clock->subject_type === 'delivery') {
            $delivery = Delivery::with('order')->find($clock->subject_id);
            if ($delivery) {
                $this->clocks->syncForDelivery($delivery, $delivery->order);
            }

            return;
        }

        if ($clock->subject_type === 'pickup_claim') {
            $claim = PickupClaim::with('delivery')->find($clock->subject_id);
            if ($claim) {
                $this->clocks->syncForPickupClaim($claim, $claim->delivery);
            }

            return;
        }

        if ($clock->subject_type === 'buyer_return_case') {
            $case = BuyerReturnCase::with('delivery')->find($clock->subject_id);
            if ($case) {
                $this->clocks->syncForBuyerReturnCase($case);
            }

            return;
        }

        if ($clock->subject_type === 'buyer_return_parcel') {
            $parcel = BuyerReturnParcel::with('returnCase.delivery')->find($clock->subject_id);
            if ($parcel) {
                $this->clocks->syncForBuyerReturnParcel($parcel);
            }
        }
    }

    private function isSubjectStillEligibleForAlert(SlaClock $clock): bool
    {
        if ($clock->status !== 'running') {
            return false;
        }

        $pauses = $this->pauses->matchingActive($clock->logistics_company_id, array_filter([$clock->hub_id]));
        if ($pauses->isNotEmpty()) {
            return false;
        }

        if ($clock->subject_type === 'order') {
            $order = Order::find($clock->subject_id);

            return (bool) ($order && in_array($order->status, ['placed', 'confirmed', 'preparing'], true));
        }

        if ($clock->subject_type === 'delivery') {
            $delivery = Delivery::with('order')->find($clock->subject_id);
            if (! $delivery || ! $delivery->order || in_array($delivery->order->status, ['cancelled', 'completed', 'returned'], true)) {
                return false;
            }

            return true;
        }

        if ($clock->subject_type === 'pickup_claim') {
            $claim = PickupClaim::find($clock->subject_id);

            return (bool) ($claim && $claim->status === 'ready');
        }

        if ($clock->subject_type === 'buyer_return_case') {
            $case = BuyerReturnCase::find($clock->subject_id);

            return (bool) ($case && in_array($case->status, ['requested', 'rejected', 'approved'], true));
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordAndNotifyThreshold(SlaClock $clock, string $level, string $idempotencyKey, array &$summary): void
    {
        if (SlaClockEvent::where('idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        SlaClockEvent::create([
            'sla_clock_id' => $clock->id,
            'event_type' => $level,
            'idempotency_key' => $idempotencyKey,
            'payload' => ['clock_kind' => $clock->clock_kind, 'delivery_id' => $clock->delivery_id, 'order_id' => $clock->order_id],
        ]);

        if ($clock->clock_kind === 'return_decision_window') {
            if ($level === 'warned') {
                $summary['return_reminders'][] = ['clock_id' => $clock->id, 'case_id' => $clock->subject_id];
                $this->notifyReturnReminder($clock, $idempotencyKey);
            }

            return;
        }

        $bucket = match ($level) {
            'warned' => 'warnings',
            'breached' => 'breaches',
            default => 'criticals',
        };
        $summary[$bucket][] = ['clock_id' => $clock->id, 'clock_kind' => $clock->clock_kind, 'delivery_id' => $clock->delivery_id, 'order_id' => $clock->order_id];

        $this->dispatchThresholdNotifications($clock, $level, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function handleBreachAction(SlaClock $clock, array &$summary): void
    {
        if ($clock->delivery) {
            $this->etaService->applyToDelivery($clock->delivery, $clock->order);
        }

        if ($clock->clock_kind === 'return_decision_window') {
            $this->automateReturnExpiry($clock, $summary);

            return;
        }

        if ($clock->clock_kind === 'pickup_dispatch_wait') {
            $summary['escalation_candidates'][] = ['clock_id' => $clock->id, 'delivery_id' => $clock->delivery_id];
            $recoveryClass = \App\Services\Logistics\DispatchRecoveryService::class;
            if (class_exists($recoveryClass) && $clock->delivery) {
                app($recoveryClass)->evaluateDelivery($clock->delivery);
            }

            return;
        }

        if (in_array($clock->clock_kind, ['forward_interhub_transit', 'reverse_consolidation_transit'], true)) {
            $summary['investigation_candidates'][] = ['clock_id' => $clock->id, 'delivery_id' => $clock->delivery_id, 'clock_kind' => $clock->clock_kind];
            $this->openInvestigationIfAvailable($clock, 'scan_timeout');
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function handleCriticalAction(SlaClock $clock, array &$summary): void
    {
        if (in_array($clock->clock_kind, ['hub_idle_sorting', 'forward_interhub_transit', 'reverse_consolidation_transit'], true)) {
            $sourceKind = $clock->clock_kind === 'hub_idle_sorting' ? 'hub_stall' : 'scan_timeout';
            $summary['investigation_candidates'][] = ['clock_id' => $clock->id, 'delivery_id' => $clock->delivery_id, 'clock_kind' => $clock->clock_kind];
            $this->openInvestigationIfAvailable($clock, $sourceKind);
        }
    }

    private function openInvestigationIfAvailable(SlaClock $clock, string $sourceKind): void
    {
        $investigationClass = \App\Services\Logistics\MissingParcelInvestigationService::class;
        if (! class_exists($investigationClass)) {
            return;
        }

        app($investigationClass)->openFromClock($clock, $sourceKind);
    }

    /**
     * Preserves B11 strict boundary: never auto-approves seller timeouts or moves money.
     *
     * @param  array<string, mixed>  $summary
     */
    private function automateReturnExpiry(SlaClock $clock, array &$summary): void
    {
        $case = BuyerReturnCase::whereKey($clock->subject_id)->lockForUpdate()->first();
        if (! $case || in_array($case->status, BuyerReturnCase::TERMINAL, true)) {
            return;
        }

        $now = now();
        $systemAdmin = User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->whereNull('closed_at')
            ->orderBy('id')
            ->first();

        if ($case->status === 'requested' && $case->response_until && $now->greaterThan($case->response_until)) {
            $source = $case->status;
            $case->status = 'escalated';
            $case->version++;
            $case->save();

            if ($systemAdmin) {
                BuyerReturnEvent::create([
                    'buyer_return_case_id' => $case->id,
                    'actor_id' => $systemAdmin->id,
                    'sequence' => $case->version,
                    'event_type' => 'escalate',
                    'source_status' => $source,
                    'target_status' => $case->status,
                    'reason' => 'Seller 72-hour response deadline passed; escalated to admin review without auto-approving refunds.',
                    'request_token_hash' => hash('sha256', "auto_return_escalate:{$case->id}:{$case->version}"),
                    'request_fingerprint' => hash('sha256', "auto_return_escalate:{$case->id}:{$source}"),
                    'context' => [
                        'response_until' => $case->response_until?->toIso8601String(),
                        'appeal_until' => $case->appeal_until?->toIso8601String(),
                        'dropoff_until' => $case->dropoff_until?->toIso8601String(),
                        'automated_monitoring' => true,
                    ],
                ]);
            }

            $summary['return_escalations'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference];
            $this->clocks->syncForBuyerReturnCase($case);
            $this->notifyReturnAction($case, 'Return Escalated For Admin Review', "Return {$case->reference} exceeded seller response window and was escalated for admin review.", "/returns/{$case->id}");

            return;
        }

        if ($case->status === 'rejected' && $case->appeal_until && $now->greaterThan($case->appeal_until)) {
            $source = $case->status;
            $case->status = 'denied';
            $case->version++;
            $case->save();
            $this->recordReturnClosureEvent($case, $source, $systemAdmin, 'Buyer 72-hour appeal window expired without appeal.');
            $summary['return_closures'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference, 'target_status' => 'denied'];
            $this->clocks->syncForBuyerReturnCase($case);

            return;
        }

        if ($case->status === 'approved' && $case->dropoff_until && $now->greaterThan($case->dropoff_until)) {
            $source = $case->status;
            $case->status = 'expired';
            $case->version++;
            $case->save();
            DB::table('buyer_return_unit_claims')->where('buyer_return_case_id', $case->id)->delete();
            $this->recordReturnClosureEvent($case, $source, $systemAdmin, 'Buyer 7-day hub drop-off window expired; unit claims released.');
            $summary['return_closures'][] = ['buyer_return_case_id' => $case->id, 'reference' => $case->reference, 'target_status' => 'expired'];
            $this->clocks->syncForBuyerReturnCase($case);
        }
    }

    private function recordReturnClosureEvent(BuyerReturnCase $case, string $source, ?User $actor, string $reason): void
    {
        if ($actor) {
            BuyerReturnEvent::create([
                'buyer_return_case_id' => $case->id,
                'actor_id' => $actor->id,
                'sequence' => $case->version,
                'event_type' => 'close-period',
                'source_status' => $source,
                'target_status' => $case->status,
                'reason' => $reason,
                'request_token_hash' => hash('sha256', "auto_return_close:{$case->id}:{$case->version}"),
                'request_fingerprint' => hash('sha256', "auto_return_close:{$case->id}:{$source}:{$case->status}"),
                'context' => [
                    'response_until' => $case->response_until?->toIso8601String(),
                    'appeal_until' => $case->appeal_until?->toIso8601String(),
                    'dropoff_until' => $case->dropoff_until?->toIso8601String(),
                    'automated_monitoring' => true,
                ],
            ]);
        }

        $this->notifyReturnAction($case, 'Return Window Closed', $reason, "/returns/{$case->id}");
    }

    private function dispatchThresholdNotifications(SlaClock $clock, string $level, string $idempotencyKey): void
    {
        $order = $clock->order;
        $delivery = $clock->delivery;
        $ref = $delivery?->tracking_number ?? $order?->order_number ?? "Clock #{$clock->id}";
        $actionLabel = ucfirst($level).' SLA';

        $recipients = collect();
        if ($clock->clock_kind === 'seller_acceptance_pack' && $order?->shop?->user_id) {
            $recipients->push([
                'user_id' => $order->shop->user_id,
                'type' => 'order-event',
                'title' => "Packing SLA {$actionLabel}: {$ref}",
                'body' => 'Prepare and mark the parcel ready for pickup before dispatch cutoffs slip.',
                'link' => '/seller/orders',
            ]);
        }

        if ($level !== 'warned') {
            User::query()->where('role', 'admin')->where('status', 'active')->whereNull('closed_at')->pluck('id')
                ->each(function (int $adminId) use ($clock, $ref, $level, $recipients): void {
                    $recipients->push([
                        'user_id' => $adminId,
                        'type' => 'governance-event',
                        'title' => "SLA {$level}: {$clock->clock_kind} ({$ref})",
                        'body' => "Monitoring detected a {$level} threshold on {$clock->clock_kind}.",
                        'link' => '/governance/monitoring',
                    ]);
                });
        }

        if ($clock->logistics_company_id) {
            app(LogisticsEligibilityService::class)
                ->companyOperatorIds((int) $clock->logistics_company_id)
                ->take(10)
                ->each(function (int $opsId) use ($clock, $ref, $level, $recipients): void {
                    $recipients->push([
                        'user_id' => $opsId,
                        'type' => 'parcel-event',
                        'title' => "Hub SLA {$level}: {$ref}",
                        'body' => "{$clock->clock_kind} reached {$level} threshold.",
                        'link' => '/hub/dashboard',
                    ]);
                });
        }

        foreach ($recipients->unique(fn ($r) => $r['user_id'].':'.$r['type']) as $entry) {
            $this->notifications->record(
                (int) $entry['user_id'],
                $entry['type'],
                "sla:{$idempotencyKey}:u:{$entry['user_id']}",
                [
                    'title' => $entry['title'],
                    'body' => $entry['body'],
                    'href' => $entry['link'],
                    'clock_kind' => $clock->clock_kind,
                    'order_id' => $clock->order_id,
                    'delivery_id' => $clock->delivery_id,
                ]
            );
        }
    }

    private function notifyReturnReminder(SlaClock $clock, string $idempotencyKey): void
    {
        $case = BuyerReturnCase::find($clock->subject_id);
        if (! $case) {
            return;
        }

        $targetUserId = match ($case->status) {
            'requested' => $case->seller_id,
            'rejected', 'approved' => $case->buyer_id,
            default => null,
        };

        if (! $targetUserId) {
            return;
        }

        $this->notifications->record(
            $targetUserId,
            'buyer-return',
            "sla-return-warn:{$idempotencyKey}:u:{$targetUserId}",
            [
                'title' => "Return Deadline Reminder ({$case->reference})",
                'body' => 'Your return window expires within 24 hours. Review and act before the deadline closes.',
                'href' => "/returns/{$case->id}",
                'buyer_return_case_id' => $case->id,
            ]
        );
    }

    private function notifyReturnAction(BuyerReturnCase $case, string $title, string $body, string $href): void
    {
        $recipientIds = array_unique(array_filter([
            $case->buyer_id,
            $case->seller_id,
            ...User::query()->where('role', 'admin')->where('status', 'active')->whereNull('closed_at')->pluck('id')->all(),
        ]));

        foreach ($recipientIds as $recipientId) {
            $this->notifications->record(
                (int) $recipientId,
                'buyer-return',
                "return-sla-action:{$case->id}:v:{$case->version}:u:{$recipientId}",
                [
                    'title' => $title,
                    'body' => $body,
                    'href' => $href,
                    'buyer_return_case_id' => $case->id,
                ]
            );
        }
    }
}
