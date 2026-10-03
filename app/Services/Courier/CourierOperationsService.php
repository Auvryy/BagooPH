<?php

namespace App\Services\Courier;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Support\Facades\DB;

class CourierOperationsService
{
    public function claimPickup(User $rider, Delivery $delivery): Delivery
    {
        return DB::transaction(function () use ($rider, $delivery) {
            // Serialize claims with seller cancellation using the same lock order.
            $orderId = Delivery::whereKey($delivery->id)->value('order_id');
            $lockedOrder = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::whereKey($delivery->id)->where('order_id', $lockedOrder->id)->lockForUpdate()->firstOrFail();
            $lockedDelivery->setRelation('order', $lockedOrder);
            $profile = CourierProfile::with(['company', 'hub'])
                ->where('user_id', $rider->id)
                ->lockForUpdate()
                ->first();

            $this->assertEligibleRider($rider, $profile);
            $this->assertOperationalScope($profile);

            if (Delivery::activePickupCount($rider->id) >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER) {
                throw new DomainException(
                    'Pickup capacity reached. Complete or hand over one of your active pickups before claiming another.'
                );
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
                actor: $rider
            );

            return $lockedDelivery->fresh(['order']);
        });
    }

    public function setAvailability(User $rider, bool $isAvailable): CourierProfile
    {
        return DB::transaction(function () use ($rider, $isAvailable) {
            $profile = CourierProfile::with(['company', 'hub'])
                ->where('user_id', $rider->id)
                ->lockForUpdate()
                ->first();
            $this->assertEligibleRider($rider, $profile, requireAvailability: false);

            if ($isAvailable) {
                $this->assertOperationalScope($profile);
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
        if (! $rider->isCourier() || $rider->status !== 'active' || ! $rider->isKycApproved()) {
            throw new DomainException('Only an active and approved rider may perform courier work.');
        }

        if (! $profile) {
            throw new DomainException('Your courier profile is incomplete.');
        }

        if ($requireAvailability && ! $profile->is_available) {
            throw new DomainException('Go on duty before claiming a pickup job.');
        }
    }

    private function assertOperationalScope(CourierProfile $profile): void
    {
        if (! $profile->logistics_company_id || ! $profile->assigned_hub_id) {
            throw new DomainException('A logistics company and working hub must be assigned before going on duty.');
        }

        if (! $profile->company?->is_active || $profile->company->status !== 'active' || ! $profile->hub?->is_active) {
            throw new DomainException('Your assigned logistics company or working hub is not active.');
        }
    }
}
