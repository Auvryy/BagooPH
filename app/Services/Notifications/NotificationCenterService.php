<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\BuyerAccessService;
use App\Services\ExceptionOversightService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\GovernanceHistoryService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationCenterService
{
    public const TYPES = ['order-event', 'parcel-event', 'hub-pickup', 'parcel-return', 'governance-event', 'cod-event', 'settlement-event'];

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
        if ($target === 'account-status') {
            return '/pending-approval';
        }
        if ($target === 'identity-correction' && $user->isKycApproved()) {
            return '/account/settings#identity-correction';
        }
        if ($target === 'seller-products' && $user->isSeller() && $user->canAccessPortal()) {
            return '/seller/products';
        }
        try {
            if ($target === 'seller-settlement') {
                app(SellerSettlementService::class)->scoped($user)->whereKey((int) ($data['order_id'] ?? 0))->firstOrFail();

                return '/seller-settlements/'.(int) $data['order_id'];
            }
            if ($target === 'cod-cash') {
                app(CodCashService::class)->account($user, (int) ($data['cod_account_id'] ?? 0));

                return '/cash-handover/'.(int) $data['cod_account_id'];
            }
            if ($target === 'exception') {
                app(ExceptionOversightService::class)->detail($user, $data['exception_kind'], (int) $data['exception_id'], false);

                return '/exceptions/'.$data['exception_kind'].'/'.(int) $data['exception_id'];
            }
            if ($target === 'governance-history') {
                app(GovernanceHistoryService::class)->detail($user, $data['governance_source'], (int) $data['governance_id']);

                return '/governance-history/'.$data['governance_source'].'/'.(int) $data['governance_id'];
            }
            if ($target === 'custody-own') {
                $grants = app(RestrictedCustodyRecoveryService::class)->ownGrants($user);
                if (collect($grants)->contains('id', $data['grant_id'])) {
                    return '/custody-recovery';
                }
            }
            if ($target === 'custody-receiving') {
                app(RestrictedCustodyRecoveryService::class)->receiving($user);

                return '/custody-recovery/receiving';
            }
            if ($target === 'custody-work') {
                $work = RestrictionAffectedWork::findOrFail($data['work_id']);
                app(RestrictedCustodyRecoveryService::class)->proposal($user, $work);

                return '/custody-recovery/work/'.(int) $data['work_id'];
            }
        } catch (AuthorizationException|HttpException|ModelNotFoundException|DomainException) {
            // A saved notice does not retain a permission which has since been revoked.
        }

        return null;
    }
}
