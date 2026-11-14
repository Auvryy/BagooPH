import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { PackageSearch, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubPageHeader, hubButton, hubInput, hubPrimary } from '@/Components/Logistics/Workspace';
import useRequestError from '@/hooks/useCourierRequestError';
import type { PageProps } from '@/types';

interface Intake {
    id: number; barcode: string; manifest_reference: string; scope: string;
    hub: { id: number; name: string; code: string }; version: number; can_intake: boolean; blocker: string | null;
    finding: { reference: string; recorded_at: string; at_selected_hub: boolean; proof_url: string | null } | null;
}
interface ReleaseState {
    can_release: boolean;
    blocker: string | null;
    stale: boolean;
    reconciliation: { id: number; reference: string; outcome_path: string; outcome_label: string; status: string; approved_at: string | null; released_at: string | null } | null;
}

function IntakeForm({ intake, requestToken, actorId }: { intake: Intake; requestToken: string; actorId: number | null }) {
    const initial = (token: string = crypto.randomUUID()) => ({ hub_id: intake.hub.id, expected_version: intake.version, request_token: token,
        barcode: '', reason: '', proof: null as File | null, quarantine_confirmed: false });
    const form = useForm(initial(requestToken));
    const pending = useRef(false);
    const file = useRef<HTMLInputElement>(null);
    const identity = useRef(`${intake.id}:${intake.hub.id}:${actorId ?? ''}`);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const clear = () => { form.setData(initial()); form.clearErrors(); setFailure(''); if (file.current) file.current.value = ''; };
    useEffect(() => {
        const next = `${intake.id}:${intake.hub.id}:${actorId ?? ''}`;
        if (identity.current !== next) { identity.current = next; clear(); }
    }, [intake.id, intake.hub.id, actorId]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!intake.can_intake || pending.current || form.processing) return;
        form.clearErrors(); let invalid = false;
        const reject = (key: keyof typeof form.data, message: string) => { form.setError(key, message); invalid = true; };
        if (form.data.barcode !== intake.barcode) reject('barcode', 'Scan this parcel’s exact waybill.');
        if (form.data.reason.trim().length < 10 || form.data.reason.trim().length > 1000) reject('reason', 'Enter 10–1,000 characters about the actual finding and storage.');
        if (!form.data.proof || form.data.proof.size <= 0 || form.data.proof.size > 5242880
            || !['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(form.data.proof.type)) reject('proof', 'Choose a real JPG, PNG, WebP or PDF up to 5 MB.');
        if (!form.data.quarantine_confirmed) reject('quarantine_confirmed', 'Confirm actual custody in the named hub quarantine.');
        if (invalid) return;
        pending.current = true; setFailure('');
        form.transform(data => ({ hub_id: data.hub_id, expected_version: data.expected_version, request_token: data.request_token,
            barcode: data.barcode, reason: data.reason.trim(), proof: data.proof, quarantine_confirmed: data.quarantine_confirmed }));
        form.post(`/loss-found/${intake.id}`, { preserveScroll: true, forceFormData: true,
            onSuccess: page => {
                const saved = (page.props as { intake?: Intake }).intake;
                if (saved?.id === intake.id && saved.finding) clear();
            }, onFinish: () => { pending.current = false; } });
    };
    const busy = pending.current || form.processing;
    return <form onSubmit={submit} aria-label="Record actual found parcel" aria-busy={form.processing || undefined}
        className="space-y-5 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-base font-bold text-slate-900">Record actual quarantine intake</h2>
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
        {Object.entries(form.errors).map(([key, error]) => <p role="alert" key={key} className="text-sm text-rose-700">{error}</p>)}
        <label className="block text-sm font-semibold text-slate-900">Scan parcel waybill<input className={`${hubInput} mt-2`} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} required maxLength={100} autoComplete="off" disabled={busy} /></label>
        <label className="block text-sm font-semibold text-slate-900">Where was it found and stored?<textarea className={`${hubInput} mt-2`} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={busy} /></label>
        <label className="block text-sm font-semibold text-slate-900">Private finding evidence<input ref={file} className={`${hubInput} mt-2`} type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required disabled={busy} onChange={event => form.setData('proof', event.target.files?.[0] ?? null)} /><span className="mt-1 block text-xs font-normal text-slate-500">JPG, PNG, WebP or PDF · up to 5 MB</span></label>
        <label className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-slate-800"><input type="checkbox" className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" required disabled={busy} checked={form.data.quarantine_confirmed} onChange={event => form.setData('quarantine_confirmed', event.target.checked)} /><span>I physically hold this parcel at {intake.hub.name} and have placed it in quarantine. This records no normal manifest receipt, transport arrival or delivery.</span></label>
        <div className="flex flex-wrap gap-3"><button type="submit" disabled={busy} className={hubPrimary}>{form.processing ? 'Recording…' : 'Record quarantine intake'}</button><button type="button" className={hubButton} disabled={busy} onClick={clear}>Clear and start a new update</button></div>
    </form>;
}

function ReleaseForm({ intake, release, requestToken, actorId }: { intake: Intake; release: ReleaseState & { reconciliation: NonNullable<ReleaseState['reconciliation']> }; requestToken: string; actorId: number | null }) {
    const reconciliationRef = release.reconciliation.reference;
    const initial = (token: string = crypto.randomUUID()) => ({
        hub_id: intake.hub.id, expected_version: intake.version, reconciliation_reference: reconciliationRef,
        request_token: token, barcode: '', reason: '', release_confirmed: false,
    });
    const form = useForm(initial(requestToken));
    const pending = useRef(false);
    const identity = useRef(`${intake.id}:${intake.hub.id}:${reconciliationRef}:${actorId ?? ''}`);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    const clear = () => { form.setData(initial()); form.clearErrors(); setFailure(''); };
    useEffect(() => {
        const next = `${intake.id}:${intake.hub.id}:${reconciliationRef}:${actorId ?? ''}`;
        if (identity.current !== next) { identity.current = next; clear(); }
    }, [intake.id, intake.hub.id, reconciliationRef, actorId]);
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!release.can_release || pending.current || form.processing) return;
        form.clearErrors(); let invalid = false;
        const reject = (key: keyof typeof form.data, message: string) => { form.setError(key, message); invalid = true; };
        if (form.data.barcode !== intake.barcode) reject('barcode', 'Scan this parcel’s exact waybill before releasing quarantine.');
        if (form.data.reason.trim().length < 10 || form.data.reason.trim().length > 1000) reject('reason', 'Enter 10–1,000 characters describing the physical release handover.');
        if (!form.data.release_confirmed) reject('release_confirmed', 'Confirm physical release from the named hub quarantine.');
        if (invalid) return;
        pending.current = true; setFailure('');
        form.transform(data => ({
            hub_id: data.hub_id, expected_version: data.expected_version, reconciliation_reference: data.reconciliation_reference,
            request_token: data.request_token, barcode: data.barcode, reason: data.reason.trim(), release_confirmed: data.release_confirmed,
        }));
        form.post(`/loss-found/${intake.id}/release`, {
            preserveScroll: true,
            onSuccess: page => {
                const updated = (page.props as { release?: ReleaseState | null }).release;
                if (updated?.reconciliation?.status === 'released') clear();
            },
            onFinish: () => { pending.current = false; },
        });
    };
    const busy = pending.current || form.processing;
    return <form onSubmit={submit} aria-label="Release parcel from quarantine" aria-busy={form.processing || undefined}
        className="space-y-5 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:p-6">
        <h2 className="text-base font-bold text-slate-900">Complete physical quarantine release</h2>
        <p className="text-sm text-slate-600">Approved outcome: <strong className="text-slate-900">{release.reconciliation.outcome_label}</strong> ({reconciliationRef})</p>
        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}
        {Object.entries(form.errors).map(([key, error]) => <p role="alert" key={key} className="text-sm text-rose-700">{error}</p>)}
        <label className="block text-sm font-semibold text-slate-900">Scan parcel waybill<input className={`${hubInput} mt-2`} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} required maxLength={100} autoComplete="off" disabled={busy} /></label>
        <label className="block text-sm font-semibold text-slate-900">Physical release handover details<textarea className={`${hubInput} mt-2`} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} required minLength={10} maxLength={1000} rows={3} autoComplete="off" disabled={busy} /></label>
        <label className="flex items-start gap-3 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-slate-800"><input type="checkbox" className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" required disabled={busy} checked={form.data.release_confirmed} onChange={event => form.setData('release_confirmed', event.target.checked)} /><span>I physically verified this parcel at {intake.hub.name} and release quarantine strictly onto its approved reconciliation path.</span></label>
        <div className="flex flex-wrap gap-3"><button type="submit" disabled={busy} className={hubPrimary}>{form.processing ? 'Releasing…' : 'Release quarantine'}</button><button type="button" className={hubButton} disabled={busy} onClick={clear}>Clear</button></div>
    </form>;
}

export default function LossFoundIntake({ intake, release = null, requestToken }: { intake: Intake; release?: ReleaseState | null; requestToken: string }) {
    const actorId = (usePage<PageProps>().props as Partial<PageProps>).auth?.user?.id ?? null;
    return <DashboardLayout title="Found parcel"><Head title="Found parcel" />
        <div className="mx-auto max-w-4xl space-y-6">
            <HubPageHeader title="Found parcel" icon={PackageSearch}
                description="Keep the actual parcel on hold while its original loss and compensation are reconciled."
                actions={<Link href="/hub/scan" className={hubButton}>Back to scanner</Link>} />
            <section className="grid gap-4 rounded-lg border border-slate-300 bg-white p-5 shadow-sm sm:grid-cols-2 sm:p-6">
                <div><p className="text-xs text-slate-500">{intake.scope}</p><p className="mt-1 break-all text-sm font-bold text-[#C20836]">{intake.barcode}</p><p className="mt-1 text-xs text-slate-600">{intake.manifest_reference}</p></div>
                <div><p className="text-xs text-slate-500">Actual intake facility</p><p className="mt-1 text-sm font-bold text-slate-900">{intake.hub.name}</p><p className="mt-1 text-xs text-slate-600">{intake.hub.code}</p></div>
            </section>
            {intake.finding ? <>
                <section role="status" className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-5 sm:p-6">
                    <ShieldCheck className="h-6 w-6 text-amber-800" />
                    <h2 className="text-base font-bold text-amber-950">
                        {release?.reconciliation?.status === 'released'
                            ? `Quarantine released · ${release.reconciliation.outcome_label}`
                            : release?.reconciliation?.status === 'approved' && !release.stale
                                ? `Reconciliation approved · ${release.reconciliation.outcome_label}`
                                : 'Quarantine recorded · awaiting reconciliation'}
                    </h2>
                    <p className="text-sm text-amber-950">
                        {release?.reconciliation?.status === 'released'
                            ? 'Physical quarantine has been released onto the approved path.'
                            : `${intake.finding.at_selected_hub ? 'Keep this parcel in the recorded quarantine area.' : 'This parcel already has a quarantine intake at another facility.'} Ordinary delivery and return handling remain on hold.`}
                    </p>
                    <p className="text-xs text-amber-900">{new Date(intake.finding.recorded_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</p>
                    {intake.finding.proof_url && <a href={intake.finding.proof_url} className={hubButton}>Download private intake evidence</a>}
                </section>
                {release?.can_release && release.reconciliation
                    ? <ReleaseForm intake={intake} release={release as ReleaseState & { reconciliation: NonNullable<ReleaseState['reconciliation']> }} requestToken={requestToken} actorId={actorId} />
                    : release?.blocker && release.reconciliation?.status !== 'released' && <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950">{release.blocker}</p>}
            </> : intake.blocker ? <p role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-5 text-sm text-amber-950">{intake.blocker}</p> : intake.can_intake && <IntakeForm intake={intake} requestToken={requestToken} actorId={actorId} />}
        </div>
    </DashboardLayout>;
}
