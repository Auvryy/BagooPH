import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashDate, cashInput, cashMoney, cashPrimary } from '@/Components/CodCashUI';
import type { SettlementRecord } from '@/types/finance';
import FinancialReviewLinks from '@/Components/Finance/FinancialReviewLinks';

export default function SettlementDetail({ record, can_release, requestToken }: {
    record: SettlementRecord; can_release: boolean; requestToken: string;
}) {
    const history = record.history ?? [];
    const lastPayment = [...history].reverse().find(event => ['payment_recorded', 'payment_reference_corrected'].includes(event.event_type));
    const action = record.status === 'eligible' || record.needs_reauthorization ? 'authorize' : record.status === 'authorized' ? (record.payable_seller_cents === 0 ? 'close-proceeds' : 'record-payment') : 'adjust-reference';
    const cashAction = ['record-payment', 'adjust-reference'].includes(action);
    const form = useForm({ reason: '', payment_reference: '', proof: null as File | null, payment_confirmed: false,
        request_token: requestToken, expected_version: record.version, source_event_id: lastPayment?.id ?? 0 });
    useEffect(() => {
        form.setData(data => ({ ...data, request_token: requestToken, expected_version: record.version,
            source_event_id: lastPayment?.id ?? 0, proof: null, payment_confirmed: false }));
    }, [record.version, requestToken, lastPayment?.id]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(data => ({ reason: data.reason, request_token: data.request_token, expected_version: data.expected_version,
            ...(cashAction ? { payment_reference: data.payment_reference, proof: data.proof, payment_confirmed: data.payment_confirmed } : {}),
            ...(action === 'adjust-reference' ? { source_event_id: data.source_event_id } : {}) }));
        form.post(`/seller-settlements/${record.order_id}/${action}`, { forceFormData: true, preserveScroll: true,
            onSuccess: () => form.reset('reason', 'payment_reference', 'proof', 'payment_confirmed') });
    };
    return (
        <DashboardLayout title={`Proceeds · ${record.order_number}`} subtitle="Original product amounts, release decisions and payment evidence stay together.">
            <Head title="Seller settlement evidence" />
            <div className="space-y-5">
                <Link className={cashButton} href="/seller-settlements">All seller proceeds</Link>
                <FinancialReviewLinks value={record.shipment_finances} />

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <div className="flex flex-wrap justify-between gap-3 border-b border-zinc-100 pb-4">
                        <div>
                            <h2 className="text-base font-bold text-[#121316]">{record.shop_name}</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Recipient: {record.seller_name}</p>
                        </div>
                        <span className="self-start rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold capitalize text-zinc-700">{record.status}</span>
                    </div>
                    <dl className="mt-5 grid gap-4 sm:grid-cols-3">
                        {[['Product subtotal', record.product_cents], ['Seller product share', record.seller_cents], ['Platform commission', record.commission_cents], ['Shipping charge', record.shipping_cents], ['Order discount', record.discount_cents]].map(([label, cents]) => (
                            <div key={label as string} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                <dt className="text-xs font-semibold text-zinc-500">{label}</dt>
                                <dd className="mt-1 tabular-nums text-xl font-bold tabular-nums text-[#121316]">{cashMoney(cents as number)}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-4 text-xs sm:text-sm leading-relaxed text-zinc-500">
                        Commission is rounded once to the nearest cent on original products. The gross 10%/90% split is retained; promotional costs are deducted separately from their payer. Shipping remains separate from product proceeds and does not establish rider or logistics earnings.
                    </p>
                    {record.promotion_accounting === 'legacy_recorded' ? (
                        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50/80 p-3 text-xs text-amber-900">
                            This earlier settlement retains its recorded payment. Its discount payer was not recorded; promotional history has not been reconstructed.
                        </p>
                    ) : (
                        <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                            {[['Seller promotional cost', record.seller_promotion_cents], ['Bagoo promotional cost', record.platform_promotion_cents], [record.return_seller_cents > 0 ? 'Original funded seller share' : 'Net seller payout', record.net_seller_cents], ['Net platform amount', record.net_commission_cents]].map(([label, cents]) => (
                                <div key={label as string} className="rounded-lg border border-zinc-100 bg-zinc-50/40 p-3">
                                    <dt className="text-xs font-semibold text-zinc-500">{label}</dt>
                                    <dd className="mt-1 tabular-nums text-xl font-bold tabular-nums text-[#121316]">{cents === null ? 'Funding review required' : cashMoney(cents as number)}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    {record.promotion_snapshot?.length > 0 && (
                        <ul className="mt-4 space-y-1.5 text-xs text-zinc-600">
                            {record.promotion_snapshot.map(offer => (
                                <li key={offer.allocation_id}>
                                    {offer.code}: {offer.funding_party === 'shop' ? 'shop-funded' : 'Bagoo-funded'} · <span className="tabular-nums font-semibold">{cashMoney(offer.seller_expense_cents + offer.platform_expense_cents)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Source references</h2>
                    <p className="break-all text-xs text-zinc-600">Order completion: <span className="tabular-nums text-[#121316]">{record.buyer_reference ?? 'Awaiting verified completion'}</span></p>
                    <p className="break-all text-xs text-zinc-600">Reconciliation: <span className="tabular-nums text-[#121316]">{record.reconciliation_reference ?? 'Awaiting platform reconciliation'}</span></p>
                    <p className="break-all text-xs text-zinc-600">Settlement: <span className="tabular-nums text-[#121316]">{record.reference ?? 'Release not yet authorized'}</span></p>
                    {can_release && record.cash_id && <Link className={cashButton} href={`/cash-handover/${record.cash_id}`}>Original COD evidence</Link>}
                    {record.legacy_ledger && (
                        <p className="rounded-lg border border-amber-200 bg-amber-50/80 p-3 text-xs text-amber-900">
                            Older ledger #{record.legacy_ledger.id}: {record.legacy_ledger.status}. The original entry is retained; its status alone is not payment evidence.
                        </p>
                    )}
                    {record.blockers.length > 0 && (
                        <ul className="list-disc space-y-1.5 pl-5 text-xs text-amber-900">
                            {record.blockers.map(blocker => <li key={blocker}>{blocker}</li>)}
                        </ul>
                    )}
                </section>

                {record.return_seller_cents > 0 && (
                    <section className="space-y-3 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">Separate return deductions</h2>
                        <p className="text-xs leading-relaxed text-zinc-600">
                            Original seller share stays retained. Approved return contributions: {cashMoney(record.return_seller_cents)}. Remaining unpaid proceeds: {cashMoney(['settled', 'closed'].includes(record.status) ? 0 : record.payable_seller_cents)}. Actual seller payment recorded: {cashMoney(record.paid_seller_cents)}.
                        </p>
                        {record.needs_reauthorization && <p className="text-xs text-amber-900">Return deductions changed after consent. Review and authorize the actual remaining amount again.</p>}
                        <Link className={cashButton} href="/buyer-returns">Review return and recovery records</Link>
                    </section>
                )}

                {can_release && !['pending', 'closed'].includes(record.status) && record.blockers.length === 0 && (
                    <form onSubmit={submit} className="space-y-4 overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                        <h2 className="text-sm font-semibold text-[#121316]">
                            {action === 'authorize' ? 'Authorize seller release' : action === 'record-payment' ? 'Record an existing seller payment' : action === 'close-proceeds' ? 'Close zero remaining proceeds' : 'Correct the payment reference'}
                        </h2>
                        <p className="text-xs sm:text-sm leading-relaxed text-zinc-600">
                            {action === 'authorize'
                                ? 'Both required sources are present. Authorization leaves payment awaiting a genuine receipt.'
                                : action === 'record-payment'
                                ? `Confirm the existing ${cashMoney(record.payable_seller_cents)} payment to the named seller using its reference and private receipt.`
                                : action === 'close-proceeds'
                                ? 'All original seller proceeds were deducted by retained return adjustments. Close the obligation without recording a cash transfer.'
                                : 'This adds linked evidence and keeps the original payment. It does not change paid amounts or initiate another transfer.'}
                        </p>
                        {cashAction && (
                            <>
                                <label className="block text-xs font-semibold text-[#121316]">
                                    Payment reference
                                    <input required maxLength={120} value={form.data.payment_reference} onChange={event => form.setData('payment_reference', event.target.value)} className={cashInput} />
                                </label>
                                <label className="block text-xs font-semibold text-[#121316]">
                                    Private receipt or payment evidence
                                    <input required type="file" accept="image/jpeg,image/png,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className={cashInput} />
                                </label>
                                <p className="text-xs text-zinc-500">JPG, PNG or PDF, up to 5 MB.</p>
                                <label className="flex items-start gap-3 text-xs text-zinc-700">
                                    <input required type="checkbox" checked={form.data.payment_confirmed} onChange={event => form.setData('payment_confirmed', event.target.checked)} className="mt-0.5 rounded border-zinc-300 text-[#E00D42]" />
                                    I reviewed the original transfer, its amount, recipient and evidence.
                                </label>
                            </>
                        )}
                        <label className="block text-xs font-semibold text-[#121316]">
                            Review reason
                            <textarea required minLength={5} maxLength={1000} rows={3} className={cashInput} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} />
                        </label>
                        {Object.entries(form.errors).map(([key, message]) => <p role="alert" key={key} className="text-xs text-rose-700">{message}</p>)}
                        <button disabled={form.processing} className={cashPrimary}>
                            {form.processing ? 'Saving…' : action === 'authorize' ? 'Authorize release' : action === 'record-payment' ? 'Save payment record' : action === 'close-proceeds' ? 'Close zero proceeds' : 'Save linked correction'}
                        </button>
                    </form>
                )}

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs">
                    <h2 className="text-sm font-semibold text-[#121316]">Retained decisions and receipts</h2>
                    {history.length === 0 && <p className="mt-3 text-xs text-zinc-500">No release or payment decision has been recorded.</p>}
                    <ol className="mt-4 space-y-3">
                        {history.map(event => (
                            <li key={event.id} className="rounded-lg border border-zinc-200/90 bg-zinc-50/40 p-4">
                                <div className="flex flex-wrap justify-between gap-2">
                                    <p className="text-sm font-semibold text-[#121316]">
                                        {event.event_type === 'release_authorized'
                                            ? 'Release authorized'
                                            : event.event_type === 'release_reauthorized'
                                            ? 'Remaining release authorized'
                                            : event.event_type === 'proceeds_closed'
                                            ? 'Zero proceeds closed'
                                            : event.event_type === 'payment_recorded'
                                            ? 'Payment recorded'
                                            : 'Payment reference corrected'}
                                    </p>
                                    <p className="text-xs text-zinc-500">{cashDate(event.created_at)}</p>
                                </div>
                                <p className="mt-1.5 break-all tabular-nums text-xs text-zinc-500">{event.reference}</p>
                                <p className="mt-1.5 text-xs font-medium text-zinc-700">{event.actor_name} · {event.actor_role}</p>
                                <p className="mt-1.5 text-xs text-zinc-600">{event.reason}</p>
                                {event.payment_reference && <p className="mt-1.5 break-all text-xs text-zinc-600">Payment reference: <span className="tabular-nums">{event.payment_reference}</span></p>}
                                {event.source_reference && <p className="mt-1.5 break-all text-xs text-zinc-600">Original evidence: {event.source_reference} · Previous reference: {event.context.previous_reference}</p>}
                                {event.proof_url && <a className={`${cashButton} mt-3`} href={event.proof_url}>Open private payment evidence</a>}
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </DashboardLayout>
    );
}
