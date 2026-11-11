<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\LogisticsManifestParcel;
use App\Models\MotherBag;
use App\Models\MotherBagParcel;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContinuousScanAutomationService
{
    public function __construct(
        private readonly LogisticsEligibilityService $eligibility,
        private readonly LogisticsManifestService $manifestService,
        private readonly OrderStateMachineService $stateMachine,
        private readonly LogisticsRoutingEngine $routingEngine,
        private readonly PickupClaimService $pickupClaimService,
    ) {}

    /**
     * Determine whether an incoming waybill can be automatically progressed in continuous scan mode.
     */
    public function canAutomate(Delivery $delivery, LogisticsHub $hub, User $actor): bool
    {
        // Online scans require one explicit action and its current source evidence.
        // Batch progression must first satisfy the B17 evidence and replay contract.
        return false;
    }

    /**
     * Execute full atomic progression for a parcel in continuous scanning mode.
     * Advances the parcel across manifest receipt, bag opening, sorting, binning, and rider assignment.
     */
    public function executeAutomatedProgression(
        Delivery $delivery,
        LogisticsHub $hub,
        User $actor,
        array $options = []
    ): array {
        if (! $this->canAutomate($delivery, $hub, $actor)) {
            throw new DomainException('This parcel is not eligible for continuous automated progression.');
        }

        return DB::transaction(function () use ($delivery, $hub, $actor) {
            // Lock the order and delivery records to prevent race conditions
            Order::whereKey($delivery->order_id)->lockForUpdate()->first();
            $delivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $steps = [];
            $messages = [];

            // -------------------------------------------------------------
            // STEP 1: Inbound Manifest & Mother Bag Auto-Receipt
            // -------------------------------------------------------------
            $arrivingManifest = $this->findArrivingManifest($delivery, $hub);
            if ($arrivingManifest) {
                // Auto-open sealed Mother Bag if the parcel is nested inside one
                $bagMember = MotherBagParcel::where('active_delivery_id', $delivery->id)->with('bag')->first();
                if ($bagMember?->bag && $bagMember->bag->manifest_id === $arrivingManifest->id && $bagMember->bag->status !== 'opened') {
                    $bag = MotherBag::whereKey($bagMember->bag->id)->lockForUpdate()->firstOrFail();
                    $bag->fill(['status' => 'opened', 'opened_by_id' => $actor->id, 'opened_at' => now()]);
                    $bag->version++;
                    $bag->save();

                    DB::table('mother_bag_events')->insert([
                        'mother_bag_id' => $bag->id,
                        'actor_id' => $actor->id,
                        'event_type' => 'open',
                        'barcode_scanned' => $bag->reference,
                        'payload' => json_encode(['seal_intact' => true, 'auto_opened_by_waybill' => $delivery->tracking_number], JSON_THROW_ON_ERROR),
                    ]);
                    $steps[] = 'bag_unsealed';
                }

                // Record parcel receipt on arriving manifest
                $manifestMember = $arrivingManifest->parcels()->where('delivery_id', $delivery->id)->where('included', true)->first();
                if ($manifestMember && ! $manifestMember->received_at) {
                    $this->manifestService->command(
                        $arrivingManifest->fresh(),
                        $actor,
                        'receive',
                        [
                            'version' => $arrivingManifest->fresh()->version,
                            'request_token' => (string) Str::uuid(),
                            'barcode' => $delivery->tracking_number,
                            'condition' => 'intact',
                            'notes' => 'Continuous scan: auto-received arriving parcel.',
                        ]
                    );
                    $steps[] = 'manifest_received';
                    $messages[] = "Received on Inbound Transfer #{$arrivingManifest->reference}";
                }

                // Check if all parcels on the manifest are received; if so, auto-close manifest
                $freshManifest = $arrivingManifest->fresh();
                if ($freshManifest && ! $freshManifest->parcels()->where('included', true)->whereNull('received_at')->exists()) {
                    $this->manifestService->command(
                        $freshManifest,
                        $actor,
                        'close',
                        [
                            'version' => $freshManifest->version,
                            'request_token' => (string) Str::uuid(),
                            'notes' => 'Continuous scan: all included parcels received. Manifest auto-closed.',
                        ]
                    );
                    $steps[] = 'manifest_closed';
                    $messages[] = 'Manifest auto-closed (all parcels received)';
                }

                $delivery = $delivery->fresh();
            }

            // -------------------------------------------------------------
            // STEP 2: Origin Bayan Hub - Physical Pickup Intake
            // -------------------------------------------------------------
            if ($hub->id === $delivery->origin_bayan_hub_id && in_array($delivery->status, ['assigned', 'assigned_pickup', 'picked_up'], true)) {
                $delivery = $this->stateMachine->transition(
                    delivery: $delivery,
                    targetStatus: OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                    actor: $actor,
                    scanMetadata: [
                        'hub_id' => $hub->id,
                        'barcode' => $delivery->tracking_number,
                        'location_name' => "{$hub->name} ({$hub->code}) Intake",
                        'facility_code' => $hub->code,
                        'scan_action' => 'RECEIVE_FROM_PICKUP_RIDER',
                        'expected_status' => $delivery->status,
                        'notes' => 'Continuous scan: auto-received from pickup rider.',
                    ]
                );
                $steps[] = 'received_at_origin_hub';
                $messages[] = 'Received into origin Bayan Hub';
            }

            // -------------------------------------------------------------
            // STEP 3: Regional Mother Hub - Auto-Sort to Line-Haul
            // -------------------------------------------------------------
            if ($hub->isMotherHub() && $delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB) {
                $delivery = $this->stateMachine->transition(
                    delivery: $delivery,
                    targetStatus: OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    actor: $actor,
                    scanMetadata: [
                        'hub_id' => $hub->id,
                        'barcode' => $delivery->tracking_number,
                        'location_name' => "{$hub->name} ({$hub->code}) Line-Haul Staging",
                        'facility_code' => $hub->code,
                        'scan_action' => 'SORT_TO_LINE_HAUL',
                        'expected_status' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                        'notes' => 'Continuous scan: auto-sorted to line-haul corridor.',
                    ]
                );
                $steps[] = 'sorted_to_line_haul';
                $messages[] = 'Auto-sorted to line-haul corridor staging';
            }

            // -------------------------------------------------------------
            // STEP 4: Destination Bayan Hub - Auto-Binning & Auto-Rider Assignment
            // -------------------------------------------------------------
            if ($hub->id === $delivery->destination_bayan_hub_id) {
                // Sub-case A: Customer Self-Pickup
                if ($delivery->delivery_type === 'hub_self_pickup' && $delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB) {
                    $this->pickupClaimService->stage(
                        $delivery,
                        $actor,
                        $hub,
                        $delivery->tracking_number,
                        $delivery->status
                    );
                    $delivery = $delivery->fresh();
                    $steps[] = 'staged_for_counter_pickup';
                    $messages[] = 'Staged at counter for buyer self-pickup';
                }

                // Sub-case B: Doorstep Delivery
                if ($delivery->delivery_type === 'doorstep') {
                    // Auto-sort to Barangay Bin
                    if ($delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB) {
                        $rawBarangay = $delivery->destination_barangay ?: ($delivery->order?->destination_barangay ?: 'General');
                        $bin = $delivery->destination_bin ?: ('BIN: BRGY-'.strtoupper(str_replace(' ', '-', Str::ascii($rawBarangay))));

                        $delivery = $this->stateMachine->transition(
                            delivery: $delivery,
                            targetStatus: OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                            actor: $actor,
                            scanMetadata: [
                                'hub_id' => $hub->id,
                                'destination_bin' => $bin,
                                'location_name' => "Hub Sorting Bay ({$rawBarangay} / {$bin})",
                                'facility_code' => $hub->code,
                                'scan_action' => 'SORT_TO_BARANGAY_BIN',
                                'expected_status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                                'notes' => "Continuous scan: auto-sorted to bin {$bin} for {$rawBarangay}",
                            ]
                        );
                        $steps[] = 'sorted_to_barangay_bin';
                        $messages[] = "Sorted to {$bin}";
                    }

                    // Auto-assign eligible final-mile rider
                    if ($delivery->status === OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN && ! $delivery->assigned_rider_id) {
                        $assignedRider = $this->autoAssignRider($delivery, $hub, $actor);
                        if ($assignedRider) {
                            $delivery = $delivery->fresh();
                            $steps[] = 'rider_assigned';
                            $messages[] = "Auto-assigned to Rider: {$assignedRider->name}";
                        } else {
                            $messages[] = 'Queued in bin (awaiting available on-duty rider)';
                        }
                    }
                }
            }

            // Build dynamic prompt for the resulting state
            $delivery = $delivery->fresh();
            $nextPrompt = $this->routingEngine->getDynamicScanPrompt($delivery, $hub);

            if (empty($messages)) {
                $statusLabel = match ($delivery->status) {
                    OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER => "Already assigned to Rider: {$delivery->assignedRider?->name} ({$delivery->destination_bin})",
                    OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN => "Sorted to {$delivery->destination_bin} (awaiting available rider)",
                    OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP => 'Already staged at counter for buyer self-pickup',
                    OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL => 'Already staged in line-haul corridor',
                    OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB => 'Already received at origin Hub (ready for linehaul staging)',
                    default => 'Status: '.str_replace('_', ' ', $delivery->status),
                };
                $summaryMessage = "Parcel #{$delivery->tracking_number}: {$statusLabel}.";
            } else {
                $summaryMessage = "Parcel #{$delivery->tracking_number}: ".implode(' ➔ ', $messages).'.';
            }

            return [
                'success' => true,
                'confirmed' => true,
                'automated_progression' => true,
                'steps' => $steps,
                'message' => $summaryMessage,
                'delivery' => $delivery,
                'prompt' => $nextPrompt,
            ];
        });
    }

    /**
     * Locate an active incoming manifest transporting this parcel to the current hub.
     */
    public function findArrivingManifest(Delivery $delivery, LogisticsHub $hub): ?LogisticsManifest
    {
        $member = LogisticsManifestParcel::where('active_delivery_id', $delivery->id)
            ->whereNull('received_at')
            ->where('included', true)
            ->with('manifest')
            ->first();

        $manifest = $member?->manifest;
        if (! $manifest || $manifest->destination_hub_id !== $hub->id || $manifest->status !== 'dispatched') {
            return null;
        }

        return $manifest;
    }

    /**
     * Automatically match and assign the best eligible final-mile rider for a sorted parcel.
     */
    public function autoAssignRider(Delivery $delivery, LogisticsHub $hub, User $actor): ?User
    {
        if ($delivery->delivery_type !== 'doorstep') {
            return null;
        }

        // Query active, operational, on-duty riders assigned to this Bayan Hub
        $candidateProfiles = $this->eligibility->riderCandidates($hub)->get();
        if ($candidateProfiles->isEmpty()) {
            return null;
        }

        $destBarangay = strtolower(trim((string) ($delivery->destination_barangay ?: $delivery->order?->destination_barangay)));

        // 1. Prioritize a rider specifically assigned to this barangay
        $chosenProfile = $candidateProfiles->first(function (CourierProfile $c) use ($destBarangay, $delivery) {
            $assigned = strtolower(trim((string) $c->assigned_barangay));
            if ($assigned === '' || $assigned !== $destBarangay) {
                return false;
            }

            return ! Delivery::riderHasActiveWork($c->user_id, $delivery->id);
        });

        // 2. Fallback to any available general/unassigned rider with lowest workload
        if (! $chosenProfile) {
            $chosenProfile = $candidateProfiles
                ->filter(function (CourierProfile $c) use ($destBarangay, $delivery) {
                    $assigned = strtolower(trim((string) $c->assigned_barangay));
                    if ($assigned !== '' && $destBarangay !== '' && $assigned !== $destBarangay) {
                        return false;
                    }

                    return ! Delivery::riderHasActiveWork($c->user_id, $delivery->id);
                })
                ->sortBy(function (CourierProfile $c) {
                    return Delivery::where('assigned_rider_id', $c->user_id)
                        ->whereIn('status', [
                            OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                            OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
                        ])
                        ->count();
                })
                ->first();
        }

        if (! $chosenProfile) {
            return null;
        }

        $riderUser = $chosenProfile->user;

        try {
            $this->stateMachine->transition(
                delivery: $delivery,
                targetStatus: OrderStateMachineService::STATUS_ASSIGNED_TO_RIDER,
                actor: $actor,
                scanMetadata: [
                    'hub_id' => $hub->id,
                    'rider_id' => $riderUser->id,
                    'location_name' => "{$hub->name} ({$hub->code}) Final-Mile Staging",
                    'facility_code' => $hub->code,
                    'scan_action' => 'ASSIGN_RIDER',
                    'expected_status' => OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                    'notes' => "Continuous scan: auto-assigned to final-mile rider {$riderUser->name}",
                ]
            );

            return $riderUser;
        } catch (\Throwable) {
            return null;
        }
    }
}
