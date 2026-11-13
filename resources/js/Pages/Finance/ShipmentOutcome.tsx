import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Receipt } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ShipmentAmountSummary, { ShipmentAmounts, shipmentMoney, shipmentStatuses } from '@/Components/Finance/ShipmentAmounts';
import { returnDate } from '@/Components/Returns/ReturnStatus';
import useRequestError from '@/hooks/useCourierRequestError';

interface Plan {
    id: number; reference: string; kind: string; status: string; version: number; mode: string; order: { id: number; order_number: string };
    amounts: ShipmentAmounts; state: { assessed: boolean; due_cents: number; received_cents: number; remaining_cents: number; pending_offer_cents: number };
    actions: string[]; review_reason: string | null; cost_reference: string | null; review_proof_url: string | null; wallet_balance_cents: number | null;
    receivers: Array<{ id: number; name: string }>; pending_offers: Array<{ id: number; amount_cents: number; cash_reference: string }>;
    history: Array<{ id: number; reference: string; event_type: string; amount_cents: number; created_at: string; reason: string | null;
        cash_reference: string | null; proof_url: string | null; wallet_reference: string | null; no_buyer_payment: boolean }>;
}
const actions: Record<string, string> = { 'execute-wallet-refusal': 'Apply approved Wallet amounts', 'assess-cod-refusal': 'Record zero refund and shipping balance',
    'offer-shipping-payment': 'Record my actual shipping transfer', 'receive-shipping-payment': 'Confirm incoming shipping payment', 'pay-wallet-shipping': 'Pay shipping from my Wallet' };
const historyNames: Record<string, string> = { approved: 'Amounts approved · no money moved', 'wallet-refund': 'Buyer Wallet credited', 'zero-refund-assessed': 'Recorded zero buyer refund',
    'offer-shipping-payment': 'Buyer shipping transfer recorded', 'receive-shipping-payment': 'Incoming shipping payment confirmed', 'pay-wallet-shipping': 'Buyer paid separate shipping balance' };
const field = 'mt-2 w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';

export default function ShipmentOutcome({ financialPlan: plan }: { financialPlan: Plan }) {
    const initial = () => ({ action: '', expected_version: plan.version, request_token: crypto.randomUUID(), reason: '', amount: '', cash_reference: '',
        source_event_id: '', recipient_id: '', proof: null as File | null, payment_confirmed: false, wallet_refund_confirmed: false, assessment_confirmed: false });
    const form = useForm(initial());
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData(initial()); form.clearErrors(); }, [plan.id, plan.version]);
    const action = form.data.action;
    const offer = action === 'offer-shipping-payment';
    const receive = action === 'receive-shipping-payment';
    const virtual = action === 'pay-wallet-shipping';
    const cashProof = offer || receive;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !plan.actions.includes(action)) return;
        if (cashProof && (!form.data.proof || form.data.proof.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(form.data.proof.type))) {
            setFailure('Choose a real JPG, PNG, WebP or PDF receipt up to 5 MB.'); return;
        }
        pending.current = true; setFailure('');
        form.transform(data => ({ action, expected_version: data.expected_version, request_token: data.request_token, reason: data.reason.trim(),
            ...(action === 'execute-wallet-refusal' ? { wallet_refund_confirmed: data.wallet_refund_confirmed } : {}),
            ...(action === 'assess-cod-refusal' ? { assessment_confirmed: data.assessment_confirmed } : {}),
            ...(offer || virtual ? { amount: data.amount } : {}), ...(offer ? { recipient_id: data.recipient_id, cash_reference: data.cash_reference.trim() } : {}),
            ...(receive ? { source_event_id: data.source_event_id } : {}), ...(cashProof ? { proof: data.proof } : {}),
            ...(cashProof || virtual ? { payment_confirmed: data.payment_confirmed } : {}) }));
        form.post(`/shipment-finances/${plan.id}/actions`, { preserveScroll: true, forceFormData: cashProof, onFinish: () => { pending.current = false; } });
    };
    return <ReturnLayout title="Shipment finances" subtitle={plan.order.order_number}><Head title="Shipping and refund outcome" /><main className="mx-auto max-w-4xl space-y-6 py-4">
        <Link href="/return-liabilities?view=shipments" className="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white"><ArrowLeft className="h-4 w-4" />Undelivered parcels</Link>
        <header className="rounded-2xl border border-rose-200 bg-white p-5 shadow-sm"><div className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="flex items-center gap-2 text-xl font-bold"><Receipt className="h-5 w-5 text-[#E00D42]" />Shipping and refund</h1><p className="mt-2 text-sm text-slate-600">Purchase {plan.order.order_number}</p></div><span className="rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-[#C20836]">{shipmentStatuses[plan.status] ?? plan.status}</span></div><p className="mt-3 break-all text-xs text-slate-500">{plan.reference}</p><p className="mt-3 text-xs font-semibold text-[#C20836]">{plan.mode === 'wallet' ? 'Demonstration Wallet credits' : 'COD not collected · no merchandise refund'}</p></header>
        <ShipmentAmountSummary value={plan.amounts} outstanding={plan.state.remaining_cents} />
        {(plan.review_reason || plan.cost_reference || plan.review_proof_url) && <details className="rounded-xl border border-slate-300 bg-white p-5"><summary className="cursor-pointer text-sm font-bold text-[#C20836]">Saved independent review</summary>{plan.review_reason && <p className="mt-4 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{plan.review_reason}</p>}{plan.cost_reference && <p className="mt-3 break-words text-xs text-slate-600">Cost reference: {plan.cost_reference}</p>}{plan.review_proof_url && <a href={plan.review_proof_url} className="mt-3 inline-flex rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white">Download private review evidence</a>}</details>}
        {(plan.state.received_cents > 0 || plan.state.pending_offer_cents > 0 || plan.wallet_balance_cents !== null) && <dl className="grid gap-4 rounded-xl border border-slate-300 bg-white p-5 text-sm sm:grid-cols-3">{[
            ['Shipping payments received', plan.state.received_cents], ['Transfers awaiting receipt', plan.state.pending_offer_cents],
            ...(plan.wallet_balance_cents !== null ? [['My available virtual credits', plan.wallet_balance_cents]] : []),
        ].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 font-bold">{shipmentMoney(Number(cents))}</dd></div>)}</dl>}
        {plan.actions.length > 0 && <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-sm" aria-busy={form.processing || undefined}>
            <label className="block text-sm font-semibold">Financial action<select required disabled={form.processing} value={action} onChange={event => form.setData('action', event.target.value)} className={field}><option value="">Choose an action</option>{plan.actions.map(name => <option key={name} value={name}>{actions[name]}</option>)}</select></label>
            {(offer || virtual) && <label className="block text-sm font-semibold">{virtual ? 'Virtual credits to pay' : 'Actual transfer amount (PHP)'}<input required inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" maxLength={12} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className={field} /></label>}
            {offer && <><label className="block text-sm font-semibold">Receiving admin<select required value={form.data.recipient_id} onChange={event => form.setData('recipient_id', event.target.value)} className={field}><option value="">Who actually received the transfer?</option>{plan.receivers.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label><label className="block text-sm font-semibold">Actual transfer reference<input required minLength={3} maxLength={120} value={form.data.cash_reference} onChange={event => form.setData('cash_reference', event.target.value)} className={field} /></label></>}
            {receive && <label className="block text-sm font-semibold">Original incoming transfer<select required value={form.data.source_event_id} onChange={event => form.setData('source_event_id', event.target.value)} className={field}><option value="">Choose your unreceived transfer</option>{plan.pending_offers.map(item => <option key={item.id} value={item.id}>{item.cash_reference} · {shipmentMoney(item.amount_cents)}</option>)}</select></label>}
            {cashProof && <label className="block text-sm font-semibold">Private receipt<input key={`${plan.id}-${plan.version}-${action}`} required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className={field} /><span className="mt-1 block text-xs font-normal text-slate-500">JPG, PNG, WebP or PDF · up to 5 MB.</span></label>}
            <label className="block text-sm font-semibold">Reason or receipt details<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
            {action === 'execute-wallet-refusal' && <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data.wallet_refund_confirmed} onChange={event => form.setData('wallet_refund_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I reviewed the original buyer and approved amounts. Apply these virtual entries once.</label>}
            {action === 'assess-cod-refusal' && <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data.assessment_confirmed} onChange={event => form.setData('assessment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />No COD was collected. Record zero buyer refund and the separate reviewed shipping balance.</label>}
            {(cashProof || virtual) && <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data.payment_confirmed} onChange={event => form.setData('payment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />{virtual ? 'Pay the entered shipping amount from my available virtual credits.' : 'I checked the actual amount, receiving person and private transfer receipt.'}</label>}
            {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}
            <button disabled={form.processing || !action} className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : actions[action] ?? 'Save financial update'}</button>
        </form>}
        {plan.history.length > 0 && <section className="rounded-2xl border border-slate-300 bg-white p-5"><h2 className="text-sm font-bold">Financial history</h2><ol className="mt-4 space-y-4">{plan.history.map(item => <li key={item.id} className="space-y-2 border-b border-slate-200 pb-4 last:border-0 last:pb-0"><div className="flex flex-wrap justify-between gap-2"><h3 className="text-sm font-bold">{historyNames[item.event_type] ?? 'Financial update'}</h3><span className="text-xs text-slate-500">{returnDate(item.created_at)}</span></div>{item.amount_cents > 0 && <p className="text-sm font-bold">{shipmentMoney(item.amount_cents)}</p>}{item.reason && <p className="whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{item.reason}</p>}{item.wallet_reference && <p className="break-all text-xs text-slate-600">Financial journal: {item.wallet_reference}</p>}{item.cash_reference && <p className="break-all text-xs text-slate-600">Transfer reference: {item.cash_reference}</p>}{item.proof_url && <a href={item.proof_url} className="inline-flex rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white">Download private receipt</a>}</li>)}</ol></section>}
    </main></ReturnLayout>;
}
