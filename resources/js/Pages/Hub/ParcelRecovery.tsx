import { FormEvent, useEffect, useRef } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, PackageSearch, ScanLine } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import WaybillCamera from '@/Components/WaybillCamera';

interface Observation {
    id: number; reference: string; kind: string; barcode: string; observedHub: string;
    reporter: string; reportedAt: string; state: string; url: string; manifestUrl: string | null;
}
interface RecoveryCase {
    id: number; reference: string; state: string; version: number; legIndex: number; reason: string;
    currentHubId: number | null; nextHubId: number | null; canPrepareTransfer: boolean;
    transferUrl: string | null; originalManifestUrl: string | null;
    events: Array<{ reference: string; type: string; actor: string; hub: string | null; notes: string; recordedAt: string }>;
    transfers: Array<{ reference: string; status: string; url: string | null }>;
}
interface Detail extends Observation {
    notes: string; blocker: string | null; canAuthorize: boolean; canIntake: boolean; canWithdraw: boolean;
    sourceToken: string | null; custody: string; expectedHub: string | null; case: RecoveryCase | null;
    route: Array<{ id: number; name: string; code: string; tier: string }>;
}
interface Props {
    observations: { data: Observation[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    selectedObservation: Detail | null; filters: { status: string }; basePath: string; manifestPath: string;
    requestToken: string; creationToken: string | null; vehicles: Array<{ id: number; plate: string; driver: string }>;
}
const labels: Record<string, string> = {
    needs_review: 'Needs company review', authorized: 'Awaiting intake scan', held: 'Held at recovery hub',
    in_transit: 'Recovery transfer in progress', completed: 'Physical recovery complete', resolved: 'Discrepancy resolved',
    withdrawn: 'Unused authorization withdrawn', intake: 'Actual parcel received', transfer_departed: 'Recovery transfer departed',
    transfer_received: 'Recovery transfer received',
};
const label = (value: string) => labels[value] ?? value.replaceAll('_', ' ');
const date = (value: string) => new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
const buttonClass = 'rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';

export default function ParcelRecovery({ observations, selectedObservation: selected, filters, basePath, manifestPath, requestToken, creationToken, vehicles }: Props) {
    const pending = useRef(false);
    const form = useForm({ notes: '', barcode: '', request_token: requestToken, source_token: selected?.sourceToken ?? '', version: selected?.case?.version ?? 0 });
    const transfer = useForm({ vehicle_id: '', creation_token: creationToken ?? '', direction: 'recovery',
        parcel_recovery_case_id: selected?.case?.id ?? 0, source_hub_id: selected?.case?.currentHubId ?? 0, destination_hub_id: selected?.case?.nextHubId ?? 0 });
    useEffect(() => {
        form.setData({ notes: '', barcode: '', request_token: requestToken, source_token: selected?.sourceToken ?? '', version: selected?.case?.version ?? 0 });
        form.clearErrors();
        transfer.setData({ vehicle_id: '', creation_token: creationToken ?? '', direction: 'recovery',
            parcel_recovery_case_id: selected?.case?.id ?? 0, source_hub_id: selected?.case?.currentHubId ?? 0, destination_hub_id: selected?.case?.nextHubId ?? 0 });
    }, [selected?.id, selected?.case?.version]);
    useEffect(() => form.setData('source_token', selected?.sourceToken ?? ''), [selected?.sourceToken]);
    useEffect(() => transfer.setData('creation_token', creationToken ?? ''), [creationToken]);
    const command = (action: 'authorize' | 'intake' | 'withdraw') => {
        if (!selected || pending.current || form.processing || transfer.processing) return;
        pending.current = true;
        form.post(`${basePath}/${selected.id}/${action}`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const createTransfer = (event: FormEvent) => {
        event.preventDefault();
        if (pending.current || form.processing || transfer.processing) return;
        pending.current = true;
        transfer.post(manifestPath, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const error = (message?: string) => message ? <p role="alert" className="mt-1 text-sm text-red-700">{message}</p> : null;
    const busy = form.processing || transfer.processing;

    return <DashboardLayout title="Parcel recovery">
        <Head title="Parcel recovery" />
        <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div><h1 className="text-2xl font-bold text-slate-900">Parcel recovery</h1><p className="mt-1 max-w-2xl text-sm text-slate-600">Recover unexpected parcels through actual hub scans and recorded transfers back to their original route.</p></div>
                <Link href={manifestPath} className={buttonClass}>View manifests</Link>
            </div>
            <div className="flex flex-wrap gap-2" aria-label="Recovery worklist filters">
                {[['open', 'Needs attention'], ['resolved', 'Resolved'], ['all', 'All observations']].map(([value, text]) => <button key={value} type="button" aria-pressed={filters.status === value}
                    onClick={() => router.get(basePath, { status: value }, { preserveScroll: true })}
                    className={`${buttonClass} ${filters.status === value ? 'border-[#E00D42] bg-[#FFFAFB] text-[#E00D42]' : ''}`}>{text}</button>)}
            </div>
            <div className="grid items-start gap-6 lg:grid-cols-[minmax(260px,1fr)_minmax(0,2fr)]">
                <section className="rounded-lg border border-slate-300 bg-white p-4">
                    <h2 className="font-semibold text-slate-900">Reported observations</h2>
                    {!observations.data.length && <div className="py-8 text-center"><PackageSearch className="mx-auto h-8 w-8 text-slate-400" /><p className="mt-3 text-sm text-slate-600">No observations match this worklist.</p></div>}
                    <div className="mt-3 space-y-3">{observations.data.map(observation => <Link key={observation.id} href={observation.url}
                        className={`block rounded-lg border p-3 ${selected?.id === observation.id ? 'border-[#E00D42] bg-[#FFFAFB]' : 'border-slate-300 hover:bg-slate-50'}`}>
                        <p className="break-all text-sm font-semibold text-slate-900">{observation.barcode}</p><p className="mt-1 text-xs text-slate-600">{observation.observedHub}</p>
                        <p className="mt-2 text-xs font-semibold text-slate-800">{label(observation.state)}</p><p className="mt-1 text-xs text-slate-500">{date(observation.reportedAt)}</p>
                    </Link>)}</div>
                    <nav className="mt-4 flex flex-wrap gap-2" aria-label="Recovery worklist pages">{observations.links.map((page, index) => page.url
                        ? <Link key={index} href={page.url} aria-current={page.active ? 'page' : undefined} className={buttonClass}>{page.label.replaceAll('&laquo;', '‹').replaceAll('&raquo;', '›')}</Link>
                        : <span key={index} className="px-3 py-2 text-sm text-slate-400">{page.label.replaceAll('&laquo;', '‹').replaceAll('&raquo;', '›')}</span>)}</nav>
                </section>
                {!selected ? <section className="rounded-lg border border-slate-300 bg-white p-6"><h2 className="font-semibold text-slate-900">Choose a reported parcel</h2><p className="mt-2 text-sm text-slate-600">Review its actual observation, current custodian and supported next step. Unknown waybills remain open until their original parcel can be established.</p></section>
                    : <div className="space-y-5">
                        <section className="rounded-lg border border-slate-300 bg-white p-5">
                            <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="break-all text-lg font-semibold text-slate-900">{selected.barcode}</h2><span className="rounded-lg border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">{label(selected.state)}</span></div>
                            <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                                <div><dt className="text-xs text-slate-500">Observed facility</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{selected.observedHub}</dd></div>
                                <div><dt className="text-xs text-slate-500">Recorded custodian</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{selected.custody}</dd></div>
                                <div><dt className="text-xs text-slate-500">Original expected hub</dt><dd className="mt-1 text-sm font-semibold text-slate-800">{selected.expectedHub ?? 'Not established'}</dd></div>
                                <div><dt className="text-xs text-slate-500">Reported by</dt><dd className="mt-1 text-sm text-slate-800">{selected.reporter} · {date(selected.reportedAt)}</dd></div>
                            </dl>
                            <p className="mt-4 whitespace-pre-wrap text-sm text-slate-700">{selected.notes}</p>
                            {selected.manifestUrl && <Link href={selected.manifestUrl} className="mt-4 inline-block text-sm font-semibold text-[#E00D42]">Open the observation manifest</Link>}
                            {selected.route.length > 0 && <div className="mt-4 rounded-lg border border-slate-300 bg-slate-50 p-3"><h3 className="text-sm font-semibold text-slate-800">Authorized recovery route</h3><ol className="mt-2 flex flex-wrap items-center gap-2">{selected.route.map((hub, index) => <li key={hub.id} className="flex items-center gap-2 text-sm text-slate-700">{index > 0 && <ArrowRight className="h-4 w-4 text-slate-400" />}<span>{hub.name}<span className="ml-1 text-xs text-slate-500">({hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'})</span></span></li>)}</ol></div>}
                            {selected.blocker && <div role="alert" className="mt-4 flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"><AlertTriangle className="h-5 w-5 shrink-0" /><p>{selected.blocker}</p></div>}
                        </section>
                        {(selected.canAuthorize || selected.canIntake || selected.canWithdraw) && <section className="rounded-lg border border-slate-300 bg-white p-5">
                            <h2 className="font-semibold text-slate-900">Next action</h2>
                            <p className="mt-2 text-sm text-slate-600">{selected.canAuthorize ? 'The company authorizes the case. The assigned receiving handler then scans the actual parcel.' : selected.canIntake ? 'Scan the actual waybill at the observed facility. Recording intake changes custody and keeps the original delivery obligation.' : 'The company may withdraw this unused authorization. This does not change parcel custody or resolve the observation.'}</p>
                            {selected.canIntake && <><WaybillCamera disabled={busy} onScan={barcode => form.setData('barcode', barcode)} /><label className="mt-4 block text-sm font-semibold text-slate-800">Actual waybill<input required value={form.data.barcode} disabled={busy} onChange={event => form.setData('barcode', event.target.value)} className={inputClass} autoComplete="off" />{error(form.errors.barcode)}</label></>}
                            <label className="mt-4 block text-sm font-semibold text-slate-800">Reason or receiving notes<textarea required minLength={5} maxLength={1000} rows={3} disabled={busy} value={form.data.notes} onChange={event => form.setData('notes', event.target.value)} className={inputClass} />{error(form.errors.notes)}</label>
                            {Object.entries(form.errors).filter(([key]) => !['notes', 'barcode'].includes(key)).map(([key, message]) => <p role="alert" key={key} className="mt-2 text-sm text-red-700">{message}</p>)}
                            <div className="mt-4 flex flex-wrap gap-3">
                                {selected.canAuthorize && <button type="button" disabled={busy || form.data.notes.trim().length < 5} onClick={() => command('authorize')} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Authorize recovery</button>}
                                {selected.canIntake && <button type="button" disabled={busy || !form.data.barcode || form.data.notes.trim().length < 5} onClick={() => command('intake')} className="inline-flex items-center gap-2 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><ScanLine className="h-4 w-4" />Record actual intake</button>}
                                {selected.canWithdraw && <button type="button" disabled={busy || form.data.notes.trim().length < 5} onClick={() => command('withdraw')} className={buttonClass}>Withdraw unused authorization</button>}
                            </div>
                        </section>}
                        {selected.case && <section className="rounded-lg border border-slate-300 bg-white p-5">
                            <h2 className="font-semibold text-slate-900">Recovery progress</h2><p className="mt-1 break-all text-xs text-slate-500">{selected.case.reference}</p>
                            {selected.case.state === 'completed' && <div className="mt-4 flex gap-2 rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-900"><CheckCircle2 className="h-5 w-5 shrink-0" /><p>The original expected hub received the parcel. Its normal journey may continue after manifest closure. The company still reviews the observation and its linked receipt before resolving the discrepancy.</p></div>}
                            {selected.case.canPrepareTransfer && <form onSubmit={createTransfer} className="mt-4">
                                <p className="text-sm text-slate-600">Prepare the next recorded transfer. The sending handler loads the actual parcel; the company seals the manifest before departure.</p>
                                <label className="mt-3 block text-sm font-semibold text-slate-800">Source-hub vehicle and driver<select required disabled={busy} value={transfer.data.vehicle_id} onChange={event => transfer.setData('vehicle_id', event.target.value)} className={inputClass}><option value="">Choose an eligible vehicle</option>{vehicles.map(vehicle => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate} · {vehicle.driver}</option>)}</select></label>
                                {!vehicles.length && <p className="mt-2 text-sm text-amber-800">This hub needs an eligible vehicle and available driver for the required transfer type.</p>}
                                {Object.entries(transfer.errors).map(([key, message]) => <p role="alert" key={key} className="mt-2 text-sm text-red-700">{message}</p>)}
                                <button disabled={busy || !transfer.data.vehicle_id} className="mt-3 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Prepare recovery manifest</button>
                            </form>}
                            {selected.case.transferUrl && <Link href={selected.case.transferUrl} className={`${buttonClass} mt-4 inline-block`}>Continue the current recovery manifest</Link>}
                            {selected.case.state === 'in_transit' && <p className="mt-3 text-sm text-slate-600">The next assigned receiving handler must scan this parcel on its arriving recovery manifest.</p>}
                            <div className="mt-4 flex flex-wrap gap-3">{selected.case.originalManifestUrl && <Link href={selected.case.originalManifestUrl} className="text-sm font-semibold text-[#E00D42]">Original receiving manifest</Link>}{selected.case.transfers.map(transfer => transfer.url
                                ? <Link key={transfer.reference} href={transfer.url} className="break-all text-xs text-slate-600">{transfer.reference} · {label(transfer.status)}</Link>
                                : <span key={transfer.reference} className="break-all text-xs text-slate-600">{transfer.reference} · {label(transfer.status)}</span>)}</div>
                            <h3 className="mt-6 text-sm font-semibold text-slate-900">Recorded history</h3><ol className="mt-3 space-y-4">{selected.case.events.map(event => <li key={event.reference} className="border-l-2 border-slate-300 pl-4"><p className="text-sm font-semibold text-slate-800">{label(event.type)}</p><p className="mt-1 text-xs text-slate-500">{event.actor}{event.hub ? ` · ${event.hub}` : ''} · {date(event.recordedAt)}</p><p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{event.notes}</p></li>)}</ol>
                        </section>}
                    </div>}
            </div>
        </div>
    </DashboardLayout>;
}
