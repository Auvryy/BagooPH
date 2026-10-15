<?php

namespace App\Services\Advertisements;

use App\Models\AdvertisementRevision;
use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\Voucher;
use App\Models\VoucherRevision;
use App\Services\Commerce\BuyerDiscoveryService;
use App\Services\Vouchers\VoucherCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdvertisementDestinationService
{
    public function products(AdvertisementRevision $revision): Collection
    {
        $query = app(BuyerDiscoveryService::class)->catalogue()->where('products.status', 'active')->where('stock', '>', 0);
        match ($revision->destination_type) {
            'product' => $query->whereKey($revision->destination_id),
            'shop' => $query->where('products.shop_id', $revision->destination_id),
            'category' => $query->whereIn('products.category_id', $this->categoryIds($revision->destination_id)),
            'collection', 'offer' => $query->whereIn('products.id', $revision->product_ids ?? []),
            default => $query->whereRaw('1 = 0'),
        };

        return $query->orderByDesc('products.id')->limit(24)->get();
    }

    public function problem(AdvertisementRevision $revision, bool $publishing = false, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): ?string
    {
        if ($revision->imported_candidate || ! $revision->starts_at || ! $revision->ends_at || ! $revision->slot) {
            return 'Complete the destination, dates and placement before publishing this artwork.';
        }
        if ($revision->destination_type === 'shop' && ! Shop::eligible()->whereKey($revision->destination_id)->exists()) {
            return 'The selected shop is not currently available.';
        }
        if ($revision->destination_type === 'category' && ! Category::whereKey($revision->destination_id)->where('is_active', true)->exists()) {
            return 'The selected category is not currently available.';
        }
        $products = $this->products($revision);
        if ($products->isEmpty()) {
            return 'Choose an available destination with products that can currently be purchased.';
        }
        if ($publishing && in_array($revision->destination_type, ['collection', 'offer'], true)
            && $products->count() !== count($revision->product_ids ?? [])) {
            return 'Some selected products are no longer available. Update the collection before publishing.';
        }
        if (($revision->purpose === 'offer' || $revision->destination_type === 'offer') && ! $revision->offer_revision_id) {
            return 'Select a published voucher for artwork that promises an offer.';
        }
        if ($revision->offer_revision_id) {
            $offer = VoucherRevision::find($revision->offer_revision_id);
            $voucher = $offer ? Voucher::with(['currentRevision.balance', 'shop'])->find($offer->voucher_id) : null;
            if (! $voucher || (int) $voucher->current_revision_id !== (int) $offer->id) {
                return 'The linked offer terms changed. Save and review the advertisement with the current offer.';
            }
            $state = app(VoucherCampaignService::class)->state($voucher);
            if (! in_array($state, $publishing ? ['active', 'scheduled'] : ['active'], true)) {
                return 'The linked offer is not currently available.';
            }
            if ($offer->shop_id && $products->contains(fn (Product $product) => (int) $product->shop_id !== (int) $offer->shop_id)) {
                return 'The selected products must belong to the shop funding this voucher.';
            }
            if ($publishing && (($start ?? $revision->starts_at)->lessThan($offer->starts_at)
                || ($end ?? $revision->ends_at)->greaterThan($offer->ends_at))) {
                return 'The advertisement must start and end within the linked offer period.';
            }
        }

        return null;
    }

    public function assertPublishable(AdvertisementRevision $revision, CarbonImmutable $start, CarbonImmutable $end): void
    {
        if ($problem = $this->problem($revision, true, $start, $end)) {
            throw ValidationException::withMessages(['advertisement' => $problem]);
        }
    }

    public function target(AdvertisementRevision $revision): array
    {
        return match ($revision->destination_type) {
            'product' => ($product = Product::find($revision->destination_id))
                ? ['label' => $product->name, 'url' => route('products.show', $product->slug)] : [],
            'shop' => ($shop = Shop::find($revision->destination_id))
                ? ['label' => $shop->name, 'url' => route('shop.show', $shop->slug)] : [],
            'category' => ($category = Category::find($revision->destination_id))
                ? ['label' => $category->name, 'url' => route('buyer.search', ['category' => $category->slug])] : [],
            'collection' => ['label' => 'Matching product collection', 'url' => null],
            'offer' => ['label' => 'Published offer terms and matching products', 'url' => null],
            default => [],
        };
    }

    public function offerTerms(AdvertisementRevision $revision): ?array
    {
        $offer = $revision->offer_revision_id ? VoucherRevision::with('shop')->find($revision->offer_revision_id) : null;
        if (! $offer) {
            return null;
        }

        return $offer->only(['code', 'name', 'description', 'issuer_type', 'discount_type', 'value_cents',
            'percentage_bps', 'minimum_cents', 'maximum_cents', 'per_buyer_limit', 'starts_at', 'ends_at'])
            + ['shop_name' => $offer->shop?->name];
    }

    public function options(): array
    {
        $offers = Voucher::with(['currentRevision.balance', 'shop'])->whereNotNull('current_revision_id')->get()
            ->filter(fn (Voucher $voucher) => in_array(app(VoucherCampaignService::class)->state($voucher), ['active', 'scheduled'], true));

        return [
            'products' => Product::availableForSale()->where('status', 'active')->where('stock', '>', 0)->orderBy('name')
                ->get(['id', 'name', 'shop_id'])->map(fn ($product) => ['id' => $product->id, 'name' => $product->name, 'shop_id' => $product->shop_id]),
            'shops' => Shop::eligible()->orderBy('name')->get(['id', 'name']),
            'categories' => Category::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'offers' => $offers->map(fn ($voucher) => ['id' => $voucher->current_revision_id, 'name' => $voucher->currentRevision->name,
                'code' => $voucher->code, 'shop_id' => $voucher->shop_id,
                'starts_at' => $voucher->currentRevision->starts_at->toIso8601String(), 'ends_at' => $voucher->currentRevision->ends_at->toIso8601String()])->values(),
        ];
    }

    private function categoryIds(?int $id): array
    {
        $category = Category::whereKey($id)->where('is_active', true)->first();

        return $category ? app(BuyerDiscoveryService::class)->categoryIds($category->slug) : [];
    }
}
