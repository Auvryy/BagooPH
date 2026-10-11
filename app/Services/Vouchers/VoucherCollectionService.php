<?php

namespace App\Services\Vouchers;

use App\Models\User;
use App\Models\VoucherRevision;
use App\Services\BuyerAccessService;
use App\Services\Orders\CheckoutQuoteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class VoucherCollectionService
{
    public function collection(User $actor, Request $request): array
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $tab = $request->validate(['voucher_tab' => 'nullable|in:available,saved,used,expired'])['voucher_tab'] ?? 'available';
        $queries = [
            'available' => app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer),
            'saved' => VoucherRevision::whereHas('savedVouchers', fn (Builder $q) => $q->where('buyer_id', $buyer->id)),
            'used' => VoucherRevision::whereHas('redemptions', fn (Builder $q) => $q->where('buyer_id', $buyer->id)),
            'expired' => VoucherRevision::where('ends_at', '<=', now())->where(fn (Builder $q) => $q
                ->whereHas('savedVouchers', fn (Builder $s) => $s->where('buyer_id', $buyer->id))
                ->orWhereHas('redemptions', fn (Builder $u) => $u->where('buyer_id', $buyer->id))),
        ];
        $counts = array_map(fn (Builder $q) => (clone $q)->count(), $queries);
        $offers = $queries[$tab]->with(['voucher', 'shop:id,name,slug', 'balance',
            'savedVouchers' => fn ($q) => $q->where('buyer_id', $buyer->id),
            'redemptions' => fn ($q) => $q->where('buyer_id', $buyer->id)->latest('id'),
        ])->orderByDesc('id')->paginate(12, ['*'], 'voucher_page')->withQueryString()
            ->through(fn (VoucherRevision $revision) => $this->present($revision, $buyer));

        return ['tab' => $tab, 'counts' => $counts, 'offers' => $offers];
    }

    public function present(VoucherRevision $revision, ?User $buyer = null): array
    {
        $available = app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer)->whereKey($revision->id)->exists();
        $voucher = $revision->voucher;
        $reason = $available ? null : match (true) {
            $revision->ends_at->lessThanOrEqualTo(now()) => 'This voucher has expired.',
            ! $voucher || (bool) $voucher->archived_at => 'This campaign has ended. Your saved terms remain available.',
            $voucher->current_revision_id !== $revision->id => 'The shop or Bagoo has published new terms. This saved version is no longer usable.',
            ! $voucher->is_active => 'This voucher is paused.',
            $revision->starts_at->isFuture() => 'This voucher is not available yet.',
            default => 'This voucher is unavailable for new uses.',
        };
        $saved = $buyer ? $revision->savedVouchers->firstWhere('buyer_id', $buyer->id) : null;
        $uses = $buyer && $revision->relationLoaded('redemptions') ? $revision->redemptions : collect();

        return [...app(CheckoutQuoteService::class)->present($revision), 'eligible' => $available,
            'saved_id' => $saved?->id, 'unavailable_reason' => $reason,
            'uses' => $uses->map(fn ($use) => ['id' => $use->id, 'used_at' => $use->created_at->toIso8601String(),
                'saving' => $use->discount_cents / 100, 'code' => $use->terms['code'], 'name' => $use->terms['name']])->all()];
    }

    public function shopOffers(int $shopId, ?User $buyer = null): array
    {
        if ($buyer && (! $buyer->isBuyer() || ! $buyer->canAccessPortal())) {
            $buyer = null;
        }

        return app(CheckoutQuoteService::class)->availableQuery(buyer: $buyer)->where('issuer_type', 'shop')->where('shop_id', $shopId)
            ->with(['savedVouchers' => fn ($q) => $q->where('buyer_id', $buyer?->id ?? 0)])
            ->orderByDesc('id')->limit(6)->get()->map(fn ($revision) => $this->present($revision, $buyer))->all();
    }
}
