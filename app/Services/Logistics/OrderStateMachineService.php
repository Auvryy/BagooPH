<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class OrderStateMachineService
{
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

    private const ALLOWED_FROM = [
        self::STATUS_PICKED_UP => ['assigned_pickup'],
        self::STATUS_ARRIVED_AT_ORIGIN_HUB => [self::STATUS_PICKED_UP],
        self::STATUS_IN_TRANSIT_TO_MOTHER_HUB => [self::STATUS_ARRIVED_AT_ORIGIN_HUB],
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
            $lockedDelivery = Delivery::with('order')->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $targetStatus = strtolower(trim($targetStatus));
            $hub = $this->resolveHub($scanMetadata['hub_id'] ?? null);

            $this->assertActiveActor($actor);
            $this->assertActorMayTransition($lockedDelivery, $targetStatus, $actor, $hub, $scanMetadata);

            if ($lockedDelivery->status === $targetStatus) {
                $existing = DeliveryCheckpoint::query()
                    ->where('delivery_id', $lockedDelivery->id)
                    ->where('checkpoint_type', $targetStatus)
                    ->when($hub, fn ($query) => $query->where('hub_id', $hub->id))
                    ->exists();
                if ($existing) {
                    return $lockedDelivery;
                }
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

            $previousStatus = $lockedDelivery->status;
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
                manifestNumber: $scanMetadata['manifest_number'] ?? null
            );

            return $lockedDelivery->fresh();
        });
    }

    private function assertActiveActor(User $actor): void
    {
        if ($actor->status !== 'active' || $actor->kyc_status !== 'approved') {
            throw new DomainException('Only active and approved accounts may change parcel custody.');
        }
    }

    private function assertActorMayTransition(
        Delivery $delivery,
        string $targetStatus,
        User $actor,
        ?LogisticsHub $hub,
        array $metadata
    ): void {
        if ($targetStatus === self::STATUS_PICKED_UP) {
            $this->assertCourier($actor, $delivery->courier_id, 'pickup rider');
            $profile = $actor->courierProfile;
            if (! $profile || $profile->logistics_company_id !== $delivery->logistics_company_id || $profile->assigned_hub_id !== $delivery->origin_bayan_hub_id) {
                throw new DomainException('The pickup rider is not assigned to this logistics company and origin hub.');
            }

            return;
        }

        if (in_array($targetStatus, [self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERED, self::STATUS_DELIVERY_FAILED], true)) {
            $this->assertCourier($actor, $delivery->assigned_rider_id, 'final-mile rider');
            $profile = $actor->courierProfile;
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
        if (! $isScopedCompanyAdmin && ! HubHandler::where('user_id', $actor->id)->where('hub_id', $hub->id)->where('is_active', true)->exists()) {
            throw new DomainException('This operator is not assigned to the scanned facility.');
        }

        $expectedHubId = match ($targetStatus) {
            self::STATUS_ARRIVED_AT_ORIGIN_HUB,
            self::STATUS_IN_TRANSIT_TO_MOTHER_HUB => $delivery->origin_bayan_hub_id,
            self::STATUS_ARRIVED_AT_MOTHER_HUB,
            self::STATUS_SORTED_TO_LINE_HAUL,
            self::STATUS_IN_TRANSIT_TO_DEST_HUB => $delivery->origin_mother_hub_id,
            self::STATUS_ARRIVED_AT_DEST_HUB,
            self::STATUS_SORTED_TO_BARANGAY_BIN,
            self::STATUS_READY_FOR_HUB_PICKUP,
            self::STATUS_ASSIGNED_TO_RIDER,
            self::STATUS_CUSTOMER_COLLECTED => $delivery->destination_bayan_hub_id,
            default => null,
        };

        if (! $expectedHubId || $hub->id !== $expectedHubId) {
            throw new DomainException('The parcel is not expected at this facility for the requested scan.');
        }

        if ($targetStatus === self::STATUS_ASSIGNED_TO_RIDER && empty($metadata['rider_id'])) {
            throw new DomainException('A final-mile rider is required for assignment.');
        }

        if ($targetStatus === self::STATUS_ASSIGNED_TO_RIDER) {
            $rider = User::with('courierProfile')->find($metadata['rider_id']);
            $profile = $rider?->courierProfile;
            $barangay = trim((string) $delivery->order?->destination_barangay);
            if (
                ! $rider
                || ! $rider->isCourier()
                || $rider->status !== 'active'
                || $rider->kyc_status !== 'approved'
                || ! $profile?->is_available
                || $profile->logistics_company_id !== $delivery->logistics_company_id
                || $profile->assigned_hub_id !== $delivery->destination_bayan_hub_id
                || ($profile->assigned_barangay && $barangay !== '' && strcasecmp($profile->assigned_barangay, $barangay) !== 0)
            ) {
                throw new DomainException('The selected rider is not eligible for this company, hub, and barangay.');
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
                $delivery->current_hub_id = null;
                $delivery->shuttle_manifest_number = $metadata['manifest_number'] ?? 'FEEDER-'.now()->format('Ymd-His').'-'.$delivery->id;
                $order->status = 'at_sorting_center';
                break;
            case self::STATUS_SORTED_TO_LINE_HAUL:
                $order->status = 'at_sorting_center';
                break;
            case self::STATUS_IN_TRANSIT_TO_DEST_HUB:
                $delivery->current_hub_id = null;
                $delivery->truck_manifest_number = $metadata['manifest_number'] ?? 'LINEHAUL-'.now()->format('Ymd-His').'-'.$delivery->id;
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
                if ($delivery->failure_attempts >= 3) {
                    $delivery->status = self::STATUS_RETURN_TO_SENDER;
                }
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
        return $hubId ? LogisticsHub::whereKey($hubId)->where('is_active', true)->first() : null;
    }

    private function isCompanyAdmin(User $actor, ?int $companyId): bool
    {
        return $actor->isLogistics()
            && LogisticsCompany::whereKey($companyId)->where('user_id', $actor->id)->exists();
    }
}
