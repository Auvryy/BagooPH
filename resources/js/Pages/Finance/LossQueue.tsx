import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Search, ShieldCheck } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import FinancialCaseNav from '@/Components/Finance/FinancialCaseNav';
import { lossStatuses } from '@/Components/Finance/lossStatuses';
import { financeMoney } from '@/utils/finance';

interface Page<T> { data: T[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }
interface Row { id: number; reference: string; order_number: string; mode: string; status: keyof typeof lossStatuses;
    amount_label: string; amount_cents: number; outstanding_cents: number | null; href: string }
const button = 'inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] transition-colors hover:bg-[#E00D42] hover:text-white';
const field = 'mt-2 w-full rounded-lg border border-slate-300 bg-white text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';
function Pages({ value, label }: { value: Page<unknown>; label: string }) {
    return <nav aria-label={label} className="flex items-center justify-between gap-3 text-sm">{value.prev_page_url ? <Link href={value.prev_page_url} className={button}>Previous</Link> : <span />}<span className="text-slate-600">Page {value.current_page} of {value.last_page}</span>{value.next_page_url ? <Link href={value.next_page_url} className={button}>Next</Link> : <span />}</nav>;
}

export default function LossQueue({ cases, reviewCandidates, filters }: { cases: Page<Row>;
    reviewCandidates: Page<{ id: number; reference: string; manifest_reference: string; scope: string; href: string }> | null;
    filters: { view: string; search: string; status: string; order_id?: number } }) {
    const form = useForm(filters);
    const submit = (event: FormEvent) => { event.preventDefault(); if (!form.processing) form.get('/return-liabilities', { preserveState: true, preserveScroll: true }); };
    return <ReturnLayout title="Return liabilities"><Head title="Parcel loss finances" /><main className="mx-auto max-w-5xl space-y-6 py-4">
        <header><h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900"><ShieldCheck className="h-6 w-6 text-[#E00D42]" />Parcel loss finances</h1><p className="mt-2 text-sm text-slate-600">Reviewed loss amounts and their financial history.</p></header>
        <FinancialCaseNav view="losses" orderId={filters.order_id} />
        {filters.order_id && <Link href="/return-liabilities?view=losses" className={button}>Show all orders</Link>}
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
            <label className="min-w-48 flex-1 text-sm font-semibold text-slate-900">Reference<input maxLength={80} value={form.data.search} onChange={event => form.setData('search', event.target.value)} className={field} /></label>
            <label className="text-sm font-semibold text-slate-900">Status<select value={form.data.status} onChange={event => form.setData('status', event.target.value)} className={field}><option value="">All statuses</option>{Object.entries(lossStatuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
            <button disabled={form.processing} className="inline-flex items-center gap-2 rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50"><Search className="h-4 w-4" />Search</button>
            {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="w-full text-sm text-rose-700">{String(error)}</p>)}
        </form>
        <div className="grid gap-4 md:grid-cols-2">{cases.data.map(item => <article key={item.id} className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="break-all text-sm font-bold text-slate-900">{item.order_number}</h2><p className="mt-1 break-all text-xs text-slate-500">{item.reference}</p></div><span className="rounded-lg bg-rose-50 px-2 py-1.5 text-xs font-semibold text-[#C20836]">{lossStatuses[item.status]}</span></div>
            <dl className="grid gap-4 sm:grid-cols-2"><div><dt className="text-xs text-slate-600">{item.amount_label} · {item.mode === 'wallet' ? 'Virtual Wallet' : 'COD'}</dt><dd className="mt-1 font-bold text-slate-900">{financeMoney(item.amount_cents)}</dd></div>
                {item.outstanding_cents !== null && <div><dt className="text-xs text-slate-600">Company funding outstanding</dt><dd className="mt-1 font-bold text-slate-900">{financeMoney(item.outstanding_cents)}</dd></div>}</dl>
            <Link href={item.href} className={button}>Review finances</Link>
        </article>)}</div>
        {!cases.data.length && <p className="rounded-lg border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">No reviewed parcel losses match these filters.</p>}
        <Pages value={cases} label="Loss finance pages" />
        {reviewCandidates && <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 shadow-sm"><h2 className="font-bold text-slate-900">Missing observations awaiting loss review</h2>
            {reviewCandidates.data.length ? <ul className="divide-y divide-slate-200">{reviewCandidates.data.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 py-4"><div><p className="break-all text-sm font-semibold text-slate-900">{item.reference}</p><p className="mt-1 break-all text-xs text-slate-500">{item.manifest_reference} · {item.scope === 'buyer_return' ? 'Buyer return parcel' : 'Undelivered parcel'}</p></div><Link href={item.href} className={button}>Review actual loss</Link></li>)}</ul>
                : <p className="text-sm text-slate-600">No eligible loss reviews on this observation page.</p>}
            <Pages value={reviewCandidates} label="Missing observation pages" />
        </section>}
    </main></ReturnLayout>;
}
