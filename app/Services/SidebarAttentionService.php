<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\AccountReviewRequest;
use App\Models\AdvertisementSubmission;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletTopUpRequest;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Database\QueryException;

class SidebarAttentionService
{
    public function for(?User $user, int $unreadMessages = 0): array
    {
        if (! $user || ! $user->canAccessPortal() || ! $user->email_verified_at || ! in_array($user->role, ['admin', 'seller'], true)) {
            return [];
        }
        if ($user->isAdmin()) {
            return $this->withUnreadTargets($user, [
                'shop_reviews' => Shop::where('review_status', 'pending_approval')->exists(),
                'kyc' => User::whereIn('role', ['buyer', 'seller', 'courier', 'logistics'])->where('kyc_status', 'pending_approval')->exists(),
                'review_requests' => AccountReviewRequest::doesntHave('decision')->exists(),
                'products' => Product::where('compliance_restricted', true)->exists(),
                'advertisements' => AdvertisementSubmission::doesntHave('reviews')->exists(),
                'wallet_credits' => WalletTopUpRequest::where('status', 'pending')->exists(),
            ]);
        }
        $shops = Shop::where('user_id', $user->id)->select('id');

        return $this->withUnreadTargets($user, [
            'messages' => $unreadMessages > 0,
            'products' => Product::whereIn('shop_id', $shops)->where(fn ($products) => $products
                ->where('status', 'draft')->orWhere('compliance_restricted', true)
                ->orWhere(fn ($stock) => $stock->where('status', 'active')->where('stock', '<=', 5)))->exists(),
            'orders' => Order::whereIn('status', [OrderStatus::PLACED->value, OrderStatus::PENDING->value,
                OrderStatus::CONFIRMED->value, OrderStatus::PREPARING->value, OrderStatus::PROCESSING->value, 'packaging'])
                ->where(fn ($orders) => $orders->where('payment_method', 'cod')->orWhere('payment_status', 'paid'))
                ->whereHas('items', fn ($items) => $items->whereIn('shop_id', $shops))->exists(),
        ]);
    }

    private function withUnreadTargets(User $user, array $work): array
    {
        try {
            $notices = $user->notifications()->whereIn('type', NotificationCenterService::TYPES)->whereNull('read_at');
            $targets = $user->isAdmin()
                ? ['review_requests' => 'account-review-admin', 'advertisements' => 'promotion-admin']
                : ['products' => 'seller-products', 'orders' => 'seller-orders', 'vouchers' => 'seller-vouchers',
                    'finances' => 'seller-settlement', 'promotions' => 'promotion-seller'];
            foreach ($targets as $key => $target) {
                $work[$key] = ($work[$key] ?? false) || (clone $notices)->where('data->target', $target)->exists();
            }
        } catch (QueryException) {
            // A notification outage must not hide independently recorded work.
        }

        return $work;
    }
}
