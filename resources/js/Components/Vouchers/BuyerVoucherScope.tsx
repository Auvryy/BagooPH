import { Link } from '@inertiajs/react';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';

export default function BuyerVoucherScope({ offer }: { offer: VoucherOffer }) {
    if (offer.product_scope !== 'selected') return null;
    const products = offer.eligible_products ?? [];
    return <div className="space-y-1 text-xs text-slate-600">
        <p className="font-semibold text-slate-800">Selected products only</p>
        <p>Only qualifying products count toward the minimum spend and discount.</p>
        {products.length ? <details className="pt-1"><summary className="cursor-pointer font-semibold text-[#C20836]">Eligible products ({products.length})</summary>
            <ul className="mt-2 max-h-40 space-y-2 overflow-y-auto pl-3">{products.map(product => <li key={product.id}>
                <Link href={product.url} className="inline-block break-words py-1 text-[#C20836] underline decoration-rose-200 underline-offset-2 hover:decoration-[#C20836]">{product.name}</Link>
            </li>)}</ul>
        </details> : <p className="text-amber-800">Qualifying products are currently unavailable.</p>}
    </div>;
}
