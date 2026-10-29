import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { ArrowRight, CheckCircle2, Container, Package, ScanLine, Truck, Undo2 } from 'lucide-react';
import { HubBadge, HubEmpty, HubNextStep, HubPageHeader, HubRoute, HubSearch, HubStepRail, hubButton, type HubPlace } from '@/Components/Logistics/Workspace';
import HubPicker from '@/Components/Logistics/HubPicker';
import VehiclePicker from '@/Components/Logistics/VehiclePicker';
import HubMap from '@/Components/Logistics/HubMap';
import { nextTransferHubs, transferSteps } from '@/utils/hubWorkspace';
import { QRCodeSVG } from 'qrcode.react';
import WaybillCamera from '@/Components/WaybillCamera';

interface Hub extends HubPlace { id: number; name: string; code: string; tier: string }
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
    motherBags?: { id: string; reference: string; seal_number: string; status: string }[];
    canManage: boolean; canClose: boolean; canLoad: boolean; canReceive: boolean;
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
    sourceHubIds?: number[]; hubs: Hub[]; vehicles: Array<{ id: number; hub_id: number; plate_number: string; vehicle_type: string; driver_name: string; capacity_kg?: number }>;
    buyerReturns?: Array<{ id: number; reference: string; barcode: string; source_hub_id: number; destination_hub_id: number; weight_grams: number }>;
}
const inputClass = 'mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-[#E00D42] focus:ring-[#E00D42]';
const buttonClass = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50';
const words = (value: string) => value.replaceAll('_', ' ').replaceAll('-', ' ');
const date = (value: string) => new Date(value).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

export default function Manifests({ manifests, selectedManifest: selected, canCreate, creationToken, commandToken, basePath, hubs, vehicles, sourceHubIds = [], buyerReturns = [] }: Props) {
    const creation = useForm({ source_hub_id: sourceHubIds.length === 1 ? String(sourceHubIds[0]) : '', destination_hub_id: '', vehicle_id: '', direction: 'outbound', buyer_return_case_id: '', creation_token: creationToken ?? '' });
    const [barcode, setBarcode] = useState('');
    const [search, setSearch] = useState('');
    const [returnSearch, setReturnSearch] = useState('');
    const selectReturn = (item: NonNullable<Props['buyerReturns']>[number]) => creation.setData({ ...creation.data, buyer_return_case_id: String(item.id), source_hub_id: String(item.source_hub_id), destination_hub_id: String(item.destination_hub_id), vehicle_id: '' });
    const visibleManifests = manifests.data.filter(item => [item.reference, item.source.name, item.destination.name, item.vehicle_plate, item.driver_name, item.status].join(' ').toLowerCase().includes(search.toLowerCase()));
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
    const sendingHubs = hubs.filter(hub => sourceHubIds.includes(hub.id));
    const requiredVehicleType = sourceHub?.tier === 'regional_mother_hub' && destinationHub?.tier === 'regional_mother_hub' ? 'wing_truck' : 'l300_van';
    const availableVehicles = vehicles.filter(vehicle => String(vehicle.hub_id) === creation.data.source_hub_id && vehicle.vehicle_type === requiredVehicleType);
    const receivingHubs = nextTransferHubs(hubs, sourceHub);
    const nextTitle = !selected ? '' : selected.status === 'closed' ? 'Transfer complete' : selected.reviewedHold ? 'Resolve the linked handover first' : selected.status === 'draft' ? (selected.included_count ? 'Check the loaded parcels, then seal the list' : selected.direction === 'buyer_return' ? 'Sending handler: scan the separate return barcode' : selected.direction === 'recovery' ? 'Sending handler: scan the authorized recovery parcel' : 'Load a sealed bag before departure') : selected.status === 'sealed' ? 'Sending handler: scan and record departure' : selected.status === 'dispatched' ? 'Receiving handler: verify bags, then scan each parcel' : 'Receiving manager: close the completed transfer';
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
        <div className="space-y-6">
            <HubPageHeader title="Transfers & manifests" description="One manifest is one vehicle trip to the next hub. Choose the route, load sealed bags and receive each arriving parcel." icon={Truck} actions={<Link className={hubButton} href={route('hub.mother-bags.index')}><Container className="h-4 w-4" />Mother Bags</Link>} />
            {selected && <><HubStepRail steps={transferSteps(selected.status, selected.included_count > 0)} /><HubNextStep title={nextTitle} tone={selected.status === 'closed' ? 'success' : selected.reviewedHold ? 'warning' : 'brand'} icon={selected.status === 'closed' ? CheckCircle2 : ArrowRight}><span>{selected.status === 'closed' ? 'All required receiving evidence is retained below.' : `${selected.source.name} → ${selected.destination.name}. Preparation does not move a parcel; the actual scan confirms each handover.`}</span></HubNextStep></>}
            {canCreate && !selected && <section className="rounded-xl border border-slate-300 bg-white p-5 shadow-sm">
                <h2 className="text-lg font-semibold text-slate-900">Prepare a draft</h2>
                <p className="mt-1 text-sm text-slate-600">Choose the next hub and assigned vehicle, then load the sealed master bag in Mother Bags.</p>
                <form onSubmit={create} className="mt-4 grid gap-4 sm:grid-cols-3">
                    <fieldset className="sm:col-span-3"><legend className="mb-2 text-xs font-semibold text-slate-700">What is moving?</legend><div className="grid gap-2 sm:grid-cols-3">{[{value:'outbound',title:'Delivery to buyer',detail:'Normal hub-to-hub transfer',icon:Truck},{value:'return',title:'Failed-delivery return',detail:'Follow the saved reverse route',icon:Undo2},{value:'buyer_return',title:'Buyer item return',detail:'A separate accepted return',icon:Package}].map(option => <label key={option.value} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 focus-within:ring-2 focus-within:ring-[#E00D42]/20 ${creation.data.direction === option.value ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}><input type="radio" name="transfer-direction" value={option.value} checked={creation.data.direction === option.value} onChange={() => creation.setData({...creation.data,direction:option.value,buyer_return_case_id:'',source_hub_id:sourceHubIds.length === 1 ? String(sourceHubIds[0]) : '',destination_hub_id:'',vehicle_id:''})} className="sr-only" /><option.icon className={`mt-0.5 h-4 w-4 shrink-0 ${creation.data.direction === option.value ? 'text-[#E00D42]' : 'text-slate-400'}`} /><span><span className="block text-xs font-semibold text-slate-900">{option.title}</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">{option.detail}</span></span></label>)}</div>{fieldError(creation.errors.direction)}</fieldset>
                    {creation.data.direction === 'buyer_return' && <fieldset className="space-y-3 sm:col-span-3"><legend className="mb-2 text-xs font-semibold text-slate-700">Accepted buyer return</legend><div onKeyDown={event => { if (event.key === 'Enter') event.preventDefault(); }}><HubSearch label="Find a received return" value={returnSearch} placeholder="Scan return barcode or search" onChange={value => { setReturnSearch(value);const match = buyerReturns.find(item => item.barcode.toUpperCase() === value.trim().toUpperCase());if (match) selectReturn(match); }} /></div><div className="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-2">{buyerReturns.filter(item => `${item.reference} ${item.barcode}`.toLowerCase().includes(returnSearch.toLowerCase())).map(item => <label key={item.id} className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3.5 ${creation.data.buyer_return_case_id === String(item.id) ? 'border-rose-300 bg-rose-50/50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}><input type="radio" required name="buyer-return-case" value={item.id} checked={creation.data.buyer_return_case_id === String(item.id)} onChange={() => selectReturn(item)} className="mt-1 border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><Package className="mt-1 h-4 w-4 shrink-0 text-slate-400" /><span className="min-w-0"><span className="block text-sm font-semibold text-slate-900">Return #{item.id}</span><span className="mt-1 block break-words text-xs text-slate-600">{item.barcode}</span><span className="mt-1 block text-[11px] text-slate-500">{item.reference} · {(item.weight_grams / 1000).toFixed(3)} kg</span></span></label>)}</div>{!buyerReturns.length && <p className="rounded-lg border border-dashed border-slate-200 p-4 text-xs leading-6 text-slate-500">No received buyer returns need a transfer from your sending hubs.</p>}<p className="text-xs leading-5 text-slate-500">Scanning selects the return. Choose its vehicle and prepare its exact next leg below.</p>{fieldError(creation.errors.buyer_return_case_id)}</fieldset>}
                    <div className="grid gap-5 sm:col-span-3 lg:grid-cols-[minmax(0,1fr)_300px]">
                        <div className="space-y-5"><div className="grid items-start gap-4 sm:grid-cols-2">
                            <HubPicker label="Sending hub" hubs={sendingHubs} value={creation.data.source_hub_id} disabled={creation.data.direction === 'buyer_return'} onChange={id => creation.setData({...creation.data,source_hub_id:id,destination_hub_id:'',vehicle_id:''})} error={creation.errors.source_hub_id} />
                            <HubPicker label="Receiving hub" hubs={receivingHubs} value={creation.data.destination_hub_id} disabled={!sourceHub || creation.data.direction === 'buyer_return'} onChange={id => creation.setData({...creation.data,destination_hub_id:id,vehicle_id:''})} error={creation.errors.destination_hub_id} />
                        </div>
                        {sourceHub && destinationHub && <HubRoute stops={[sourceHub,destinationHub]} label="Selected transfer route" />}
                        {sourceHub && destinationHub ? availableVehicles.length ? <VehiclePicker vehicles={availableVehicles} value={creation.data.vehicle_id} onChange={id => creation.setData('vehicle_id',id)} error={creation.errors.vehicle_id} /> : <HubNextStep title={`No available ${requiredVehicleType === 'wing_truck' ? 'wing truck' : 'closed van'} at this sending hub`} tone="warning"><p>An eligible assigned driver and vehicle are required. Ask the company manager to review Fleet & drivers.</p></HubNextStep> : <p className="rounded-lg border border-dashed border-slate-200 p-4 text-xs leading-6 text-slate-500">Choose the sending and receiving hubs to see vehicles available for that exact leg.</p>}</div>
                        <div className="space-y-2"><HubMap hubs={hubs} routeIds={sourceHub && destinationHub ? [sourceHub.id,destinationHub.id] : undefined} selectedId={destinationHub?.id ?? sourceHub?.id} onSelect={hub => { if(creation.data.direction === 'buyer_return')return;if(sourceHub && receivingHubs.some(item => item.id === hub.id))creation.setData({...creation.data,destination_hub_id:String(hub.id),vehicle_id:''});else if(sendingHubs.some(item => item.id === hub.id))creation.setData({...creation.data,source_hub_id:String(hub.id),destination_hub_id:'',vehicle_id:''}); }} className="h-64" /><p className="text-[11px] leading-5 text-slate-500">Choose a hub pin or use the searchable fields. Only a permitted next hub can be selected.</p></div>
                    </div>
                    <div className="sm:col-span-3">{fieldError(creation.errors.creation_token)}{Object.entries(creation.errors).filter(([key]) => !['source_hub_id', 'destination_hub_id', 'vehicle_id', 'creation_token'].includes(key)).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-700">{message}</p>)}<button disabled={creation.processing || !sourceHub || !destinationHub || !creation.data.vehicle_id || !availableVehicles.length} className="mt-2 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Prepare manifest</button><p className="mt-2 text-sm text-slate-600">Feeder runs use a closed van; Mother-to-Mother line-haul uses a wing truck. The source-hub vehicle needs an active, available assigned driver.</p></div>
                </form>
            </section>}
            <div className="grid gap-6 lg:grid-cols-[minmax(240px,1fr)_minmax(0,2fr)]">
                <section className="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
                    <div className="flex items-center justify-between gap-3"><h2 className="font-semibold text-slate-900">Recorded transfers</h2>{selected && <Link href={basePath} className="text-sm font-semibold text-[#E00D42]">All manifests</Link>}</div>
                    {!manifests.data.length && <p className="mt-4 text-sm text-slate-600">No manifests have been recorded within your scope.</p>}
                    <div className="mt-3"><HubSearch label="Search loaded transfers" value={search} onChange={setSearch} placeholder="Hub, plate or manifest" /></div><div className="mt-3 space-y-2">{visibleManifests.map(manifest => <Link key={manifest.id} href={basePath + '/' + manifest.id} className={'block rounded-lg border p-3 ' + (selected?.id === manifest.id ? 'border-[#E00D42] bg-[#FFFAFB]' : 'border-slate-300 hover:bg-slate-50')}>
                        <div className="flex items-center justify-between gap-2"><span className="text-[11px] font-medium text-slate-500">Transfer #{manifest.id}</span><HubBadge tone={manifest.status === 'closed' ? 'success' : manifest.status === 'dispatched' ? 'brand' : 'neutral'}>{words(manifest.status)}</HubBadge></div><p className="mt-2 text-sm font-semibold leading-6 text-slate-900">{manifest.source.name}<ArrowRight className="mx-1 inline h-3.5 w-3.5 text-slate-400" />{manifest.destination.name}</p><p className="mt-2 text-xs text-slate-500">{manifest.vehicle_plate} · {manifest.included_count} parcels</p><p title={manifest.reference} className="mt-1 truncate text-[10px] text-slate-400">{manifest.reference}</p>
                    </Link>)}</div>
                    {!visibleManifests.length && manifests.data.length > 0 && <p className="py-6 text-center text-xs text-slate-500">No matches on this page. Try a hub name or vehicle plate.</p>}
                    {manifests.links.filter(link => link.url).length > 1 && <nav aria-label="Manifest pages" className="mt-4 flex flex-wrap gap-2">{manifests.links.filter(link => link.url).map((link, index) => <Link key={index} href={link.url!} aria-current={link.active ? 'page' : undefined} className={buttonClass}>{link.label.replace(/&laquo;|&raquo;/g, '').trim()}</Link>)}</nav>}
                </section>
                {selected ? <section className="min-w-0 space-y-4 rounded-xl border border-slate-300 bg-white p-5 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3"><div><div className="flex flex-wrap items-center gap-2"><HubBadge tone={selected.status === 'closed' ? 'success' : selected.status === 'dispatched' ? 'brand' : 'neutral'}>{words(selected.status)}</HubBadge><span className="text-xs capitalize text-slate-500">{words(selected.direction)} · {words(selected.type)}</span></div><h2 className="mt-3 text-base font-semibold text-slate-900">Transfer #{selected.id}</h2><details className="mt-2 text-xs text-slate-500"><summary className="w-fit cursor-pointer font-medium text-[#C20836]">Transfer reference &amp; QR</summary><div className="mt-3 w-fit max-w-full rounded-lg border border-slate-200 bg-white p-3"><QRCodeSVG value={selected.reference} size={96} /><p className="mt-3 break-all text-[11px] text-slate-600">{selected.reference}</p></div></details><p className="mt-2 flex items-center gap-2 text-xs text-slate-500"><Truck className="h-3.5 w-3.5" />{selected.vehicle_plate} · {selected.driver_name}</p>{selected.dispatched_at && <p className="mt-1 text-xs text-slate-500">Departed {date(selected.dispatched_at)}</p>}{selected.received_at && <p className="mt-1 text-xs text-slate-500">Received {date(selected.received_at)}</p>}</div>{selected.buyerReturnUrl && <Link href={selected.buyerReturnUrl} className={hubButton}>Open buyer return</Link>}</div>
                    <HubRoute stops={[selected.source,selected.destination]} />
                    {selected.recoveryUrl && <Link href={selected.recoveryUrl} className="inline-block text-sm font-semibold text-[#E00D42]">View this parcel recovery case</Link>}
                    {selected.reviewedHold && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">A reviewed handover is linked to this manifest. List changes, departure and closure stay paused until its actual recovery receipt. Other unheld expected parcels may still be received.</p>}
                    {receiptCounts && <dl aria-label="Actual manifest receipts" className="grid grid-cols-2 gap-3 rounded-lg border border-slate-300 bg-slate-50 p-4 sm:grid-cols-5">
                        {[['Original expected', receiptCounts.expected], ['Recorded receipts', receiptCounts.normal_received],
                            ['Corrective receipts', receiptCounts.corrective_expected_received], ['Unlisted receipts', receiptCounts.unlisted_received],
                            ['Still awaiting', receiptCounts.outstanding]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 text-lg font-bold text-slate-900">{value}</dd></div>)}
                    </dl>}
                    {hasCorrections && <p role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm leading-relaxed text-amber-950">Corrective receipts record current arrival. The original parcel list and any missing departure records are retained. Unlisted receipts stay separate; unresolved parcels and discrepancies still need their own evidence.</p>}
                    {selected.direction === 'outbound' || selected.direction === 'return' ? <section className="rounded-lg border border-slate-300 bg-slate-50/70 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900"><Container className="h-4 w-4" />Sealed Mother Bags</h3><p className="mt-1 text-xs leading-5 text-slate-500">{selected.status === 'draft' ? 'New-flow parcels load together when their sealed master tag is scanned in Mother Bags.' : 'Verify each arriving bag seal in Mother Bags before scanning its individual parcels here.'}</p></div><Link href={route('hub.mother-bags.index')} className={hubButton}>Open bag workspace</Link></div>
                        {!!selected.motherBags?.length && <ul className="mt-3 divide-y divide-slate-200">{selected.motherBags.map(bag => <li key={bag.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><Link href={route('hub.mother-bags.index', { bag: bag.id })} className="text-sm font-semibold text-[#E00D42] underline underline-offset-4">{bag.reference} · Seal {bag.seal_number}</Link><HubBadge tone={bag.status === 'opened' || bag.status === 'received' ? 'success' : 'neutral'}>{words(bag.status)}</HubBadge></li>)}</ul>}
                    </section> : null}
                    {Object.entries(errors).map(([key, message]) => <p key={key} role="alert" className="text-sm text-red-700">{message}</p>)}
                    {((selected.status === 'draft' && selected.canLoad) || (['dispatched', 'received'].includes(selected.status) && selected.canReceive)) && <WaybillCamera key={selected.id + ':' + selected.status} onScan={setBarcode} disabled={busy} />}
                    {selected.status === 'draft' && selected.canLoad && !hasCorrections && !selected.reviewedHold && <details open={selected.direction === 'buyer_return' || Boolean(selected.recoveryUrl)} className="rounded-lg border border-slate-300 p-4"><summary className="cursor-pointer text-sm font-semibold text-slate-700">Individual loading for historical parcels or recovery</summary><p className="my-3 text-xs text-slate-500">Use Mother Bags for new-flow hub transfers. Separate buyer returns and reviewed recovery retain individual scans.</p><form onSubmit={event => { event.preventDefault(); command('load', { barcode }); }} className="flex flex-wrap items-end gap-3">
                        <label className="min-w-0 flex-1 text-sm font-semibold text-slate-800">Actual outbound waybill<input required maxLength={255} disabled={busy} autoComplete="off" spellCheck={false} className={inputClass} value={barcode} onChange={event => setBarcode(event.target.value)} /></label><button disabled={busy} className={buttonClass}>Record outbound scan</button>
                    </form></details>}
                    {selected.status === 'dispatched' && selected.canReceive && <form onSubmit={event => { event.preventDefault(); command('receive', { barcode, condition: 'intact' }); }} className="flex flex-wrap items-end gap-3">
                        <label className="min-w-0 flex-1 text-sm font-semibold text-slate-800">Actual arriving waybill<input required maxLength={255} disabled={busy} autoComplete="off" spellCheck={false} className={inputClass} value={barcode} onChange={event => setBarcode(event.target.value)} /></label><button disabled={busy} className={buttonClass}>Record parcel receipt</button>
                    </form>}
                    {selected.status !== 'closed' && (selected.canManage || selected.canLoad || selected.canReceive) && <label className="block text-sm font-semibold text-slate-800">Notes or correction reason<textarea maxLength={1000} disabled={busy} rows={2} className={inputClass} value={notes} onChange={event => setNotes(event.target.value)} /></label>}
                    <div className="flex flex-wrap gap-2">
                        {selected.canManage && selected.status === 'draft' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !selected.included_count} onClick={() => command('seal')} className={buttonClass}>Seal parcel list</button>}
                        {selected.canManage && selected.status === 'sealed' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !notes.trim()} onClick={() => command('reopen')} className={buttonClass}>Reopen with reason</button>}
                        {selected.canLoad && selected.status === 'sealed' && !hasCorrections && !selected.reviewedHold && <button disabled={busy} onClick={() => command('dispatch')} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Record departure</button>}
                        {selected.canClose && selected.status === 'received' && <button disabled={busy || selected.discrepancies.length > 0 || selected.reviewedHold} onClick={() => command('close')} className={buttonClass}>Close received manifest</button>}
                        {selected.canClose && selected.status !== 'closed' && hasCorrections && <button disabled={busy || !notes.trim() || selected.discrepancies.length > 0 || receiptCounts?.outstanding !== 0 || selected.reviewedHold} onClick={() => command('close-recovery')} className={buttonClass}>Close reviewed recovery</button>}
                    </div>
                    {(['dispatched', 'received'].includes(selected.status) || (hasCorrections && selected.status !== 'closed')) && selected.canReceive && <details className="rounded-lg border border-slate-200 bg-slate-50/50 p-4">
                        <summary className="cursor-pointer text-sm font-semibold text-slate-700">Report an arrival problem</summary><div className="mt-4">
                        <p className="mt-1 text-sm text-slate-600">An observation preserves the current parcel state. Missing and damaged parcels need a later actual receipt. An unexpected physical parcel stays unresolved until supported handling is recorded.</p>
                        <form className="mt-3 space-y-3" onSubmit={event => { event.preventDefault(); command('report-discrepancy', { kind, ...(kind === 'missing' ? { parcel_id: parcelId } : { barcode }) }); }}>
                            <label className="block text-sm font-semibold text-slate-800">Observed issue<select className={inputClass} value={kind} disabled={busy} onChange={event => setKind(event.target.value)}>{['missing', 'extra', 'duplicate', 'damaged', 'wrong_hub'].map(value => <option key={value} value={value}>{words(value)}</option>)}</select></label>
                            {kind === 'missing' ? <label className="block text-sm font-semibold text-slate-800">Expected parcel<select required className={inputClass} value={parcelId} disabled={busy} onChange={event => setParcelId(event.target.value)}><option value="">Choose missing parcel</option>{selected.parcels.filter(parcel => parcel.included && !parcel.received_at && !parcel.corrective_receipt).map(parcel => <option key={parcel.id} value={parcel.id}>{parcel.tracking_number}</option>)}</select></label> : <label className="block text-sm font-semibold text-slate-800">Actual observed waybill<input required maxLength={255} className={inputClass} disabled={busy} autoComplete="off" spellCheck={false} value={barcode} onChange={event => setBarcode(event.target.value)} /></label>}
                            <button disabled={busy || !notes.trim()} className={buttonClass}>Record discrepancy with reason</button>
                        </form>
                    </div></details>}
                    {selected.discrepancies.length > 0 && <section aria-label="Unresolved discrepancies" className="rounded-lg border border-amber-300 bg-amber-50 p-4"><h3 className="font-semibold text-slate-900">Unresolved discrepancies</h3><ul className="mt-3 space-y-3">{selected.discrepancies.map(issue => <li key={issue.id} className="border-b border-amber-200 pb-3"><p className="text-sm font-semibold capitalize">{words(issue.kind)}{issue.barcode && ' · ' + issue.barcode}</p><p className="mt-1 text-sm">{issue.reason}</p><p className="mt-1 break-all text-xs text-slate-600">{issue.reference}</p><div className="mt-2 flex flex-wrap gap-2">{issue.recoveryUrl && <Link href={issue.recoveryUrl} className={buttonClass}>Review actual parcel recovery</Link>}{selected.canReceive && ['extra', 'wrong_hub'].includes(issue.kind) && <button disabled={busy || !notes.trim() || !barcode.trim()} className={buttonClass} onClick={() => command('correct-discrepancy', { source_event_id: issue.id, barcode })}>Record corrected waybill</button>}{selected.canClose && <button disabled={busy || !notes.trim()} className={buttonClass} onClick={() => command('resolve-discrepancy', { source_event_id: issue.id })}>Resolve using supporting receipt</button>}</div></li>)}</ul><p className="mt-3 text-sm text-slate-700">Use a recording correction only when the original waybill entry was wrong. It requires the correctly identified parcel's actual receipt and an assigned receiving handler's reason. A note alone never resolves missing custody.</p></section>}
                    <p className="text-sm text-slate-600">The sealed list cannot change. Preparation and assignment do not transfer parcel custody; recorded departure and receiving scans establish each handoff.</p>
                    <div className="overflow-x-auto"><table className="w-full min-w-[540px] text-left text-sm"><caption className="mb-2 text-left font-semibold text-slate-900">Parcel list</caption><thead><tr className="border-b border-slate-300 text-slate-700"><th className="py-2 pr-3">Waybill</th><th className="py-2 pr-3">Current state</th><th className="py-2">Receipt</th></tr></thead><tbody>
                        {selected.parcels.map(parcel => <tr key={parcel.id} className="border-b border-slate-300"><td className="py-3 pr-3"><p className="break-all font-semibold">{parcel.tracking_number}</p>{!parcel.included && <p className="text-xs text-slate-600">Removed from draft</p>}{parcel.included && selected.canManage && selected.status === 'draft' && !hasCorrections && !selected.reviewedHold && <button disabled={busy || !notes.trim()} onClick={() => command('remove', { parcel_id: parcel.id })} className="mt-1 text-xs font-semibold text-[#E00D42] disabled:opacity-50">Remove with reason</button>}{parcel.handover_hold && <p className="mt-1 text-xs font-semibold text-amber-800">Awaiting supervised recovery</p>}{parcel.handover_issue_url && <Link href={parcel.handover_issue_url} className="mt-1 inline-block text-xs font-semibold text-[#E00D42]">Review handover</Link>}</td><td className="py-3 pr-3 capitalize">{words(parcel.status)}</td><td className="py-3">{parcel.received_at ? words(parcel.receipt_condition ?? 'received') + ' · ' + date(parcel.received_at) : parcel.corrective_receipt ? 'Corrective receipt · ' + date(parcel.corrective_receipt.received_at) : 'Awaiting receipt'}</td></tr>)}
                        {!selected.parcels.length && <tr><td colSpan={3} className="py-4 text-slate-600">{selected.direction === 'buyer_return' ? 'No return parcel has been loaded. Scan its separate return barcode before sealing this draft.' : 'No original outbound parcels were recorded. Any unlisted corrective receipts are shown separately.'}</td></tr>}
                    </tbody></table></div>
                    {hasCorrections && <section aria-label="Corrective receipt evidence" className="rounded-lg border border-slate-300 p-4">
                        <h3 className="font-semibold text-slate-900">Corrective receipt evidence</h3><ul className="mt-3 space-y-3">{selected.correctiveReceipts?.map(receipt => <li key={receipt.reference} className="border-b border-slate-300 pb-3">
                            <p className="break-all text-sm font-semibold">{receipt.tracking_number}</p><p className="mt-1 text-sm text-slate-700">{receipt.original_member_id === null ? 'Unlisted receipt — original expected count unchanged' : 'Receipt for an original expected parcel'}</p>
                            <p className="mt-1 text-xs text-slate-600">{receipt.received_by} · {date(receipt.received_at)} · Manila time</p><p className="mt-1 text-xs text-slate-600">Before this correction: {receipt.original_received_count} standard receipts / {receipt.original_expected_count} originally expected</p>
                            <p className="mt-1 break-all text-xs text-slate-600">{receipt.reference}</p>
                        </li>)}</ul>
                    </section>}
                    <details className="rounded-lg border border-slate-200 p-4"><summary className="cursor-pointer text-sm font-semibold text-slate-700">Transfer history ({selected.events.length})</summary><ol className="mt-4 space-y-3">{selected.events.map(event => <li key={event.id} className="border-l-2 border-slate-300 pl-3"><p className="text-sm font-semibold capitalize text-slate-900">{words(event.type)} · {event.actor}</p><p className="mt-1 break-words text-xs text-slate-600">{date(event.created_at)} · {event.reference}</p>{event.barcode && <p className="mt-1 break-all text-sm text-slate-700">Submitted code: {event.barcode}</p>}{event.reason && <p className="mt-1 whitespace-pre-wrap text-sm text-slate-700">{event.reason}</p>}</li>)}</ol></details>
                </section> : <section className="rounded-xl border border-slate-200 bg-white shadow-sm"><HubEmpty title="Choose a recorded transfer" description="Its next action, truck, sealed bags and receiving scans will appear here." icon={Truck} /></section>}
            </div>
        </div>
    </DashboardLayout>;
}
