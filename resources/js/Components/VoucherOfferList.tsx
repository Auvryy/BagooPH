import { Link } from '@inertiajs/react';
import { Tag } from 'lucide-react';
import { VoucherOffer } from '@/hooks/usePurchaseQuote';
import SavedVoucherButton from '@/Components/Vouchers/SavedVoucherButton';

const pesos = (value: number | string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value));

export default function VoucherOfferList({ offers, busy = false, onSelect, allowSave = false, emptyTitle = 'No available offers', emptyDescription = 'Shop and Bagoo offers will appear here when available.' }: {
    offers: VoucherOffer[];
    busy?: boolean;
    onSelect?: (code: string, offer: VoucherOffer) => void;
    allowSave?: boolean;
    emptyTitle?: string;
    emptyDescription?: string;
}) {
    if (offers.length === 0) {
        return <div className="flex min-h-48 flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
            <Tag aria-hidden="true" className="h-6 w-6 text-slate-400" />
            <p className="font-bold text-slate-800">{emptyTitle}</p>
            <p className="text-xs leading-relaxed text-slate-500">{emptyDescription}</p>
        </div>;
    }

    return <div className="space-y-3">
        {offers.map(offer => {
            const shippingCap = Number(offer.discount_value) > 0 ? Number(offer.discount_value) : null;
            const cap = offer.max_discount === null ? shippingCap : shippingCap === null
                ? Number(offer.max_discount) : Math.min(shippingCap, Number(offer.max_discount));
            const benefit = offer.discount_type === 'free_shipping'
                ? 'Shipping discount' + (cap === null ? '' : ' up to ' + pesos(cap))
                : offer.discount_type === 'percent' ? Number(offer.discount_value) + '% off'
                    : pesos(Math.min(Number(offer.discount_value), Number(offer.max_discount ?? offer.discount_value))) + ' off';
            return <div key={offer.revision_id ?? offer.id} className="space-y-2 rounded-2xl border border-slate-300 bg-white p-4">
                <p className="text-xs font-semibold text-slate-600">{offer.issuer === 'shop' ? offer.shop_name : 'Bagoo offer'}</p>
                <h3 className="font-bold text-slate-900">{offer.name || benefit}</h3>
                <p className="text-sm font-bold text-[#C20836]">{benefit}</p>
                <p className="text-xs text-slate-600">Minimum spend {pesos(offer.min_spend)}
                    {offer.discount_type === 'percent' && offer.max_discount !== null ? ' · Maximum discount ' + pesos(offer.max_discount) : ''}</p>
                {offer.expires_at && <p className="text-xs text-slate-500">Expires {new Date(offer.expires_at).toLocaleString('en-PH', {
                    timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short',
                })}</p>}
                {offer.description && <p className="text-xs leading-relaxed text-slate-600">{offer.description}</p>}
                {offer.unavailable_reason && <p className="text-xs text-amber-800">{offer.unavailable_reason}</p>}
                {offer.uses?.map(use => <p key={use.id} className="text-xs text-slate-600">Used {new Date(use.used_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })} · Saved {pesos(use.saving)}</p>)}
                <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                    <span className="text-xs font-semibold text-slate-600">{offer.code}</span>
                    {onSelect ? <button type="button" disabled={busy || offer.eligible === false}
                        onClick={() => onSelect(offer.code, offer)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-bold text-[#C20836] hover:bg-rose-50 disabled:opacity-50">
                        {offer.eligible === false ? 'Unavailable' : 'Use voucher'}
                        {offer.saving != null && offer.eligible !== false ? ' · Save ' + pesos(offer.saving) : ''}
                    </button> : offer.eligible === false ? <span className="text-xs text-slate-500">Unavailable</span> : <Link href={offer.use_url ?? route('buyer.cart')}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-bold text-[#C20836] hover:bg-rose-50">Use voucher</Link>}
                </div>
                {allowSave && <SavedVoucherButton offer={offer} />}
            </div>;
        })}
    </div>;
}
