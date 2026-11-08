import { FormEvent, useEffect, useRef } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, PackageSearch, ScanLine } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import WaybillCamera from '@/Components/WaybillCamera';
import { HubEmpty, HubNextStep, HubPageHeader, HubRoute } from '@/Components/Logistics/Workspace';
import VehiclePicker from '@/Components/Logistics/VehiclePicker';

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
    handoverHold?: boolean; handoverIssueUrl?: string | null;
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
const inputClass = 'mt-1.5 w-full rounded-lg border border-zinc-300 bg-white px-3.5 py-2 text-sm text-[#121316] shadow-2xs focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 transition-colors';
const buttonClass = 'inline-flex items-center justify-center rounded-lg border border-rose-200 bg-rose-50/85 px-4 py-2 text-xs font-semibold text-[#C20836] shadow-2xs hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] disabled:cursor-not-allowed disabled:opacity-50 transition-colors cursor-pointer';
const primaryClass = 'inline-flex items-center justify-center gap-2 rounded-lg bg-[#E00D42] hover:bg-[#C20836] px-4 py-2 text-xs font-bold text-white shadow-xs transition disabled:cursor-not-allowed disabled:opacity-50';

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
    const error = (message?: string) => message ? <p role="alert" className="mt-1 text-sm text-rose-700">{message}</p> : null;
    const busy = form.processing || transfer.processing;

    return (
        <DashboardLayout title="Parcel recovery">
            <Head title="Parcel recovery" />
            <div className="space-y-6">
                <HubPageHeader
                    title="Parcel recovery"
                    icon={PackageSearch}
                    description="Recover unexpected parcels through actual scans and recorded transfers back to their original route."
                    actions={<Link href={manifestPath} className={buttonClass}>View manifests</Link>}
                />

                <div className="flex gap-2 overflow-x-auto pb-1 [&>*]:shrink-0" role="group" aria-label="Recovery worklist filters">
                    {[['open', 'Needs attention'], ['resolved', 'Resolved'], ['all', 'All observations']].map(([value, text]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={filters.status === value}
                            onClick={() => router.get(basePath, { status: value }, { preserveScroll: true })}
                            className={`${buttonClass} ${filters.status === value ? 'border-rose-300 bg-rose-50/80 text-[#E00D42] font-bold' : ''}`}
                        >
                            {text}
                        </button>
                    ))}
                </div>

                <div className="grid items-start gap-6 lg:grid-cols-[340px_1fr]">
                    {/* Left: Reported Observations Sidebar */}
                    <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                        <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                            <h2 className="text-sm font-bold text-[#121316]">Reported observations</h2>
                        </header>
                        <div className="p-3.5 space-y-2.5">
                            {!observations.data.length && (
                                <div className="py-8 text-center">
                                    <PackageSearch className="mx-auto h-7 w-7 text-zinc-400" />
                                    <p className="mt-2.5 text-xs text-zinc-500 font-medium">No observations match this worklist.</p>
                                </div>
                            )}
                            {observations.data.map(observation => (
                                <Link
                                    key={observation.id}
                                    href={observation.url}
                                    className={`block rounded-xl border p-4 transition-all duration-150 ${
                                        selected?.id === observation.id
                                            ? 'border-rose-300 bg-rose-50/60 ring-1 ring-rose-200/60 shadow-xs'
                                            : 'border-zinc-200/90 bg-white hover:border-zinc-300 hover:bg-zinc-50/40 shadow-2xs'
                                    }`}
                                >
                                    <span className="inline-block break-all rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                                        {observation.barcode}
                                    </span>
                                    <p className="mt-2.5 text-xs font-bold text-[#121316]">{observation.observedHub}</p>
                                    <p className="mt-1.5 text-xs font-semibold text-zinc-700">{label(observation.state)}</p>
                                    <p className="mt-1 text-xs text-zinc-500">{date(observation.reportedAt)}</p>
                                </Link>
                            ))}

                            {observations.links.filter(page => page.url).length > 1 && (
                                <nav className="pt-2 flex flex-wrap gap-2" aria-label="Recovery worklist pages">
                                    {observations.links.map((page, index) =>
                                        page.url ? (
                                            <Link key={index} href={page.url} aria-current={page.active ? 'page' : undefined} className={buttonClass}>
                                                {page.label.replaceAll('&laquo;', '‹').replaceAll('&raquo;', '›')}
                                            </Link>
                                        ) : (
                                            <span key={index} className="px-3 py-2 text-xs text-zinc-400">
                                                {page.label.replaceAll('&laquo;', '‹').replaceAll('&raquo;', '›')}
                                            </span>
                                        )
                                    )}
                                </nav>
                            )}
                        </div>
                    </section>

                    {/* Right: Selected Observation Details */}
                    {!selected ? (
                        <section className="rounded-xl border border-zinc-200/90 bg-white p-6 shadow-xs">
                            <HubEmpty
                                icon={PackageSearch}
                                title={observations.data.length ? 'Choose a reported parcel' : 'No recovery work in this view'}
                                description={
                                    observations.data.length
                                        ? 'See its current holder, authorized route and next action. Unknown waybills remain open until the original parcel is established.'
                                        : 'Parcels reported with an arrival problem appear here for company review. You can check another filter or continue ordinary parcel handling.'
                                }
                            />
                        </section>
                    ) : (
                        <div className="min-w-0 space-y-5">
                            <HubNextStep
                                title={
                                    selected.canAuthorize
                                        ? 'Company: review this observation'
                                        : selected.canIntake
                                          ? 'Receiving handler: scan the actual parcel'
                                          : selected.case?.canPrepareTransfer
                                            ? 'Company: prepare the next recovery transfer'
                                            : selected.case?.state === 'in_transit'
                                              ? 'Receiving handler: receive the arriving transfer'
                                              : selected.case?.state === 'completed'
                                                ? 'Physical recovery recorded'
                                                : 'Review the recorded evidence'
                                }
                                tone={selected.blocker ? 'warning' : selected.case?.state === 'completed' ? 'success' : 'brand'}
                            >
                                <p>{selected.blocker ?? 'Use the supported action below. An approval or transfer plan does not record physical custody.'}</p>
                            </HubNextStep>

                            {/* Observation Overview Card */}
                            <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                    <h2 className="break-all rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                                        {selected.barcode}
                                    </h2>
                                    <span className="rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-700">
                                        {label(selected.state)}
                                    </span>
                                </header>
                                <div className="p-5 sm:p-6 space-y-5">
                                    <dl className="grid gap-4 sm:grid-cols-2 rounded-xl border border-zinc-200/80 bg-zinc-50/30 p-4">
                                        <div>
                                            <dt className="text-xs text-zinc-500 font-medium">Observed facility</dt>
                                            <dd className="mt-1 text-sm font-bold text-[#121316]">{selected.observedHub}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-zinc-500 font-medium">Recorded custodian</dt>
                                            <dd className="mt-1 text-sm font-bold text-[#121316]">{selected.custody}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-zinc-500 font-medium">Original expected hub</dt>
                                            <dd className="mt-1 text-sm font-bold text-[#121316]">{selected.expectedHub ?? 'Not established'}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-zinc-500 font-medium">Reported by</dt>
                                            <dd className="mt-1 text-sm text-zinc-800">{selected.reporter} · {date(selected.reportedAt)}</dd>
                                        </div>
                                    </dl>
                                    <p className="whitespace-pre-wrap text-sm text-zinc-700 leading-relaxed">{selected.notes}</p>
                                    {selected.manifestUrl && (
                                        <Link href={selected.manifestUrl} className="inline-block text-xs font-bold text-[#E00D42] hover:text-[#C20836] underline underline-offset-4">
                                            Open the observation manifest
                                        </Link>
                                    )}
                                    {selected.route.length > 0 && (
                                        <div>
                                            <HubRoute stops={selected.route} currentHubId={selected.case?.currentHubId} label="Authorized recovery route" />
                                        </div>
                                    )}
                                    {selected.blocker && (
                                        <div role="alert" className="flex gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                            <AlertTriangle className="h-5 w-5 shrink-0 text-amber-600" />
                                            <p>{selected.blocker}</p>
                                        </div>
                                    )}
                                    {selected.handoverHold && (
                                        <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">
                                            <p>
                                                This parcel has a reviewed handover hold. Only its specifically authorized original recovery can continue, after the known holder acknowledges possession. The hold ends at the correct receiving hub.
                                            </p>
                                            {selected.handoverIssueUrl && (
                                                <Link href={selected.handoverIssueUrl} className="mt-2 inline-block font-bold text-[#E00D42]">
                                                    Review handover authorization
                                                </Link>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </section>

                            {/* Next Action Console */}
                            {(selected.canAuthorize || selected.canIntake || selected.canWithdraw) && (
                                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <h2 className="text-sm font-bold text-[#121316]">Next action</h2>
                                    </header>
                                    <div className="p-5 sm:p-6 space-y-4">
                                        <p className="text-xs sm:text-sm text-zinc-600 leading-relaxed">
                                            {selected.canAuthorize
                                                ? 'The company authorizes the case. The assigned receiving handler then scans the actual parcel.'
                                                : selected.canIntake
                                                  ? 'Scan the actual waybill at the observed facility. Recording intake changes custody and keeps the original delivery obligation.'
                                                  : 'The company may withdraw this unused authorization. This does not change parcel custody or resolve the observation.'}
                                        </p>
                                        {selected.canIntake && (
                                            <>
                                                <WaybillCamera disabled={busy} onScan={barcode => form.setData('barcode', barcode)} />
                                                <label className="block text-xs font-bold text-[#121316]">
                                                    Actual waybill
                                                    <input
                                                        required
                                                        value={form.data.barcode}
                                                        disabled={busy}
                                                        onChange={event => form.setData('barcode', event.target.value)}
                                                        className={inputClass}
                                                        autoComplete="off"
                                                    />
                                                    {error(form.errors.barcode)}
                                                </label>
                                            </>
                                        )}
                                        <label className="block text-xs font-bold text-[#121316]">
                                            Reason or receiving notes
                                            <textarea
                                                required
                                                minLength={5}
                                                maxLength={1000}
                                                rows={3}
                                                disabled={busy}
                                                value={form.data.notes}
                                                onChange={event => form.setData('notes', event.target.value)}
                                                className={inputClass}
                                            />
                                            {error(form.errors.notes)}
                                        </label>
                                        {Object.entries(form.errors)
                                            .filter(([key]) => !['notes', 'barcode'].includes(key))
                                            .map(([key, message]) => (
                                                <p role="alert" key={key} className="text-sm text-rose-700">{message}</p>
                                            ))}
                                        <div className="flex flex-wrap gap-3 pt-1">
                                            {selected.canAuthorize && (
                                                <button type="button" disabled={busy || form.data.notes.trim().length < 5} onClick={() => command('authorize')} className={primaryClass}>
                                                    Authorize recovery
                                                </button>
                                            )}
                                            {selected.canIntake && (
                                                <button type="button" disabled={busy || !form.data.barcode || form.data.notes.trim().length < 5} onClick={() => command('intake')} className={primaryClass}>
                                                    <ScanLine className="h-4 w-4" />
                                                    Record actual intake
                                                </button>
                                            )}
                                            {selected.canWithdraw && (
                                                <button type="button" disabled={busy || form.data.notes.trim().length < 5} onClick={() => command('withdraw')} className={buttonClass}>
                                                    Withdraw unused authorization
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </section>
                            )}

                            {/* Recovery Progress Card */}
                            {selected.case && (
                                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <h2 className="text-sm font-bold text-[#121316]">Recovery progress</h2>
                                        <p className="mt-0.5 break-all tabular-nums text-xs text-zinc-500">{selected.case.reference}</p>
                                    </header>
                                    <div className="p-5 sm:p-6 space-y-5">
                                        {selected.case.state === 'completed' && (
                                            <div className="flex gap-2.5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                                                <CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600" />
                                                <p>
                                                    The original expected hub received the parcel. Its normal journey may continue after manifest closure. The company still reviews the observation and its linked receipt before resolving the discrepancy.
                                                </p>
                                            </div>
                                        )}

                                        {selected.case.canPrepareTransfer && (
                                            <form onSubmit={createTransfer} className="space-y-4">
                                                <p className="text-xs sm:text-sm text-zinc-600">
                                                    Prepare the next recorded transfer. The sending handler loads the actual parcel; the company seals the manifest before departure.
                                                </p>
                                                <div>
                                                    <VehiclePicker
                                                        label="Source-hub vehicle and driver"
                                                        vehicles={vehicles.map(vehicle => ({ id: vehicle.id, plate_number: vehicle.plate, driver_name: vehicle.driver }))}
                                                        value={transfer.data.vehicle_id}
                                                        onChange={value => transfer.setData('vehicle_id', value)}
                                                        disabled={busy}
                                                    />
                                                </div>
                                                {!vehicles.length && (
                                                    <p className="text-xs text-amber-800">
                                                        This hub needs an eligible vehicle and available driver for the required transfer type.
                                                    </p>
                                                )}
                                                {Object.entries(transfer.errors).map(([key, message]) => (
                                                    <p role="alert" key={key} className="text-sm text-rose-700">{message}</p>
                                                ))}
                                                <button disabled={busy || !transfer.data.vehicle_id} className={primaryClass}>
                                                    Prepare recovery manifest
                                                </button>
                                            </form>
                                        )}

                                        {selected.case.transferUrl && (
                                            <Link href={selected.case.transferUrl} className={buttonClass}>
                                                Continue the current recovery manifest
                                            </Link>
                                        )}

                                        {selected.case.state === 'in_transit' && (
                                            <p className="text-xs sm:text-sm text-zinc-600">
                                                The next assigned receiving handler must scan this parcel on its arriving recovery manifest.
                                            </p>
                                        )}

                                        <div className="flex flex-wrap gap-3">
                                            {selected.case.originalManifestUrl && (
                                                <Link href={selected.case.originalManifestUrl} className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] underline underline-offset-4">
                                                    Original receiving manifest
                                                </Link>
                                            )}
                                            {selected.case.transfers.map(item =>
                                                item.url ? (
                                                    <Link key={item.reference} href={item.url} className="break-all text-xs tabular-nums text-zinc-600 hover:text-[#121316]">
                                                        {item.reference} · {label(item.status)}
                                                    </Link>
                                                ) : (
                                                    <span key={item.reference} className="break-all text-xs tabular-nums text-zinc-600">
                                                        {item.reference} · {label(item.status)}
                                                    </span>
                                                )
                                            )}
                                        </div>

                                        <div className="border-t border-zinc-100 pt-5">
                                            <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-400">Recorded history</h3>
                                            <ol className="mt-3.5 space-y-4">
                                                {selected.case.events.map(event => (
                                                    <li key={event.reference} className="border-l-2 border-zinc-200 pl-4">
                                                        <p className="text-sm font-bold text-[#121316]">{label(event.type)}</p>
                                                        <p className="mt-0.5 text-xs text-zinc-500">
                                                            {event.actor}{event.hub ? ` · ${event.hub}` : ''} · {date(event.recordedAt)}
                                                        </p>
                                                        <p className="mt-1.5 whitespace-pre-wrap text-sm text-zinc-700">{event.notes}</p>
                                                    </li>
                                                ))}
                                            </ol>
                                        </div>
                                    </div>
                                </section>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
}
