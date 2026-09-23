import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
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

interface NetworkScope {
    company_name: string;
    company_code: string | null;
    active_hub_id: number | null;
    can_switch_facility: boolean;
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
    latitude: number;
    longitude: number;
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
}

const facilityType = (tier: HubItem['tier']) => (
    tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'
);

export default function HubNetwork({ scope, hubs }: Props) {
    const [filterTier, setFilterTier] = useState<'all' | HubItem['tier']>('all');
    const [search, setSearch] = useState('');
    const [selectedHub, setSelectedHub] = useState<HubItem | null>(null);
    const [coverageSearch, setCoverageSearch] = useState('');

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
    const pickupCounters = bayanHubs.filter((hub) => hub.allows_self_pickup);
    const totalCapacity = hubs.reduce((total, hub) => total + hub.capacity, 0);
    const parcelsInCustody = hubs.reduce((total, hub) => total + hub.parcel_count, 0);

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
            actions={(
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-2 rounded-xs bg-[#E00D42] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#C20836]"
                >
                    <ScanLine className="h-4 w-4" />
                    Scan parcel
                </Link>
            )}
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-5 font-sans">
                <section className="grid gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(280px,0.75fr)]">
                    <div className="rounded-lg border border-slate-300 bg-white p-5 shadow-xs">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 className="text-base font-bold text-slate-900">Connected road network</h2>
                                <p className="mt-1 text-xs leading-5 text-slate-500">
                                    Every parcel enters through a Bayan Hub, passes through a Mother Hub, then reaches its destination Bayan Hub.
                                </p>
                            </div>
                            <span className="mt-2 inline-flex w-fit items-center gap-1.5 rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700 sm:mt-0">
                                <Package className="h-3.5 w-3.5" />
                                {parcelsInCustody.toLocaleString()} in custody
                            </span>
                        </div>

                        <div className="mt-5 grid gap-2 sm:grid-cols-[1fr_auto_1fr_auto_1fr] sm:items-center">
                            <div className="rounded-md border border-slate-300 bg-slate-50 p-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700">
                                        <Store className="h-4 w-4" />
                                    </span>
                                    <div>
                                        <p className="text-sm font-bold text-slate-900">Origin Bayan Hub</p>
                                        <p className="mt-0.5 text-xs text-slate-500">Local intake and feeder dispatch</p>
                                    </div>
                                </div>
                            </div>
                            <ArrowRight className="mx-auto hidden h-4 w-4 text-slate-400 sm:block" />
                            <div className="rounded-md border border-[#E00D42]/40 bg-[#FDF2F4] p-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-md border border-[#E00D42]/30 bg-white text-[#E00D42]">
                                        <Warehouse className="h-4 w-4" />
                                    </span>
                                    <div>
                                        <p className="text-sm font-bold text-slate-900">Mother Hub</p>
                                        <p className="mt-0.5 text-xs text-slate-500">Required regional sortation</p>
                                    </div>
                                </div>
                            </div>
                            <ArrowRight className="mx-auto hidden h-4 w-4 text-slate-400 sm:block" />
                            <div className="rounded-md border border-slate-300 bg-slate-50 p-4">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-md border border-slate-300 bg-white text-slate-700">
                                        <Store className="h-4 w-4" />
                                    </span>
                                    <div>
                                        <p className="text-sm font-bold text-slate-900">Destination Bayan Hub</p>
                                        <p className="mt-0.5 text-xs text-slate-500">Rider dispatch or counter pickup</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-300 bg-white p-5 shadow-xs">
                        <h2 className="text-base font-bold text-slate-900">Network at a glance</h2>
                        <div className="mt-4 divide-y divide-slate-200">
                            <div className="flex items-center justify-between py-3 first:pt-0">
                                <span className="text-xs text-slate-500">Owned facilities</span>
                                <span className="text-sm font-bold text-slate-900">{hubs.length}</span>
                            </div>
                            <div className="flex items-center justify-between py-3">
                                <span className="text-xs text-slate-500">Mother / Bayan Hubs</span>
                                <span className="text-sm font-bold text-slate-900">{motherHubs.length} / {bayanHubs.length}</span>
                            </div>
                            <div className="flex items-center justify-between py-3">
                                <span className="text-xs text-slate-500">Total staging capacity</span>
                                <span className="text-sm font-bold text-slate-900">{totalCapacity.toLocaleString()}</span>
                            </div>
                            <div className="flex items-center justify-between py-3 pb-0">
                                <span className="text-xs text-slate-500">Self-pickup counters</span>
                                <span className="text-sm font-bold text-slate-900">{pickupCounters.length}</span>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="rounded-lg border border-slate-300 bg-white p-4 shadow-xs">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="relative w-full lg:max-w-md">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Search facility, code, municipality, or province"
                                className="w-full rounded-xs border-slate-300 bg-white py-2 pl-9 pr-3 text-xs text-slate-800 placeholder:text-slate-400 focus:border-[#E00D42] focus:ring-[#E00D42]"
                            />
                        </div>

                        <div className="grid grid-cols-3 gap-1 rounded-md border border-slate-300 bg-slate-50 p-1 text-xs">
                            {[
                                { value: 'all' as const, label: `All (${hubs.length})` },
                                { value: 'regional_mother_hub' as const, label: `Mother (${motherHubs.length})` },
                                { value: 'local_bayan_hub' as const, label: `Bayan (${bayanHubs.length})` },
                            ].map((filter) => (
                                <button
                                    key={filter.value}
                                    type="button"
                                    onClick={() => setFilterTier(filter.value)}
                                    className={`rounded-xs px-3 py-1.5 font-semibold transition ${
                                        filterTier === filter.value
                                            ? 'bg-white text-slate-900 shadow-xs'
                                            : 'text-slate-500 hover:text-slate-800'
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
                                    className={`group flex min-h-64 flex-col rounded-lg border bg-white p-5 text-left shadow-xs transition hover:border-slate-400 hover:shadow-sm ${
                                        isWorkingFacility ? 'border-[#E00D42] ring-1 ring-[#E00D42]/20' : 'border-slate-300'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-md border ${
                                            isWorkingFacility
                                                ? 'border-[#E00D42]/30 bg-[#FDF2F4] text-[#E00D42]'
                                                : 'border-slate-300 bg-slate-50 text-slate-700'
                                        }`}>
                                            {hub.tier === 'regional_mother_hub'
                                                ? <Warehouse className="h-4 w-4" />
                                                : <Store className="h-4 w-4" />}
                                        </span>
                                        <div className="flex flex-wrap justify-end gap-1.5">
                                            <span className="rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-xs font-bold uppercase text-slate-700">
                                                {facilityType(hub.tier)}
                                            </span>
                                            {isWorkingFacility && (
                                                <span className="inline-flex items-center gap-1 rounded-xs border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-bold uppercase text-emerald-700">
                                                    <Check className="h-3 w-3" /> Working
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <div className="mt-4">
                                        <div className="flex items-center gap-2">
                                            <h3 className="line-clamp-1 text-base font-bold text-slate-900">{hub.name}</h3>
                                            <span className="shrink-0 text-xs font-semibold text-slate-400">{hub.code}</span>
                                        </div>
                                        <p className="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                                            <MapPin className="h-3.5 w-3.5 shrink-0" />
                                            <span className="truncate">{hub.city_municipality}, {hub.province}</span>
                                        </p>
                                    </div>

                                    <div className="mt-4 rounded-md border border-slate-300 bg-slate-50 p-3">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-slate-500">Capacity used</span>
                                            <span className="font-semibold text-slate-800">
                                                {hub.parcel_count.toLocaleString()} / {hub.capacity.toLocaleString()}
                                            </span>
                                        </div>
                                        <div className="mt-2 h-1.5 overflow-hidden rounded-xs bg-slate-200">
                                            <div
                                                className={`h-full rounded-xs ${capacityTone}`}
                                                style={{ width: `${Math.min(100, hub.utilization)}%` }}
                                            />
                                        </div>
                                        <div className="mt-3 grid grid-cols-3 divide-x divide-slate-300 text-center">
                                            <div>
                                                <span className="block text-xs text-slate-500">Vehicles</span>
                                                <span className="mt-0.5 block text-sm font-bold text-slate-900">{hub.fleet_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-xs text-slate-500">Handlers</span>
                                                <span className="mt-0.5 block text-sm font-bold text-slate-900">{hub.handlers_count}</span>
                                            </div>
                                            <div>
                                                <span className="block text-xs text-slate-500">Barangays</span>
                                                <span className="mt-0.5 block text-sm font-bold text-slate-900">{hub.coverage_barangays.length}</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-auto flex items-center justify-between border-t border-slate-200 pt-3 text-xs">
                                        <span className="text-slate-500">
                                            {hub.allows_self_pickup ? `${hub.ready_pickup_count} awaiting counter pickup` : 'Transfer facility'}
                                        </span>
                                        <span className="inline-flex items-center gap-1 font-semibold text-[#E00D42]">
                                            View facility
                                            <ChevronRight className="h-3.5 w-3.5 transition group-hover:translate-x-0.5" />
                                        </span>
                                    </div>
                                </button>
                            );
                        })}
                    </section>
                ) : (
                    <section className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
                        <Building2 className="mx-auto h-8 w-8 text-slate-400" />
                        <h2 className="mt-3 text-sm font-bold text-slate-900">No facilities found</h2>
                        <p className="mt-1 text-xs text-slate-500">Try another search or facility type.</p>
                    </section>
                )}
            </div>

            {selectedHub && (
                <div className="!mt-0 fixed inset-0 z-50 font-sans">
                    <button
                        type="button"
                        aria-label="Close facility details"
                        onClick={closeDetails}
                        className="absolute inset-0 h-full w-full bg-slate-950/25"
                    />

                    <aside
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="facility-details-title"
                        className="absolute inset-y-0 right-0 flex w-full max-w-xl flex-col border-l border-slate-300 bg-white shadow-2xl"
                    >
                        <header className="border-b border-slate-300 border-t-4 border-t-[#E00D42] bg-white px-5 py-4 sm:px-6">
                            <div className="flex items-start justify-between gap-4">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-xs font-bold uppercase text-slate-700">
                                            {facilityType(selectedHub.tier)}
                                        </span>
                                        <span className="text-xs font-semibold text-[#E00D42]">{selectedHub.code}</span>
                                    </div>
                                    <h2 id="facility-details-title" className="mt-3 text-xl font-extrabold text-slate-900">
                                        {selectedHub.name}
                                    </h2>
                                    <p className="mt-1 text-xs text-slate-500">{selectedHub.company_name}</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeDetails}
                                    className="rounded-xs border border-slate-300 p-2 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                                    aria-label="Close facility details"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>

                            <div className="mt-4">
                                {scope.active_hub_id === selectedHub.id ? (
                                    <div className="inline-flex items-center gap-2 rounded-xs border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                        <Check className="h-4 w-4" />
                                        Current working facility
                                    </div>
                                ) : scope.can_switch_facility ? (
                                    <button
                                        type="button"
                                        onClick={() => handleSwitchHub(selectedHub.id)}
                                        className="inline-flex items-center gap-2 rounded-xs bg-[#E00D42] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#C20836]"
                                    >
                                        <Building2 className="h-4 w-4" />
                                        Use as working facility
                                    </button>
                                ) : (
                                    <p className="text-xs text-slate-500">Your account is restricted to its assigned facility.</p>
                                )}
                            </div>
                        </header>

                        <div className="flex-1 space-y-6 overflow-y-auto px-5 py-5 sm:px-6">
                            <section>
                                <h3 className="text-sm font-bold text-slate-900">Facility snapshot</h3>
                                <div className="mt-3 grid grid-cols-2 gap-3">
                                    {[
                                        { label: 'Parcels in custody', value: selectedHub.parcel_count.toLocaleString(), icon: Package },
                                        { label: 'Staging capacity', value: selectedHub.capacity.toLocaleString(), icon: Warehouse },
                                        { label: 'Active vehicles', value: selectedHub.fleet_count.toLocaleString(), icon: Truck },
                                        { label: 'Active handlers', value: selectedHub.handlers_count.toLocaleString(), icon: Users },
                                    ].map((item) => {
                                        const Icon = item.icon;
                                        return (
                                            <div key={item.label} className="rounded-md border border-slate-300 bg-slate-50 p-3">
                                                <div className="flex items-center gap-2 text-slate-500">
                                                    <Icon className="h-4 w-4" />
                                                    <span className="text-xs">{item.label}</span>
                                                </div>
                                                <p className="mt-2 text-lg font-extrabold text-slate-900">{item.value}</p>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-3 rounded-md border border-slate-300 bg-white p-3">
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="text-slate-500">Capacity used</span>
                                        <span className="font-semibold text-slate-800">{selectedHub.utilization}%</span>
                                    </div>
                                    <div className="mt-2 h-1.5 overflow-hidden rounded-xs bg-slate-200">
                                        <div
                                            className={`h-full rounded-xs ${selectedHub.utilization >= 80 ? 'bg-amber-500' : 'bg-[#E00D42]'}`}
                                            style={{ width: `${Math.min(100, selectedHub.utilization)}%` }}
                                        />
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-slate-200 pt-5">
                                <h3 className="text-sm font-bold text-slate-900">Location</h3>
                                <div className="mt-3 rounded-md border border-slate-300 bg-slate-50 p-4">
                                    <div className="flex items-start gap-2.5 text-xs leading-5 text-slate-700">
                                        <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-[#E00D42]" />
                                        <span>
                                            {selectedHub.address}, {selectedHub.barangay}, {selectedHub.city_municipality}, {selectedHub.province}
                                        </span>
                                    </div>
                                    <div className="mt-3 border-t border-slate-300 pt-3 text-xs text-slate-500">
                                        GPS pin: <span className="font-semibold text-slate-700">{Number(selectedHub.latitude).toFixed(4)}° N, {Number(selectedHub.longitude).toFixed(4)}° E</span>
                                    </div>
                                </div>
                            </section>

                            <section className="border-t border-slate-200 pt-5">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-sm font-bold text-slate-900">Service coverage</h3>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {selectedHub.coverage_barangays.length} assigned barangays
                                        </p>
                                    </div>
                                    <span className="rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">
                                        {selectedHub.allows_self_pickup ? 'Counter pickup available' : 'Transfer only'}
                                    </span>
                                </div>

                                {selectedHub.coverage_barangays.length > 8 && (
                                    <div className="relative mt-3">
                                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                        <input
                                            type="search"
                                            value={coverageSearch}
                                            onChange={(event) => setCoverageSearch(event.target.value)}
                                            placeholder="Search assigned barangay"
                                            className="w-full rounded-xs border-slate-300 py-2 pl-9 pr-3 text-xs focus:border-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                    </div>
                                )}

                                <div className="mt-3 grid max-h-48 grid-cols-1 gap-2 overflow-y-auto sm:grid-cols-2">
                                    {filteredCoverage.length > 0 ? filteredCoverage.map((barangay) => (
                                        <div key={barangay} className="rounded-xs border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700">
                                            {barangay}
                                        </div>
                                    )) : (
                                        <p className="col-span-full rounded-md border border-dashed border-slate-300 p-4 text-center text-xs text-slate-500">
                                            No matching barangay found.
                                        </p>
                                    )}
                                </div>
                            </section>

                            <section className="border-t border-slate-200 pt-5">
                                <h3 className="text-sm font-bold text-slate-900">Facility tools</h3>
                                <div className="mt-3 space-y-2">
                                    <Link
                                        href={route('hub.scan.station')}
                                        className="flex items-center justify-between rounded-xs border border-slate-300 bg-white px-3 py-3 text-xs font-semibold text-slate-800 transition hover:bg-slate-50"
                                    >
                                        <span className="flex items-center gap-2.5"><ScanLine className="h-4 w-4 text-[#E00D42]" /> Scan parcel</span>
                                        <ChevronRight className="h-4 w-4 text-slate-400" />
                                    </Link>
                                    <Link
                                        href={route('hub.deliveries')}
                                        className="flex items-center justify-between rounded-xs border border-slate-300 bg-white px-3 py-3 text-xs font-semibold text-slate-800 transition hover:bg-slate-50"
                                    >
                                        <span className="flex items-center gap-2.5"><Package className="h-4 w-4 text-slate-600" /> Parcels and waybills</span>
                                        <ChevronRight className="h-4 w-4 text-slate-400" />
                                    </Link>
                                    {selectedHub.allows_self_pickup && (
                                        <Link
                                            href={route('hub.counter')}
                                            className="flex items-center justify-between rounded-xs border border-slate-300 bg-white px-3 py-3 text-xs font-semibold text-slate-800 transition hover:bg-slate-50"
                                        >
                                            <span className="flex items-center gap-2.5"><Store className="h-4 w-4 text-slate-600" /> Counter pickup</span>
                                            <ChevronRight className="h-4 w-4 text-slate-400" />
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
