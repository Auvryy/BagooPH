import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashInput, cashMoney } from '@/Components/CodCashUI';
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
    return (
        <DashboardLayout title="Seller proceeds" subtitle="Follow verified completion, COD reconciliation and recorded seller payment.">
            <Head title="Seller proceeds" />
            <div className="space-y-5">
                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Product proceeds and payment evidence</h2>
                    <p className="mt-1.5 text-xs sm:text-sm leading-relaxed text-zinc-600">
                        The gross product split is 90% for the seller and 10% for Bagoo. Shop-funded offers reduce the seller’s net payout; Bagoo-funded offers are a separate platform cost. Earnings release on verified delivery; external cash payment still requires reconciled COD and a real receipt.
                    </p>
                    {can_release && <p className="mt-1.5 text-xs text-zinc-500">Recording payment confirms an existing transfer and its receipt. Bagoo does not transfer money from this page.</p>}
                </section>

                <form onSubmit={submit} className="grid items-end gap-3 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-4 sm:grid-cols-[1fr_240px_auto] shadow-xs">
                    <label className="text-xs font-semibold text-[#121316]">
                        Order number
                        <input className={cashInput} value={query} maxLength={100} onChange={event => setQuery(event.target.value)} />
                    </label>
                    <label className="text-xs font-semibold text-[#121316]">
                        Settlement state
                        <select className={cashInput} value={status} onChange={event => setStatus(event.target.value)}>
                            <option value="">All states</option>
                            {['pending', 'eligible', 'authorized', 'settled', 'closed'].map(value => (
                                <option key={value} value={value}>{value[0].toUpperCase() + value.slice(1)}</option>
                            ))}
                        </select>
                    </label>
                    <button className={cashButton}>Apply filters</button>
                </form>

                <div className="grid gap-4 lg:grid-cols-2">
                    {records.data.map(record => (
                        <article key={record.order_id} className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="text-sm font-bold text-[#121316]">{record.order_number}</h2>
                                <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold capitalize text-zinc-700">{record.status}</span>
                            </div>
                            <p className="mt-1 text-xs text-zinc-500">{record.shop_name ?? 'Original shop requires review'}</p>
                            <p className="mt-4 text-xs font-semibold text-zinc-500">Net seller payout</p>
                            <p className="mt-1 tabular-nums text-2xl font-bold tabular-nums text-[#121316]">
                                {record.net_seller_cents === null ? 'Funding review required' : cashMoney(record.status === 'settled' ? record.paid_seller_cents : record.payable_seller_cents)}
                            </p>
                            <dl className="mt-4 grid grid-cols-2 gap-3 rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 text-xs">
                                <div>
                                    <dt className="text-zinc-500">Order completion</dt>
                                    <dd className="mt-0.5 font-semibold text-[#121316]">{record.buyer_completed ? 'Recorded' : 'Awaiting completion'}</dd>
                                </div>
                                <div>
                                    <dt className="text-zinc-500">Platform COD</dt>
                                    <dd className="mt-0.5 font-semibold text-[#121316]">{record.cash_reconciled ? 'Reconciled' : 'Awaiting reconciliation'}</dd>
                                </div>
                            </dl>
                            <Link href={`/seller-settlements/${record.order_id}`} className={`${cashButton} mt-4`}>Review proceeds</Link>
                        </article>
                    ))}
                </div>

                {records.total === 0 && <p className="rounded-xl border border-dashed border-zinc-200 bg-white p-8 text-center text-xs text-zinc-500">No orders match these filters.</p>}

                <nav aria-label="Settlement pages" className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-xs text-zinc-500">Page {records.current_page} of {records.last_page} · {records.total} records</p>
                    <div className="flex gap-2">
                        {records.prev_page_url && <Link className={cashButton} href={records.prev_page_url}>Previous</Link>}
                        {records.next_page_url && <Link className={cashButton} href={records.next_page_url}>Next</Link>}
                    </div>
                </nav>
            </div>
        </DashboardLayout>
    );
}
