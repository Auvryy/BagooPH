<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\AccountReviewRequest;
use App\Models\Advertisement;
use App\Models\BuyerReturnCase;
use App\Models\Order;
use App\Models\Product;
use App\Models\RestrictionAffectedWork;
use App\Models\RiderCompanyRequest;
use App\Models\SaleReminder;
use App\Models\User;
use App\Services\Advertisements\SellerPromotionService;
use App\Services\BuyerAccessService;
use App\Services\ExceptionOversightService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\GovernanceHistoryService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use App\Services\Orders\OrderTrackingService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationCenterService
{
    public const TYPES = ['sale-reminder', 'order-event', 'parcel-event', 'hub-pickup', 'parcel-return', 'governance-event', 'cod-event', 'settlement-event', 'buyer-return'];

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

    public function acknowledgeDisplayed(User $user, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        abort_unless(count($ids) >= 1 && count($ids) <= 50
            && collect($ids)->every(fn ($id) => is_string($id) && Str::isUuid($id)), 422);

        return DB::transaction(function () use ($user, $ids) {
            $user = $this->current(User::whereKey($user->id)->lockForUpdate()->firstOrFail());
            $records = $user->notifications()->whereIn('type', self::TYPES)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($records->count() === count($ids), 404);
            // UUID/time cutoffs can include a later unseen notice created in the same clock tick.
            // Only explicitly displayed owned records may be acknowledged.
            $user->notifications()->whereIn('type', self::TYPES)->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);

            return $records->pluck('id')->all();
        });
    }

    public function readSnapshot(User $user): ?array
    {
        return DB::transaction(function () use ($user) {
            DB::table('notification_read_snapshots')->where('expires_at', '<=', now())->delete();
            $id = (string) Str::uuid();
            $expires = now()->addMinutes(15);
            DB::table('notification_read_snapshots')->insert(['id' => $id, 'user_id' => $user->id, 'expires_at' => $expires]);
            // Copy the exact set in SQL. Later UUIDs cannot enter through clock or sort cutoffs.
            $unread = $user->notifications()->whereIn('type', self::TYPES)->whereNull('read_at')
                ->selectRaw('? AS snapshot_id, notifications.id AS notification_id', [$id]);
            DB::table('notification_read_snapshot_items')->insertUsing(['snapshot_id', 'notification_id'], $unread->toBase());
            $count = DB::table('notification_read_snapshot_items')->where('snapshot_id', $id)->count();
            if ($count === 0) {
                DB::table('notification_read_snapshots')->where('id', $id)->delete();

                return null;
            }

            return ['id' => $id, 'count' => $count, 'expires_at' => $expires->toIso8601String()];
        });
    }

    public function acknowledgeSnapshot(User $user, string $id): int
    {
        return DB::transaction(function () use ($user, $id) {
            $user = $this->current(User::whereKey($user->id)->lockForUpdate()->firstOrFail());
            $snapshot = DB::table('notification_read_snapshots')->where('id', $id)->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $snapshot || now()->greaterThanOrEqualTo($snapshot->expires_at)) {
                throw ValidationException::withMessages(['snapshot' => 'This notification list is out of date. Reload the page, then try Mark all as read again.']);
            }
            $ids = DB::table('notification_read_snapshot_items')->where('snapshot_id', $id)->select('notification_id');

            return $user->notifications()->whereIn('type', self::TYPES)->whereIn('id', $ids)->whereNull('read_at')
                ->update(['read_at' => now(), 'updated_at' => now()]);
        });
    }

    public function present(DatabaseNotification $notice, User $user): array
    {
        // Legacy or future payload fields are never forwarded wholesale to the client.
        $data = array_intersect_key($notice->data, array_flip(['title', 'body', 'order_number', 'hub_name', 'hub_address', 'operating_hours', 'expires_at']));

        return ['id' => $notice->id, 'type' => $notice->type, 'data' => $data,
            'read_at' => $notice->read_at?->toIso8601String(), 'created_at' => $notice->created_at->toIso8601String(),
            'href' => $this->href($notice, $user)];
    }

    public function forOrder(User $buyer, Order $order): array
    {
        abort_unless($buyer->isBuyer() && $order->buyer_id === $buyer->id, 403);
        $notices = $buyer->notifications()->whereIn('type', self::TYPES)
            ->where('data->order_id', $order->id)->latest()->orderBy('id')->get();
        $originalIds = $notices->isEmpty() ? [] : $order->delivery?->checkpoints()
            ->whereNull('source_checkpoint_id')->whereIn('checkpoint_type', array_keys(OrderTrackingService::EVENTS))
            ->pluck('id')->all() ?? [];

        return $notices->map(function (DatabaseNotification $notice) use ($buyer, $originalIds) {
            $milestone = $notice->data['milestone'] ?? null;
            $checkpointId = $notice->type === 'parcel-event'
                && preg_match('/\Acheckpoint:([1-9][0-9]{0,17})\z/', (string) $notice->source_key, $match)
                && in_array((int) $match[1], $originalIds, true) ? (int) $match[1] : null;

            return $this->present($notice, $buyer) + [
                'checkpoint_id' => $checkpointId,
                'milestone' => $notice->type === 'order-event' && is_string($milestone)
                    && isset(OrderTrackingService::EVENTS[$milestone]) ? $milestone : null,
            ];
        })->all();
    }

    private function href(DatabaseNotification $notice, User $user): ?string
    {
        $data = $notice->data;
        if ($notice->type === 'buyer-return') {
            $case = BuyerReturnCase::find((int) ($data['return_id'] ?? 0));
            if ($case && (($user->isBuyer() && $case->buyer_id === $user->id && app(BuyerAccessService::class)->canViewOrderHistory($user))
                || ($user->isSeller() && $user->isKycApproved() && $case->seller_id === $user->id)
                || ($user->isAdmin() && $user->canAccessPortal() && $user->email_verified_at))) {
                return '/buyer-returns/'.$case->id;
            }

            return null;
        }
        $target = $data['target'] ?? match ($notice->type) {
            'hub-pickup' => 'buyer-order',
            'parcel-return' => $user->isBuyer() ? 'buyer-order' : 'seller-orders',
            default => null,
        };
        if (in_array($target, ['promotion-admin', 'promotion-seller'], true)
            && app(SellerPromotionService::class)->installed()) {
            $ad = Advertisement::whereKey((int) ($data['advertisement_id'] ?? 0))->whereNotNull('shop_id')->first();
            if ($ad && $ad->submissions()->whereKey((int) ($data['submission_id'] ?? 0))->exists() && $user->canAccessPortal() && $user->email_verified_at) {
                $anchor = '#submission-'.(int) $data['submission_id'];
                if ($target === 'promotion-admin' && $user->isAdmin()) {
                    return '/admin/advertisements/'.$ad->id.$anchor;
                }
                if ($target === 'promotion-seller' && $user->isSeller() && (int) $ad->created_by_id === (int) $user->id
                    && $ad->shop()->where('user_id', $user->id)->exists()) {
                    return '/seller/promotions/'.$ad->id.$anchor;
                }
            }

            return null;
        }
        if ($target === 'buyer-sale' && $user->isBuyer() && $user->canAccessPortal()) {
            $product = Product::availableForSale()->whereKey((int) ($data['product_id'] ?? 0))->first();
            if ($product && SaleReminder::whereKey((int) ($data['sale_reminder_id'] ?? 0))->where('buyer_id', $user->id)
                ->whereHas('sale', fn ($sale) => $sale->where('product_id', $product->id))->exists()) {
                return route('buyer.products.show', $product->slug, absolute: false);
            }

            return null;
        }
        if ($target === 'buyer-wallet' && $user->isBuyer() && app(BuyerAccessService::class)->canViewOrderHistory($user)) {
            return route('buyer.profile', ['tab' => 'wallet'], absolute: false);
        }
        if ($target === 'buyer-order' && $user->isBuyer() && app(BuyerAccessService::class)->canViewOrderHistory($user)
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
        if ($target === 'courier-profile' && $user->isEligibleCourier()) {
            return '/courier/profile?tab=assignment';
        }
        if ($target === 'rider-company-assignment' && $user->isEligibleCourier()
            && RiderCompanyRequest::whereKey($data['company_request_id'] ?? 0)->where('rider_id', $user->id)->exists()) {
            return '/courier/profile?tab=assignment';
        }
        if ($target === 'company-rider-requests' && app(LogisticsEligibilityService::class)->isCompanyAdministrator($user)
            && RiderCompanyRequest::whereKey($data['company_request_id'] ?? 0)->whereHas('company', fn ($company) => $company->where('user_id', $user->id))->exists()) {
            return '/hub/network';
        }
        if ($target === 'hub-recovery' && $user->isLogistics() && $user->canAccessPortal()) {
            return '/hub/delivery-recovery';
        }
        if ($target === 'account-status') {
            return '/account/status';
        }
        if ($target === 'account-review-admin' && $user->isAdmin() && $user->canAccessPortal()
            && AccountReviewRequest::whereKey($data['review_request_id'] ?? 0)->exists()) {
            return '/admin/account-reviews/'.(int) $data['review_request_id'];
        }
        if ($target === 'identity-correction' && $user->isKycApproved()) {
            return '/account/settings#identity-correction';
        }
        if ($target === 'seller-products' && $user->isSeller() && $user->canAccessPortal()) {
            return '/seller/products';
        }
        if ($target === 'seller-vouchers' && $user->isSeller() && $user->canAccessPortal()) {
            return '/seller/vouchers';
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
