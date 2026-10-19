import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import WaybillCamera from '@/Components/WaybillCamera';

interface Hub { id: number; name: string; code: string; tier: string }
interface ReceiptCounts { expected: number; normal_received: number; corrective_expected_received: number; unlisted_received: number; outstanding: number }
interface Manifest {
    id: number; reference: string; type: string; direction: string; status: string; version: number; included_count: number;
    source: Hub; destination: Hub; vehicle_plate: string; driver_name: string;
    dispatched_at: string | null; received_at: string | null;
    recoveryUrl?: string | null;
    buyerReturnUrl?: string | null;
    receiptCounts?: ReceiptCounts;
}
interface Detail extends Manifest {
    canManage: boolean; canLoad: boolean; canReceive: boolean;
    reviewedHold?: boolean;
    parcels: Array<{ id: number; tracking_number: string; included: boolean; status: string; received_at: string | null; receipt_condition: string | null;
        corrective_receipt?: { reference: string; received_at: string } | null; handover_hold?: boolean; handover_issue_url?: string | null }>;
    correctiveReceipts?: Array<{ reference: string; tracking_number: string; original_member_id: number | null; received_at: string; received_by: string;
        original_expected_count: number; original_received_count: number }>;
    discrepancies: Array<{ id: number; reference: string; kind: string; parcel_id: number | null; barcode: string | null; reason: string; recoveryUrl?: string | null }>;
    events: Array<{ id: number; reference: string; type: string; actor: string; actor_role: string; barcode: string | null; reason: string | null; created_at: string }>;
}
interface Props {
    manifests: { data: Manifest[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    selectedManifest: Detail | null; canCreate: boolean; creationToken: string | null; commandToken: string; basePath: string;
    hubs: Hub[]; vehicles: Array<{ id: number; hub_id: number; plate_number: string; vehicle_type: string; driver_name: string }>;
    buyerReturns?: Array<{ id: number; reference: string; barcode: string; source_hub_id: number; destination_hub_id: number; weight_grams: number }>;
}
const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
const buttonClass = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
const words = (value: string) => value.replaceAll('_', ' ').replaceAll('-', ' ');
const date = (value: string) => new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

export default function Manifests({ manifests, selectedManifest: selected, canCreate, creationToken, commandToken, basePath, hubs, vehicles, buyerReturns = [] }: Props) {
    const creation = useForm({ source_hub_id: '', destination_hub_id: '', vehicle_id: '', direction: 'outbound', buyer_return_case_id: '', creation_token: creationToken ?? '' });
    const [barcode, setBarcode] = useState('');
    const [notes, setNotes] = useState('');
    const [kind, setKind] = useState('missing');
    const [parcelId, setParcelId] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const pending = useRef(false);
    const hasCorrections = Boolean(selected?.correctiveReceipts?.length);
    const receiptCounts = selected?.receiptCounts;
    useEffect(() => creation.setData('creation_token', creationToken ?? ''), [creationToken]);
    useEffect(() => { setBarcode(''); setNotes(''); setErrors({}); }, [selected?.id]);
    const sourceHub = hubs.find(hub => String(hub.id) === creation.data.source_hub_id);
    const destinationHub = hubs.find(hub => String(hub.id) === creation.data.destination_hub_id);
    const requiredVehicleType = sourceHub?.tier === 'regional_mother_hub' && destinationHub?.tier === 'regional_mother_hub' ? 'wing_truck' : 'l300_van';
    const create = (event: FormEvent) => {
        event.preventDefault();
        if (creation.processing) return;
        creation.post(basePath, { preserveScroll: true, onSuccess: () => creation.reset('source_hub_id', 'destination_hub_id', 'vehicle_id') });
    };
    const command = (action: string, fields: Record<string, string | number> = {}) => {
        if (!selected || pending.current) return;
        pending.current = true;
        setBusy(true);
        setErrors({});
        router.post(basePath + '/' + selected.id + '/' + action, { version: selected.version, request_token: commandToken, notes, ...fields }, {
            preserveScroll: true,
            onError: (value) => setErrors(value),
            onSuccess: () => { setBarcode(''); setNotes(''); },
            onFinish: () => { pending.current = false; setBusy(false); },
        });
    };
    const fieldError = (message?: string) => message ? <p role="alert" className="mt-1 text-sm text-red-700">{message}</p> : null;
    return <DashboardLayout title="Manifests">
        <Head title="Manifests" />
        <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6">
            <div><h1 className="text-2xl font-bold text-slate-900">Manifests</h1><p className="mt-1 text-sm text-slate-600">Keep each parcel linked to its actual hub transfer, vehicle, scans and receipt.</p></div>
            {canCreate && !selected && <section className="rounded-lg border border-slate-300 bg-white p-5">
                <h2 className="text-lg font-semibold text-slate-900">Prepare a draft</h2>
                <p className="mt-1 text-sm text-slate-600">Choose the next hub on the parcel route. An assigned handler scans the actual parcels before you seal the list.</p>
                <form onSubmit={create} className="mt-4 grid gap-4 sm:grid-cols-3">
                    <label className="text-sm font-semibold text-slate-800 sm:col-span-3">Transfer direction<select value={creation.data.direction} className={inputClass} onChange={event => creation.setData({ ...creation.data, direction: event.target.value, buyer_return_case_id: '', source_hub_id: '', destination_hub_id: '', vehicle_id: '' })}><option value="outbound">Delivery to buyer</option><option value="return">Failed-delivery return</option><option value="buyer_return">Buyer item return</option></select>{fieldError(creation.errors.direction)}</label>
                    {creation.data.direction === 'buyer_return' && <label className="text-sm font-semibold text-slate-800 sm:col-span-3">Accepted buyer return<select required className={inputClass} value={creation.data.buyer_return_case_id} onChange={event => { const item = buyerReturns.find(item => String(item.id) === event.target.value); creation.setData({ ...creation.data, buyer_return_case_id: event.target.value, source_hub_id: item ? String(item.source_hub_id) : '', destination_hub_id: item ? String(item.destination_hub_id) : '', vehicle_id: '' }); }}><option value="">Choose an actual received return</option>{buyerReturns.map(item => <option key={item.id} value={item.id}>{item.reference} · {item.barcode} · {(item.weight_grams / 1000).toFixed(3)} kg</option>)}</select><span className="mt-1 block text-xs font-normal text-slate-500">Each return keeps one retained manifest for its exact next leg.</span></label>}
                    <label className="text-sm font-semibold text-slate-800">Sending hub<select required disabled={creation.data.direction === 'buyer_return'} value={creation.data.source_hub_id} className={inputClass} onChange={(event) => creation.setData({ ...creation.data, source_hub_id: event.target.value, vehicle_id: '' })}><option value="">Select sending hub</option>{hubs.map(hub => <option key={hub.id} value={hub.id}>{hub.code} — {hub.name}</option>)}</select>{fieldError(creation.errors.source_hub_id)}</label>
                    <label className="text-sm font-semibold text-slate-800">Receiving hub<select required disabled={creation.data.direction === 'buyer_return'} value={creation.data.destination_hub_id} className={inputClass} onChange={(event) => creation.setData({ ...creation.data, destination_hub_id: event.target.value, vehicle_id: '' })}><option value="">Select receiving hub</option>{hubs.filter(hub => String(hub.id) !== creation.data.source_hub_id).map(hub => <option key={hub.id} value={hub.id}>{hub.code} — {hub.name}</option>)}</select>{fieldError(creation.errors.destination_hub_id)}</label>
                    <label className="text-sm font-semibold text-slate-800">Vehicle and driver<select required value={creation.data.vehicle_id} className={inputClass} onChange={(event) => creation.setData('vehicle_id', event.target.value)}><option value="">Select source-hub vehicle</option>{vehicles.filter(vehicle => String(vehicle.hub_id) === creation.data.source_hub_id && vehicle.vehicle_type === requiredVehicleType).map(vehicle => <option key={vehicle.id} value={vehicle.id}>{vehicle.plate_number} — {vehicle.driver_name}</option>)}</select>{fieldError(creation.errors.vehicle_id)}</label>
                    <div className="sm:col-span-3">{fieldError(creation.errors.creation_token)}{Object.entries(creation.errors).filter(([key]) => !['source_hub_id', 'destination_hub_id', 'vehicle_id', 'creation_token'].includes(key)).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-700">{message}</p>)}<button disabled={creation.processing} className="mt-2 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Prepare manifest</button><p className="mt-2 text-sm text-slate-600">Feeder runs use a closed van; Mother-to-Mother line-haul uses a wing truck. The source-hub vehicle needs an active, available assigned driver.</p></div>
                </form>
            </section>}
            <div className="grid gap-6 lg:grid-cols-[minmax(240px,1fr)_minmax(0,2fr)]">
                <section className="rounded-lg border border-slate-300 bg-white p-4">
                    <div className="flex items-center justify-between gap-3"><h2 className="font-semibold text-slate-900">Recorded transfers</h2>{selected && <Link href={basePath} className="text-sm font-semibold text-[#E00D42]">All manifests</Link>}</div>
                    {!manifests.data.length && <p className="mt-4 text-sm text-slate-600">No manifests have been recorded within your scope.</p>}
                    <div className="mt-3 space-y-2">{manifests.data.map(manifest => <Link key={manifest.id} href={basePath + '/' + manifest.id} className={'block rounded-lg border p-3 ' + (selected?.id === manifest.id ? 'border-[#E00D42] bg-[#FFFAFB]' : 'border-slate-300 hover:bg-slate-50')}>
                        <p className="break-all text-sm font-semibold text-slate-900">{manifest.reference}</p><p className="mt-1 text-sm text-slate-700">{manifest.source.code} → {manifest.destination.code}</p><p className="mt-1 text-xs capitalize text-slate-600">{words(manifest.direction)} · {words(manifest.status)} · {manifest.included_count} parcels · {manifest.vehicle_plate}</p>
                    </Link>)}</div>
                    <nav aria-label="Manifest pages" className="mt-4 flex flex-wrap gap-2">{manifests.links.filter(link => link.url).map((link, index) => <Link key={index} href={link.url!} aria-current={link.active ? 'page' : undefined} className={buttonClass}>{link.label.replace(/&laquo;|&raquo;/g, '').trim()}</Link>)}</nav>
                </section>
                {selected ? <section className="min-w-0 space-y-4 rounded-lg border border-slate-300 bg-white p-5">
                    <div><p className="text-sm font-semibold capitalize text-[#E00D42]">{words(selected.status)} · {words(selected.direction)} · {words(selected.type)}</p><h2 className="mt-1 break-all text-lg font-semibold text-slate-900">{selected.reference}</h2><p className="mt-2 text-sm text-slate-700">{selected.source.name} → {selected.destination.name}</p><p className="mt-1 text-sm text-slate-600">{selected.vehicle_plate} · {selected.driver_name}</p>{selected.buyerReturnUrl && <Link href={selected.buyerReturnUrl} className="mt-3 inline-flex text-sm font-semibold text-[#C20836]">Open buyer return</Link>}{selected.dispatched_at && <p className="mt-1 text-sm text-slate-600">Departed {date(selected.dispatched_at)}</p>}{selected.received_at && <p className="mt-1 text-sm text-slate-600">All parcels received {date(selected.received_at)}</p>}</div>
                    {selected.recoveryUrl && <Link href={selected.recoveryUrl} className="inline-block text-sm font-semibold text-[#E00D42]">View this parcel recovery case</Link>}
                    {selected.reviewedHold && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">A reviewed handover is linked to this manifest. List changes, departure and closure stay paused until its actual recovery receipt. Other unheld expected parcels may still be received.</p>}
                    {receiptCounts && <dl aria-label="Actual manifest receipts" className="grid grid-cols-2 gap-3 rounded-lg border border-slate-300 bg-slate-50 p-4 sm:grid-cols-5">
                        {[['Original expected', receiptCounts.expected], ['Recorded receipts', receiptCounts.normal_received],
                            ['Corrective receipts', receiptCounts.corrective_expected_received], ['Unlisted receipts', receiptCounts.unlisted_received],
                            ['Still awaiting', receiptCounts.outstanding]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 text-lg font-bold text-slate-900">{value}</dd></div>)}
                    </dl>}
                    {hasCorrections && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">Corrective receipts record current arrival. The original parcel list and any missing departure records are retained. Unlisted receipts stay separate; unresolved parcels and discrepancies still need their own evidence.</p>}
                    {Object.entries(errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-700">{message}</p>)}
                    {((selected.status === 'draft' && selected.canLoad) || (['dispatched', 'received'].includes(selected.status) && selected.canReceive)) && <WaybillCamera key={selected.id + ':' + selected.status} onScan={setBarcode} disabled={busy} />}
                    {selected.status === 'draft' && selected.canLoad && !hasCorrections && !selected.reviewedHold && <form onSubmit={event => { event.preventDefault(); command('load', { barcode }); }} className="flex flex-wrap items-end gap-3">
                        <label className="min-w-0 flex-1 text-sm font-semibold text-slate-800">Actual outbound waybill<input required maxLength={255} disabled={busy} autoComplete="off" spellCheck={false} className={inputClass} value={barcode} onChange={event => setBarcode(event.target.value)} /></label><button disabled={busy} className={buttonClass}>Record outbound scan</button>
                    </form>}
                    {selected.status === 'dispatched' && selected.canReceive && <form onSubmit={event => { event.preventDefault(); command('receive', { barcode, condition: 'intact' }); }} className="flex flex-wrap items-end gap-3">
                        <label className="min-w-0 flex-1 text-sm font-semibold text-slate-800">Actual arriving waybill<input required maxLength={255} disabled={busy} autoComplete="off" spellCheck={false} className={inputClass} value={barcode} onChange={event => setBarcode(event.target.value)} /></label><button disabled={busy} className={buttonClass}>Record parcel receipt</button>
                    </form>}
                    {selected.status !== 'closed' && (selected.canManage || selected.canLoad || selected.canReceive) && <label className="block text-sm font-semibold text-slate-800">Notes or correction reason<textarea maxLength={1000} disabled={busy} rows={2} className={inputClass} value={notes} onChange={event => setNotes(event.target.value)} /></label>}
                    <div className="flex flex-wrap gap-2">
                        {selected.canManage && selected.status === 'draft' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !selected.included_count} onClick={() => command('seal')} className={buttonClass}>Seal parcel list</button>}
                        {selected.canManage && selected.status === 'sealed' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !notes.trim()} onClick={() => command('reopen')} className={buttonClass}>Reopen with reason</button>}
                        {selected.canLoad && selected.status === 'sealed' && !hasCorrections && !selected.reviewedHold && <button disabled={busy} onClick={() => command('dispatch')} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Record departure</button>}
                        {selected.canManage && selected.status === 'received' && <button disabled={busy || selected.discrepancies.length > 0 || selected.reviewedHold} onClick={() => command('close')} className={buttonClass}>Close received manifest</button>}
                        {selected.canManage && selected.status !== 'closed' && hasCorrections && <button disabled={busy || !notes.trim() || selected.discrepancies.length > 0 || receiptCounts?.outstanding !== 0 || selected.reviewedHold} onClick={() => command('close-recovery')} className={buttonClass}>Close reviewed recovery</button>}
                    </div>
                    {(['dispatched', 'received'].includes(selected.status) || (hasCorrections && selected.status !== 'closed')) && selected.canReceive && <section className="rounded-lg border border-slate-300 p-4">
                        <h3 className="font-semibold text-slate-900">Record an arrival discrepancy</h3>
                        <p className="mt-1 text-sm text-slate-600">An observation preserves the current parcel state. Missing and damaged parcels need a later actual receipt. An unexpected physical parcel stays unresolved until supported handling is recorded.</p>
                        <form className="mt-3 space-y-3" onSubmit={event => { event.preventDefault(); command('report-discrepancy', { kind, ...(kind === 'missing' ? { parcel_id: parcelId } : { barcode }) }); }}>
                            <label className="block text-sm font-semibold text-slate-800">Observed issue<select className={inputClass} value={kind} disabled={busy} onChange={event => setKind(event.target.value)}>{['missing', 'extra', 'duplicate', 'damaged', 'wrong_hub'].map(value => <option key={value} value={value}>{words(value)}</option>)}</select></label>
                            {kind === 'missing' ? <label className="block text-sm font-semibold text-slate-800">Expected parcel<select required className={inputClass} value={parcelId} disabled={busy} onChange={event => setParcelId(event.target.value)}><option value="">Choose missing parcel</option>{selected.parcels.filter(parcel => parcel.included && !parcel.received_at && !parcel.corrective_receipt).map(parcel => <option key={parcel.id} value={parcel.id}>{parcel.tracking_number}</option>)}</select></label> : <label className="block text-sm font-semibold text-slate-800">Actual observed waybill<input required maxLength={255} className={inputClass} disabled={busy} autoComplete="off" spellCheck={false} value={barcode} onChange={event => setBarcode(event.target.value)} /></label>}
                            <button disabled={busy || !notes.trim()} className={buttonClass}>Record discrepancy with reason</button>
                        </form>
                    </section>}
                    {selected.discrepancies.length > 0 && <section aria-label="Unresolved discrepancies" className="rounded-lg border border-amber-300 bg-amber-50 p-4"><h3 className="font-semibold text-slate-900">Unresolved discrepancies</h3><ul className="mt-3 space-y-3">{selected.discrepancies.map(issue => <li key={issue.id} className="border-b border-amber-200 pb-3"><p className="text-sm font-semibold capitalize">{words(issue.kind)}{issue.barcode && ' · ' + issue.barcode}</p><p className="mt-1 text-sm">{issue.reason}</p><p className="mt-1 break-all text-xs text-slate-600">{issue.reference}</p><div className="mt-2 flex flex-wrap gap-2">{issue.recoveryUrl && <Link href={issue.recoveryUrl} className={buttonClass}>Review actual parcel recovery</Link>}{selected.canReceive && ['extra', 'wrong_hub'].includes(issue.kind) && <button disabled={busy || !notes.trim() || !barcode.trim()} className={buttonClass} onClick={() => command('correct-discrepancy', { source_event_id: issue.id, barcode })}>Record corrected waybill</button>}{selected.canManage && <button disabled={busy || !notes.trim()} className={buttonClass} onClick={() => command('resolve-discrepancy', { source_event_id: issue.id })}>Resolve using supporting receipt</button>}</div></li>)}</ul><p className="mt-3 text-sm text-slate-700">Use a recording correction only when the original waybill entry was wrong. It requires the correctly identified parcel's actual receipt and an assigned receiving handler's reason. A note alone never resolves missing custody.</p></section>}
                    <p className="text-sm text-slate-600">The sealed list cannot change. Preparation and assignment do not transfer parcel custody; recorded departure and receiving scans establish each handoff.</p>
                    <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="mb-2 text-left font-semibold text-slate-900">Parcel list</caption><thead><tr className="border-b border-slate-300 text-slate-700"><th className="py-2 pr-3">Waybill</th><th className="py-2 pr-3">Current state</th><th className="py-2">Receipt</th></tr></thead><tbody>
                        {selected.parcels.map(parcel => <tr key={parcel.id} className="border-b border-slate-200"><td className="py-3 pr-3"><p className="break-all font-semibold">{parcel.tracking_number}</p>{!parcel.included && <p className="text-xs text-slate-600">Removed from draft</p>}{parcel.included && selected.canManage && selected.status === 'draft' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !notes.trim()} onClick={() => command('remove', { parcel_id: parcel.id })} className="mt-1 text-xs font-semibold text-[#E00D42] disabled:opacity-50">Remove with reason</button>}{parcel.handover_hold && <p className="mt-1 text-xs font-semibold text-amber-800">Awaiting supervised recovery</p>}{parcel.handover_issue_url && <Link href={parcel.handover_issue_url} className="mt-1 inline-block text-xs font-semibold text-[#E00D42]">Review handover</Link>}</td><td className="py-3 pr-3 capitalize">{words(parcel.status)}</td><td className="py-3">{parcel.received_at ? words(parcel.receipt_condition ?? 'received') + ' · ' + date(parcel.received_at) : parcel.corrective_receipt ? 'Corrective receipt · ' + date(parcel.corrective_receipt.received_at) : 'Awaiting receipt'}</td></tr>)}
                        {!selected.parcels.length && <tr><td colSpan={3} className="py-4 text-slate-600">{selected.direction === 'buyer_return' ? 'No return parcel has been loaded. Scan its separate return barcode before sealing this draft.' : 'No original outbound parcels were recorded. Any unlisted corrective receipts are shown separately.'}</td></tr>}
                    </tbody></table></div>
                    {hasCorrections && <section aria-label="Corrective receipt evidence" className="rounded-lg border border-slate-300 p-4">
                        <h3 className="font-semibold text-slate-900">Corrective receipt evidence</h3><ul className="mt-3 space-y-3">{selected.correctiveReceipts?.map(receipt => <li key={receipt.reference} className="border-b border-slate-200 pb-3">
                            <p className="break-all text-sm font-semibold">{receipt.tracking_number}</p><p className="mt-1 text-sm text-slate-700">{receipt.original_member_id === null ? 'Unlisted receipt — original expected count unchanged' : 'Receipt for an original expected parcel'}</p>
                            <p className="mt-1 text-xs text-slate-600">{receipt.received_by} · {date(receipt.received_at)} · Manila time</p><p className="mt-1 text-xs text-slate-600">Before this correction: {receipt.original_received_count} standard receipts / {receipt.original_expected_count} originally expected</p>
                            <p className="mt-1 break-all text-xs text-slate-600">{receipt.reference}</p>
                        </li>)}</ul>
                    </section>}
                    <div><h3 className="font-semibold text-slate-900">Retained history</h3><ol className="mt-3 space-y-3">{selected.events.map(event => <li key={event.id} className="border-l-2 border-slate-300 pl-3"><p className="text-sm font-semibold capitalize text-slate-900">{words(event.type)} · {event.actor}</p><p className="mt-1 text-xs text-slate-600">{date(event.created_at)} · {event.reference}</p>{event.barcode && <p className="mt-1 break-all text-sm text-slate-700">Submitted code: {event.barcode}</p>}{event.reason && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{event.reason}</p>}</li>)}</ol></div>
                </section> : <section className="rounded-lg border border-slate-300 bg-white p-5"><h2 className="font-semibold text-slate-900">Review a transfer</h2><p className="mt-2 text-sm text-slate-600">Select a recorded manifest to review its parcel list, vehicle, scans and custody history. Company administrators prepare and seal lists; assigned hub handlers record physical scans.</p></section>}
            </div>
        </div>
    </DashboardLayout>;
}
