import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashInput, cashMoney, cashSurface } from '@/Components/CodCashUI';
import type { FinancialPage, SettlementRecord } from '@/types/finance';

export default function Settlements({ records, filters, can_release }: {
    records: FinancialPage<SettlementRecord>; filters: { q?: string; status?: string }; can_release: boolean;
}) {
    const [query, setQuery] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/seller-settlements', { q: query, status }, { preserveState: true });
    };
    return <DashboardLayout title="Seller proceeds" subtitle="Follow buyer confirmation, COD reconciliation, release approval and recorded seller payment.">
        <Head title="Seller proceeds" />
        <div className="space-y-5">
            <section className={`${cashSurface} p-5`}>
                <h2 className="font-semibold">Product proceeds and payment evidence</h2>
                <p className="mt-2 text-sm leading-6 text-slate-600">The seller receives 90% of product sales and the platform keeps 10%. Shipping and discounts appear separately. Release approval awaits payment evidence before an amount is shown as settled.</p>
                {can_release && <p className="mt-2 text-sm text-slate-600">Recording payment confirms an existing transfer and its receipt. Bagoo does not transfer money from this page.</p>}
            </section>
            <form onSubmit={submit} className="grid items-end gap-3 sm:grid-cols-[1fr_240px_auto]">
                <label className="text-sm font-semibold">Order number<input className={cashInput} value={query} maxLength={100} onChange={event => setQuery(event.target.value)} /></label>
                <label className="text-sm font-semibold">Settlement state<select className={cashInput} value={status} onChange={event => setStatus(event.target.value)}><option value="">All states</option>{['pending', 'eligible', 'authorized', 'settled'].map(value => <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>)}</select></label>
                <button className={cashButton}>Apply filters</button>
            </form>
            <div className="grid gap-4 lg:grid-cols-2">{records.data.map(record => <article key={record.order_id} className={`${cashSurface} p-5`}>
                <div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-semibold">{record.order_number}</h2><span className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-1 text-sm capitalize">{record.status}</span></div>
                <p className="mt-2 text-sm text-slate-600">{record.shop_name ?? 'Original shop requires review'}</p>
                <p className="mt-4 text-xs text-slate-600">Seller product share</p><p className="mt-1 text-2xl font-bold">{cashMoney(record.seller_cents)}</p>
                <dl className="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt className="text-slate-600">Buyer confirmation</dt><dd className="mt-1 font-semibold">{record.buyer_completed ? 'Recorded' : 'Awaiting buyer'}</dd></div><div><dt className="text-slate-600">Platform COD</dt><dd className="mt-1 font-semibold">{record.cash_reconciled ? 'Reconciled' : 'Awaiting reconciliation'}</dd></div></dl>
                <Link href={`/seller-settlements/${record.order_id}`} className={`${cashButton} mt-5`}>Review proceeds</Link>
            </article>)}</div>
            {records.total === 0 && <p className={`${cashSurface} p-8 text-center text-slate-600`}>No orders match these filters.</p>}
            <nav aria-label="Settlement pages" className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate-600">Page {records.current_page} of {records.last_page} · {records.total} records</p><div className="flex gap-2">{records.prev_page_url && <Link className={cashButton} href={records.prev_page_url}>Previous</Link>}{records.next_page_url && <Link className={cashButton} href={records.next_page_url}>Next</Link>}</div></nav>
        </div>
    </DashboardLayout>;
}
