import { Link } from '@inertiajs/react';

export default function FinancialCaseNav({ view, orderId }: { view: string; orderId?: number }) {
    return <nav aria-label="Financial case type" className="flex flex-wrap gap-2">{[
        ['returns', 'Item returns'], ['shipments', 'Undelivered parcels'], ['losses', 'Parcel losses'],
    ].map(([key, label]) => <Link key={key} href={`/return-liabilities?view=${key}${orderId ? `&order_id=${orderId}` : ''}`} aria-current={view === key ? 'page' : undefined}
        className={`rounded-lg border px-3 py-2 text-sm font-semibold transition-colors ${view === key
            ? 'border-[#E00D42] bg-[#E00D42] text-white' : 'border-rose-200 bg-rose-50/85 text-[#C20836] hover:bg-[#E00D42] hover:text-white'}`}>{label}</Link>)}</nav>;
}
