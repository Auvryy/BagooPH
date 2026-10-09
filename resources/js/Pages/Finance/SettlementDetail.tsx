import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, type FormEvent } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { cashButton, cashDate, cashInput, cashMoney, cashPrimary, cashSurface } from '@/Components/CodCashUI';
import type { SettlementRecord } from '@/types/finance';

export default function SettlementDetail({ record, can_release, requestToken }: {
    record: SettlementRecord; can_release: boolean; requestToken: string;
}) {
    const history = record.history ?? [];
    const lastPayment = [...history].reverse().find(event => ['payment_recorded', 'payment_reference_corrected'].includes(event.event_type));
    const action = record.status === 'eligible' ? 'authorize' : record.status === 'authorized' ? 'record-payment' : 'adjust-reference';
    const form = useForm({ reason: '', payment_reference: '', proof: null as File | null, payment_confirmed: false,
        request_token: requestToken, expected_version: record.version, source_event_id: lastPayment?.id ?? 0 });
    useEffect(() => {
        form.setData(data => ({ ...data, request_token: requestToken, expected_version: record.version,
            source_event_id: lastPayment?.id ?? 0, proof: null, payment_confirmed: false }));
    }, [record.version, requestToken, lastPayment?.id]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(data => ({ reason: data.reason, request_token: data.request_token, expected_version: data.expected_version,
            ...(action !== 'authorize' ? { payment_reference: data.payment_reference, proof: data.proof, payment_confirmed: data.payment_confirmed } : {}),
            ...(action === 'adjust-reference' ? { source_event_id: data.source_event_id } : {}) }));
        form.post(`/seller-settlements/${record.order_id}/${action}`, { forceFormData: true, preserveScroll: true,
            onSuccess: () => form.reset('reason', 'payment_reference', 'proof', 'payment_confirmed') });
    };
    return <DashboardLayout title={`Proceeds · ${record.order_number}`} subtitle="Original product amounts, release decisions and payment evidence stay together.">
        <Head title="Seller settlement evidence" />
        <div className="space-y-5">
            <Link className={cashButton} href="/seller-settlements">All seller proceeds</Link>
            <section className={`${cashSurface} p-5`}><div className="flex flex-wrap justify-between gap-3"><div><h2 className="text-lg font-bold">{record.shop_name}</h2><p className="mt-1 text-sm text-slate-600">Recipient: {record.seller_name}</p></div><span className="self-start rounded-lg border border-slate-300 bg-slate-50 px-3 py-1 text-sm capitalize">{record.status}</span></div>
                <dl className="mt-5 grid gap-4 sm:grid-cols-3">{[['Product subtotal', record.product_cents], ['Seller product share', record.seller_cents], ['Platform commission', record.commission_cents], ['Shipping charge', record.shipping_cents], ['Order discount', record.discount_cents]].map(([label, cents]) => <div key={label as string}><dt className="text-sm text-slate-600">{label}</dt><dd className="mt-1 text-xl font-bold">{cashMoney(cents as number)}</dd></div>)}</dl>
                <p className="mt-4 text-sm leading-6 text-slate-600">Commission is rounded once to the nearest cent; the seller receives the remaining product amount. Shipping and discounts do not change this product basis. A shipping charge does not establish rider or logistics earnings.</p>
            </section>
            <section className={`${cashSurface} space-y-3 p-5`}><h2 className="font-semibold">Source references</h2><p className="break-all text-sm">Buyer confirmation: {record.buyer_reference ?? 'Awaiting buyer confirmation'}</p><p className="break-all text-sm">Reconciliation: {record.reconciliation_reference ?? 'Awaiting platform reconciliation'}</p><p className="break-all text-sm">Settlement: {record.reference ?? 'Release not yet authorized'}</p>{can_release && record.cash_id && <Link className={cashButton} href={`/cash-handover/${record.cash_id}`}>Original COD evidence</Link>}
                {record.legacy_ledger && <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Older ledger #{record.legacy_ledger.id}: {record.legacy_ledger.status}. The original entry is retained; its status alone is not payment evidence.</p>}
                {record.blockers.length > 0 && <ul className="list-disc space-y-2 pl-5 text-sm text-amber-900">{record.blockers.map(blocker => <li key={blocker}>{blocker}</li>)}</ul>}
            </section>
            {can_release && record.status !== 'pending' && record.blockers.length === 0 && <form onSubmit={submit} className={`${cashSurface} space-y-4 p-5`}>
                <h2 className="font-semibold">{action === 'authorize' ? 'Authorize seller release' : action === 'record-payment' ? 'Record an existing seller payment' : 'Correct the payment reference'}</h2>
                <p className="text-sm leading-6 text-slate-600">{action === 'authorize' ? 'Both required sources are present. Authorization leaves payment awaiting a genuine receipt.' : action === 'record-payment' ? `Confirm the existing ${cashMoney(record.seller_cents)} payment to the named seller using its reference and private receipt.` : 'This adds linked evidence and keeps the original payment. It does not change paid amounts or initiate another transfer.'}</p>
                {action !== 'authorize' && <><label className="block text-sm font-semibold">Payment reference<input required maxLength={120} value={form.data.payment_reference} onChange={event => form.setData('payment_reference', event.target.value)} className={cashInput} /></label><label className="block text-sm font-semibold">Private receipt or payment evidence<input required type="file" accept="image/jpeg,image/png,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className={cashInput} /></label><p className="text-xs text-slate-600">JPG, PNG or PDF, up to 5 MB.</p><label className="flex items-start gap-3 text-sm"><input required type="checkbox" checked={form.data.payment_confirmed} onChange={event => form.setData('payment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I reviewed the original transfer, its amount, recipient and evidence.</label></>}
                <label className="block text-sm font-semibold">Review reason<textarea required minLength={5} maxLength={1000} rows={3} className={cashInput} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
                {Object.entries(form.errors).map(([key, message]) => <p role="alert" key={key} className="text-sm text-red-700">{message}</p>)}
                <button disabled={form.processing} className={cashPrimary}>{form.processing ? 'Saving…' : action === 'authorize' ? 'Authorize release' : action === 'record-payment' ? 'Save payment record' : 'Save linked correction'}</button>
            </form>}
            <section className={`${cashSurface} p-5`}><h2 className="font-semibold">Retained decisions and receipts</h2>{history.length === 0 && <p className="mt-3 text-sm text-slate-600">No release or payment decision has been recorded.</p>}<ol className="mt-4 space-y-4">{history.map(event => <li key={event.id} className="rounded-lg border border-slate-300 p-4"><div className="flex flex-wrap justify-between gap-2"><p className="font-semibold">{event.event_type === 'release_authorized' ? 'Release authorized' : event.event_type === 'payment_recorded' ? 'Payment recorded' : 'Payment reference corrected'}</p><p className="text-sm">{cashDate(event.created_at)}</p></div><p className="mt-2 break-all text-xs text-slate-600">{event.reference}</p><p className="mt-2 text-sm">{event.actor_name} · {event.actor_role}</p><p className="mt-2 text-sm">{event.reason}</p>{event.payment_reference && <p className="mt-2 break-all text-sm">Payment reference: {event.payment_reference}</p>}{event.source_reference && <p className="mt-2 break-all text-sm">Original evidence: {event.source_reference} · Previous reference: {event.context.previous_reference}</p>}{event.proof_url && <a className={`${cashButton} mt-3`} href={event.proof_url}>Open private payment evidence</a>}</li>)}</ol></section>
        </div>
    </DashboardLayout>;
}
