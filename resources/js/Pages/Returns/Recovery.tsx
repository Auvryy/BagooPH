import { FormEvent, useCallback, useEffect, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ClipboardCheck, MapPin, Route, ShieldCheck } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubNextStep, HubPageHeader, HubPanel, HubRoute, hubButton, hubInput, hubPrimary } from '@/Components/Logistics/Workspace';
import WaybillCamera from '@/Components/WaybillCamera';
import useRequestError from '@/hooks/useCourierRequestError';

interface Recovery {
    id: number; reference: string; state: string; version: number; leg_index: number; authorization_reason: string | null;
    barcode: string; caseUrl: string; originalManifestUrl: string | null; sourceToken: string; actions: string[];
    hubs: Array<{ id: number; name: string; code: string; tier: string }>;
    currentHubId: number | null; manifestUrl: string | null; canPrepare: boolean;
    history: Array<{ reference: string; event_type: string; actor_role: string; reason: string; created_at: string }>;
}
const labels: Record<string, string> = { authorize: 'Authorize recovery route', withdraw: 'Withdraw observation', intake: 'Receive actual parcel' };
const date = (value: string) => new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

export default function RecoveryPage({ recovery }: { recovery: Recovery }) {
    const form = useForm({ action: '', notes: '', barcode: '', photo: null as File | null, request_token: crypto.randomUUID() });
    const pending = useRef(false);
    const [failure, setFailure] = useState('');
    useRequestError(pending, useCallback(message => setFailure(message), []));
    useEffect(() => { form.setData({ action: '', notes: '', barcode: '', photo: null, request_token: crypto.randomUUID() }); form.clearErrors(); setFailure(''); }, [recovery.id, recovery.version]);
    const selected = recovery.actions.includes(form.data.action) ? form.data.action : '';
    const intake = selected === 'intake';
    const title = recovery.state === 'observed' ? 'Company review needed'
        : recovery.state === 'authorized' ? 'Receive the actual parcel at the observed hub'
            : recovery.state === 'held' ? 'Prepare the next recovery transfer'
                : recovery.state === 'in_transit' ? 'Await the next hub’s actual receipt'
                    : recovery.state === 'completed' ? 'Original return route resumed' : 'Observation withdrawn';
    const description = ['observed', 'authorized'].includes(recovery.state) ? 'The original recorded custody remains in place until a handler receives the actual parcel.'
        : recovery.state === 'completed' ? 'The original expected hub has received the recovered parcel. Continue the return from its saved route.'
            : recovery.state === 'withdrawn' ? 'The observation was withdrawn before intake. No physical movement was recorded.'
                : 'Each departure and receipt belongs to its own manifest. Financial fault and repayment require separate review.';
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!selected || pending.current || form.processing) return;
        if (intake && form.data.barcode.trim().toUpperCase() !== recovery.barcode) {
            form.setError('barcode', 'Scan this return’s separate barcode.'); return;
        }
        if (intake && (!form.data.photo || form.data.photo.size > 5242880 || !['image/jpeg', 'image/png', 'image/webp'].includes(form.data.photo.type))) {
            form.setError('photo', 'Choose a real JPG, PNG or WebP photo up to 5 MB.'); return;
        }
        pending.current = true; setFailure('');
        form.transform(data => ({ version: recovery.version, request_token: data.request_token, notes: data.notes,
            ...(selected === 'authorize' ? { source_token: recovery.sourceToken } : {}),
            ...(intake ? { barcode: data.barcode.trim().toUpperCase(), photo: data.photo } : {}) }));
        form.post(`/buyer-return-recoveries/${recovery.id}/${selected}`, { preserveScroll: true, forceFormData: intake, onFinish: () => { pending.current = false; } });
    };
    return <DashboardLayout title="Return route recovery"><Head title={recovery.reference} />
        <div className="mx-auto max-w-6xl space-y-5">
            <HubPageHeader title="Return route recovery" description={recovery.reference} icon={Route}
                actions={<Link href={recovery.caseUrl} className={hubButton}>Open return case</Link>} />
            <HubNextStep title={title} icon={recovery.state === 'completed' ? ShieldCheck : ClipboardCheck}
                action={recovery.manifestUrl ? <Link className={hubButton} href={recovery.manifestUrl}>Open recovery manifest</Link>
                    : recovery.canPrepare ? <Link className={hubButton} href={recovery.caseUrl}>Measure and prepare return</Link> : undefined}>
                {description}
            </HubNextStep>
            <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1.5fr)_minmax(280px,1fr)]">
                <div className="space-y-5"><HubPanel title={recovery.state === 'observed' ? 'Proposed path' : 'Recovery path'} icon={<MapPin className="h-4 w-4" />}>
                    <div className="mb-5 flex flex-wrap items-center gap-2"><HubBadge tone={recovery.state === 'completed' ? 'success' : 'warning'}>{recovery.state.replaceAll('_', ' ')}</HubBadge><span className="break-all text-xs font-semibold text-slate-600">{recovery.barcode}</span></div>
                    <HubRoute stops={recovery.hubs} currentHubId={recovery.currentHubId} label={recovery.state === 'observed' ? 'Proposed recovery route' : 'Recovery route'} />
                    {recovery.authorization_reason && <p className="mt-4 rounded-lg border border-rose-200 bg-rose-50/50 p-3 text-sm leading-6 text-slate-700">{recovery.authorization_reason}</p>}
                    {recovery.originalManifestUrl && <Link className={`${hubButton} mt-5`} href={recovery.originalManifestUrl}>Open original return manifest</Link>}
                </HubPanel>
                <HubPanel title="Retained recovery history"><ol className="space-y-4">{recovery.history.map(item => <li key={item.reference} className="border-l-2 border-rose-200 pl-4"><p className="text-sm font-bold text-slate-800">{item.event_type.replaceAll('_', ' ')}</p><p className="mt-1 whitespace-pre-wrap break-words text-sm leading-6 text-slate-600">{item.reason}</p><p className="mt-1 text-xs text-slate-500">{item.actor_role} · {date(item.created_at)}</p></li>)}</ol></HubPanel></div>
                {recovery.actions.length > 0 && <HubPanel title="Record the next step" description="Only the actions available to your account are shown.">
                    <form onSubmit={submit} className="space-y-4" aria-busy={form.processing || undefined}>
                        <fieldset disabled={form.processing} className="space-y-2"><legend className="mb-2 text-xs font-semibold text-slate-700">Action</legend>{recovery.actions.map(action => <label key={action} className={`flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm font-semibold ${selected === action ? 'border-rose-300 bg-rose-50 text-[#9F1239]' : 'border-slate-300 text-slate-700'}`}><input type="radio" name="return-recovery-action" required checked={selected === action} onChange={() => form.setData('action', action)} className="mt-0.5 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" />{labels[action]}</label>)}</fieldset>
                        {intake && <><WaybillCamera disabled={form.processing} onScan={barcode => form.setData('barcode', barcode)} instruction="Scan the separate return barcode on the actual parcel." />
                            <label className="block text-xs font-semibold text-slate-700">Separate return barcode<input required maxLength={40} autoComplete="off" disabled={form.processing} className={`${hubInput} mt-2`} value={form.data.barcode} onChange={event => form.setData('barcode', event.target.value)} /></label>
                            <label className="block text-xs font-semibold text-slate-700">Actual intake photo<input required type="file" disabled={form.processing} accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" className={`${hubInput} mt-2`} onChange={event => form.setData('photo', event.target.files?.[0] ?? null)} /><span className="mt-1 block text-xs font-normal text-slate-500">Private JPG, PNG or WebP, up to 5 MB.</span></label></>}
                        <label className="block text-xs font-semibold text-slate-700">Review or handover details<textarea required minLength={10} maxLength={1000} rows={4} disabled={form.processing} className={`${hubInput} mt-2`} value={form.data.notes} onChange={event => form.setData('notes', event.target.value)} /><span className="mt-1 block text-xs font-normal text-slate-500">10–1,000 characters.</span></label>
                        {failure && <p role="alert" className="text-sm text-rose-700">{failure}</p>}{Object.entries(form.errors).map(([key, message]) => <p role="alert" key={key} className="text-sm text-rose-700">{String(message)}</p>)}
                        <button type="submit" disabled={!selected || form.processing} className={`${hubPrimary} w-full`}>{form.processing ? 'Saving…' : labels[selected] ?? 'Choose an action'}</button>
                    </form>
                </HubPanel>}
            </div>
        </div>
    </DashboardLayout>;
}
