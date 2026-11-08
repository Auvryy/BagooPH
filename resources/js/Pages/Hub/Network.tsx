import React, { useEffect, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronRight,
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
import { HubPageHeader, HubPanel } from '@/Components/Logistics/Workspace';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';
import type { CompanyRiderIntake as Intake } from '@/types/riderCompany';

interface NetworkScope {
    company_name: string;
    company_code: string | null;
    active_hub_id: number | null;
    can_switch_facility: boolean;
    can_scan: boolean;
}

interface HubItem {
    id: number;
    name: string;
    code: string;
    tier: 'regional_mother_hub' | 'local_bayan_hub';
    company_name: string;
    company_code: string;
    province: string;
    city_municipality: string;
    barangay: string;
    address: string;
    latitude: number | null;
    longitude: number | null;
    parent_hub_id?: number | null;
    capacity: number;
    coverage_barangays: string[];
    allows_self_pickup: boolean;
    is_active: boolean;
    handlers_count: number;
    fleet_count: number;
    parcel_count: number;
    ready_pickup_count: number;
    utilization: number;
}

interface Props {
    scope: NetworkScope;
    hubs: HubItem[];
    riderIntake?: Intake | null;
}

const facilityType = (tier: HubItem['tier']) => (
    tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'
);

export default function HubNetwork({ scope, hubs, riderIntake }: Props) {
    const placement = useForm({ kind: 'handler', email: '', hub_id: String(scope.active_hub_id ?? '') });
    const [filterTier, setFilterTier] = useState<'all' | HubItem['tier']>('all');
    const [search, setSearch] = useState('');
    const [selectedHub, setSelectedHub] = useState<HubItem | null>(null);
    const [coverageSearch, setCoverageSearch] = useState('');
    useBodyScrollLock(Boolean(selectedHub));

    useEffect(() => {
        if (!selectedHub) return undefined;

        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setSelectedHub(null);
                setCoverageSearch('');
            }
        };

        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [selectedHub]);

    const normalizedSearch = search.trim().toLowerCase();
    const filteredHubs = hubs.filter((hub) => {
        const matchesTier = filterTier === 'all' || hub.tier === filterTier;
        const matchesSearch = !normalizedSearch || [
            hub.name,
            hub.code,
            hub.city_municipality,
            hub.province,
        ].some((value) => value.toLowerCase().includes(normalizedSearch));

        return matchesTier && matchesSearch;
    });

    const motherHubs = hubs.filter((hub) => hub.tier === 'regional_mother_hub');
    const bayanHubs = hubs.filter((hub) => hub.tier === 'local_bayan_hub');
    const parcelsInCustody = hubs.reduce((total, hub) => total + hub.parcel_count, 0);
    const activeHub = hubs.find((hub) => hub.id === scope.active_hub_id) ?? null;
    const networkMetrics = [
        { label: 'Active facilities', value: hubs.length, detail: 'Owned by this company', icon: Building2 },
        { label: 'Mother Hubs', value: motherHubs.length, detail: 'Regional sortation', icon: Warehouse },
        { label: 'Bayan Hubs', value: bayanHubs.length, detail: 'Local operations', icon: Store },
        { label: 'Parcels in custody', value: parcelsInCustody, detail: 'Across active facilities', icon: Package },
    ];

    const closeDetails = () => {
        setSelectedHub(null);
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
                <span className="flex items-center gap-1.5 text-xs text-slate-600">
                    <span className="font-semibold text-slate-800">{scope.company_name}</span>
                    {scope.company_code && <span className="text-slate-400">· {scope.company_code}</span>}
                </span>
            )}
            actions={scope.can_scan ? (
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-[#C20836] active:scale-[0.99]"
                >
                    <ScanLine className="h-4 w-4" />
                    Scan parcel
                </Link>
            ) : undefined}
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-6 font-sans">
                <HubPageHeader title="Hub network" description="See how your company’s hubs connect. Choose a pin or facility card to inspect its coverage and available actions." icon={Building2} />
                <div className="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(260px,1fr)]">
                    <div className="rounded-xl overflow-hidden border border-zinc-200/90 shadow-sm bg-white">
                        <HubMap hubs={filteredHubs} selectedId={selectedHub?.id ?? activeHub?.id} onSelect={hub => setSelectedHub(hubs.find(item => item.id === hub.id) ?? null)} className="h-[360px]" />
                    </div>
                    <HubPanel title="Your network at a glance" icon={<Warehouse className="h-4 w-4" />}>
                        <div className="flex gap-6">
                            <div>
                                <p className="text-3xl font-black tracking-tight text-[#1C1A17]">{motherHubs.length}</p>
                                <p className="mt-1 text-xs text-zinc-500 font-medium">Mother Hubs</p>
                            </div>
                            <div className="border-l border-zinc-200 pl-6">
                                <p className="text-3xl font-black tracking-tight text-[#1C1A17]">{bayanHubs.length}</p>
                                <p className="mt-1 text-xs text-zinc-500 font-medium">Bayan Hubs</p>
                            </div>
                        </div>
                        <div className="mt-5 space-y-3 border-t border-zinc-100 pt-4 text-xs text-zinc-600">
                            <p className="flex items-center gap-2"><span className="h-3 w-3 rounded-md bg-[#1C1A17]" />Mother Hub · transfers between service areas</p>
                            <p className="flex items-center gap-2"><span className="h-3 w-3 rounded-full bg-[#E00D42]" />Bayan Hub · pickup, local sorting and delivery</p>
                        </div>
                        <p className="mt-4 text-xs leading-5 text-zinc-500">Map links show configured hub relationships. A parcel follows its own saved route and actual scans.</p>
                        {activeHub && (
                            <div className="mt-4 rounded-xl bg-zinc-50 border border-zinc-200/80 p-3.5 shadow-xs">
                                <p className="text-[11px] text-zinc-500 font-medium">Current facility view</p>
                                <p className="mt-0.5 text-xs font-bold text-[#1C1A17]">{activeHub.name}</p>
                            </div>
                        )}
                    </HubPanel>
                </div>
                {scope.can_switch_facility && <details className="rounded-xl border border-zinc-200/90 bg-white shadow-sm overflow-hidden"><summary className="cursor-pointer px-6 py-4.5 text-xs sm:text-sm font-bold text-[#1C1A17] hover:bg-zinc-50/50 transition-colors">People & placements <span className="ml-2 text-xs font-normal text-zinc-500">Rider requests and handler assignments</span></summary><div className="space-y-4 border-t border-zinc-100 p-5 sm:p-6">
                <CompanyRiderIntake workspace={riderIntake} />
                {scope.can_switch_facility && hubs.length > 0 && (
                    <form className="rounded-xl border border-zinc-200/90 bg-white p-5 sm:p-6 shadow-xs" onSubmit={(event) => {
                        event.preventDefault();
                        placement.post(route('hub.placements'), { preserveScroll: true, onSuccess: () => placement.reset('email') });
                    }}>
                        <h2 className="text-sm sm:text-base font-bold text-[#1C1A17]">Assign a hub handler</h2>
                        <p className="mt-1 text-xs leading-5 text-zinc-500">Use an approved logistics account’s email. New rider assignments use company offers and rider acceptance above.</p>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <label className="space-y-1.5 text-xs font-bold text-[#1C1A17]">
                                <span>Account email</span>
                                <input type="email" required maxLength={254} className="block w-full rounded-xl border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white text-xs text-[#1C1A17] font-medium shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 px-3.5 py-2.5 transition-colors" value={placement.data.email} onChange={(event) => placement.setData('email', event.target.value)} placeholder="handler@company.com" />
                            </label>
                            <label className="space-y-1.5 text-xs font-bold text-[#1C1A17]">
                                <span>Destination facility</span>
                                <select required className="block w-full rounded-xl border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white text-xs text-[#1C1A17] font-medium shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 px-3.5 py-2.5 transition-colors" value={placement.data.hub_id} onChange={(event) => {
                                    placement.setData('hub_id', event.target.value);
                                }}>
                                    <option value="">Choose a facility</option>
                                    {hubs.map((hub) => <option key={hub.id} value={hub.id}>{hub.name}</option>)}
                                </select>
                            </label>
                        </div>
                        {Object.values(placement.errors).map((error, index) => <p key={index} role="alert" className="mt-2 text-xs font-medium text-rose-700">{error}</p>)}
                        <button type="submit" disabled={placement.processing} className="mt-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] px-5 py-2.5 text-xs font-bold text-white shadow-sm transition active:scale-[0.99] disabled:opacity-50">{placement.processing ? 'Saving…' : 'Save placement'}</button>
                    </form>
                )}
                </div></details>}
                <section className="rounded-xl border border-zinc-200/90 bg-white p-5 sm:p-6 shadow-sm">
                    <div className="flex flex-col gap-3 border-b border-zinc-100 pb-4 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <h2 className="text-base font-bold text-[#1C1A17]">Company facilities</h2>
                            <p className="mt-1 text-xs leading-5 text-zinc-500">
                                Only active facilities owned by {scope.company_name} are listed below.
                            </p>
                        </div>
                        {activeHub && (
                            <div className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 shadow-xs">
                                <p className="text-[11px] text-zinc-500 font-medium">Working facility</p>
                                <p className="mt-0.5 text-xs font-bold text-[#1C1A17]">
                                    {activeHub.name} <span className="font-mono text-xs font-bold text-[#E00D42] ml-1">{activeHub.code}</span>
                                </p>
                            </div>
                        )}
                    </div>

                    <div className="mt-5 grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                        {networkMetrics.map((metric) => {
                            const Icon = metric.icon;
                            return (
                                <div key={metric.label} className="rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-4 shadow-xs">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-xs text-zinc-500 font-medium">{metric.label}</p>
                                            <p className="mt-1 text-xl sm:text-2xl font-black text-[#1C1A17]">{metric.value.toLocaleString()}</p>
                                        </div>
                                        <span className="flex h-10 w-10 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-600 shadow-xs">
                                            <Icon className="h-4 w-4" />
                                        </span>
                                    </div>
                                    <p className="mt-2 text-xs text-zinc-400 font-medium">{metric.detail}</p>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="rounded-xl border border-zinc-200/90 bg-white p-4.5 sm:p-5 shadow-sm">
                    <div className="flex flex-col gap-3.5 lg:flex-row lg:items-center lg:justify-between">
                        <div className="relative w-full lg:max-w-md">
                            <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                            <input
                                type="search"
                                aria-label="Search facilities" value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search facility, code, municipality, or province"
                                className="w-full rounded-xl border border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white py-2.5 pl-9 pr-3.5 text-xs font-medium text-[#1C1A17] placeholder:text-zinc-400 focus:outline-none focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 shadow-xs transition-colors"
                            />
                        </div>

                        <div className="grid grid-cols-3 gap-1 rounded-xl border border-zinc-200/90 bg-zinc-100/70 p-1 text-xs">
                            {[
                                { value: 'all' as const, label: `All (${hubs.length})` },
                                { value: 'regional_mother_hub' as const, label: `Mother (${motherHubs.length})` },
                                { value: 'local_bayan_hub' as const, label: `Bayan (${bayanHubs.length})` },
                            ].map((filter) => (
                                <button
                                    key={filter.value}
                                    type="button"
                                    onClick={() => setFilterTier(filter.value)}
                                    className={`rounded-lg px-3 py-1.5 font-bold transition-all duration-150 ${
                                        filterTier === filter.value
                                            ? 'bg-white text-[#1C1A17] shadow-xs'
                                            : 'text-zinc-500 hover:text-zinc-900'
                                    }`}
                                >
                                    {filter.label}
                                </button>
                            ))}
                        </div>
                    </div>
                </section>

                {filteredHubs.length > 0 ? (
                    <section className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
                        {filteredHubs.map((hub) => {
                            const isWorkingFacility = scope.active_hub_id === hub.id;
                            const capacityTone = hub.utilization >= 80 ? 'bg-amber-500' : 'bg-[#E00D42]';

                            return (
                                <button
                                    key={hub.id}
                                    type="button"
                                    onClick={() => setSelectedHub(hub)}
                                    className={`group flex min-h-64 flex-col rounded-xl border bg-white p-5 sm:p-6 text-left shadow-sm transition-all duration-150 hover:border-zinc-300 hover:shadow-md ${
                                        isWorkingFacility ? 'border-[#E00D42] ring-2 ring-[#E00D42]/15' : 'border-zinc-200/90'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <span className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border shadow-xs ${
                                            isWorkingFacility
                                                ? 'border-rose-200 bg-rose-50 text-[#E00D42]'
                                                : 'border-zinc-200 bg-zinc-50 text-zinc-700'
                                        }`}>
                                            {hub.tier === 'regional_mother_hub'
                                                ? <Warehouse className="h-5 w-5" />
                                                : <Store className="h-5 w-5" />}
                                        </span>
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            <span className="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-bold uppercase text-zinc-700 shadow-xs">
                                                {facilityType(hub.tier)}
                                            </span>
                                            {isWorkingFacility && (
                                                <span className="inline-flex items-center gap-1 rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold uppercase text-emerald-700 shadow-xs">
                                                    <Check className="h-3 w-3" /> Working
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-4">
                                        <div className="flex items-center gap-2">
                                            <h3 className="line-clamp-1 text-base font-bold text-[#1C1A17]">{hub.name}</h3>
                                            <span className="shrink-0 text-xs font-mono font-bold text-zinc-400">{hub.code}</span>
                                        </div>
                                        <p className="mt-1 flex items-center gap-1.5 text-xs text-zinc-500 font-medium">
                                            <MapPin className="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                            <span className="truncate">{hub.city_municipality}, {hub.province}</span>
                                        </p>
                                    </div>

                                    <div className="mt-4 rounded-xl border border-zinc-200/80 bg-zinc-50/70 p-3.5 shadow-xs">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-zinc-500 font-medium">Capacity used</span>
                                            <span className="font-bold text-[#1C1A17]">
                                                {hub.parcel_count.toLocaleString()} / {hub.capacity.toLocaleString()}
                                            </span>
                                        </div>
                                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200">
                                            <div
                                                className={`h-full rounded-full ${capacityTone}`}
                                                style={{ width: `${Math.min(100, hub.utilization)}%` }}
                                            />
                                        </div>
                                        <div className="mt-3.5 grid grid-cols-3 divide-x divide-zinc-200 text-center">
                                            <div>
                                                <span className="block text-xs text-zinc-500 font-medium">Vehicles</span>
                                                <span className="mt-0.5 block text-sm font-bold text-[#1C1A17]">{hub.fleet_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-xs text-zinc-500 font-medium">Handlers</span>
                                                <span className="mt-0.5 block text-sm font-bold text-[#1C1A17]">{hub.handlers_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-xs text-zinc-500 font-medium">Barangays</span>
                                                <span className="mt-0.5 block text-sm font-bold text-[#1C1A17]">{hub.coverage_barangays.length}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-auto flex items-center justify-between border-t border-zinc-100 pt-3.5 text-xs">
                                        <span className="text-zinc-500 font-medium">
                                            {hub.allows_self_pickup ? `${hub.ready_pickup_count} awaiting counter pickup` : 'Transfer facility'}
                                        </span>
                                        <span className="inline-flex items-center gap-1 font-bold text-[#E00D42] group-hover:text-[#C20836]">
                                            View facility
                                            <ChevronRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                                        </span>
                                    </div>
                                </button>
                            );
                        })}
                    </section>
                ) : (
                    <section className="rounded-xl border border-dashed border-zinc-200 bg-white p-12 text-center">
                        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-400 mb-3">
                            <Building2 className="h-6 w-6" />
                        </span>
                        <h2 className="text-sm font-bold text-[#1C1A17]">No facilities found</h2>
                        <p className="mt-1 text-xs text-zinc-500">Try another search or facility type.</p>
                    </section>
                )}
            </div>

            {selectedHub && (
                <div className="!mt-0 fixed inset-0 z-50 overscroll-contain font-sans">
                    <button
                        type="button"
                        aria-label="Close facility details"
                        onClick={closeDetails}
                        className="absolute inset-0 h-full w-full bg-black/40 backdrop-blur-xs transition-opacity"
                    />

                    <aside
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="facility-details-title"
                        className="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col overscroll-contain overflow-y-auto border-l border-zinc-200/90 bg-white shadow-2xl"
                    >
                        <header className="border-b border-zinc-100 border-t-4 border-t-[#E00D42] bg-white px-6 py-5">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-bold uppercase text-zinc-700 shadow-xs">
                                            {facilityType(selectedHub.tier)}
                                        </span>
                                        <span className="text-xs font-mono font-bold text-[#E00D42]">{selectedHub.code}</span>
                                    </div>
                                    <h2 id="facility-details-title" className="mt-3 text-xl font-black text-[#1C1A17]">
                                        {selectedHub.name}
                                    </h2>
                                    <p className="mt-1 text-xs text-zinc-500 font-medium">{selectedHub.company_name}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeDetails}
                                    className="rounded-xl border border-zinc-200/90 p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 transition-colors shadow-xs"
                                    aria-label="Close facility details"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>

                            <div className="mt-4">
                                {scope.active_hub_id === selectedHub.id ? (
                                    <div className="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2 text-xs font-bold text-emerald-700 shadow-xs">
                                        <Check className="h-4 w-4" />
                                        Current working facility
                                    </div>
                                ) : scope.can_switch_facility ? (
                                    <button
                                        type="button"
                                        onClick={() => handleSwitchHub(selectedHub.id)}
                                        className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] px-4 py-2 text-xs font-bold text-white shadow-sm transition active:scale-[0.99]"
                                    >
                                        <Building2 className="h-4 w-4" />
                                        Use as working facility
                                    </button>
                                ) : (
                                    <p className="text-xs text-zinc-500 font-medium">Your account is restricted to its assigned facility.</p>
                                )}
                            </div>
                        </header>

                        <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                            <section>
                                <h3 className="text-sm font-bold text-[#1C1A17]">Facility snapshot</h3>
                                <div className="mt-3.5 grid grid-cols-2 gap-3.5">
                                    {[
                                        { label: 'Parcels in custody', value: selectedHub.parcel_count.toLocaleString(), icon: Package },
                                        { label: 'Staging capacity', value: selectedHub.capacity.toLocaleString(), icon: Warehouse },
                                        { label: 'Active vehicles', value: selectedHub.fleet_count.toLocaleString(), icon: Truck },
                                        { label: 'Active handlers', value: selectedHub.handlers_count.toLocaleString(), icon: Users },
                                    ].map((item) => {
                                        const Icon = item.icon;
                                        return (
                                            <div key={item.label} className="rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-4 shadow-xs">
                                                <div className="flex items-center gap-2 text-zinc-500">
                                                    <Icon className="h-4 w-4" />
                                                    <span className="text-xs font-medium">{item.label}</span>
                                                </div>
                                                <p className="mt-2 text-lg font-black text-[#1C1A17]">{item.value}</p>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-3.5 rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="text-zinc-500 font-medium">Capacity used</span>
                                        <span className="font-bold text-[#1C1A17]">{selectedHub.utilization}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200">
                                        <div
                                            className={`h-full rounded-full ${selectedHub.utilization >= 80 ? 'bg-amber-500' : 'bg-[#E00D42]'}`}
                                            style={{ width: `${Math.min(100, selectedHub.utilization)}%` }}
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <h3 className="text-sm font-bold text-[#1C1A17]">Location</h3>
                                <div className="mt-3.5 rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-4.5 shadow-xs">
                                    <div className="flex items-start gap-3 text-xs leading-5 text-zinc-700 font-medium">
                                        <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-[#E00D42]" />
                                        <span>
                                            {selectedHub.address}, {selectedHub.barangay}, {selectedHub.city_municipality}, {selectedHub.province}
                                        </span>
                                    </div>
                                    <div className="mt-3 border-t border-zinc-200/80 pt-3 text-xs text-zinc-500">
                                        GPS pin: <span className="font-mono font-bold text-[#1C1A17]">{Number(selectedHub.latitude).toFixed(4)}° N, {Number(selectedHub.longitude).toFixed(4)}° E</span>
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-sm font-bold text-[#1C1A17]">Service coverage</h3>
                                        <p className="mt-0.5 text-xs text-zinc-500 font-medium">
                                            {selectedHub.coverage_barangays.length} assigned barangays
                                        </p>
                                    </div>
                                    <span className="rounded-lg border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-semibold text-zinc-700 shadow-xs">
                                        {selectedHub.allows_self_pickup ? 'Counter pickup available' : 'Transfer only'}
                                    </span>
                                </div>

                                {selectedHub.coverage_barangays.length > 8 && (
                                    <div className="relative mt-3.5">
                                        <Search className="absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                                        <input
                                            type="search"
                                            aria-label="Search assigned barangays" value={coverageSearch}
                                            onChange={(event) => setCoverageSearch(event.target.value)}
                                            placeholder="Search assigned barangay"
                                            className="w-full rounded-xl border border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white py-2 pl-9 pr-3 text-xs font-medium text-[#1C1A17] placeholder:text-zinc-400 focus:outline-none focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 shadow-xs transition-colors"
                                        />
                                    </div>
                                )}

                                <div className="mt-3.5 grid max-h-48 grid-cols-1 gap-2.5 overflow-y-auto sm:grid-cols-2">
                                    {filteredCoverage.length > 0 ? filteredCoverage.map((barangay) => (
                                        <div key={barangay} className="rounded-xl border border-zinc-200/90 bg-white px-3.5 py-2.5 text-xs font-semibold text-[#1C1A17] shadow-xs">
                                            {barangay}
                                        </div>
                                    )) : (
                                        <p className="col-span-full rounded-xl border border-dashed border-zinc-200 p-4 text-center text-xs text-zinc-400 font-medium">
                                            No matching barangay found.
                                        </p>
                                    )}
                                </div>
                            </section>

                            <section className="border-t border-zinc-100 pt-6">
                                <h3 className="text-sm font-bold text-[#1C1A17]">Facility tools</h3>
                                <div className="mt-3.5 space-y-2.5">
                                    <Link
                                        href={route('hub.scan.station')}
                                        className="flex items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-4 py-3 text-xs font-bold text-[#1C1A17] shadow-xs transition hover:bg-zinc-50 hover:border-zinc-300"
                                    >
                                        <span className="flex items-center gap-2.5"><ScanLine className="h-4 w-4 text-[#E00D42]" /> Scan parcel</span>
                                        <ChevronRight className="h-4 w-4 text-zinc-400" />
                                    </Link>
                                    <Link
                                        href={route('hub.deliveries')}
                                        className="flex items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-4 py-3 text-xs font-bold text-[#1C1A17] shadow-xs transition hover:bg-zinc-50 hover:border-zinc-300"
                                    >
                                        <span className="flex items-center gap-2.5"><Package className="h-4 w-4 text-zinc-600" /> Parcels and waybills</span>
                                        <ChevronRight className="h-4 w-4 text-zinc-400" />
                                    </Link>
                                    {selectedHub.allows_self_pickup && (
                                        <Link
                                            href={route('hub.counter')}
                                            className="flex items-center justify-between rounded-xl border border-zinc-200/90 bg-white px-4 py-3 text-xs font-bold text-[#1C1A17] shadow-xs transition hover:bg-zinc-50 hover:border-zinc-300"
                                        >
                                            <span className="flex items-center gap-2.5"><Store className="h-4 w-4 text-zinc-600" /> Counter pickup</span>
                                            <ChevronRight className="h-4 w-4 text-zinc-400" />
                                        </Link>
                                    )}
                                </div>
                            </section>
                        </div>
                    </aside>
                </div>
            )}
        </DashboardLayout>
    );
}
