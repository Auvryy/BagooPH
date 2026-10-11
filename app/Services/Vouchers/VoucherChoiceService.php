<?php

namespace App\Services\Vouchers;

use App\Exceptions\CheckoutException;
use App\Models\User;
use App\Models\VoucherRevision;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\VoucherPreviewService;
use App\Services\ShopEligibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VoucherChoiceService
{
    public const MAX_CAMPAIGNS = 40;

    public const MAX_COMBINATIONS = 4096;

    public function options(User $buyer, Collection $items, string $deliveryType, array $input, ?int $shopId): array
    {
        if ($shopId !== null && ! $items->pluck('product.shop_id')->contains($shopId)) {
            throw new CheckoutException('This voucher is not for the selected shop items.');
        }
        $selected = app(VoucherPreviewService::class)->preview($buyer, $items, $deliveryType, $input)['revisions'];
        $query = VoucherRevision::with(['voucher', 'balance', 'shop:id,name,slug',
            'savedVouchers' => fn ($q) => $q->where('buyer_id', $buyer->id)])
            ->where('issuer_type', $shopId === null ? 'platform' : 'shop')->where('shop_id', $shopId)
            ->where(fn (Builder $q) => $q->whereHas('voucher', fn (Builder $v) => $v->whereColumn('current_revision_id', 'voucher_revisions.id'))
                ->orWhereHas('savedVouchers', fn (Builder $s) => $s->where('buyer_id', $buyer->id)));
        $offers = $query->orderByDesc('id')->paginate(12, ['*'], 'voucher_page')->through(function ($revision) use ($selected, $items, $deliveryType, $buyer) {
            $candidates = $selected->reject(fn ($r) => $r->issuer_type === $revision->issuer_type && $r->shop_id === $revision->shop_id)->push($revision);
            $quote = app(CheckoutQuoteService::class)->quote($items, $deliveryType, $candidates, $buyer);
            $saving = collect($quote['redemptions'])->firstWhere('revision_id', $revision->id)['discount_cents'] ?? 0;

            return [...app(CheckoutQuoteService::class)->present($revision), 'saved_id' => $revision->savedVouchers->first()?->id,
                'eligible' => $quote['voucher_error'] === null, 'unavailable_reason' => $quote['voucher_error'], 'saving' => $saving / 100];
        });

        return ['offers' => $offers];
    }

    /** Enumerate every bounded allowed combination; never truncate and label a partial search best. */
    public function best(User $buyer, Collection $items, string $deliveryType): array
    {
        $quotes = app(CheckoutQuoteService::class);
        $query = $quotes->availableQuery($items, $buyer);
        if ((clone $query)->count() > self::MAX_CAMPAIGNS) {
            throw new CheckoutException('There are too many offers to compare together. Choose your vouchers separately.');
        }
        $offers = $query->withCount(['redemptions as buyer_uses_count' => fn ($q) => $q->where('buyer_id', $buyer->id)])->orderBy('id')->get();
        $slots = [];
        foreach ($items->pluck('product.shop_id')->unique()->sort() as $shopId) {
            $shopOffers = $offers->where('issuer_type', 'shop')->where('shop_id', $shopId);
            if ($shopOffers->isNotEmpty()) {
                $slots[] = [null, ...$shopOffers->all()];
            }
        }
        $slots[] = [null, ...$offers->where('issuer_type', 'platform')->all()];
        $combinations = 1;
        foreach ($slots as $slot) {
            $combinations *= count($slot);
            if ($combinations > self::MAX_COMBINATIONS) {
                throw new CheckoutException('There are too many voucher combinations to compare. Choose your vouchers separately.');
            }
        }
        foreach ($offers as $offer) {
            // Request-local read snapshot only. Final checkout reloads and locks fresh records.
            $offer->setAttribute('quote_buyer_id', $buyer->id);
            $offer->setAttribute('budget_increases_sum_budget_increase_cents', $offer->budget_increases_sum_budget_increase_cents ?? 0);
            $offer->setAttribute('quote_shop_eligible', $offer->issuer_type === 'platform' || app(ShopEligibilityService::class)->isEligible($offer->shop));
        }
        $best = collect();
        $bestQuote = $quotes->quote($items, $deliveryType);
        $bestSaving = 0;
        $checked = 0;
        foreach ($this->combinations($slots) as $selection) {
            $checked++;
            $quote = $quotes->quote($items, $deliveryType, $selection, $buyer);
            $saving = array_sum(array_column($quote['redemptions'], 'discount_cents'));
            if ($quote['voucher_error'] === null && ($saving > $bestSaving || ($saving === $bestSaving && $this->tie($selection, $best) < 0))) {
                $best = $selection;
                $bestQuote = $quote;
                $bestSaving = $saving;
            }
        }
        $map = $items->pluck('product.shop_id')->unique()->mapWithKeys(fn ($id) => [$id => null])->all();
        foreach ($best->where('issuer_type', 'shop') as $offer) {
            $map[$offer->shop_id] = $offer->id;
        }
        ksort($map, SORT_NUMERIC);

        return ['selection' => ['voucher_code' => null, 'shop_voucher_revision_ids' => $map,
            'platform_voucher_revision_id' => $best->firstWhere('issuer_type', 'platform')?->id],
            'quote' => $bestQuote, 'combinationsChecked' => $checked];
    }

    private function combinations(array $slots, int $index = 0, ?Collection $chosen = null): \Generator
    {
        $chosen ??= collect();
        if ($index === count($slots)) {
            yield $chosen;

            return;
        }
        foreach ($slots[$index] as $offer) {
            yield from $this->combinations($slots, $index + 1, $offer ? $chosen->concat([$offer]) : $chosen);
        }
    }

    private function tie(Collection $left, Collection $right): int
    {
        return $left->count() <=> $right->count() ?: ($left->pluck('id')->sort()->values()->all() <=> $right->pluck('id')->sort()->values()->all());
    }
}
