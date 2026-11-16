<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\ScanBatchItem;
use App\Models\ScanBatchRequest;
use App\Models\ScanRequestLedger;
use App\Models\User;
use App\Services\Finance\ShipmentLossCustody;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class HubBatchScanService
{
    public const MAX_BATCH_SIZE = 100;

    public const NON_CONFIRMABLE_ACTIONS = [
        'AWAIT_BARANGAY_SORT',
        'AWAIT_RETRY_REVIEW',
        'AWAIT_BUYER_PICKUP',
        'AWAIT_SELLER_RECEIPT',
        'AWAIT_RETURN_REVIEW',
        'INSPECT_WAYBILL',
        'AWAIT_HANDOVER_RECOVERY',
    ];

    public const MANIFEST_ONLY_ACTIONS = [
        'DISPATCH_TO_FEEDER',
        'DISPATCH_LINE_HAUL',
        'RECEIVE_AT_MOTHER_HUB',
        'RECEIVE_AT_DESTINATION_HUB',
        'DISPATCH_RETURN_MANIFEST',
        'RECEIVE_RETURN_MANIFEST',
        'RECEIVE_NEXT_MOTHER_HUB',
        'RECEIVE_NEXT_BAYAN_HUB',
    ];

    public function __construct(
        private readonly LogisticsEligibilityService $eligibility,
        private readonly LogisticsRoutingEngine $routing,
        private readonly HubParcelWorkstationService $workstation,
        private readonly OrderStateMachineService $stateMachine,
        private readonly PickupClaimService $pickupClaims,
        private readonly DeliveryReturnService $returns,
        private readonly DeliveryRecoveryService $recovery,
        private readonly WaybillScanInputService $inputs,
    ) {}

    public static function targetStatusForAction(string $action): ?string
    {
        return match ($action) {
            'RECEIVE_NEXT_MOTHER_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
            'RECEIVE_NEXT_BAYAN_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'RECEIVE_FROM_PICKUP_RIDER' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
            'DISPATCH_TO_FEEDER' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
            'RECEIVE_AT_MOTHER_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
            'SORT_TO_LINE_HAUL' => OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
            'DISPATCH_LINE_HAUL' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
            'RECEIVE_AT_DESTINATION_HUB' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'STAGE_FOR_PICKUP' => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
            'RECEIVE_FAILED_DELIVERY' => OrderStateMachineService::STATUS_DELIVERY_FAILED,
            'RELEASE_APPROVED_RETRY' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
            'STAGE_SELLER_RETURN' => OrderStateMachineService::STATUS_RETURN_TO_SENDER,
            'START_EXPIRED_PICKUP_RETURN' => OrderStateMachineService::STATUS_RETURN_TO_SENDER,
            default => null,
        };
    }

    /**
     * Process an explicit 1-scan direct confirmation or offline/burst batch queue
     * with per-item transaction isolation, canonical lock ordering, and idempotent replay.
     */
    public function processBatch(
        User $actor,
        LogisticsHub $hub,
        array $validated,
        string $sourceChannel = 'web',
    ): array {
        abort_unless($actor->isLogistics() && $this->eligibility->canScan($actor, $hub), 403, 'An active handler assignment is required to process batch scans at this facility.');
        abort_unless($this->eligibility->canIntake($actor, $hub), 403, 'Your current hub job role is Pack & Dispatch only. Ask a manager to switch your role to Entry / Intake.');

        $batchStart = hrtime(true);
        $directMode = (bool) ($validated['direct_mode'] ?? false);
        $pinnedAction = isset($validated['pinned_action']) && is_string($validated['pinned_action'])
            ? strtoupper(trim($validated['pinned_action']))
            : null;
        if ($pinnedAction === '' || $pinnedAction === 'ANY_PERMITTED_PARCEL_STEP') {
            $pinnedAction = null;
        }
        $batchNotes = $validated['notes'] ?? null;
        $rawItems = array_values($validated['items'] ?? []);

        $batch = ScanBatchRequest::create([
            'batch_reference' => (string) Str::uuid(),
            'logistics_company_id' => $hub->logistics_company_id,
            'hub_id' => $hub->id,
            'actor_id' => $actor->id,
            'source_channel' => $sourceChannel,
            'direct_mode' => $directMode,
            'pinned_action' => $pinnedAction,
            'submitted_count' => count($rawItems),
            'created_at' => now(),
        ]);

        $confirmedCount = 0;
        $duplicateCount = 0;
        $rejectedCount = 0;
        $results = [];

        foreach ($rawItems as $index => $rawItem) {
            $result = $this->processSingleItem($batch, $index, $actor, $hub, $rawItem, $pinnedAction, $batchNotes);
            $results[] = $result;
            match ($result['outcome']) {
                'confirmed' => $confirmedCount++,
                'duplicate_replay' => $duplicateCount++,
                default => $rejectedCount++,
            };
        }

        $elapsedMs = max(1, (int) round((hrtime(true) - $batchStart) / 1_000_000));
        $scansPerSec = round(count($rawItems) / ($elapsedMs / 1000), 2);

        $batch->update([
            'confirmed_count' => $confirmedCount,
            'duplicate_count' => $duplicateCount,
            'rejected_count' => $rejectedCount,
            'elapsed_ms' => $elapsedMs,
            'finalized_at' => now(),
        ]);

        return [
            'success' => true,
            'batch_reference' => $batch->batch_reference,
            'hub' => $hub->only(['id', 'name', 'code', 'tier']),
            'direct_mode' => $directMode,
            'pinned_action' => $pinnedAction,
            'summary' => [
                'submitted' => count($rawItems),
                'confirmed' => $confirmedCount,
                'duplicate_replay' => $duplicateCount,
                'rejected' => $rejectedCount,
                'elapsed_ms' => $elapsedMs,
                'scans_per_sec' => $scansPerSec,
            ],
            'items' => $results,
        ];
    }

    private function processSingleItem(
        ScanBatchRequest $batch,
        int $index,
        User $actor,
        LogisticsHub $hub,
        array $rawItem,
        ?string $batchPinnedAction,
        ?string $batchNotes,
    ): array {
        $itemStart = hrtime(true);
        $normalized = $this->inputs->normalize([
            'barcode' => $rawItem['barcode'] ?? '',
            'action' => $rawItem['expected_action'] ?? null,
            'notes' => $rawItem['notes'] ?? $batchNotes,
        ], allowTrackingUrl: true);

        $barcode = trim((string) ($normalized['barcode'] ?? ''));
        $token = trim((string) ($rawItem['request_token'] ?? ''));
        $locationBarcode = filled($rawItem['location_barcode'] ?? null) ? trim((string) $rawItem['location_barcode']) : null;
        $expectedAction = filled($normalized['action'] ?? null) ? strtoupper(trim((string) $normalized['action'])) : $batchPinnedAction;
        if ($expectedAction === 'ANY_PERMITTED_PARCEL_STEP') {
            $expectedAction = null;
        }
        $notes = $normalized['notes'] ?? null;
        $clientScannedAt = isset($rawItem['client_scanned_at']) && $rawItem['client_scanned_at']
            ? Carbon::parse((string) $rawItem['client_scanned_at'])
            : null;

        $fingerprint = hash('sha256', json_encode([
            $hub->id,
            $barcode,
            $expectedAction,
            $locationBarcode,
            $notes,
        ], JSON_THROW_ON_ERROR));

        $existingLedger = ScanRequestLedger::query()
            ->where('actor_id', $actor->id)
            ->where('request_token', $token)
            ->first();

        if ($existingLedger) {
            return $this->recordLedgerCollision(
                batch: $batch,
                index: $index,
                ledger: $existingLedger,
                fingerprint: $fingerprint,
                barcode: $barcode,
                expectedAction: $expectedAction,
                clientScannedAt: $clientScannedAt,
                itemStart: $itemStart,
            );
        }

        if ($barcode === '' || ! preg_match('/\A[A-Z0-9][A-Z0-9_-]*\z/', $barcode)) {
            return $this->recordRejected($batch, $index, $token, $barcode, null, $expectedAction, 'Scan a valid printed tracking code.', $clientScannedAt, $itemStart);
        }

        if (str_starts_with($barcode, 'BAG-') || str_starts_with($barcode, 'RMB-')) {
            return $this->recordRejected($batch, $index, $token, $barcode, null, $expectedAction, 'Mother Bags require seal verification in Mother Bags and per-parcel receiving in Manifests.', $clientScannedAt, $itemStart);
        }

        if (str_starts_with($barcode, 'BRT-')) {
            return $this->recordRejected($batch, $index, $token, $barcode, null, $expectedAction, 'Buyer return barcodes require individual case verification rather than batch confirmation.', $clientScannedAt, $itemStart);
        }

        $lookup = $this->findUniqueDelivery($barcode);
        if (! $lookup['delivery']) {
            return $this->recordRejected($batch, $index, $token, $barcode, null, $expectedAction, $lookup['error'], $clientScannedAt, $itemStart);
        }

        $candidate = $lookup['delivery'];
        if ($hub->logistics_company_id !== $candidate->logistics_company_id) {
            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, 'This parcel is outside the active facility company.', $clientScannedAt, $itemStart);
        }

        if ($loss = app(ShipmentLossCustody::class)->authority($candidate)) {
            $salvaged = app(ShipmentLossCustody::class)->releasedReconciliation($candidate)?->outcome_path === 'already_settled_salvage';
            $message = $salvaged ? ShipmentLossCustody::SALVAGE_HOLD : ShipmentLossCustody::HOLD;

            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, $message, $clientScannedAt, $itemStart);
        }

        if ($misroute = app(MisrouteTrapService::class)->inspect($candidate, $hub, $actor)) {
            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, $misroute, $clientScannedAt, $itemStart);
        }

        try {
            $confirmed = DB::transaction(function () use (
                $actor,
                $hub,
                $candidate,
                $token,
                $fingerprint,
                $barcode,
                $locationBarcode,
                $expectedAction,
                $notes,
            ) {
                $ledgerInTx = ScanRequestLedger::query()
                    ->where('actor_id', $actor->id)
                    ->where('request_token', $token)
                    ->first();

                if ($ledgerInTx) {
                    return ['ledger_hit' => $ledgerInTx];
                }

                Order::query()->whereKey($candidate->order_id)->lockForUpdate()->first();
                $delivery = Delivery::query()
                    ->with([
                        'order',
                        'originBayanHub',
                        'originMotherHub',
                        'destinationBayanHub',
                        'destinationMotherHub',
                        'currentHub',
                        'courier',
                        'assignedRider.courierProfile',
                        'latestCustodyCheckpoint',
                        'latestOriginalCheckpoint',
                        'pickupClaim',
                    ])
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->eligibility->lockNetwork($hub->logistics_company_id, [$actor->id], [$hub->id]);

                if ($locationBarcode !== null) {
                    $this->assertLocationMatches($delivery, $hub, $locationBarcode);
                }

                if (app(HandoverHoldService::class)->held($delivery)) {
                    throw new DomainException('Complete supervised handover recovery before an ordinary scan.');
                }

                $prompt = $this->routing->getDynamicScanPrompt($delivery, $hub);
                $action = (string) ($prompt['action'] ?? 'INSPECT_WAYBILL');
                $expectedStatus = (string) ($prompt['expected_status'] ?? $delivery->status);

                if (in_array($action, self::NON_CONFIRMABLE_ACTIONS, true)) {
                    throw new DomainException($prompt['prompt'] ?? 'This parcel cannot be confirmed through fast batch scanning.');
                }

                if (in_array($action, self::MANIFEST_ONLY_ACTIONS, true)) {
                    throw new DomainException('Inter-hub bag sealing and manifest dispatch/receipt must be confirmed in Manifests or Mother Bags.');
                }

                if ($expectedAction !== null && $action !== $expectedAction) {
                    throw new DomainException("Parcel requires {$action}, not {$expectedAction}.");
                }

                $targetStatus = self::targetStatusForAction($action);
                if (! $targetStatus) {
                    throw new DomainException('The current parcel stage is not a recognized scan confirmation.');
                }

                $guidance = $this->workstation->guidance($delivery, $hub, $actor, collect(), '/hub');
                if (! ($guidance['can_act'] ?? false) || ($guidance['action'] ?? null) !== 'scan') {
                    throw new DomainException($guidance['blocker'] ?? $guidance['next_step'] ?? 'Operator is not authorized for the current parcel step.');
                }

                $statusBefore = $delivery->status;
                $updated = $this->executeTransition(
                    delivery: $delivery,
                    hub: $hub,
                    actor: $actor,
                    barcode: $barcode,
                    action: $action,
                    expectedStatus: $expectedStatus,
                    targetStatus: $targetStatus,
                    prompt: $prompt,
                    notes: $notes,
                );

                $checkpointRef = DeliveryCheckpoint::query()
                    ->where('delivery_id', $updated->id)
                    ->latest('id')
                    ->value('record_reference');

                ScanRequestLedger::create([
                    'actor_id' => $actor->id,
                    'hub_id' => $hub->id,
                    'delivery_id' => $updated->id,
                    'request_token' => $token,
                    'request_fingerprint' => $fingerprint,
                    'barcode_submitted' => $barcode,
                    'action_applied' => $action,
                    'status_before' => $statusBefore,
                    'status_after' => $updated->status,
                    'checkpoint_reference' => $checkpointRef,
                    'created_at' => now(),
                ]);

                return [
                    'ledger_hit' => null,
                    'delivery' => $updated,
                    'action' => $action,
                    'status_before' => $statusBefore,
                    'status_after' => $updated->status,
                    'checkpoint_reference' => $checkpointRef,
                ];
            });

            if ($confirmed['ledger_hit'] instanceof ScanRequestLedger) {
                return $this->recordLedgerCollision(
                    batch: $batch,
                    index: $index,
                    ledger: $confirmed['ledger_hit'],
                    fingerprint: $fingerprint,
                    barcode: $barcode,
                    expectedAction: $expectedAction,
                    clientScannedAt: $clientScannedAt,
                    itemStart: $itemStart,
                );
            }

            $latencyMs = max(1, (int) round((hrtime(true) - $itemStart) / 1_000_000));
            $item = ScanBatchItem::create([
                'scan_batch_request_id' => $batch->id,
                'item_index' => $index,
                'request_token' => $token,
                'barcode_submitted' => $barcode,
                'delivery_id' => $confirmed['delivery']->id,
                'expected_action' => $expectedAction,
                'action_applied' => $confirmed['action'],
                'outcome' => 'confirmed',
                'status_before' => $confirmed['status_before'],
                'status_after' => $confirmed['status_after'],
                'checkpoint_reference' => $confirmed['checkpoint_reference'],
                'error_message' => null,
                'client_scanned_at' => $clientScannedAt,
                'server_processed_at' => now(),
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);

            return [
                'item_index' => $index,
                'request_token' => $token,
                'barcode' => $barcode,
                'tracking_number' => $confirmed['delivery']->tracking_number,
                'delivery_id' => $confirmed['delivery']->id,
                'outcome' => 'confirmed',
                'action_applied' => $item->action_applied,
                'status_before' => $item->status_before,
                'status_after' => $item->status_after,
                'checkpoint_reference' => $item->checkpoint_reference,
                'latency_ms' => $latencyMs,
                'error' => null,
            ];
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                $racedLedger = ScanRequestLedger::query()
                    ->where('actor_id', $actor->id)
                    ->where('request_token', $token)
                    ->first();

                if ($racedLedger) {
                    return $this->recordLedgerCollision(
                        batch: $batch,
                        index: $index,
                        ledger: $racedLedger,
                        fingerprint: $fingerprint,
                        barcode: $barcode,
                        expectedAction: $expectedAction,
                        clientScannedAt: $clientScannedAt,
                        itemStart: $itemStart,
                    );
                }
            }

            // Keep the real database error for diagnosis; the worker still gets a safe retry message.
            report($exception);

            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, 'Concurrent update prevented batch confirmation. Retry safely with the same token.', $clientScannedAt, $itemStart);
        } catch (ValidationException $exception) {
            $firstMessage = collect($exception->errors())->flatten()->first() ?: $exception->getMessage();

            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, (string) $firstMessage, $clientScannedAt, $itemStart);
        } catch (DomainException $exception) {
            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, $exception->getMessage(), $clientScannedAt, $itemStart);
        } catch (Throwable $exception) {
            report($exception);

            return $this->recordRejected($batch, $index, $token, $barcode, $candidate->id, $expectedAction, 'Could not process this scan item right now.', $clientScannedAt, $itemStart);
        }
    }

    /**
     * Attempt fast indexed lookup by deliveries.tracking_number while preserving cross-namespace ambiguity checks.
     */
    public function findUniqueDelivery(string $barcode): array
    {
        $eager = [
            'order.items.product',
            'order.buyer',
            'order.shop',
            'originBayanHub',
            'originMotherHub',
            'destinationBayanHub',
            'destinationMotherHub',
            'assignedRider',
        ];

        $directMatches = Delivery::query()
            ->with($eager)
            ->useWritePdo()
            ->where('tracking_number', $barcode)
            ->limit(2)
            ->get();

        if ($directMatches->count() > 1) {
            return [
                'delivery' => null,
                'error' => 'This code matches more than one parcel and needs review. Use its unique tracking barcode.',
                'ambiguous' => true,
            ];
        }

        if ($directMatches->count() === 1) {
            $direct = $directMatches->first();
            $conflictingOrderIds = Order::query()
                ->useWritePdo()
                ->where('order_number', $barcode)
                ->pluck('id');

            if ($conflictingOrderIds->isNotEmpty()) {
                $aliasConflict = Delivery::query()
                    ->useWritePdo()
                    ->whereIn('order_id', $conflictingOrderIds)
                    ->where('id', '!=', $direct->id)
                    ->exists();

                if ($aliasConflict) {
                    return [
                        'delivery' => null,
                        'error' => 'This code matches more than one parcel and needs review. Use its unique tracking barcode.',
                        'ambiguous' => true,
                    ];
                }
            }

            return ['delivery' => $direct, 'error' => null, 'ambiguous' => false];
        }

        $matches = Delivery::query()
            ->with($eager)
            ->useWritePdo()
            ->whereHas('order', fn ($query) => $query->where('order_number', $barcode))
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            return [
                'delivery' => null,
                'error' => 'Multiple parcels matched this order reference; scan the parcel tracking barcode instead.',
                'ambiguous' => true,
            ];
        }

        $matched = $matches->first();
        if (! $matched) {
            return ['delivery' => null, 'error' => "Parcel #{$barcode} not found in logistics registry.", 'ambiguous' => false];
        }

        return ['delivery' => $matched, 'error' => null, 'ambiguous' => false];
    }

    private function assertLocationMatches(Delivery $delivery, LogisticsHub $hub, string $locationBarcode): void
    {
        $nextHop = $this->routing->computeNextHop($delivery, $hub);
        $nextHopBin = $nextHop['recommended_bin'] ?? null;
        $assignedBin = $delivery->destination_bin;
        $isDestStation = ($hub->id === $delivery->destination_bayan_hub_id);
        $expectedBin = $isDestStation
            ? ($assignedBin ?: ($nextHopBin ?: 'LOC-BIN-01'))
            : ($nextHopBin ?: ($assignedBin ?: 'LOC-BIN-01'));

        $clean = static fn (?string $val): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $val));
        $targetClean = $clean($locationBarcode);

        foreach (array_filter([$expectedBin, $assignedBin, $nextHopBin]) as $candidate) {
            if (strcasecmp(trim($locationBarcode), trim($candidate)) === 0 || $targetClean === $clean($candidate)) {
                return;
            }
        }

        throw new DomainException("Mis-sort Error: Expected slotting into {$expectedBin}, but scanned {$locationBarcode}. Please place parcel in the correct bin.");
    }

    private function executeTransition(
        Delivery $delivery,
        LogisticsHub $hub,
        User $actor,
        string $barcode,
        string $action,
        string $expectedStatus,
        string $targetStatus,
        array $prompt,
        ?string $notes,
    ): Delivery {
        return match ($action) {
            'STAGE_FOR_PICKUP' => $this->pickupClaims->stage($delivery, $actor, $hub, $barcode, $expectedStatus),
            'START_EXPIRED_PICKUP_RETURN' => $this->pickupClaims->beginReturn($delivery, $actor, $hub, $barcode, $expectedStatus, (string) ($prompt['claim_reference'] ?? '')),
            'STAGE_SELLER_RETURN' => $this->returns->stage($delivery, $actor, $hub, $barcode, $expectedStatus, (string) ($prompt['route_reference'] ?? '')),
            'RECEIVE_FAILED_DELIVERY' => $this->recovery->receive($delivery, $actor, $hub, $barcode, $expectedStatus, (string) ($prompt['attempt_reference'] ?? '')),
            'RELEASE_APPROVED_RETRY' => $this->recovery->beginRetry($delivery, $actor, $hub, $barcode, $expectedStatus, (string) ($prompt['attempt_reference'] ?? '')),
            default => $this->stateMachine->transition(
                delivery: $delivery,
                targetStatus: $targetStatus,
                actor: $actor,
                scanMetadata: [
                    'hub_id' => $hub->id,
                    'barcode' => $barcode,
                    'location_name' => "{$hub->name} ({$hub->code})",
                    'facility_code' => $hub->code,
                    'scan_action' => $action,
                    'expected_status' => $expectedStatus,
                    'notes' => $notes ?? "Batch Floor Scan: {$action}",
                ],
            ),
        };
    }

    private function recordLedgerCollision(
        ScanBatchRequest $batch,
        int $index,
        ScanRequestLedger $ledger,
        string $fingerprint,
        string $barcode,
        ?string $expectedAction,
        ?Carbon $clientScannedAt,
        int $itemStart,
    ): array {
        if (! hash_equals($ledger->request_fingerprint, $fingerprint)) {
            return $this->recordRejected(
                batch: $batch,
                index: $index,
                token: $ledger->request_token,
                barcode: $barcode,
                deliveryId: $ledger->delivery_id,
                expectedAction: $expectedAction,
                errorMessage: 'Request token was already committed for a different scan payload.',
                clientScannedAt: $clientScannedAt,
                itemStart: $itemStart,
            );
        }

        $latencyMs = max(1, (int) round((hrtime(true) - $itemStart) / 1_000_000));
        $item = ScanBatchItem::create([
            'scan_batch_request_id' => $batch->id,
            'item_index' => $index,
            'request_token' => $ledger->request_token,
            'barcode_submitted' => $barcode,
            'delivery_id' => $ledger->delivery_id,
            'expected_action' => $expectedAction,
            'action_applied' => $ledger->action_applied,
            'outcome' => 'duplicate_replay',
            'status_before' => $ledger->status_before,
            'status_after' => $ledger->status_after,
            'checkpoint_reference' => $ledger->checkpoint_reference,
            'error_message' => null,
            'client_scanned_at' => $clientScannedAt,
            'server_processed_at' => now(),
            'latency_ms' => $latencyMs,
            'created_at' => now(),
        ]);

        return [
            'item_index' => $index,
            'request_token' => $ledger->request_token,
            'barcode' => $barcode,
            'tracking_number' => $ledger->delivery?->tracking_number ?? $ledger->barcode_submitted,
            'delivery_id' => $ledger->delivery_id,
            'outcome' => 'duplicate_replay',
            'action_applied' => $item->action_applied,
            'status_before' => $item->status_before,
            'status_after' => $item->status_after,
            'checkpoint_reference' => $item->checkpoint_reference,
            'latency_ms' => $latencyMs,
            'error' => null,
        ];
    }

    private function recordRejected(
        ScanBatchRequest $batch,
        int $index,
        string $token,
        string $barcode,
        ?int $deliveryId,
        ?string $expectedAction,
        string $errorMessage,
        ?Carbon $clientScannedAt,
        int $itemStart,
    ): array {
        $latencyMs = max(1, (int) round((hrtime(true) - $itemStart) / 1_000_000));
        $truncatedError = mb_substr($errorMessage, 0, 500);

        if ($token !== '' && Str::isUuid($token)) {
            ScanBatchItem::create([
                'scan_batch_request_id' => $batch->id,
                'item_index' => $index,
                'request_token' => $token,
                'barcode_submitted' => $barcode !== '' ? mb_substr($barcode, 0, 255) : 'INVALID',
                'delivery_id' => $deliveryId,
                'expected_action' => $expectedAction,
                'action_applied' => null,
                'outcome' => 'rejected',
                'status_before' => null,
                'status_after' => null,
                'checkpoint_reference' => null,
                'error_message' => $truncatedError,
                'client_scanned_at' => $clientScannedAt,
                'server_processed_at' => now(),
                'latency_ms' => $latencyMs,
                'created_at' => now(),
            ]);
        }

        return [
            'item_index' => $index,
            'request_token' => $token,
            'barcode' => $barcode,
            'tracking_number' => null,
            'delivery_id' => $deliveryId,
            'outcome' => 'rejected',
            'action_applied' => null,
            'status_before' => null,
            'status_after' => null,
            'checkpoint_reference' => null,
            'latency_ms' => $latencyMs,
            'error' => $errorMessage,
        ];
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return in_array((string) $sqlState, ['23000', '23505'], true);
    }
}
