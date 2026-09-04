<?php

namespace App\Enums;

enum OrderStatus: string
{
    // Canonical 13 Stages
    case PLACED = 'placed';
    case CONFIRMED = 'confirmed';
    case PREPARING = 'preparing';
    case READY_FOR_PICKUP = 'ready_for_pickup';
    case PICKED_UP = 'picked_up';
    case AT_SORTING_CENTER = 'at_sorting_center';
    case SORTED = 'sorted';
    case ASSIGNED_TO_RIDER = 'assigned_to_rider';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case COMPLETED = 'completed';
    case DELIVERY_FAILED = 'delivery_failed';
    case RETURNED = 'returned';

    // Legacy Aliases
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::PLACED, self::PENDING => 'Order Placed',
            self::CONFIRMED => 'Order Confirmed',
            self::PREPARING, self::PROCESSING => 'Preparing Order',
            self::READY_FOR_PICKUP => 'Ready for Pickup',
            self::PICKED_UP => 'Parcel Picked Up',
            self::AT_SORTING_CENTER => 'At Central Sorting Center',
            self::SORTED => 'Sorted for Destination',
            self::ASSIGNED_TO_RIDER => 'Assigned to Delivery Rider',
            self::OUT_FOR_DELIVERY, self::SHIPPED => 'Out for Delivery',
            self::DELIVERED => 'Delivered to Customer',
            self::COMPLETED => 'Order Completed',
            self::DELIVERY_FAILED => 'Delivery Attempt Failed',
            self::RETURNED => 'Returned to Sender',
            self::CANCELLED => 'Order Cancelled',
        };
    }

    public static function normalize(string|self $status): string
    {
        if ($status instanceof self) {
            return $status->value;
        }

        $raw = strtolower(trim($status));
        return match($raw) {
            'placed' => self::PLACED->value,
            'confirmed' => self::CONFIRMED->value,
            'preparing', 'packaging' => self::PREPARING->value,
            'ready_for_pickup' => self::READY_FOR_PICKUP->value,
            'picked_up' => self::PICKED_UP->value,
            'at_sorting_center' => self::AT_SORTING_CENTER->value,
            'sorted' => self::SORTED->value,
            'assigned_to_rider' => self::ASSIGNED_TO_RIDER->value,
            'out_for_delivery' => self::OUT_FOR_DELIVERY->value,
            'delivered' => self::DELIVERED->value,
            'completed' => self::COMPLETED->value,
            'delivery_failed' => self::DELIVERY_FAILED->value,
            'returned' => self::RETURNED->value,
            'pending' => self::PENDING->value,
            'processing' => self::PROCESSING->value,
            'shipped' => self::SHIPPED->value,
            'cancelled' => self::CANCELLED->value,
            default => $raw,
        };
    }

    public static function getEquivalentStatuses(string|self $status): array
    {
        $val = $status instanceof self ? $status->value : strtolower(trim((string) $status));

        return match($val) {
            'placed', 'pending' => ['placed', 'pending'],
            'confirmed' => ['confirmed'],
            'preparing', 'processing', 'packaging' => ['preparing', 'processing', 'packaging'],
            'ready_for_pickup' => ['ready_for_pickup'],
            'picked_up' => ['picked_up', 'shipped'],
            'at_sorting_center' => ['at_sorting_center', 'shipped'],
            'sorted' => ['sorted', 'shipped'],
            'assigned_to_rider' => ['assigned_to_rider', 'shipped'],
            'out_for_delivery' => ['out_for_delivery', 'shipped'],
            'shipped' => ['shipped', 'picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery'],
            'delivered' => ['delivered'],
            'completed' => ['completed'],
            'delivery_failed', 'failed' => ['delivery_failed', 'failed'],
            'returned' => ['returned'],
            'cancelled' => ['cancelled'],
            default => [$val],
        };
    }
}
