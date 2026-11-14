import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import useRequestError from '@/hooks/useCourierRequestError';
import { returnDate } from './ReturnStatus';
import RefundFundingCredits from './RefundFundingCredits';

const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
const names: Record<string, string> = {
    'approve-repayment': 'Approve original amount', 'offer-recovery': 'Record seller transfer', 'receive-recovery': 'Confirm recovery receipt',
    'offer-funding': 'Record funding transfer', 'receive-funding': 'Confirm funding receipt', 'record-repayment': 'Record buyer repayment',
    'confirm-receipt': 'I received the repayment', 'report-nonreceipt': 'Report missing repayment', 'resolve-nonreceipt': 'Add payment review evidence',
    'close-zero-return': 'Close with no repayment due',
    'refund-wallet': 'Refund to Bagoo Wallet',
    'offer-compensation': 'Record company transfer', 'receive-compensation': 'Confirm company receipt',
    'reserve-wallet-original': 'Reserve original Wallet funds', 'fund-wallet-liability': 'Fund refund from my Wallet',
    'advance-wallet-funding': 'Advance demonstration credits',
};
const historyNames: Record<string, string> = { ...names, 'approve-repayment': 'Original amount approved',
    'offer-recovery': 'Seller transfer offered', 'receive-recovery': 'Seller recovery received', 'offer-funding': 'Funding transfer offered',
    'receive-funding': 'Funding received', 'record-repayment': 'Repayment recorded', 'confirm-receipt': 'Buyer confirmed receipt',
    'report-nonreceipt': 'Buyer reported non-receipt', 'resolve-nonreceipt': 'Payment review evidence added', 'close-zero-return': 'Closed with no repayment due', 'refund-wallet': 'Refund credited to the original Bagoo Wallet',
    'offer-compensation': 'Company transfer offered', 'receive-compensation': 'Company funding received',
    'reserve-wallet-original': 'Original Wallet funds reserved', 'fund-wallet-liability': 'Own Wallet contribution reserved',
    'advance-wallet-funding': 'Demonstration advance reserved', 'wallet-funding-credit': 'Demonstration credits granted to the liable account' };

export interface ReturnMoney {
    shipment_finance?: { id: number; reference: string; return_reference: string; href: string };
    mode?: 'wallet' | 'cod'; policy_version?: number; review_token?: string; blockers?: string[];
    original_payment?: { reference: string; paid_cents: number };
    original_seller_payment?: null | { reference: string; amount_cents: number };
    plan?: null | { reference: string | null; is_preview: boolean; fault: string; original_buyer_merchandise_cents: number;
        outbound_shipping_refund_cents: number; buyer_refund_cents: number };
    reserve?: { balance_cents: number; original_required_cents: number; original_reserved_cents: number; liability_due_cents: number;
        liable_received_cents: number; liability_remaining_cents: number; advance_outstanding_cents: number; refund_goal_cents: number };
    funding?: { required_backing_cents: number; received_backing_cents: number };
    owned_wallet_balance_cents?: number | null; owned_wallet_available_cents?: number; compensation_remaining_cents?: number;
    funding_credit_max_cents?: number;
    funding_credit_requests?: Array<{ id: number; reference: string; amount_cents: number; reason: string; status: string; version: number;
        review_reason: string | null; actor_name: string; can_review: boolean; wallet_reference: string | null }>;
    adjustment: null | { gross_cents: number; commission_cents: number; shop_discount_cents: number; platform_discount_cents: number;
        buyer_cents: number; seller_cents: number; platform_cents: number };
    actions: string[]; recovery_due_cents?: number; recovery_received_cents?: number; recovery_remaining_cents?: number;
    backing?: { verified: boolean; available_cents: number; reconciled_cents: number; received_cents: number;
        outgoing_cents: number; seller_paid_cents: number; protected_seller_cents: number; protected_shipping_cents: number };
    pending_offers?: Array<{ id: number; event_type: string; amount_cents: number; cash_reference: string }>;
    receivers?: Array<{ id: number; name: string }>;
    history: Array<{ id: number; reference: string; event_type: string; amount_cents: number; cash_reference: string | null;
        reason: string; source_event_id: number | null; proof_url: string | null; created_at: string; wallet_reference?: string | null }>;
}

export default function ReturnMoneyPanel({ value, caseId, version }: { value: ReturnMoney; caseId: number; version: number }) {
    const form = useForm({ action: '', reason: '', amount: '', cash_reference: '', source_event_id: '', recipient_id: '',
        proof: null as File | null, payment_confirmed: false, wallet_refund_confirmed: false, wallet_reserve_confirmed: false,
        wallet_funding_confirmed: false, demo_advance_confirmed: false, expected_version: version, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', reason: '', amount: '', cash_reference: '', source_event_id: '', recipient_id: '', proof: null,
        payment_confirmed: false, wallet_refund_confirmed: false, wallet_reserve_confirmed: false, wallet_funding_confirmed: false,
        demo_advance_confirmed: false, expected_version: version, request_token: crypto.randomUUID() }); form.clearErrors(); }, [caseId, version]);
    const action = form.data.action;
    const offer = ['offer-recovery', 'offer-funding', 'offer-compensation'].includes(action);
    const receipt = ['receive-recovery', 'receive-funding', 'receive-compensation'].includes(action);
    const walletAmount = ['fund-wallet-liability', 'advance-wallet-funding'].includes(action);
    const confirmations = {
        'reserve-wallet-original': ['wallet_reserve_confirmed', 'I reviewed the original payment. Move its available original funds into this refund reserve.'],
        'fund-wallet-liability': ['wallet_funding_confirmed', 'Transfer the entered virtual credits from my own Wallet into this refund reserve.'],
        'advance-wallet-funding': ['demo_advance_confirmed', 'Issue the entered demonstration credits as a platform advance. The liable account still owes its contribution.'],
    } as const;
    const confirmation = confirmations[action as keyof typeof confirmations];
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
            ...(action === 'refund-wallet' ? { wallet_refund_confirmed: data.wallet_refund_confirmed } : {}),
            ...(action === 'approve-repayment' && value.policy_version === 2 ? { review_token: value.review_token } : {}),
            ...(walletAmount ? { amount: data.amount } : {}),
            ...(confirmation ? { [confirmation[0]]: data[confirmation[0]] } : {}),
            ...(receipt ? { source_event_id: data.source_event_id } : ['confirm-receipt', 'report-nonreceipt', 'resolve-nonreceipt'].includes(action) ? { source_event_id: payment?.id } : {}) }));
        form.post(`/buyer-returns/${caseId}/money`, { preserveScroll: true, forceFormData: true, onFinish: () => { pending.current = false; } });
    };
    if (value.shipment_finance) return <section className="space-y-4 rounded-lg border border-rose-200 bg-white p-5 shadow-sm"><h2 className="flex items-center gap-2 text-lg font-bold text-slate-900"><Receipt className="h-5 w-5 text-[#E00D42]" />Parcel loss financial review</h2><p className="text-sm leading-6 text-slate-600">This return has its own approved loss review. Its funding and payment history stay with that original claim.</p><Link href={value.shipment_finance.href} className="inline-flex rounded-lg border border-rose-200 bg-rose-50/85 px-4 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white">Review loss finances</Link></section>;
    const totals = value.adjustment;
    const due = value.plan?.buyer_refund_cents ?? totals?.buyer_cents ?? 0;
    const breakdown: Array<[string, number]> = value.plan ? [
        ['Returned items after original discounts', value.plan.original_buyer_merchandise_cents],
        ['Original paid shipping to refund', value.plan.outbound_shipping_refund_cents], ['Buyer repayment due', due],
    ] : totals ? [['Original returned merchandise', totals.gross_cents], ['Original product discounts', totals.shop_discount_cents + totals.platform_discount_cents], ['Buyer repayment due', due]] : [];
    if (value.recovery_remaining_cents !== undefined) breakdown.push(['Seller recovery outstanding', value.recovery_remaining_cents]);
    if (value.compensation_remaining_cents !== undefined) breakdown.push(['Company funding outstanding', value.compensation_remaining_cents]);
    return <section className="space-y-5 rounded-2xl border border-slate-300 bg-white p-5 sm:p-6">
        <div><h2 className="flex items-center gap-2 text-lg font-bold text-slate-900"><Receipt className="h-5 w-5 text-[#E00D42]" />{value.mode === 'wallet' ? 'Wallet return refund' : 'Return repayment'}</h2><p className="mt-2 text-sm leading-6 text-slate-600">{value.policy_version === 2 ? (value.mode === 'wallet' ? 'Demonstration credits. Funding the reserve and refunding the buyer are separate actions.' : 'Confirm incoming funds before recording the buyer’s actual repayment.') : value.mode === 'wallet' ? 'The approved original merchandise amount returns as virtual credits to the buyer’s original wallet. Shipping stays separate. The saved wallet reference confirms the actual credit.' : 'Original merchandise and its product discounts determine the amount. Shipping stays separate. Approval and transfer offers do not confirm money received.'}</p></div>
        {value.plan?.is_preview && <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Review preview · No amount has been approved or transferred.</p>}
        {value.original_payment && <dl className="space-y-3 rounded-xl border border-slate-300 p-4 text-sm"><div><dt className="text-xs text-slate-600">Original buyer payment · {money(value.original_payment.paid_cents)}</dt><dd className="mt-1 break-all font-semibold">{value.original_payment.reference}</dd></div><div><dt className="text-xs text-slate-600">Original seller payout</dt><dd className="mt-1 break-all font-semibold">{value.original_seller_payment ? `${money(value.original_seller_payment.amount_cents)} · ${value.original_seller_payment.reference}` : 'No original seller payout recorded'}</dd></div></dl>}
        {breakdown.length ? <dl className="grid gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-2">{breakdown.map(([label, cents]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 text-base font-bold text-slate-900">{money(cents)}</dd></div>)}</dl> : <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">The original amount can be reviewed after seller receipt and condition review.</p>}
        {value.blockers?.map(message => <p key={message} role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{message}</p>)}
        {value.reserve && <div className="rounded-xl border border-rose-200 bg-rose-50/50 p-4"><h3 className="text-sm font-bold text-[#C20836]">Demonstration refund reserve</h3><dl className="mt-3 grid gap-4 sm:grid-cols-2">{[
            ['Original funds reserved', value.reserve.original_reserved_cents], ['Original funds required', value.reserve.original_required_cents],
            ['Liable account contribution received', value.reserve.liable_received_cents], ['Liable account contribution outstanding', value.reserve.liability_remaining_cents],
            ['Platform advance outstanding', value.reserve.advance_outstanding_cents], ['Current reserve balance', value.reserve.balance_cents],
            ...(value.owned_wallet_balance_cents !== undefined && value.owned_wallet_balance_cents !== null ? [['My Wallet balance', value.owned_wallet_balance_cents]] : []),
            ...(value.owned_wallet_available_cents !== undefined && value.owned_wallet_available_cents !== value.owned_wallet_balance_cents ? [['Available for this return', value.owned_wallet_available_cents]] : []),
        ].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 text-sm font-bold">{money(Number(cents))}</dd></div>)}</dl></div>}
        {(value.funding_credit_requests || (value.funding_credit_max_cents ?? 0) > 0) && <RefundFundingCredits value={value} caseId={caseId} version={version} />}
        {value.backing && <div className="space-y-3 border-t border-slate-300 pt-4"><h3 className="text-sm font-bold text-slate-900">Actual repayment backing</h3><dl className="grid gap-3 text-sm sm:grid-cols-2">{[['Original reconciled COD', value.backing.reconciled_cents], ['Additional money received', value.backing.received_cents], ['Seller already paid', value.backing.seller_paid_cents], ['Remaining seller proceeds protected', value.backing.protected_seller_cents], ['Shipping protected', value.backing.protected_shipping_cents], ['Buyer repayments recorded', value.backing.outgoing_cents], ['Available after obligations', value.backing.available_cents]].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 font-semibold">{money(Number(cents))}</dd></div>)}</dl>{(!value.backing.verified || value.backing.available_cents < due || value.funding && value.funding.received_backing_cents < value.funding.required_backing_cents) && <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Awaiting confirmed backing. Reconcile original COD and confirm enough actual incoming funds before recording a repayment.</p>}</div>}
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
        {value.actions.length > 0 && <form onSubmit={submit} className="space-y-4 border-t border-slate-300 pt-5" aria-busy={form.processing || undefined}><label className="block text-sm font-semibold">Payment action<select required disabled={form.processing} value={action} onChange={event => form.setData('action', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Choose an action</option>{value.actions.map(name => <option key={name} value={name}>{names[name]}</option>)}</select></label>
            {action === 'record-repayment' && <p className="rounded-lg bg-rose-50 p-3 text-sm text-rose-900">Record the actual {money(due)} transfer to the original buyer. The buyer must confirm receiving it. This form does not send money.</p>}
            {action === 'resolve-nonreceipt' && <p className="text-sm text-slate-600">Add the actual payment investigation evidence. The original repayment stays recorded and the buyer must still confirm receipt. Do not pay again.</p>}
            {action === 'refund-wallet' && <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data.wallet_refund_confirmed} onChange={event => form.setData('wallet_refund_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I reviewed the original buyer, returned units and exact virtual credits. Refund them to the original Bagoo Wallet once.</label>}
            {confirmation && <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data[confirmation[0]]} onChange={event => form.setData(confirmation[0], event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />{confirmation[1]}</label>}
            {(offer || walletAmount) && <label className="block text-sm font-semibold">{walletAmount ? 'Virtual credits (PHP equivalent)' : 'Actual transfer amount (PHP)'}<input required inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" maxLength={12} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label>}
            {offer && <label className="block text-sm font-semibold">Receiving platform admin<select required value={form.data.recipient_id} onChange={event => form.setData('recipient_id', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Who actually received this transfer?</option>{(value.receivers ?? []).map(receiver => <option key={receiver.id} value={receiver.id}>{receiver.name}</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">The named recipient must independently confirm the incoming transfer.</span></label>}
            {receipt && <label className="block text-sm font-semibold">Original incoming transfer<select required value={form.data.source_event_id} onChange={event => form.setData('source_event_id', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm"><option value="">Choose your unreceived transfer</option>{(value.pending_offers ?? []).filter(event => event.event_type === action.replace('receive-', 'offer-')).map(event => <option key={event.id} value={event.id}>{event.cash_reference} · {money(event.amount_cents)}</option>)}</select></label>}
            {(offer || action === 'record-repayment') && <label className="block text-sm font-semibold">Actual transfer reference<input required minLength={3} maxLength={120} value={form.data.cash_reference} onChange={event => form.setData('cash_reference', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label>}
            {needsProof && <><label className="block text-sm font-semibold">Private receipt<input key={`${caseId}-${version}-${action}`} required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /></label><p className="text-xs text-slate-500">JPG, PNG, WebP or PDF, up to 5 MB.</p><label className="flex items-start gap-2 text-sm"><input required type="checkbox" checked={form.data.payment_confirmed} onChange={event => form.setData('payment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I checked the actual transfer, amount, recipient and private receipt.</label></>}
            <label className="block text-sm font-semibold">{action === 'confirm-receipt' ? 'Confirm what you received' : 'Reason or receipt details'}<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 text-sm" /><span className="mt-1 block text-xs font-normal text-slate-500">10–1,000 characters. Describe the actual payment or review.</span></label>{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}<button disabled={form.processing || !action} className="rounded-xl bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : names[action] ?? 'Save payment update'}</button>
        </form>}
        {value.history.length > 0 && <ol className="space-y-4 border-t border-slate-300 pt-5">{value.history.map(event => <li key={event.id} className="border-b border-slate-200 pb-4 last:border-0 last:pb-0"><div className="flex flex-wrap justify-between gap-2"><p className="text-sm font-bold">{historyNames[event.event_type] ?? 'Payment update'}</p><p className="text-xs text-slate-500">{returnDate(event.created_at)}</p></div>{event.amount_cents > 0 && <p className="mt-2 text-sm font-semibold">{money(event.amount_cents)}</p>}<p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{event.reason}</p>{event.wallet_reference && <p className="mt-2 break-all text-xs text-slate-600">Wallet reference: {event.wallet_reference}</p>}{event.cash_reference && <p className="mt-2 break-all text-xs text-slate-600">Transfer reference: {event.cash_reference}</p>}{event.proof_url && <a href={event.proof_url} className="mt-3 inline-block text-sm font-semibold text-[#C20836] underline">Download private receipt</a>}</li>)}</ol>}
    </section>;
}
