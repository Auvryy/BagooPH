import { Link, router } from '@inertiajs/react';
import { useEffect, useState, type FormEvent } from 'react';
import { ChevronDown, ChevronRight, X } from 'lucide-react';
import { Badge, Notice, buttonClasses, inputClasses, labelClasses, type BadgeTone } from '@/Components/ui';
import type { FinancialTotal, OversightFilters, OversightRecord, OversightProps } from '@/types/finance';
import { financeMoney } from '@/utils/finance';

/** Seller payout stages, in the order money moves. Keys match the server's proceeds states and metrics. */
const STATUS: Record<string, { label: string; tone: BadgeTone }> = {
    pending: { label: 'Waiting for buyer', tone: 'info' },
    eligible: { label: 'Ready for release', tone: 'info' },
    authorized: { label: 'Payment approved', tone: 'info' },
    settled: { label: 'Paid', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
    void: { label: 'Cancelled', tone: 'neutral' },
    unverified: { label: 'Under review', tone: 'warning' },
};
const SELLER_STATES = ['pending', 'eligible', 'authorized', 'settled', 'closed'];
const METRIC_LABEL: Record<string, string> = {
    pending: 'To be paid', eligible: 'Ready for release', authorized: 'Payment approved', settled: 'Paid to you',
    commission: 'Bagoo commission', discounts: 'Shop discounts', return_deductions: 'Return deductions', recovery_due: 'You owe Bagoo',
};

const cents = (value: string | null | undefined): bigint | null => (value != null && /^\d+$/.test(value) ? BigInt(value) : null);
const shortDate = (value: string) => {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Asia/Manila' });
};

function shareOf(record: OversightRecord): string | null {
    const settled = record.metrics.settled;
    return settled && settled !== '0' ? settled : (record.metrics.pending ?? null);
}

function Amount({ total, label, size = 'lg' }: { total: FinancialTotal | undefined; label: string; size?: 'lg' | 'sm' }) {
    const value = <span className={`tabular-nums ${size === 'lg' ? 'text-[28px] font-bold leading-9' : 'text-sm font-semibold'} text-ink-900`}>{financeMoney(total?.amount_cents)}</span>;
    const body = size === 'lg'
        ? <><span className="block text-[13px] font-medium text-ink-600">{label}</span><span className="mt-1 flex items-center gap-1">{value}{total?.url && <ChevronRight aria-hidden="true" className="h-5 w-5 text-ink-400" />}</span></>
        : <><span className="text-sm text-ink-700">{label}</span><span className="flex items-center gap-1">{value}{total?.url && <ChevronRight aria-hidden="true" className="h-4 w-4 text-ink-400" />}</span></>;
    const layout = size === 'lg' ? 'block rounded-control p-2 -m-2' : 'flex min-h-11 items-center justify-between gap-4 rounded-control px-2 -mx-2';
    return total?.url
        ? <Link href={total.url} className={`${layout} hover:bg-surface-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500`}>{body}</Link>
        : <div className={layout}>{body}</div>;
}

export default function SellerFinanceOverview({ records, totals, filters, error, stateOptions }: OversightProps) {
    const [form, setForm] = useState<OversightFilters>(filters);
    useEffect(() => setForm(filters), [filters]);
    const total = (key: string) => totals.find(item => item.key === key);
    const update = (key: keyof OversightFilters, value: string) => setForm(current => ({ ...current, [key]: value }));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get('/financial-oversight', { from: form.from || undefined, to: form.to || undefined, state: form.state || undefined }, { preserveState: true });
    };

    const pending = cents(total('pending')?.amount_cents);
    const eligible = cents(total('eligible')?.amount_cents);
    const authorized = cents(total('authorized')?.amount_cents);
    const waiting: FinancialTotal | undefined = pending !== null && eligible !== null && authorized !== null
        ? { ...total('pending')!, amount_cents: String(pending - eligible - authorized), url: null } : undefined;
    const owed = cents(total('recovery_due')?.amount_cents);
    const deductions = ['commission', 'discounts', 'return_deductions'].filter(key => total(key));
    const unavailable = totals.some(item => item.amount_cents === null && METRIC_LABEL[item.key]);
    const filtered = Boolean(filters.from || filters.to || filters.state || filters.metric);
    const states = SELLER_STATES.filter(state => stateOptions.length === 0 || stateOptions.includes(state));

    return (
        <div className="space-y-6">
            {error && <Notice tone="warning" role="alert" title="Some payout records could not be checked">{error}</Notice>}
            {!error && unavailable && <Notice tone="warning" role="status">Some amounts are under review and show as Unavailable.</Notice>}

            <section aria-label="Your payouts" className="grid overflow-hidden rounded-card border border-line-200 bg-white md:grid-cols-[1.4fr_1fr]">
                <div className="p-5 sm:p-6">
                    <Amount total={total('pending')} label="To be paid" />
                    <dl className="mt-4 space-y-0 border-t border-line-200 pt-2">
                        {waiting && <div><dt className="sr-only">Waiting for buyer</dt><dd><Amount size="sm" total={waiting} label="Waiting for the buyer to confirm" /></dd></div>}
                        <div><dt className="sr-only">Ready for release</dt><dd><Amount size="sm" total={total('eligible')} label="Ready for release" /></dd></div>
                        <div><dt className="sr-only">Payment approved</dt><dd><Amount size="sm" total={total('authorized')} label="Payment approved, sending soon" /></dd></div>
                    </dl>
                </div>
                <div className="border-t border-line-200 p-5 sm:p-6 md:border-l md:border-t-0">
                    <Amount total={total('settled')} label="Paid to you" />
                    {deductions.length > 0 && (
                        <details className="group mt-4 border-t border-line-200 pt-2">
                            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 rounded-control text-sm font-medium text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                                Fees and deductions
                                <ChevronDown aria-hidden="true" className="h-4 w-4 text-ink-500 transition-transform duration-150 group-open:rotate-180" />
                            </summary>
                            <div className="pb-1">
                                {deductions.map(key => <Amount key={key} size="sm" total={total(key)} label={METRIC_LABEL[key]} />)}
                                <p className="pt-2 text-xs text-ink-600">Bagoo keeps 10% of product sales. Shipping is never part of your payout.</p>
                            </div>
                        </details>
                    )}
                </div>
            </section>

            {owed !== null && owed > 0n && (
                <Notice tone="warning" title={`You owe Bagoo ${financeMoney(total('recovery_due')?.amount_cents)}`}
                    action={total('recovery_due')?.url ? <Link href={total('recovery_due')!.url!} className="text-sm font-semibold text-brand-700 underline underline-offset-4">See the returns</Link> : undefined}>
                    A buyer return was refunded after you were paid.
                </Notice>
            )}

            <section aria-label="Orders" className="rounded-card border border-line-200 bg-white">
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3 border-b border-line-200 p-4 sm:px-6">
                    <h2 className="mr-auto self-center text-lg font-semibold text-ink-900">Orders</h2>
                    <div className="grid w-full grid-cols-2 gap-3 sm:flex sm:w-auto">
                        <label className="sm:w-40">
                            <span className={labelClasses}>From</span>
                            <input type="date" className={inputClasses} value={form.from ?? ''} onChange={event => update('from', event.target.value)} />
                        </label>
                        <label className="sm:w-40">
                            <span className={labelClasses}>To</span>
                            <input type="date" className={inputClasses} value={form.to ?? ''} onChange={event => update('to', event.target.value)} />
                        </label>
                    </div>
                    <div className="flex w-full items-end gap-2 sm:w-auto">
                        <label className="min-w-0 flex-1 sm:w-48 sm:flex-none">
                            <span className={labelClasses}>Status</span>
                            <select className={inputClasses} value={form.state ?? ''} onChange={event => update('state', event.target.value)}>
                                <option value="">All</option>
                                {states.map(state => <option key={state} value={state}>{STATUS[state].label}</option>)}
                            </select>
                        </label>
                        <button type="submit" className={buttonClasses('secondary')}>Apply</button>
                        {filtered && <Link href="/financial-oversight" className={buttonClasses('ghost')}>Clear</Link>}
                    </div>
                </form>

                {filters.metric && (
                    <div className="flex items-center gap-2 border-b border-line-200 px-4 py-2 text-sm text-ink-700 sm:px-6">
                        Showing orders in <span className="font-semibold text-ink-900">{METRIC_LABEL[filters.metric] ?? filters.metric}</span>
                        <Link href="/financial-oversight" aria-label="Show all orders" className="inline-flex h-8 w-8 items-center justify-center rounded-control text-ink-600 hover:bg-surface-100"><X className="h-4 w-4" /></Link>
                    </div>
                )}

                {!error && records.data.length > 0 && (
                    <>
                        <div className="hidden grid-cols-[1.4fr_1fr_1fr_1fr_24px] gap-4 border-b border-line-200 px-6 py-2 text-xs font-medium uppercase tracking-wide text-ink-600 md:grid" aria-hidden="true">
                            <span>Order</span><span>Date</span><span>Status</span><span className="text-right">Your share</span><span />
                        </div>
                        <ul className="divide-y divide-line-200">
                            {records.data.map(record => {
                                const status = STATUS[record.proceeds?.status ?? ''] ?? { label: 'Under review', tone: 'warning' as const };
                                return (
                                    <li key={record.order_id}>
                                        <Link href={record.url} className="grid grid-cols-[1fr_auto_24px] items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface-100 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:px-6 md:grid-cols-[1.4fr_1fr_1fr_1fr_24px]">
                                            <span className="min-w-0 truncate font-mono text-sm font-medium text-ink-900">{record.order_number}</span>
                                            <span className="col-start-1 row-start-2 text-xs text-ink-600 md:col-start-auto md:row-start-auto md:text-sm">{shortDate(record.recorded_at)}</span>
                                            <span className="col-start-2 row-start-2 justify-self-end md:col-start-auto md:row-start-auto md:justify-self-start"><Badge tone={status.tone}>{status.label}</Badge></span>
                                            <span className="col-start-2 row-start-1 text-right text-sm font-semibold tabular-nums text-ink-900 md:col-start-auto md:row-start-auto">{financeMoney(shareOf(record))}</span>
                                            <ChevronRight aria-hidden="true" className="col-start-3 row-span-2 row-start-1 h-4 w-4 text-ink-400 md:col-start-auto md:row-span-1 md:row-start-auto" />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </>
                )}

                {!error && records.total === 0 && (
                    <div className="px-6 py-12 text-sm">
                        <p className="font-medium text-ink-900">{filtered ? 'No records match these filters.' : 'No payouts yet.'}</p>
                        <p className="mt-1 text-ink-600">{filtered ? 'Try another date or status.' : 'Orders appear here once a buyer pays for your products.'}</p>
                    </div>
                )}

                {!error && records.total > 0 && (
                    <nav aria-label="Financial record pages" className="flex flex-wrap items-center justify-between gap-3 border-t border-line-200 px-4 py-3 sm:px-6">
                        <p className="text-xs text-ink-600">{records.total} {records.total === 1 ? 'order' : 'orders'} · page {records.current_page} of {records.last_page}</p>
                        <div className="flex gap-2">
                            {records.prev_page_url && <Link className={buttonClasses('secondary', 'sm')} href={records.prev_page_url}>Previous</Link>}
                            {records.next_page_url && <Link className={buttonClasses('secondary', 'sm')} href={records.next_page_url}>Next</Link>}
                        </div>
                    </nav>
                )}
            </section>
        </div>
    );
}
