import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Box, CheckCheck, Container, LockKeyhole, Package, Printer, RotateCcw, ScanLine, Truck } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubButton, HubRoute, HubSearch, hubButton, hubInput, type HubPlace } from '@/Components/Logistics/Workspace';
import HubPicker from '@/Components/Logistics/HubPicker';
import { CapacityMeter, HandlingFields, HandlingSummary, emptyHandling, type Handling } from '@/Components/Logistics/CargoHandling';
import WaybillCamera from '@/Components/WaybillCamera';
import { nextTransferHubs } from '@/utils/hubWorkspace';

type Hub = HubPlace & { id: number; name: string; code: string; tier: string; logistics_company_id: number; canScan: boolean; canPackDispatch?: boolean; canIntake?: boolean; jobRole?: string };
type BagPreset = { key: string; label: string; max_count: number; max_weight_kg: number; max_volume_cm3: number };
type Bag = { capacity_preset?: string | null; packing_policy_version?: number | null; physical_tag?: string | null; capacity?: { count: number; weight_kg: number | null; volume_cm3: number | null; max_count: number; max_weight_kg: number; max_volume_cm3: number } | null; id: string; reference: string; status: string; seal_number: string | null; version: number; source_hub_id: number; destination_hub_id: number; manifest_id: number | null; parcels_count: number; source: Pick<Hub, 'id' | 'name' | 'code'>; destination: Pick<Hub, 'id' | 'name' | 'code'> };
type Detail = Bag & { canPack: boolean; canOpen: boolean; sourceJobRole?: string; destinationJobRole?: string; parcels: { handling?: Handling | null; id: number; tracking_number: string; status: string; removed: boolean; received: boolean }[]; events: { event_type: string; barcode_scanned: string | null; actor: string; created_at: string }[] };
type Props = { activeHubId?: number | null; bagPresets?: BagPreset[]; packingAvailable?: boolean; packingToken?: string; commandToken?: string; bags: { data: Bag[]; links: { url: string | null; label: string; active: boolean }[] }; selectedBag: Detail | null; hubs: Hub[]; manifests: { id: number; reference: string; source_hub_id: number; destination_hub_id: number; vehicle_plate?: string; driver_name?: string }[]; creationToken: string; basePath: string };
const words = (value: string) => value.replaceAll('_', ' ');
const DEFAULT_BAG_PRESETS: BagPreset[] = [
    { key: 'standard', label: 'Standard Sack (50 parcels · 25 kg · 0.30 m³)', max_count: 50, max_weight_kg: 25, max_volume_cm3: 300000 },
    { key: 'compact', label: 'Small Pouch Sack (80 parcels · 15 kg · 0.20 m³)', max_count: 80, max_weight_kg: 15, max_volume_cm3: 200000 },
    { key: 'bulk', label: 'Bulk / Cage Bag (120 parcels · 50 kg · 0.60 m³)', max_count: 120, max_weight_kg: 50, max_volume_cm3: 600000 },
];

export default function MotherBags({ bags, selectedBag: bag, hubs, manifests, creationToken, basePath, packingAvailable = false, packingToken = creationToken, commandToken = creationToken, activeHubId = null, bagPresets = DEFAULT_BAG_PRESETS }: Props) {
    const [search, setSearch] = useState('');
    const [bagCategory, setBagCategory] = useState<'all' | 'open' | 'sealed' | 'transit'>('all');
    const [manifestScan, setManifestScan] = useState('');
    const [continuousPack, setContinuousPack] = useState(true);
    const [showSealScanner, setShowSealScanner] = useState(false);
    const [revertingTracking, setRevertingTracking] = useState<string | null>(null);
    const [revertNotes, setRevertNotes] = useState('Mis-scanned parcel');
    const packableHubs = hubs.filter(hub => hub.canPackDispatch ?? hub.canScan);
    const defaultSourceHubId = activeHubId && packableHubs.some(hub => hub.id === activeHubId)
        ? String(activeHubId)
        : packableHubs[0]
            ? String(packableHubs[0].id)
            : '';
    const activeHubRecord = hubs.find(hub => hub.id === activeHubId) ?? packableHubs[0] ?? null;
    const matchingManifests = manifests.filter(item => item.source_hub_id === bag?.source_hub_id && item.destination_hub_id === bag?.destination_hub_id);
    const openBagsCount = bags.data.filter(item => item.status === 'open').length;
    const sealedBagsCount = bags.data.filter(item => item.status === 'sealed').length;
    const transitBagsCount = bags.data.filter(item => ['in_transit', 'opened', 'received'].includes(item.status)).length;
    const visibleBags = bags.data
        .filter(item => {
            if (bagCategory === 'open') return item.status === 'open';
            if (bagCategory === 'sealed') return item.status === 'sealed';
            if (bagCategory === 'transit') return ['in_transit', 'opened', 'received'].includes(item.status);
            return true;
        })
        .filter(item => [item.reference, item.source.name, item.destination.name, item.seal_number, item.status].join(' ').toLowerCase().includes(search.toLowerCase()));
    const create = useForm({
        source_hub_id: defaultSourceHubId,
        destination_hub_id: '',
        creation_token: creationToken,
        capacity_preset: bagPresets[0]?.key ?? 'standard',
        max_count: String(bagPresets[0]?.max_count ?? 50),
        max_weight_kg: String(bagPresets[0]?.max_weight_kg ?? 25),
        max_volume_m3: String(Number(((bagPresets[0]?.max_volume_cm3 ?? 300000) / 1000000).toFixed(2))),
    });
    const pending = useRef(false);
    const packing = useForm({ source_hub_id: defaultSourceHubId, barcode: '', physical_tag: '', physical_checked: false, request_token: packingToken, handling: emptyHandling() });
    const action = useForm({ request_token: commandToken, handling: emptyHandling(), version: bag?.version ?? 1, barcode: '', seal_number: '', manifest_id: '', notes: '', seal_intact: false });
    useEffect(() => {
        setManifestScan('');
        setRevertingTracking(null);
        setShowSealScanner(false);
        action.setData({ request_token: commandToken, handling: emptyHandling(), version: bag?.version ?? 1, barcode: '', seal_number: '', manifest_id: '', notes: '', seal_intact: false });
        action.clearErrors();
    }, [bag?.id, bag?.version]);
    useEffect(() => create.setData('creation_token', creationToken), [creationToken]);
    useEffect(() => {
        if (!defaultSourceHubId) return;
        if (!create.data.source_hub_id) {
            create.setData({ ...create.data, source_hub_id: defaultSourceHubId, destination_hub_id: '' });
        }
        if (!packing.data.source_hub_id) {
            packing.setData('source_hub_id', defaultSourceHubId);
        }
    }, [defaultSourceHubId]);
    const command = (name: string) => {
        if (!bag || pending.current) return;
        pending.current = true;
        action.post(`${basePath}/${bag.id}/${name}`, { preserveScroll: true, onFinish: () => { pending.current = false; } });
    };
    const packScannedWaybill = (code: string) => {
        if (!bag || pending.current) return;
        pending.current = true;
        router.post(`${basePath}/${bag.id}/add`, {
            request_token: crypto.randomUUID(),
            handling: action.data.handling,
            version: bag.version,
            barcode: code,
        }, {
            preserveScroll: true,
            onFinish: () => {
                pending.current = false;
            },
        });
    };
    const revertParcel = (trackingNumber: string) => {
        if (!bag || pending.current) return;
        pending.current = true;
        router.post(`${basePath}/${bag.id}/remove`, {
            request_token: crypto.randomUUID(),
            version: bag.version,
            barcode: trackingNumber,
            notes: revertNotes.trim() || 'Mis-scanned parcel',
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setRevertingTracking(null);
            },
            onFinish: () => {
                pending.current = false;
            },
        });
    };
    const submit = (event: FormEvent, name: string) => { event.preventDefault(); command(name); };
    const source = hubs.find(hub => String(hub.id) === create.data.source_hub_id);
    const destination = hubs.find(hub => String(hub.id) === create.data.destination_hub_id);
    const receivingHubs = nextTransferHubs(hubs.filter(hub => hub.logistics_company_id === source?.logistics_company_id), source);
    const activeParcels = bag ? bag.parcels.filter(item => !item.removed) : [];
    const error = Object.values(action.errors).concat(Object.values(create.errors), Object.values(packing.errors)).filter(Boolean);

    return (
        <DashboardLayout title="Mother Bags">
            <Head title="Mother Bags" />
            <style>{`@media print { body * { visibility: hidden; } [data-bag-label], [data-bag-label] * { visibility: visible; } [data-bag-label] { position: absolute; left: 0; top: 0; width: 100%; padding: 24px; } [data-bag-label] svg { margin: 0 auto; width: 180px; height: 180px; } }`}</style>
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <h1 className="text-2xl font-bold tracking-tight text-[#121316]">Mother Bags</h1>
                    <div className="flex flex-wrap items-center gap-2">
                        {bag && (
                            <Link
                                href={basePath}
                                className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-[#121316] shadow-xs hover:border-zinc-300 hover:bg-zinc-50 transition"
                            >
                                <ScanLine className="h-4 w-4 text-zinc-600" />
                                Auto-pack parcel
                            </Link>
                        )}
                        <Link
                            href={route('hub.manifests.index')}
                            className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-[#121316] shadow-xs hover:border-zinc-300 hover:bg-zinc-50 transition"
                        >
                            <Truck className="h-4 w-4 text-zinc-600" />
                            Manifests
                        </Link>
                    </div>
                </div>

                {error.length > 0 && (
                    <div role="alert" className="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-800 shadow-xs">
                        {error.join(' ')}
                    </div>
                )}

                <div className="grid items-start gap-6 lg:grid-cols-[360px_1fr]">
                    {/* Left Column: Prepare Mother Bag + Bag Directory */}
                    <div className="space-y-5">
                        <section className="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs">
                            <div className="flex items-start gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-600">
                                    <Container className="h-4 w-4" />
                                </div>
                                <div>
                                    <h2 className="text-sm font-bold text-[#121316]">Prepare a Mother Bag</h2>
                                    <p className="mt-0.5 text-xs text-zinc-500">Sending hub is auto-selected; choose next hub and bag limit preset.</p>
                                </div>
                            </div>
                            {activeHubRecord && activeHubRecord.canPackDispatch === false && (
                                <p role="status" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    Your active hub role at {activeHubRecord.name} is locked to Entry / Intake only. Ask a manager to switch your role to Pack &amp; Dispatch to prepare or seal bags.
                                </p>
                            )}
                            <form onSubmit={event => { event.preventDefault(); create.post(basePath); }} className="mt-5 space-y-4">
                                <HubPicker label="Sending hub" hubs={packableHubs} value={create.data.source_hub_id} onChange={id => create.setData({ ...create.data, source_hub_id: id, destination_hub_id: '' })} error={create.errors.source_hub_id} />
                                <HubPicker label="Next hub" hubs={receivingHubs} value={create.data.destination_hub_id} onChange={id => create.setData('destination_hub_id', id)} disabled={!source} error={create.errors.destination_hub_id} />
                                <label className="block text-xs font-bold text-[#121316]">
                                    Bag capacity preset
                                    <select
                                        className={`${hubInput} mt-1.5`}
                                        value={create.data.capacity_preset}
                                        onChange={event => {
                                            const key = event.target.value;
                                            const preset = bagPresets.find(item => item.key === key);
                                            if (preset) {
                                                create.setData({
                                                    ...create.data,
                                                    capacity_preset: key,
                                                    max_count: String(preset.max_count),
                                                    max_weight_kg: String(preset.max_weight_kg),
                                                    max_volume_m3: String(Number((preset.max_volume_cm3 / 1000000).toFixed(2))),
                                                });
                                            } else {
                                                create.setData('capacity_preset', key);
                                            }
                                        }}
                                    >
                                        {bagPresets.map(preset => (
                                            <option key={preset.key} value={preset.key}>{preset.label}</option>
                                        ))}
                                        <option value="custom">Custom limits</option>
                                    </select>
                                </label>
                                {create.data.capacity_preset === 'custom' && (
                                    <div className="grid grid-cols-3 gap-2 rounded-lg border border-zinc-200 bg-zinc-50/70 p-3">
                                        <label className="text-[11px] font-semibold text-zinc-700">
                                            Max parcels
                                            <input type="number" min={1} max={500} required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_count} onChange={event => create.setData('max_count', event.target.value)} />
                                        </label>
                                        <label className="text-[11px] font-semibold text-zinc-700">
                                            Max kg
                                            <input type="number" min={1} max={200} step="0.5" required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_weight_kg} onChange={event => create.setData('max_weight_kg', event.target.value)} />
                                        </label>
                                        <label className="text-[11px] font-semibold text-zinc-700">
                                            Max m³
                                            <input type="number" min={0.01} max={2} step="0.01" required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_volume_m3} onChange={event => create.setData('max_volume_m3', event.target.value)} />
                                        </label>
                                    </div>
                                )}
                                {source && destination && <HubRoute stops={[source, destination]} label="Selected bag route" />}
                                <button
                                    type="submit"
                                    disabled={create.processing || !source || !destination}
                                    className="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-[#E00D42] hover:bg-[#C20836] px-4 py-2.5 text-xs font-bold text-white shadow-xs transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <Box className="h-4 w-4" />
                                    Create bag
                                </button>
                            </form>
                        </section>

                        <aside className="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs space-y-3.5">
                            <HubSearch label="Search loaded bags" value={search} onChange={setSearch} placeholder="Hub, tag or seal" />
                            <div role="tablist" aria-label="Filter mother bags" className="grid grid-cols-2 gap-2">
                                {([
                                    { id: 'all', label: 'All Bags', count: bags.data.length },
                                    { id: 'open', label: 'Open Packing', count: openBagsCount },
                                    { id: 'sealed', label: 'Sealed', count: sealedBagsCount },
                                    { id: 'transit', label: 'In Transit & Received', count: transitBagsCount },
                                ] as const).map((tab) => {
                                    const selected = bagCategory === tab.id;
                                    return (
                                        <button
                                            key={tab.id}
                                            role="tab"
                                            type="button"
                                            aria-selected={selected}
                                            onClick={() => setBagCategory(tab.id)}
                                            className={`flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-xs font-semibold transition cursor-pointer ${
                                                selected
                                                    ? 'border-[#C20836] bg-[#E00D42] text-white shadow-xs'
                                                    : 'border-zinc-200 bg-slate-100/80 text-zinc-700 hover:bg-slate-100 hover:text-[#121316]'
                                            }`}
                                        >
                                            <span className="truncate">{tab.label}</span>
                                            <span
                                                className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold tabular-nums ${
                                                    selected
                                                        ? 'bg-white/20 text-white'
                                                        : 'border border-zinc-200 bg-white text-zinc-600'
                                                }`}
                                            >
                                                {tab.count}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>

                            {visibleBags.length > 0 && (
                                <div className="space-y-2.5 pt-1">
                                    {visibleBags.map(item => (
                                        <Link
                                            key={item.id}
                                            href={`${basePath}?bag=${item.id}`}
                                            preserveScroll
                                            className={`block p-4 rounded-xl border transition-all duration-150 ${
                                                bag?.id === item.id
                                                    ? 'border-[#E00D42] bg-rose-50/40 ring-1 ring-[#E00D42]/20 shadow-xs'
                                                    : 'border-zinc-200 bg-white hover:border-zinc-300 hover:bg-zinc-50/40 shadow-2xs'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <div className="flex items-center gap-1.5 min-w-0">
                                                    <span
                                                        className="min-w-0 truncate rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 tabular-nums text-xs font-bold text-[#121316]"
                                                        title={item.reference}
                                                    >
                                                        {item.reference}
                                                    </span>
                                                    {item.reference.startsWith('RMB-') && (
                                                        <span className="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 shrink-0">
                                                            REVERSE RTS
                                                        </span>
                                                    )}
                                                </div>
                                                <HubBadge tone={item.status === 'received' ? 'brand' : 'neutral'}>{words(item.status)}</HubBadge>
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
                                </div>
                            )}

                            {bags.data.length > 0 && visibleBags.length === 0 && (
                                <p className="rounded-xl border border-dashed border-zinc-200 bg-white p-5 text-center text-xs text-zinc-500 font-medium">
                                    No bags match this category or search filter.
                                </p>
                            )}

                            {bags.data.length === 0 && (
                                <div className="rounded-xl border border-dashed border-zinc-200 bg-white px-4 py-10 text-center">
                                    <div className="mx-auto flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-zinc-500">
                                        <Container className="h-4 w-4" />
                                    </div>
                                    <p className="mt-3 text-xs font-semibold text-[#121316]">Choose a bag to continue</p>
                                </div>
                            )}

                            {bags.links.filter(link => link.url).length > 1 && (
                                <div className="flex flex-wrap gap-2 pt-1">
                                    {bags.links.filter(link => link.url).map((link, i) => (
                                        <Link key={i} href={link.url!} preserveScroll className={hubButton} dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ))}
                                </div>
                            )}
                        </aside>
                    </div>

                    {/* Right Column: Auto-pack (default) OR Selected Bag Workspace */}
                    <div className="min-w-0 space-y-5">
                        {!packingAvailable && (
                            <p role="status" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                                Packing upgrades need the additive database migration. Existing trip history remains available.
                            </p>
                        )}

                        {!bag && packingAvailable && (
                            <section className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs">
                                <header className="border-b border-zinc-100 bg-white px-5 py-4">
                                    <h2 className="text-base font-bold text-[#121316]">Scan into the next open bag</h2>
                                </header>
                                <form
                                    onSubmit={event => {
                                        event.preventDefault();
                                        if (pending.current) return;
                                        pending.current = true;
                                        packing.post(`${basePath}/pack`, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                packing.reset('barcode', 'handling');
                                                packing.setData('request_token', crypto.randomUUID());
                                            },
                                            onFinish: () => {
                                                pending.current = false;
                                            },
                                        });
                                    }}
                                >
                                    <div className="space-y-3.5 p-5">
                                        <div className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]">
                                            <HubPicker label="Packing hub" hubs={packableHubs} value={packing.data.source_hub_id} onChange={id => packing.setData('source_hub_id', id)} />
                                            <label className="min-w-0 block text-xs font-semibold text-zinc-700">
                                                Actual parcel waybill
                                                <input
                                                    required
                                                    maxLength={255}
                                                    autoComplete="off"
                                                    className="mt-2 w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm font-medium text-[#121316] shadow-xs placeholder:text-zinc-400 focus:border-[#E00D42] focus:outline-none focus:ring-2 focus:ring-[#E00D42]/15 transition"
                                                    value={packing.data.barcode}
                                                    onChange={event => packing.setData('barcode', event.target.value)}
                                                />
                                            </label>
                                            <button
                                                type="submit"
                                                disabled={packing.processing || !packing.data.source_hub_id}
                                                className="inline-flex min-h-[42px] items-center justify-center rounded-xl bg-[#E00D42] hover:bg-[#C20836] px-5 py-2.5 text-xs font-bold text-white shadow-xs transition cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Find bag &amp; add parcel
                                            </button>
                                        </div>
                                        <HandlingFields value={packing.data.handling} onChange={value => packing.setData('handling', value)} disabled={packing.processing} />
                                        <details className="rounded-lg border border-zinc-200 bg-white p-4">
                                            <summary className="cursor-pointer text-sm font-semibold text-[#121316]">Reusable sack tag (optional)</summary>
                                            <label className="mt-3 block text-xs font-semibold text-zinc-700">
                                                Permanent sack tag
                                                <input maxLength={80} pattern="[A-Z0-9-]+" className={`${hubInput} mt-1.5`} value={packing.data.physical_tag} onChange={event => packing.setData('physical_tag', event.target.value.toUpperCase())} />
                                            </label>
                                            <label className="mt-3 flex gap-2 text-xs text-zinc-700">
                                                <input type="checkbox" checked={packing.data.physical_checked} onChange={event => packing.setData('physical_checked', event.target.checked)} className="rounded border-zinc-300 text-[#121316] focus:ring-[#121316]" />
                                                I checked this permanent tag on the sack at this hub.
                                            </label>
                                        </details>
                                    </div>
                                    <div className="border-t border-zinc-200/80 bg-slate-50/70 px-5 py-3 text-xs text-zinc-600">
                                        Over 15 kg or a side over 60 cm? Use{' '}
                                        <Link className="font-semibold text-[#121316] underline underline-offset-4 hover:text-[#E00D42]" href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests`}>
                                            loose cargo in Manifests
                                        </Link>.
                                    </div>
                                </form>
                            </section>
                        )}

                        {bag && (
                            <div className="space-y-5">
                                {/* Compact Selected Bag Summary */}
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-5 py-3.5 shadow-xs">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-bold text-[#121316] tabular-nums">{bag.reference}</span>
                                            <HubBadge tone={bag.status === 'received' ? 'brand' : 'neutral'}>{words(bag.status)}</HubBadge>
                                            {bag.seal_number && (
                                                <span className="text-xs font-medium text-zinc-500 tabular-nums">Seal {bag.seal_number}</span>
                                            )}
                                        </div>
                                        <p className="mt-0.5 text-xs text-zinc-600">
                                            {bag.source.name} → {bag.destination.name} · {bag.parcels_count} recorded parcels
                                        </p>
                                    </div>
                                    {bag.manifest_id && (
                                        <Link
                                            className="text-xs font-bold text-[#121316] underline underline-offset-4 hover:text-zinc-700"
                                            href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}`}
                                        >
                                            Open transport manifest
                                        </Link>
                                    )}
                                </div>

                                {/* Pack and Seal Console */}
                                {!bag.canPack && bag.status === 'open' && (
                                    <div role="status" className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                                        Packing and sealing this bag requires a Pack &amp; Dispatch or Full Hub Operations lock at {bag.source.name}.
                                    </div>
                                )}
                                {bag.canPack && bag.status === 'open' && (
                                    <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                        <header className="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <ScanLine className="h-4 w-4 text-zinc-600" />
                                                    <h2 className="text-sm font-bold text-[#121316]">Pack and seal</h2>
                                                </div>
                                                <p className="mt-0.5 text-xs text-zinc-500">Keep this bag open, scan incoming parcels continuously, revert any mis-scan, then scan or type the seal.</p>
                                            </div>
                                            <label className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-[#121316] cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={continuousPack}
                                                    onChange={event => setContinuousPack(event.target.checked)}
                                                    className="rounded border-zinc-300 text-[#E00D42] focus:ring-[#E00D42]"
                                                />
                                                Hands-free continuous auto-add
                                            </label>
                                        </header>
                                        <div className="p-5 sm:p-6 space-y-5">
                                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4">
                                                <p className="max-w-md text-xs leading-5 text-zinc-600">
                                                    Arriving Mother Hub parcels need their sorting scan before the next bag. Check the parcel’s current stage if packing is blocked.
                                                </p>
                                                <Link href={route('hub.scan.station')} className="inline-flex items-center gap-1.5 text-xs font-bold text-[#121316] underline underline-offset-4 hover:text-zinc-700">
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
                                            {bag.packing_policy_version && <HandlingFields value={action.data.handling} onChange={value => action.setData('handling', value)} disabled={action.processing} />}
                                            <WaybillCamera
                                                continuous={continuousPack}
                                                cooldownMs={1800}
                                                buttonLabel={continuousPack ? 'Start continuous camera scan' : 'Scan actual waybill with camera'}
                                                stopLabel="Stop continuous camera"
                                                instruction={continuousPack
                                                    ? 'Point camera at each parcel barcode — valid waybills pack into this bag automatically without tapping Add.'
                                                    : 'Scan the parcel waybill to fill this field, then review before tapping Add parcel.'}
                                                onScan={value => {
                                                    if (continuousPack) {
                                                        packScannedWaybill(value);
                                                    } else {
                                                        action.setData('barcode', value);
                                                    }
                                                }}
                                                disabled={action.processing}
                                            />
                                            {activeParcels.length > 0 && (
                                                <div className="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 space-y-2.5">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className="text-xs font-bold text-[#121316]">
                                                            Recently scanned in this bag ({activeParcels.length})
                                                        </p>
                                                        <span className="text-[11px] text-zinc-500">Revert a mis-scan before sealing</span>
                                                    </div>
                                                    <div className="max-h-48 space-y-2 overflow-y-auto pr-1">
                                                        {activeParcels.slice(0, 8).map(item => (
                                                            <div key={item.id} className="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs">
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="rounded border border-zinc-200 bg-zinc-100 px-1.5 py-0.5 font-bold text-[#121316] tabular-nums">
                                                                            {item.tracking_number}
                                                                        </span>
                                                                        <span className="text-zinc-500">{words(item.status)}</span>
                                                                    </div>
                                                                    {revertingTracking === item.tracking_number ? (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => setRevertingTracking(null)}
                                                                            className="text-[11px] font-semibold text-zinc-500 hover:text-zinc-800 cursor-pointer"
                                                                        >
                                                                            Cancel
                                                                        </button>
                                                                    ) : (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => {
                                                                                setRevertingTracking(item.tracking_number);
                                                                                setRevertNotes('Mis-scanned parcel');
                                                                            }}
                                                                            className="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-[11px] font-semibold text-zinc-700 hover:border-red-200 hover:bg-red-50 hover:text-red-700 cursor-pointer transition"
                                                                        >
                                                                            <RotateCcw className="h-3 w-3" />
                                                                            Revert
                                                                        </button>
                                                                    )}
                                                                </div>
                                                                {revertingTracking === item.tracking_number && (
                                                                    <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-zinc-100 pt-2">
                                                                        <input
                                                                            type="text"
                                                                            maxLength={200}
                                                                            value={revertNotes}
                                                                            onChange={event => setRevertNotes(event.target.value)}
                                                                            placeholder="Reason (e.g. Wrong route)"
                                                                            className={`${hubInput} flex-1 py-1 text-xs`}
                                                                        />
                                                                        <button
                                                                            type="button"
                                                                            disabled={action.processing}
                                                                            onClick={() => revertParcel(item.tracking_number)}
                                                                            className="rounded-lg bg-red-600 px-2.5 py-1.5 text-[11px] font-bold text-white hover:bg-red-700 cursor-pointer disabled:opacity-50"
                                                                        >
                                                                            Confirm revert
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            <form onSubmit={event => submit(event, 'seal')} className="space-y-3 border-t border-zinc-100 pt-5">
                                                <div className="flex flex-wrap items-end gap-3">
                                                    <label className="min-w-0 flex-1 text-xs font-bold text-[#121316]">
                                                        Numbered seal
                                                        <input
                                                            required
                                                            maxLength={80}
                                                            className={`${hubInput} mt-1.5 tabular-nums`}
                                                            value={action.data.seal_number}
                                                            onChange={event => action.setData('seal_number', event.target.value)}
                                                            placeholder="Scan or type SL-XXXXX"
                                                        />
                                                    </label>
                                                    <button
                                                        type="button"
                                                        onClick={() => setShowSealScanner(value => !value)}
                                                        className={hubButton}
                                                    >
                                                        <ScanLine className="h-4 w-4" />
                                                        {showSealScanner ? 'Hide seal camera' : 'Scan seal'}
                                                    </button>
                                                    <HubButton primary disabled={action.processing || !bag.parcels_count}>
                                                        <LockKeyhole className="h-4 w-4" />
                                                        Seal bag
                                                    </HubButton>
                                                </div>
                                                {showSealScanner && (
                                                    <WaybillCamera
                                                        onScan={code => {
                                                            action.setData('seal_number', code);
                                                            setShowSealScanner(false);
                                                        }}
                                                        buttonLabel="Scan printed seal with camera"
                                                        instruction="Point camera at the printed seal barcode or QR to auto-fill SL-XXXXX."
                                                        disabled={action.processing}
                                                    />
                                                )}
                                            </form>
                                        </div>
                                    </section>
                                )}

                                {/* Load by Master Tag */}
                                {bag.canPack && bag.status === 'sealed' && !bag.manifest_id && (
                                    <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                        <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                            <div className="flex items-center gap-2">
                                                <Truck className="h-4 w-4 text-zinc-600" />
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
                                                        className="text-xs font-bold text-[#121316] underline underline-offset-4 hover:text-zinc-700 cursor-pointer"
                                                    >
                                                        Auto-fill this bag&apos;s tag ({bag.reference}) &amp; seal ({bag.seal_number})
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
                                                                className={`flex cursor-pointer items-start gap-3 rounded-xl border p-4 shadow-xs transition-colors focus-within:ring-2 focus-within:ring-[#E00D42]/15 ${
                                                                    action.data.manifest_id === String(item.id) ? 'border-[#E00D42] bg-rose-50/40 ring-1 ring-[#E00D42]/20' : 'border-zinc-200 bg-white hover:bg-zinc-50'
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

                                {/* Verify Arriving Seal */}
                                {bag.canOpen && bag.status === 'in_transit' && (
                                    <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                        <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                            <div className="flex items-center gap-2">
                                                <CheckCheck className="h-4 w-4 text-zinc-600" />
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
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 shadow-xs">
                                        <p className="text-xs sm:text-sm font-semibold text-[#121316]">
                                            All parcels arrived. Scan each waybill to record sorting before its next transfer or rider assignment.
                                        </p>
                                        <Link href={route('hub.scan.station')} className={hubButton}>
                                            <ScanLine className="h-4 w-4" />
                                            Open scan station
                                        </Link>
                                    </div>
                                )}

                                {bag.capacity && (
                                    <section aria-label="Bag capacity" className="space-y-3 rounded-xl border border-zinc-200 bg-white p-5 shadow-xs">
                                        <div className="flex flex-wrap justify-between gap-2">
                                            <h2 className="font-bold text-[#121316]">Packing capacity</h2>
                                            <span className="break-all text-xs text-zinc-600">Permanent sack: <strong>{bag.physical_tag ?? 'No reusable tag recorded'}</strong></span>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <CapacityMeter label="Parcels" value={bag.capacity.count} max={bag.capacity.max_count} unit="parcels" />
                                            <CapacityMeter label="Weight" value={bag.capacity.weight_kg} max={bag.capacity.max_weight_kg} unit="kg" />
                                            <CapacityMeter label="Volume" value={bag.capacity.volume_cm3 === null ? null : Number((bag.capacity.volume_cm3 / 1000000).toFixed(4))} max={Number(((bag.capacity.max_volume_cm3 || 300000) / 1000000).toFixed(2))} unit="m³" />
                                        </div>
                                        <p className="text-xs text-zinc-500">Declared sizes or operator readings guide packing. Check the actual fit before attaching the numbered seal.</p>
                                    </section>
                                )}

                                {/* Parcel Contents Table */}
                                <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2">
                                            <Package className="h-4 w-4 text-zinc-600" />
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
                                                            <span className="rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 tabular-nums text-xs font-bold text-[#121316]">
                                                                {item.tracking_number}
                                                            </span><HandlingSummary value={item.handling} />
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
                                                            <HubBadge tone={item.received ? 'brand' : 'neutral'}>
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

                                {/* Master Tag & Route Card */}
                                <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                    <header className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2.5">
                                            <Container className="h-4 w-4 text-zinc-600" />
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
                                                    <span className="rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 tabular-nums text-xs font-bold text-[#121316]">
                                                        {bag.reference}
                                                    </span>
                                                    <HubBadge tone={bag.status === 'received' ? 'brand' : 'neutral'}>{words(bag.status)}</HubBadge>
                                                </div>
                                                <p className="text-xs sm:text-sm text-zinc-600 font-medium">
                                                    {bag.parcels_count} parcels · {bag.seal_number ? `Seal ${bag.seal_number}` : 'Seal not attached'}
                                                </p>
                                                {bag.manifest_id && (
                                                    <Link
                                                        className="inline-block text-xs sm:text-sm font-bold text-[#121316] hover:text-zinc-700 underline underline-offset-4"
                                                        href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}`}
                                                    >
                                                        Open transport manifest
                                                    </Link>
                                                )}
                                            </div>
                                            <div data-bag-label className="rounded-xl border border-zinc-200 bg-white p-4 text-center shadow-xs">
                                                <QRCodeSVG value={bag.reference} size={96} />
                                                <p className="mt-2.5 text-xs tabular-nums font-bold text-[#121316]">{bag.reference}</p>
                                                <p className="mt-1 text-xs text-zinc-500 font-medium">{bag.source.code} → {bag.destination.code}</p>
                                                {bag.physical_tag && <p className="mt-1 break-all text-xs text-zinc-600">Sack {bag.physical_tag}</p>}
                                                {bag.seal_number && <p className="mt-1 break-all text-xs tabular-nums text-zinc-600">Seal {bag.seal_number}</p>}
                                            </div>
                                        </div>
                                    </div>
                                </section>

                                {/* Correct Packing or Loading */}
                                {bag.canPack && ['open', 'sealed'].includes(bag.status) && (
                                    <details className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
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
                                                    Numbered seal
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

                                {/* Bag Activity Log */}
                                <details className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
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
                        )}
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
