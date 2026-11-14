<?php

namespace App\Services\Monitoring;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryRecoveryEvent;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\SlaClock;
use App\Models\SlaPause;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class EtaCalculationService
{
    public const PHT_TIMEZONE = 'Asia/Manila';

    public const CUTOFF_HOUR = 16;

    /**
     * Read-only checkout route ETA preview. Never mutates database records.
     *
     * @param  array<int, int>  $routeHubIds
     * @return array<string, mixed>
     */
    public function preview(
        LogisticsCompany $company,
        array $routeHubIds,
        bool $sameHubBypass,
        string $deliveryType = 'doorstep',
        ?LogisticsHub $originHub = null,
        ?LogisticsHub $destinationHub = null,
        ?CarbonInterface $referenceTime = null,
    ): array {
        $now = $this->toImmutable($referenceTime ?? now());
        $pht = $now->setTimezone(self::PHT_TIMEZONE);
        $cutoffApplied = $pht->hour >= self::CUTOFF_HOUR;

        [$cutoffMin, $cutoffMax] = $cutoffApplied ? [12, 24] : [0, 0];
        [$prepMin, $prepMax] = [12, 36];
        [$pickupMin, $pickupMax] = [2, 12];
        [$transitMin, $transitMax] = $this->transitWindow($routeHubIds, $sameHubBypass, visitedHops: 0);
        [$finalMin, $finalMax] = $deliveryType === 'hub_self_pickup' ? [2, 8] : [6, 24];

        $totalMin = $cutoffMin + $prepMin + $pickupMin + $transitMin + $finalMin;
        $totalMax = $cutoffMax + $prepMax + $pickupMax + $transitMax + $finalMax;

        $pauses = $this->activePausesFor(
            $company->id,
            array_values(array_filter([$originHub?->id, $destinationHub?->id])),
            array_values(array_filter([$originHub?->province, $destinationHub?->province])),
            $now,
        );

        $activePause = $pauses->first();
        $pauseBufferHours = $activePause ? 24 : 0;
        $minAt = $now->addHours($totalMin + $pauseBufferHours)->utc();
        $maxAt = $now->addHours($totalMax + $pauseBufferHours)->utc();

        return [
            'estimated_min_at' => $minAt->toIso8601String(),
            'estimated_max_at' => $maxAt->toIso8601String(),
            'cutoff_applied' => $cutoffApplied,
            'cutoff_note' => $cutoffApplied
                ? 'Placed after the 16:00 PHT same-day cutoff (+12h to +24h schedule buffer).'
                : 'Qualifies before the 16:00 PHT same-day seller staging cutoff.',
            'eta_status' => $activePause ? 'paused' : 'on_track',
            'active_pause' => $activePause ? $this->serializePause($activePause, publicSafe: true) : null,
            'label' => $this->formatPhtRange($minAt, $maxAt),
            'note' => $this->buildPreviewNote($sameHubBypass, count($routeHubIds), $deliveryType, $activePause),
        ];
    }

    /**
     * Computes updated ETA bounds and audit basis for a persisted Delivery.
     *
     * @return array<string, mixed>
     */
    public function calculateForDelivery(
        Delivery $delivery,
        ?CarbonInterface $referenceTime = null,
        ?Order $order = null,
        int $shiftSeconds = 0,
    ): array {
        $now = $this->toImmutable($referenceTime ?? now());
        $order ??= $delivery->order;
        $orderStatus = strtolower((string) ($order?->status ?? 'placed'));
        $deliveryStatus = strtolower((string) ($delivery->status ?? 'unassigned'));
        $existingBasis = is_array($delivery->eta_basis) ? $delivery->eta_basis : [];

        if (in_array($orderStatus, ['cancelled'], true)) {
            return [
                'estimated_delivery_min_at' => $delivery->estimated_delivery_min_at,
                'estimated_delivery_max_at' => $delivery->estimated_delivery_max_at,
                'estimated_delivery_at' => $delivery->estimated_delivery_at,
                'eta_basis' => array_replace($existingBasis, [
                    'eta_status' => 'cancelled',
                    'recalculated_at' => $now->toIso8601String(),
                    'note' => 'Order was cancelled before completion.',
                ]),
            ];
        }

        if (in_array($deliveryStatus, ['delivered', 'customer_collected'], true) || in_array($orderStatus, ['delivered', 'completed'], true)) {
            $deliveredAt = $delivery->delivered_at ?? $now;

            return [
                'estimated_delivery_min_at' => $delivery->estimated_delivery_min_at ?? $deliveredAt,
                'estimated_delivery_max_at' => $delivery->estimated_delivery_max_at ?? $deliveredAt,
                'estimated_delivery_at' => $delivery->estimated_delivery_at ?? $deliveredAt,
                'eta_basis' => array_replace($existingBasis, [
                    'eta_status' => 'completed',
                    'recalculated_at' => $now->toIso8601String(),
                    'note' => 'Handover completed.',
                ]),
            ];
        }

        $anchorOrderCreated = $order?->created_at ? $this->toImmutable($order->created_at) : $now;
        $cutoffApplied = $anchorOrderCreated->setTimezone(self::PHT_TIMEZONE)->hour >= self::CUTOFF_HOUR;

        [$remMin, $remMax] = $this->remainingSegmentHours($delivery, $orderStatus, $deliveryStatus, $cutoffApplied, $now);

        $retryAt = $this->futureRetryAt($delivery, $now);
        $baseClockStart = $retryAt ?? $now;

        $pauses = $this->activePausesFor(
            $delivery->logistics_company_id,
            array_values(array_filter([
                $delivery->origin_bayan_hub_id,
                $delivery->origin_mother_hub_id,
                $delivery->destination_mother_hub_id,
                $delivery->destination_bayan_hub_id,
                $delivery->current_hub_id,
            ])),
            [],
            $now,
        );

        $activePause = $pauses->first();
        $ongoingPauseHours = $activePause ? 24 : 0;
        $ongoingElapsed = $activePause ? max(0, (int) $this->toImmutable($activePause->starts_at)->diffInSeconds($now)) : 0;

        $minAt = $baseClockStart->addSeconds($ongoingElapsed)->addHours($remMin + $ongoingPauseHours)->utc();
        $maxAt = $baseClockStart->addSeconds($ongoingElapsed)->addHours($remMax + $ongoingPauseHours)->utc();

        if ($shiftSeconds > 0) {
            if ($delivery->estimated_delivery_min_at) {
                $shiftedMin = $this->toImmutable($delivery->estimated_delivery_min_at)->addSeconds($shiftSeconds)->utc();
                if ($shiftedMin->greaterThan($minAt)) {
                    $minAt = $shiftedMin;
                }
            }
            if ($delivery->estimated_delivery_max_at) {
                $shiftedMax = $this->toImmutable($delivery->estimated_delivery_max_at)->addSeconds($shiftSeconds)->utc();
                if ($shiftedMax->greaterThan($maxAt)) {
                    $maxAt = $shiftedMax;
                }
            }
        }

        if ($maxAt->lessThanOrEqualTo($minAt)) {
            $maxAt = $minAt->addHours(max(4, $remMax - $remMin));
        }

        $initialMinAt = $existingBasis['initial_min_at'] ?? $minAt->toIso8601String();
        $initialMaxAt = $existingBasis['initial_max_at'] ?? $maxAt->toIso8601String();

        $etaStatus = 'on_track';
        if ($activePause) {
            $etaStatus = 'paused';
        } elseif ($delivery->estimated_delivery_max_at && $now->greaterThan($this->toImmutable($delivery->estimated_delivery_max_at))) {
            $etaStatus = 'delayed';
        } elseif ($deliveryStatus === 'delivery_failed' || str_starts_with($deliveryStatus, 'return')) {
            $etaStatus = 'delayed';
        }

        $routeHubIds = is_array($delivery->route_hub_ids) ? $delivery->route_hub_ids : [];
        $routeTier = $delivery->same_hub_bypass
            ? 'same_hub_bypass'
            : (count($routeHubIds) <= 3 && count($routeHubIds) >= 2 ? 'same_mother_hub' : (count($routeHubIds) >= 4 ? 'cross_mother_hub' : 'legacy_v1'));

        return [
            'estimated_delivery_min_at' => $minAt,
            'estimated_delivery_max_at' => $maxAt,
            'estimated_delivery_at' => $maxAt,
            'eta_basis' => array_replace($existingBasis, [
                'initial_min_at' => $initialMinAt,
                'initial_max_at' => $initialMaxAt,
                'route_tier' => $routeTier,
                'cutoff_applied' => $cutoffApplied,
                'remaining_hours_min' => $remMin,
                'remaining_hours_max' => $remMax,
                'eta_status' => $etaStatus,
                'pause_reference' => $activePause?->reference,
                'pause_reason_code' => $activePause?->reason_code,
                'pause_title' => $activePause?->title,
                'paused_seconds_applied' => (int) ($existingBasis['paused_seconds_applied'] ?? 0) + max(0, $shiftSeconds),
                'recalculated_at' => $now->toIso8601String(),
                'note' => $activePause
                    ? "SLA clock paused due to {$activePause->title}."
                    : ($etaStatus === 'delayed' ? 'Revised ETA calculated from current live parcel custody.' : 'Calculated dynamically from route topology and live custody stage.'),
            ]),
        ];
    }

    public function applyToDelivery(
        Delivery $delivery,
        ?Order $order = null,
        bool $persist = true,
        int $shiftSeconds = 0,
    ): Delivery {
        $computed = $this->calculateForDelivery($delivery, now(), $order, $shiftSeconds);
        $delivery->forceFill([
            'estimated_delivery_min_at' => $computed['estimated_delivery_min_at'],
            'estimated_delivery_max_at' => $computed['estimated_delivery_max_at'],
            'estimated_delivery_at' => $computed['estimated_delivery_at'],
            'eta_basis' => $computed['eta_basis'],
        ]);

        if ($persist && $delivery->exists) {
            $delivery->save();
        }

        return $delivery;
    }

    /**
     * Presents ETA data cleanly for Buyer, Seller, Hub, Admin, or Public views.
     *
     * @return array<string, mixed>|null
     */
    public function present(?Delivery $delivery, ?Order $order = null, bool $publicSafe = false): ?array
    {
        if (! $delivery) {
            return null;
        }

        $minAt = $delivery->estimated_delivery_min_at ? $this->toImmutable($delivery->estimated_delivery_min_at) : null;
        $maxAt = $delivery->estimated_delivery_max_at
            ? $this->toImmutable($delivery->estimated_delivery_max_at)
            : ($delivery->estimated_delivery_at ? $this->toImmutable($delivery->estimated_delivery_at) : null);

        if (! $minAt && $maxAt) {
            $minAt = $maxAt->subHours(24);
        }

        $basis = is_array($delivery->eta_basis) ? $delivery->eta_basis : [];
        $etaStatus = (string) ($basis['eta_status'] ?? 'on_track');

        if ($etaStatus === 'on_track' && $maxAt && now()->greaterThan($maxAt) && ! in_array($delivery->status, ['delivered', 'customer_collected'], true)) {
            $etaStatus = 'delayed';
        }

        $pauseTitle = $basis['pause_title'] ?? null;
        $pauseReason = $basis['pause_reason_code'] ?? null;
        $pauseNotice = null;
        if ($etaStatus === 'paused' || $pauseReason) {
            $pauseNotice = $publicSafe
                ? 'Schedule paused due to a regional Force Majeure or service advisory. Delivery promises will resume automatically.'
                : ($pauseTitle ? "SLA Paused: {$pauseTitle}" : 'Force-Majeure / Outage SLA pause is active on this route.');
        }

        $delayNotice = null;
        if ($etaStatus === 'delayed') {
            $delayNotice = $publicSafe
                ? 'Delivery window updated based on the latest transit progress.'
                : ($basis['note'] ?? 'Revised ETA calculated after exceeding initial forecast window.');
        }

        return [
            'estimated_min_at' => $minAt?->toIso8601String(),
            'estimated_max_at' => $maxAt?->toIso8601String(),
            'estimated_at' => $maxAt?->toIso8601String(),
            'label' => ($minAt && $maxAt) ? $this->formatPhtRange($minAt, $maxAt) : 'Forecast pending waybill schedule',
            'eta_status' => $etaStatus,
            'status_label' => match ($etaStatus) {
                'paused' => 'Paused (Force Majeure)',
                'delayed' => 'Delayed (Updated Forecast)',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default => 'On Track',
            },
            'cutoff_applied' => (bool) ($basis['cutoff_applied'] ?? false),
            'pause_notice' => $pauseNotice,
            'delay_notice' => $delayNotice,
            'route_tier' => $basis['route_tier'] ?? null,
        ];
    }

    public function formatPhtRange(CarbonInterface $minAt, CarbonInterface $maxAt): string
    {
        $minPht = $this->toImmutable($minAt)->setTimezone(self::PHT_TIMEZONE);
        $maxPht = $this->toImmutable($maxAt)->setTimezone(self::PHT_TIMEZONE);

        if ($minPht->isSameDay($maxPht)) {
            return $minPht->format('M j, Y').' (PHT)';
        }

        if ($minPht->format('Y-m') === $maxPht->format('Y-m')) {
            return $minPht->format('M j').' - '.$maxPht->format('j, Y').' (PHT)';
        }

        return $minPht->format('M j').' - '.$maxPht->format('M j, Y').' (PHT)';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function remainingSegmentHours(
        Delivery $delivery,
        string $orderStatus,
        string $deliveryStatus,
        bool $cutoffApplied,
        CarbonImmutable $now,
    ): array {
        $routeHubIds = is_array($delivery->route_hub_ids) ? $delivery->route_hub_ids : [];
        $prePickup = in_array($deliveryStatus, ['unassigned', 'assigned', 'assigned_pickup'], true)
            && in_array($orderStatus, ['placed', 'confirmed', 'preparing', 'ready_for_pickup'], true);

        [$cutoffMin, $cutoffMax] = ($prePickup && $cutoffApplied) ? [12, 24] : [0, 0];

        [$prepMin, $prepMax] = match ($orderStatus) {
            'placed', 'confirmed' => [12, 36],
            'preparing' => [6, 24],
            default => [0, 0],
        };

        $pickupMin = 0;
        $pickupMax = 0;
        if ($prePickup) {
            [$pickupMin, $pickupMax] = $orderStatus === 'ready_for_pickup' ? [4, 24] : [2, 12];
        } elseif ($deliveryStatus === 'picked_up') {
            [$pickupMin, $pickupMax] = [2, 8];
        }

        [$transitMin, $transitMax] = [0, 0];
        if (! in_array($deliveryStatus, [
            'arrived_at_destination_hub',
            'sorted_to_barangay_bin',
            'ready_for_hub_pickup',
            'assigned_to_rider',
            'out_for_delivery',
            'delivery_failed',
            'return_to_sender',
            'return_in_transit',
        ], true)) {
            $visitedHops = match ($deliveryStatus) {
                'arrived_at_origin_hub' => 1,
                'in_transit_to_mother_hub' => 1,
                'arrived_at_mother_hub', 'sorted_to_line_haul' => 2,
                'in_transit_to_destination_hub' => max(2, count($routeHubIds) - 1),
                default => 0,
            };
            [$transitMin, $transitMax] = $this->transitWindow($routeHubIds, (bool) $delivery->same_hub_bypass, $visitedHops);
        }

        if ($delivery->delivery_type === 'hub_self_pickup') {
            [$finalMin, $finalMax] = $deliveryStatus === 'ready_for_hub_pickup' ? [0, 0] : [2, 8];
        } else {
            [$finalMin, $finalMax] = match ($deliveryStatus) {
                'out_for_delivery' => [1, 8],
                'sorted_to_barangay_bin', 'assigned_to_rider' => [4, 16],
                'delivery_failed', 'return_to_sender', 'return_in_transit' => [12, 36],
                default => [6, 24],
            };
        }

        return [
            $cutoffMin + $prepMin + $pickupMin + $transitMin + $finalMin,
            $cutoffMax + $prepMax + $pickupMax + $transitMax + $finalMax,
        ];
    }

    /**
     * @param  array<int, int>  $routeHubIds
     * @return array{0: int, 1: int}
     */
    private function transitWindow(array $routeHubIds, bool $sameHubBypass, int $visitedHops): array
    {
        if ($sameHubBypass) {
            return $visitedHops >= 1 ? [2, 6] : [4, 12];
        }

        $hubCount = count($routeHubIds);
        if ($hubCount === 0) {
            $baseMin = 36;
            $baseMax = 72;
            $totalHops = 3;
        } elseif ($hubCount <= 3) {
            $baseMin = 24;
            $baseMax = 48;
            $totalHops = max(1, $hubCount - 1);
        } else {
            $baseMin = 48;
            $baseMax = 84;
            $totalHops = max(2, $hubCount - 1);
        }

        $remainingHops = max(0, $totalHops - $visitedHops);
        if ($remainingHops === 0) {
            return [2, 6];
        }

        $ratio = $remainingHops / $totalHops;

        return [
            max(2, (int) round($baseMin * $ratio)),
            max(6, (int) round($baseMax * $ratio)),
        ];
    }

    private function futureRetryAt(Delivery $delivery, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($delivery->status !== 'delivery_failed' || ! $delivery->exists) {
            return null;
        }

        $attempt = DeliveryAttempt::query()->where('delivery_id', $delivery->id)->latest('attempt_number')->first();
        if (! $attempt) {
            return null;
        }

        $approval = DeliveryRecoveryEvent::query()
            ->where('delivery_attempt_id', $attempt->id)
            ->where('event_type', 'retry_approved')
            ->first();

        if (! $approval?->retry_at) {
            return null;
        }

        $retryAt = $this->toImmutable($approval->retry_at);

        return $retryAt->greaterThan($now) ? $retryAt : null;
    }

    /**
     * @param  array<int, int>  $hubIds
     * @param  array<int, string>  $extraProvinces
     */
    private function activePausesFor(?int $companyId, array $hubIds, array $extraProvinces, CarbonImmutable $point)
    {
        $cleanHubIds = array_values(array_unique(array_filter($hubIds)));
        $hubProvinces = $cleanHubIds === []
            ? []
            : LogisticsHub::query()->whereIn('id', $cleanHubIds)->pluck('province')->filter()->all();
        $provinces = array_values(array_unique(array_merge($hubProvinces, $extraProvinces)));

        return SlaPause::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', $point)
            ->where(function ($q) use ($point): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $point);
            })
            ->orderByDesc('starts_at')
            ->get()
            ->filter(fn (SlaPause $pause) => $pause->matchesContext($companyId, $cleanHubIds, $provinces))
            ->values();
    }

    private function serializePause(SlaPause $pause, bool $publicSafe): array
    {
        return [
            'reference' => $pause->reference,
            'scope_type' => $pause->scope_type,
            'reason_code' => $pause->reason_code,
            'title' => $publicSafe ? 'Force Majeure / Regional Service Advisory' : $pause->title,
            'starts_at' => $pause->starts_at?->toIso8601String(),
            'ends_at' => $pause->ends_at?->toIso8601String(),
        ];
    }

    private function buildPreviewNote(bool $sameHubBypass, int $hubCount, string $deliveryType, ?SlaPause $pause): string
    {
        if ($pause) {
            return "Force-Majeure pause active ({$pause->reason_code}). Projected delivery window includes a buffer while conditions stabilize.";
        }

        $tierText = $sameHubBypass
            ? 'Local same-child-hub fast sorting route.'
            : ($hubCount >= 4 ? 'Cross-Mother-Hub regional line-haul route.' : 'Same-Mother-Hub regional shuttle route.');

        return $deliveryType === 'hub_self_pickup'
            ? $tierText.' Ready for counter ID + claim code release upon arrival.'
            : $tierText.' Includes final-mile doorstep dispatch window.';
    }

    private function toImmutable(CarbonInterface $time): CarbonImmutable
    {
        return $time instanceof CarbonImmutable ? $time : CarbonImmutable::instance($time);
    }
}
