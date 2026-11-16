<?php

namespace App\Services\Promotions;

use App\Models\ProductSale;
use App\Models\SaleEvent;
use App\Models\SaleEventClaim;
use App\Models\SaleEventVoucher;
use App\Models\User;
use App\Services\Commerce\SalePriceService;
use App\Services\Finance\CodMoney;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\ShopEligibilityService;
use Illuminate\Support\Collection;

/** Real event data for buyer, seller and admin pages: no invented prices, counts or timers. */
class SaleEventPresenter
{
    public function summary(SaleEvent $event): array
    {
        return ['id' => $event->id, 'slug' => $event->slug, 'title' => $event->title, 'badge' => $event->badge,
            'banner_url' => $event->bannerUrl(), 'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->closesAt()->toIso8601String(), 'status' => $event->status()];
    }

    /** Vouchers still usable, with real claim counts and whether this buyer already claimed each. */
    public function vouchers(SaleEvent $event, ?User $buyer = null, ?int $shopId = null, bool $includeUnavailable = false): array
    {
        $entries = SaleEventVoucher::with(['voucher.currentRevision.shop:id,name,slug'])->where('sale_event_id', $event->id)
            ->when($shopId !== null, fn ($query) => $query->where('shop_id', $shopId))
            ->orderByRaw('case when shop_id is null then 0 else 1 end')->orderBy('id')->get();
        $mine = $buyer ? SaleEventClaim::where('buyer_id', $buyer->id)->whereIn('sale_event_voucher_id', $entries->pluck('id'))->pluck('sale_event_voucher_id')->all() : [];

        return $entries->map(function (SaleEventVoucher $entry) use ($mine, $includeUnavailable) {
            $voucher = $entry->voucher;
            $revision = $voucher?->currentRevision;
            $usable = $voucher && $voucher->is_active && ! $voucher->archived_at && $revision && $revision->ends_at->isFuture();
            if (! $usable && ! $includeUnavailable) {
                return null;
            }

            // Voucher terms first: their own 'id' is the voucher, and the claim button must use the event entry id.
            return [
                ...($revision ? app(CheckoutQuoteService::class)->present($revision) : ['code' => $voucher?->code, 'name' => $voucher?->name]),
                'id' => $entry->id,
                'voucher_id' => $entry->voucher_id,
                'claim_limit' => $entry->claim_limit,
                'claimed_count' => $entry->claimed_count,
                'sold_out' => $entry->claim_limit !== null && $entry->claimed_count >= $entry->claim_limit,
                'claimed' => in_array($entry->id, $mine, true),
                'usable' => $usable,
            ];
        })->filter()->values()->all();
    }

    /** Products on discount for this event, priced exactly as checkout will charge them. */
    public function products(SaleEvent $event, ?int $shopId = null, bool $includeEnded = false): Collection
    {
        $sales = ProductSale::with(['product' => fn ($query) => $query->with(['shop', 'category:id,name,slug'])])
            ->where('sale_event_id', $event->id)
            ->when(! $includeEnded, fn ($query) => $query->whereNull('ended_at')->where('ends_at', '>', now()))
            ->when($shopId !== null, fn ($query) => $query->whereHas('product', fn ($product) => $product->where('shop_id', $shopId)))
            ->orderBy('id')->get();
        $prices = app(SalePriceService::class);
        $eligibility = app(ShopEligibilityService::class);

        return $sales->map(function (ProductSale $sale) use ($prices, $eligibility, $includeEnded) {
            $product = $sale->product;
            if (! $product || (! $includeEnded && ! $eligibility->productIsEligible($product))) {
                return null;
            }
            $regular = min($prices->regularPrices($product));
            $saleCents = $sale->discount_type === 'percentage'
                ? $prices->discountedCents($regular, (int) $sale->percentage)
                : max(1, $regular - max(0, CodMoney::cents($product->price) - CodMoney::cents($sale->price)));

            return [
                'sale_id' => $sale->id,
                'id' => $product->id,
                'name' => html_entity_decode($product->name, ENT_QUOTES | ENT_HTML5),
                'slug' => $product->slug,
                'image' => $product->featured_image,
                'regular_price' => $regular / 100,
                'price' => $saleCents / 100,
                'discount_pct' => $sale->discount_type === 'percentage' ? (int) $sale->percentage : (int) round((1 - $saleCents / max(1, $regular)) * 100),
                'live' => $sale->isLive(),
                'ended' => (bool) $sale->ended_at || $sale->ends_at->lessThanOrEqualTo(now()),
                'stock' => (int) $product->stock,
                'rating' => $product->rating !== null && (float) $product->rating > 0 ? (float) $product->rating : null,
                'sales_count' => (int) $product->sales_count,
                'shop' => $product->shop ? ['id' => $product->shop->id, 'name' => $product->shop->name, 'slug' => $product->shop->slug] : null,
                'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name, 'slug' => $product->category->slug] : null,
            ];
        })->filter()->values();
    }
}
