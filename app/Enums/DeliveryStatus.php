<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    // Canonical Stages
    case UNASSIGNED = 'unassigned';
    case ASSIGNED_PICKUP = 'assigned_pickup';
    case PICKED_UP = 'picked_up';
    case AT_SORTING_CENTER = 'at_sorting_center';
    case SORTED = 'sorted';
    case ASSIGNED_TO_RIDER = 'assigned_to_rider';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case RETURNED = 'returned';

    // Legacy Aliases
    case ASSIGNED = 'assigned';
    case IN_TRANSIT = 'in_transit';
    case DELIVERY_FAILED = 'delivery_failed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::UNASSIGNED => 'Awaiting Courier Assignment',
            self::ASSIGNED_PICKUP, self::ASSIGNED => 'Courier Assigned for Pickup',
            self::PICKED_UP => 'Picked Up from Seller',
            self::AT_SORTING_CENTER, self::IN_TRANSIT => 'At Central Sorting Center',
            self::SORTED => 'Sorted to Destination Bin',
            self::ASSIGNED_TO_RIDER => 'Assigned to Area Rider',
            self::OUT_FOR_DELIVERY => 'Out for Doorstep Delivery',
            self::DELIVERED => 'Delivered Successfully',
            self::FAILED, self::DELIVERY_FAILED => 'Delivery Attempt Failed',
            self::RETURNED => 'Returned to Hub/Seller',
            self::CANCELLED => 'Delivery Cancelled',
        };
    }

    public static function normalize(string|self $status): string
    {
        if ($status instanceof self) {
            return $status->value;
        }

        $raw = strtolower(trim($status));
        return match($raw) {
            'unassigned' => self::UNASSIGNED->value,
            'assigned_pickup' => self::ASSIGNED_PICKUP->value,
            'assigned' => self::ASSIGNED->value,
            'picked_up' => self::PICKED_UP->value,
            'at_sorting_center' => self::AT_SORTING_CENTER->value,
            'in_transit' => self::IN_TRANSIT->value,
            'sorted' => self::SORTED->value,
            'assigned_to_rider' => self::ASSIGNED_TO_RIDER->value,
            'out_for_delivery' => self::OUT_FOR_DELIVERY->value,
            'delivered' => self::DELIVERED->value,
            'failed' => self::FAILED->value,
            'delivery_failed' => self::DELIVERY_FAILED->value,
            'returned' => self::RETURNED->value,
            'cancelled' => self::CANCELLED->value,
            default => $raw,
        };
    }

    public static function getEquivalentStatuses(string|self $status): array
    {
        $val = $status instanceof self ? $status->value : strtolower(trim((string) $status));

        return match($val) {
            'unassigned' => ['unassigned'],
            'assigned' => ['assigned', 'assigned_pickup', 'assigned_to_rider'],
            'assigned_pickup' => ['assigned_pickup', 'assigned'],
            'assigned_to_rider' => ['assigned_to_rider', 'assigned'],
            'picked_up' => ['picked_up'],
            'in_transit' => ['in_transit', 'at_sorting_center', 'sorted'],
            'at_sorting_center' => ['at_sorting_center', 'in_transit'],
            'sorted' => ['sorted', 'in_transit'],
            'out_for_delivery' => ['out_for_delivery'],
            'delivered' => ['delivered'],
            'failed', 'delivery_failed' => ['failed', 'delivery_failed'],
            'returned' => ['returned'],
            'cancelled' => ['cancelled'],
            default => [$val],
        };
    }
}
