<?php

namespace App\Services\Commerce;

use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\SaleReminder;
use App\Models\User;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\ShopEligibilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReminderService
{
    public const LEAD_MINUTES = 10;

    public function set(User $actor, ProductSale $sale, bool $subscribed): void
    {
        DB::transaction(function () use ($actor, $sale, $subscribed) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            $product = Product::whereKey($sale->product_id)->lockForUpdate()->firstOrFail();
            $sale = ProductSale::whereKey($sale->id)->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $reminder = SaleReminder::where('buyer_id', $buyer->id)->where('product_sale_id', $sale->id)->lockForUpdate()->first();
            if (! $subscribed) {
                $reminder?->update(['subscribed' => false]);

                return;
            }
            if (! app(ShopEligibilityService::class)->productIsEligible($product) || $sale->kind !== 'flash' || $sale->ended_at || ! $sale->starts_at->isFuture()) {
                throw ValidationException::withMessages(['sale_reminder' => 'Reminders are available only for upcoming flash slots on active products.']);
            }
            $reminder ??= SaleReminder::create(['buyer_id' => $buyer->id, 'product_sale_id' => $sale->id]);
            $reminder->update(['subscribed' => true]);
            $pending = NotificationDelivery::where('recipient_id', $buyer->id)->where('source_key', 'sale-reminder:'.$reminder->id)->lockForUpdate()->first();
            if ($reminder->notified_at && $pending && ! $pending->delivered_at) {
                $pending->update(['discarded_at' => null, 'available_at' => now()]);
                DB::afterCommit(fn () => app(NotificationDeliveryService::class)->deliver($pending->id));

                return;
            }
            $this->notifyIfDue($reminder, $buyer, $sale, $product);
        }, 3);
    }

    public function status(User $buyer, ?int $saleId): ?array
    {
        if (! $saleId) {
            return null;
        }
        $reminder = SaleReminder::where('buyer_id', $buyer->id)->where('product_sale_id', $saleId)->first();
        $delivery = $reminder ? NotificationDelivery::where('recipient_id', $buyer->id)->where('source_key', 'sale-reminder:'.$reminder->id)->first() : null;

        return ['sale_id' => $saleId, 'subscribed' => $reminder?->subscribed ?? false, 'notified' => $reminder?->notified_at !== null,
            'delivered' => $delivery?->delivered_at !== null, 'discarded' => $delivery?->discarded_at !== null];
    }

    public function dispatchDue(int $limit = 100): int
    {
        $ids = SaleReminder::where('subscribed', true)->whereNull('notified_at')
            ->whereHas('buyer', fn ($buyers) => $buyers->where('role', 'buyer')->where('status', 'active')->whereNull('closed_at')
                ->whereIn('kyc_status', User::APPROVED_KYC_STATUSES)->where(fn ($age) => $age
                ->where(fn ($legacy) => $legacy->whereNull('birthday')->where('identity_version', 0))
                ->orWhere(fn ($date) => $date->whereDate('birthday', '>=', '0001-01-01')->whereDate('birthday', '<=', app(BirthDateEligibility::class)->limits()['adult_maximum']))))
            ->whereHas('sale.product', fn ($products) => $products->availableForSale()->whereHas('shop', fn ($shops) => $shops->where('vacation_mode', false)))
            ->whereHas('sale', fn ($sales) => $sales->where('kind', 'flash')->whereNull('ended_at')->where('starts_at', '>', now())->where('starts_at', '<=', now()->addMinutes(self::LEAD_MINUTES)))
            ->orderBy('id')->limit($limit)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += (int) DB::transaction(function () use ($id) {
                $candidate = SaleReminder::find($id);
                if (! $candidate) {
                    return false;
                }
                $buyer = User::whereKey($candidate->buyer_id)->lockForUpdate()->first();
                $sale = ProductSale::find($candidate->product_sale_id);
                if (! $buyer || ! $sale) {
                    return false;
                }
                $product = Product::whereKey($sale->product_id)->lockForUpdate()->first();
                $sale = ProductSale::whereKey($sale->id)->lockForUpdate()->first();
                $reminder = SaleReminder::whereKey($id)->lockForUpdate()->first();

                return $product && $sale && $reminder ? $this->notifyIfDue($reminder, $buyer, $sale, $product) : false;
            }, 3);
        }

        return $sent;
    }

    private function notifyIfDue(SaleReminder $reminder, User $buyer, ProductSale $sale, Product $product): bool
    {
        if (! $reminder->subscribed || $reminder->notified_at || ! $buyer->isBuyer() || ! $buyer->canAccessPortal()
            || $sale->kind !== 'flash' || $sale->ended_at || ! $sale->starts_at->isFuture()
            || $sale->starts_at->greaterThan(now()->addMinutes(self::LEAD_MINUTES))
            || ! app(ShopEligibilityService::class)->productIsEligible($product) || $product->shop?->vacation_mode) {
            return false;
        }
        app(NotificationDeliveryService::class)->record($buyer->id, 'sale-reminder', 'sale-reminder:'.$reminder->id, [
            'title' => 'Your sale reminder',
            'body' => $sale->name.' for '.$product->name.' starts '.$sale->starts_at->setTimezone('Asia/Manila')->format('M j, g:i A').' (Asia/Manila). Prices and available stock are confirmed at checkout.',
            'target' => 'buyer-sale', 'sale_reminder_id' => $reminder->id, 'product_id' => $product->id, 'product_sale_id' => $sale->id,
        ]);
        $reminder->update(['notified_at' => now()]);

        return true;
    }

    public function deliveryIsCurrent(NotificationDelivery $delivery): bool
    {
        $reminder = SaleReminder::whereKey((int) ($delivery->data['sale_reminder_id'] ?? 0))->where('buyer_id', $delivery->recipient_id)->with('buyer', 'sale.product.shop')->first();
        $sale = $reminder?->sale;
        $product = $sale?->product;

        return $reminder && $reminder->subscribed && $reminder->buyer?->isBuyer() && $reminder->buyer->canAccessPortal()
            && $sale && $sale->kind === 'flash' && ! $sale->ended_at && $sale->starts_at->isFuture()
            && $sale->starts_at->lessThanOrEqualTo(now()->addMinutes(self::LEAD_MINUTES)) && $product
            && ! $product->shop?->vacation_mode && app(ShopEligibilityService::class)->productIsEligible($product);
    }
}
