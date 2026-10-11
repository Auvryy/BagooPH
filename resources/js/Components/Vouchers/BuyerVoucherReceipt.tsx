export interface VoucherReceipt {
    merchandise: number; shipping_before: number; shipping_after: number; total_savings: number; total: number; legacy_discount: boolean;
    discounts: { code: string; name: string; issuer: 'shop' | 'platform'; product_discount: number; shipping_discount: number }[];
}
const money = (value: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
export default function BuyerVoucherReceipt({ receipt }: { receipt: VoucherReceipt }) {
    return <dl className="space-y-3 text-xs text-slate-600">
        <div className="flex justify-between gap-3"><dt>Merchandise</dt><dd>{money(receipt.merchandise)}</dd></div>
        {receipt.discounts.map((offer, index) => <div key={index} className="flex justify-between gap-3 text-[#C20836]"><dt>{offer.issuer === 'shop' ? 'Shop voucher' : offer.shipping_discount > 0 ? 'Bagoo shipping discount' : 'Bagoo product discount'} · {offer.code}</dt><dd>−{money(offer.product_discount + offer.shipping_discount)}</dd></div>)}
        {receipt.legacy_discount && <div className="flex justify-between gap-3"><dt>Recorded voucher discount</dt><dd>−{money(receipt.total_savings)}</dd></div>}
        <div className="flex justify-between gap-3"><dt>Shipping before discounts</dt><dd>{money(receipt.shipping_before)}</dd></div>
        <div className="flex justify-between gap-3"><dt>{receipt.shipping_after === 0 ? 'Free shipping' : 'Shipping payable'}</dt><dd>{money(receipt.shipping_after)}</dd></div>
        <div className="flex justify-between gap-3 font-semibold text-[#C20836]"><dt>Total savings</dt><dd>{money(receipt.total_savings)}</dd></div>
        <div className="flex justify-between gap-3 border-t border-slate-300 pt-3 text-sm font-bold text-slate-900"><dt>Total COD</dt><dd>{money(receipt.total)}</dd></div>
    </dl>;
}
