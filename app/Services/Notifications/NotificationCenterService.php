<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;

class NotificationCenterService
{
    public const TYPES = ['order-event', 'parcel-event', 'hub-pickup', 'parcel-return'];

    public function current(User $actor): User
    {
        $user = User::find($actor->id);
        abort_unless($user && $this->permitted($user), 403);

        return $user;
    }

    private function permitted(User $user): bool
    {
        return UserRole::tryFrom($user->role) !== null && $user->closed_at === null
            && in_array($user->status, ['active', 'pending_approval', 'suspended', 'inactive'], true);
    }

    public function summary(?User $user): array
    {
        if (! $user || ! $this->permitted($user)) {
            return ['available' => false, 'unread' => null];
        }
        try {
            return ['available' => true, 'unread' => $user->notifications()->whereIn('type', self::TYPES)->whereNull('read_at')->count()];
        } catch (QueryException) {
            return ['available' => false, 'unread' => null];
        }
    }

    public function owned(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereIn('type', self::TYPES)->whereKey($id)->firstOrFail();
    }

    public function acknowledge(User $user, string $id): DatabaseNotification
    {
        $notice = $this->owned($user, $id);
        $user->notifications()->whereKey($id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

        return $notice->fresh();
    }

    public function present(DatabaseNotification $notice, User $user): array
    {
        // Legacy or future payload fields are never forwarded wholesale to the client.
        $data = array_intersect_key($notice->data, array_flip(['title', 'body', 'order_number', 'hub_name', 'hub_address', 'operating_hours', 'expires_at']));

        return ['id' => $notice->id, 'type' => $notice->type, 'data' => $data,
            'read_at' => $notice->read_at?->toIso8601String(), 'created_at' => $notice->created_at->toIso8601String(),
            'href' => $this->href($notice, $user)];
    }

    private function href(DatabaseNotification $notice, User $user): ?string
    {
        $data = $notice->data;
        $target = $data['target'] ?? match ($notice->type) {
            'hub-pickup' => 'buyer-order',
            'parcel-return' => $user->isBuyer() ? 'buyer-order' : 'seller-orders',
            default => null,
        };
        if ($target === 'buyer-order' && $user->isBuyer() && app(BuyerAccessService::class)->canAccessExistingOrders($user)
            && Order::whereKey($data['order_id'] ?? 0)->where('buyer_id', $user->id)->exists()) {
            return '/buyer/orders/'.(int) $data['order_id'];
        }
        if ($target === 'seller-orders' && $user->isSeller() && $user->canAccessPortal()
            && Order::whereKey($data['order_id'] ?? 0)->whereHas('shop', fn ($shop) => $shop->where('user_id', $user->id))->exists()) {
            return '/seller/orders';
        }
        if ($target === 'courier-assignments' && $user->isEligibleCourier()) {
            return '/courier/deliveries';
        }
        if ($target === 'hub-recovery' && $user->isLogistics() && $user->canAccessPortal()) {
            return '/hub/delivery-recovery';
        }

        return null;
    }
}
