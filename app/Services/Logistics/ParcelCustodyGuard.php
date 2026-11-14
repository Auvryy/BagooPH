<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Finance\ShipmentLossReconciliationService;
use App\Services\Orders\OrderCancellationService;
use DomainException;

class ParcelCustodyGuard
{
    public function assertSource(Delivery $parcel, string $target, User $actor, ?LogisticsHub $hub): void
    {
        if (app(OrderCancellationService::class)->livePending($parcel->order)) {
            throw new DomainException('The buyer’s cancellation request is awaiting review. Preparation and pickup remain on hold.');
        }
        $lossSellerReturn = in_array($parcel->status, ['return_to_sender', 'return_in_transit'], true)
            && app(ShipmentLossReconciliationService::class)->activeForParcel($parcel)?->outcome_path === 'already_settled_seller_return';
        $expectedOrder = match ($parcel->status) {
            'assigned_pickup' => ['ready_for_pickup'], 'picked_up' => ['picked_up'],
            'arrived_at_origin_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul', 'in_transit_to_mother_hub',
            'in_transit_to_destination_hub', 'arrived_at_destination_hub' => ['at_sorting_center'],
            'sorted_to_barangay_bin', 'ready_for_hub_pickup' => ['sorted'], 'assigned_to_rider' => ['assigned_to_rider'],
            'out_for_delivery' => ['out_for_delivery'],
            'delivery_failed' => ['delivery_failed'],
            'return_to_sender', 'return_in_transit' => $lossSellerReturn ? ['cancelled'] : ['delivery_failed'],
            default => null,
        };
        if ($expectedOrder && ! in_array($parcel->order->status, $expectedOrder, true)) {
            throw new DomainException('The order and parcel custody stages disagree. Review their retained source before continuing.');
        }
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $kind = $custody['kind'] ?? 'unknown';
        if ($target === 'picked_up') {
            $shop = $parcel->order->shop;
            $historicalUnknown = $parcel->order->logistics_flow_version < 2 && $kind === 'unknown';
            if (! $historicalUnknown && ($kind !== 'seller' || ! $shop || ($custody['shop_id'] ?? null) !== $shop->id
                || ($custody['user_id'] ?? null) !== $shop->user_id || $actor->id === $shop->user_id)) {
                throw new DomainException('Pickup requires the original seller custody. Use reviewed recovery for a contradictory holder.');
            }
        } elseif ($target === 'arrived_at_origin_hub') {
            if ($kind !== 'courier' || ($custody['user_id'] ?? null) !== $parcel->courier_id
                || ! $parcel->courier_id || $actor->id === $parcel->courier_id || $parcel->current_hub_id
                || $parcel->order->status !== 'picked_up') {
                throw new DomainException('Origin receipt requires recorded pickup-rider custody and a separate receiving operator. Review the current holder before scanning.');
            }
        } elseif (in_array($target, ['sorted_to_line_haul', 'sorted_to_barangay_bin', 'assigned_to_rider'], true)) {
            if ($kind !== 'hub' || ($custody['hub_id'] ?? null) !== $hub?->id || $parcel->current_hub_id !== $hub?->id) {
                throw new DomainException('This action requires actual custody at the selected hub. An assignment or status cannot replace its receipt.');
            }
        } elseif (in_array($target, ['delivered', 'delivery_failed'], true)) {
            if ($kind !== 'courier' || ($custody['user_id'] ?? null) !== $actor->id || $parcel->current_hub_id) {
                throw new DomainException('The assigned delivery rider must hold the recorded parcel custody before its outcome can be saved.');
            }
        }
    }

    public function independentTransport(User $actor, ?int $driverId): void
    {
        if (! $driverId || $driverId === $actor->id || ! User::whereKey($driverId)->where('role', 'courier')->exists()) {
            throw new DomainException('A separate named courier driver must hold this transport handover. Hub operators cannot acknowledge their own vehicle handover.');
        }
    }
}
