<?php

namespace App\Services\Courier;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\WaybillScanInputService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CourierMessagingService
{
    private const PICKUP_MESSAGE_STATUSES = ['assigned', 'assigned_pickup', 'picked_up'];

    private const FINAL_MILE_MESSAGE_STATUSES = ['assigned_to_rider', 'out_for_delivery'];

    public function conversations(User $rider): array
    {
        $deliveries = $this->accessibleDeliveries($rider);
        $orderIds = $deliveries->pluck('order_id')->unique()->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        $messages = Message::query()
            ->whereIn('order_id', $orderIds)
            ->where(function ($query) use ($rider) {
                $query->where('sender_id', $rider->id)
                    ->orWhere('receiver_id', $rider->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $conversations = [];

        foreach ($deliveries as $delivery) {
            foreach ($this->participantsForDelivery($delivery, $rider) as $participant) {
                $conversationMessages = $messages
                    ->filter(fn (Message $message) => $message->order_id === $delivery->order_id
                        && in_array($participant['user']->id, [$message->sender_id, $message->receiver_id], true))
                    ->sortBy('created_at')
                    ->values();

                if (! $participant['can_send'] && $conversationMessages->isEmpty()) {
                    continue;
                }

                $unread = $conversationMessages
                    ->where('receiver_id', $rider->id)
                    ->where('is_read', false);
                $latest = $conversationMessages->last();

                $conversations[] = [
                    'delivery_id' => $delivery->id,
                    'tracking_number' => $delivery->tracking_number,
                    'order_number' => $delivery->order?->order_number,
                    'phase' => $participant['phase'],
                    'can_send' => $participant['can_send'],
                    'participant' => [
                        'id' => $participant['user']->id,
                        'name' => $participant['user']->name,
                        'avatar' => $participant['user']->avatar,
                        'role' => $participant['user']->role,
                        'shop_name' => $participant['shop_name'],
                    ],
                    'last_message' => $latest?->message,
                    'last_time' => $latest?->created_at?->toIso8601String(),
                    'unread_count' => $unread->count(),
                    'messages' => $conversationMessages->map(fn (Message $message) => [
                        'id' => $message->id,
                        'sender_id' => $message->sender_id,
                        'message' => $message->message,
                        'created_at' => $message->created_at?->toIso8601String(),
                    ])->all(),
                ];
            }
        }

        usort($conversations, function (array $left, array $right): int {
            if ($left['can_send'] !== $right['can_send']) {
                return $left['can_send'] ? -1 : 1;
            }

            return strcmp($right['last_time'] ?? '', $left['last_time'] ?? '');
        });

        return $conversations;
    }

    public function acknowledge(User $rider, Delivery $delivery, string $phase, int $throughMessageId): void
    {
        DB::transaction(function () use ($rider, $delivery, $phase, $throughMessageId) {
            $order = Order::whereKey($delivery->order_id)->lockForUpdate()->firstOrFail();
            $delivery = Delivery::whereKey($delivery->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            $rider = User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $this->acknowledgeCurrent($rider, $delivery, $phase, $throughMessageId);
        });
    }

    private function acknowledgeCurrent(User $rider, Delivery $delivery, string $phase, int $throughMessageId): void
    {
        if (! $rider->isEligibleCourier()) {
            throw new DomainException('This delivery conversation is not available to your account.');
        }

        $accessible = $this->conversationQuery($rider)->whereKey($delivery->id)->first();
        $participant = $accessible
            ? collect($this->participantsForDelivery($accessible, $rider))->firstWhere('phase', $phase)
            : null;
        $profile = $rider->courierProfile;
        $expectedHubId = $phase === 'pickup' ? $delivery->origin_bayan_hub_id : $delivery->destination_bayan_hub_id;

        if (! $participant || $profile?->assigned_hub_id !== $expectedHubId) {
            throw new DomainException('This delivery conversation is outside your assignment.');
        }

        $thread = Message::query()
            ->where('order_id', $delivery->order_id)
            ->where(function ($query) use ($rider, $participant) {
                $query->where(function ($incoming) use ($rider, $participant) {
                    $incoming->where('sender_id', $participant['user']->id)->where('receiver_id', $rider->id);
                })->orWhere(function ($outgoing) use ($rider, $participant) {
                    $outgoing->where('sender_id', $rider->id)->where('receiver_id', $participant['user']->id);
                });
            });

        if (! (clone $thread)->whereKey($throughMessageId)->exists()) {
            throw new DomainException('The selected message does not belong to this conversation.');
        }

        $thread->where('receiver_id', $rider->id)
            ->where('id', '<=', $throughMessageId)
            ->where('is_read', false)
            ->update(['is_read' => true]);
    }

    public function send(User $rider, Delivery $delivery, string $body, ?string $phase = null, ?int $expectedParticipant = null, ?int $expectedAssignment = null): Message
    {
        $body = app(WaybillScanInputService::class)->normalize(['notes' => $body])['notes'] ?? '';
        Validator::make(['message' => $body], ['message' => ['required', 'string', new ApplicationText('notes', 1, 1000)]])->validate();

        return DB::transaction(function () use ($rider, $delivery, $body, $phase, $expectedParticipant, $expectedAssignment) {
            Order::whereKey($delivery->order_id)->lockForUpdate()->firstOrFail();
            $lockedDelivery = Delivery::with([
                'order.buyer',
                'order.items.product.shop.user',
            ])->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $participant = $this->activeParticipantForDelivery($lockedDelivery, $rider);
            if ($phase !== null && $participant['phase'] !== $phase) {
                throw new DomainException('This conversation is no longer active. Refresh and select the current delivery conversation.');
            }
            $assignment = DeliveryCheckpoint::where('delivery_id', $lockedDelivery->id)->whereNull('source_checkpoint_id')
                ->where('checkpoint_type', $participant['phase'] === 'pickup' ? 'assigned_pickup' : 'assigned_to_rider')->max('id');
            if (($expectedParticipant !== null && $participant['user']->id !== $expectedParticipant)
                || ($expectedAssignment !== null && (int) $assignment !== $expectedAssignment)) {
                throw new DomainException('This conversation assignment or participant changed. Refresh before sending the draft.');
            }
            $messageBody = trim($body);

            if ($messageBody === '') {
                throw new DomainException('Enter a message before sending.');
            }

            $duplicate = Message::query()
                ->where('sender_id', $rider->id)
                ->where('receiver_id', $participant['user']->id)
                ->where('order_id', $lockedDelivery->order_id)
                ->where('message', $messageBody)
                ->where('created_at', '>=', now()->subSeconds(2))
                ->first();

            if ($duplicate) {
                return $duplicate;
            }

            return Message::create([
                'sender_id' => $rider->id,
                'receiver_id' => $participant['user']->id,
                'shop_id' => $participant['shop_id'],
                'order_id' => $lockedDelivery->order_id,
                'message' => $messageBody,
                'is_read' => false,
            ]);
        });
    }

    public function recordPickupNote(User $rider, Delivery $delivery, string $body): Message
    {
        return DB::transaction(function () use ($rider, $delivery, $body) {
            $lockedDelivery = Delivery::with([
                'order.buyer',
                'order.items.product.shop.user',
            ])->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

            $messageBody = trim($body);
            if ($messageBody === '') {
                throw new DomainException('Enter a pickup note before sending it to the seller.');
            }

            $participant = $this->activeParticipantForDelivery($lockedDelivery, $rider);
            if ($participant['phase'] !== 'pickup') {
                throw new DomainException('Pickup notes may be sent only to the seller for an active pickup assignment.');
            }

            return Message::firstOrCreate(
                [
                    'sender_id' => $rider->id,
                    'receiver_id' => $participant['user']->id,
                    'shop_id' => $participant['shop_id'],
                    'order_id' => $lockedDelivery->order_id,
                    'message' => $messageBody,
                ],
                ['is_read' => false],
            );
        });
    }

    private function accessibleDeliveries(User $rider): Collection
    {
        return $this->conversationQuery($rider)->latest()->limit(50)->get();
    }

    public function conversationQuery(User $rider): Builder
    {
        $current = User::find($rider->id);
        if (! $current?->isEligibleCourier()) {
            return Delivery::query()->whereRaw('1 = 0');
        }
        $profile = $current->courierProfile;
        $rider->setRelation('courierProfile', $profile);
        if (! $profile?->logistics_company_id || ! $profile->assigned_hub_id) {
            return Delivery::query()->whereRaw('1 = 0');
        }

        return Delivery::query()
            ->where('logistics_company_id', $profile->logistics_company_id)
            ->where(function ($query) use ($rider, $profile) {
                $query->where(function ($pickup) use ($rider, $profile) {
                    $pickup->where('courier_id', $rider->id)
                        ->where('origin_bayan_hub_id', $profile->assigned_hub_id);
                })->orWhere(function ($finalMile) use ($rider, $profile) {
                    $finalMile->where('assigned_rider_id', $rider->id)
                        ->where('destination_bayan_hub_id', $profile->assigned_hub_id);
                });
            })
            ->with([
                'order.buyer',
                'order.items.product.shop.user',
            ]);
    }

    public function participantsForDelivery(Delivery $delivery, User $rider): array
    {
        $participants = [];
        $canWork = app(LogisticsEligibilityService::class)->isOperational($rider->courierProfile);

        if ($delivery->courier_id === $rider->id && $rider->courierProfile?->assigned_hub_id === $delivery->origin_bayan_hub_id) {
            $seller = $delivery->order?->items?->first()?->product?->shop?->user;
            if ($seller) {
                $participants[] = [
                    'phase' => 'pickup',
                    'user' => $seller,
                    'shop_id' => $delivery->order->items->first()->product->shop->id,
                    'shop_name' => $delivery->order->items->first()->product->shop->name,
                    'can_send' => $canWork && in_array($delivery->status, self::PICKUP_MESSAGE_STATUSES, true),
                ];
            }
        }

        if ($delivery->assigned_rider_id === $rider->id
            && $rider->courierProfile?->assigned_hub_id === $delivery->destination_bayan_hub_id
            && $delivery->order?->buyer) {
            $participants[] = [
                'phase' => 'final_mile',
                'user' => $delivery->order->buyer,
                'shop_id' => null,
                'shop_name' => null,
                'can_send' => $canWork && in_array($delivery->status, self::FINAL_MILE_MESSAGE_STATUSES, true),
            ];
        }

        return $participants;
    }

    private function activeParticipantForDelivery(Delivery $delivery, User $rider): array
    {
        $hubId = in_array($delivery->status, self::PICKUP_MESSAGE_STATUSES, true) && $delivery->courier_id === $rider->id
            ? $delivery->origin_bayan_hub_id
            : (in_array($delivery->status, self::FINAL_MILE_MESSAGE_STATUSES, true) && $delivery->assigned_rider_id === $rider->id
                ? $delivery->destination_bayan_hub_id : null);
        $eligibility = app(LogisticsEligibilityService::class);
        $eligibility->lockNetwork((int) $delivery->logistics_company_id, [$rider->id], [$hubId]);
        $rider = User::findOrFail($rider->id);
        $profile = CourierProfile::where('user_id', $rider->id)->lockForUpdate()->first();
        $eligibility->assertCourierScope($profile, (int) $delivery->logistics_company_id, (int) $hubId);
        $rider->setRelation('courierProfile', $profile);
        if (
            ! $rider->isEligibleCourier()
            || ! $profile
            || $profile->logistics_company_id !== $delivery->logistics_company_id
        ) {
            throw new DomainException('This delivery conversation is not available to your account.');
        }

        $participant = collect($this->participantsForDelivery($delivery, $rider))
            ->first(fn (array $candidate) => $candidate['can_send']);

        if (! $participant) {
            throw new DomainException('Messaging is available only while you have an active pickup or final-mile assignment.');
        }

        $expectedHubId = $participant['phase'] === 'pickup'
            ? $delivery->origin_bayan_hub_id
            : $delivery->destination_bayan_hub_id;

        if ($profile->assigned_hub_id !== $expectedHubId) {
            throw new DomainException('This delivery conversation is outside your assigned hub.');
        }

        return $participant;
    }
}
