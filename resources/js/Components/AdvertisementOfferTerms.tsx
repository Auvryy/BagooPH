import { OfferTerms, dateLabel } from '@/types/advertisements';

const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
export default function AdvertisementOfferTerms({ offer }: { offer: OfferTerms }) {
    const discount = offer.discount_type === 'percent' ? `${(offer.percentage_bps ?? 0) / 100}% off eligible products`
        : offer.discount_type === 'free_shipping' ? `Shipping discount up to ${money(offer.maximum_cents ?? offer.value_cents)}`
        : `${money(Math.min(offer.value_cents, offer.maximum_cents ?? offer.value_cents))} off eligible products`;
    return <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 sm:p-6" aria-label="Offer terms">
        <div><p className="text-xs font-bold uppercase tracking-wide text-[#E00D42]">{offer.issuer_type === 'shop' ? offer.shop_name : 'Bagoo'} offer</p><h2 className="mt-2 text-xl font-bold text-slate-900">{offer.name}</h2></div>
        <p className="font-bold text-[#E00D42]">{discount}</p>
        {offer.description && <p className="whitespace-pre-line text-sm leading-relaxed text-slate-600">{offer.description}</p>}
        <dl className="grid gap-4 text-sm sm:grid-cols-2">
            <div><dt className="text-slate-500">Voucher code</dt><dd className="mt-1 font-bold">{offer.code}</dd></div>
            <div><dt className="text-slate-500">Minimum eligible spend</dt><dd className="mt-1 font-bold">{money(offer.minimum_cents)}</dd></div>
            <div><dt className="text-slate-500">Maximum saving</dt><dd className="mt-1 font-bold">{money(offer.maximum_cents ?? offer.value_cents)}</dd></div>
            <div><dt className="text-slate-500">Uses per buyer</dt><dd className="mt-1 font-bold">Up to {offer.per_buyer_limit}</dd></div>
            <div className="sm:col-span-2"><dt className="text-slate-500">Offer period (Asia/Manila)</dt><dd className="mt-1">{dateLabel(offer.starts_at)} – {dateLabel(offer.ends_at)}</dd></div>
        </dl>
        <p className="text-xs leading-relaxed text-slate-500">Availability, remaining uses, minimum spend and applicable products are checked again at checkout. Opening this page does not apply or save a voucher. Shipping discounts apply to eligible delivery fees; any remaining shipping charge is shown before you place an order.</p>
    </section>;
}
