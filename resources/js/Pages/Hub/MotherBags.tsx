import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Box, CheckCheck, ChevronRight, LockKeyhole, Printer, RotateCcw, ScanLine, Truck } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubButton, HubCategoryTabs, InfoHint, HubSearch, hubButton, hubInput, hubPrimary, type HubPlace } from '@/Components/Logistics/Workspace';
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
    const [loadScannerTarget, setLoadScannerTarget] = useState<'barcode' | 'seal' | null>(null);
    const [verifyScannerTarget, setVerifyScannerTarget] = useState<'barcode' | 'seal' | null>(null);
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
    const prefillBagFields = Boolean(bag && ['sealed', 'in_transit'].includes(bag.status));
    const defaultManifestId = bag?.status === 'sealed' && !bag.manifest_id && matchingManifests.length === 1
        ? String(matchingManifests[0].id)
        : '';
    const action = useForm({
        request_token: commandToken,
        handling: emptyHandling(),
        version: bag?.version ?? 1,
        barcode: prefillBagFields ? (bag?.reference ?? '') : '',
        seal_number: prefillBagFields ? (bag?.seal_number ?? '') : '',
        manifest_id: defaultManifestId,
        notes: '',
        seal_intact: bag?.status === 'in_transit',
    });
    useEffect(() => {
        setManifestScan('');
        setRevertingTracking(null);
        setShowSealScanner(false);
        setLoadScannerTarget(null);
        setVerifyScannerTarget(null);
        const shouldPrefill = Boolean(bag && ['sealed', 'in_transit'].includes(bag.status));
        const autoManifestId = bag?.status === 'sealed' && !bag.manifest_id && matchingManifests.length === 1
            ? String(matchingManifests[0].id)
            : '';
        action.setData({
            request_token: commandToken,
            handling: emptyHandling(),
            version: bag?.version ?? 1,
            barcode: shouldPrefill ? (bag?.reference ?? '') : '',
            seal_number: shouldPrefill ? (bag?.seal_number ?? '') : '',
            manifest_id: autoManifestId,
            notes: '',
            seal_intact: bag?.status === 'in_transit',
        });
        action.clearErrors();
    }, [bag?.id, bag?.version, bag?.status]);
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
            <div className="space-y-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">Mother bags</h1>
                        {packableHubs.length === 1 && <p className="mt-1 text-sm text-ink-600">{packableHubs[0].name} · {packableHubs[0].code}</p>}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {bag && (
                            <Link href={basePath} className={hubButton}>
                                <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                Auto-pack parcel
                            </Link>
                        )}
                        <Link href={route('hub.manifests.index')} className={hubButton}>
                            <Truck className="h-4 w-4 text-ink-500" aria-hidden="true" />
                            Manifests
                        </Link>
                    </div>
                </div>

                {error.length > 0 && (
                    <div role="alert" className="rounded-control border border-brand-100 bg-brand-50 px-4 py-3 text-sm font-medium text-brand-700">
                        {error.join(' ')}
                    </div>
                )}

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-[380px_1fr]">
                    <div className="space-y-6">
                        {/* Bags directory */}
                        <section aria-label="Your bags" className="rounded-card border border-line-200 bg-white">
                            <div className="space-y-3 px-5 pt-5">
                                <h2 className="text-base font-semibold text-ink-900">Bags</h2>
                                <HubSearch label="Search loaded bags" value={search} onChange={setSearch} placeholder="Search hub, tag or seal" />
                            </div>
                            <div className="mt-3 overflow-x-auto px-3">
                                <HubCategoryTabs
                                    label="Filter mother bags"
                                    activeId={bagCategory}
                                    onChange={id => setBagCategory(id as typeof bagCategory)}
                                    tabs={[
                                        { id: 'all', label: 'All', count: bags.data.length },
                                        { id: 'open', label: 'Packing', count: openBagsCount },
                                        { id: 'sealed', label: 'Sealed', count: sealedBagsCount },
                                        { id: 'transit', label: 'Moving', count: transitBagsCount },
                                    ]}
                                />
                            </div>

                            <div className="p-3">
                                {visibleBags.length > 0 && (
                                    <ul className="space-y-2">
                                        {visibleBags.map(item => {
                                            const fill = item.capacity && item.capacity.max_count > 0 ? Math.min(100, Math.round((item.capacity.count / item.capacity.max_count) * 100)) : null;
                                            const selected = bag?.id === item.id;
                                            return (
                                                <li key={item.id}>
                                                    <Link
                                                        href={`${basePath}?bag=${item.id}`}
                                                        preserveScroll
                                                        aria-current={selected ? 'true' : undefined}
                                                        className={`group relative block rounded-control border px-3 py-3 pr-9 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50' : 'border-line-200 bg-white hover:border-ink-400 hover:bg-surface-50'}`}
                                                    >
                                                        <ChevronRight aria-hidden="true" className={`absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 transition-transform group-hover:translate-x-0.5 ${selected ? 'text-brand-500' : 'text-ink-400'}`} />
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="min-w-0 truncate text-sm font-semibold tabular-nums text-ink-900" title={item.reference}>{item.reference}</span>
                                                            <span className="flex shrink-0 items-center gap-1.5">
                                                                {item.reference.startsWith('RMB-') && <HubBadge tone="warning">Return</HubBadge>}
                                                                <HubBadge tone={item.status === 'open' ? 'brand' : item.status === 'received' ? 'success' : 'neutral'}>{words(item.status)}</HubBadge>
                                                            </span>
                                                        </div>
                                                        <p className="mt-1 flex min-w-0 items-center gap-1.5 text-[13px] text-ink-700">
                                                            <span className="truncate">{item.source.name}</span>
                                                            <ArrowRight className="h-3.5 w-3.5 shrink-0 text-ink-400" aria-hidden="true" />
                                                            <span className="truncate">{item.destination.name}</span>
                                                        </p>
                                                        <div className="mt-2 flex items-center gap-3">
                                                            {fill !== null && (
                                                                <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-100" role="img" aria-label={`${fill}% full`}>
                                                                    <span className={`block h-full rounded-full ${fill >= 90 ? 'bg-brand-500' : 'bg-brand-200'}`} style={{ width: `${fill}%` }} />
                                                                </span>
                                                            )}
                                                            <span className="shrink-0 text-xs tabular-nums text-ink-600">
                                                                {item.capacity ? `${item.capacity.count}/${item.capacity.max_count}` : item.parcels_count} parcels{item.seal_number ? ` · Seal ${item.seal_number}` : ''}
                                                            </span>
                                                        </div>
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}

                                {bags.data.length > 0 && visibleBags.length === 0 && (
                                    <p className="px-3 py-6 text-center text-sm text-ink-600">No bags match.</p>
                                )}

                                {bags.data.length === 0 && (
                                    <p className="px-3 py-6 text-center text-sm text-ink-600">No bags yet. Create one below.</p>
                                )}

                                {bags.links.filter(link => link.url).length > 1 && (
                                    <div className="flex flex-wrap gap-2 px-3 pt-2">
                                        {bags.links.filter(link => link.url).map((link, i) => (
                                            <Link key={i} href={link.url!} preserveScroll className={hubButton} dangerouslySetInnerHTML={{ __html: link.label }} />
                                        ))}
                                    </div>
                                )}
                            </div>
                        </section>

                        {/* New bag */}
                        <details open={bags.data.length === 0} className="group rounded-card border border-line-200 bg-white">
                            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-card px-5 py-4 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 [&::-webkit-details-marker]:hidden">
                                <span className="flex items-center gap-2 text-base font-semibold text-ink-900">
                                    <Box className="h-4 w-4 text-brand-500" aria-hidden="true" />
                                    New bag
                                </span>
                            </summary>
                            <div className="border-t border-line-200 px-5 pb-5 pt-4">
                                {activeHubRecord && activeHubRecord.canPackDispatch === false && (
                                    <p role="status" className="mb-4 rounded-control bg-warning-soft px-3 py-2 text-[13px] text-warning">
                                        Your role at {activeHubRecord.name} is Entry / Intake only. Ask a manager for Pack &amp; Dispatch to create bags.
                                    </p>
                                )}
                                <form onSubmit={event => { event.preventDefault(); create.post(basePath); }} className="space-y-5">
                                    {packableHubs.length === 1 ? (
                                        <div>
                                            <p className="mb-1.5 text-[13px] font-medium text-ink-700">From</p>
                                            <p className="rounded-control bg-surface-100 px-3 py-2.5 text-sm font-medium text-ink-900">{packableHubs[0].name} <span className="font-normal text-ink-500">· {packableHubs[0].code}</span></p>
                                        </div>
                                    ) : (
                                        <HubPicker label="From" hubs={packableHubs} value={create.data.source_hub_id} onChange={id => create.setData({ ...create.data, source_hub_id: id, destination_hub_id: '' })} error={create.errors.source_hub_id} />
                                    )}

                                    {receivingHubs.length > 0 && receivingHubs.length <= 4 ? (
                                        <fieldset>
                                            <legend className="mb-1.5 text-[13px] font-medium text-ink-700">To</legend>
                                            <div className="grid gap-2">
                                                {receivingHubs.map(hub => {
                                                    const selected = create.data.destination_hub_id === String(hub.id);
                                                    return (
                                                        <label key={hub.id} className={`flex cursor-pointer items-center gap-3 rounded-control border px-3 py-2.5 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50/60' : 'border-line-300 hover:border-ink-400'}`}>
                                                            <input type="radio" name="destination_hub_id" value={hub.id} checked={selected} onChange={() => create.setData('destination_hub_id', String(hub.id))} className="sr-only" />
                                                            <span aria-hidden="true" className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${selected ? 'border-brand-500' : 'border-line-300'}`}>{selected && <span className="h-2 w-2 rounded-full bg-brand-500" />}</span>
                                                            <span className="min-w-0 flex-1">
                                                                <span className="block truncate text-sm font-medium text-ink-900">{hub.name}</span>
                                                                <span className="block text-xs text-ink-600">{hub.code} · {hub.tier === 'regional_mother_hub' ? 'Mother hub' : 'Bayan hub'}</span>
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                            {create.errors.destination_hub_id && <p className="mt-1.5 text-[13px] text-brand-700">{create.errors.destination_hub_id}</p>}
                                        </fieldset>
                                    ) : (
                                        <HubPicker label="To" hubs={receivingHubs} value={create.data.destination_hub_id} onChange={id => create.setData('destination_hub_id', id)} disabled={!source} error={create.errors.destination_hub_id} />
                                    )}

                                    <fieldset>
                                        <legend className="mb-1.5 text-[13px] font-medium text-ink-700">Bag size</legend>
                                        <div className="grid grid-cols-2 gap-2">
                                            {[...bagPresets.map(preset => ({ key: preset.key, title: preset.label.split('(')[0].trim(), detail: `${preset.max_count} parcels · ${preset.max_weight_kg} kg`, preset })), { key: 'custom', title: 'Custom', detail: 'Set your own limits', preset: null as BagPreset | null }].map(option => {
                                                const selected = create.data.capacity_preset === option.key;
                                                return (
                                                    <label key={option.key} className={`cursor-pointer rounded-control border px-3 py-2.5 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50/60' : 'border-line-300 hover:border-ink-400'}`}>
                                                        <input type="radio" name="capacity_preset" value={option.key} checked={selected} className="sr-only" onChange={() => {
                                                            if (option.preset) {
                                                                create.setData({
                                                                    ...create.data,
                                                                    capacity_preset: option.key,
                                                                    max_count: String(option.preset.max_count),
                                                                    max_weight_kg: String(option.preset.max_weight_kg),
                                                                    max_volume_m3: String(Number((option.preset.max_volume_cm3 / 1000000).toFixed(2))),
                                                                });
                                                            } else {
                                                                create.setData('capacity_preset', option.key);
                                                            }
                                                        }} />
                                                        <span className={`block truncate text-sm font-medium ${selected ? 'text-brand-600' : 'text-ink-900'}`}>{option.title}</span>
                                                        <span className="block text-xs tabular-nums text-ink-600">{option.detail}</span>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </fieldset>
                                    {create.data.capacity_preset === 'custom' && (
                                        <div className="grid grid-cols-3 gap-2">
                                            <label className="text-xs font-medium text-ink-700">
                                                Max parcels
                                                <input type="number" min={1} max={500} required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_count} onChange={event => create.setData('max_count', event.target.value)} />
                                            </label>
                                            <label className="text-xs font-medium text-ink-700">
                                                Max kg
                                                <input type="number" min={1} max={200} step="0.5" required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_weight_kg} onChange={event => create.setData('max_weight_kg', event.target.value)} />
                                            </label>
                                            <label className="text-xs font-medium text-ink-700">
                                                Max m³
                                                <input type="number" min={0.01} max={2} step="0.01" required className={`${hubInput} mt-1 tabular-nums`} value={create.data.max_volume_m3} onChange={event => create.setData('max_volume_m3', event.target.value)} />
                                            </label>
                                        </div>
                                    )}
                                    <button type="submit" disabled={create.processing || !source || !destination} className={`${hubPrimary} w-full`}>
                                        {create.processing ? 'Creating…' : 'Create bag'}
                                    </button>
                                </form>
                            </div>
                        </details>
                    </div>

                    {/* Right Column: Auto-pack (default) OR Selected Bag Workspace; first on phones */}
                    <div className="order-first min-w-0 space-y-5 lg:order-none">
                        {!packingAvailable && (
                            <p role="status" className="rounded-control bg-warning-soft p-4 text-sm text-warning">
                                Packing upgrades need the additive database migration. Existing trip history remains available.
                            </p>
                        )}

                        {!bag && packingAvailable && (
                            <section aria-labelledby="pack-title" className="rounded-card border border-line-200 bg-white">
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
                                    <div className="space-y-4 p-5 sm:p-6">
                                        <div className="flex items-start gap-3">
                                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500">
                                                <ScanLine className="h-5 w-5" aria-hidden="true" />
                                            </span>
                                            <div>
                                                <h2 id="pack-title" className="text-lg font-semibold text-ink-900">Pack a parcel</h2>
                                                <p className="text-sm text-ink-600">Scan a waybill. It goes into the open bag for its next hub.</p>
                                            </div>
                                        </div>
                                        {packableHubs.length > 1 && (
                                            <HubPicker label="Packing at" hubs={packableHubs} value={packing.data.source_hub_id} onChange={id => packing.setData('source_hub_id', id)} />
                                        )}
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <label className="min-w-0 flex-1">
                                                <span className="sr-only">Parcel waybill</span>
                                                <input
                                                    required
                                                    maxLength={255}
                                                    autoComplete="off"
                                                    placeholder="Scan or type waybill"
                                                    className={`${hubInput} h-12 text-base`}
                                                    value={packing.data.barcode}
                                                    onChange={event => packing.setData('barcode', event.target.value)}
                                                />
                                            </label>
                                            <button type="submit" disabled={packing.processing || !packing.data.source_hub_id} className={`${hubPrimary} h-12 px-6`}>
                                                {packing.processing ? 'Adding…' : 'Add to bag'}
                                            </button>
                                        </div>
                                        <HandlingFields value={packing.data.handling} onChange={value => packing.setData('handling', value)} disabled={packing.processing} />
                                        <details className="rounded-control bg-surface-50 px-4 py-3">
                                            <summary className="cursor-pointer text-sm font-medium text-ink-900">Reusable sack tag (optional)</summary>
                                            <label className="mt-3 block text-xs font-medium text-ink-700">
                                                Permanent sack tag
                                                <input maxLength={80} pattern="[A-Z0-9-]+" className={`${hubInput} mt-1.5`} value={packing.data.physical_tag} onChange={event => packing.setData('physical_tag', event.target.value.toUpperCase())} />
                                            </label>
                                            <label className="mt-3 flex gap-2 text-[13px] text-ink-700">
                                                <input type="checkbox" checked={packing.data.physical_checked} onChange={event => packing.setData('physical_checked', event.target.checked)} className="rounded-chip border-line-300 text-brand-500 focus:ring-brand-500" />
                                                I checked this permanent tag on the sack at this hub.
                                            </label>
                                        </details>
                                    </div>
                                    <div className="border-t border-line-200 px-5 py-3 text-[13px] text-ink-600 sm:px-6">
                                        Over 15 kg or a side over 60 cm? Use{' '}
                                        <Link className="font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900" href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests`}>
                                            loose cargo in Manifests
                                        </Link>.
                                    </div>
                                </form>
                            </section>
                        )}

                        {bag && (() => {
                            const manifestHref = bag.manifest_id ? `${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}` : null;
                            const stages = ['Packing', 'Sealed', 'Loaded', 'In transit', 'Received'];
                            const stageIndex = bag.status === 'open' ? 0 : bag.status === 'sealed' ? (bag.manifest_id ? 2 : 1) : bag.status === 'in_transit' ? 3 : 4;
                            const card = 'rounded-card border border-line-200 bg-white';
                            const fieldLabel = 'block text-[13px] font-medium text-ink-700';
                            return (
                            <div className="space-y-5">
                                {/* Bag overview: identity, lifecycle, route, capacity and printable tag */}
                                <section aria-label="Selected bag" className={`${card} p-5 sm:p-6`}>
                                    <div className="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                                        <div className="min-w-0 flex-1 space-y-4">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2 className="text-xl font-bold tabular-nums text-ink-900">{bag.reference}</h2>
                                                    <HubBadge tone={bag.status === 'open' ? 'brand' : bag.status === 'received' ? 'success' : 'neutral'}>{words(bag.status)}</HubBadge>
                                                </div>
                                                <p className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-ink-700">
                                                    <span>{bag.source.name}</span>
                                                    <ArrowRight className="h-3.5 w-3.5 text-ink-400" aria-hidden="true" />
                                                    <span>{bag.destination.name}</span>
                                                </p>
                                                <p className="mt-0.5 text-[13px] text-ink-600">
                                                    {bag.parcels_count} parcels · {bag.seal_number ? `Seal ${bag.seal_number}` : 'Not sealed'}
                                                    {bag.physical_tag && <> · Sack {bag.physical_tag}</>}
                                                    {manifestHref && <> · <Link className="font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900" href={manifestHref}>Open transport manifest</Link></>}
                                                </p>
                                            </div>

                                            <ol aria-label="Bag progress" className="grid grid-cols-5 gap-1">
                                                {stages.map((label, index) => (
                                                    <li key={label} aria-current={index === stageIndex ? 'step' : undefined} className="min-w-0">
                                                        <span aria-hidden="true" className={`block h-1.5 rounded-full ${index <= stageIndex ? 'bg-brand-500' : 'bg-line-200'}`} />
                                                        <span className={`mt-1.5 block truncate text-xs ${index === stageIndex ? 'font-semibold text-brand-600' : index < stageIndex ? 'text-ink-700' : 'text-ink-500'}`}>{label}</span>
                                                    </li>
                                                ))}
                                            </ol>

                                            {bag.capacity && (
                                                <div aria-label="Bag capacity">
                                                    <p className="mb-2 text-[13px] font-medium text-ink-700">Packing capacity</p>
                                                    <div className="grid gap-3 sm:grid-cols-3">
                                                        <CapacityMeter label="Parcels" value={bag.capacity.count} max={bag.capacity.max_count} unit="" />
                                                        <CapacityMeter label="Weight" value={bag.capacity.weight_kg} max={bag.capacity.max_weight_kg} unit="kg" />
                                                        <CapacityMeter label="Volume" value={bag.capacity.volume_cm3 === null ? null : Number((bag.capacity.volume_cm3 / 1000000).toFixed(4))} max={Number(((bag.capacity.max_volume_cm3 || 300000) / 1000000).toFixed(2))} unit="m³" />
                                                    </div>
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex shrink-0 flex-row items-center gap-3 sm:flex-col sm:gap-2">
                                            <div data-bag-label className="rounded-control border border-line-200 bg-white p-3 text-center">
                                                <QRCodeSVG value={bag.reference} size={88} />
                                                <p className="mt-2 text-xs font-semibold tabular-nums text-ink-900">{bag.reference}</p>
                                                <p className="text-xs text-ink-600">{bag.source.code} → {bag.destination.code}</p>
                                                {bag.physical_tag && <p className="break-all text-xs text-ink-600">Sack {bag.physical_tag}</p>}
                                                {bag.seal_number && <p className="break-all text-xs tabular-nums text-ink-600">Seal {bag.seal_number}</p>}
                                            </div>
                                            <button type="button" onClick={() => window.print()} className={`${hubButton} min-h-9 px-3 text-[13px]`}>
                                                <Printer className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                Print tag
                                            </button>
                                        </div>
                                    </div>
                                </section>

                                {/* Next action for this stage */}
                                {!bag.canPack && bag.status === 'open' && (
                                    <p role="status" className="rounded-control bg-warning-soft px-4 py-3 text-[13px] text-warning">
                                        Packing this bag needs a Pack &amp; Dispatch or Full Hub Operations role at {bag.source.name}.
                                    </p>
                                )}

                                {bag.canPack && bag.status === 'open' && (
                                    <section aria-labelledby="pack-seal-title" className={card}>
                                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                                            <h2 id="pack-seal-title" className="flex items-center gap-1 text-lg font-semibold text-ink-900">
                                                Pack and seal
                                                <InfoHint>Scan parcels into this bag, revert any mis-scan, then scan or type the numbered seal. Arriving Mother Hub parcels need their sorting scan in Scan Station first.</InfoHint>
                                            </h2>
                                            <label className="inline-flex cursor-pointer items-center gap-2 text-[13px] font-medium text-ink-700">
                                                <input type="checkbox" checked={continuousPack} onChange={event => setContinuousPack(event.target.checked)} className="rounded-chip border-line-300 text-brand-500 focus:ring-brand-500" />
                                                Hands-free continuous auto-add
                                            </label>
                                        </div>
                                        <div className="space-y-5 p-5 sm:p-6">
                                            <form onSubmit={event => submit(event, 'add')} className="flex flex-col gap-2 sm:flex-row">
                                                <label className="min-w-0 flex-1">
                                                    <span className="sr-only">Parcel waybill</span>
                                                    <input autoFocus required autoComplete="off" className={`${hubInput} h-12 text-base`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} placeholder="Scan or type waybill" />
                                                </label>
                                                <button disabled={action.processing} className={`${hubPrimary} h-12 px-6`}>{action.processing ? 'Adding…' : 'Add parcel'}</button>
                                            </form>
                                            {bag.packing_policy_version && <HandlingFields value={action.data.handling} onChange={value => action.setData('handling', value)} disabled={action.processing} />}
                                            <WaybillCamera
                                                continuous={continuousPack}
                                                cooldownMs={1800}
                                                buttonLabel={continuousPack ? 'Start continuous camera scan' : 'Scan waybill with camera'}
                                                stopLabel="Stop continuous camera"
                                                instruction={continuousPack ? 'Point the camera at each barcode. Valid waybills are added automatically.' : 'Scan a waybill to fill the field, then tap Add parcel.'}
                                                onScan={value => { if (continuousPack) packScannedWaybill(value); else action.setData('barcode', value); }}
                                                disabled={action.processing}
                                            />

                                            {activeParcels.length > 0 && (
                                                <div>
                                                    <p className="mb-2 text-[13px] font-medium text-ink-700">Recently scanned in this bag <span className="font-normal text-ink-500">· {activeParcels.length}</span></p>
                                                    <ul className="max-h-56 divide-y divide-line-200 overflow-y-auto rounded-control border border-line-200">
                                                        {activeParcels.slice(0, 8).map(item => (
                                                            <li key={item.id} className="px-3 py-2 text-[13px]">
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <span className="min-w-0"><span className="font-medium tabular-nums text-ink-900">{item.tracking_number}</span> <span className="text-ink-500">· {words(item.status)}</span></span>
                                                                    {revertingTracking === item.tracking_number ? (
                                                                        <button type="button" onClick={() => setRevertingTracking(null)} className="text-xs font-medium text-ink-600 hover:text-ink-900">Cancel</button>
                                                                    ) : (
                                                                        <button type="button" onClick={() => { setRevertingTracking(item.tracking_number); setRevertNotes('Mis-scanned parcel'); }}
                                                                            className="inline-flex items-center gap-1 rounded-control px-2 py-1 text-xs font-medium text-ink-700 hover:bg-brand-50 hover:text-brand-700">
                                                                            <RotateCcw className="h-3 w-3" aria-hidden="true" />
                                                                            Revert
                                                                        </button>
                                                                    )}
                                                                </div>
                                                                {revertingTracking === item.tracking_number && (
                                                                    <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                        <input type="text" maxLength={200} value={revertNotes} onChange={event => setRevertNotes(event.target.value)} placeholder="Reason, e.g. wrong route" className={`${hubInput} min-h-9 flex-1 py-1 text-[13px]`} />
                                                                        <button type="button" disabled={action.processing} onClick={() => revertParcel(item.tracking_number)}
                                                                            className="min-h-9 rounded-control border border-brand-700 px-3 text-xs font-semibold text-brand-700 hover:bg-brand-50 disabled:opacity-50">
                                                                            Confirm revert
                                                                        </button>
                                                                    </div>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            )}

                                            <form onSubmit={event => submit(event, 'seal')} className="space-y-3 border-t border-line-200 pt-5">
                                                <div className="flex flex-wrap items-end gap-2">
                                                    <label className="min-w-0 flex-1">
                                                        <span className={fieldLabel}>Numbered seal</span>
                                                        <input required maxLength={80} className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} placeholder="Scan or type SL-XXXXX" />
                                                    </label>
                                                    <button
                                                        type="button"
                                                        onClick={() => action.setData('seal_number', `SL-${bag.reference.replace(/^BAG-|^RMB-/, '').slice(0, 6)}-${bag.version}`)}
                                                        className={hubButton}
                                                    >
                                                        Auto-fill seal
                                                    </button>
                                                    <button type="button" onClick={() => setShowSealScanner(value => !value)} className={hubButton}>
                                                        <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                        {showSealScanner ? 'Hide seal camera' : 'Scan seal'}
                                                    </button>
                                                    <HubButton primary disabled={action.processing || !bag.parcels_count}>
                                                        <LockKeyhole className="h-4 w-4" aria-hidden="true" />
                                                        Seal bag
                                                    </HubButton>
                                                </div>
                                                {showSealScanner && (
                                                    <WaybillCamera onScan={code => { action.setData('seal_number', code); setShowSealScanner(false); }} buttonLabel="Scan printed seal with camera" instruction="Point the camera at the seal barcode or QR." disabled={action.processing} />
                                                )}
                                            </form>
                                        </div>
                                    </section>
                                )}

                                {bag.canPack && bag.status === 'sealed' && !bag.manifest_id && (
                                    <section aria-labelledby="load-title" className={card}>
                                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                                            <div>
                                                <h2 id="load-title" className="text-lg font-semibold text-ink-900">Load onto a vehicle</h2>
                                                <p className="mt-0.5 text-xs text-ink-600">Master bag tag and seal are pre-filled from this sealed bag. Scan with camera or confirm the vehicle trip below.</p>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setLoadScannerTarget(current => (current === 'barcode' ? null : 'barcode'))}
                                                    className={hubButton}
                                                >
                                                    <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                    {loadScannerTarget === 'barcode' ? 'Hide bag camera' : 'Scan bag tag'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setLoadScannerTarget(current => (current === 'seal' ? null : 'seal'))}
                                                    className={hubButton}
                                                >
                                                    <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                    {loadScannerTarget === 'seal' ? 'Hide seal camera' : 'Scan seal'}
                                                </button>
                                                <button type="button" onClick={() => action.setData({ ...action.data, barcode: bag.reference, seal_number: bag.seal_number || '' })}
                                                    className="text-[13px] font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900">
                                                    Fill tag and seal from this bag
                                                </button>
                                            </div>
                                        </div>
                                        <form onSubmit={event => submit(event, 'load')} className="space-y-5 p-5 sm:p-6">
                                            {loadScannerTarget && (
                                                <WaybillCamera
                                                    onScan={code => {
                                                        action.setData(loadScannerTarget === 'barcode' ? 'barcode' : 'seal_number', code);
                                                        setLoadScannerTarget(null);
                                                    }}
                                                    buttonLabel={loadScannerTarget === 'barcode' ? 'Scan master bag QR / barcode' : 'Scan numbered seal barcode'}
                                                    instruction={loadScannerTarget === 'barcode' ? 'Point the camera at the master bag QR code or barcode.' : 'Point the camera at the numbered seal barcode.'}
                                                    disabled={action.processing}
                                                />
                                            )}
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <label><span className={fieldLabel}>Master bag barcode</span><input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} /></label>
                                                <label><span className={fieldLabel}>Numbered seal</span><input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} /></label>
                                            </div>
                                            <fieldset className="space-y-3">
                                                <legend className={`${fieldLabel} mb-1.5`}>Vehicle trip</legend>
                                                {matchingManifests.length > 0 ? (
                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                        {matchingManifests.map(item => {
                                                            const selected = action.data.manifest_id === String(item.id);
                                                            return (
                                                                <label key={item.id} className={`flex cursor-pointer items-center gap-3 rounded-control border px-3 py-2.5 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50/60' : 'border-line-300 hover:border-ink-400'}`}>
                                                                    <input type="radio" name="bag-manifest" required value={item.id} checked={selected} onChange={() => { action.setData('manifest_id', String(item.id)); setManifestScan(''); }} className="sr-only" />
                                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-warning-soft text-warning"><Truck className="h-4 w-4" aria-hidden="true" /></span>
                                                                    <span className="min-w-0">
                                                                        <span className="block truncate text-sm font-medium text-ink-900">{item.vehicle_plate ?? `Transfer #${item.id}`}</span>
                                                                        <span className="block truncate text-xs text-ink-600">{item.driver_name ?? 'Recorded driver'} · {item.reference}</span>
                                                                    </span>
                                                                </label>
                                                            );
                                                        })}
                                                    </div>
                                                ) : (
                                                    <p className="rounded-control bg-warning-soft p-3 text-[13px] text-warning">No vehicle trip matches this route yet. Prepare one in Manifests, then come back.</p>
                                                )}
                                                <details className="text-[13px]">
                                                    <summary className="cursor-pointer font-medium text-ink-700">Scan a trip reference instead</summary>
                                                    <input className={`${hubInput} mt-2`} value={manifestScan} autoComplete="off" placeholder="Scan manifest reference"
                                                        onChange={event => {
                                                            setManifestScan(event.target.value);
                                                            const match = matchingManifests.find(item => item.reference.toUpperCase() === event.target.value.trim().toUpperCase());
                                                            action.setData('manifest_id', match ? String(match.id) : '');
                                                        }} />
                                                </details>
                                            </fieldset>
                                            <HubButton primary disabled={action.processing || !action.data.manifest_id}>Load sealed bag</HubButton>
                                        </form>
                                    </section>
                                )}

                                {bag.canOpen && bag.status === 'in_transit' && (
                                    <section aria-labelledby="verify-title" className={card}>
                                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                                            <div>
                                                <h2 id="verify-title" className="flex items-center gap-1 text-lg font-semibold text-ink-900">
                                                    Verify arriving seal
                                                    <InfoHint>Check the master tag and physical seal before receiving the parcels inside.</InfoHint>
                                                </h2>
                                                <p className="mt-0.5 text-xs text-ink-600">Expected bag tag and seal are pre-filled. Scan the bag QR/barcode with your camera or verify directly.</p>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setVerifyScannerTarget(current => (current === 'barcode' ? null : 'barcode'))}
                                                    className={hubButton}
                                                >
                                                    <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                    {verifyScannerTarget === 'barcode' ? 'Hide bag scanner' : 'Scan bag QR / barcode'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setVerifyScannerTarget(current => (current === 'seal' ? null : 'seal'))}
                                                    className={hubButton}
                                                >
                                                    <ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />
                                                    {verifyScannerTarget === 'seal' ? 'Hide seal scanner' : 'Scan seal'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => action.setData({ ...action.data, barcode: bag.reference, seal_number: bag.seal_number || '', seal_intact: true })}
                                                    className="text-[13px] font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900"
                                                >
                                                    Fill tag and seal from this bag
                                                </button>
                                            </div>
                                        </div>
                                        <form onSubmit={event => submit(event, 'open')} className="space-y-4 p-5 sm:p-6">
                                            {verifyScannerTarget && (
                                                <WaybillCamera
                                                    onScan={code => {
                                                        action.setData(verifyScannerTarget === 'barcode' ? 'barcode' : 'seal_number', code);
                                                        setVerifyScannerTarget(null);
                                                    }}
                                                    buttonLabel={verifyScannerTarget === 'barcode' ? 'Start camera for master bag QR / barcode' : 'Start camera for seal barcode'}
                                                    instruction={verifyScannerTarget === 'barcode' ? 'Point the camera at the arriving master bag QR or barcode tag.' : 'Point the camera at the arriving physical seal barcode.'}
                                                    disabled={action.processing}
                                                />
                                            )}
                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <label><span className={fieldLabel}>Scanned master bag tag</span><input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} /></label>
                                                <label><span className={fieldLabel}>Seal number observed</span><input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} /></label>
                                            </div>
                                            <label className="flex items-center gap-2.5 text-sm text-ink-700">
                                                <input required type="checkbox" checked={action.data.seal_intact} onChange={event => action.setData('seal_intact', event.target.checked)} className="rounded-chip border-line-300 text-brand-500 focus:ring-brand-500" />
                                                The physical numbered seal is intact and matches.
                                            </label>
                                            <HubButton primary disabled={action.processing}>Verify and open bag</HubButton>
                                        </form>
                                    </section>
                                )}

                                {bag.status === 'received' && (
                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-card border border-success/20 bg-success-soft px-5 py-4">
                                        <p className="flex items-center gap-2 text-sm font-medium text-ink-900"><CheckCheck className="h-4 w-4 text-success" aria-hidden="true" />All parcels arrived. Next: scan each waybill to sort it.</p>
                                        <Link href={route('hub.scan.station')} className={hubButton}><ScanLine className="h-4 w-4 text-ink-500" aria-hidden="true" />Open scan station</Link>
                                    </div>
                                )}

                                {/* Contents */}
                                <section aria-labelledby="contents-title" className={card}>
                                    <h2 id="contents-title" className="px-5 pt-5 text-base font-semibold text-ink-900 sm:px-6">Parcel contents <span className="font-normal text-ink-500">· {bag.parcels.length}</span></h2>
                                    {bag.parcels.length === 0 ? (
                                        <p className="px-5 py-8 text-center text-sm text-ink-600 sm:px-6">Scan the first parcel to begin packing.</p>
                                    ) : (
                                        <ul className="mt-3 divide-y divide-line-200 border-t border-line-200">
                                            {bag.parcels.map(item => (
                                                <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
                                                    <div className="min-w-0">
                                                        <p className="text-sm font-medium tabular-nums text-ink-900">{item.tracking_number}</p>
                                                        <p className="flex flex-wrap items-center gap-2 text-xs text-ink-600">
                                                            {item.removed ? 'Removed from bag' : words(item.status)}
                                                            {item.status === 'return_to_sender' && <HubBadge tone="warning">Return</HubBadge>}
                                                        </p>
                                                        <HandlingSummary value={item.handling} />
                                                    </div>
                                                    <HubBadge tone={item.received ? 'success' : 'neutral'}>{item.received ? 'Received' : item.removed ? 'Removed' : 'Awaiting receipt'}</HubBadge>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>

                                {bag.canPack && ['open', 'sealed'].includes(bag.status) && (
                                    <details className={card}>
                                        <summary className="cursor-pointer rounded-card px-5 py-4 text-sm font-medium text-ink-900 hover:bg-surface-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                                            Correct packing or loading
                                        </summary>
                                        <form onSubmit={event => submit(event, bag.status === 'open' ? 'remove' : bag.manifest_id ? 'unload' : 'reopen')} className="space-y-4 border-t border-line-200 p-5 sm:p-6">
                                            <p className="text-[13px] text-ink-600">
                                                {bag.status === 'open' ? 'Remove a scanned parcel before sealing. The reason is kept.' : bag.manifest_id ? 'Unload the whole sealed bag from an undeparted trip. Parcels and seal stay together.' : 'Reopen this bag to correct its contents. The old seal cannot be reused.'}
                                            </p>
                                            {(bag.status === 'open' || bag.manifest_id) && (
                                                <label className="block"><span className={fieldLabel}>{bag.status === 'open' ? 'Parcel waybill to remove' : 'Scanned master bag tag'}</span><input required className={`${hubInput} mt-1.5`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} /></label>
                                            )}
                                            {bag.manifest_id && (
                                                <label className="block"><span className={fieldLabel}>Numbered seal</span><input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} /></label>
                                            )}
                                            <label className="block"><span className={fieldLabel}>Reason</span><textarea required maxLength={1000} rows={2} className={`${hubInput} mt-1.5 h-auto`} value={action.data.notes} onChange={event => action.setData('notes', event.target.value)} /></label>
                                            <HubButton disabled={action.processing}>{bag.status === 'open' ? 'Remove parcel with reason' : bag.manifest_id ? 'Unload complete bag' : 'Reopen with reason'}</HubButton>
                                        </form>
                                    </details>
                                )}

                                <details className={card}>
                                    <summary className="cursor-pointer rounded-card px-5 py-4 text-sm font-medium text-ink-900 hover:bg-surface-50">Bag activity <span className="font-normal text-ink-500">· {bag.events.length}</span></summary>
                                    <ol className="divide-y divide-line-200 border-t border-line-200">
                                        {bag.events.map((event, index) => (
                                            <li key={index} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
                                                <div>
                                                    <p className="text-sm font-medium text-ink-900">{words(event.event_type)}</p>
                                                    <p className="text-xs text-ink-600">{event.actor}{event.barcode_scanned ? ` · ${event.barcode_scanned}` : ''}</p>
                                                </div>
                                                <time className="text-xs tabular-nums text-ink-500">{new Date(event.created_at).toLocaleString('en-PH')}</time>
                                            </li>
                                        ))}
                                    </ol>
                                </details>
                            </div>
                            );
                        })()}
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
