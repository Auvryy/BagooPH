import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import useRequestError from '@/hooks/useCourierRequestError';
import { returnDate } from './ReturnStatus';

const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
const names: Record<string, string> = {
    'approve-repayment': 'Approve original amount', 'offer-recovery': 'Record seller transfer', 'receive-recovery': 'Confirm recovery receipt',
    'offer-funding': 'Record funding transfer', 'receive-funding': 'Confirm funding receipt', 'record-repayment': 'Record buyer repayment',
    'confirm-receipt': 'I received the repayment', 'report-nonreceipt': 'Report missing repayment', 'resolve-nonreceipt': 'Add payment review evidence',
    'close-zero-return': 'Close with no repayment due',
};
const historyNames: Record<string, string> = { ...names, 'approve-repayment': 'Original amount approved',
    'offer-recovery': 'Seller transfer offered', 'receive-recovery': 'Seller recovery received', 'offer-funding': 'Funding transfer offered',
    'receive-funding': 'Funding received', 'record-repayment': 'Repayment recorded', 'confirm-receipt': 'Buyer confirmed receipt',
    'report-nonreceipt': 'Buyer reported non-receipt', 'resolve-nonreceipt': 'Payment review evidence added', 'close-zero-return': 'Closed with no repayment due' };

export interface ReturnMoney {
    adjustment: null | { gross_cents: number; commission_cents: number; shop_discount_cents: number; platform_discount_cents: number;
        buyer_cents: number; seller_cents: number; platform_cents: number };
    actions: string[]; recovery_due_cents?: number; recovery_received_cents?: number; recovery_remaining_cents?: number;
    backing?: { verified: boolean; available_cents: number; reconciled_cents: number; received_cents: number;
        outgoing_cents: number; seller_paid_cents: number; protected_seller_cents: number; protected_shipping_cents: number };
    pending_offers?: Array<{ id: number; event_type: string; amount_cents: number; cash_reference: string }>;
    receivers?: Array<{ id: number; name: string }>;
    history: Array<{ id: number; reference: string; event_type: string; amount_cents: number; cash_reference: string | null;
        reason: string; source_event_id: number | null; proof_url: string | null; created_at: string }>;
}

export default function ReturnMoneyPanel({ value, caseId, version }: { value: ReturnMoney; caseId: number; version: number }) {
    const form = useForm({ action: '', reason: '', amount: '', cash_reference: '', source_event_id: '', recipient_id: '',
        proof: null as File | null, payment_confirmed: false, expected_version: version, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', reason: '', amount: '', cash_reference: '', source_event_id: '', recipient_id: '', proof: null,
        payment_confirmed: false, expected_version: version, request_token: crypto.randomUUID() }); form.clearErrors(); }, [caseId, version]);
    const action = form.data.action;
    const offer = ['offer-recovery', 'offer-funding'].includes(action);
    const receipt = ['receive-recovery', 'receive-funding'].includes(action);
    const needsProof = offer || receipt || ['record-repayment', 'resolve-nonreceipt'].includes(action);
    const payment = [...value.history].reverse().find(event => event.event_type === 'record-repayment');
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !value.actions.includes(action)) return;
        if (needsProof && (!form.data.proof || form.data.proof.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(form.data.proof.type))) {
            setFailure('Choose a JPG, PNG, WebP or PDF receipt up to 5 MB.'); return;
        }
        pending.current = true; setFailure('');
        form.transform(data => ({ action, reason: data.reason.trim(), expected_version: data.expected_version, request_token: data.request_token,
            ...(offer ? { amount: data.amount, recipient_id: data.recipient_id } : {}), ...(offer || action === 'record-repayment' ? { cash_reference: data.cash_reference.trim() } : {}),
            ...(needsProof ? { proof: data.proof, payment_confirmed: data.payment_confirmed } : {}),
            ...(receipt ? { source_event_id: data.source_event_id } : ['confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt'].includes(action) ? { source_event_id: payment?.id } : {}) }));
        form.post(`/buyer-returns/${caseId}/money`, { preserveScroll: true, forceFormData: true, onFinish: () => { pending.current = false; } });
    };
    const totals = value.adjustment;
    return <section className="space-y-5 rounded-2xl border border-slate-300 bg-white p-5 sm:p-6">
        <div><h2 className="flex items-center gap-2 text-lg font-bold text-slate-900"><Receipt className="h-5 w-5 text-[#E00D42]" />Return repayment</h2><p className="mt-2 text-sm leading-6 text-slate-600">Original merchandise and its product discounts determine the amount. Shipping stays separate. Approval and transfer offers do not confirm money received.</p></div>
        {totals ? <dl className="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">{[['Original returned merchandise', totals.gross_cents], ['Original product discounts', totals.shop_discount_cents + totals.platform_discount_cents], ['Buyer repayment due', totals.buyer_cents], ...(value.recovery_remaining_cents !== undefined ? [['Seller recovery outstanding', value.recovery_remaining_cents]] : [])].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 text-base font-bold text-slate-900">{money(Number(cents))}</dd></div>)}</dl> : <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">The original amount can be approved after actual seller receipt and condition review.</p>}
        {value.backing && <div className="space-y-3 border-t border-slate-300 pt-4"><h3 className="text-sm font-bold text-slate-900">Actual repayment backing</h3><dl className="grid gap-3 text-sm sm:grid-cols-2">{[['Original reconciled COD', value.backing.reconciled_cents], ['Additional money received', value.backing.received_cents], ['Seller already paid', value.backing.seller_paid_cents], ['Remaining seller proceeds protected', value.backing.protected_seller_cents], ['Shipping protected', value.backing.protected_shipping_cents], ['Buyer repayments recorded', value.backing.outgoing_cents], ['Available after obligations', value.backing.available_cents]].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 font-semibold">{money(Number(cents))}</dd></div>)}</dl>{(!value.backing.verified || totals && value.backing.available_cents < totals.buyer_cents) && <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Awaiting confirmed backing. Reconcile original COD and confirm enough actual incoming funds before recording a repayment.</p>}</div>}
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
        {value.actions.length > 0 && <form onSubmit={submit} className="space-y-4 border-t border-slate-300 pt-5" aria-busy={form.processing || undefined}><label className="block text-sm font-semibold">Payment action<select required disabled={form.processing} value={action} onChange={event => form.setData('action', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Choose an action</option>{value.actions.map(name => <option key={name} value={name}>{names[name]}</option>)}</select></label>
            {action === 'record-repayment' && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-900">Record the actual {money(totals?.buyer_cents ?? 0)} transfer to the original buyer. The buyer must confirm receiving it. This form does not send money.</p>}
            {action === 'resolve-nonreceipt' && <p className="text-sm text-slate-600">Add the actual payment investigation evidence. The original repayment stays recorded and the buyer must still confirm receipt. Do not pay again.</p>}
            {offer && <label className="block text-sm font-semibold">Actual transfer amount (PHP)<input required inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" maxLength={12} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label>}
            {offer && <label className="block text-sm font-semibold">Receiving platform admin<select required value={form.data.recipient_id} onChange={event => form.setData('recipient_id', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Who actually received this transfer?</option>{(value.receivers ?? []).map(receiver => <option key={receiver.id} value={receiver.id}>{receiver.name}</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">The named recipient must independently confirm the incoming transfer.</span></label>}
            {receipt && <label className="block text-sm font-semibold">Original incoming transfer<select required value={form.data.source_event_id} onChange={event => form.setData('source_event_id', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Choose your unreceived transfer</option>{(value.pending_offers ?? []).filter(event => event.event_type === (action === 'receive-recovery' ? 'offer-recovery' : 'offer-funding')).map(event => <option key={event.id} value={event.id}>{event.cash_reference} · {money(event.amount_cents)}</option>)}</select></label>}
            {(offer || action === 'record-repayment') && <label className="block text-sm font-semibold">Actual transfer reference<input required minLength={3} maxLength={120} value={form.data.cash_reference} onChange={event => form.setData('cash_reference', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label>}
            {needsProof && <><label className="block text-sm font-semibold">Private receipt<input key={`${caseId}-${version}-${action}`} required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label><p className="text-xs text-slate-500">JPG, PNG, WebP or PDF, up to 5 MB.</p><label className="flex items-start gap-2 text-sm"><input required type="checkbox" checked={form.data.payment_confirmed} onChange={event => form.setData('payment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I checked the actual transfer, amount, recipient and private receipt.</label></>}
            <label className="block text-sm font-semibold">{action === 'confirm-receipt' ? 'Confirm what you received' : 'Reason or receipt details'}<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /><span className="mt-1 block text-xs font-normal text-slate-500">10–1,000 characters. Describe the actual payment or review.</span></label>{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}<button disabled={form.processing || !action} className="rounded-xl bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : names[action] ?? 'Save payment update'}</button>
        </form>}
        {value.history.length > 0 && <ol className="space-y-4 border-t border-slate-300 pt-5">{value.history.map(event => <li key={event.id} className="border-b border-slate-200 pb-4 last:border-0 last:pb-0"><div className="flex flex-wrap justify-between gap-2"><p className="text-sm font-bold">{historyNames[event.event_type]}</p><p className="text-xs text-slate-500">{returnDate(event.created_at)}</p></div>{event.amount_cents > 0 && <p className="mt-2 text-sm font-semibold">{money(event.amount_cents)}</p>}<p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{event.reason}</p>{event.cash_reference && <p className="mt-2 break-all text-xs text-slate-600">Transfer reference: {event.cash_reference}</p>}{event.proof_url && <a href={event.proof_url} className="mt-3 inline-block text-sm font-semibold text-[#C20836] underline">Download private receipt</a>}</li>)}</ol>}
    </section>;
}
