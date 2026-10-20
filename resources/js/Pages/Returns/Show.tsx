import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import { ArrowLeft, FileImage, RefreshCw } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ReturnStatus, { returnDate, returnLabels } from '@/Components/Returns/ReturnStatus';
import useRequestError from '@/hooks/useCourierRequestError';
import ReturnCustodyPanel, { ReturnCustody } from '@/Components/Returns/ReturnCustodyPanel';
import ReturnMoneyPanel, { ReturnMoney } from '@/Components/Returns/ReturnMoneyPanel';

interface ReturnCase {
    id: number; reference: string; status: string; version: number; reason: string; description: string;
    order: { id: number; order_number: string }; actions: string[]; request_until: string;
    response_until: string; appeal_until: string | null; dropoff_until: string | null;
    items: Array<{ id: number; quantity: number; purchase: { name: string; color: string | null; size: string | null; unit_price: string } }>;
    evidence: Array<{ id: number; url: string; mime: string; bytes: number; purpose?: string }>;
    history: Array<{ reference: string; event_type: string; actor_role: string; reason: string; target_status: string; created_at: string }>;
    custody?: ReturnCustody | null;
    money?: ReturnMoney | null;
}
const actionNames: Record<string, string> = { approve: 'Approve return', reject: 'Reject return', escalate: 'Ask admin to review', withdraw: 'Withdraw request', 'close-period': 'Close expired period' };
const reasons: Record<string, string> = { damaged: 'Item damaged', wrong_item: 'Wrong item', missing_parts: 'Missing parts', not_as_described: 'Not as described' };

export default function ShowReturn({ returnCase: item }: { returnCase: ReturnCase }) {
    const { auth } = usePage<PageProps>().props;
    const form = useForm({ action: '', reason: '', expected_version: item.version, request_token: crypto.randomUUID() });
    const reasonLimit = auth.user?.role === 'seller' && form.data.action === 'reject' ? 300 : 1000;
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    const [refreshing, setRefreshing] = useState(false);
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', reason: '', expected_version: item.version, request_token: crypto.randomUUID() }); form.clearErrors(); }, [item.id, item.version]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !item.actions.includes(form.data.action)) return;
        pending.current = true; setFailure('');
        form.post(`/buyer-returns/${item.id}/actions`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const refresh = () => {
        if (pending.current) return;
        pending.current = true; setFailure(''); setRefreshing(true);
        router.reload({ only: ['returnCase', 'auth', 'flash'], onError: () => setFailure('The return could not refresh. Try again.'), onFinish: () => { pending.current = false; setRefreshing(false); } });
    };
    return <ReturnLayout>
        <Head title={`${item.reference} — BagooPH returns`} />
        <div className="mx-auto max-w-5xl space-y-6 py-4">
            <Link href="/buyer-returns" className="inline-flex items-center gap-2 text-sm font-semibold text-slate-600"><ArrowLeft className="h-4 w-4" />All returns</Link>
            <header className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-300 pb-5"><div className="min-w-0"><h1 className="break-all text-2xl font-bold text-slate-900">{item.reference}</h1><p className="mt-2 text-sm text-slate-600">Purchase {item.order.order_number}</p><div className="mt-3"><ReturnStatus status={item.status} /></div></div><button type="button" onClick={refresh} disabled={form.processing || refreshing} className="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold disabled:opacity-50"><RefreshCw className="h-4 w-4" />{refreshing ? 'Refreshing…' : 'Refresh return'}</button></header>
            {failure && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{failure}</p>}
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
                <div className="space-y-6">
                    {item.custody && <ReturnCustodyPanel custody={item.custody} caseId={item.id} version={item.version} />}
                    {item.money && <ReturnMoneyPanel value={item.money} caseId={item.id} version={item.version} />}
                    <section className="rounded-2xl border border-slate-300 bg-white p-5 sm:p-6"><h2 className="text-lg font-bold text-slate-900">Purchased items</h2><ul className="mt-4 divide-y divide-slate-200">{item.items.map(line => <li key={line.id} className="py-3 first:pt-0"><p className="break-words text-sm font-bold text-slate-900">{line.purchase.name}</p><p className="mt-1 text-xs text-slate-600">{[line.purchase.color, line.purchase.size].filter(Boolean).join(' · ')} · Returning {line.quantity}</p></li>)}</ul><div className="mt-5 border-t border-slate-300 pt-4"><h3 className="font-semibold text-slate-900">{reasons[item.reason] ?? 'Item problem'}</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{item.description}</p></div></section>
                    <section className="rounded-2xl border border-slate-300 bg-white p-5 sm:p-6"><h2 className="text-lg font-bold text-slate-900">Private photos</h2><p className="mt-2 text-xs leading-5 text-slate-500">Available to the involved owners and authorized reviewers.</p><ul className="mt-4 flex flex-wrap gap-3">{item.evidence.map((photo, index) => <li key={photo.id}><a href={photo.url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2.5 text-sm font-semibold text-[#C20836] hover:bg-rose-50"><FileImage className="h-4 w-4" />{photo.purpose === 'intake' ? 'Hub intake' : photo.purpose === 'seller-receive' ? 'Seller receipt' : 'Request photo'} {index + 1}<span className="text-xs font-normal text-slate-500">{(photo.bytes / 1048576).toFixed(2)} MB</span></a></li>)}</ul></section>
                    <section className="rounded-2xl border border-slate-300 bg-white p-5 sm:p-6"><h2 className="text-lg font-bold text-slate-900">Decisions and updates</h2><ol className="mt-5 ml-2 border-l border-slate-300 pl-6">{item.history.map(event => <li key={event.reference} className="relative pb-6 last:pb-0"><span className="absolute -left-[29px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-[#E00D42] ring-1 ring-slate-300" aria-hidden="true" /><div className="flex flex-wrap justify-between gap-2"><h3 className="text-sm font-bold text-slate-900">{returnLabels[event.target_status] ?? 'Return update'}</h3><p className="text-xs text-slate-500">{returnDate(event.created_at)}</p></div><p className="mt-1 text-xs capitalize text-slate-500">{event.actor_role}</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-700">{event.reason}</p></li>)}</ol></section>
                </div>
                <aside className="space-y-5">
                    <section className="rounded-2xl border border-slate-300 bg-white p-5"><h2 className="font-bold text-slate-900">What happens next?</h2><p className="mt-3 text-sm leading-6 text-slate-600">{item.status === 'requested' ? 'The seller reviews your original items and photos. If the response period passes, you can ask an admin to review.' : item.status === 'rejected' ? 'Read the seller’s exact reason in the updates. You can ask an admin to review before the appeal deadline.' : item.status === 'escalated' ? 'An admin reviews the request and the recorded seller feedback. No repayment is implied while the case is being reviewed.' : item.status === 'approved' ? 'Return transport is approved. Physical hub intake and each handover must be recorded before repayment can be reviewed.' : ['withdrawn', 'expired', 'denied'].includes(item.status) ? 'This request is closed without a repayment. Its original evidence and decisions remain available here.' : item.status === 'repaid' ? 'You confirmed receiving the recorded repayment. The original purchase, delivery and payment evidence remain available.' : item.status === 'closed_no_repayment' ? 'The returned merchandise was fully covered by its original product discounts. No merchandise repayment was due and no outgoing transfer was recorded.' : item.status === 'payment_recorded' ? 'A manual repayment was recorded. Check that you received it, then confirm receipt or report a missing payment below.' : item.status === 'repayment_disputed' ? 'Your non-receipt report is awaiting payment review. The original payment stays retained; no second repayment is authorized.' : item.status === 'repayment_ready' ? 'The original repayment amount is approved. Actual backing and a recorded transfer are required before you can confirm receipt.' : 'Follow the actual parcel and payment records below. Physical receipt and repayment are separate steps.'}</p>
                        <dl className="mt-4 space-y-3 border-t border-slate-200 pt-4 text-xs"><div><dt className="font-semibold text-slate-700">Seller response by</dt><dd className="mt-1 text-slate-600">{returnDate(item.response_until)}</dd></div>{item.appeal_until && <div><dt className="font-semibold text-slate-700">Request admin review by</dt><dd className="mt-1 text-slate-600">{returnDate(item.appeal_until)}</dd></div>}{item.dropoff_until && <div><dt className="font-semibold text-slate-700">Physical drop-off by</dt><dd className="mt-1 text-slate-600">{returnDate(item.dropoff_until)}</dd></div>}</dl>
                    </section>
                    {item.actions.length > 0 && <form onSubmit={submit} className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5" aria-busy={form.processing || undefined}><h2 className="font-bold text-slate-900">Update this return</h2><label className="block text-sm font-semibold text-slate-700">Action<select required disabled={form.processing || refreshing} value={form.data.action} onChange={event => form.setData('action', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]"><option value="">Choose an action</option>{item.actions.map(action => <option key={action} value={action}>{actionNames[action] ?? action}</option>)}</select></label><label className="block text-sm font-semibold text-slate-700">Reason<textarea required minLength={10} maxLength={reasonLimit} rows={4} disabled={form.processing || refreshing} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]" /><span className="mt-1 block text-xs font-normal text-slate-500">10–{reasonLimit.toLocaleString()} characters. Explain the actual decision.</span></label>{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}<button type="submit" disabled={form.processing || refreshing || !form.data.action} className="w-full rounded-xl bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">{form.processing ? 'Saving…' : actionNames[form.data.action] ?? 'Save update'}</button></form>}
                </aside>
            </div>
        </div>
    </ReturnLayout>;
}
