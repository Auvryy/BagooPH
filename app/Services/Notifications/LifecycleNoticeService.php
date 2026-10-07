<?php

namespace App\Services\Notifications;

use App\Models\Delivery;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LifecycleNoticeService
{
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
            'expires_at' => $claim->expires_at->toIso8601String(), 'href' => '/buyer/orders/'.$orderId,
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
                'milestone' => $milestone, 'order_id' => $order->id, 'delivery_id' => $parcel->id, 'href' => '/seller/orders']);
        }
    }

    private function store(int $userId, string $type, string $source, array $data): void
    {
        if (DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $userId)->where('source_key', $source)->exists()) {
            return;
        }
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => $type,
            'notifiable_type' => User::class, 'notifiable_id' => $userId, 'source_key' => $source,
            'data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
    }
}
