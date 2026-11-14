import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ClipboardCheck, FileText, ShieldCheck } from 'lucide-react';
import ReturnLayout from '@/Components/Returns/ReturnLayout';
import { PageProps } from '@/types';
import { financeMoney } from '@/utils/finance';
import useRequestError from '@/hooks/useCourierRequestError';

export interface LossReviewSource {
    observation_id: number;
    observation_reference: string;
    manifest_reference: string;
    company_name: string;
    barcode: string;
    direction: string;
    scope: 'undelivered' | 'buyer_return';
}

export interface LossReviewBasis {
    original_merchandise_cents: number;
    buyer_paid_shipping_cents: number;
    actual_buyer_paid_cents: number;
    buyer_refund_cents: number;
    seller_protection_cap_cents: number;
    protected_earned_shipping_cents: number;
}

export interface LossReviewPreview {
    input: { seller_protection: string; investigation_reference: string };
    amounts: Record<string, number>;
    review_token: string;
}

export interface LossReviewProps {
    order: { id: number; order_number: string };
    source: LossReviewSource | null;
    basis: LossReviewBasis | null;
    preview?: LossReviewPreview | null;
    blocker: string | null;
    requestToken: string;
}

interface ReviewForm {
    seller_protection: string;
    investigation_reference: string;
    reason: string;
    proof: File | null;
    loss_confirmed: boolean;
    request_token: string;
}

const decimal = /^(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/;
const previewKeys = ['buyer_refund_cents', 'seller_protection_cents', 'protected_earned_shipping_cents',
    'company_funding_due_cents', 'original_escrow_required_cents', 'reserve_goal_cents'];
const inputClass = 'mt-2 w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
const secondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-4 py-2.5 text-sm font-bold text-[#C20836] transition-colors hover:bg-[#E00D42] hover:text-white disabled:cursor-not-allowed disabled:opacity-50';
const directions: Record<string, string> = { outbound: 'Forward shipment', return: 'Return to seller', buyer_return: 'Buyer item return', recovery: 'Authorized recovery' };

const cents = (value: string) => {
    if (!decimal.test(value)) return null;
    const [whole, fraction = ''] = value.split('.');
    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
};
const validProof = (proof: File | null) => proof && proof.size > 0 && proof.size <= 5242880
    && ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(proof.type);

function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? <p id={id} role="alert" className="mt-2 text-xs font-medium text-rose-700">{message}</p> : null;
}

function AmountCard({ label, amount, primary = false, zeroLabel }: { label: string; amount: number; primary?: boolean; zeroLabel?: string }) {
    return <div className={`rounded-lg border p-4 ${primary ? 'border-rose-200 bg-rose-50/70' : 'border-slate-300 bg-white'}`}>
        <dt className="text-xs font-medium text-slate-600">{label}</dt>
        <dd className={`mt-2 break-words text-xl font-bold ${primary ? 'text-[#C20836]' : 'text-slate-900'}`}>{financeMoney(amount)}</dd>
        {amount === 0 && zeroLabel && <p className="mt-1 text-xs font-semibold text-[#9F1239]">{zeroLabel}</p>}
    </div>;
}

export default function LossReview({ order, source, basis, preview = null, blocker, requestToken }: LossReviewProps) {
    const initial = (): ReviewForm => ({ seller_protection: preview?.input.seller_protection ?? '0.00',
        investigation_reference: preview?.input.investigation_reference ?? '', reason: '', proof: null,
        loss_confirmed: false, request_token: requestToken });
    const form = useForm<ReviewForm>(initial());
    const pending = useRef(false);
    const fileInput = useRef<HTMLInputElement>(null);
    const actorId = (usePage<PageProps>().props as Partial<PageProps>).auth?.user?.id ?? null;
    const sourceScope = `${actorId ?? ''}:${source?.observation_id ?? ''}`;
    const previousSource = useRef({ scope: sourceScope, actorId, requestToken });
    const [failure, setFailure] = useState('');
    const [phase, setPhase] = useState<'preview' | 'approve' | null>(null);
    useRequestError(pending, useCallback(message => setFailure(message), []));

    const clearSensitive = () => {
        form.setData({ ...form.data, reason: '', proof: null, loss_confirmed: false });
        if (fileInput.current) fileInput.current.value = '';
    };
    useEffect(() => {
        if (previousSource.current.scope === sourceScope) return;
        const actorChanged = previousSource.current.actorId !== actorId;
        const nextToken = actorChanged && requestToken === previousSource.current.requestToken ? crypto.randomUUID() : requestToken;
        previousSource.current = { scope: sourceScope, actorId, requestToken };
        form.setData({ ...initial(), request_token: nextToken });
        if (fileInput.current) fileInput.current.value = '';
        setFailure(current => (current === '' ? current : ''));
        form.clearErrors();
    }, [sourceScope]);

    const validAmounts = !!preview && /^[a-f0-9]{64}$/.test(preview.review_token)
        && previewKeys.every(key => Number.isSafeInteger(preview.amounts[key]) && preview.amounts[key] >= 0);
    const matches = !!preview && form.data.seller_protection === preview.input.seller_protection
        && form.data.investigation_reference.trim() === preview.input.investigation_reference;
    const unavailable = blocker || (!source || !basis ? 'The original missing-parcel evidence is unavailable. Reload its review.' : null);
    const busy = form.processing || phase !== null;
    const changeDetails = (key: 'seller_protection' | 'investigation_reference', value: string) => {
        form.setData({ ...form.data, [key]: value, reason: '', proof: null, loss_confirmed: false });
        if (fileInput.current) fileInput.current.value = '';
        setFailure('');
    };
    const finish = () => { pending.current = false; setPhase(null); };
    const previewAmounts = () => {
        if (pending.current || form.processing || unavailable || !source || !basis) return;
        form.clearErrors();
        const protection = cents(form.data.seller_protection);
        if (protection === null || protection < 0 || protection > basis.seller_protection_cap_cents) {
            form.setError('seller_protection', `Enter an amount from ${financeMoney(0)} to ${financeMoney(basis.seller_protection_cap_cents)}, with at most two decimal places.`);
            return;
        }
        const reference = form.data.investigation_reference.trim();
        if (reference.length < 3 || reference.length > 120) {
            form.setError('investigation_reference', 'Enter the original investigation reference, using 3–120 characters.');
            return;
        }
        setFailure(''); pending.current = true; setPhase('preview');
        form.transform(data => ({ seller_protection: data.seller_protection, investigation_reference: data.investigation_reference.trim() }));
        form.post(`/shipment-finances/missing/${source.observation_id}/preview`, { preserveScroll: true, onSuccess: clearSensitive, onFinish: finish });
    };
    const approve = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || unavailable || !source || !preview || !matches || !validAmounts) return;
        form.clearErrors();
        let invalid = false;
        if (form.data.reason.trim().length < 10 || form.data.reason.trim().length > 1000) {
            form.setError('reason', 'Explain the independent loss and liability finding in 10–1,000 characters.'); invalid = true;
        }
        if (!validProof(form.data.proof)) {
            form.setError('proof', 'Choose a real JPG, PNG, WebP or PDF up to 5 MB.'); invalid = true;
        }
        if (!form.data.loss_confirmed) {
            form.setError('loss_confirmed', 'Confirm your independent review of the actual loss and responsible company.'); invalid = true;
        }
        if (invalid) return;
        setFailure(''); pending.current = true; setPhase('approve');
        form.transform(data => ({ seller_protection: data.seller_protection, investigation_reference: data.investigation_reference.trim(),
            review_token: preview.review_token, request_token: data.request_token, reason: data.reason.trim(), proof: data.proof, loss_confirmed: data.loss_confirmed }));
        form.post(`/shipment-finances/missing/${source.observation_id}/approve`, { preserveScroll: true, forceFormData: true, onSuccess: clearSensitive, onFinish: finish });
    };
    const otherErrors = Object.entries(form.errors).filter(([key]) => !['seller_protection', 'investigation_reference', 'reason', 'proof', 'loss_confirmed'].includes(key));

    return <ReturnLayout title="Shipment finances" subtitle={order.order_number}>
        <Head title="Parcel loss review" />
        <main className="mx-auto max-w-4xl space-y-6 py-4">
            <Link href="/return-liabilities?view=losses" className={secondary}><ArrowLeft className="h-4 w-4" />Parcel losses</Link>
            <header className="space-y-3">
                <div className="flex flex-wrap items-center gap-3"><h1 className="flex items-center gap-2 text-2xl font-bold text-slate-900"><ClipboardCheck className="h-6 w-6 text-[#E00D42]" />Review parcel loss</h1>
                    {source && <span className="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-[#C20836]">{source.scope === 'buyer_return' ? 'Buyer return parcel' : 'Undelivered parcel'}</span>}</div>
                <p className="text-sm text-slate-600">{order.order_number} · Review the original loss and separate compensation. Approval moves no money.</p>
            </header>
            {unavailable && <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{unavailable}</p>}
            {source && <section aria-labelledby="loss-source-title" className="rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6">
                <h2 id="loss-source-title" className="flex items-center gap-2 text-sm font-bold text-slate-900"><FileText className="h-4 w-4 text-[#E00D42]" />Original missing parcel</h2>
                <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">{[
                    ['Responsible logistics company', source.company_name], ['Missing observation', source.observation_reference], ['Original manifest', source.manifest_reference],
                    ['Parcel barcode', source.barcode], ['Transport', directions[source.direction] ?? source.direction],
                ].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 break-all font-semibold text-slate-900">{value}</dd></div>)}</dl>
            </section>}
            {basis && <section aria-labelledby="original-money-title" className="space-y-4">
                <h2 id="original-money-title" className="text-sm font-bold text-slate-900">Original payment and limits</h2>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><AmountCard label="Actual buyer payment" amount={basis.actual_buyer_paid_cents} primary zeroLabel="No buyer refund due" />
                    <AmountCard label="Items after discounts" amount={basis.original_merchandise_cents} /><AmountCard label="Paid outbound shipping" amount={basis.buyer_paid_shipping_cents} />
                    <AmountCard label="Maximum seller protection" amount={basis.seller_protection_cap_cents} /></dl>
            </section>}
            {!unavailable && source && basis && <form onSubmit={approve} aria-label="Review original parcel loss" aria-busy={busy || undefined} className="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                <section className="space-y-5 p-5 sm:p-6" aria-labelledby="loss-details-title">
                    <div className="flex items-center gap-3"><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-sm font-bold text-[#C20836]">1</span><h2 id="loss-details-title" className="font-bold text-slate-900">Review the separate amounts</h2></div>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div><label htmlFor="seller-protection" className="text-sm font-semibold text-slate-900">Seller protection (PHP)</label>
                            <input id="seller-protection" required inputMode="decimal" autoComplete="off" pattern="(0|[1-9][0-9]{0,8})(\.[0-9]{1,2})?" maxLength={12} value={form.data.seller_protection}
                                onChange={event => changeDetails('seller_protection', event.target.value)} disabled={busy} aria-describedby="protection-limit protection-error" aria-invalid={!!form.errors.seller_protection} className={inputClass} />
                            <p id="protection-limit" className="mt-2 text-xs text-slate-600">{financeMoney(0)}–{financeMoney(basis.seller_protection_cap_cents)} · Separate from the buyer refund.</p>
                            <FieldError id="protection-error" message={form.errors.seller_protection} /></div>
                        <div><label htmlFor="investigation-reference" className="text-sm font-semibold text-slate-900">Investigation reference</label>
                            <input id="investigation-reference" required minLength={3} maxLength={120} autoComplete="off" value={form.data.investigation_reference}
                                onChange={event => changeDetails('investigation_reference', event.target.value)} disabled={busy} aria-describedby="investigation-error" aria-invalid={!!form.errors.investigation_reference} className={inputClass} />
                            <FieldError id="investigation-error" message={form.errors.investigation_reference} /></div>
                    </div>
                    <button type="button" onClick={previewAmounts} disabled={busy} className={secondary}>{phase === 'preview' ? 'Checking amounts…' : 'Preview loss amounts'}</button>
                </section>
                {preview && <section aria-label="Proposed loss amounts" className="space-y-4 border-t border-slate-300 bg-slate-50/70 p-5 sm:p-6">
                    <dl className="grid gap-3 sm:grid-cols-3"><AmountCard label="Proposed buyer refund" amount={preview.amounts.buyer_refund_cents} primary zeroLabel="No buyer refund due" />
                        <AmountCard label="Separate seller protection" amount={preview.amounts.seller_protection_cents} /><AmountCard label="Actual earned shipping retained" amount={preview.amounts.protected_earned_shipping_cents} /></dl>
                    <dl className="space-y-2 rounded-lg border border-slate-300 bg-white p-4 text-sm">{[
                        ['Original buyer funds', preview.amounts.original_escrow_required_cents], ['Company funding required', preview.amounts.company_funding_due_cents], ['Total funding required', preview.amounts.reserve_goal_cents],
                    ].map(([label, value]) => <div key={String(label)} className="flex flex-wrap items-center justify-between gap-2"><dt className="text-slate-600">{label}</dt><dd className="font-bold text-slate-900">{financeMoney(value)}</dd></div>)}</dl>
                    {!matches && <p role="status" className="text-sm font-semibold text-amber-800">Details changed. Preview again before approval.</p>}
                    {!validAmounts && <p role="alert" className="text-sm font-semibold text-rose-700">The preview is incomplete. Check the review again before approval.</p>}
                </section>}
                {preview && matches && validAmounts && <section aria-labelledby="loss-confirmation-title" className="space-y-5 border-t border-slate-300 p-5 sm:p-6">
                    <div className="flex items-center gap-3"><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-sm font-bold text-[#C20836]">2</span><h2 id="loss-confirmation-title" className="font-bold text-slate-900">Record the independent finding</h2></div>
                    <div><div className="flex items-center justify-between gap-2"><label htmlFor="loss-reason" className="text-sm font-semibold text-slate-900">Review reason</label><span className="text-xs text-slate-500">{form.data.reason.length}/1,000</span></div>
                        <textarea id="loss-reason" required minLength={10} maxLength={1000} rows={4} autoComplete="off" value={form.data.reason} onChange={event => form.setData('reason', event.target.value)}
                            disabled={busy} aria-describedby="loss-reason-error" aria-invalid={!!form.errors.reason} className={inputClass} /><FieldError id="loss-reason-error" message={form.errors.reason} /></div>
                    <div><label htmlFor="loss-proof" className="flex items-center gap-2 text-sm font-semibold text-slate-900"><ShieldCheck className="h-4 w-4 text-[#E00D42]" />Private investigation evidence</label>
                        <input ref={fileInput} id="loss-proof" required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" disabled={busy}
                            onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} aria-describedby="loss-proof-help loss-proof-error" aria-invalid={!!form.errors.proof}
                            className={`${inputClass} p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-[#C20836]`} />
                        <p id="loss-proof-help" className="mt-2 text-xs text-slate-600">JPG, PNG, WebP or PDF · up to 5 MB.</p><FieldError id="loss-proof-error" message={form.errors.proof} /></div>
                    <div><label className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50/50 p-4 text-sm leading-6 text-slate-800">
                        <input required type="checkbox" checked={form.data.loss_confirmed} onChange={event => form.setData('loss_confirmed', event.target.checked)} disabled={busy}
                            aria-describedby="loss-consent-error" className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
                        I independently verified the original parcel loss, responsible company and separate buyer refund and seller protection.</label><FieldError id="loss-consent-error" message={form.errors.loss_confirmed} /></div>
                </section>}
                <footer className="space-y-4 border-t border-slate-300 p-5 sm:p-6">
                    {failure && <p role="alert" className="text-sm font-medium text-rose-700">{failure}</p>}
                    {otherErrors.map(([key, message]) => <p key={key} role="alert" className="text-sm font-medium text-rose-700">{String(message)}</p>)}
                    <button type="submit" disabled={busy || !matches || !validAmounts} className="w-full rounded-lg bg-[#E00D42] px-5 py-3 text-sm font-bold text-white transition-colors hover:bg-[#C20836] disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto">{phase === 'approve' ? 'Saving reviewed loss…' : 'Approve reviewed loss'}</button>
                </footer>
            </form>}
        </main>
    </ReturnLayout>;
}
