<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case UNASSIGNED = 'unassigned';
    case ASSIGNED = 'assigned';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';

    public function label(): string
    {
        return match($this) {
            self::UNASSIGNED => 'Awaiting Courier Assignment',
            self::ASSIGNED => 'Courier Assigned',
            self::PICKED_UP => 'Picked Up from Seller',
            self::IN_TRANSIT => 'In Transit',
            self::OUT_FOR_DELIVERY => 'Out for Delivery',
            self::DELIVERED => 'Delivered Successfully',
            self::FAILED => 'Delivery Failed / Rescheduled',
        };
    }
}
