<?php

namespace App\Services;

use App\Models\AccountReviewRequest;
use App\Models\AdvertisementSubmission;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletTopUpRequest;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderWorkspaceService;

class SidebarAttentionService
{
    public function for(?User $user, int $unreadMessages = 0): array
    {
        if (! $user || ! $user->canAccessPortal() || ! $user->email_verified_at || ! in_array($user->role, ['admin', 'seller'], true)) {
            return [];
        }
        if ($user->isAdmin()) {
            return [
                'shop_reviews' => Shop::where('review_status', 'pending_approval')->exists(),
                'kyc' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->where('kyc_status', 'pending_approval')->exists(),
                'review_requests' => AccountReviewRequest::doesntHave('decision')->exists(),
                'advertisements' => AdvertisementSubmission::doesntHave('reviews')->exists(),
                'wallet_credits' => WalletTopUpRequest::where('status', 'pending')->exists(),
            ];
        }

        return [
            'messages' => $unreadMessages > 0,
            'orders' => $this->sellerOrdersNeedAction($user),
        ];
    }

    private function sellerOrdersNeedAction(User $seller): bool
    {
        $shops = Shop::where('user_id', $seller->id)->limit(2)->get();
        if ($shops->count() !== 1 || ! app(ShopEligibilityService::class)->isEligible($shops->first())) {
            return false;
        }
        $shop = $shops->first();
        $lifecycle = app(OrderLifecycleService::class);

        return Order::whereIn('status', OrderWorkspaceService::PREPARATION)
            ->where(fn ($orders) => $orders->where('payment_method', 'cod')->orWhere('payment_status', 'paid'))
            ->whereHas('items', fn ($items) => $items->where('shop_id', $shop->id))
            ->whereHas('delivery', fn ($delivery) => $delivery->where('status', 'unassigned')
                ->whereNull('courier_id')->whereNull('assigned_rider_id'))
            ->with(['items', 'delivery.checkpoints'])->lazyById(50)
            ->contains(function (Order $order) use ($shop, $lifecycle): bool {
                $flags = $lifecycle->sellerActionFlags($order, true, $order->items->every(fn ($item) => $item->shop_id === $shop->id));

                return $flags['can_accept_and_pack'] || $flags['can_pack'] || $flags['can_mark_ready'];
            });
    }
}
