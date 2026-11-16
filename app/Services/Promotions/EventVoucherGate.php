<?php

namespace App\Services\Promotions;

use App\Models\SaleEventClaim;
use App\Models\SaleEventVoucher;
use App\Models\User;
use App\Models\VoucherRevision;

/** A voucher added to an event with a claim limit can only be used by the buyers who claimed it. */
class EventVoucherGate
{
    /** SQL for VoucherRevision queries: keep vouchers that are open to all, or that this buyer claimed. */
    public static function whereUsableSql(): string
    {
        return '(not exists (select 1 from sale_event_vouchers g where g.voucher_id = voucher_revisions.voucher_id and g.claim_limit is not null)'
            .' or exists (select 1 from sale_event_claims c join sale_event_vouchers g on g.id = c.sale_event_voucher_id'
            .' where g.voucher_id = voucher_revisions.voucher_id and g.claim_limit is not null and c.buyer_id = ?))';
    }

    public static function blocked(VoucherRevision $revision, ?User $buyer): bool
    {
        $limited = SaleEventVoucher::where('voucher_id', $revision->voucher_id)->whereNotNull('claim_limit')->pluck('id');
        if ($limited->isEmpty()) {
            return false;
        }

        return ! $buyer || ! SaleEventClaim::whereIn('sale_event_voucher_id', $limited)->where('buyer_id', $buyer->id)->exists();
    }
}
