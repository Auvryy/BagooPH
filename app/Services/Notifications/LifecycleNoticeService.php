<?php

namespace App\Services\Notifications;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryReturnRoute;
use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\User;
use App\Services\Logistics\DeliveryRecoveryService;

class LifecycleNoticeService
{
    public function __construct(private readonly NotificationDeliveryService $delivery) {}

    public function order(Order $order, string $milestone): void
    {
        [$title, $body] = match ($milestone) {
            'placed' => ['You have a new order', 'Review the order and prepare it for pickup.'],
            'confirmed' => ['Your order was confirmed', 'The seller has accepted your order.'],
            'preparing' => ['Your order is being prepared', 'The seller is packing your parcel.'],
            'ready_for_pickup' => ['Your order is ready for pickup', 'The parcel is waiting for an eligible pickup rider.'],
            'cancelled' => ['Your order was cancelled', 'Open the order to review its current status.'],
            'completed' => ['The buyer confirmed receipt', 'The order is completed. Cash reconciliation and seller settlement are separate steps.'],
        };
        $seller = in_array($milestone, ['placed', 'completed'], true);
        $recipientId = $seller ? $order->shop?->user_id : $order->buyer_id;
        if ($recipientId) {
            $this->store($recipientId, 'order-event', 'order:'.$order->id.':'.$milestone, [
                'title' => $title, 'body' => $body, 'milestone' => $milestone, 'order_id' => $order->id,
                'order_number' => $order->order_number, 'target' => $seller ? 'seller-orders' : 'buyer-order',
            ]);
        }
    }

    public function checkpoint(DeliveryCheckpoint $checkpoint): void
    {
        // Only newly recorded canonical custody events qualify; aliases and old fixtures do not.
        if ($checkpoint->source_checkpoint_id || ! $checkpoint->source_state || ! $checkpoint->target_state) {
            return;
        }
        $milestone = $checkpoint->checkpoint_type;
        if ($milestone === 'assigned_to_rider') {
            $recipientId = $checkpoint->target_state['assigned_rider_id'] ?? null;
            if ($recipientId) {
                $this->store((int) $recipientId, 'parcel-event', 'checkpoint:'.$checkpoint->id, [
                    'title' => 'You have a delivery assignment',
                    'body' => 'Open your assignment and scan the parcel out of the destination hub.',
                    'milestone' => $milestone, 'delivery_id' => $checkpoint->delivery_id, 'target' => 'courier-assignments',
                ]);
            }

            return;
        }
        $title = match ($milestone) {
            'assigned_pickup' => 'A pickup rider claimed the parcel',
            'picked_up' => 'The parcel was picked up',
            'arrived_at_origin_hub', 'arrived_at_mother_hub', 'arrived_at_destination_hub' => 'The parcel reached a sorting hub',
            'sorted_to_line_haul', 'sorted_to_barangay_bin' => 'The parcel was sorted for its next journey',
            'in_transit_to_mother_hub', 'in_transit_to_destination_hub' => 'The parcel is travelling to its next hub',
            'out_for_delivery' => 'Your parcel is out for delivery',
            'delivery_failed' => 'The delivery attempt was unsuccessful',
            'delivered' => 'Your parcel was delivered',
            default => null,
        };
        if (! $title) {
            return;
        }
        $parcel = $checkpoint->delivery()->with('order.shop')->firstOrFail();
        $order = $parcel->order;
        $body = match ($milestone) {
            'delivered' => 'Confirm receipt from your order page after checking the parcel.',
            'delivery_failed' => 'The parcel must return to the destination hub before another attempt or an authorized recovery option.',
            'out_for_delivery' => $order->payment_method === 'cod'
                ? 'Have PHP '.number_format((float) $order->total_amount, 2).' ready for cash on delivery.'
                : 'The delivery rider is bringing your parcel.',
            default => 'Open the order to view its recorded progress.',
        };
        if ($milestone === 'delivery_failed') {
            $reason = DeliveryAttempt::where('delivery_id', $parcel->id)->latest('id')->value('reason_code');
            $body = (DeliveryRecoveryService::REASONS[$reason] ?? 'Delivery attempt unsuccessful').'. '.$body;
        }
        $source = 'checkpoint:'.$checkpoint->id;
        $data = ['title' => $title, 'body' => $body, 'milestone' => $milestone, 'order_id' => $order->id,
            'order_number' => $order->order_number, 'delivery_id' => $parcel->id];
        if ($milestone !== 'assigned_pickup') {
            $this->store($order->buyer_id, 'parcel-event', $source, $data + ['target' => 'buyer-order']);
        }
        if (! in_array($milestone, ['out_for_delivery', 'delivered'], true) && $order->shop?->user_id) {
            $this->store($order->shop->user_id, 'parcel-event', $source, $data + ['target' => 'seller-orders']);
        }
        if ($milestone === 'assigned_pickup' && $parcel->courier_id) {
            $this->store($parcel->courier_id, 'parcel-event', $source, [
                'title' => 'Your pickup claim was recorded', 'body' => 'Open your assignment to review the pickup details.',
                'milestone' => $milestone, 'delivery_id' => $parcel->id, 'target' => 'courier-assignments',
            ]);
        }
        if ($milestone === 'delivery_failed') {
            $recipients = HubHandler::eligible()->where('hub_id', $parcel->destination_bayan_hub_id)->pluck('user_id');
            $ownerId = $parcel->destinationBayanHub?->company?->user_id;
            if ($ownerId && User::eligibleLogisticsAccounts()->whereKey($ownerId)->exists()) {
                $recipients->push($ownerId);
            }
            foreach ($recipients->unique() as $recipientId) {
                $this->store($recipientId, 'parcel-event', $source, [
                    'title' => 'A delivery needs hub recovery', 'body' => $body,
                    'delivery_id' => $parcel->id, 'milestone' => $milestone, 'target' => 'hub-recovery',
                ]);
            }
        }
    }

    public function pickup(PickupClaim $claim, string $milestone): void
    {
        $orderId = Delivery::whereKey($claim->delivery_id)->value('order_id');
        $hub = LogisticsHub::findOrFail($claim->hub_id);
        $title = match ($milestone) {
            'ready' => 'Your parcel is ready for hub pickup',
            'day_three', 'day_six' => 'Remember to collect your parcel',
            'expired' => 'Your pickup holding period has ended',
            'collected' => 'Your parcel was collected',
        };
        $this->store($claim->buyer_id, 'hub-pickup', $claim->reference.':'.$milestone, [
            'title' => $title, 'milestone' => $milestone, 'order_id' => $orderId, 'delivery_id' => $claim->delivery_id,
            'hub_name' => $hub->name, 'hub_address' => $hub->address, 'operating_hours' => $hub->operating_hours,
            'expires_at' => $claim->expires_at->toIso8601String(), 'target' => 'buyer-order',
            'body' => $milestone === 'collected' ? 'Confirm receipt from your order page after checking the parcel.'
                : 'Open your order for private claim-code access and the current pickup instructions.',
        ]);
    }

    public function returned(DeliveryReturnRoute $route, string $milestone): void
    {
        $parcel = Delivery::findOrFail($route->delivery_id);
        $order = Order::findOrFail($parcel->order_id);
        $title = match ($milestone) {
            'started' => 'Your parcel is returning through the hubs',
            'ready' => 'Your returned parcel is ready for receipt',
            'received' => 'Your returned parcel receipt was recorded',
        };
        $sellerId = $order->shop?->user_id;
        if ($sellerId) {
            $this->store($sellerId, 'parcel-return', $route->reference.':'.$milestone, ['title' => $title,
                'milestone' => $milestone, 'order_id' => $order->id, 'delivery_id' => $parcel->id, 'target' => 'seller-orders']);
        }
        $this->store($order->buyer_id, 'parcel-return', $route->reference.':'.$milestone, [
            'title' => $milestone === 'received' ? 'The returned parcel reached the seller' : 'Your parcel is returning to the seller',
            'body' => 'Open your order to view its recorded return progress.', 'milestone' => $milestone,
            'order_id' => $order->id, 'delivery_id' => $parcel->id, 'target' => 'buyer-order',
        ]);
    }

    private function store(int $userId, string $type, string $source, array $data): void
    {
        $this->delivery->record($userId, $type, $source, $data);
    }
}
