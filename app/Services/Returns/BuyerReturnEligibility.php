<?php

namespace App\Services\Returns;

use App\Models\Order;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Logistics\PickupClaimService;
use DomainException;
use Illuminate\Validation\ValidationException;

class BuyerReturnEligibility
{
    public function source(Order $order): array
    {
        $parcel = $order->delivery;
        if (! in_array($order->status, ['delivered', 'completed'], true) || $order->payment_method !== 'cod'
            || ! $parcel || ! $parcel->delivered_at || $parcel->delivered_at->isFuture()) {
            $this->reject('Returns are available for seven days after recorded delivery or hub collection.');
        }
        $type = $parcel->status === 'customer_collected' ? 'customer_collected' : 'delivered';
        $check = $parcel->checkpoints()->whereNull('source_checkpoint_id')->where('checkpoint_type', $type)->first();
        $custody = $check?->custody_after;
        $physical = $type === 'customer_collected'
            ? app(PickupClaimService::class)->hasCollectionEvidence($parcel)
            : $parcel->status === 'delivered' && $check?->actor_role === 'courier'
                && $check->scanned_by_id === $parcel->assigned_rider_id && $check->proof_image
                && ($check->source_state['delivery_status'] ?? null) === 'out_for_delivery';
        if (! $physical || ! $check || $check->barcode_scanned !== $parcel->tracking_number
            || ($check->target_state['order_status'] ?? null) !== 'delivered'
            || ($custody['kind'] ?? null) !== 'buyer' || ($custody['user_id'] ?? null) !== $order->buyer_id
            || ! $check->created_at->equalTo($parcel->delivered_at)) {
            $this->reject('The original delivery receipt needs review before a return can be requested.');
        }
        $until = $parcel->delivered_at->copy()->addDays(7);
        if (now()->greaterThan($until)) {
            $this->reject('The seven-day return period for this delivery has ended.');
        }
        try {
            $route = app(DeliveryReturnService::class)->evidencedForwardRoute($parcel, $check->id);
        } catch (DomainException) {
            $this->reject('The original hub receipts need review before return transport.');
        }

        return ['checkpoint' => $check, 'delivered_at' => $parcel->delivered_at, 'request_until' => $until, 'route' => $route];
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['return' => $message]);
    }
}
