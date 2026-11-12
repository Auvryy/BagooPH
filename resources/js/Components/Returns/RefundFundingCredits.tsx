import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import useRequestError from '@/hooks/useCourierRequestError';
import type { ReturnMoney } from './ReturnMoneyPanel';

const money = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
const field = 'mt-2 w-full rounded-lg border border-slate-300 text-sm';
type FundingRequest = NonNullable<ReturnMoney['funding_credit_requests']>[number];

function Review({ request, caseId }: { request: FundingRequest; caseId: number }) {
    const form = useForm({ decision: '', reason: '', demo_credit_confirmed: false, version: request.version, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !['approved', 'rejected'].includes(form.data.decision)) return;
        pending.current = true; setFailure('');
        form.transform(data => ({ decision: data.decision, reason: data.reason.trim(), version: data.version,
            request_token: data.request_token, ...(data.decision === 'approved' ? { demo_credit_confirmed: data.demo_credit_confirmed } : {}) }));
        form.post(`/buyer-returns/${caseId}/wallet-funding-credits/${request.id}/review`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    return <form onSubmit={submit} className="mt-4 space-y-4" aria-busy={form.processing || undefined}>
        <label className="block text-sm font-semibold">Independent funding review<select required value={form.data.decision} onChange={event => form.setData('decision', event.target.value)} className={field}><option value="">Choose a decision</option><option value="approved">Approve demonstration credits</option><option value="rejected">Decline request</option></select></label>
        <label className="block text-sm font-semibold">Review reason<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
        {form.data.decision === 'approved' && <label className="flex items-start gap-2 text-sm leading-6"><input type="checkbox" required checked={form.data.demo_credit_confirmed} onChange={event => form.setData('demo_credit_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />Grant {money(request.amount_cents)} in demonstration credits to {request.actor_name}. The account must separately fund its refund obligation.</label>}
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
        {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}
        <button disabled={form.processing || !form.data.decision} className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Saving…' : 'Save funding review'}</button>
    </form>;
}

export default function RefundFundingCredits({ value, caseId, version }: { value: ReturnMoney; caseId: number; version: number }) {
    const form = useForm({ amount: '', reason: '', expected_version: version, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ amount: '', reason: '', expected_version: version, request_token: crypto.randomUUID() }); form.clearErrors(); }, [caseId, version]);
    const requests = value.funding_credit_requests ?? [];
    const maximum = value.funding_credit_max_cents ?? 0;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || maximum <= 0 || requests.some(request => request.status === 'pending')) return;
        pending.current = true; setFailure('');
        form.transform(data => ({ ...data, reason: data.reason.trim() }));
        form.post(`/buyer-returns/${caseId}/wallet-funding-credits`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    return <section className="space-y-4 border-t border-slate-300 pt-5">
        <div><h3 className="text-sm font-bold text-slate-900">Demonstration funding credits</h3><p className="mt-1 text-sm leading-6 text-slate-600">A reviewed grant adds virtual credits to the liable account. Its obligation clears when that account transfers them into the refund reserve.</p></div>
        {maximum > 0 && !requests.some(request => request.status === 'pending') && <form onSubmit={submit} className="space-y-4 rounded-xl bg-rose-50/50 p-4" aria-busy={form.processing || undefined}>
            <label className="block text-sm font-semibold">Requested credits (up to {money(maximum)})<input required type="text" inputMode="decimal" pattern="[0-9]+(\.[0-9]{1,2})?" maxLength={12} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className={field} /></label>
            <label className="block text-sm font-semibold">Why these credits are needed<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
            {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
            {Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}
            <button disabled={form.processing} className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing ? 'Sending…' : 'Request demonstration credits'}</button>
        </form>}
        {requests.map(request => <article key={`${request.id}-${request.version}`} className="rounded-xl border border-slate-300 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2"><h4 className="text-sm font-bold">{request.actor_name} · {money(request.amount_cents)}</h4><span className="rounded-md bg-rose-50 px-2 py-1 text-xs font-semibold capitalize text-[#C20836]">{request.status === 'pending' ? 'Awaiting review' : request.status}</span></div>
            <p className="mt-2 break-all text-xs text-slate-500">{request.reference}</p><p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-600">{request.reason}</p>
            {request.review_reason && <p className="mt-3 whitespace-pre-wrap break-words text-sm text-slate-700">Review: {request.review_reason}</p>}
            {request.wallet_reference && <p className="mt-2 break-all text-xs text-slate-600">Grant reference: {request.wallet_reference}</p>}
            {request.can_review && <Review request={request} caseId={caseId} />}
        </article>)}
    </section>;
}
