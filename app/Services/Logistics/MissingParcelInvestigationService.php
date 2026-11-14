<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\LogisticsManifestEvent;
use App\Models\MissingParcelInvestigation;
use App\Models\MissingParcelInvestigationEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\SlaClock;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class MissingParcelInvestigationService
{
    public function __construct(
        private LogisticsEligibilityService $eligibility,
        private NotificationDeliveryService $notifications,
    ) {}

    public function openFromClock(SlaClock $clock, string $sourceKind = 'scan_timeout'): ?MissingParcelInvestigation
    {
        if (! $clock->delivery_id || ! Schema::hasTable('missing_parcel_investigations')) {
            return null;
        }

        return DB::transaction(function () use ($clock, $sourceKind): ?MissingParcelInvestigation {
            $delivery = Delivery::with('order')->whereKey($clock->delivery_id)->lockForUpdate()->first();
            if (! $delivery || ! $delivery->order || in_array($delivery->order->status, ['cancelled', 'completed', 'returned'], true)
                || in_array($delivery->status, ['delivered', 'customer_collected'], true)) {
                return null;
            }

            $existing = MissingParcelInvestigation::query()
                ->where('delivery_id', $delivery->id)
                ->where('status', 'investigating')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $summary = match ($sourceKind) {
                'hub_stall' => "Parcel {$delivery->tracking_number} remained idle past 48h at hub sorting ({$clock->clock_kind}). Physical custody verification requested without auto-declaring loss.",
                'pickup_transit_gap' => "Parcel {$delivery->tracking_number} was picked up but lacked an origin hub intake scan within 24h.",
                default => "Parcel {$delivery->tracking_number} exceeded {$clock->clock_kind} SLA threshold without a confirming arrival scan. Timeout opens investigation only; loss is not yet proven.",
            };

            $investigation = MissingParcelInvestigation::create([
                'delivery_id' => $delivery->id,
                'order_id' => $delivery->order_id,
                'logistics_company_id' => $delivery->logistics_company_id,
                'hub_id' => $clock->hub_id ?? $delivery->current_hub_id ?? $delivery->origin_bayan_hub_id,
                'sla_clock_id' => $clock->id,
                'source_kind' => $sourceKind,
                'status' => 'investigating',
                'proves_loss' => false,
                'liability_determined' => false,
                'compensation_status' => 'pending_review',
                'insurer_funding_status' => 'deferred_no_insurer_pool',
                'summary' => $summary,
                'opened_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $investigation->id,
                'event_type' => 'opened',
                'notes' => $summary,
                'payload' => ['source_kind' => $sourceKind, 'sla_clock_id' => $clock->id, 'clock_kind' => $clock->clock_kind],
            ]);

            $this->notifyInvestigationOpened($investigation);

            return $investigation;
        });
    }

    public function openFromManifestMissing(LogisticsManifestEvent $observation, ?User $actor = null): MissingParcelInvestigation
    {
        return DB::transaction(function () use ($observation, $actor): MissingParcelInvestigation {
            $parcel = $observation->parcel?->delivery ?? Delivery::with('order')->find($observation->parcel?->delivery_id);
            if (! $parcel) {
                throw new DomainException('Manifest missing discrepancy must reference a persisted delivery parcel.');
            }

            Delivery::whereKey($parcel->id)->lockForUpdate()->first();
            $existing = MissingParcelInvestigation::query()
                ->where('delivery_id', $parcel->id)
                ->where('status', 'investigating')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! $existing->logistics_manifest_event_id) {
                    $existing->update(['logistics_manifest_event_id' => $observation->id]);
                }

                return $existing;
            }

            $investigation = MissingParcelInvestigation::create([
                'delivery_id' => $parcel->id,
                'order_id' => $parcel->order_id,
                'logistics_company_id' => $parcel->logistics_company_id,
                'hub_id' => $observation->hub_id,
                'logistics_manifest_event_id' => $observation->id,
                'source_kind' => 'manifest_missing',
                'status' => 'investigating',
                'proves_loss' => false,
                'liability_determined' => false,
                'compensation_status' => 'pending_review',
                'insurer_funding_status' => 'deferred_no_insurer_pool',
                'opened_by_id' => $actor?->id,
                'summary' => "Manifest discrepancy reported parcel {$parcel->tracking_number} missing at arrival sweep.",
                'opened_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $investigation->id,
                'event_type' => 'opened',
                'actor_id' => $actor?->id,
                'notes' => $investigation->summary,
                'payload' => ['manifest_event_id' => $observation->id],
            ]);

            $this->notifyInvestigationOpened($investigation);

            return $investigation;
        });
    }

    public function openManual(User $actor, Delivery $delivery, array $input): MissingParcelInvestigation
    {
        $data = Validator::make($input, [
            'summary' => ['required', 'string', 'min:10', 'max:1000'],
            'hub_id' => ['nullable', 'integer', 'exists:logistics_hubs,id'],
            'investigator_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();

        $this->assertOperator($actor, $delivery->logistics_company_id);

        return DB::transaction(function () use ($actor, $delivery, $data): MissingParcelInvestigation {
            $locked = Delivery::with('order')->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $existing = MissingParcelInvestigation::query()
                ->where('delivery_id', $locked->id)
                ->where('status', 'investigating')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new DomainException("An active investigation ({$existing->reference}) already tracks this parcel.");
            }

            $investigation = MissingParcelInvestigation::create([
                'delivery_id' => $locked->id,
                'order_id' => $locked->order_id,
                'logistics_company_id' => $locked->logistics_company_id,
                'hub_id' => $data['hub_id'] ?? $locked->current_hub_id ?? $locked->origin_bayan_hub_id,
                'investigator_id' => $data['investigator_id'] ?? null,
                'opened_by_id' => $actor->id,
                'source_kind' => 'manual_report',
                'status' => 'investigating',
                'proves_loss' => false,
                'liability_determined' => false,
                'compensation_status' => 'pending_review',
                'insurer_funding_status' => 'deferred_no_insurer_pool',
                'summary' => trim($data['summary']),
                'opened_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $investigation->id,
                'event_type' => 'opened',
                'actor_id' => $actor->id,
                'notes' => trim($data['summary']),
            ]);

            $this->notifyInvestigationOpened($investigation);

            return $investigation;
        });
    }

    public function assignInvestigator(MissingParcelInvestigation $investigation, User $actor, int $investigatorId, string $notes): MissingParcelInvestigation
    {
        $this->assertOperator($actor, $investigation->logistics_company_id);

        return DB::transaction(function () use ($investigation, $actor, $investigatorId, $notes): MissingParcelInvestigation {
            $locked = MissingParcelInvestigation::whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'investigating') {
                throw new DomainException('Only open investigations can be assigned.');
            }

            $investigator = User::findOrFail($investigatorId);
            $this->assertOperator($investigator, $locked->logistics_company_id);

            $locked->update(['investigator_id' => $investigator->id]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $locked->id,
                'event_type' => 'assigned',
                'actor_id' => $actor->id,
                'notes' => trim($notes) ?: "Assigned investigator {$investigator->name}.",
                'payload' => ['investigator_id' => $investigator->id],
            ]);

            $this->notifications->record(
                $investigator->id,
                'governance-event',
                "mpi-assign:{$locked->id}:u:{$investigator->id}",
                [
                    'title' => "Missing Parcel Investigation Assigned ({$locked->reference})",
                    'body' => trim($notes) ?: $locked->summary,
                    'href' => '/governance/monitoring',
                    'investigation_id' => $locked->id,
                ]
            );

            return $locked->fresh();
        });
    }

    public function addFinding(MissingParcelInvestigation $investigation, User $actor, string $notes): MissingParcelInvestigation
    {
        $this->assertOperator($actor, $investigation->logistics_company_id);

        return DB::transaction(function () use ($investigation, $actor, $notes): MissingParcelInvestigation {
            $locked = MissingParcelInvestigation::whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'investigating') {
                throw new DomainException('Closed investigations cannot record new findings.');
            }

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $locked->id,
                'event_type' => 'finding',
                'actor_id' => $actor->id,
                'notes' => trim($notes),
            ]);

            return $locked->fresh();
        });
    }

    public function resolveRecovered(MissingParcelInvestigation $investigation, ?User $actor, string $notes): MissingParcelInvestigation
    {
        if ($actor) {
            $this->assertOperator($actor, $investigation->logistics_company_id);
        }

        return DB::transaction(function () use ($investigation, $actor, $notes): MissingParcelInvestigation {
            $locked = MissingParcelInvestigation::whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'investigating') {
                return $locked;
            }

            $locked->update([
                'status' => 'recovered',
                'proves_loss' => false,
                'liability_determined' => false,
                'compensation_status' => 'not_applicable',
                'resolved_by_id' => $actor?->id,
                'resolution_notes' => trim($notes),
                'resolved_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $locked->id,
                'event_type' => 'recovered',
                'actor_id' => $actor?->id,
                'notes' => trim($notes),
            ]);

            return $locked->fresh();
        });
    }

    public function confirmLoss(MissingParcelInvestigation $investigation, User $admin, string $notes, ?int $financialPlanId = null): MissingParcelInvestigation
    {
        if (! $admin->isAdmin() || ! $admin->canAccessPortal()) {
            throw new DomainException('Only an authorized Platform Admin may confirm permanent parcel loss.');
        }

        return DB::transaction(function () use ($investigation, $admin, $notes, $financialPlanId): MissingParcelInvestigation {
            $locked = MissingParcelInvestigation::whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'investigating') {
                throw new DomainException('Only an active investigation can be confirmed lost.');
            }

            $linkedPlan = $financialPlanId
                ? ShipmentFinancialPlan::findOrFail($financialPlanId)
                : ShipmentFinancialPlan::query()
                    ->where('kind', 'transit_loss')
                    ->when($locked->order_id, fn ($q) => $q->where('order_id', $locked->order_id))
                    ->latest('id')
                    ->first();

            $compensationStatus = $linkedPlan
                ? 'linked_financial_plan'
                : ($locked->logistics_manifest_event_id ? 'pending_review' : 'deferred_legacy_manual');

            $locked->update([
                'status' => 'confirmed_lost',
                'proves_loss' => true,
                'liability_determined' => true,
                'compensation_status' => $compensationStatus,
                'insurer_funding_status' => 'deferred_no_insurer_pool',
                'shipment_financial_plan_id' => $linkedPlan?->id,
                'resolved_by_id' => $admin->id,
                'resolution_notes' => trim($notes),
                'resolved_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $locked->id,
                'event_type' => 'confirmed_lost',
                'actor_id' => $admin->id,
                'notes' => trim($notes),
                'payload' => [
                    'proves_loss' => true,
                    'liability_determined' => true,
                    'compensation_status' => $compensationStatus,
                    'insurer_funding_status' => 'deferred_no_insurer_pool',
                    'shipment_financial_plan_id' => $linkedPlan?->id,
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function closeUnfounded(MissingParcelInvestigation $investigation, User $actor, string $notes): MissingParcelInvestigation
    {
        $this->assertOperator($actor, $investigation->logistics_company_id);

        return DB::transaction(function () use ($investigation, $actor, $notes): MissingParcelInvestigation {
            $locked = MissingParcelInvestigation::whereKey($investigation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'investigating') {
                throw new DomainException('Only an open investigation may be closed.');
            }

            $locked->update([
                'status' => 'closed_unfounded',
                'proves_loss' => false,
                'liability_determined' => false,
                'compensation_status' => 'not_applicable',
                'resolved_by_id' => $actor->id,
                'resolution_notes' => trim($notes),
                'resolved_at' => now(),
            ]);

            MissingParcelInvestigationEvent::create([
                'missing_parcel_investigation_id' => $locked->id,
                'event_type' => 'closed_unfounded',
                'actor_id' => $actor->id,
                'notes' => trim($notes),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Automatically closes open scan-timeout investigations when a legitimate physical hub scan confirms recovery.
     */
    public function reconcilePhysicalScan(Delivery $delivery, ?User $actor = null, ?string $checkpointType = null): void
    {
        if (! Schema::hasTable('missing_parcel_investigations')) {
            return;
        }

        $open = MissingParcelInvestigation::query()
            ->where('delivery_id', $delivery->id)
            ->where('status', 'investigating')
            ->whereIn('source_kind', ['scan_timeout', 'hub_stall', 'pickup_transit_gap', 'manifest_missing'])
            ->get();

        foreach ($open as $investigation) {
            $this->resolveRecovered(
                $investigation,
                $actor,
                'Recovered by verified physical scan'.($checkpointType ? " ({$checkpointType})" : '').'.',
            );
        }
    }

    private function assertOperator(User $actor, ?int $companyId): void
    {
        if (! $actor->canAccessPortal()) {
            throw new DomainException('Portal access is required for investigation actions.');
        }

        if ($actor->isAdmin()) {
            return;
        }

        if ($actor->isLogistics() && $companyId !== null && ($this->eligibility->isCompanyManager($actor, $companyId) || (int) $actor->logistics_company_id === (int) $companyId)) {
            return;
        }

        throw new DomainException('Only Platform Admins or authorized Logistics Company operators for this carrier may manage this investigation.');
    }

    private function notifyInvestigationOpened(MissingParcelInvestigation $investigation): void
    {
        $adminIds = User::query()
            ->where('status', 'active')
            ->whereNull('closed_at')
            ->where('role', 'admin')
            ->limit(10)
            ->pluck('id');

        $companyIds = $investigation->logistics_company_id
            ? $this->eligibility->companyOperatorIds((int) $investigation->logistics_company_id)->take(15)
            : collect();

        $recipients = $adminIds->merge($companyIds)->unique()->values();

        foreach ($recipients as $recipientId) {
            $this->notifications->record(
                (int) $recipientId,
                'governance-event',
                "mpi-open:{$investigation->id}:u:{$recipientId}",
                [
                    'title' => "Investigation Opened ({$investigation->reference})",
                    'body' => $investigation->summary,
                    'href' => '/governance/monitoring',
                    'investigation_id' => $investigation->id,
                    'delivery_id' => $investigation->delivery_id,
                ]
            );
        }
    }
}
