<?php

namespace App\Services\Vouchers;

use App\Models\Product;
use App\Models\VoucherRevision;
use App\Services\Orders\CheckoutQuoteService;
use Illuminate\Support\Collection;

/**
 * Tells product cards about a live shop voucher that applies to the product, so buyers see the saving
 * before checkout. One query covers the whole page; checkout still decides the actual discount.
 */
class ProductVoucherHints
{
    /** @param  Collection<int, Product>  $products */
    public function attach(Collection $products): void
    {
        $shopIds = $products->pluck('shop_id')->filter()->unique()->values();
        if ($shopIds->isEmpty()) {
            return;
        }
        $offers = app(CheckoutQuoteService::class)->availableQuery()
            ->where('issuer_type', 'shop')->whereIn('shop_id', $shopIds)->get()->groupBy('shop_id');
        foreach ($products as $product) {
            $best = ($offers[$product->shop_id] ?? collect())
                ->filter(fn (VoucherRevision $revision) => ($revision->product_scope ?? 'all') === 'all'
                    || in_array($product->id, $revision->product_ids ?? [], true))
                ->sortByDesc(fn (VoucherRevision $revision) => $revision->discount_type === 'percent'
                    ? (int) round(($revision->percentage_bps / 10000) * ($product->price * 100)) : $revision->value_cents)
                ->first();
            $product->setAttribute('voucher_hint', $best ? [
                'label' => $this->label($best),
                'min_spend' => $best->minimum_cents / 100,
            ] : null);
        }
    }

    private function label(VoucherRevision $revision): string
    {
        $amount = fn (int $cents) => '₱'.rtrim(rtrim(number_format($cents / 100, 2, '.', ','), '0'), '.');

        return match ($revision->discount_type) {
            'percent' => rtrim(rtrim(number_format($revision->percentage_bps / 100, 2), '0'), '.').'% off',
            'free_shipping' => $amount($revision->value_cents).' off shipping',
            default => $amount($revision->value_cents).' off',
        };
    }
}
