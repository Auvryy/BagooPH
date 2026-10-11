<?php

namespace App\Services\Vouchers;

use App\Models\SavedVoucher;
use App\Models\User;
use App\Models\VoucherRevision;
use App\Services\BuyerAccessService;
use App\Services\ShopEligibilityService;

class SavedVoucherService
{
    public function save(User $actor, VoucherRevision $revision): SavedVoucher
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $revision = VoucherRevision::with('voucher')->findOrFail($revision->id);
        abort_unless($revision->voucher?->is_active && ! $revision->voucher->archived_at
            && $revision->voucher->current_revision_id === $revision->id
            && $revision->voucher->issuer_type === $revision->issuer_type
            && ($revision->issuer_type === 'platform' || ($revision->shop && app(ShopEligibilityService::class)->isEligible($revision->shop)))
            && $revision->starts_at->lessThanOrEqualTo(now()) && $revision->ends_at->isFuture(), 422, 'This offer is unavailable to save.');

        // Bookmarks deliberately do not lock, reserve or consume funding and quota.
        return SavedVoucher::firstOrCreate(['buyer_id' => $buyer->id, 'voucher_revision_id' => $revision->id]);
    }

    public function remove(User $actor, SavedVoucher $saved): void
    {
        $buyer = app(BuyerAccessService::class)->requirePortal($actor);
        $saved = SavedVoucher::findOrFail($saved->id);
        abort_unless($saved->buyer_id === $buyer->id, 403);
        SavedVoucher::whereKey($saved->id)->where('buyer_id', $buyer->id)->delete();
    }
}
