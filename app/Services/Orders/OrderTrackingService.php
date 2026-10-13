<?php

namespace App\Services\Orders;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\HandoverHoldService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Vouchers\VoucherReceiptService;

class OrderTrackingService
{
    public const LABELS = [
        'placed' => 'Order placed', 'confirmed' => 'Seller confirmed', 'preparing' => 'Seller preparing',
        'ready_for_pickup' => 'Ready for pickup', 'picked_up' => 'Picked up from seller',
        'at_sorting_center' => 'Moving through the hub network', 'sorted' => 'Sorted at destination hub',
        'assigned_to_rider' => 'Assigned to delivery rider', 'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered · awaiting buyer confirmation', 'completed' => 'Buyer confirmed receipt',
        'delivery_failed' => 'Delivery issue', 'returned' => 'Returned to seller', 'cancelled' => 'Order cancelled',
        'unknown' => 'Order status needs review',
    ];

    public const STAGES = [
        'unassigned' => 'Pickup not assigned', 'assigned' => 'Pickup rider assigned',
        'assigned_pickup' => 'Pickup rider assigned', 'picked_up' => 'Collected from seller',
        'arrived_at_origin_hub' => 'Received at origin Bayan Hub', 'at_sorting_center' => 'At a sorting hub',
        'in_transit' => 'Travelling through the hub network', 'in_transit_to_mother_hub' => 'Travelling to Mother Hub',
        'arrived_at_mother_hub' => 'Received at Mother Hub', 'sorted_to_line_haul' => 'Sorted for the next hub transfer',
        'in_transit_to_destination_hub' => 'Travelling to destination Bayan Hub',
        'arrived_at_destination_hub' => 'Received at destination Bayan Hub', 'sorted' => 'Sorted at destination hub',
        'sorted_to_barangay_bin' => 'Sorted for the delivery area',
        'ready_for_hub_pickup' => 'Staged for secure hub collection',
        'assigned_to_rider' => 'Delivery rider assigned; awaiting departure', 'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Doorstep handover recorded', 'customer_collected' => 'Collected at hub counter',
        'completed' => 'Buyer receipt confirmed', 'failed' => 'Delivery attempt failed',
        'delivery_failed' => 'Delivery attempt failed', 'return_to_sender' => 'Returning to seller',
        'return_in_transit' => 'Travelling on the return route', 'returned' => 'Seller return receipt recorded',
        'cancelled' => 'Delivery cancelled', 'parcel_recovery_held' => 'Held for route recovery',
        'parcel_recovery_in_transit' => 'Travelling on a recovery route',
    ];

    public const EVENTS = [
        'handover_fulfillment_confirmed' => 'Seller confirmed an omitted fulfillment step now',
        'handover_holder_acknowledged' => 'Rider confirmed current possession during recovery',
        'handover_recovery_received' => 'Actual recovery receipt recorded at the named hub',
        'order_placed' => 'Order placed', 'placed' => 'Order placed', 'confirmed' => 'Seller confirmed',
        'preparing' => 'Seller preparation started', 'seller_pack' => 'Seller preparation recorded',
        'ready_for_pickup' => 'Seller marked the parcel ready for pickup', 'assigned_pickup' => 'Pickup rider assigned',
        'courier_pickup' => 'Collected from seller', 'picked_up' => 'Collected from seller',
        'arrived_at_origin_hub' => 'Received at origin Bayan Hub', 'in_transit_to_mother_hub' => 'Departed for Mother Hub',
        'arrived_at_mother_hub' => 'Received at Mother Hub', 'sorted_to_line_haul' => 'Sorted at Mother Hub',
        'in_transit_to_destination_hub' => 'Departed for destination Bayan Hub',
        'arrived_at_destination_hub' => 'Received at destination Bayan Hub',
        'sorted_to_barangay_bin' => 'Sorted for the delivery area', 'ready_for_hub_pickup' => 'Staged for secure hub collection',
        'assigned_to_rider' => 'Delivery rider assigned', 'out_for_delivery' => 'Delivery rider departed',
        'delivered' => 'Doorstep handover recorded', 'customer_collected' => 'Collected at hub counter',
        'doorstep_handover' => 'Recipient handover recorded', 'completed' => 'Buyer confirmed receipt',
        'buyer_confirmed' => 'Buyer confirmed receipt', 'buyer_completed' => 'Buyer confirmed receipt',
        'delivery_failed' => 'Delivery attempt failed', 'failed_delivery_hub_return' => 'Failed delivery returned to destination hub',
        'delivery_rescheduled' => 'Approved retry released for sorting',
        'expired_pickup_return_started' => 'Uncollected parcel entered the return route',
        'return_to_sender' => 'Return received at a sorting hub', 'return_in_transit' => 'Departed on the return route',
        'seller_return_staged' => 'Return ready for the seller receipt scan',
        'parcel_returned' => 'Seller received the returned parcel', 'returned' => 'Seller received the returned parcel',
        'parcel_recovery_intake' => 'Parcel received for route recovery',
        'parcel_recovery_departed' => 'Departed on the recovery route',
        'parcel_recovery_received' => 'Recovery transfer received at a sorting hub', 'cancelled' => 'Order cancelled',
    ];

    public static function relations(bool $history = true): array
    {
        $relations = ['delivery.latestCustodyCheckpoint.hub:id,name', 'delivery.latestOriginalCheckpoint',
            'delivery.latestAttempt.events', 'delivery.pickupClaim', 'delivery.destinationBayanHub'];
        if ($history) {
            $relations['delivery.checkpoints'] = fn ($query) => $query->whereNull('source_checkpoint_id')
                ->with('hub:id,name')->orderBy('created_at')->orderBy('id');
        }

        return $relations;
    }

    public static function commercialStatus(?Order $order, ?Delivery $parcel): string
    {
        $status = match ($order?->status) {
            'pending' => 'placed', 'processing', 'packaging' => 'preparing', 'failed' => 'delivery_failed',
            'canceled' => 'cancelled', default => $order?->status,
        };
        if (isset(self::LABELS[$status ?? '']) && $status !== 'unknown') {
            return $status;
        }
        if (! in_array($status, ['shipped', 'in_transit'], true)) {
            return 'unknown';
        }

        return match ($parcel?->status) {
            'picked_up' => 'picked_up',
            'arrived_at_origin_hub', 'in_transit_to_mother_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul',
            'in_transit_to_destination_hub', 'arrived_at_destination_hub', 'at_sorting_center', 'in_transit' => 'at_sorting_center',
            'sorted', 'sorted_to_barangay_bin', 'ready_for_hub_pickup' => 'sorted',
            'assigned_to_rider' => 'assigned_to_rider', 'out_for_delivery' => 'out_for_delivery',
            'delivered', 'customer_collected' => 'delivered',
            'failed', 'delivery_failed', 'return_to_sender', 'return_in_transit' => 'delivery_failed',
            'returned' => 'returned', 'cancelled' => 'cancelled', default => 'unknown',
        };
    }

    public static function label(?Order $order, ?Delivery $parcel): string
    {
        return self::LABELS[self::commercialStatus($order, $parcel)];
    }

    public function present(Order $order, bool $history = true, ?Delivery $parcel = null): array
    {
        $parcel ??= $order->delivery;
        $status = self::commercialStatus($order, $parcel);
        $checkpoint = $parcel?->latestCustodyCheckpoint;
        $custody = $checkpoint?->custody_after ?? ['kind' => 'unknown'];
        $notice = $this->inconsistency($order, $parcel, $custody);
        [$step, $responsible] = $this->next($order, $parcel, $status, $custody);
        if ($notice) {
            [$step, $responsible] = ['Refresh tracking. Contact support if the records still need review.', 'Bagoo support'];
        }
        if ($parcel && app(HandoverHoldService::class)->held($parcel)) {
            $notice = 'This parcel is on a reviewed handover hold. Ordinary movement is paused until its authorized recovery receipt.';
            [$step, $responsible] = ['Await supervised handover recovery and the actual receiving-hub scan.', 'Logistics company and named holder'];
        }

        return [
            'commercial_status' => $status, 'status_label' => self::LABELS[$status],
            'stage_label' => $parcel ? (self::STAGES[$parcel->status] ?? 'Parcel stage needs review') : 'Parcel record not available',
            'tone' => $notice || in_array($status, ['delivery_failed', 'returned', 'cancelled', 'unknown'], true)
                ? 'attention' : (in_array($status, ['delivered', 'completed'], true) ? 'success' : 'progress'),
            'custodian' => $this->holder($order, $parcel, $checkpoint, $custody),
            'custody_recorded_at' => $checkpoint?->created_at?->toIso8601String(),
            'next_step' => $step, 'responsible' => $responsible, 'notice' => $notice,
            'observed_at' => now()->toIso8601String(),
            'history' => $history && $parcel ? $parcel->checkpoints->filter(fn ($event) => $event->source_checkpoint_id === null && isset(self::EVENTS[$event->checkpoint_type]))
                ->sortBy([['created_at', 'asc'], ['id', 'asc']])->map(fn ($event) => [
                    'id' => $event->id, 'label' => self::EVENTS[$event->checkpoint_type],
                    'location' => $event->hub?->name ?: ($event->location_name ?: 'Location not recorded'),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values()->all() : [],
            'failure' => $this->failure($parcel),
        ];
    }

    /** Keep internal evidence and private attempt records out of serialized order props. */
    public function attach(Order $order, bool $history = true): void
    {
        $order->setAttribute('tracking', $this->present($order, $history));
        $order->setAttribute('voucher_receipt', app(VoucherReceiptService::class)->present($order));
        foreach (['checkpoints', 'latestCustodyCheckpoint', 'latestOriginalCheckpoint', 'latestAttempt', 'pickupClaim', 'destinationBayanHub'] as $relation) {
            $order->delivery?->unsetRelation($relation);
        }
    }

    public function canShowPickupCode(Order $order): bool
    {
        $parcel = $order->delivery;
        $claim = $parcel?->pickupClaim;
        $hub = $parcel?->destinationBayanHub;

        return $parcel && $claim && $hub && ! $claim->code_hash && ! $claim->code_issued_at
            && ! $this->inconsistency($order, $parcel, $parcel->latestCustodyCheckpoint?->custody_after ?? [])
            && app(PickupClaimService::class)->collectionBlocker($parcel, $hub) === null;
    }

    private function inconsistency(Order $order, ?Delivery $parcel, array $custody): ?string
    {
        $status = self::commercialStatus($order, $parcel);
        if ($status === 'unknown' || ($parcel && ! isset(self::STAGES[$parcel->status]))) {
            return 'Some tracking records need review. No new parcel progress is assumed.';
        }
        if (! $parcel) {
            return 'The parcel record is not available. Your recorded order is retained.';
        }
        $expected = match ($parcel->status) {
            'unassigned', 'assigned', 'assigned_pickup' => ['placed', 'confirmed', 'preparing', 'ready_for_pickup', 'cancelled'],
            'picked_up' => ['picked_up'],
            'arrived_at_origin_hub', 'in_transit_to_mother_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul',
            'in_transit_to_destination_hub', 'arrived_at_destination_hub', 'at_sorting_center', 'in_transit' => ['at_sorting_center'],
            'sorted', 'sorted_to_barangay_bin', 'ready_for_hub_pickup' => ['sorted'],
            'assigned_to_rider' => ['assigned_to_rider'], 'out_for_delivery' => ['out_for_delivery'],
            'failed', 'delivery_failed', 'return_to_sender', 'return_in_transit' => ['delivery_failed'],
            'delivered', 'customer_collected' => ['delivered', 'completed'], 'completed' => ['completed'],
            'returned' => ['returned'], 'cancelled' => ['cancelled'], default => null,
        };
        if ($expected && ! in_array($status, $expected, true)) {
            return 'The order state and parcel stage differ. Their recorded history is retained for review.';
        }
        $kind = $custody['kind'] ?? 'unknown';
        if (! in_array($kind, ['seller', 'courier', 'hub', 'manifest', 'buyer'], true)
            && ! in_array($parcel->status, ['unassigned', 'assigned', 'assigned_pickup', 'cancelled'], true)) {
            return 'The parcel handover is not recorded. Its holder and next handover need review.';
        }
        $holders = match ($parcel->status) {
            'picked_up', 'out_for_delivery' => ['courier'],
            'arrived_at_origin_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul', 'arrived_at_destination_hub',
            'sorted_to_barangay_bin', 'ready_for_hub_pickup', 'assigned_to_rider', 'parcel_recovery_held' => ['hub'],
            'in_transit_to_mother_hub', 'in_transit_to_destination_hub', 'return_in_transit', 'parcel_recovery_in_transit' => ['manifest'],
            'delivered', 'customer_collected', 'completed' => ['buyer'],
            'delivery_failed', 'failed' => ['courier', 'hub'], 'return_to_sender' => ['hub'], 'returned' => ['seller'],
            default => null,
        };
        if ($holders && ! in_array($kind, $holders, true)) {
            return 'The parcel stage and retained handover differ. The recorded holder needs review.';
        }
        if ($kind === 'hub' && (! isset($custody['hub_id']) || $custody['hub_id'] !== $parcel->current_hub_id)) {
            return 'The recorded holder and current facility differ. Assignment does not confirm a handover.';
        }
        $riders = $parcel->status === 'picked_up' ? [$parcel->courier_id]
            : (in_array($parcel->status, ['out_for_delivery', 'delivery_failed', 'failed'], true) ? [$parcel->assigned_rider_id] : [$parcel->courier_id, $parcel->assigned_rider_id]);
        if ($kind === 'courier' && ! in_array($custody['user_id'] ?? null, array_filter($riders), true)) {
            return 'The retained rider handover does not match the current assignment. The recorded holder needs review.';
        }
        if ($kind === 'buyer' && ($custody['user_id'] ?? null) !== $order->buyer_id) {
            return 'The recipient handover needs review against this order.';
        }

        return null;
    }

    private function holder(Order $order, ?Delivery $parcel, ?DeliveryCheckpoint $checkpoint, array $custody): string
    {
        return match ($custody['kind'] ?? 'unknown') {
            'hub' => ($checkpoint?->hub_id === ($custody['hub_id'] ?? null) && $checkpoint?->hub)
                ? $checkpoint->hub->name : 'Sorting hub (recorded handover)',
            'courier' => match (true) {
                ! isset($custody['user_id']) => 'Recorded courier; assignment needs review',
                in_array($parcel?->status, ['out_for_delivery', 'delivery_failed', 'failed'], true)
                    && $custody['user_id'] === $parcel?->assigned_rider_id => 'Delivery rider (handover recorded)',
                $custody['user_id'] === $parcel?->courier_id => 'Pickup rider (handover recorded)',
                $custody['user_id'] === $parcel?->assigned_rider_id => 'Delivery rider (handover recorded)',
                default => 'Recorded courier; assignment needs review',
            },
            'manifest' => 'Hub transport (handover recorded)',
            'buyer' => ($custody['user_id'] ?? null) === $order->buyer_id ? 'Buyer (handover recorded)' : 'Recorded recipient needs review',
            'seller' => 'Seller (handover recorded)', default => 'Parcel holder not recorded',
        };
    }

    private function next(Order $order, ?Delivery $parcel, string $status, array $custody): array
    {
        if ($status === 'cancelled') {
            return ['This order was cancelled. No further delivery is scheduled.', 'No delivery action'];
        }
        if ($status === 'completed') {
            return ['Receipt is confirmed. Your order history remains available.', 'No further receipt action'];
        }
        if ($status === 'returned') {
            return ['The seller received the returned parcel. The recorded return journey is retained.', 'No delivery action'];
        }
        if ($status === 'delivered') {
            return [$parcel?->status === 'customer_collected'
                ? 'Hub collection is recorded. The buyer can inspect the items and confirm receipt.'
                : 'Delivery is recorded. The buyer can inspect the items and confirm receipt.', 'Buyer'];
        }
        if (in_array($parcel?->status, ['parcel_recovery_held', 'parcel_recovery_in_transit'], true)) {
            return ['Logistics is recovering the parcel onto its recorded route. Follow the retained recovery updates.', 'Logistics company and assigned handlers'];
        }
        if ($status === 'delivery_failed') {
            if ($parcel?->status === 'return_in_transit') {
                return ['The parcel is travelling back through the hub network. Await the next recorded receipt.', 'Receiving hub'];
            }
            if ($parcel?->status === 'return_to_sender') {
                $staged = $parcel->latestOriginalCheckpoint?->checkpoint_type === 'seller_return_staged';

                return [$staged ? 'The owning seller must scan and confirm return receipt.' : 'Logistics is continuing the return through the recorded hub route.', $staged ? 'Seller' : 'Hub staff'];
            }
            $failure = $this->failure($parcel);
            if (($custody['kind'] ?? null) === 'courier') {
                return ['The rider must return the parcel to the destination hub for a recorded receipt and review.', 'Delivery rider'];
            }
            if ($failure && $failure['retry_at']) {
                return ['A retry is approved for the recorded date. The hub must release it before another delivery starts.', 'Destination hub'];
            }

            return ['The destination hub must review the failed attempt and record the permitted retry or return.', 'Destination hub'];
        }

        return match ($status) {
            'placed' => ['The seller will review and confirm the order.', 'Seller'],
            'confirmed' => ['The seller will prepare the items and waybill.', 'Seller'],
            'preparing' => ['The seller must finish preparation and mark the parcel ready for pickup.', 'Seller'],
            'ready_for_pickup' => [$parcel && in_array($parcel->status, ['assigned', 'assigned_pickup'], true)
                ? 'The assigned pickup rider must collect and scan the parcel at the seller.' : 'Await an eligible pickup rider. Assignment alone does not record collection.', 'Pickup rider'],
            'picked_up' => ['The pickup rider must bring the parcel to the origin Bayan Hub for its intake scan.', 'Pickup rider and origin hub'],
            'at_sorting_center' => ['Hub staff will record the next sorting or transfer on the Bayan-Hub and Mother-Hub route.', 'Hub staff'],
            'sorted' => $parcel?->isSelfPickup() ? $this->counterNext($parcel)
                : ['The destination hub will assign an eligible delivery rider.', 'Destination hub'],
            'assigned_to_rider' => ['The assigned delivery rider must scan the parcel out of the destination hub.', 'Delivery rider'],
            'out_for_delivery' => ['Await the assigned rider. Prepare the exact COD amount when payment is due.', 'Delivery rider and buyer'],
            default => ['Refresh tracking or contact support for a review of the recorded state.', 'Bagoo support'],
        };
    }

    private function counterNext(Delivery $parcel): array
    {
        $claim = $parcel->pickupClaim;
        if ($parcel->status !== 'ready_for_hub_pickup' || ! $claim) {
            return ['The destination hub must stage the parcel before secure counter collection is available.', 'Destination hub'];
        }
        if ($claim->status === 'expired' || now()->greaterThanOrEqualTo($claim->expires_at)) {
            return ['The collection deadline has passed. Hub staff must review the uncollected parcel for return.', 'Destination hub'];
        }
        if ($claim->locked_until?->isFuture()) {
            return ['Counter verification is temporarily locked. Follow the recorded unlock time before trying again.', 'Buyer and destination hub'];
        }
        if ($claim->status !== 'ready') {
            return ['The recorded collection state needs hub review before another counter handover.', 'Destination hub'];
        }
        if (! $parcel->destinationBayanHub || app(PickupClaimService::class)->collectionBlocker($parcel, $parcel->destinationBayanHub) !== null) {
            return ['The recorded staging and collection readiness need hub review before collection.', 'Destination hub'];
        }

        return ['Collect at the destination hub before the deadline with the private claim code, photo ID and exact COD payment.', 'Buyer and counter handler'];
    }

    private function failure(?Delivery $parcel): ?array
    {
        $attempt = $parcel?->latestAttempt;
        if (! $attempt || $attempt->attempt_number !== $parcel->failure_attempts) {
            return null;
        }
        $approval = $attempt->events->firstWhere('event_type', 'retry_approved');
        $started = $attempt->events->contains('event_type', 'retry_started');

        return [
            'attempt_number' => $attempt->attempt_number,
            'reason' => DeliveryRecoveryService::REASONS[$attempt->reason_code] ?? 'Delivery failure recorded',
            'attempted_at' => $attempt->attempted_at?->toIso8601String(),
            'retry_at' => $parcel->status === 'delivery_failed' && ! $started ? $approval?->retry_at?->toIso8601String() : null,
        ];
    }
}
