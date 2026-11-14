import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { FileText, Wallet } from 'lucide-react';
import { financeMoney } from '@/utils/finance';
import useRequestError from '@/hooks/useCourierRequestError';
import { PageProps } from '@/types';

export interface LossAction {
    id: string; label: string; confirmation: string; proof: boolean; amount: boolean; receiver: boolean; source: boolean;
    cash_reference: boolean; outcome_path?: boolean; maximum_cents: number | null; sources: { id: number; label: string; amount_cents: number }[];
}
export interface ReconciliationRecord {
    id: number; reference: string; outcome_path: string; outcome_label: string;
    status: 'approved' | 'invalidated' | 'released'; stale: boolean;
    reason: string; proof_url?: string; invalidation_reason: string | null;
    invalidated_at: string | null; released_at: string | null; created_at: string;
}
export interface ReconciliationAssessment {
    eligible_paths: string[];
    blocked_reasons: Record<string, string>;
    stale: boolean;
    reconciliation: ReconciliationRecord | null;
}
interface CreditRequest {
    id: number; reference: string; status: 'pending' | 'approved' | 'rejected'; version: number; amount_cents: number;
    reason: string; review_reason: string | null; can_review: boolean; can_approve: boolean; wallet_reference: string | null;
}
export interface LossControlState {
    version: number; actions: LossAction[]; receivers: { id: number; name: string }[]; hold_message: string | null;
    funding: Record<string, number> | null; disposition: { reference: string; proof_url: string } | null;
    finding?: { reference: string; proof_url: string; intake_url?: string } | null;
    reconciliation?: ReconciliationAssessment | null;
    accounting?: { reference: string; verified: boolean } | null;
    credits: { maximum_cents: number; wallet_balance_cents: number | null; available_for_authority_cents?: number | null; requests: CreditRequest[] } | null;
}
const outcomePaths: Record<string, string> = {
    unpaid_undelivered: 'Restore unused funds & resume forward delivery',
    post_delivery_partial_return: 'Return/restore compensation & resume buyer return transit',
    already_settled_salvage: 'Keep settled compensation & move found parcel to salvage custody',
    already_settled_seller_return: 'Return settled protection & return merchandise to seller',
};
const field = 'mt-2 w-full rounded-lg border border-slate-300 bg-white text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
const primary = 'rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white transition-colors hover:bg-[#C20836] disabled:cursor-not-allowed disabled:opacity-50';
const secondary = 'inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-sm font-semibold text-[#C20836] transition-colors hover:bg-[#E00D42] hover:text-white disabled:opacity-50';
const reasonValid = (value: string) => value.trim().length >= 10 && value.trim().length <= 1000;
const decimal = /^(0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/;
function cents(value: string) {
    if (!decimal.test(value)) return null;
    const [whole, part = ''] = value.split('.');
    return Number(whole) * 100 + Number(part.padEnd(2, '0'));
}
const validProof = (file: File | null) => !!file && file.size > 0 && file.size <= 5242880
    && ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type);
function Errors({ errors, failure }: { errors: Record<string, string>; failure: string }) {
    return <>{failure && <p role="alert" className="text-sm font-medium text-rose-700">{failure}</p>}{Object.entries(errors).map(([key, value]) => <p key={key} role="alert" className="text-sm text-rose-700">{value}</p>)}</>;
}
function savedVersion(page: unknown, planId: number): number | null {
    const loss = (page as { props?: { loss?: { id?: number; controls?: { version?: number } } } })?.props?.loss;
    const version = loss?.controls?.version;
    return loss?.id === planId && Number.isSafeInteger(version) && Number(version) > 0 ? Number(version) : null;
}

function ActionForm({ planId, value }: { planId: number; value: LossControlState }) {
    const actorId = (usePage<PageProps>().props as Partial<PageProps>).auth?.user?.id ?? null;
    const initial = (version = value.version) => ({ action: '', expected_version: version, request_token: crypto.randomUUID(), reason: '',
        amount: '', recipient_id: '', source_event_id: '', cash_reference: '', outcome_path: '', proof: null as File | null, confirmed: false });
    const form = useForm(initial());
    const pending = useRef(false);
    const file = useRef<HTMLInputElement>(null);
    const previous = useRef(`${planId}:${actorId ?? ''}`);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const reset = (version = value.version) => { form.setData(initial(version)); form.clearErrors(); setFailure(''); if (file.current) file.current.value = ''; };
    useEffect(() => {
        const current = `${planId}:${actorId ?? ''}`;
        if (previous.current !== current) { previous.current = current; reset(); }
    }, [planId, actorId]);
    const spec = value.actions.find(action => action.id === form.data.action);
    const selectablePaths = value.reconciliation ? value.reconciliation.eligible_paths : Object.keys(outcomePaths);
    const source = spec?.sources.find(item => String(item.id) === form.data.source_event_id);
    const sourceLabel = spec?.id === 'return-unused-credit' ? 'Original approved grant'
        : spec?.id === 'return-company-source' ? 'Original company contribution'
            : spec?.id === 'return-platform-source' ? 'Original platform reserve deposit'
                : ['confirm-company-cash-return', 'report-company-cash-nonreceipt', 'resolve-company-cash-nonreceipt'].includes(spec?.id ?? '') ? 'Original company cash reimbursement'
                    : 'Original source';
    const sourcePlaceholder = spec?.id === 'return-unused-credit' ? 'Select the original grant approval'
        : spec?.id === 'return-company-source' ? 'Select the original reserve contribution'
            : spec?.id === 'return-platform-source' ? 'Select the original escrow or advance deposit'
                : ['confirm-company-cash-return', 'report-company-cash-nonreceipt', 'resolve-company-cash-nonreceipt'].includes(spec?.id ?? '') ? 'Select the recorded company reimbursement'
                    : 'Select the original recorded transfer';
    const confirmationCopy = spec?.id === 'return-unused-credit'
        ? 'I authorize returning only these unused reviewed credits from my company Wallet to their original demo issuance.'
        : spec?.id === 'return-company-source'
            ? 'I confirm restoring this unused contribution from its original reserve to my company Wallet, keeping reviewed credits restricted to their original grant.'
            : spec?.id === 'return-platform-source'
                ? 'I independently verified this found-parcel hold and authorize returning only this unused platform source to its original escrow or demo issuance without a buyer repayment or parcel release.'
                : spec?.id === 'reconcile-found'
                    ? 'I independently verified the physical quarantine finding and financial balances, and authorize this reconciliation outcome without releasing custody until hub verification.'
                    : spec?.id === 'invalidate-reconciliation'
                        ? 'I confirm invalidating this un-released reconciliation so the parcel remains quarantined until a new independent reconciliation is approved.'
                        : spec?.id === 'return-buyer-compensation'
                            ? 'I voluntarily authorize returning my Wallet refund compensation while keeping the parcel in quarantine review.'
                            : spec?.id === 'return-seller-compensation'
                                ? 'I voluntarily authorize returning my Wallet seller protection proceeds while keeping the parcel in quarantine review.'
                                : spec?.id === 'restore-company-compensation'
                                    ? 'I independently verified returned compensation and authorize reimbursing the original company Wallet funding after any platform advances are retired.'
                                    : spec?.id === 'restore-escrow-compensation'
                                        ? 'I independently verified surplus returned compensation after company reimbursement and authorize restoring the remaining original escrow balance.'
                                        : 'I confirm the selected update, original participant and actual supporting evidence.';
    const busy = pending.current || form.processing;
    const submit = (event: FormEvent) => {
        event.preventDefault();
        const spec = value.actions.find(action => action.id === form.data.action);
        if (pending.current || form.processing || !spec) return;
        const source = spec.sources.find(item => String(item.id) === form.data.source_event_id);
        form.clearErrors(); let invalid = false;
        const reject = (key: keyof typeof form.data, message: string) => { form.setError(key, message); invalid = true; };
        if (!reasonValid(form.data.reason)) reject('reason', 'Enter 10–1,000 characters about this original update.');
        if (!form.data.confirmed) reject('confirmed', 'Confirm the selected update and its original evidence.');
        if (spec.proof && !validProof(form.data.proof)) reject('proof', 'Choose a real JPG, PNG, WebP or PDF up to 5 MB.');
        if (spec.receiver && !value.receivers.some(person => String(person.id) === form.data.recipient_id)) reject('recipient_id', 'Select the distinct admin who actually received this transfer.');
        if (spec.source && !source) reject('source_event_id', 'Select this update’s original source.');
        if (spec.outcome_path && (!form.data.outcome_path || !selectablePaths.includes(form.data.outcome_path))) reject('outcome_path', 'Select an eligible reconciliation outcome.');
        if (spec.amount) {
            const amount = cents(form.data.amount);
            const maximum = spec.id === 'retire-advance' && source ? Math.min(source.amount_cents, spec.maximum_cents ?? 0) : spec.maximum_cents;
            if (amount === null || amount <= 0 || !Number.isSafeInteger(maximum) || amount > Number(maximum)) reject('amount', 'Enter a positive amount within the displayed original limit.');
        }
        if (spec.cash_reference && !/^[A-Za-z0-9][A-Za-z0-9 ._:/-]{2,119}$/.test(form.data.cash_reference.trim())) reject('cash_reference', 'Use 3–120 letters, numbers, spaces or . _ : / - for the actual transfer reference.');
        if (invalid) return;
        pending.current = true; setFailure('');
        form.transform(data => ({ action: spec.id, expected_version: data.expected_version, request_token: data.request_token,
            reason: data.reason.trim(), [spec.confirmation]: data.confirmed,
            ...(spec.amount ? { amount: data.amount } : {}), ...(spec.receiver ? { recipient_id: data.recipient_id } : {}),
            ...(spec.source ? { source_event_id: data.source_event_id } : {}), ...(spec.cash_reference ? { cash_reference: data.cash_reference.trim() } : {}),
            ...(spec.outcome_path ? { outcome_path: data.outcome_path } : {}),
            ...(spec.proof ? { proof: data.proof } : {}) }));
        form.post(`/shipment-finances/${planId}/actions`, { preserveScroll: true, forceFormData: spec.proof,
            onSuccess: page => { const version = savedVersion(page, planId); if (version !== null) reset(version); },
            onFinish: () => { pending.current = false; } });
    };
    if (!value.actions.length && !form.data.action) return null;
    return <form onSubmit={submit} aria-label="Update original loss finances" aria-busy={form.processing || undefined} className="space-y-5 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-base font-bold text-slate-900">Record a financial update</h2>
        <label className="block text-sm font-semibold text-slate-900">Action<select required disabled={busy} value={form.data.action} onChange={event => {
            const next = value.actions.find(item => item.id === event.target.value); reset();
            const paths = value.reconciliation ? value.reconciliation.eligible_paths : Object.keys(outcomePaths);
            form.setData({ ...initial(), action: next?.id ?? '', source_event_id: next?.sources.length === 1 ? String(next.sources[0].id) : '',
                outcome_path: next?.outcome_path && paths.length === 1 ? paths[0] : '' });
        }} className={field}><option value="">Choose an available action</option>{value.actions.map(action => <option key={action.id} value={action.id}>{action.label}</option>)}</select></label>
        {spec && <>
            {spec.outcome_path && <fieldset disabled={busy} className="space-y-2 rounded-lg border border-slate-200 p-4">
                <legend className="px-1 text-sm font-semibold text-slate-900">Eligible reconciliation outcome</legend>
                {selectablePaths.map(path => <label key={path} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm ${form.data.outcome_path === path ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200'}`}>
                    <input type="radio" required name="reconciliation-outcome-path" checked={form.data.outcome_path === path} onChange={() => form.setData('outcome_path', path)} className="mt-1 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />
                    <span><strong className="block text-slate-900">{outcomePaths[path] ?? path}</strong><span className="text-xs text-slate-600">{path}</span></span>
                </label>)}
            </fieldset>}
            {spec.amount && <label className="block text-sm font-semibold text-slate-900">Amount (PHP)<input required inputMode="decimal" maxLength={12} autoComplete="off" disabled={busy} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className={field} /><span className="mt-2 block text-xs font-normal text-slate-600">Maximum {financeMoney(spec.id === 'retire-advance' && source ? Math.min(source.amount_cents, spec.maximum_cents ?? 0) : spec.maximum_cents ?? 0)}</span></label>}
            {spec.receiver && <label className="block text-sm font-semibold text-slate-900">Actual receiving admin<select required disabled={busy} value={form.data.recipient_id} onChange={event => form.setData('recipient_id', event.target.value)} className={field}><option value="">Select the distinct receiver</option>{value.receivers.map(person => <option key={person.id} value={person.id}>{person.name}</option>)}</select></label>}
            {spec.source && <label className="block text-sm font-semibold text-slate-900">{sourceLabel}<select required disabled={busy} value={form.data.source_event_id} onChange={event => form.setData('source_event_id', event.target.value)} className={field}><option value="">{sourcePlaceholder}</option>{spec.sources.map(item => <option key={item.id} value={item.id}>{item.label} · {financeMoney(item.amount_cents)}</option>)}</select></label>}
            {spec.cash_reference && <label className="block text-sm font-semibold text-slate-900">Actual transfer reference<input required minLength={3} maxLength={120} autoComplete="off" disabled={busy} value={form.data.cash_reference} onChange={event => form.setData('cash_reference', event.target.value)} className={field} /></label>}
            {spec.proof && <label className="block text-sm font-semibold text-slate-900">Private evidence<input ref={file} required type="file" accept="image/jpeg,image/png,image/webp,application/pdf" disabled={busy} onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} className={`${field} p-2 file:mr-3 file:rounded-lg file:border-0 file:bg-rose-50 file:px-3 file:py-2 file:text-[#C20836]`} /><span className="mt-2 block text-xs font-normal text-slate-600">JPG, PNG, WebP or PDF · up to 5 MB.</span></label>}
            <label className="block text-sm font-semibold text-slate-900">Reason<textarea required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={busy} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
            {spec.id === 'record-disposition' && <p className="text-sm text-slate-600">This records independent loss evidence. It does not confirm a parcel, vehicle or sack arrival.</p>}
            {spec.id === 'return-unused-credit' && <p className="text-sm text-slate-600">Return {source ? financeMoney(source.amount_cents) : 'the selected grant’s unused credits'} to the original demo issuance. Company debt, paid compensation and the parcel hold remain separate.</p>}
            {spec.id === 'return-company-source' && <p className="text-sm text-slate-600">Restore {source ? financeMoney(source.amount_cents) : 'the selected unused contribution'} to your company Wallet. Reviewed credits retain their original restrictions. This does not clear liability or release the parcel.</p>}
            {spec.id === 'return-platform-source' && <p className="text-sm text-slate-600">Return {source ? financeMoney(source.amount_cents) : 'the selected unused deposit'} to its original escrow or demo issuance account. Owned company contributions, debt, paid compensation and the quarantine hold remain separate.</p>}
            {spec.id === 'reconcile-found' && <p className="text-sm text-slate-600">Approving reconciliation selects an eligible physical path while keeping the parcel quarantined until its holding hub completes barcode-verified release.</p>}
            {spec.id === 'account-loss' && <p className="text-sm text-slate-600">The retained independent disposition supports this missing parcel. Accounting moves no money, receives no parcel and confirms no vehicle or sack arrival.</p>}
            <label className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50/50 p-4 text-sm leading-6 text-slate-800"><input required type="checkbox" disabled={busy} checked={form.data.confirmed} onChange={event => form.setData('confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /> {confirmationCopy}</label>
        </>}
        {form.data.action && !spec && <p role="alert" className="text-sm text-amber-900">This action is no longer available. Check the saved history before starting a new update.</p>}
        <Errors errors={form.errors} failure={failure} />
        <div className="flex flex-wrap gap-3"><button disabled={busy || !spec} className={primary}>{form.processing ? 'Saving…' : spec?.label ?? 'Save update'}</button><button type="button" disabled={busy} onClick={() => reset()} className={secondary}>Start new update</button></div>
    </form>;
}

function CreditReview({ planId, request }: { planId: number; request: CreditRequest }) {
    const form = useForm({ decision: '', reason: '', confirmed: false, version: request.version, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || !request.can_review) return;
        form.clearErrors();
        if (!reasonValid(form.data.reason)) { form.setError('reason', 'Enter a review reason using 10–1,000 characters.'); return; }
        if (!['approved', 'rejected'].includes(form.data.decision) || form.data.decision === 'approved' && (!request.can_approve || !form.data.confirmed)) { form.setError('decision', 'Choose an available decision and confirm any grant.'); return; }
        pending.current = true; setFailure('');
        form.transform(data => ({ decision: data.decision, reason: data.reason.trim(), version: data.version, request_token: data.request_token,
            ...(data.decision === 'approved' ? { demo_credit_confirmed: data.confirmed } : {}) }));
        form.post(`/shipment-finances/${planId}/wallet-funding-credits/${request.id}/review`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    return <form onSubmit={submit} aria-label="Review original company funding credits" className="mt-4 space-y-4" aria-busy={form.processing || undefined}>
        <label className="block text-sm font-semibold">Independent decision<select required disabled={form.processing} value={form.data.decision} onChange={event => form.setData({ ...form.data, decision: event.target.value, confirmed: false })} className={field}><option value="">Choose a decision</option>{request.can_approve && <option value="approved">Approve demonstration credits</option>}<option value="rejected">Decline request</option></select></label>
        <label className="block text-sm font-semibold">Review reason<textarea required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={form.processing} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
        {form.data.decision === 'approved' && <label className="flex items-start gap-3 text-sm leading-6"><input required type="checkbox" disabled={form.processing} checked={form.data.confirmed} onChange={event => form.setData('confirmed', event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42]" />Grant {financeMoney(request.amount_cents)} as virtual credits to the original company owner. Its funding obligation requires a separate transfer.</label>}
        <Errors errors={form.errors} failure={failure} /><button disabled={form.processing} className={primary}>Save independent review</button>
    </form>;
}

function CreditRequestForm({ planId, version, maximum }: { planId: number; version: number; maximum: number }) {
    const initial = () => ({ amount: '', reason: '', expected_version: version, request_token: crypto.randomUUID() });
    const form = useForm(initial());
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || maximum <= 0) return;
        form.clearErrors(); const amount = cents(form.data.amount);
        if (amount === null || amount <= 0 || amount > maximum) { form.setError('amount', 'Enter a positive amount within the original company shortfall.'); return; }
        if (!reasonValid(form.data.reason)) { form.setError('reason', 'Explain the original shortfall using 10–1,000 characters.'); return; }
        pending.current = true; setFailure(''); form.transform(data => ({ ...data, reason: data.reason.trim() }));
        form.post(`/shipment-finances/${planId}/wallet-funding-credits`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    return <form onSubmit={submit} aria-label="Request original company funding credits" className="space-y-4 rounded-lg bg-rose-50/50 p-4" aria-busy={form.processing || undefined}>
        <label className="block text-sm font-semibold">Requested credits (maximum {financeMoney(maximum)})<input required inputMode="decimal" maxLength={12} autoComplete="off" disabled={form.processing} value={form.data.amount} onChange={event => form.setData('amount', event.target.value)} className={field} /></label>
        <label className="block text-sm font-semibold">Why these credits are needed<textarea required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={form.processing} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} className={field} /></label>
        <Errors errors={form.errors} failure={failure} /><div className="flex flex-wrap gap-3"><button disabled={form.processing} className={primary}>Request demonstration credits</button><button type="button" disabled={form.processing} onClick={() => { form.setData(initial()); form.clearErrors(); setFailure(''); }} className={secondary}>Start new request</button></div>
    </form>;
}

export default function LossControls({ planId, value, summaryLabels = [] }: { planId: number; value: LossControlState; summaryLabels?: string[] }) {
    const rec = value.reconciliation?.reconciliation ?? null;
    const stale = Boolean(value.reconciliation?.stale || rec?.stale);
    const findingHeading = rec?.status === 'released'
        ? 'Found parcel reconciliation released'
        : rec?.status === 'approved'
            ? 'Reconciliation approved · awaiting physical release'
            : 'Found parcel awaiting reconciliation';
    return <>
        {value.finding && <section className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-5">
            <h2 className="font-bold text-amber-950">{findingHeading}</h2>
            <p className="break-all text-xs text-amber-900">Finding {value.finding.reference}</p>
            <div className="flex flex-wrap gap-3">
                <a href={value.finding.proof_url} className={secondary}><FileText className="h-4 w-4" />Private finding evidence</a>
                {value.finding.intake_url && <a href={value.finding.intake_url} className={secondary}>Open quarantine release & intake</a>}
            </div>
        </section>}
        {value.reconciliation && <section className="space-y-3 rounded-lg border border-slate-300 bg-white p-5 shadow-sm" aria-label="Found parcel reconciliation status">
            <h2 className="font-bold text-slate-900">Found-parcel reconciliation</h2>
            {rec && <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <strong className="text-slate-900">{rec.outcome_label}</strong>
                    <span className="rounded bg-rose-50 px-2.5 py-1 text-xs font-bold text-[#C20836]">{rec.status === 'approved' ? 'Approved · ready for release' : rec.status === 'released' ? 'Released' : 'Invalidated'}</span>
                </div>
                <p className="break-all text-xs text-slate-500">Reconciliation {rec.reference}</p>
                {rec.reason && <p className="whitespace-pre-wrap break-words text-slate-700">{rec.reason}</p>}
                {rec.invalidation_reason && <p className="text-xs text-amber-900">Invalidated: {rec.invalidation_reason}</p>}
                {rec.released_at && <p className="text-xs text-slate-600">Released {new Date(rec.released_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>}
                {rec.proof_url && <a href={rec.proof_url} className={secondary}><FileText className="h-4 w-4" />Private reconciliation evidence</a>}
            </div>}
            {stale && <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">Original financial state changed after reconciliation approval. Invalidate and approve a fresh reconciliation before releasing quarantine.</p>}
            {rec?.status !== 'released' && Object.keys(value.reconciliation.blocked_reasons).length > 0 && <dl className="space-y-2 text-xs text-slate-600">
                {Object.entries(value.reconciliation.blocked_reasons).map(([path, blocker]) => <div key={path} className="rounded border border-slate-200 p-2.5"><dt className="font-semibold text-slate-800">{outcomePaths[path] ?? path}</dt><dd className="mt-1">{blocker}</dd></div>)}
            </dl>}
        </section>}
        {value.accounting && <p role="status" className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-slate-800">{value.accounting.verified ? 'The original missing parcel is accounted as a confirmed loss. Physical arrivals and money remain separate.' : 'Retained loss accounting needs independent source reconciliation.'}</p>}
        {value.hold_message && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{value.hold_message}</p>}
        <ActionForm key={planId} planId={planId} value={value} />
        {value.funding && <section className="rounded-lg border border-slate-300 bg-white p-5 shadow-sm"><h2 className="flex items-center gap-2 font-bold text-slate-900"><Wallet className="h-5 w-5 text-[#E00D42]" />Original funding</h2><dl className="mt-4 grid gap-4 sm:grid-cols-3">{Object.entries(value.funding).filter(([label]) => !summaryLabels.includes(label)).map(([label, amount]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 font-bold text-slate-900">{financeMoney(amount)}</dd></div>)}</dl></section>}
        {value.credits && (value.credits.maximum_cents > 0 || value.credits.requests.length > 0 || value.credits.wallet_balance_cents !== null) && <section className="space-y-4 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6"><h2 className="font-bold text-slate-900">Demonstration funding credits</h2>
            {value.credits.wallet_balance_cents !== null && <p className="text-sm text-slate-600">My virtual Wallet balance: <strong className="text-slate-900">{financeMoney(value.credits.wallet_balance_cents)}</strong></p>}
            {value.credits.available_for_authority_cents !== undefined && value.credits.available_for_authority_cents !== null && value.credits.available_for_authority_cents !== value.credits.wallet_balance_cents && <p className="text-sm text-slate-600">Available for this loss: <strong className="text-slate-900">{financeMoney(value.credits.available_for_authority_cents)}</strong></p>}
            {value.credits.maximum_cents > 0 && !value.credits.requests.some(request => request.status === 'pending') && <CreditRequestForm key={planId} planId={planId} version={value.version} maximum={value.credits.maximum_cents} />}
            {value.credits.requests.map(request => <article key={request.id} className="space-y-3 rounded-lg border border-slate-300 p-4"><div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold text-slate-900">{financeMoney(request.amount_cents)}</h3><span className="rounded-lg bg-rose-50 px-2 py-1.5 text-xs font-semibold text-[#C20836]">{request.status === 'pending' ? 'Awaiting independent review' : request.status === 'approved' ? 'Credits granted' : 'Request declined'}</span></div><p className="break-all text-xs text-slate-500">{request.reference}</p><p className="whitespace-pre-wrap break-words text-sm text-slate-700">{request.reason}</p>{request.review_reason && <p className="whitespace-pre-wrap break-words text-sm text-slate-700">{request.review_reason}</p>}{request.wallet_reference && <p className="break-all text-xs text-slate-600">Grant journal: {request.wallet_reference}</p>}{request.can_review && <CreditReview planId={planId} request={request} />}</article>)}
        </section>}
        {value.disposition && <section className="space-y-3 rounded-lg border border-slate-300 bg-white p-5 shadow-sm"><h2 className="font-bold text-slate-900">Retained operational loss evidence</h2><p className="break-all text-xs text-slate-500">{value.disposition.reference}</p><a href={value.disposition.proof_url} className={secondary}><FileText className="h-4 w-4" />Private loss evidence</a></section>}
    </>;
}
