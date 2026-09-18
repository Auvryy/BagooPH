<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;

class OrderStateMachineService
{
    /**
     * Complete lifecycle statuses for BagooPH logistics and orders.
     */
    public const STATUS_PLACED                     = 'placed';
    public const STATUS_CONFIRMED                  = 'confirmed';
    public const STATUS_PREPARING                  = 'preparing';
    public const STATUS_READY_FOR_PICKUP           = 'ready_for_pickup';
    public const STATUS_PICKED_UP                  = 'picked_up';
    public const STATUS_ARRIVED_AT_ORIGIN_HUB      = 'arrived_at_origin_hub';
    public const STATUS_IN_TRANSIT_TO_MOTHER_HUB   = 'in_transit_to_mother_hub';
    public const STATUS_ARRIVED_AT_MOTHER_HUB      = 'arrived_at_mother_hub';
    public const STATUS_SORTED_TO_LINE_HAUL        = 'sorted_to_line_haul';
    public const STATUS_IN_TRANSIT_TO_DEST_HUB     = 'in_transit_to_destination_hub';
    public const STATUS_ARRIVED_AT_DEST_HUB        = 'arrived_at_destination_hub';
    public const STATUS_SORTED_TO_BARANGAY_BIN     = 'sorted_to_barangay_bin';
    public const STATUS_READY_FOR_HUB_PICKUP       = 'ready_for_hub_pickup';
    public const STATUS_OUT_FOR_DELIVERY           = 'out_for_delivery';
    public const STATUS_DELIVERED                  = 'delivered';
    public const STATUS_CUSTOMER_COLLECTED         = 'customer_collected';
    public const STATUS_COMPLETED                  = 'completed';
    public const STATUS_DELIVERY_FAILED            = 'delivery_failed';
    public const STATUS_RETURN_TO_SENDER           = 'return_to_sender';

    /**
     * Transition order and delivery through an authenticated scan event.
     */
    public function transition(
        Delivery $delivery,
        string $targetStatus,
        User $actor,
        array $scanMetadata = []
    ): Delivery {
        return DB::transaction(function () use ($delivery, $targetStatus, $actor, $scanMetadata) {
            $order = $delivery->order;
            $previousStatus = $delivery->status;

            $delivery->status = $targetStatus;

            // 1. Checkpoint and facility scoping
            $hubId = $scanMetadata['hub_id'] ?? $delivery->current_hub_id;
            if ($hubId) {
                $delivery->current_hub_id = $hubId;
            }

            // 2. Specific status handler actions
            switch ($targetStatus) {
                case self::STATUS_PICKED_UP:
                    $delivery->picked_up_at = now();
                    if (! $delivery->courier_id && $actor->isCourier()) {
                        $delivery->courier_id = $actor->id;
                    }
                    break;

                case self::STATUS_IN_TRANSIT_TO_MOTHER_HUB:
                    $delivery->shuttle_manifest_number = $scanMetadata['manifest_number'] ?? ('SHUTTLE-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));
                    break;

                case self::STATUS_IN_TRANSIT_TO_DEST_HUB:
                    $delivery->truck_manifest_number = $scanMetadata['manifest_number'] ?? ('TRUCK-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4)));
                    break;

                case self::STATUS_OUT_FOR_DELIVERY:
                    if (! empty($scanMetadata['rider_id'])) {
                        $delivery->assigned_rider_id = $scanMetadata['rider_id'];
                    } elseif ($actor->isCourier()) {
                        $delivery->assigned_rider_id = $actor->id;
                    }
                    break;

                case self::STATUS_DELIVERED:
                case self::STATUS_CUSTOMER_COLLECTED:
                    $delivery->delivered_at = now();
                    $delivery->pod_latitude = $scanMetadata['latitude'] ?? null;
                    $delivery->pod_longitude = $scanMetadata['longitude'] ?? null;
                    $delivery->pod_geofence_verified = (bool)($scanMetadata['geofence_verified'] ?? false);
                    $delivery->proof_image = $scanMetadata['proof_image'] ?? $delivery->proof_image;
                    $delivery->pod_signature = $scanMetadata['pod_signature'] ?? null;
                    $order->status = ($targetStatus === self::STATUS_CUSTOMER_COLLECTED) ? self::STATUS_CUSTOMER_COLLECTED : self::STATUS_DELIVERED;
                    break;

                case self::STATUS_COMPLETED:
                    $order->status = self::STATUS_COMPLETED;
                    break;

                case self::STATUS_DELIVERY_FAILED:
                    $delivery->failure_attempts += 1;
                    $delivery->failure_reason = $scanMetadata['reason'] ?? 'Customer Unreachable';
                    $order->status = self::STATUS_DELIVERY_FAILED;

                    // If max re-attempts exceeded (3 total attempts), trigger Return-To-Sender
                    if ($delivery->failure_attempts >= 3) {
                        $delivery->status = self::STATUS_RETURN_TO_SENDER;
                        $order->status = self::STATUS_RETURN_TO_SENDER;
                    }
                    break;

                case self::STATUS_RETURN_TO_SENDER:
                    $order->status = self::STATUS_RETURN_TO_SENDER;
                    break;

                default:
                    // Keep order status aligned with key milestones
                    if (in_array($targetStatus, [self::STATUS_CONFIRMED, self::STATUS_PREPARING, self::STATUS_READY_FOR_PICKUP], true)) {
                        $order->status = $targetStatus;
                    }
                    break;
            }

            $delivery->save();
            $order->save();

            // 3. Record unbroken digital audit checkpoint
            $hub = $hubId ? LogisticsHub::find($hubId) : null;
            DeliveryCheckpoint::record(
                delivery: $delivery,
                type: $targetStatus,
                location: $scanMetadata['location_name'] ?? $hub?->name ?? 'Mobile Terminal',
                notes: $scanMetadata['notes'] ?? ("Status transitioned from [{$previousStatus}] to [{$targetStatus}]"),
                actor: $actor,
                proofImage: $scanMetadata['proof_image'] ?? null,
                hub: $hub,
                facilityCode: $scanMetadata['facility_code'] ?? $hub?->code,
                latitude: $scanMetadata['latitude'] ?? null,
                longitude: $scanMetadata['longitude'] ?? null,
                manifestNumber: $scanMetadata['manifest_number'] ?? null
            );

            return $delivery;
        });
    }
}
