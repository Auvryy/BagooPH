<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestEvent;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OrderStateMachineService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public const STATUS_PLACED = 'placed';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';

    public const STATUS_PICKED_UP = 'picked_up';

    public const STATUS_ARRIVED_AT_ORIGIN_HUB = 'arrived_at_origin_hub';

    public const STATUS_IN_TRANSIT_TO_MOTHER_HUB = 'in_transit_to_mother_hub';

    public const STATUS_ARRIVED_AT_MOTHER_HUB = 'arrived_at_mother_hub';

    public const STATUS_SORTED_TO_LINE_HAUL = 'sorted_to_line_haul';

    public const STATUS_IN_TRANSIT_TO_DEST_HUB = 'in_transit_to_destination_hub';

    public const STATUS_ARRIVED_AT_DEST_HUB = 'arrived_at_destination_hub';

    public const STATUS_SORTED_TO_BARANGAY_BIN = 'sorted_to_barangay_bin';

    public const STATUS_ASSIGNED_TO_RIDER = 'assigned_to_rider';

    public const STATUS_READY_FOR_HUB_PICKUP = 'ready_for_hub_pickup';

    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_CUSTOMER_COLLECTED = 'customer_collected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DELIVERY_FAILED = 'delivery_failed';

    public const STATUS_RETURN_TO_SENDER = 'return_to_sender';

    public const STATUS_RETURN_IN_TRANSIT = 'return_in_transit';

    private const ALLOWED_FROM = [
        self::STATUS_RETURN_IN_TRANSIT => [self::STATUS_RETURN_TO_SENDER],
        self::STATUS_RETURN_TO_SENDER => [self::STATUS_RETURN_IN_TRANSIT],
        self::STATUS_PICKED_UP => ['assigned_pickup'],
        self::STATUS_ARRIVED_AT_ORIGIN_HUB => [self::STATUS_PICKED_UP],
        self::STATUS_IN_TRANSIT_TO_MOTHER_HUB => [self::STATUS_ARRIVED_AT_ORIGIN_HUB, self::STATUS_SORTED_TO_LINE_HAUL],
        self::STATUS_ARRIVED_AT_MOTHER_HUB => [self::STATUS_IN_TRANSIT_TO_MOTHER_HUB],
        self::STATUS_SORTED_TO_LINE_HAUL => [self::STATUS_ARRIVED_AT_MOTHER_HUB],
        self::STATUS_IN_TRANSIT_TO_DEST_HUB => [self::STATUS_SORTED_TO_LINE_HAUL],
        self::STATUS_ARRIVED_AT_DEST_HUB => [self::STATUS_IN_TRANSIT_TO_DEST_HUB],
        self::STATUS_SORTED_TO_BARANGAY_BIN => [self::STATUS_ARRIVED_AT_DEST_HUB],
        self::STATUS_READY_FOR_HUB_PICKUP => [self::STATUS_ARRIVED_AT_DEST_HUB],
        self::STATUS_ASSIGNED_TO_RIDER => [self::STATUS_SORTED_TO_BARANGAY_BIN],
        self::STATUS_OUT_FOR_DELIVERY => [self::STATUS_ASSIGNED_TO_RIDER],
        self::STATUS_DELIVERED => [self::STATUS_OUT_FOR_DELIVERY],
        self::STATUS_DELIVERY_FAILED => [self::STATUS_OUT_FOR_DELIVERY],
        self::STATUS_CUSTOMER_COLLECTED => [self::STATUS_READY_FOR_HUB_PICKUP],
        self::STATUS_COMPLETED => [self::STATUS_DELIVERED],
    ];

    public function transition(
        Delivery $delivery,
        string $targetStatus,
        User $actor,
        array $scanMetadata = []
    ): Delivery {
        return DB::transaction(function () use ($delivery, $targetStatus, $actor, $scanMetadata) {
            // Match seller fulfillment/cancellation: lock the order before its parcel.
            $orderId = Delivery::whereKey($delivery->id)->value('order_id');
            $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $lockedDelivery->setRelation('order', $lockedOrder);
            $targetStatus = strtolower(trim($targetStatus));
            if ($targetStatus !== self::STATUS_COMPLETED) {
                $hubIds = [$scanMetadata['hub_id'] ?? null];
                if ($targetStatus === self::STATUS_PICKED_UP) {
                    $hubIds[] = $lockedDelivery->origin_bayan_hub_id;
                } elseif (in_array($targetStatus, [self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERED, self::STATUS_DELIVERY_FAILED], true)) {
                    $hubIds[] = $lockedDelivery->destination_bayan_hub_id;
                }
                $this->eligibility->lockNetwork((int) $lockedDelivery->logistics_company_id, [$actor->id, $scanMetadata['rider_id'] ?? null], $hubIds);
            } else {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::findOrFail($actor->id);
            $hub = $this->resolveHub($scanMetadata['hub_id'] ?? null);

            $this->assertActiveActor($actor);
            $transport = in_array($targetStatus, [self::STATUS_IN_TRANSIT_TO_MOTHER_HUB, self::STATUS_IN_TRANSIT_TO_DEST_HUB,
                self::STATUS_ARRIVED_AT_MOTHER_HUB, self::STATUS_ARRIVED_AT_DEST_HUB, self::STATUS_RETURN_IN_TRANSIT, self::STATUS_RETURN_TO_SENDER], true)
                ? $this->requireManifestScan($lockedDelivery, $targetStatus, $actor, $hub, $scanMetadata) : null;
            $this->assertActorMayTransition($lockedDelivery, $targetStatus, $actor, $hub, $scanMetadata, $transport);
            if ($transport) {
                $scanMetadata['manifest_number'] = $transport->manifest->reference;
                $scanMetadata['manifest_type'] = $transport->manifest->type;
            }

            $inputs = app(WaybillScanInputService::class);
            $scanMetadata = $inputs->normalize($scanMetadata);
            Validator::make($scanMetadata, ['notes' => $inputs->notesRules()])->validate();
            $scanRequired = in_array($targetStatus, [self::STATUS_PICKED_UP, self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERY_FAILED, self::STATUS_ARRIVED_AT_ORIGIN_HUB,
                self::STATUS_IN_TRANSIT_TO_MOTHER_HUB, self::STATUS_ARRIVED_AT_MOTHER_HUB,
                self::STATUS_IN_TRANSIT_TO_DEST_HUB, self::STATUS_ARRIVED_AT_DEST_HUB, self::STATUS_RETURN_IN_TRANSIT, self::STATUS_RETURN_TO_SENDER], true);
            $barcode = $scanRequired || isset($scanMetadata['barcode'])
                ? $inputs->matchedBarcode($scanMetadata['barcode'] ?? null, $lockedDelivery, allowOrderNumber: $hub !== null)
                : null;

            if ($lockedDelivery->status === $targetStatus) {
                $existing = DeliveryCheckpoint::query()
                    ->where('delivery_id', $lockedDelivery->id)
                    ->where('checkpoint_type', $targetStatus)
                    ->when($hub, fn ($query) => $query->where('hub_id', $hub->id))
                    ->when($transport, fn ($query) => $query->where('manifest_number', $transport->manifest->reference))
                    ->latest('id')->first();
                if ($existing) {
                    if ($existing->scanned_by_id !== $actor->id) {
                        throw new DomainException('This custody action was already recorded by another actor. Refresh the parcel before continuing.');
                    }

                    return $lockedDelivery;
                }
            }

            if (in_array($lockedOrder->status, ['cancelled', 'completed', 'returned'], true)) {
                throw new DomainException('This order is terminal and cannot change parcel custody.');
            }

            $expectedStatus = isset($scanMetadata['expected_status'])
                ? strtolower(trim((string) $scanMetadata['expected_status']))
                : null;
            if ($expectedStatus && $lockedDelivery->status !== $expectedStatus) {
                throw new DomainException("Parcel status changed from {$expectedStatus} to {$lockedDelivery->status}. Scan the waybill again.");
            }

            $allowedFrom = self::ALLOWED_FROM[$targetStatus] ?? [];
            if (! in_array($lockedDelivery->status, $allowedFrom, true)) {
                throw new DomainException("Parcel is {$lockedDelivery->status}; it cannot advance to {$targetStatus}.");
            }

            $requiredOrderStatus = match ($targetStatus) {
                self::STATUS_PICKED_UP => self::STATUS_READY_FOR_PICKUP,
                self::STATUS_OUT_FOR_DELIVERY => self::STATUS_ASSIGNED_TO_RIDER,
                self::STATUS_DELIVERED, self::STATUS_DELIVERY_FAILED => self::STATUS_OUT_FOR_DELIVERY,
                self::STATUS_RETURN_IN_TRANSIT, self::STATUS_RETURN_TO_SENDER => self::STATUS_DELIVERY_FAILED,
                default => null,
            };
            if ($requiredOrderStatus && $lockedOrder->status !== $requiredOrderStatus) {
                throw new DomainException("Order is {$lockedOrder->status}; this scan requires {$requiredOrderStatus}.");
            }

            if ($targetStatus === self::STATUS_OUT_FOR_DELIVERY) {
                $custody = DeliveryCheckpoint::lastCustody($lockedDelivery);
                if ($lockedDelivery->current_hub_id !== $lockedDelivery->destination_bayan_hub_id
                    || ($custody['kind'] ?? null) !== 'hub' || ($custody['hub_id'] ?? null) !== $lockedDelivery->destination_bayan_hub_id) {
                    throw new DomainException('The assigned parcel needs actual destination-hub custody before departure.');
                }
            }
            if ($targetStatus === self::STATUS_DELIVERY_FAILED) {
                $attempt = isset($scanMetadata['attempt_id']) && is_int($scanMetadata['attempt_id'])
                    ? DeliveryAttempt::find($scanMetadata['attempt_id']) : null;
                $departure = $lockedDelivery->checkpoints()->where('checkpoint_type', self::STATUS_OUT_FOR_DELIVERY)->latest('id')->first();
                if (! $attempt || $attempt->delivery_id !== $lockedDelivery->id || $attempt->rider_id !== $actor->id
                    || $attempt->departure_checkpoint_id !== $departure?->id || $attempt->attempt_number !== $lockedDelivery->failure_attempts + 1
                    || $attempt->attempt_number > 3 || $attempt->barcode_scanned !== $barcode
                    || $attempt->source_state !== DeliveryCheckpoint::state($lockedDelivery)) {
                    throw new DomainException('Use the recorded attempt action with actual reason, notes and proof.');
                }
                $scanMetadata['reason'] = $attempt->reason_code;
                $scanMetadata['notes'] = $attempt->notes.' Attempt evidence: '.$attempt->reference;
            }

            if ($targetStatus === self::STATUS_DELIVERED) {
                $proof = $scanMetadata['proof_image'] ?? null;
                if (
                    ! is_string($proof)
                    || preg_match('/\A\/storage\/delivery-proofs\/[A-Za-z0-9._-]+\z/', $proof) !== 1
                    || ! Storage::disk('public')->exists(substr($proof, strlen('/storage/')))
                ) {
                    throw new DomainException('Upload a proof of delivery image before recording handoff.');
                }
            }

            $previousStatus = $lockedDelivery->status;
            $sourceState = DeliveryCheckpoint::state($lockedDelivery);
            $custodyBefore = DeliveryCheckpoint::lastCustody($lockedDelivery);
            $lockedDelivery->status = $targetStatus;
            $this->applyTransition($lockedDelivery, $targetStatus, $actor, $hub, $scanMetadata);
            $lockedDelivery->save();
            $lockedDelivery->order->save();

            DeliveryCheckpoint::record(
                delivery: $lockedDelivery,
                type: $targetStatus,
                location: $scanMetadata['location_name'] ?? $hub?->name ?? 'Mobile Terminal',
                notes: $scanMetadata['notes'] ?? "Status transitioned from {$previousStatus} to {$targetStatus}",
                actor: $actor,
                proofImage: $scanMetadata['proof_image'] ?? null,
                hub: $hub,
                facilityCode: $hub?->code,
                latitude: $scanMetadata['latitude'] ?? null,
                longitude: $scanMetadata['longitude'] ?? null,
                manifestNumber: $scanMetadata['manifest_number'] ?? null,
                barcodeScanned: $barcode,
                evidence: ['source_state' => $sourceState, 'target_state' => DeliveryCheckpoint::state($lockedDelivery),
                    'custody_before' => $custodyBefore, 'custody_after' => $this->custodyAfter($lockedDelivery, $targetStatus, $actor, $hub, $barcode, $custodyBefore, $transport)],
                scanProvenance: $transport?->event_type === 'parcel_dispatched' ? 'manifest_load_event' : null,
            );

            return $lockedDelivery;
        });
    }

    private function assertActiveActor(User $actor): void
    {
        if (! $actor->canAccessPortal()) {
            throw new DomainException('Only active and approved accounts may change parcel custody.');
        }
    }

    private function custodyAfter(Delivery $delivery, string $status, User $actor, ?LogisticsHub $hub, ?string $barcode, array $before, ?LogisticsManifestEvent $transport = null): array
    {
        return match ($status) {
            self::STATUS_PICKED_UP => ['kind' => 'courier', 'user_id' => $actor->id],
            self::STATUS_ARRIVED_AT_ORIGIN_HUB, self::STATUS_ARRIVED_AT_MOTHER_HUB, self::STATUS_ARRIVED_AT_DEST_HUB, self::STATUS_RETURN_TO_SENDER => ['kind' => 'hub', 'hub_id' => $hub->id]
                + ($transport ? ['manifest_scan_reference' => $transport->reference] : []),
            self::STATUS_IN_TRANSIT_TO_MOTHER_HUB, self::STATUS_IN_TRANSIT_TO_DEST_HUB, self::STATUS_RETURN_IN_TRANSIT => ['kind' => 'manifest',
                'manifest_id' => $transport->manifest_id, 'manifest_reference' => $transport->manifest->reference,
                'vehicle_id' => $transport->manifest->vehicle_id, 'driver_id' => $transport->manifest->driver_id,
                'manifest_scan_reference' => $transport->reference, 'source_scan_reference' => $transport->payload['source_scan_reference']],
            self::STATUS_OUT_FOR_DELIVERY => $barcode ? ['kind' => 'courier', 'user_id' => $actor->id] : ['kind' => 'unknown', 'reason' => 'Final-mile handoff lacks a submitted waybill.'],
            self::STATUS_DELIVERED => $barcode ? ['kind' => 'buyer', 'user_id' => $delivery->order->buyer_id] : ['kind' => 'unknown', 'reason' => 'Recipient handoff lacks a submitted waybill.'],
            self::STATUS_CUSTOMER_COLLECTED => ['kind' => 'unknown', 'reason' => 'Secure counter evidence remains required.'],
            default => $before,
        };
    }

    private function requireManifestScan(Delivery $delivery, string $status, User $actor, ?LogisticsHub $hub, array $metadata): LogisticsManifestEvent
    {
        if (! isset($metadata['manifest_scan_event_id']) || ! is_int($metadata['manifest_scan_event_id'])) {
            throw new DomainException('Use the recorded manifest load, dispatch and receipt actions for hub transport.');
        }
        $scan = LogisticsManifestEvent::with(['manifest', 'parcel'])->find($metadata['manifest_scan_event_id']);
        $departure = in_array($status, [self::STATUS_IN_TRANSIT_TO_MOTHER_HUB, self::STATUS_IN_TRANSIT_TO_DEST_HUB, self::STATUS_RETURN_IN_TRANSIT], true);
        $manifest = $scan?->manifest;
        if (! $scan || ! $manifest || $scan->event_type !== ($departure ? 'parcel_dispatched' : 'parcel_received')
            || $scan->actor_id !== $actor->id || $scan->hub_id !== $hub?->id || $scan->parcel?->delivery_id !== $delivery->id
            || ! $scan->parcel->included || $scan->parcel->active_delivery_id !== $delivery->id
            || $manifest->logistics_company_id !== $delivery->logistics_company_id || ! in_array($manifest->status, ['dispatched', 'received'], true)
            || $this->inputsBarcode($metadata, $delivery) !== $scan->barcode_scanned) {
            throw new DomainException('A current authorized manifest scan is required for this parcel and facility.');
        }
        $service = app(LogisticsManifestService::class);
        $expected = $departure ? $service->transitStatus($manifest)
            : $service->receiptStatus($manifest);
        if ($status !== $expected || $service->direction($delivery) !== $manifest->direction || $service->route($delivery) !== $scan->parcel->route_snapshot) {
            throw new DomainException('This scan does not match the recorded parcel route and manifest leg.');
        }
        $existing = $delivery->checkpoints()->where('checkpoint_type', $status)->where('manifest_number', $manifest->reference)->first();
        if ($existing && ($existing->custody_after['manifest_scan_reference'] ?? null) === $scan->reference && $delivery->status === $status) {
            return $scan;
        }
        if ($delivery->status !== ($scan->source_state['delivery_status'] ?? null)) {
            throw new DomainException('The manifest scan source state changed. Refresh the current parcel.');
        }
        if ($departure) {
            if ($delivery->current_hub_id !== $manifest->source_hub_id || $service->nextHub($delivery) !== $manifest->destination_hub_id) {
                throw new DomainException('The manifest cannot skip or replace the next required Mother Hub leg.');
            }
        } else {
            $custody = DeliveryCheckpoint::lastCustody($delivery);
            if ($hub?->id !== $manifest->destination_hub_id || $delivery->status !== $service->transitStatus($manifest)
                || ($custody['kind'] ?? null) !== 'manifest' || ($custody['manifest_id'] ?? null) !== $manifest->id) {
                throw new DomainException('This parcel is not in the arriving manifest custody.');
            }
        }

        return $scan;
    }

    private function inputsBarcode(array $metadata, Delivery $delivery): string
    {
        return app(WaybillScanInputService::class)->matchedBarcode($metadata['barcode'] ?? null, $delivery, allowOrderNumber: true);
    }

    private function assertActorMayTransition(
        Delivery $delivery,
        string $targetStatus,
        User $actor,
        ?LogisticsHub $hub,
        array $metadata,
        ?LogisticsManifestEvent $transport = null
    ): void {
        if ($targetStatus === self::STATUS_PICKED_UP) {
            $this->eligibility->assertBayanHub((int) $delivery->origin_bayan_hub_id);
            $this->assertCourier($actor, $delivery->courier_id, 'pickup rider');
            $profile = CourierProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            $this->eligibility->assertCourierScope($profile, (int) $delivery->logistics_company_id, (int) $delivery->origin_bayan_hub_id);
            if (! $profile || $profile->logistics_company_id !== $delivery->logistics_company_id || $profile->assigned_hub_id !== $delivery->origin_bayan_hub_id) {
                throw new DomainException('The pickup rider is not assigned to this logistics company and origin hub.');
            }

            return;
        }

        if (in_array($targetStatus, [self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERED, self::STATUS_DELIVERY_FAILED], true)) {
            $this->eligibility->assertBayanHub((int) $delivery->destination_bayan_hub_id);
            $this->assertCourier($actor, $delivery->assigned_rider_id, 'final-mile rider');
            $profile = CourierProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            $this->eligibility->assertCourierScope($profile, (int) $delivery->logistics_company_id, (int) $delivery->destination_bayan_hub_id);
            if (! $profile || $profile->logistics_company_id !== $delivery->logistics_company_id || $profile->assigned_hub_id !== $delivery->destination_bayan_hub_id) {
                throw new DomainException('The final-mile rider is outside the parcel company or destination-hub scope.');
            }

            return;
        }

        if ($targetStatus === self::STATUS_COMPLETED) {
            if (! $actor->isBuyer() || $delivery->order->buyer_id !== $actor->id) {
                throw new DomainException('Only the order buyer may complete a delivered order.');
            }

            return;
        }

        if (! $hub || $hub->logistics_company_id !== $delivery->logistics_company_id) {
            throw new DomainException('The scan facility is outside the parcel logistics company.');
        }

        if (! $actor->isLogistics()) {
            throw new DomainException('Only an authorized logistics operator may perform a facility scan.');
        }

        $isScopedCompanyAdmin = $this->isCompanyAdmin($actor, $delivery->logistics_company_id);
        $hasHandlerAssignment = HubHandler::eligible()->where('user_id', $actor->id)->where('hub_id', $hub->id)->lockForUpdate()->first();
        if (! $hasHandlerAssignment && ! ($targetStatus === self::STATUS_ASSIGNED_TO_RIDER && $isScopedCompanyAdmin)) {
            throw new DomainException('This operator is not assigned to the scanned facility.');
        }

        $expectedHubId = match ($targetStatus) {
            self::STATUS_ARRIVED_AT_ORIGIN_HUB => $delivery->origin_bayan_hub_id,
            self::STATUS_IN_TRANSIT_TO_MOTHER_HUB, self::STATUS_IN_TRANSIT_TO_DEST_HUB, self::STATUS_RETURN_IN_TRANSIT => $transport?->manifest->source_hub_id,
            self::STATUS_ARRIVED_AT_MOTHER_HUB, self::STATUS_ARRIVED_AT_DEST_HUB, self::STATUS_RETURN_TO_SENDER => $transport?->manifest->destination_hub_id,
            self::STATUS_SORTED_TO_LINE_HAUL => in_array($delivery->current_hub_id, [$delivery->origin_mother_hub_id, $delivery->destination_mother_hub_id], true) ? $delivery->current_hub_id : null,
            self::STATUS_SORTED_TO_BARANGAY_BIN,
            self::STATUS_READY_FOR_HUB_PICKUP,
            self::STATUS_ASSIGNED_TO_RIDER,
            self::STATUS_CUSTOMER_COLLECTED => $delivery->destination_bayan_hub_id,
            default => null,
        };

        if (! $expectedHubId || $hub->id !== $expectedHubId) {
            throw new DomainException('The parcel is not expected at this facility for the requested scan.');
        }
        $tier = $transport ? ($hub->id === $transport->manifest->source_hub_id ? $transport->manifest->sourceHub->tier : $transport->manifest->destinationHub->tier)
            : (in_array($targetStatus, [self::STATUS_SORTED_TO_LINE_HAUL], true)
            ? 'regional_mother_hub' : 'local_bayan_hub');
        if ($hub->tier !== $tier) {
            throw new DomainException('The facility tier does not match the requested custody operation.');
        }
        if (in_array($targetStatus, [self::STATUS_READY_FOR_HUB_PICKUP, self::STATUS_CUSTOMER_COLLECTED], true) && ! $hub->allows_self_pickup) {
            throw new DomainException('The selected facility does not currently allow counter pickup.');
        }

        if ($targetStatus === self::STATUS_ASSIGNED_TO_RIDER && empty($metadata['rider_id'])) {
            throw new DomainException('A final-mile rider is required for assignment.');
        }

        if ($targetStatus === self::STATUS_ASSIGNED_TO_RIDER) {
            if ($delivery->status === $targetStatus && (int) $metadata['rider_id'] !== $delivery->assigned_rider_id) {
                throw new DomainException('The parcel already belongs to another final-mile rider.');
            }
            $rider = User::with('courierProfile')->find($metadata['rider_id']);
            $profile = $rider
                ? CourierProfile::where('user_id', $rider->id)->lockForUpdate()->first()
                : null;
            $newWork = $delivery->status !== $targetStatus;
            $this->eligibility->assertCourierScope($profile, (int) $delivery->logistics_company_id, (int) $delivery->destination_bayan_hub_id, newWork: $newWork);
            $barangay = trim((string) $delivery->order?->destination_barangay);
            if (
                ! $rider
                || ! $rider->isEligibleCourier()
                || ($newWork && ! $profile?->is_available)
                || $profile->logistics_company_id !== $delivery->logistics_company_id
                || $profile->assigned_hub_id !== $delivery->destination_bayan_hub_id
                || ($profile->assigned_barangay && $barangay !== '' && strcasecmp($profile->assigned_barangay, $barangay) !== 0)
            ) {
                throw new DomainException('The selected rider is not eligible for this company, hub, and barangay.');
            }

            if ($newWork && Delivery::riderHasActiveWork($rider->id, $delivery->id)) {
                throw new DomainException('The selected rider already has active courier work.');
            }
        }
    }

    private function applyTransition(
        Delivery $delivery,
        string $targetStatus,
        User $actor,
        ?LogisticsHub $hub,
        array $metadata
    ): void {
        $order = $delivery->order;

        switch ($targetStatus) {
            case self::STATUS_RETURN_IN_TRANSIT:
                $delivery->current_hub_id = null;
                break;
            case self::STATUS_RETURN_TO_SENDER:
                $delivery->current_hub_id = $hub->id;
                break;
            case self::STATUS_PICKED_UP:
                $delivery->picked_up_at = now();
                $delivery->current_hub_id = null;
                $order->status = self::STATUS_PICKED_UP;
                break;
            case self::STATUS_ARRIVED_AT_ORIGIN_HUB:
            case self::STATUS_ARRIVED_AT_MOTHER_HUB:
            case self::STATUS_ARRIVED_AT_DEST_HUB:
                $delivery->current_hub_id = $hub?->id;
                $order->status = 'at_sorting_center';
                break;
            case self::STATUS_IN_TRANSIT_TO_MOTHER_HUB:
            case self::STATUS_IN_TRANSIT_TO_DEST_HUB:
                $delivery->current_hub_id = null;
                if ($metadata['manifest_type'] === 'line_haul') {
                    $delivery->truck_manifest_number = $metadata['manifest_number'];
                } else {
                    $delivery->shuttle_manifest_number = $metadata['manifest_number'];
                }
                $order->status = 'at_sorting_center';
                break;
            case self::STATUS_SORTED_TO_LINE_HAUL:
                $order->status = 'at_sorting_center';
                break;
            case self::STATUS_SORTED_TO_BARANGAY_BIN:
                $delivery->destination_bin = $metadata['destination_bin'] ?? $delivery->destination_bin;
                $order->status = 'sorted';
                break;
            case self::STATUS_READY_FOR_HUB_PICKUP:
                $order->status = 'sorted';
                break;
            case self::STATUS_ASSIGNED_TO_RIDER:
                $delivery->assigned_rider_id = $metadata['rider_id'];
                $delivery->assigned_at = now();
                $order->status = self::STATUS_ASSIGNED_TO_RIDER;
                break;
            case self::STATUS_OUT_FOR_DELIVERY:
                $delivery->current_hub_id = null;
                $order->status = self::STATUS_OUT_FOR_DELIVERY;
                break;
            case self::STATUS_DELIVERED:
            case self::STATUS_CUSTOMER_COLLECTED:
                $delivery->current_hub_id = null;
                $delivery->delivered_at = now();
                $delivery->proof_image = $metadata['proof_image'] ?? $delivery->proof_image;
                $delivery->pod_latitude = $metadata['latitude'] ?? null;
                $delivery->pod_longitude = $metadata['longitude'] ?? null;
                $delivery->pod_geofence_verified = (bool) ($metadata['geofence_verified'] ?? false);
                $delivery->pod_signature = $metadata['pod_signature'] ?? null;
                $order->status = self::STATUS_DELIVERED;
                break;
            case self::STATUS_COMPLETED:
                $order->status = self::STATUS_COMPLETED;
                break;
            case self::STATUS_DELIVERY_FAILED:
                $delivery->failure_attempts++;
                $delivery->failure_reason = $metadata['reason'];
                $order->status = self::STATUS_DELIVERY_FAILED;
                break;
        }
    }

    private function assertCourier(User $actor, ?int $assignedUserId, string $phase): void
    {
        if (! $actor->isCourier() || $actor->id !== $assignedUserId) {
            throw new DomainException("Only the assigned {$phase} may perform this scan.");
        }
    }

    private function resolveHub(mixed $hubId): ?LogisticsHub
    {
        return $hubId ? LogisticsHub::eligible()->whereKey($hubId)->first() : null;
    }

    private function isCompanyAdmin(User $actor, ?int $companyId): bool
    {
        return $this->eligibility->isCompanyAdministrator($actor, $companyId);
    }
}
