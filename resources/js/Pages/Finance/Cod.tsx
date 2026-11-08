import { useRef, useState, type FormEvent } from 'react';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Building2, CheckCircle2, Landmark, Receipt, Search, Wallet } from 'lucide-react';
import { CourierDialog, CourierFieldError } from '@/Components/CourierUI';
import { CashBadge, CashFrame, cashButton, cashDate, cashInput, CashLink, cashMoney, cashPrimary, type CashAccount, type CashMode } from '@/Components/CodCashUI';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { type CourierScope } from '@/utils/courier';

interface LegacyRecord { delivery_id: number; order_number: string; tracking_number: string; status: string; due_cents: number; has_counter_source: boolean }
interface Props {
    accounts: { data: CashAccount[]; current_page: number; last_page: number; total: number; prev_page_url: string | null; next_page_url: string | null };
    totals: { expected: number; collected: number; rider: number; hub: number; platform: number; reconciled: number; excess: number; differences: number; unverified: number };
    legacy: LegacyRecord[]; filters: { q?: string }; base: string; mode: CashMode; isOnline: boolean; can_reconcile: boolean; requestToken: string; scope?: CourierScope;
}

export default function CodRecords({ accounts, totals, legacy, filters, base, mode, isOnline, requestToken, scope }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [review, setReview] = useState<LegacyRecord | null>(null);
    const [reason, setReason] = useState('');
    const [reference, setReference] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    useCourierRequestError(pending, message => setErrors({ cash: message }));
    const submitReview = (event: FormEvent) => {
        event.preventDefault();
        if (!review || pending.current) return;
        pending.current = true; setBusy(true); setErrors({});
        router.post(`${base}/legacy/${review.delivery_id}`, { expected_status: review.status, request_token: requestToken, evidence_reference: reference, reason }, {
            preserveScroll: true, onSuccess: () => setReview(null), onError: setErrors,
            onFinish: () => { pending.current = false; setBusy(false); },
        });
    };
    const metrics = [
        { label: 'Collected', amount: totals.collected, Icon: Receipt, note: 'Recorded buyer payments' },
        { label: 'With riders', amount: totals.rider, Icon: Wallet, note: 'Cash still accountable' },
        { label: 'With hubs', amount: totals.hub, Icon: Building2, note: 'Awaiting platform handover' },
        { label: 'At platform', amount: totals.platform, Icon: Landmark, note: `${cashMoney(totals.reconciled)} reconciled` },
    ];
    return (
        <CashFrame mode={mode} isOnline={isOnline} scope={scope} title="COD cash records" subtitle="Follow each collection, confirm cash handovers, and review what reached the platform.">
            <div className="space-y-6 pb-4">
                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                        <div>
                            <h2 className="text-sm font-semibold tracking-tight text-[#121316]">Every handover has a receipt</h2>
                            <p className="mt-0.5 text-xs leading-5 text-zinc-500">Cash changes hands only after the named recipient confirms their count.</p>
                        </div>
                        <span className="inline-flex items-center gap-1.5 rounded-md border border-zinc-200/90 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-700 shadow-2xs">
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-600" aria-hidden="true" />
                            {accounts.total} cash record{accounts.total === 1 ? '' : 's'}
                        </span>
                    </div>
                    <div className="grid grid-cols-1 divide-y divide-zinc-200/80 sm:grid-cols-2 sm:divide-x sm:divide-y-0 lg:grid-cols-4">
                        {metrics.map(({ label, amount, Icon, note }) => (
                            <div key={label} className="p-4 sm:p-5">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-xs font-semibold text-zinc-500">{label}</p>
                                    <span className="flex h-7 w-7 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                        <Icon className="h-3.5 w-3.5" aria-hidden="true" />
                                    </span>
                                </div>
                                <p className="mt-2.5 break-words font-mono text-2xl font-bold tabular-nums text-[#121316]">{cashMoney(amount)}</p>
                                <p className="mt-1.5 text-xs leading-relaxed text-zinc-500">{note}</p>
                            </div>
                        ))}
                    </div>
                </section>

                {(totals.differences > 0 || totals.excess > 0) && (
                    <div className="flex items-start gap-3 rounded-xl border border-amber-200/90 bg-amber-50/80 p-4 text-amber-900 shadow-2xs">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                        <p className="text-xs sm:text-sm leading-relaxed">
                            <strong>{totals.differences} unresolved cash difference{totals.differences === 1 ? '' : 's'}.</strong>
                            {totals.excess > 0 && ` ${cashMoney(totals.excess)} in extra cash stays separately recorded with its holder.`} Open the original receipt to review its evidence.
                        </p>
                    </div>
                )}

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                    <div className="flex flex-col justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5 sm:flex-row sm:items-center">
                        <div>
                            <h2 className="text-sm font-semibold tracking-tight text-[#121316]">Collection and handover queue</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Order completion and seller payouts are separate from cash reconciliation.</p>
                        </div>
                        <form
                            onSubmit={event => {
                                event.preventDefault();
                                router.get(base, search.trim() ? { q: search.trim() } : {}, { preserveState: true, preserveScroll: true });
                            }}
                            className="flex w-full items-center gap-2 sm:max-w-sm"
                        >
                            <label className="sr-only" htmlFor="cash-search">Search order or waybill</label>
                            <input
                                id="cash-search"
                                value={search}
                                onChange={event => setSearch(event.target.value)}
                                maxLength={100}
                                placeholder="Search order or waybill"
                                className="min-h-9 w-full rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs text-[#121316] shadow-2xs placeholder:text-zinc-400 focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15"
                            />
                            <button
                                type="submit"
                                className="inline-flex min-h-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 shadow-2xs transition-colors hover:bg-zinc-50 hover:text-[#121316]"
                                aria-label="Search cash records"
                            >
                                <Search className="h-4 w-4" aria-hidden="true" />
                            </button>
                        </form>
                    </div>

                    <div className="p-5">
                        {accounts.data.length === 0 ? (
                            <div className="flex min-h-48 flex-col items-center justify-center rounded-xl border border-dashed border-zinc-200 bg-zinc-50/40 px-5 py-10 text-center">
                                <span className="flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-200/90 bg-white text-zinc-500 shadow-2xs">
                                    <Receipt className="h-4 w-4" aria-hidden="true" />
                                </span>
                                <h3 className="mt-3 text-sm font-semibold text-[#121316]">{search ? 'No matching cash records' : 'No collections recorded yet'}</h3>
                                <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-zinc-500">
                                    {search ? 'Try another order number or waybill.' : 'Rider and counter collections will appear here after cash is received.'}
                                </p>
                            </div>
                        ) : (
                            <div className="grid gap-4 xl:grid-cols-2">
                                {accounts.data.map(account => (
                                    <article key={account.id} className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-2xs transition-colors hover:border-zinc-300">
                                        <div className="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-4 py-3.5">
                                            <div>
                                                <h3 className="text-sm font-bold text-[#121316]">{account.order_number}</h3>
                                                <p className="mt-0.5 break-all font-mono text-xs text-zinc-500">{account.tracking_number}</p>
                                            </div>
                                            <CashBadge status={account.status} />
                                        </div>
                                        <div className="space-y-4 p-4">
                                            <div className="flex items-start justify-between gap-4">
                                                <div>
                                                    <p className="text-xs font-semibold text-zinc-500">Original COD due</p>
                                                    <p className="mt-1 font-mono text-2xl font-bold tabular-nums text-[#121316]">{cashMoney(account.expected_cents)}</p>
                                                </div>
                                                <span className="rounded-md border border-zinc-200/80 bg-zinc-50 px-2.5 py-1 text-xs font-semibold text-zinc-600">
                                                    {account.source_kind === 'rider_collection' ? 'Doorstep' : account.source_kind === 'counter_collection' ? 'Counter' : 'Older record'}
                                                </span>
                                            </div>
                                            <div className="rounded-lg border border-zinc-200/70 bg-zinc-50/70 p-3 text-sm">
                                                <p className="flex items-center gap-2 text-xs font-semibold text-[#121316]">
                                                    <Building2 className="h-3.5 w-3.5 shrink-0 text-zinc-500" aria-hidden="true" />
                                                    {account.hub_name}
                                                </p>
                                                <p className="mt-0.5 text-xs text-zinc-500">{account.company_name}</p>
                                            </div>
                                            <div className="space-y-2 text-sm">
                                                <p className="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Recorded cash responsibility</p>
                                                {account.holders.length ? (
                                                    account.holders.map(holder => (
                                                        <div key={`${holder.stage}-${holder.user_id}`} className="flex items-start justify-between gap-3 text-xs">
                                                            <span className="text-zinc-700">
                                                                {holder.name}
                                                                <span className="ml-1 text-zinc-500">· {holder.stage === 'rider' ? 'Rider' : holder.stage === 'hub' ? 'Hub' : 'Platform'}</span>
                                                            </span>
                                                            <strong className="shrink-0 font-mono font-bold tabular-nums text-[#121316]">{cashMoney(holder.amount_cents)}</strong>
                                                        </div>
                                                    ))
                                                ) : (
                                                    <p className="text-xs text-zinc-500">Collection and cash holder still need evidence.</p>
                                                )}
                                            </div>
                                            {account.pending && (
                                                <p className="rounded-lg border border-rose-200/90 bg-rose-50/80 p-3 text-xs font-medium text-[#C20836]">
                                                    Awaiting {account.pending.recipient_name}’s receipt of {cashMoney(account.pending.amount_cents)}.
                                                </p>
                                            )}
                                            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3">
                                                <p className="text-xs text-zinc-500">{cashDate(account.updated_at)} · Manila time</p>
                                                <CashLink href={`${base}/${account.id}`}>Review cash</CashLink>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </div>

                    {accounts.last_page > 1 && (
                        <nav aria-label="Cash record pages" className="flex items-center justify-between gap-3 border-t border-zinc-100 bg-zinc-50/40 px-5 py-3">
                            <p className="text-xs text-zinc-500">Page {accounts.current_page} of {accounts.last_page}</p>
                            <div className="flex gap-2">
                                {accounts.prev_page_url && <Link href={accounts.prev_page_url} className={cashButton} preserveScroll>Previous</Link>}
                                {accounts.next_page_url && (
                                    <Link href={accounts.next_page_url} className={cashButton} preserveScroll>
                                        Next<ArrowRight className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                )}
                            </div>
                        </nav>
                    )}
                </section>

                {legacy.length > 0 && (
                    <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <div className="flex items-start gap-3">
                            <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-amber-200 bg-amber-50 text-amber-700">
                                <AlertTriangle className="h-4 w-4" aria-hidden="true" />
                            </span>
                            <div>
                                <h2 className="text-sm font-semibold text-[#121316]">Older records need cash evidence</h2>
                                <p className="mt-1 max-w-3xl text-xs leading-relaxed text-zinc-500">
                                    A paid or delivered label cannot prove who collected or received cash. Review an original counter receipt where available, or record an unresolved evidence review.
                                </p>
                            </div>
                        </div>
                        <ul className="mt-4 divide-y divide-zinc-100">
                            {legacy.map(record => (
                                <li key={record.delivery_id} className="flex flex-col justify-between gap-3 py-3.5 sm:flex-row sm:items-center">
                                    <div>
                                        <p className="text-sm font-semibold text-[#121316]">{record.order_number} · <span className="font-mono">{cashMoney(record.due_cents)}</span></p>
                                        <p className="mt-0.5 text-xs text-zinc-500">{record.has_counter_source ? 'Original counter source available for verification' : 'Collection has no verified source'}</p>
                                    </div>
                                    <button
                                        className={cashButton}
                                        onClick={() => { setReview(record); setReason(''); setReference(''); setErrors({}); }}
                                    >
                                        Review evidence<ArrowRight className="h-4 w-4" aria-hidden="true" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <CourierDialog open={review !== null} title="Review older cash evidence" onClose={() => { if (!busy) setReview(null); }} busy={busy}>
                <form onSubmit={submitReview} className="space-y-4">
                    <p className="text-sm leading-relaxed text-zinc-600">
                        {review?.order_number}. Verified original counter cash keeps its recorded collector and time. Missing collection evidence leaves the cash unverified.
                    </p>
                    <label className="block text-sm font-semibold text-[#121316]" htmlFor="legacy-reference">
                        Evidence reference
                        <input id="legacy-reference" required maxLength={120} value={reference} onChange={event => setReference(event.target.value)} className={cashInput} />
                        <CourierFieldError message={errors.evidence_reference} />
                    </label>
                    <label className="block text-sm font-semibold text-[#121316]" htmlFor="legacy-reason">
                        What did you verify?
                        <textarea id="legacy-reason" required minLength={10} maxLength={1000} rows={3} value={reason} onChange={event => setReason(event.target.value)} className={cashInput} />
                        <CourierFieldError message={errors.reason} />
                    </label>
                    <CourierFieldError message={errors.cash} />
                    <div className="flex justify-end gap-2">
                        <button type="button" disabled={busy} className={cashButton} onClick={() => setReview(null)}>Cancel</button>
                        <button type="submit" disabled={busy} className={cashPrimary}>{busy ? 'Saving…' : 'Save evidence review'}</button>
                    </div>
                </form>
            </CourierDialog>
        </CashFrame>
    );
}
