import React, { useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronRight,
    ExternalLink,
    MapPin,
    Package,
    ScanLine,
    Search,
    Store,
    Truck,
    Users,
    Warehouse,
    X,
} from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CompanyRiderIntake from '@/Components/CompanyRiderIntake';
import HubMap from '@/Components/Logistics/HubMap';
import { HubPageHeader, HubPanel, InfoHint } from '@/Components/Logistics/Workspace';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';
import type { CompanyRiderIntake as Intake } from '@/types/riderCompany';

interface NetworkScope {
    company_name: string;
    company_code: string | null;
    active_hub_id: number | null;
    can_switch_facility: boolean;
    can_manage_workers?: boolean;
    can_scan: boolean;
}

interface HandlerItem {
    id: number;
    user_id: number;
    name: string;
    email: string;
    hub_id: number;
    hub_name: string;
    hub_code: string;
    job_role: 'all' | 'intake' | 'pack_dispatch';
    role_title?: string | null;
}

interface DirectoryHubItem {
    id: number;
    name: string;
    code: string;
    tier: 'regional_mother_hub' | 'local_bayan_hub';
    company_name: string;
    company_code: string;
    province: string;
    city_municipality: string;
    barangay: string | null;
    address: string;
    latitude: number | null;
    longitude: number | null;
    parent_hub_id?: number | null;
    allows_self_pickup: boolean;
    is_active: boolean;
}

interface HubItem extends DirectoryHubItem {
    capacity: number;
    coverage_barangays: string[];
    handlers_count: number;
    fleet_count: number;
    parcel_count: number;
    ready_pickup_count: number;
    utilization: number;
}

interface Props {
    scope: NetworkScope;
    hubs: HubItem[];
    companyDirectoryHubs?: DirectoryHubItem[];
    handlers?: HandlerItem[];
    riderIntake?: Intake | null;
}

const JOB_ROLE_OPTIONS = [
    { value: 'all', label: 'Full Hub Operations (All)' },
    { value: 'intake', label: 'Entry / Intake only' },
    { value: 'pack_dispatch', label: 'Pack & Dispatch only' },
] as const;

const facilityType = (tier: HubItem['tier']) => (
    tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'
);

export default function HubNetwork({ scope, hubs, companyDirectoryHubs = [], handlers = [], riderIntake }: Props) {
    const placement = useForm({ kind: 'handler', email: '', hub_id: String(scope.active_hub_id ?? ''), job_role: 'all' });
    const [filterTier, setFilterTier] = useState<'all' | HubItem['tier']>('all');
    const [search, setSearch] = useState('');
    const [selectedHub, setSelectedHub] = useState<HubItem | null>(null);
    const [selectedPeerHub, setSelectedPeerHub] = useState<DirectoryHubItem | null>(null);
    const [coverageSearch, setCoverageSearch] = useState('');
    useBodyScrollLock(Boolean(selectedHub || selectedPeerHub));

    useEffect(() => {
        if (!selectedHub && !selectedPeerHub) return undefined;

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setSelectedHub(null);
                setSelectedPeerHub(null);
                setCoverageSearch('');
            }
        };

        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [selectedHub, selectedPeerHub]);

    const matchesFilter = (hub: DirectoryHubItem) => {
        const matchesTier = filterTier === 'all' || hub.tier === filterTier;
        const matchesSearch = !normalizedSearch || [
            hub.name,
            hub.code,
            hub.city_municipality,
            hub.province,
        ].some((value) => value.toLowerCase().includes(normalizedSearch));

        return matchesTier && matchesSearch;
    };

    const normalizedSearch = search.trim().toLowerCase();
    const accessibleHubIds = new Set(hubs.map((hub) => hub.id));
    const allCompanyHubs: DirectoryHubItem[] = [
        ...hubs,
        ...companyDirectoryHubs.filter((hub) => !accessibleHubIds.has(hub.id)),
    ];
    const filteredHubs = hubs.filter(matchesFilter);
    const filteredMapHubs = allCompanyHubs.filter(matchesFilter);
    const peerDirectoryHubs = allCompanyHubs.filter((hub) => !accessibleHubIds.has(hub.id) && matchesFilter(hub));

    const motherHubs = hubs.filter((hub) => hub.tier === 'regional_mother_hub');
    const bayanHubs = hubs.filter((hub) => hub.tier === 'local_bayan_hub');
    const networkMotherCount = allCompanyHubs.filter((hub) => hub.tier === 'regional_mother_hub').length;
    const networkBayanCount = allCompanyHubs.filter((hub) => hub.tier === 'local_bayan_hub').length;
    const parcelsInCustody = hubs.reduce((total, hub) => total + hub.parcel_count, 0);
    const activeHub = hubs.find((hub) => hub.id === scope.active_hub_id) ?? null;

    const familyRelationLabel = (hub: DirectoryHubItem) => {
        if (activeHub?.parent_hub_id && hub.id === activeHub.parent_hub_id) return 'Your Parent Mother Hub';
        if (hub.tier === 'regional_mother_hub') return 'Regional Mother Hub';
        if (activeHub?.parent_hub_id && hub.parent_hub_id === activeHub.parent_hub_id) return 'Sister Bayan Hub (Same Mother Hub)';
        return 'Company Bayan Hub';
    };

    const parentHubName = (parentHubId?: number | null) =>
        parentHubId ? allCompanyHubs.find((item) => item.id === parentHubId)?.name ?? null : null;

    const networkMetrics = [
        { label: 'Active facilities', value: allCompanyHubs.length, detail: hubs.length < allCompanyHubs.length ? `${hubs.length} assigned · ${allCompanyHubs.length} in company` : 'Owned by this company', icon: Building2 },
        { label: 'Mother Hubs', value: networkMotherCount, detail: 'Regional sortation', icon: Warehouse },
        { label: 'Bayan Hubs', value: networkBayanCount, detail: 'Local operations', icon: Store },
        { label: 'Parcels in custody', value: parcelsInCustody, detail: hubs.length < allCompanyHubs.length ? 'At your assigned hub' : 'Across active facilities', icon: Package },
    ];

    const closeDetails = () => {
        setSelectedHub(null);
        setSelectedPeerHub(null);
        setCoverageSearch('');
    };

    const handleSwitchHub = (hubId: number) => {
        router.post(
            route('hub.switchHub'),
            { hub_id: hubId },
            { preserveScroll: true },
        );
    };

    const filteredCoverage = selectedHub?.coverage_barangays.filter((barangay) => (
        barangay.toLowerCase().includes(coverageSearch.trim().toLowerCase())
    )) ?? [];

    return (
        <DashboardLayout
            title="Facility Network"
            subtitle={(
                <span className="flex items-center gap-1.5 text-xs text-zinc-600">
                    <span className="font-semibold text-ink-900">{scope.company_name}</span>
                    {scope.company_code && <span className="text-zinc-400">· {scope.company_code}</span>}
                </span>
            )}
            actions={scope.can_scan ? (
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-3.5 py-2 text-xs font-semibold text-white shadow-xs transition hover:bg-brand-600 active:scale-[0.99]"
                >
                    <ScanLine className="h-4 w-4" />
                    Scan parcel
                </Link>
            ) : undefined}
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-6 font-sans">
                <HubPageHeader
                    title="Hub network"
                    icon={Building2}
                    roleLabel={scope.company_name}
                />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                    <div className="overflow-hidden rounded-card border border-line-200 bg-white">
                        <HubMap
                            hubs={filteredMapHubs}
                            selectedId={selectedHub?.id ?? selectedPeerHub?.id ?? activeHub?.id}
                            onSelect={hub => {
                                const owned = hubs.find(item => item.id === hub.id);
                                if (owned) {
                                    setSelectedPeerHub(null);
                                    setSelectedHub(owned);
                                } else {
                                    const peer = allCompanyHubs.find(item => item.id === hub.id) ?? null;
                                    setSelectedHub(null);
                                    setSelectedPeerHub(peer);
                                }
                            }}
                            className="h-[360px]"
                        />
                    </div>
                    <HubPanel title="Network">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="rounded-control bg-surface-50 p-3">
                                <p className="flex items-center gap-1.5 text-[13px] text-ink-600"><span aria-hidden="true" className="h-2.5 w-2.5 rounded-sm bg-[#F97316]" />Mother hubs</p>
                                <p className="mt-1 text-[28px] font-bold leading-9 tabular-nums text-ink-900">{networkMotherCount}</p>
                            </div>
                            <div className="rounded-control bg-surface-50 p-3">
                                <p className="flex items-center gap-1.5 text-[13px] text-ink-600"><span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-brand-500" />Bayan hubs</p>
                                <p className="mt-1 text-[28px] font-bold leading-9 tabular-nums text-ink-900">{networkBayanCount}</p>
                            </div>
                        </div>
                        {activeHub && (
                            <dl className="mt-4 space-y-2 text-sm">
                                <div className="flex justify-between gap-3"><dt className="text-ink-600">You are at</dt><dd className="text-right font-medium text-ink-900">{activeHub.name}</dd></div>
                                {parentHubName(activeHub.parent_hub_id) && <div className="flex justify-between gap-3"><dt className="text-ink-600">Mother hub</dt><dd className="text-right font-medium text-ink-900">{parentHubName(activeHub.parent_hub_id)}</dd></div>}
                                <div className="flex justify-between gap-3"><dt className="text-ink-600">Parcels in custody</dt><dd className="font-medium tabular-nums text-ink-900">{parcelsInCustody}</dd></div>
                            </dl>
                        )}
                    </HubPanel>
                </div>

                {(scope.can_switch_facility || scope.can_manage_workers) && (
                    <details open={handlers.length > 0 && !scope.can_switch_facility} className="overflow-hidden rounded-card border border-line-200 bg-white">
                        <summary className="cursor-pointer px-5 py-4 text-base font-semibold text-ink-900 transition-colors hover:bg-surface-50">
                            People &amp; placements {handlers.length > 0 && <span className="ml-1 font-normal text-ink-500">· {handlers.length}</span>}
                        </summary>
                        <div className="space-y-4 border-t border-zinc-100 p-5 sm:p-6">
                            {handlers.length > 0 && (
                                <div className="rounded-xl border border-zinc-200/90 bg-zinc-50/30 p-5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <h2 className="text-sm font-semibold text-ink-900">Hub workers &amp; exclusive job locks</h2>

                                        </div>
                                        <span className="rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-semibold text-zinc-700 tabular-nums">
                                            {handlers.length} active assignments
                                        </span>
                                    </div>
                                    <div className="mt-4 grid gap-3 md:grid-cols-2">
                                        {handlers.map((worker) => (
                                            <div key={worker.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200/90 bg-white p-3.5 shadow-2xs">
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-xs font-semibold text-ink-900">{worker.name}</p>
                                                    <p className="truncate text-[11px] text-zinc-500">{worker.email}</p>
                                                    <p className="mt-1 truncate text-[11px] font-medium text-zinc-600">{worker.hub_name} ({worker.hub_code})</p>
                                                </div>
                                                <label className="w-full min-w-0 sm:w-auto sm:shrink-0 space-y-1 text-[11px] font-semibold text-zinc-600">
                                                    <span className="block">Locked role</span>
                                                    <select
                                                        aria-label={`Locked job role for ${worker.name} at ${worker.hub_name}`}
                                                        className="block w-full min-w-0 max-w-full rounded-lg border border-zinc-200/90 bg-white px-2.5 py-1.5 text-xs font-medium text-ink-900 shadow-2xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                                        value={worker.job_role}
                                                        onChange={(event) => {
                                                            router.patch(
                                                                route('hub.handlers.job-role', worker.id),
                                                                { job_role: event.target.value },
                                                                { preserveScroll: true },
                                                            );
                                                        }}
                                                    >
                                                        {JOB_ROLE_OPTIONS.map((option) => (
                                                            <option key={option.value} value={option.value}>{option.label}</option>
                                                        ))}
                                                    </select>
                                                </label>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                            {scope.can_switch_facility && <CompanyRiderIntake workspace={riderIntake} />}
                            {scope.can_switch_facility && hubs.length > 0 && (
                                <form
                                    className="rounded-xl border border-zinc-200/90 bg-zinc-50/30 p-5"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        placement.post(route('hub.placements'), { preserveScroll: true, onSuccess: () => placement.reset('email') });
                                    }}
                                >
                                    <h2 className="text-sm font-semibold text-ink-900">Assign a hub handler</h2>

                                    <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                        <label className="space-y-1.5 text-xs font-semibold text-ink-900">
                                            <span>Account email</span>
                                            <input
                                                type="email"
                                                required
                                                maxLength={254}
                                                className="block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-ink-900 shadow-2xs transition-colors focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15"
                                                value={placement.data.email}
                                                onChange={(event) => placement.setData('email', event.target.value)}
                                                placeholder="handler@company.com"
                                            />
                                        </label>
                                        <label className="space-y-1.5 text-xs font-semibold text-ink-900">
                                            <span>Destination facility</span>
                                            <select
                                                required
                                                className="block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-ink-900 shadow-2xs transition-colors focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15"
                                                value={placement.data.hub_id}
                                                onChange={(event) => {
                                                    placement.setData('hub_id', event.target.value);
                                                }}
                                            >
                                                <option value="">Choose a facility</option>
                                                {hubs.map((hub) => <option key={hub.id} value={hub.id}>{hub.name}</option>)}
                                            </select>
                                        </label>
                                        <label className="space-y-1.5 text-xs font-semibold text-ink-900">
                                            <span>Locked job role</span>
                                            <select
                                                className="block w-full rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-medium text-ink-900 shadow-2xs transition-colors focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15"
                                                value={placement.data.job_role}
                                                onChange={(event) => placement.setData('job_role', event.target.value)}
                                            >
                                                {JOB_ROLE_OPTIONS.map((option) => (
                                                    <option key={option.value} value={option.value}>{option.label}</option>
                                                ))}
                                            </select>
                                        </label>
                                    </div>
                                    {Object.values(placement.errors).map((error, index) => <p key={index} role="alert" className="mt-2 text-xs font-medium text-rose-700">{error}</p>)}
                                    <button type="submit" disabled={placement.processing} className="mt-4 rounded-lg bg-brand-500 px-4 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-600 active:scale-[0.99] disabled:opacity-50">
                                        {placement.processing ? 'Saving…' : 'Save placement'}
                                    </button>
                                </form>
                            )}
                        </div>
                    </details>
                )}

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                    <div className="flex flex-col gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-sm font-semibold text-ink-900">Company facilities</h2>
                        </div>
                        {activeHub && (
                            <div className="rounded-md border border-zinc-200/90 bg-white px-3 py-1 text-xs shadow-2xs">
                                <span className="text-zinc-500">Working facility: </span>
                                <span className="font-semibold text-ink-900">{activeHub.name}</span>
                                <span className="ml-1.5 tabular-nums text-xs font-semibold text-brand-500">{activeHub.code}</span>
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-2 divide-x divide-y divide-zinc-200/80 lg:grid-cols-4 lg:divide-y-0">
                        {networkMetrics.map((metric) => {
                            const Icon = metric.icon;
                            return (
                                <div key={metric.label} className="p-4 sm:p-5">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-xs font-semibold text-zinc-500">{metric.label}</p>
                                        <span className="flex h-7 w-7 items-center justify-center rounded-md border border-zinc-200/80 bg-zinc-50 text-zinc-600">
                                            <Icon className="h-3.5 w-3.5" />
                                        </span>
                                    </div>
                                    <p className="mt-2 tabular-nums text-2xl font-bold tabular-nums text-ink-900">{metric.value.toLocaleString()}</p>
                                    <p className="mt-1 text-xs text-zinc-500">{metric.detail}</p>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="relative w-full lg:max-w-md">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                            <input
                                type="search"
                                aria-label="Search facilities"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search facility, code, municipality, or province"
                                className="w-full rounded-lg border border-zinc-200/90 bg-white py-2 pl-9 pr-3.5 text-xs font-medium text-ink-900 placeholder:text-zinc-400 shadow-2xs transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                            />
                        </div>

                        <div className="grid grid-cols-3 gap-1 rounded-lg border border-zinc-200/90 bg-zinc-100/70 p-1 text-xs">
                            {[
                                { value: 'all' as const, label: `All (${allCompanyHubs.length})` },
                                { value: 'regional_mother_hub' as const, label: `Mother (${networkMotherCount})` },
                                { value: 'local_bayan_hub' as const, label: `Bayan (${networkBayanCount})` },
                            ].map((filter) => (
                                <button
                                    key={filter.value}
                                    type="button"
                                    onClick={() => setFilterTier(filter.value)}
                                    className={`rounded-md px-3 py-1.5 font-semibold transition-all duration-150 ${
 filterTier === filter.value
 ? 'bg-white text-ink-900 shadow-2xs'
 : 'text-zinc-500 hover:text-ink-900'
 }`}
                                >
                                    {filter.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                {filteredHubs.length > 0 && (
                    <section className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        {filteredHubs.map((hub) => {
                            const isWorkingFacility = scope.active_hub_id === hub.id;
                            const capacityTone = hub.utilization >= 80 ? 'bg-amber-500' : 'bg-brand-500';

                            return (
                                <button
                                    key={hub.id}
                                    type="button"
                                    onClick={() => { setSelectedPeerHub(null); setSelectedHub(hub); }}
                                    className={`group flex min-h-60 flex-col rounded-xl border bg-white p-5 text-left shadow-xs transition-all duration-150 hover:border-zinc-300 ${
 isWorkingFacility ? 'border-brand-500 ring-1 ring-brand-500/15' : 'border-zinc-200/90'
 }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border ${
 isWorkingFacility
 ? 'border-rose-200 bg-rose-50 text-brand-500'
 : hub.tier === 'regional_mother_hub'
 ? 'border-orange-200 bg-orange-50 text-orange-600'
 : 'border-zinc-200/80 bg-zinc-50 text-zinc-600'
 }`}>
                                            {hub.tier === 'regional_mother_hub'
                                                ? <Warehouse className="h-4 w-4" />
                                                : <Store className="h-4 w-4" />}
                                        </span>
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2 py-0.5 text-[11px] font-semibold text-zinc-700">
                                                {facilityType(hub.tier)}
                                            </span>
                                            {isWorkingFacility && (
                                                <span className="inline-flex items-center gap-1 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                                                    <Check className="h-3 w-3" /> Working
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-3.5">
                                        <div className="flex items-center gap-2">
                                            <h3 className="line-clamp-1 text-sm font-bold text-ink-900">{hub.name}</h3>
                                            <span className="shrink-0 tabular-nums text-xs font-semibold text-zinc-400">{hub.code}</span>
                                        </div>
                                        <p className="mt-1 flex items-center gap-1.5 text-xs text-zinc-500">
                                            <MapPin className="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                            <span className="truncate">{hub.city_municipality}, {hub.province}</span>
                                        </p>
                                    </div>

                                    <div className="mt-4 rounded-lg border border-zinc-200/80 bg-zinc-50/50 p-3">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="font-medium text-zinc-500">Capacity used</span>
                                            <span className="tabular-nums font-semibold text-ink-900">
                                                {hub.parcel_count.toLocaleString()} / {hub.capacity.toLocaleString()}
                                            </span>
                                        </div>
                                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100">
                                            <div
                                                className={`h-full rounded-full ${capacityTone}`}
                                                style={{ width: `${Math.min(100, hub.utilization)}%` }}
                                            />
                                        </div>
                                        <div className="mt-3 grid grid-cols-3 divide-x divide-zinc-200/80 text-center">
                                            <div>
                                                <span className="block text-[11px] text-zinc-500">Vehicles</span>
                                                <span className="mt-0.5 block tabular-nums text-xs font-bold text-ink-900">{hub.fleet_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-[11px] text-zinc-500">Handlers</span>
                                                <span className="mt-0.5 block tabular-nums text-xs font-bold text-ink-900">{hub.handlers_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-[11px] text-zinc-500">Barangays</span>
                                                <span className="mt-0.5 block tabular-nums text-xs font-bold text-ink-900">{hub.coverage_barangays.length}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-auto flex items-center justify-between border-t border-zinc-100 pt-3 text-xs">
                                        <span className="text-zinc-500">
                                            {hub.allows_self_pickup ? `${hub.ready_pickup_count} awaiting counter pickup` : 'Transfer facility'}
                                        </span>
                                        <span className="inline-flex items-center gap-1 font-semibold text-brand-500 group-hover:text-brand-600">
                                            View facility
                                            <ChevronRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                                        </span>
                                    </div>
                                </button>
                            );
                        })}
                    </section>
                )}

                {peerDirectoryHubs.length > 0 && (() => {
                    const mothers = allCompanyHubs.filter(hub => hub.tier === 'regional_mother_hub' && (peerDirectoryHubs.some(peer => peer.id === hub.id) || peerDirectoryHubs.some(peer => peer.parent_hub_id === hub.id)));
                    const motherIds = new Set(mothers.map(hub => hub.id));
                    const groups = [
                        ...mothers.map(mother => ({ mother, children: peerDirectoryHubs.filter(hub => hub.parent_hub_id === mother.id && hub.tier !== 'regional_mother_hub') })),
                        { mother: null as DirectoryHubItem | null, children: peerDirectoryHubs.filter(hub => hub.tier !== 'regional_mother_hub' && !(hub.parent_hub_id && motherIds.has(hub.parent_hub_id))) },
                    ].filter(group => group.mother || group.children.length);
                    const directions = (hub: DirectoryHubItem) => hub.latitude !== null && hub.longitude !== null && (
                        <a href={`https://www.google.com/maps/dir/?api=1&destination=${hub.latitude},${hub.longitude}&travelmode=driving`} target="_blank" rel="noopener noreferrer"
                            aria-label={`Directions to ${hub.name}`} className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-line-300 bg-white text-ink-700 hover:border-ink-400 hover:bg-surface-100 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                            <ExternalLink className="h-4 w-4" aria-hidden="true" />
                        </a>
                    );
                    const viewButton = (hub: DirectoryHubItem) => (
                        <button type="button" onClick={() => { setSelectedHub(null); setSelectedPeerHub(hub); }}
                            className="shrink-0 rounded-control px-2.5 py-1 text-[13px] font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500">
                            View
                        </button>
                    );
                    return (
                        <section className="rounded-card border border-line-200 bg-white" aria-label="Connected company family facilities">
                            <div className="flex flex-wrap items-baseline justify-between gap-2 px-5 pt-5">
                                <h2 className="flex items-center gap-1 text-base font-semibold text-ink-900">
                                    Company network <span className="font-normal text-ink-500">· {peerDirectoryHubs.length}</span>
                                    <InfoHint>View-only directory of mother hubs and sister branches in {scope.company_name}, for transit planning.</InfoHint>
                                </h2>
                            </div>
                            <div className="mt-3 divide-y divide-line-200">
                                {groups.map(group => {
                                    const mother = group.mother;
                                    const isParent = Boolean(mother && activeHub?.parent_hub_id === mother.id);
                                    return (
                                        <div key={mother?.id ?? 'other'} className="px-5 py-4">
                                            {mother ? (
                                                <div className={`flex items-center gap-3 rounded-control ${selectedPeerHub?.id === mother.id ? 'bg-brand-50' : ''}`}>
                                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-warning-soft text-warning">
                                                        <Warehouse className="h-4 w-4" aria-hidden="true" />
                                                    </span>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink-900">
                                                            <span className="truncate">{mother.name}</span>
                                                            {isParent && <span className="rounded-chip bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-600">Your mother hub</span>}
                                                        </p>
                                                        <p className="truncate text-xs text-ink-600">{mother.code} · {mother.city_municipality}, {mother.province} · {group.children.length} {group.children.length === 1 ? 'child hub' : 'child hubs'}</p>
                                                    </div>
                                                    {peerDirectoryHubs.some(peer => peer.id === mother.id) && viewButton(mother)}
                                                    {directions(mother)}
                                                </div>
                                            ) : (
                                                <p className="text-[13px] font-medium text-ink-600">Other hubs</p>
                                            )}
                                            {group.children.length > 0 && (
                                                <ul className={`mt-2 space-y-0.5 ${mother ? 'ml-[18px] border-l border-line-200 pl-6' : ''}`}>
                                                    {group.children.map(hub => (
                                                        <li key={hub.id} className={`flex items-center gap-3 rounded-control px-2 py-1.5 ${selectedPeerHub?.id === hub.id ? 'bg-brand-50' : 'hover:bg-surface-50'}`}>
                                                            <Store className="h-4 w-4 shrink-0 text-ink-400" aria-hidden="true" />
                                                            <div className="min-w-0 flex-1">
                                                                <p className="truncate text-sm text-ink-900">
                                                                    {hub.name} <span className="text-xs text-ink-500">{hub.code}</span>
                                                                    {activeHub?.parent_hub_id && hub.parent_hub_id === activeHub.parent_hub_id && <span className="ml-2 rounded-chip bg-surface-100 px-1.5 py-0.5 text-xs text-ink-600">Sister hub</span>}
                                                                </p>
                                                                <p className="truncate text-xs text-ink-500">{hub.city_municipality}, {hub.province}</p>
                                                            </div>
                                                            {viewButton(hub)}
                                                            {directions(hub)}
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    );
                })()}

                {filteredMapHubs.length === 0 && (
                    <section className="rounded-xl border border-dashed border-zinc-200 bg-white p-12 text-center">
                        <span className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-lg border border-zinc-200/90 bg-zinc-50 text-zinc-500">
                            <Building2 className="h-5 w-5" />
                        </span>
                        <h2 className="text-sm font-semibold text-ink-900">No facilities found</h2>
                        <p className="mt-1 text-xs text-zinc-500">Try another search or facility type.</p>
                    </section>
                )}
            </div>

            {selectedHub && (
                <div className="fixed inset-0 z-50 !mt-0 overscroll-contain font-sans">
                    <button
                        type="button"
                        aria-label="Close facility details"
                        onClick={closeDetails}
                        className="absolute inset-0 h-full w-full bg-brand-900/45 backdrop-blur-xs transition-opacity"
                    />

                    <aside
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="facility-details-title"
                        className="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col overflow-y-auto overscroll-contain border-l border-zinc-200/90 bg-white shadow-2xl"
                    >
                        <header className="border-b border-zinc-100 border-t-4 border-t-[#E00D42] bg-white px-6 py-5">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">
                                            {facilityType(selectedHub.tier)}
                                        </span>
                                        <span className="tabular-nums text-xs font-semibold text-brand-500">{selectedHub.code}</span>
                                    </div>
                                    <h2 id="facility-details-title" className="mt-2.5 text-lg font-bold text-ink-900">
                                        {selectedHub.name}
                                    </h2>
                                    <p className="mt-0.5 text-xs text-zinc-500">{selectedHub.company_name}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeDetails}
                                    className="rounded-lg border border-zinc-200/90 p-2 text-zinc-400 transition-colors hover:bg-zinc-50 hover:text-ink-900"
                                    aria-label="Close facility details"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>

                            <div className="mt-4">
                                {scope.active_hub_id === selectedHub.id ? (
                                    <div className="inline-flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700">
                                        <Check className="h-3.5 w-3.5" />
                                        Current working facility
                                    </div>
                                ) : scope.can_switch_facility ? (
                                    <button
                                        type="button"
                                        onClick={() => handleSwitchHub(selectedHub.id)}
                                        className="inline-flex items-center gap-2 rounded-lg bg-brand-500 px-3.5 py-2 text-xs font-semibold text-white shadow-2xs transition hover:bg-brand-600 active:scale-[0.99]"
                                    >
                                        <Building2 className="h-4 w-4" />
                                        Use as working facility
                                    </button>
                                ) : (
                                    <p className="text-xs text-zinc-500">Your account is restricted to its assigned facility.</p>
                                )}
                            </div>
                        </header>

                        <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                            <section>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">Facility snapshot</h3>
                                <div className="mt-3 grid grid-cols-2 gap-3">
                                    {[
                                        { label: 'Parcels in custody', value: selectedHub.parcel_count.toLocaleString(), icon: Package },
                                        { label: 'Staging capacity', value: selectedHub.capacity.toLocaleString(), icon: Warehouse },
                                        { label: 'Active vehicles', value: selectedHub.fleet_count.toLocaleString(), icon: Truck },
                                        { label: 'Active handlers', value: selectedHub.handlers_count.toLocaleString(), icon: Users },
                                    ].map((item) => {
                                        const Icon = item.icon;
                                        return (
                                            <div key={item.label} className="rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-3.5">
                                                <div className="flex items-center gap-2 text-zinc-500">
                                                    <Icon className="h-3.5 w-3.5" />
                                                    <span className="text-xs font-medium">{item.label}</span>
                                                </div>
                                                <p className="mt-2 tabular-nums text-lg font-bold tabular-nums text-ink-900">{item.value}</p>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-3 rounded-xl border border-zinc-200/90 bg-white p-3.5">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="text-zinc-500">Capacity used</span>
                                        <span className="tabular-nums font-semibold text-ink-900">{selectedHub.utilization}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100">
                                        <div
                                            className={`h-full rounded-full ${selectedHub.utilization >= 80 ? 'bg-amber-500' : 'bg-brand-500'}`}
                                            style={{ width: `${Math.min(100, selectedHub.utilization)}%` }}
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">Location</h3>
                                <div className="mt-3 rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-4">
                                    <div className="flex items-start gap-2.5 text-xs leading-5 text-zinc-700">
                                        <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                                        <span>
                                            {selectedHub.address}, {selectedHub.barangay}, {selectedHub.city_municipality}, {selectedHub.province}
                                        </span>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/80 pt-3 text-xs text-zinc-500">
                                        <span>
                                            {selectedHub.latitude !== null && selectedHub.latitude !== undefined && selectedHub.longitude !== null && selectedHub.longitude !== undefined ? (
                                                <>
                                                    GPS pin:{' '}
                                                    <span className="tabular-nums font-semibold text-ink-900">
                                                        {Number(selectedHub.latitude).toFixed(4)}° N, {Number(selectedHub.longitude).toFixed(4)}° E
                                                    </span>
                                                </>
                                            ) : (
                                                <span>GPS pin not saved yet</span>
                                            )}
                                        </span>
                                        {selectedHub.latitude !== null && selectedHub.latitude !== undefined && selectedHub.longitude !== null && selectedHub.longitude !== undefined && (
                                            <a
                                                href={`https://www.google.com/maps/dir/?api=1&destination=${selectedHub.latitude},${selectedHub.longitude}&travelmode=driving`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 rounded border border-zinc-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-ink-900 shadow-2xs transition hover:border-brand-500 hover:text-brand-500"
                                            >
                                                <ExternalLink className="h-3 w-3" />
                                                Directions
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">Service coverage</h3>
                                        <p className="mt-0.5 text-xs text-zinc-500">
                                            {selectedHub.coverage_barangays.length} assigned barangays
                                        </p>
                                    </div>
                                    <span className="rounded-md border border-zinc-200/90 bg-zinc-50 px-2.5 py-0.5 text-xs font-semibold text-zinc-700">
                                        {selectedHub.allows_self_pickup ? 'Counter pickup available' : 'Transfer only'}
                                    </span>
                                </div>

                                {selectedHub.coverage_barangays.length > 8 && (
                                    <div className="relative mt-3">
                                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                                        <input
                                            type="search"
                                            aria-label="Search assigned barangays"
                                            value={coverageSearch}
                                            onChange={(event) => setCoverageSearch(event.target.value)}
                                            placeholder="Search assigned barangay"
                                            className="w-full rounded-lg border border-zinc-200/90 bg-white py-2 pl-9 pr-3 text-xs font-medium text-ink-900 placeholder:text-zinc-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15"
                                        />
                                    </div>
                                )}

                                <div className="mt-3 grid max-h-48 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                    {filteredCoverage.length > 0 ? filteredCoverage.map((barangay) => (
                                        <div key={barangay} className="rounded-lg border border-zinc-200/90 bg-white px-3 py-2 text-xs font-medium text-ink-900">
                                            {barangay}
                                        </div>
                                    )) : (
                                        <p className="col-span-full rounded-lg border border-dashed border-zinc-200 p-4 text-center text-xs text-zinc-400">
                                            No matching barangay found.
                                        </p>
                                    )}
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">Facility tools</h3>
                                <div className="mt-3 space-y-2">
                                    <Link
                                        href={route('hub.scan.station')}
                                        className="flex items-center justify-between rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2.5 text-xs font-semibold text-ink-900 transition hover:border-zinc-300 hover:bg-zinc-50"
                                    >
                                        <span className="flex items-center gap-2"><ScanLine className="h-4 w-4 text-brand-500" /> Scan parcel</span>
                                        <ChevronRight className="h-4 w-4 text-zinc-400" />
                                    </Link>
                                    <Link
                                        href={route('hub.deliveries')}
                                        className="flex items-center justify-between rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2.5 text-xs font-semibold text-ink-900 transition hover:border-zinc-300 hover:bg-zinc-50"
                                    >
                                        <span className="flex items-center gap-2"><Package className="h-4 w-4 text-zinc-500" /> Parcels and waybills</span>
                                        <ChevronRight className="h-4 w-4 text-zinc-400" />
                                    </Link>
                                    {selectedHub.allows_self_pickup && (
                                        <Link
                                            href={route('hub.counter')}
                                            className="flex items-center justify-between rounded-lg border border-zinc-200/90 bg-white px-3.5 py-2.5 text-xs font-semibold text-ink-900 transition hover:border-zinc-300 hover:bg-zinc-50"
                                        >
                                            <span className="flex items-center gap-2"><Store className="h-4 w-4 text-zinc-500" /> Counter pickup</span>
                                            <ChevronRight className="h-4 w-4 text-zinc-400" />
                                        </Link>
                                    )}
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>
            )}
            {selectedPeerHub && !selectedHub && (
                <div className="fixed inset-0 z-50 !mt-0 overscroll-contain font-sans">
                    <button
                        type="button"
                        aria-label="Close connected facility details"
                        onClick={closeDetails}
                        className="absolute inset-0 h-full w-full bg-brand-900/45 backdrop-blur-xs transition-opacity"
                    />

                    <aside
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="peer-facility-details-title"
                        className="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col overflow-y-auto overscroll-contain border-l border-zinc-200/90 bg-white shadow-2xl"
                    >
                        <header className="border-b border-zinc-100 border-t-4 border-t-[#F97316] bg-white px-6 py-5">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`rounded-md border px-2.5 py-0.5 text-xs font-semibold ${
 selectedPeerHub.tier === 'regional_mother_hub'
 ? 'border-orange-200 bg-orange-50 text-orange-800'
 : 'border-zinc-200/90 bg-zinc-50 text-zinc-700'
 }`}>
                                            {facilityType(selectedPeerHub.tier)}
                                        </span>
                                        <span className="tabular-nums text-xs font-semibold text-brand-500">{selectedPeerHub.code}</span>
                                    </div>
                                    <h2 id="peer-facility-details-title" className="mt-2.5 text-lg font-bold text-ink-900">
                                        {selectedPeerHub.name}
                                    </h2>
                                    <p className="mt-0.5 text-xs font-medium text-zinc-600">{familyRelationLabel(selectedPeerHub)}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeDetails}
                                    className="rounded-lg border border-zinc-200/90 p-2 text-zinc-400 transition-colors hover:bg-zinc-50 hover:text-ink-900"
                                    aria-label="Close connected facility details"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                        </header>

                        <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                            <section>
                                <h3 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">Location &amp; routing details</h3>
                                <div className="mt-3 rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-4 space-y-3">
                                    <div className="flex items-start gap-2.5 text-xs leading-5 text-zinc-700">
                                        <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-brand-500" />
                                        <span>
                                            {[selectedPeerHub.address, selectedPeerHub.barangay, selectedPeerHub.city_municipality, selectedPeerHub.province].filter(Boolean).join(', ')}
                                        </span>
                                    </div>
                                    {parentHubName(selectedPeerHub.parent_hub_id) && (
                                        <p className="text-xs text-zinc-600">
                                            Parent hub: <span className="font-semibold text-ink-900">{parentHubName(selectedPeerHub.parent_hub_id)}</span>
                                        </p>
                                    )}
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/80 pt-3 text-xs text-zinc-500">
                                        <span>
                                            {selectedPeerHub.latitude !== null && selectedPeerHub.longitude !== null ? (
                                                <>
                                                    GPS pin:{' '}
                                                    <span className="tabular-nums font-semibold text-ink-900">
                                                        {Number(selectedPeerHub.latitude).toFixed(4)}° N, {Number(selectedPeerHub.longitude).toFixed(4)}° E
                                                    </span>
                                                </>
                                            ) : (
                                                <span>GPS pin not saved yet</span>
                                            )}
                                        </span>
                                        {selectedPeerHub.latitude !== null && selectedPeerHub.longitude !== null && (
                                            <a
                                                href={`https://www.google.com/maps/dir/?api=1&destination=${selectedPeerHub.latitude},${selectedPeerHub.longitude}&travelmode=driving`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 rounded border border-zinc-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-ink-900 shadow-2xs transition hover:border-brand-500 hover:text-brand-500"
                                            >
                                                <ExternalLink className="h-3 w-3" />
                                                Directions
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </section>

                            <p className="rounded-lg border border-zinc-200/80 bg-zinc-50 p-3.5 text-xs leading-5 text-zinc-600">
                                This facility belongs to your company directory so hub teams can locate Mother Hubs and sister branches along transit corridors. Operational controls stay scoped to your assigned station.
                            </p>
                        </div>
                    </aside>
                </div>
            )}
        </DashboardLayout>
    );
}
