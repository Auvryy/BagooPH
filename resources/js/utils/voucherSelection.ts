import type { VoucherOffer } from '@/hooks/usePurchaseQuote';

export interface VoucherSelection {
    voucher_code: string;
    platform_voucher_revision_id: number | null;
    shop_voucher_revision_ids: Record<number, number | null>;
}

function materialize(selection: VoucherSelection, applied: VoucherOffer[]): VoucherSelection {
    const result = { ...selection, voucher_code: '', shop_voucher_revision_ids: { ...selection.shop_voucher_revision_ids } };
    // A successful code quote supplies its slot; ID selections already have their own current state.
    for (const offer of selection.voucher_code ? applied : []) {
        if (!offer.revision_id) continue;
        if (offer.issuer === 'platform') result.platform_voucher_revision_id = offer.revision_id;
        else if (offer.shop_id) result.shop_voucher_revision_ids[offer.shop_id] = offer.revision_id;
    }
    return result;
}

export function chooseVoucher(selection: VoucherSelection, offer: VoucherOffer, applied: VoucherOffer[]): VoucherSelection {
    const result = materialize(selection, applied);
    if (offer.revision_id) {
        if (offer.issuer === 'platform') result.platform_voucher_revision_id = offer.revision_id;
        else if (offer.shop_id) result.shop_voucher_revision_ids[offer.shop_id] = offer.revision_id;
    }
    return result;
}

export function withoutVoucher(selection: VoucherSelection, shopId: number | null, applied: VoucherOffer[]): VoucherSelection {
    const result = materialize(selection, applied);
    if (shopId === null) result.platform_voucher_revision_id = null;
    else result.shop_voucher_revision_ids[shopId] = null;
    return result;
}
