<?php

namespace App\Services\Orders;

use App\Models\Order;

class SellerOrderDisclosureService
{
    /** Redact response models only; preserve the original purchased recipient and waybill. */
    public function available(Order $order): bool
    {
        return in_array($order->status, ['confirmed', 'preparing', 'processing', 'packaging', 'ready_for_pickup',
            'picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'delivered', 'completed',
            'delivery_failed', 'returned', 'shipped', 'in_transit', 'failed'], true);
    }

    public function apply(Order $order): void
    {
        $available = $this->available($order);
        $order->setAttribute('recipient_details_available', $available);
        if ($available) {
            return;
        }
        $order->setAttribute('can_print_waybill', false);
        foreach (['recipient_phone', 'shipping_address', 'shipping_postal_code', 'destination_barangay',
            'destination_latitude', 'destination_longitude', 'notes'] as $field) {
            $order->setAttribute($field, null);
        }
        if ($parcel = $order->delivery) {
            foreach (['tracking_number', 'delivery_address', 'delivery_phone', 'destination_bin',
                'pod_latitude', 'pod_longitude', 'pod_signature', 'courier_notes', 'failure_reason'] as $field) {
                $parcel->setAttribute($field, null);
            }
        }
        $tracking = $order->getAttribute('tracking');
        if (is_array($tracking)) {
            $tracking['failure'] = null;
            // Seller-origin preparation labels carry no recipient address.
            $tracking['history'] = array_map(fn ($entry) => in_array($entry['type'] ?? null,
                ['placed', 'order_placed', 'confirmed', 'preparing', 'seller_pack', 'ready_for_pickup', 'cancelled'], true)
                ? $entry : [...$entry, 'location' => 'Seller preparation'], $tracking['history'] ?? []);
            $order->setAttribute('tracking', $tracking);
        }
    }
}
