import { Link } from '@inertiajs/react';
import { Receipt } from 'lucide-react';

export interface FinancialReviews {
    records: Array<{ id: number; reference: string; kind: string; active: boolean; return_reference: string | null; href: string }>;
    histories: Array<{ kind: string; count: number; href: string }>;
}
const button = 'inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white';

export default function FinancialReviewLinks({ value, fallbackUrl }: { value?: FinancialReviews | null; fallbackUrl?: string | null }) {
    if (!value?.records.length) return fallbackUrl ? <section className="space-y-3 rounded-lg border border-rose-200 bg-white p-4"><h2 className="text-sm font-bold text-slate-900">Shipping and refund review</h2><Link href={fallbackUrl} className={button}>Review finances</Link></section> : null;
    return <section aria-label="Original financial reviews" className="space-y-4 rounded-lg border border-rose-200 bg-white p-4">
        <h2 className="flex items-center gap-2 text-sm font-bold text-slate-900"><Receipt className="h-4 w-4 text-[#E00D42]" />Shipping and refunds</h2>
        <ul className="divide-y divide-slate-200">{value.records.map(record => <li key={record.id} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"><div className="min-w-0 space-y-1">
            <p className="text-sm font-semibold text-slate-900">{record.kind === 'transit_loss' ? 'Parcel loss review' : 'Shipping review'}{!record.active && <span className="ml-2 text-xs font-normal text-slate-500">Earlier review</span>}</p>
            {record.return_reference && <p className="break-all text-xs text-slate-600">Return {record.return_reference}</p>}<p className="break-all text-xs text-slate-500">{record.reference}</p>
        </div><Link href={record.href} className={button}>Review finances</Link></li>)}</ul>
        {value.histories.filter(history => history.count > value.records.filter(record => record.kind === history.kind).length).map(history => <Link key={history.kind} href={history.href} className={button}>All {history.kind === 'transit_loss' ? 'loss' : 'shipping'} reviews ({history.count})</Link>)}
    </section>;
}
