<?php

namespace App\Services\Courier;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CourierOperationsService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function queue(User $rider, string $phase): Builder
    {
        $profile = CourierProfile::where('user_id', $rider->id)->first();
        $query = Delivery::query()->where('logistics_company_id', $profile?->logistics_company_id)
            ->with(['order.items', 'originBayanHub', 'destinationBayanHub']);
        if (! $rider->isEligibleCourier() || ! $profile?->logistics_company_id || ! $profile->assigned_hub_id) {
            return $query->whereRaw('1 = 0');
        }

        return match ($phase) {
            'available' => $query->whereNull('courier_id')->whereRaw('deliveries.status = ?', ['unassigned'])
                ->where('origin_bayan_hub_id', $profile->assigned_hub_id)
                ->whereHas('order', fn ($order) => $order->where('status', OrderStateMachineService::STATUS_READY_FOR_PICKUP))
                ->when(! $this->eligibility->canReceivePickups($profile), fn ($empty) => $empty->whereRaw('1 = 0'))
                ->orderBy('created_at')->orderBy('id'),
            'pickup' => $query->where('courier_id', $rider->id)->where('origin_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw("deliveries.status in ('assigned', 'assigned_pickup', 'picked_up')")->orderBy('assigned_at')->orderBy('id'),
            'final_mile' => $query->where('assigned_rider_id', $rider->id)->where('destination_bayan_hub_id', $profile->assigned_hub_id)
                ->whereRaw("deliveries.status in ('assigned_to_rider', 'out_for_delivery', 'delivery_failed')")
                ->where(fn ($failed) => $failed->whereRaw("deliveries.status != 'delivery_failed'")->orWhereNull('current_hub_id'))
                ->orderBy('assigned_at')->orderBy('id'),
            default => throw new DomainException('Unknown courier queue.'),
        };
    }

    public function claimPickup(User $rider, Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($rider, $delivery) {
            // Serialize claims with seller cancellation using the same lock order.
            $orderId = Delivery::whereKey($delivery->id)->value('order_id');
            $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $lockedDelivery->setRelation('order', $lockedOrder);
            $this->eligibility->lockNetwork((int) $lockedDelivery->logistics_company_id, [$rider->id], [$lockedDelivery->origin_bayan_hub_id]);
            $this->eligibility->assertBayanHub((int) $lockedDelivery->origin_bayan_hub_id);
            $rider = User::findOrFail($rider->id);
            $profile = CourierProfile::with(['company', 'hub'])
                ->where('user_id', $rider->id)
                ->lockForUpdate()
                ->first();

            $this->assertEligibleRider($rider, $profile);
            $this->eligibility->assertCourierScope($profile, (int) $lockedDelivery->logistics_company_id, (int) $lockedDelivery->origin_bayan_hub_id, newWork: true);

            if (Delivery::activePickupCount($rider->id) >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) {
                throw new DomainException(
                    'Pickup capacity reached. Complete or hand over one of your active pickups before claiming another.'
                );
            }
            if (! $this->eligibility->canReceivePickups($profile)) {
                throw new DomainException('Complete your active final-mile work before claiming a new pickup.');
            }

            if ($lockedDelivery->courier_id !== null || $lockedDelivery->status !== 'unassigned') {
                throw new DomainException('This pickup job is unavailable or has already been claimed.');
            }

            if ($lockedDelivery->order?->status !== OrderStateMachineService::STATUS_READY_FOR_PICKUP) {
                throw new DomainException('This parcel is not ready for pickup.');
            }

            if (
                $profile->logistics_company_id !== $lockedDelivery->logistics_company_id
                || $profile->assigned_hub_id !== $lockedDelivery->origin_bayan_hub_id
            ) {
                throw new DomainException('This pickup job is outside your assigned company or origin hub.');
            }

            $sourceState = DeliveryCheckpoint::state($lockedDelivery);
            $custody = DeliveryCheckpoint::lastCustody($lockedDelivery);
            $lockedDelivery->update([
                'courier_id' => $rider->id,
                'status' => 'assigned_pickup',
                'assigned_at' => now(),
            ]);

            DeliveryCheckpoint::record(
                delivery: $lockedDelivery,
                type: 'assigned_pickup',
                location: $lockedDelivery->pickup_store_name ?? 'Merchant store',
                notes: "Pickup job claimed by {$rider->name}",
                actor: $rider,
                evidence: ['source_state' => $sourceState, 'target_state' => DeliveryCheckpoint::state($lockedDelivery),
                    'custody_before' => $custody, 'custody_after' => $custody],
            );

            return $lockedDelivery->fresh(['order']);
        });
    }

    public function setAvailability(User $rider, bool $isAvailable): CourierProfile
    {
        return DB::transaction(function () use ($rider, $isAvailable) {
            $scope = CourierProfile::where('user_id', $rider->id)->first();
            if ($isAvailable) {
                $this->eligibility->lockNetwork((int) $scope?->logistics_company_id, [$rider->id], [$scope?->assigned_hub_id]);
            } else {
                User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            }
            $rider = User::findOrFail($rider->id);
            $profile = CourierProfile::with(['company', 'hub'])
                ->where('user_id', $rider->id)
                ->lockForUpdate()
                ->first();
            $this->assertEligibleRider($rider, $profile, requireAvailability: false);

            if ($isAvailable) {
                $this->eligibility->assertCourierScope($profile, (int) $scope?->logistics_company_id, (int) $scope?->assigned_hub_id);
            }

            $profile->update(['is_available' => $isAvailable]);

            return $profile->fresh();
        });
    }

    private function assertEligibleRider(
        User $rider,
        ?CourierProfile $profile,
        bool $requireAvailability = true
    ): void {
        if (! $rider->isEligibleCourier()) {
            throw new DomainException('Only an active and approved rider may perform courier work.');
        }

        if (! $profile) {
            throw new DomainException('Your courier profile is incomplete.');
        }

        if ($requireAvailability && ! $profile->is_available) {
            throw new DomainException('Go on duty before claiming a pickup job.');
        }
    }
}
