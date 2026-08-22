<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending Payment / Confirmation',
            self::PROCESSING => 'Processing / Packaging',
            self::READY_FOR_PICKUP => 'Ready for Pickup',
            self::SHIPPED => 'Out with Courier',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Cancelled',
        };
    }
}
