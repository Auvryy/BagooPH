import { FormEvent, useEffect, useRef, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Box, CheckCheck, Container, LockKeyhole, Package, Printer, RotateCcw, ScanLine, Truck } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubBadge, HubButton, HubCategoryTabs, InfoHint, HubRoute, HubSearch, hubButton, hubInput, hubPrimary, type HubPlace } from '@/Components/Logistics/Workspace';
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

                <div className="grid items-start gap-6 lg:grid-cols-[380px_1fr]">
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
                                    <ul className="space-y-1">
                                        {visibleBags.map(item => {
                                            const fill = item.capacity && item.capacity.max_count > 0 ? Math.min(100, Math.round((item.capacity.count / item.capacity.max_count) * 100)) : null;
                                            const selected = bag?.id === item.id;
                                            return (
                                                <li key={item.id}>
                                                    <Link
                                                        href={`${basePath}?bag=${item.id}`}
                                                        preserveScroll
                                                        aria-current={selected ? 'true' : undefined}
                                                        className={`block rounded-control px-3 py-3 transition-colors ${selected ? 'bg-brand-50 ring-1 ring-brand-200' : 'hover:bg-surface-50'}`}
                                                    >
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
                                <span className="text-sm text-ink-600 group-open:hidden">Create</span>
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

                    {/* Right Column: Auto-pack (default) OR Selected Bag Workspace */}
                    <div className="min-w-0 space-y-5">
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

                        {bag && (
                            <div className="space-y-5">
                                {/* Compact Selected Bag Summary */}
                                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white px-5 py-3.5 shadow-xs">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-bold text-ink-900 tabular-nums">{bag.reference}</span>
                                            <HubBadge tone={bag.status === 'received' ? 'brand' : 'neutral'}>{words(bag.status)}</HubBadge>
                                            {bag.seal_number && (
                                                <span className="text-xs font-medium text-zinc-500 tabular-nums">Seal {bag.seal_number}</span>
                                            )}
                                        </div>
                                        <p className="mt-0.5 break-words text-xs text-zinc-600">
                                            {bag.source.name} → {bag.destination.name} · {bag.parcels_count} recorded parcels
                                        </p>
                                    </div>
                                    {bag.manifest_id && (
                                        <Link
                                            className="text-xs font-bold text-ink-900 underline underline-offset-4 hover:text-zinc-700"
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
                                                    <h2 className="text-base font-semibold text-ink-900">Pack and seal</h2>
<InfoHint>Keep this bag open, scan incoming parcels continuously, revert any mis-scan, then scan or type the seal.</InfoHint>
                                                </div>
                                            </div>
                                            <label className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-ink-900 cursor-pointer">
                                                <input
                                                    type="checkbox"
                                                    checked={continuousPack}
                                                    onChange={event => setContinuousPack(event.target.checked)}
                                                    className="rounded border-zinc-300 text-brand-500 focus:ring-brand-500"
                                                />
                                                Hands-free continuous auto-add
                                            </label>
                                        </header>
                                        <div className="p-5 sm:p-6 space-y-5">
                                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4">
                                                <p className="max-w-md text-xs leading-5 text-zinc-600">
                                                    Arriving Mother Hub parcels need their sorting scan before the next bag. Check the parcel’s current stage if packing is blocked.
                                                </p>
                                                <Link href={route('hub.scan.station')} className="inline-flex items-center gap-1.5 text-xs font-bold text-ink-900 underline underline-offset-4 hover:text-zinc-700">
                                                    <ScanLine className="h-4 w-4" />
                                                    Check in Scan Station
                                                </Link>
                                            </div>
                                            <form onSubmit={event => submit(event, 'add')} className="flex flex-wrap items-end gap-3">
                                                <label className="min-w-0 flex-1 text-xs font-bold text-ink-900">
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
                                                        <p className="text-xs font-bold text-ink-900">
                                                            Recently scanned in this bag ({activeParcels.length})
                                                        </p>
                                                        <span className="text-[11px] text-zinc-500">Revert a mis-scan before sealing</span>
                                                    </div>
                                                    <div className="max-h-48 space-y-2 overflow-y-auto pr-1">
                                                        {activeParcels.slice(0, 8).map(item => (
                                                            <div key={item.id} className="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs">
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <div className="flex items-center gap-2">
                                                                        <span className="rounded border border-zinc-200 bg-zinc-100 px-1.5 py-0.5 font-bold text-ink-900 tabular-nums">
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
                                                    <label className="min-w-0 flex-1 text-xs font-bold text-ink-900">
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
                                                <h2 className="text-base font-semibold text-ink-900">Load by master tag</h2>
                                            </div>
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
                                                        className="text-xs font-bold text-ink-900 underline underline-offset-4 hover:text-zinc-700 cursor-pointer"
                                                    >
                                                        Auto-fill this bag&apos;s tag ({bag.reference}) &amp; seal ({bag.seal_number})
                                                    </button>
                                                </div>
                                                <label className="text-xs font-bold text-ink-900">
                                                    Master bag barcode
                                                    <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                                </label>
                                                <label className="text-xs font-bold text-ink-900">
                                                    Numbered seal
                                                    <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                                </label>
                                                <fieldset className="space-y-3 sm:col-span-2">
                                                    <legend className="mb-2 text-xs font-bold text-ink-900">Draft transport manifest</legend>
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
 action.data.manifest_id === String(item.id) ? 'border-brand-500 bg-rose-50/40 ring-1 ring-brand-500/20' : 'border-zinc-200 bg-white hover:bg-zinc-50'
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
                                                                    className="mt-1 border-zinc-300 text-brand-500 focus:ring-brand-500"
                                                                />
                                                                <span className="min-w-0">
                                                                    <span className="flex items-center gap-2 text-xs sm:text-sm font-bold text-ink-900">
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
                                                <h2 className="text-base font-semibold text-ink-900">Verify arriving seal</h2>
<InfoHint>Check the master tag and physical seal before individual receiving.</InfoHint>
                                            </div>
                                        </header>
                                        <div className="p-5 sm:p-6">
                                            <form onSubmit={event => submit(event, 'open')} className="space-y-4">
                                                <label className="block text-xs font-bold text-ink-900">
                                                    Scanned master bag tag
                                                    <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                                </label>
                                                <label className="block text-xs font-bold text-ink-900">
                                                    Seal number observed
                                                    <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                                </label>
                                                <label className="flex items-center gap-2.5 text-xs text-zinc-700 font-medium">
                                                    <input
                                                        required
                                                        type="checkbox"
                                                        checked={action.data.seal_intact}
                                                        onChange={event => action.setData('seal_intact', event.target.checked)}
                                                        className="rounded border-zinc-300 text-brand-500 focus:ring-brand-500"
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
                                        <p className="text-xs sm:text-sm font-semibold text-ink-900">
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
                                            <h2 className="font-bold text-ink-900">Packing capacity</h2>
                                            <span className="break-all text-xs text-zinc-600">Permanent sack: <strong>{bag.physical_tag ?? 'No reusable tag recorded'}</strong></span>
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <CapacityMeter label="Parcels" value={bag.capacity.count} max={bag.capacity.max_count} unit="parcels" />
                                            <CapacityMeter label="Weight" value={bag.capacity.weight_kg} max={bag.capacity.max_weight_kg} unit="kg" />
                                            <CapacityMeter label="Volume" value={bag.capacity.volume_cm3 === null ? null : Number((bag.capacity.volume_cm3 / 1000000).toFixed(4))} max={Number(((bag.capacity.max_volume_cm3 || 300000) / 1000000).toFixed(2))} unit="m³" />
                                        </div>
                                    </section>
                                )}

                                {/* Parcel Contents Table */}
                                <section className="rounded-xl border border-zinc-200 bg-white shadow-xs overflow-hidden">
                                    <header className="border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                        <div className="flex items-center gap-2">
                                            <Package className="h-4 w-4 text-zinc-600" />
                                            <h2 className="text-base font-semibold text-ink-900">Parcel contents</h2>
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
                                                            <span className="rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 tabular-nums text-xs font-bold text-ink-900">
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
                                                <h2 className="text-sm font-bold text-ink-900 tabular-nums">{bag.reference}</h2>
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
                                                    <span className="rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 tabular-nums text-xs font-bold text-ink-900">
                                                        {bag.reference}
                                                    </span>
                                                    <HubBadge tone={bag.status === 'received' ? 'brand' : 'neutral'}>{words(bag.status)}</HubBadge>
                                                </div>
                                                <p className="text-xs sm:text-sm text-zinc-600 font-medium">
                                                    {bag.parcels_count} parcels · {bag.seal_number ? `Seal ${bag.seal_number}` : 'Seal not attached'}
                                                </p>
                                                {bag.manifest_id && (
                                                    <Link
                                                        className="inline-block text-xs sm:text-sm font-bold text-ink-900 hover:text-zinc-700 underline underline-offset-4"
                                                        href={`${basePath.startsWith('/hub') ? '/hub' : ''}/manifests/${bag.manifest_id}`}
                                                    >
                                                        Open transport manifest
                                                    </Link>
                                                )}
                                            </div>
                                            <div data-bag-label className="rounded-xl border border-zinc-200 bg-white p-4 text-center shadow-xs">
                                                <QRCodeSVG value={bag.reference} size={96} />
                                                <p className="mt-2.5 text-xs tabular-nums font-bold text-ink-900">{bag.reference}</p>
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
                                        <summary className="cursor-pointer bg-zinc-50/40 px-5 py-3.5 text-xs sm:text-sm font-bold text-ink-900 hover:bg-zinc-50 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
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
                                                <label className="block text-xs font-bold text-ink-900">
                                                    {bag.status === 'open' ? 'Parcel waybill to remove' : 'Scanned master bag tag'}
                                                    <input required className={`${hubInput} mt-1.5`} value={action.data.barcode} onChange={event => action.setData('barcode', event.target.value)} />
                                                </label>
                                            )}
                                            {bag.manifest_id && (
                                                <label className="block text-xs font-bold text-ink-900">
                                                    Numbered seal
                                                    <input required className={`${hubInput} mt-1.5 tabular-nums`} value={action.data.seal_number} onChange={event => action.setData('seal_number', event.target.value)} />
                                                </label>
                                            )}
                                            <label className="block text-xs font-bold text-ink-900">
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
                                    <summary className="cursor-pointer bg-zinc-50/40 px-5 py-3.5 text-xs sm:text-sm font-bold text-ink-900 hover:bg-zinc-50 transition-colors">
                                        Bag activity ({bag.events.length})
                                    </summary>
                                    <div className="border-t border-zinc-100 p-5 sm:p-6">
                                        <ol className="divide-y divide-zinc-100">
                                            {bag.events.map((event, index) => (
                                                <li key={index} className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                                                    <div>
                                                        <p className="text-xs sm:text-sm font-semibold text-ink-900">{words(event.event_type)}</p>
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
