import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import ShipmentAmountSummary, { ShipmentAmounts } from '@/Components/Finance/ShipmentAmounts';
import { PageProps } from '@/types';
import useRequestError from '@/hooks/useCourierRequestError';

interface Source { payment_method: string; attempt_reference: string; return_route_reference: string; seller_receipt_reference: string; original_payment_reference: string | null; attempt_proof_url: string }
interface Preview { review_token: string; input: { reverse_fee: string; cost_reference: string }; amounts: ShipmentAmounts }
const field = 'mt-2 w-full rounded-lg border border-slate-300 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]';

export default function ShipmentReview({ order, source, preview, blocker }: { order: { id: number; order_number: string }; source: Source | null; preview: Preview | null; blocker: string | null }) {
    const actorId = (usePage<PageProps>().props as Partial<PageProps>).auth?.user?.id ?? null;
    const reviewScope = `${actorId ?? ''}:${order.id}`;
    const initial = () => ({ reverse_fee: preview?.input.reverse_fee ?? '', cost_reference: preview?.input.cost_reference ?? '', reason: '', proof: null as File | null, conforming_shipment_confirmed: false, request_token: crypto.randomUUID() });
    const form = useForm(initial());
    const pending = useRef(false);
    const fileInput = useRef<HTMLInputElement>(null);
    const previousScope = useRef(reviewScope);
    const [failure, setFailure] = useState('');
    const [previewing, setPreviewing] = useState(false);
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => {
        if (previousScope.current === reviewScope) return;
        previousScope.current = reviewScope;
        form.setData(initial());
        if (fileInput.current) fileInput.current.value = '';
        setFailure('');
        form.clearErrors();
    }, [reviewScope]);
    const matches = preview && form.data.reverse_fee === preview.input.reverse_fee && form.data.cost_reference.trim() === preview.input.cost_reference;
    const previewAmounts = () => {
        if (pending.current || form.processing || blocker) return;
        form.clearErrors();
        if (!/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?$/.test(form.data.reverse_fee)) { form.setError('reverse_fee', 'Enter the actual amount with at most two decimal places.'); return; }
        if (form.data.cost_reference.trim().length < 3) { form.setError('cost_reference', 'Enter the original cost reference.'); return; }
        pending.current = true; setPreviewing(true); setFailure('');
        form.transform(data => ({ reverse_fee: data.reverse_fee, cost_reference: data.cost_reference.trim() }));
        form.post(`/shipment-finances/orders/${order.id}/preview`, { preserveScroll: true, onFinish: () => { pending.current = false; setPreviewing(false); } });
    };
    const approve = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !matches || blocker) return;
        if (!form.data.proof || form.data.proof.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(form.data.proof.type)) { setFailure('Choose a real JPG, PNG, WebP or PDF up to 5 MB.'); return; }
        pending.current = true; setFailure('');
        form.transform(data => ({ ...data, reason: data.reason.trim(), cost_reference: data.cost_reference.trim(), review_token: preview.review_token }));
        form.post(`/shipment-finances/orders/${order.id}/approve`, { preserveScroll: true, forceFormData: true, onFinish: () => { pending.current = false; } });
    };
    return <ReturnLayout title="Shipment finances" subtitle={order.order_number}><Head title="Refusal financial review" /><main className="mx-auto max-w-4xl space-y-6 py-4">
        <Link href="/return-liabilities?view=shipments" className="inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white"><ArrowLeft className="h-4 w-4" />Undelivered parcels</Link>
        <header><h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900"><ClipboardCheck className="h-6 w-6 text-[#E00D42]" />Refusal financial review</h1><p className="mt-2 text-sm text-slate-600">Purchase {order.order_number} · Approving amounts moves no money.</p>{source && <span className="mt-3 inline-block rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-[#C20836]">{source.payment_method === 'wallet' ? 'Demonstration Wallet credits' : 'Uncollected COD'}</span>}</header>
        {blocker && <p role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{blocker}</p>}
        {source && <section className="rounded-xl border border-slate-300 bg-white p-5 shadow-sm"><h2 className="text-sm font-bold">Original return evidence</h2><dl className="mt-4 grid gap-4 sm:grid-cols-2">{[['Refused attempt', source.attempt_reference], ['Reverse route', source.return_route_reference], ['Seller receipt', source.seller_receipt_reference], ['Original payment', source.original_payment_reference ?? 'COD not collected']].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 break-all text-sm font-semibold">{value}</dd></div>)}</dl><a href={source.attempt_proof_url} className="mt-4 inline-flex rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white">Download original refusal proof</a></section>}
        {!blocker && source && <form onSubmit={approve} className="space-y-5 rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-6" aria-busy={form.processing || undefined}>
            <div className="grid gap-4 sm:grid-cols-2"><label className="text-sm font-semibold">Actual reverse shipping (PHP)<input required inputMode="decimal" pattern="(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?" maxLength={12} value={form.data.reverse_fee} onChange={event => form.setData('reverse_fee', event.target.value)} className={field} /></label><label className="text-sm font-semibold">Original cost reference<input required minLength={3} maxLength={120} value={form.data.cost_reference} onChange={event => form.setData('cost_reference', event.target.value)} className={field} /></label></div>
            <button type="button" disabled={form.processing} onClick={previewAmounts} className="rounded-lg border border-rose-200 bg-rose-50/85 px-4 py-3 text-sm font-bold text-[#C20836] hover:bg-[#E00D42] hover:text-white disabled:opacity-50">{previewing ? 'Checking…' : 'Preview amounts'}</button>
            {preview && <div className="space-y-3 border-t border-slate-300 pt-5"><ShipmentAmountSummary value={preview.amounts} preview />{!matches && <p role="status" className="text-sm text-amber-800">Amounts changed. Preview again before approving.</p>}</div>}
            <label className="block text-sm font-semibold">Review reason<textarea required minLength={10} maxLength={1000} rows={3} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
            <label className="block text-sm font-semibold">Private conformity and cost evidence<input ref={fileInput} required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className={field} /><span className="mt-1 block text-xs font-normal text-slate-500">JPG, PNG, WebP or PDF · up to 5 MB.</span></label>
            <label className="flex items-start gap-2 text-sm leading-6"><input required type="checkbox" checked={form.data.conforming_shipment_confirmed} onChange={event => form.setData('conforming_shipment_confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />I independently reviewed the conforming parcel, actual refusal, complete seller receipt and evidenced reverse charge.</label>
            {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}{Object.entries(form.errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-rose-700">{String(message)}</p>)}
            <button disabled={form.processing || !matches} className="rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white disabled:opacity-50">{form.processing && !previewing ? 'Saving…' : 'Approve reviewed amounts'}</button>
        </form>}
    </main></ReturnLayout>;
}
