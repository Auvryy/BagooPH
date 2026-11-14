<?php

namespace App\Services\Monitoring;

use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\SlaClock;
use App\Models\SlaClockEvent;
use App\Models\SlaPause;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SlaPauseService
{
    public function __construct(
        private LogisticsEligibilityService $eligibility,
        private EtaCalculationService $etaService,
    ) {}

    public function activate(User $actor, array $input): SlaPause
    {
        $data = Validator::make($input, [
            'scope_type' => ['required', 'string', 'in:'.implode(',', SlaPause::SCOPES)],
            'scope_value' => ['nullable', 'string', 'max:191'],
            'logistics_company_id' => ['nullable', 'integer', 'exists:logistics_companies,id'],
            'logistics_hub_id' => ['nullable', 'integer', 'exists:logistics_hubs,id'],
            'reason_code' => ['required', 'string', 'in:'.implode(',', SlaPause::REASONS)],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'ends_at' => ['nullable', 'date'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): SlaPause {
            $scopeType = $data['scope_type'];
            $companyId = $data['logistics_company_id'] ?? null;
            $hubId = $data['logistics_hub_id'] ?? null;
            $scopeValue = isset($data['scope_value']) ? trim((string) $data['scope_value']) : null;

            if ($scopeType === 'hub') {
                if (! $hubId) {
                    throw new DomainException('Select a valid logistics hub for a hub-scoped pause.');
                }
                $hub = LogisticsHub::lockForUpdate()->findOrFail($hubId);
                $companyId = $hub->logistics_company_id;
            } elseif ($scopeType === 'company') {
                if (! $companyId) {
                    throw new DomainException('Select a valid logistics company for a company-scoped pause.');
                }
                LogisticsCompany::lockForUpdate()->findOrFail($companyId);
                $hubId = null;
            } elseif ($scopeType === 'province') {
                if (! $scopeValue) {
                    throw new DomainException('Provide the affected province name for a province-scoped pause.');
                }
                $companyId = null;
                $hubId = null;
            } else {
                $companyId = null;
                $hubId = null;
                $scopeValue = null;
            }

            $this->authorize($actor, $scopeType, $companyId, $hubId);

            $startsAt = now();
            $pause = SlaPause::create([
                'scope_type' => $scopeType,
                'scope_value' => $scopeValue ?: null,
                'logistics_company_id' => $companyId,
                'logistics_hub_id' => $hubId,
                'reason_code' => $data['reason_code'],
                'title' => trim($data['title']),
                'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $data['ends_at'] ?? null,
                'created_by_id' => $actor->id,
            ]);

            $runningClocks = SlaClock::query()
                ->where('status', 'running')
                ->with(['delivery.order', 'order'])
                ->lockForUpdate()
                ->get();

            $hubProvinces = LogisticsHub::query()->pluck('province', 'id');
            $touchedDeliveryIds = [];

            foreach ($runningClocks as $clock) {
                if (! $this->clockMatchesPause($clock, $pause, $hubProvinces)) {
                    continue;
                }

                $key = "pause_start:{$pause->id}:clock:{$clock->id}";
                if (SlaClockEvent::where('idempotency_key', $key)->exists()) {
                    continue;
                }

                $clock->update([
                    'status' => 'paused',
                    'pause_reason' => 'force_majeure',
                    'active_pause_id' => $pause->id,
                    'paused_at' => $startsAt,
                ]);

                SlaClockEvent::create([
                    'sla_clock_id' => $clock->id,
                    'event_type' => 'paused_by_pause',
                    'idempotency_key' => $key,
                    'actor_id' => $actor->id,
                    'payload' => [
                        'pause_id' => $pause->id,
                        'pause_reference' => $pause->reference,
                        'reason_code' => $pause->reason_code,
                        'scope_type' => $pause->scope_type,
                    ],
                ]);

                if ($clock->delivery_id) {
                    $touchedDeliveryIds[$clock->delivery_id] = true;
                }
            }

            if ($touchedDeliveryIds !== []) {
                Delivery::query()
                    ->whereIn('id', array_keys($touchedDeliveryIds))
                    ->with('order')
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (Delivery $delivery) => $this->etaService->applyToDelivery($delivery, $delivery->order));
            }

            return $pause->fresh(['company', 'hub', 'createdBy']);
        });
    }

    public function resume(SlaPause $pause, User $actor, ?string $notes = null): SlaPause
    {
        return DB::transaction(function () use ($pause, $actor, $notes): SlaPause {
            $locked = SlaPause::whereKey($pause->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $locked->scope_type, $locked->logistics_company_id, $locked->logistics_hub_id);

            if ($locked->status === 'ended') {
                return $locked->fresh(['company', 'hub', 'createdBy', 'endedBy']);
            }

            $endedAt = now();
            $pauseSeconds = max(0, (int) $locked->starts_at->diffInSeconds($endedAt));
            $mergedNotes = $locked->notes;
            if ($notes !== null && trim($notes) !== '') {
                $mergedNotes = trim(($mergedNotes ? $mergedNotes."\n" : '').'Resume note: '.trim($notes));
            }

            $locked->update([
                'status' => 'ended',
                'resumed_at' => $endedAt,
                'ended_by_id' => $actor->id,
                'accumulated_paused_seconds' => $pauseSeconds,
                'notes' => $mergedNotes,
            ]);

            $affectedClocks = SlaClock::query()
                ->where('active_pause_id', $locked->id)
                ->with(['delivery.order', 'order'])
                ->lockForUpdate()
                ->get();

            $clockService = app(SlaClockService::class);
            $deliveryShifts = [];

            foreach ($affectedClocks as $clock) {
                $key = "pause_resume:{$locked->id}:clock:{$clock->id}";
                if (SlaClockEvent::where('idempotency_key', $key)->exists()) {
                    continue;
                }

                $pausedStart = $clock->paused_at ?? $locked->starts_at;
                $delta = max(0, (int) $pausedStart->diffInSeconds($endedAt));

                $otherPauseReason = $clockService->nonForceMajeurePauseReason($clock);
                $clock->paused_seconds = (int) $clock->paused_seconds + $delta;
                $clock->warning_at = $clock->warning_at?->copy()->addSeconds($delta);
                $clock->breach_at = $clock->breach_at?->copy()->addSeconds($delta);
                $clock->critical_at = $clock->critical_at?->copy()->addSeconds($delta);
                $clock->active_pause_id = null;

                if ($otherPauseReason !== null) {
                    $clock->status = 'paused';
                    $clock->pause_reason = $otherPauseReason;
                    $clock->paused_at = $endedAt;
                } else {
                    $clock->status = 'running';
                    $clock->pause_reason = null;
                    $clock->paused_at = null;
                }

                $clock->save();

                SlaClockEvent::create([
                    'sla_clock_id' => $clock->id,
                    'event_type' => 'resumed',
                    'idempotency_key' => $key,
                    'actor_id' => $actor->id,
                    'payload' => [
                        'pause_id' => $locked->id,
                        'pause_reference' => $locked->reference,
                        'added_seconds' => $delta,
                        'next_status' => $clock->status,
                        'next_pause_reason' => $clock->pause_reason,
                    ],
                ]);

                if ($clock->delivery_id) {
                    $deliveryShifts[$clock->delivery_id] = max($deliveryShifts[$clock->delivery_id] ?? 0, $delta);
                }
            }

            if ($deliveryShifts !== []) {
                Delivery::query()
                    ->whereIn('id', array_keys($deliveryShifts))
                    ->with('order')
                    ->lockForUpdate()
                    ->get()
                    ->each(function (Delivery $delivery) use ($deliveryShifts): void {
                        $this->etaService->applyToDelivery(
                            $delivery,
                            $delivery->order,
                            shiftSeconds: (int) ($deliveryShifts[$delivery->id] ?? 0),
                        );
                    });
            }

            return $locked->fresh(['company', 'hub', 'createdBy', 'endedBy']);
        });
    }

    /**
     * @param  array<int, int|null>  $hubIds
     * @return Collection<int, SlaPause>
     */
    public function matchingActive(?int $companyId, array $hubIds = [], ?CarbonInterface $at = null): Collection
    {
        $point = $at ?? now();
        $cleanHubIds = array_values(array_unique(array_filter(array_map(fn ($id) => $id !== null ? (int) $id : null, $hubIds))));

        $provinces = $cleanHubIds === []
            ? []
            : LogisticsHub::query()->whereIn('id', $cleanHubIds)->pluck('province')->filter()->unique()->values()->all();

        $active = SlaPause::query()
            ->where('status', 'active')
            ->where('starts_at', '<=', $point)
            ->where(function ($q) use ($point): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $point);
            })
            ->orderByDesc('starts_at')
            ->get();

        return $active->filter(fn (SlaPause $pause) => $pause->matchesContext($companyId, $cleanHubIds, $provinces))->values();
    }

    public function authorize(User $actor, string $scopeType, ?int $companyId, ?int $hubId): void
    {
        if (! $actor->canAccessPortal()) {
            throw new DomainException('Only active portal operators may manage Force-Majeure SLA pauses.');
        }

        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->isLogistics() && in_array($scopeType, ['company', 'hub'], true) && $companyId !== null) {
            if ($this->eligibility->isCompanyManager($actor, $companyId)) {
                return;
            }
        }

        throw new DomainException('Only Platform Admins (platform/province/company/hub) or the owning Logistics Company Manager (company/hub) may manage this pause.');
    }

    /**
     * @param  Collection<int, string|null>  $hubProvinces
     */
    public function clockMatchesPause(SlaClock $clock, SlaPause $pause, Collection $hubProvinces): bool
    {
        $hubIds = array_values(array_filter([
            $clock->hub_id,
            $clock->delivery?->origin_bayan_hub_id,
            $clock->delivery?->destination_bayan_hub_id,
            $clock->delivery?->current_hub_id,
        ]));

        $provinces = [];
        foreach ($hubIds as $hubId) {
            if (! empty($hubProvinces[$hubId])) {
                $provinces[] = (string) $hubProvinces[$hubId];
            }
        }

        return $pause->matchesContext($clock->logistics_company_id, $hubIds, $provinces);
    }
}
