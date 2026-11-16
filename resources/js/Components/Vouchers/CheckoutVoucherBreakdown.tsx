import { PurchaseQuote } from '@/hooks/usePurchaseQuote';

export default function CheckoutVoucherBreakdown({ quote, shopId, price }: { quote: PurchaseQuote; shopId?: number; price: (value: number) => string }) {
    const shop = shopId === undefined ? undefined : quote.shops.find(row => row.shop_id === shopId);
    const merchandise = shop?.subtotal ?? quote.subtotal;
    const shipping = shop?.shipping ?? quote.shipping_fee;
    const shippingDiscount = shopId === undefined ? quote.shipping_discount : shop?.shipping_discount ?? 0;
    const rows = (quote.vouchers ?? (quote.voucher ? [quote.voucher] : [])).map(offer => {
        const use = quote.redemptions?.find(row => row.revision_id === offer.revision_id);
        const allocation = use?.allocations?.find(row => row.shop_id === shopId);
        const amount = shopId === undefined ? (use?.discount_cents ?? 0) / 100 : ((allocation?.product_discount_cents ?? 0) + (allocation?.shipping_discount_cents ?? 0)) / 100;
        return { offer, amount };
    }).filter(row => row.amount > 0);
    return <dl className="space-y-2 text-sm text-ink-600 [&_dd]:tabular-nums [&_dd]:text-right">
        <div className="flex justify-between gap-3"><dt>Items</dt><dd>{price(merchandise)}</dd></div>
        {rows.map(({ offer, amount }) => <div key={offer.revision_id} className="flex justify-between gap-3 text-success"><dt>{offer.issuer === 'shop' ? 'Shop discount' : offer.discount_type === 'free_shipping' ? 'Bagoo shipping discount' : 'Bagoo product discount'} · {offer.code}{offer.product_scope === 'selected' && <span className="mt-0.5 block text-xs text-ink-600">Selected products only</span>}</dt><dd>−{price(amount)}</dd></div>)}
        <div className="flex justify-between gap-3"><dt>Shipping before discounts</dt><dd>{price(shipping)}</dd></div>
        <div className="flex justify-between gap-3"><dt>{shipping - shippingDiscount === 0 ? 'Free shipping' : 'Shipping payable'}</dt><dd>{price(shipping - shippingDiscount)}</dd></div>
        {(shop?.discount ?? quote.discount) > 0 && <div className="flex justify-between gap-3 font-medium text-success"><dt>You save</dt><dd>{price(shop?.discount ?? quote.discount)}</dd></div>}
        {shop && <div className="flex justify-between gap-3 border-t border-line-200 pt-2 font-semibold text-ink-900"><dt>Shop total COD</dt><dd>{price(shop.total)}</dd></div>}
    </dl>;
}
