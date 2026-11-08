import { FormEvent, useEffect, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Box, CheckCheck, Container, LockKeyhole, Package, Printer, ScanLine, Truck } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubButton, HubEmpty, HubNextStep, HubPageHeader, HubRoute, HubSearch, HubStepRail, hubButton, hubInput, type HubPlace } from '@/Components/Logistics/Workspace';
import HubPicker from '@/Components/Logistics/HubPicker';
import WaybillCamera from '@/Components/WaybillCamera';
import { bagSteps, nextTransferHubs } from '@/utils/hubWorkspace';

type Hub = HubPlace & { id: number; name: string; code: string; tier: string; logistics_company_id: number; canScan: boolean };
type Bag = { id: string; reference: string; status: string; seal_number: string | null; version: number; source_hub_id: number; destination_hub_id: number; manifest_id: number | null; parcels_count: number; source: Pick<Hub, 'id' | 'name' | 'code'>; destination: Pick<Hub, 'id' | 'name' | 'code'> };
type Detail = Bag & { canPack: boolean; canOpen: boolean; parcels: { id: number; tracking_number: string; status: string; removed: boolean; received: boolean }[]; events: { event_type: string; barcode_scanned: string | null; actor: string; created_at: string }[] };
type Props = { bags: { data: Bag[]; links: { url: string | null; label: string; active: boolean }[] }; selectedBag: Detail | null; hubs: Hub[]; manifests: { id: number; reference: string; source_hub_id: number; destination_hub_id: number; vehicle_plate?: string; driver_name?: string }[]; creationToken: string; basePath: string };
const words = (value: string) => value.replaceAll('_', ' ');

export default function MotherBags({ bags, selectedBag: bag, hubs, manifests, creationToken, basePath }: Props) {
    const [search, setSearch] = useState('');
    const [manifestScan, setManifestScan] = useState('');
    const matchingManifests = manifests.filter(item => item.source_hub_id === bag?.source_hub_id && item.destination_hub_id === bag?.destination_hub_id);
    const visibleBags = bags.data.filter(item => [item.reference, item.source.name, item.destination.name, item.seal_number, item.status].join(' ').toLowerCase().includes(search.toLowerCase()));
    const create = useForm({ source_hub_id: '', destination_hub_id: '', creation_token: creationToken });
    const action = useForm({ version: bag?.version ?? 1, barcode: '', seal_number: '', manifest_id: '', notes: '', seal_intact: false });
    useEffect(() => { setManifestScan(''); action.setData({ version: bag?.version ?? 1, barcode: '', seal_number: '', manifest_id: '', notes: '', seal_intact: false }); action.clearErrors(); }, [bag?.id, bag?.version]);
    useEffect(() => create.setData('creation_token', creationToken), [creationToken]);
    const command = (name: string) => bag && action.post(`${basePath}/${bag.id}/${name}`, { preserveScroll: true });
    const submit = (event: FormEvent, name: string) => { event.preventDefault(); command(name); };
    const source = hubs.find(hub => String(hub.id) === create.data.source_hub_id);
    const destination = hubs.find(hub => String(hub.id) === create.data.destination_hub_id);
    const receivingHubs = nextTransferHubs(hubs.filter(hub => hub.logistics_company_id === source?.logistics_company_id), source);
    const nextTitle = !bag ? '' : bag.status === 'received' ? 'Bag received: scan parcels for their next sorting step' : bag.status === 'opened' ? 'Scan every arriving parcel in the linked manifest' : bag.status === 'in_transit' ? 'Receiving handler: check the physical master tag and seal' : bag.status === 'sealed' ? bag.manifest_id ? 'Bag loaded: record actual departure in the manifest' : 'Scan the sealed master tag onto a matching draft' : 'Scan parcels into this bag, then attach a numbered seal';
    const error = Object.values(action.errors).concat(Object.values(create.errors)).filter(Boolean);

    return (
        <DashboardLayout title="Mother Bags">
            <Head title="Mother Bags" />
            <style>{`@media print { body * { visibility: hidden; } [data-bag-label], [data-bag-label] * { visibility: visible; } [data-bag-label] { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; } [data-bag-label] svg { margin: 0 auto; width: 180px; height: 180px; } }`}</style>
            <div className="space-y-6">
                <HubPageHeader
                    title="Mother Bags"
                    description="Group parcels going to the same next hub. Their master tag, numbered seal and individual receipts keep the handover clear."
                    icon={Container}
                    actions={<Link href={route('hub.manifests.index')} className={hubButton}><Truck className="h-4 w-4" />Manifests</Link>}
                />

                {bag && (
                    <>
                        <HubStepRail steps={bagSteps(bag.status, Boolean(bag.manifest_id))} label="Mother Bag progress" />
                        <HubNextStep title={nextTitle} tone={bag.status === 'received' ? 'success' : 'brand'} icon={bag.status === 'received' ? CheckCheck : ScanLine}>
                            {bag.source.name} → {bag.destination.name}. {bag.parcels_count} recorded parcels.
                            {bag.manifest_id && (
                                <Link className="ml-2 font-semibold text-[#C20836] underline underline-offset-4" href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}`}>
                                    Open transport manifest
                                </Link>
                            )}
                        </HubNextStep>
                    </>
                )}

                {error.length > 0 && (
                    <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800 shadow-xs">
                        {error.join(' ')}
                    </div>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[360px_1fr]">
                    {/* Left Column: Unified Notion Sidebar Panel */}
                    <aside className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                        {/* Top Section: Prepare a Mother Bag */}
                        <div className="p-5 border-b border-zinc-100 bg-zinc-50/40">
                            <div className="flex items-center gap-2">
                                <Container className="h-4 w-4 text-[#E00D42]" />
                                <h2 className="text-sm font-bold text-[#121316]">Prepare a Mother Bag</h2>
                            </div>
                            <p className="mt-1 text-xs text-zinc-500">Choose the actual sending and next receiving hub.</p>
                            <form onSubmit={event => { event.preventDefault(); create.post(basePath); }} className="mt-4 space-y-4">
                                <HubPicker label="Sending hub" hubs={hubs.filter(hub => hub.canScan)} value={create.data.source_hub_id} onChange={id => create.setData({ ...create.data, source_hub_id: id, destination_hub_id: '' })} error={create.errors.source_hub_id} />
                                <HubPicker label="Next hub" hubs={receivingHubs} value={create.data.destination_hub_id} onChange={id => create.setData('destination_hub_id', id)} disabled={!source} error={create.errors.destination_hub_id} />
                                {source && destination && <HubRoute stops={[source, destination]} label="Selected bag route" />}
                                <button
                                    type="submit"
                                    disabled={create.processing || !source || !destination}
                                    className="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-[#E00D42] hover:bg-[#C20836] px-4 py-2.5 text-xs font-bold text-white shadow-xs transition disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Box className="h-4 w-4" />
                                    Create bag
                                </button>
                            </form>
                        </div>

                        {/* Middle Section: Search */}
                        <div className="p-3.5 border-b border-zinc-100 bg-white">
                            <HubSearch label="Search loaded bags" value={search} onChange={setSearch} placeholder="Hub, tag or seal" />
                        </div>

                        {/* Bottom Section: Mother Bag Cards */}
                        <div className="p-3.5 space-y-2.5">
                            {visibleBags.map(item => (
                                <Link
                                    key={item.id}
                                    href={`${basePath}?bag=${item.id}`}
                                    preserveScroll
                                    className={`block p-4 rounded-xl border transition-all duration-150 ${
                                        bag?.id === item.id
                                            ? 'border-rose-300 bg-rose-50/60 ring-1 ring-rose-200/60 shadow-xs'
                                            : 'border-zinc-200/90 bg-white hover:border-zinc-300 hover:bg-zinc-50/40 shadow-2xs'
                                    }`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <div className="flex items-center gap-1.5 min-w-0">
                                            <span
                                                className="min-w-0 truncate rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]"
                                                title={item.reference}
                                            >
                                                {item.reference}
                                            </span>
                                            {item.reference.startsWith('RMB-') && (
                                                <span className="rounded-md bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800 shrink-0">
                                                    REVERSE RTS
                                                </span>
                                            )}
                                        </div>
                                        <HubBadge tone={item.status === 'received' ? 'success' : 'neutral'}>{words(item.status)}</HubBadge>
                                    </div>
                                    <p className="mt-3 text-xs sm:text-sm font-bold leading-5 text-[#121316]">
                                        {item.source.name}
                                        <ArrowRight className="mx-1.5 inline h-3.5 w-3.5 text-zinc-400" />
                                        {item.destination.name}
                                    </p>
                                    <p className="mt-2 text-xs text-zinc-500 font-medium">
                                        {item.parcels_count} parcels{item.seal_number ? ` · Seal ${item.seal_number}` : ''}
                                    </p>
                                </Link>
                            ))}

                            {bags.data.length === 0 && (
                                <p className="rounded-xl border border-dashed border-zinc-200 bg-zinc-50/40 p-6 text-center text-xs text-zinc-500 font-medium">
                                    Create your first bag for an outgoing hub transfer.
                                </p>
                            )}

                            {bags.links.filter(link => link.url).length > 1 && (
                                <div className="flex flex-wrap gap-2 pt-2">
                                    {bags.links.filter(link => link.url).map((link, i) => (
                                        <Link key={i} href={link.url!} preserveScroll className={hubButton} dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ))}
                                </div>
                            )}
                        </div>
                    </aside>

                    {/* Right Column: Active Bag Packing Console */}
                    {bag ? (
                        <div className="min-w-0 space-y-5">
                            {/* Master Tag & Route Card */}
                            <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                <header className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                    <div className="flex items-center gap-2.5">
                                        <Container className="h-4 w-4 text-[#E00D42]" />
                                        <div>
                                            <h2 className="text-sm font-bold text-[#121316] tabular-nums">{bag.reference}</h2>
                                            <p className="text-xs text-zinc-500">Master tag, recorded route and numbered seal</p>
                                        </div>
                                    </div>
                                    <HubButton type="button" onClick={() => window.print()}>
                                        <Printer className="h-4 w-4" />
                                        Print master tag
                                    </HubButton>
                                </header>
                                <div className="p-5 sm:p-6 space-y-5">
                                    <HubRoute stops={[bag.source, bag.destination]} />
                                    <div className="flex flex-wrap items-center justify-between gap-5 rounded-xl border border-zinc-200/80 bg-zinc-50/30 p-4">
                                        <div className="space-y-2">
                                            <div className="flex items-center gap-2">
                                                <span className="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                                                    {bag.reference}
                                                </span>
                                                <HubBadge tone={bag.status === 'received' ? 'success' : 'brand'}>{words(bag.status)}</HubBadge>
                                            </div>
                                            <p className="text-xs sm:text-sm text-zinc-600 font-medium">
                                                {bag.parcels_count} parcels · {bag.seal_number ? `Seal ${bag.seal_number}` : 'Seal not attached'}
                                            </p>
                                            {bag.manifest_id && (
                                                <Link
                                                    className="inline-block text-xs sm:text-sm font-bold text-[#E00D42] hover:text-[#C20836] underline underline-offset-4"
                                                    href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}`}
                                                >
                                                    Open transport manifest
                                                </Link>
                                            )}
                                        </div>
                                        <div data-bag-label className="rounded-xl border border-zinc-200/90 bg-white p-4 text-center shadow-xs">
                                            <QRCodeSVG value={bag.reference} size={96} />
                                            <p className="mt-2.5 text-xs tabular-nums font-bold text-[#121316]">{bag.reference}</p>
                                            <p className="mt-1 text-xs text-zinc-500 font-medium">{bag.source.code} → {bag.destination.code}</p>
                                            {bag.seal_number && <p className="mt-1 text-xs tabular-nums text-zinc-600">Seal {bag.seal_number}</p>}
                                        </div>
                                    </div>
                                </div>
                            </section>

                            {/* Pack and Seal Console */}
                            {bag.canPack && bag.status === 'open' && (
                                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2">
                                            <ScanLine className="h-4 w-4 text-[#E00D42]" />
                                            <h2 className="text-sm font-bold text-[#121316]">Pack and seal</h2>
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-500">Pack parcels ready for this next leg, then attach a new numbered seal.</p>
                                    </header>
                                    <div className="p-5 sm:p-6 space-y-5">
                                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-zinc-50/60 p-4">
                                            <p className="max-w-md text-xs leading-5 text-zinc-600">
                                                Arriving Mother Hub parcels need their sorting scan before the next bag. Check the parcel’s current stage if packing is blocked.
                                            </p>
                                            <Link href={route('hub.scan.station')} className="inline-flex items-center gap-1.5 text-xs font-bold text-[#E00D42] hover:text-[#C20836]">
                                                <ScanLine className="h-4 w-4" />
                                                Check in Scan Station
                                            </Link>
                                        </div>
                                        <form onSubmit={event => submit(event, 'add')} className="flex flex-wrap items-end gap-3">
                                            <label className="min-w-0 flex-1 text-xs font-bold text-[#121316]">
                                                Parcel waybill
                                                <input
                                                    autoFocus
                                                    required
                                                    autoComplete="off"
                                                    className={`${hubInput} mt-1.5`}
                                                    value={action.data.barcode}
                                                    onChange={event => action.setData('barcode', event.target.value)}
                                                    placeholder="Scan or enter waybill number"
                                                />
                                            </label>
                                            <HubButton disabled={action.processing}>Add parcel</HubButton>
                                        </form>
                                        <WaybillCamera onScan={value => action.setData('barcode', value)} disabled={action.processing} />
                                        <form onSubmit={event => submit(event, 'seal')} className="flex flex-wrap items-end gap-3 border-t border-zinc-100 pt-5">
                                            <label className="min-w-0 flex-1 text-xs font-bold text-[#121316]">
                                                Numbered seal
                                                <input
                                                    required
                                                    maxLength={80}
                                                    className={`${hubInput} mt-1.5 tabular-nums`}
                                                    value={action.data.seal_number}
                                                    onChange={event => action.setData('seal_number', event.target.value)}
                                                    placeholder="SL-XXXXX"
                                                />
                                            </label>
                                            <HubButton primary disabled={action.processing || !bag.parcels_count}>
                                                <LockKeyhole className="h-4 w-4" />
                                                Seal bag
                                            </HubButton>
                                        </form>
                                    </div>
                                </section>
                            )}

                            {/* Load by Master Tag */}
                            {bag.canPack && bag.status === 'sealed' && !bag.manifest_id && (
                                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2">
                                            <Truck className="h-4 w-4 text-[#E00D42]" />
                                            <h2 className="text-sm font-bold text-[#121316]">Load by master tag</h2>
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-500">One bag scan loads its complete sealed parcel list.</p>
                                    </header>
                                    <div className="p-5 sm:p-6">
                                        <form onSubmit={event => submit(event, 'load')} className="grid gap-4 sm:grid-cols-2">
                                            <div className="sm:col-span-2 flex flex-wrap items-center justify-between gap-2 pb-1">
                                                <span className="text-xs text-zinc-500 font-medium">Scan physical bag label & seal, or auto-fill:</span>
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        action.setData({
                                                            ...action.data,
                                                            barcode: bag.reference,
                                                            seal_number: bag.seal_number || '',
                                                        });
                                                    }}
                                                    className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] hover:underline cursor-pointer"
                                                >
                                                    Auto-fill this bag's tag ({bag.reference}) & seal ({bag.seal_number})
                                                </button>
                                            </div>
                                            <label className="text-xs font-bold text-[#121316]">
                                                Master bag barcode
                                                <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                            </label>
                                            <label className="text-xs font-bold text-[#121316]">
                                                Numbered seal
                                                <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                            </label>
                                            <fieldset className="space-y-3 sm:col-span-2">
                                                <legend className="mb-2 text-xs font-bold text-[#121316]">Draft transport manifest</legend>
                                                <label className="block text-xs font-medium text-zinc-500">
                                                    Scan transfer reference (or choose a truck below)
                                                    <input
                                                        className={`${hubInput} mt-1.5`}
                                                        value={manifestScan}
                                                        autoComplete="off"
                                                        placeholder="Scan a prepared manifest reference"
                                                        onChange={event => {
                                                            setManifestScan(event.target.value);
                                                            const match = matchingManifests.find(item => item.reference.toUpperCase() === event.target.value.trim().toUpperCase());
                                                            action.setData('manifest_id', match ? String(match.id) : '');
                                                        }}
                                                    />
                                                </label>
                                                <div className="grid gap-2.5 sm:grid-cols-2">
                                                    {matchingManifests.map(item => (
                                                        <label
                                                            key={item.id}
                                                            className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 shadow-xs transition-colors focus-within:ring-2 focus-within:ring-[#E00D42]/20 ${
                                                                action.data.manifest_id === String(item.id) ? 'border-rose-300 bg-rose-50/70' : 'border-zinc-200/90 bg-white hover:bg-zinc-50'
                                                            }`}
                                                        >
                                                            <input
                                                                type="radio"
                                                                name="bag-manifest"
                                                                required
                                                                value={item.id}
                                                                checked={action.data.manifest_id === String(item.id)}
                                                                onChange={() => {
                                                                    action.setData('manifest_id', String(item.id));
                                                                    setManifestScan('');
                                                                }}
                                                                className="mt-1 border-zinc-300 text-[#E00D42] focus:ring-[#E00D42]"
                                                            />
                                                            <span className="min-w-0">
                                                                <span className="flex items-center gap-2 text-xs sm:text-sm font-bold text-[#121316]">
                                                                    <Truck className="h-4 w-4 text-zinc-400" />
                                                                    {item.vehicle_plate ?? `Transfer #${item.id}`}
                                                                </span>
                                                                <span className="mt-1 block text-xs text-zinc-500">{item.driver_name ?? 'Recorded driver'} · Draft #{item.id}</span>
                                                                <span title={item.reference} className="mt-1 block truncate text-[11px] tabular-nums text-zinc-400">{item.reference}</span>
                                                            </span>
                                                        </label>
                                                    ))}
                                                </div>
                                                {!matchingManifests.length && (
                                                    <p className="text-xs leading-5 text-amber-800 rounded-lg bg-amber-50 p-3 border border-amber-200">
                                                        No draft matches this bag’s route. Prepare its vehicle trip in Manifests, then return here.
                                                    </p>
                                                )}
                                            </fieldset>
                                            <div className="sm:col-span-2">
                                                <HubButton primary disabled={action.processing || !action.data.manifest_id}>Load sealed bag</HubButton>
                                            </div>
                                        </form>
                                    </div>
                                </section>
                            )}

                            {/* Correct Packing or Loading */}
                            {bag.canPack && ['open', 'sealed'].includes(bag.status) && (
                                <details className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <summary className="cursor-pointer bg-zinc-50/40 px-5 py-3.5 text-xs sm:text-sm font-bold text-[#121316] hover:bg-zinc-50 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">
                                        Correct packing or loading
                                    </summary>
                                    <form onSubmit={event => submit(event, bag.status === 'open' ? 'remove' : bag.manifest_id ? 'unload' : 'reopen')} className="space-y-4 border-t border-zinc-100 p-5 sm:p-6">
                                        <p className="text-xs leading-5 text-zinc-500">
                                            {bag.status === 'open'
                                                ? 'Remove a scanned parcel before sealing, with the reason retained.'
                                                : bag.manifest_id
                                                  ? 'Unload this complete sealed bag from an undeparted draft manifest. Its parcels and numbered seal stay together.'
                                                  : 'Reopen this undeparted bag to correct its contents. The old seal cannot be reused.'}
                                        </p>
                                        {(bag.status === 'open' || bag.manifest_id) && (
                                            <label className="block text-xs font-bold text-[#121316]">
                                                {bag.status === 'open' ? 'Parcel waybill to remove' : 'Scanned master bag tag'}
                                                <input required className={`${hubInput} mt-1.5`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                            </label>
                                        )}
                                        {bag.manifest_id && (
                                            <label className="block text-xs font-bold text-[#121316]">
                                                {bag.manifest_id ? 'Numbered seal' : ''}
                                                <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                            </label>
                                        )}
                                        <label className="block text-xs font-bold text-[#121316]">
                                            Actual correction reason
                                            <textarea required maxLength={1000} rows={2} className={`${hubInput} mt-1.5`} value={action.data.notes} onChange={event => action.setData('notes', event.target.value)} />
                                        </label>
                                        <HubButton disabled={action.processing}>
                                            {bag.status === 'open' ? 'Remove parcel with reason' : bag.manifest_id ? 'Unload complete bag' : 'Reopen with reason'}
                                        </HubButton>
                                    </form>
                                </details>
                            )}

                            {/* Verify Arriving Seal */}
                            {bag.canOpen && bag.status === 'in_transit' && (
                                <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2">
                                            <CheckCheck className="h-4 w-4 text-[#E00D42]" />
                                            <h2 className="text-sm font-bold text-[#121316]">Verify arriving seal</h2>
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-500">Check the master tag and physical seal before individual receiving.</p>
                                    </header>
                                    <div className="p-5 sm:p-6">
                                        <form onSubmit={event => submit(event, 'open')} className="space-y-4">
                                            <label className="block text-xs font-bold text-[#121316]">
                                                Scanned master bag tag
                                                <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                            </label>
                                            <label className="block text-xs font-bold text-[#121316]">
                                                Seal number observed
                                                <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                            </label>
                                            <label className="flex items-center gap-2.5 text-xs text-zinc-700 font-medium">
                                                <input
                                                    required
                                                    type="checkbox"
                                                    checked={action.data.seal_intact}
                                                    onChange={event => action.setData('seal_intact', event.target.checked)}
                                                    className="rounded border-zinc-300 text-[#E00D42] focus:ring-[#E00D42]"
                                                />
                                                The physical numbered seal is intact and matches.
                                            </label>
                                            <HubButton primary disabled={action.processing}>Verify and open bag</HubButton>
                                        </form>
                                    </div>
                                </section>
                            )}

                            {/* Received Notice */}
                            {bag.status === 'received' && (
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-xs">
                                    <p className="text-xs sm:text-sm font-semibold text-emerald-900">
                                        All parcels arrived. Scan each waybill to record sorting before its next transfer or rider assignment.
                                    </p>
                                    <Link href={route('hub.scan.station')} className={hubButton}>
                                        <ScanLine className="h-4 w-4" />
                                        Open scan station
                                    </Link>
                                </div>
                            )}

                            {/* Parcel Contents Table */}
                            <section className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                    <div className="flex items-center gap-2">
                                        <Package className="h-4 w-4 text-[#E00D42]" />
                                        <h2 className="text-sm font-bold text-[#121316]">Parcel contents</h2>
                                    </div>
                                    <p className="mt-0.5 text-xs text-zinc-500">
                                        {bag.status === 'opened' ? 'Open the manifest and scan each arriving waybill.' : 'Membership and each actual receiving scan stay recorded.'}
                                    </p>
                                </header>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[480px] text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-zinc-100 bg-zinc-50/30 text-zinc-500 font-semibold">
                                                <th className="px-5 py-3">Waybill</th>
                                                <th className="px-5 py-3">Progress</th>
                                                <th className="px-5 py-3 text-right">Receipt</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-zinc-100">
                                            {bag.parcels.map(item => (
                                                <tr key={item.id} className="hover:bg-zinc-50/50 transition-colors">
                                                    <td className="px-5 py-3.5">
                                                        <span className="rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 tabular-nums text-xs font-bold text-[#E00D42]">
                                                            {item.tracking_number}
                                                        </span>
                                                    </td>
                                                    <td className="px-5 py-3.5 text-zinc-600">
                                                        <div className="flex items-center gap-2">
                                                            <span>{item.removed ? 'Removed from bag' : words(item.status)}</span>
                                                            {item.status === 'return_to_sender' && (
                                                                <span className="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">
                                                                    RTS RETURN
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-5 py-3.5 text-right">
                                                        <HubBadge tone={item.received ? 'success' : 'neutral'}>
                                                            {item.received ? 'Received' : item.removed ? 'Removed' : 'Awaiting receipt'}
                                                        </HubBadge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {bag.parcels.length === 0 && (
                                        <p className="py-10 text-center text-xs text-zinc-400 font-medium">
                                            Scan the first parcel to begin packing.
                                        </p>
                                    )}
                                </div>
                            </section>

                            {/* Bag Activity Log */}
                            <details className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                                <summary className="cursor-pointer bg-zinc-50/40 px-5 py-3.5 text-xs sm:text-sm font-bold text-[#121316] hover:bg-zinc-50 transition-colors">
                                    Bag activity ({bag.events.length})
                                </summary>
                                <div className="border-t border-zinc-100 p-5 sm:p-6">
                                    <ol className="divide-y divide-zinc-100">
                                        {bag.events.map((event, index) => (
                                            <li key={index} className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                                                <div>
                                                    <p className="text-xs sm:text-sm font-semibold text-[#121316]">{words(event.event_type)}</p>
                                                    <p className="mt-0.5 text-xs text-zinc-500">
                                                        {event.actor}{event.barcode_scanned ? ` · ${event.barcode_scanned}` : ''}
                                                    </p>
                                                </div>
                                                <time className="text-xs text-zinc-400 tabular-nums">{new Date(event.created_at).toLocaleString('en-PH')}</time>
                                            </li>
                                        ))}
                                    </ol>
                                </div>
                            </details>
                        </div>
                    ) : (
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-6 shadow-xs">
                            <HubEmpty
                                title="Choose a bag to continue"
                                description="Its next action, route, seal and parcel receipts appear here. To start a new transfer, choose the sending and receiving hubs on the left."
                                icon={Container}
                            />
                        </div>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
}
