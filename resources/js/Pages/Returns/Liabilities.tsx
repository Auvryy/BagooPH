import { FormEvent } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Receipt, Search } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnStatus, { returnLabels } from '@/Components/Returns/ReturnStatus';
import { shipmentStatuses } from '@/Components/Finance/ShipmentAmounts';
import FinancialCaseNav from '@/Components/Finance/FinancialCaseNav';

interface Row { id: number; reference: string; status: string; order_number: string; mode: string; refund_cents: number; outstanding_cents: number | null; kind?: string; href?: string }
interface Page<T> { data: T[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number }
const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
const returnStatuses = ['repayment_ready', 'payment_recorded', 'repayment_disputed', 'repaid', 'closed_no_repayment'];
const button = 'rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white';

function Pages({ value, label }: { value: Page<unknown>; label: string }) {
    return <nav aria-label={label} className="flex items-center justify-between gap-3 text-sm">{value.prev_page_url ? <Link href={value.prev_page_url} className={button}>Previous</Link> : <span />}<span className="text-slate-600">Page {value.current_page} of {value.last_page}</span>{value.next_page_url ? <Link href={value.next_page_url} className={button}>Next</Link> : <span />}</nav>;
}

export default function Liabilities({ cases, filters, reviewCandidates }: { cases: Page<Row>; filters: { search: string; status: string; view?: string; order_id?: number }; reviewCandidates?: Page<{ id: number; order_number: string }> | null }) {
    const view = filters.view ?? 'returns';
    const shipments = view === 'shipments';
    const form = useForm({ ...filters, view });
    const statuses = shipments ? Object.keys(shipmentStatuses) : returnStatuses;
    const submit = (event: FormEvent) => { event.preventDefault(); if (!form.processing) form.get('/return-liabilities', { preserveState: true, preserveScroll: true }); };
    return <ReturnLayout><Head title="Return liabilities" /><main className="mx-auto max-w-5xl space-y-6 py-4">
        <header><h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900"><Receipt className="h-6 w-6 text-[#E00D42]" />Return liabilities</h1><p className="mt-2 text-sm text-slate-600">Approved refunds, funding and repayment history.</p></header>
        <FinancialCaseNav view={view} orderId={filters.order_id} />
        {filters.order_id && <Link href={`/return-liabilities?view=${view}`} className={button}>Show all orders</Link>}
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-300 bg-white p-4"><label className="min-w-48 flex-1 text-sm font-semibold">Return or purchase reference<input maxLength={80} value={form.data.search} onChange={event => form.setData('search', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label><label className="text-sm font-semibold">Status<select value={form.data.status} onChange={event => form.setData('status', event.target.value)} className="mt-2 block w-full rounded-lg border border-slate-300 text-sm"><option value="">All statuses</option>{statuses.map(status => <option key={status} value={status}>{(shipments ? shipmentStatuses : returnLabels)[status] ?? status}</option>)}</select></label><button disabled={form.processing} className="inline-flex items-center gap-2 rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50"><Search className="h-4 w-4" />Search</button>{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="w-full text-sm text-rose-700">{String(message)}</p>)}</form>
        <div className="grid gap-4 md:grid-cols-2">{cases.data.map(item => <article key={item.id} className="space-y-4 rounded-xl border border-slate-300 bg-white p-5 shadow-sm"><div className="flex flex-wrap justify-between gap-3"><div><h2 className="break-all text-sm font-bold text-slate-900">{item.reference}</h2><p className="mt-1 break-all text-xs text-slate-500">Purchase {item.order_number}</p></div>{item.kind === 'shipment' ? <span className="self-start rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-[#C20836]">{shipmentStatuses[item.status] ?? item.status}</span> : <ReturnStatus status={item.status} />}</div><dl className="grid grid-cols-2 gap-4"><div><dt className="text-xs text-slate-600">Buyer refund · {item.mode === 'wallet' ? 'Demo Wallet' : 'COD'}</dt><dd className="mt-1 font-bold">{money(item.refund_cents)}</dd></div>{item.outstanding_cents !== null && <div><dt className="text-xs text-slate-600">{item.kind === 'shipment' ? 'Shipping outstanding' : 'Funding outstanding'}</dt><dd className="mt-1 font-bold">{money(item.outstanding_cents)}</dd></div>}</dl><Link href={item.href ?? `/return-liabilities/${item.id}`} className={`inline-flex text-sm ${button}`}>Review finances</Link></article>)}</div>
        {!cases.data.length && <p className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-600">No approved {shipments ? 'shipment' : 'return'} finances match these filters.</p>}
        <Pages value={cases} label="Return finance pages" />
        {shipments && reviewCandidates && <section className="space-y-4 rounded-xl border border-slate-300 bg-white p-5"><h2 className="text-base font-bold">Returned parcels awaiting financial review</h2>{reviewCandidates.data.length ? <ul className="divide-y divide-slate-200">{reviewCandidates.data.map(order => <li key={order.id} className="flex flex-wrap items-center justify-between gap-3 py-4"><p className="text-sm font-semibold">Purchase {order.order_number}</p><Link href={`/shipment-finances/orders/${order.id}/review`} className={`text-sm ${button}`}>Review actual refusal</Link></li>)}</ul> : <p className="text-sm text-slate-600">No unreviewed refusals match this search.</p>}<Pages value={reviewCandidates} label="Unreviewed refusal pages" /></section>}
    </main></ReturnLayout>;
}
