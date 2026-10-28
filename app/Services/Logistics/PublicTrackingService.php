<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Services\Orders\OrderTrackingService;

class PublicTrackingService
{
    private const CHECKPOINT_LOCATIONS = [
        'handover_fulfillment_confirmed' => 'Seller fulfillment confirmed during recovery',
        'handover_holder_acknowledged' => 'Current rider possession confirmed',
        'handover_recovery_received' => 'Actual recovery receipt at the named hub',
        'parcel_recovery_intake' => 'Parcel received for route recovery',
        'parcel_recovery_departed' => 'Parcel travelling on its recovery route',
        'parcel_recovery_received' => 'Recovery transfer received at a sorting hub',
        'failed_delivery_hub_return' => 'Destination Bayan Hub',
        'delivery_rescheduled' => 'Destination Bayan Hub',
        'expired_pickup_return_started' => 'Destination Bayan Hub',
        'order_placed' => 'BagooPH',
        'placed' => 'BagooPH',
        'confirmed' => 'Seller preparation',
        'preparing' => 'Seller preparation',
        'seller_pack' => 'Seller preparation',
        'ready_for_pickup' => 'Seller preparation',
        'assigned_pickup' => 'Seller pickup',
        'courier_pickup' => 'Seller pickup',
        'picked_up' => 'Seller pickup',
        'arrived_at_origin_hub' => 'Origin Bayan Hub',
        'in_transit_to_mother_hub' => 'Transfer to Mother Hub',
        'arrived_at_mother_hub' => 'Mother Hub',
        'sorted_to_line_haul' => 'Mother Hub',
        'in_transit_to_destination_hub' => 'Transfer to destination Bayan Hub',
        'arrived_at_destination_hub' => 'Destination Bayan Hub',
        'sorted_to_barangay_bin' => 'Destination Bayan Hub',
        'ready_for_hub_pickup' => 'Destination Bayan Hub counter',
        'assigned_to_rider' => 'Destination Bayan Hub',
        'out_for_delivery' => 'Delivery area',
        'delivered' => 'Recipient handoff',
        'customer_collected' => 'Destination Bayan Hub counter',
        'doorstep_handover' => 'Recipient handoff',
        'completed' => 'Buyer receipt confirmed',
        'buyer_confirmed' => 'Buyer receipt confirmed',
        'buyer_completed' => 'Buyer receipt confirmed',
        'delivery_completed' => 'Order completed after delivery',
        'delivery_failed' => 'Delivery area',
        'return_to_sender' => 'Return hub receipt',
        'return_in_transit' => 'Return hub transfer',
        'seller_return_staged' => 'Awaiting seller return receipt',
        'parcel_returned' => 'Seller return handoff',
        'returned' => 'Seller return handoff',
        'cancelled' => 'Order cancelled',
    ];

    public function find(string $trackingCode): ?array
    {
        $delivery = Delivery::query()
            ->with(['order', 'checkpoints' => fn ($query) => $query->whereNull('source_checkpoint_id')->oldest('created_at')->orderBy('id')])
            ->where('tracking_number', $trackingCode)
            ->first();

        if (! $delivery) {
            return null;
        }

        // The public surface has the same privacy boundary for every account role.
        $name = $delivery->delivery_recipient_name ?: $delivery->order?->recipient_name;
        $phone = $delivery->delivery_phone ?: $delivery->order?->recipient_phone;
        $area = implode(', ', array_filter([
            $delivery->order?->shipping_city,
            $delivery->order?->shipping_province,
        ]));
        $status = OrderTrackingService::commercialStatus($delivery->order, $delivery);

        return [
            'tracking_number' => $delivery->tracking_number,
            'status' => $status,
            'order_status' => $status,
            'delivery_recipient_name' => $this->maskName($name),
            'delivery_phone' => $this->maskPhone($phone),
            'delivery_address' => $area !== '' ? 'Protected location, '.$area : 'Delivery area protected',
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
            'delivered_at' => $delivery->delivered_at?->toISOString(),
            'checkpoints' => $delivery->checkpoints
                ->filter(fn (DeliveryCheckpoint $checkpoint) => isset(self::CHECKPOINT_LOCATIONS[$checkpoint->checkpoint_type]))
                ->map(fn (DeliveryCheckpoint $checkpoint) => [
                    'checkpoint_type' => $checkpoint->checkpoint_type,
                    'location_name' => self::CHECKPOINT_LOCATIONS[$checkpoint->checkpoint_type],
                    'created_at' => $checkpoint->created_at->toISOString(),
                ])->values()->all(),
        ];
    }

    private function maskName(?string $name): string
    {
        if (! $name || trim($name) === '') {
            return 'Recipient protected';
        }

        return implode(' ', array_map(
            fn (string $part) => mb_substr($part, 0, 1).'***',
            preg_split('/\s+/u', trim($name))
        ));
    }

    private function maskPhone(?string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone ?? '');

        return strlen($digits) >= 10 ? '••• ••• '.substr($digits, -4) : 'Phone protected';
    }
}
