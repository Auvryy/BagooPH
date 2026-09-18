import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    Building2, 
    MapPin, 
    Truck, 
    Layers, 
    Store, 
    Check, 
    Search,
    Users,
    ArrowUpRight,
    Compass
} from 'lucide-react';

interface HubItem {
    id: number;
    name: string;
    code: string;
    tier: string;
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
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    hubs: HubItem[];
}

export default function HubNetwork({ activeHub, hubs }: Props) {
    const [filterTier, setFilterTier] = useState<'all' | 'regional_mother_hub' | 'local_bayan_hub'>('all');
    const [search, setSearch] = useState('');

    const filteredHubs = hubs.filter((h) => {
        const matchesTier = filterTier === 'all' || h.tier === filterTier;
        const matchesSearch = 
            h.name.toLowerCase().includes(search.toLowerCase()) ||
            h.code.toLowerCase().includes(search.toLowerCase()) ||
            h.city_municipality.toLowerCase().includes(search.toLowerCase()) ||
            h.province.toLowerCase().includes(search.toLowerCase());
        return matchesTier && matchesSearch;
    });

    const motherHubs = hubs.filter((h) => h.tier === 'regional_mother_hub');
    const bayanHubs = hubs.filter((h) => h.tier === 'local_bayan_hub');

    return (
        <DashboardLayout
            title="Facility Network"
            subtitle="Regional Mother Hubs & Local Bayan Hub topology"
            actions={
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold font-mono rounded-lg shadow-xs transition uppercase tracking-wider"
                >
                    <Building2 className="w-3.5 h-3.5" />
                    <span>Station Scanner</span>
                </Link>
            }
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP NETWORK KPI TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Total Facilities</span>
                                <Building2 className="w-4 h-4 text-blue-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {hubs.length}{' '}
                                    <span className="text-sm font-bold text-slate-400">stations</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Network Tier:</span>
                            <span className="font-bold text-slate-800">
                                {motherHubs.length} Mother / {bayanHubs.length} Bayan
                            </span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Self-Pickup Hubs</span>
                                <Store className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {bayanHubs.filter((h) => h.allows_self_pickup).length}{' '}
                                    <span className="text-sm font-bold text-slate-400">counters</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Customer Pickup:</span>
                            <span className="font-bold text-emerald-600">Zero-Fee Ready</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Network Capacity</span>
                                <Layers className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {hubs.reduce((acc, h) => acc + h.capacity, 0).toLocaleString()}{' '}
                                    <span className="text-sm font-bold text-slate-400">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">High-Flow Corridors:</span>
                            <span className="font-bold text-slate-800">SLEX / Laguna</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Active Station</span>
                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 uppercase font-mono">
                                    Current
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-lg sm:text-xl font-black text-slate-900 truncate">
                                    {activeHub?.name || 'Central Terminal'}
                                </p>
                                <p className="text-xs text-blue-600 font-mono font-bold mt-0.5">
                                    {activeHub?.code || 'STATION-01'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Workstation Mode:</span>
                            <span className="font-bold text-slate-700 capitalize">
                                {activeHub?.tier.replace(/_/g, ' ') || 'Facility'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH & FILTER CONTROLS */}
                <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div className="relative w-full sm:w-80">
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search facility name, code, or town..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition"
                        />
                    </div>

                    <div className="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl font-mono text-xs w-full sm:w-auto justify-center">
                        <button
                            type="button"
                            onClick={() => setFilterTier('all')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            ALL ({hubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('regional_mother_hub')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'regional_mother_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            MOTHER HUBS ({motherHubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('local_bayan_hub')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'local_bayan_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            BAYAN HUBS ({bayanHubs.length})
                        </button>
                    </div>
                </div>

                {/* 3. FACILITY CARDS GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {filteredHubs.map((hub) => {
                        const isCurrent = activeHub?.id === hub.id;
                        return (
                            <div
                                key={hub.id}
                                className={`bg-white rounded-2xl p-5 border transition flex flex-col justify-between ${
                                    isCurrent
                                        ? 'border-blue-500 ring-2 ring-blue-500/20 shadow-md'
                                        : 'border-slate-200/90 hover:border-slate-300 shadow-2xs'
                                }`}
                            >
                                <div>
                                    {/* Header Badges */}
                                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                        <div className="flex items-center gap-1.5">
                                            <span className={`text-[10px] font-bold px-2 py-0.5 rounded font-mono uppercase ${
                                                hub.tier === 'regional_mother_hub'
                                                    ? 'bg-purple-50 text-purple-700 border border-purple-200'
                                                    : 'bg-blue-50 text-blue-700 border border-blue-200'
                                            }`}>
                                                {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                            </span>
                                            {hub.allows_self_pickup && (
                                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono">
                                                    Counter Pickup
                                                </span>
                                            )}
                                        </div>
                                        {isCurrent ? (
                                            <span className="inline-flex items-center gap-1 text-[10px] font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full font-mono">
                                                <Check className="w-3 h-3" /> Selected
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    router.post(route('hub.switchHub'), { hub_id: hub.id }, { preserveScroll: true });
                                                }}
                                                className="text-[10px] font-bold text-slate-500 hover:text-blue-600 font-mono cursor-pointer underline"
                                            >
                                                Switch Here
                                            </button>
                                        )}
                                    </div>

                                    {/* Facility Name & Location */}
                                    <div className="mt-3">
                                        <h3 className="text-base font-black text-slate-900 leading-snug">
                                            {hub.name}
                                        </h3>
                                        <p className="text-xs text-blue-600 font-mono font-bold mt-0.5">
                                            {hub.code} • {hub.company_name}
                                        </p>
                                        <div className="flex items-start gap-1.5 text-xs text-slate-500 mt-2">
                                            <MapPin className="w-3.5 h-3.5 text-slate-400 shrink-0 mt-0.5" />
                                            <span>{hub.address}, {hub.city_municipality}, {hub.province}</span>
                                        </div>
                                    </div>

                                    {/* Capacity Utilization Bar */}
                                    <div className="mt-4 p-3 rounded-xl bg-slate-50 border border-slate-100">
                                        <div className="flex items-center justify-between text-[11px] font-mono mb-1.5">
                                            <span className="text-slate-500 font-bold">Throughput Load</span>
                                            <span className="font-bold text-slate-800">{hub.utilization}%</span>
                                        </div>
                                        <div className="w-full bg-slate-200 rounded-full h-2 overflow-hidden">
                                            <div
                                                className={`h-full rounded-full ${
                                                    hub.utilization > 80 ? 'bg-rose-500' : 'bg-blue-600'
                                                }`}
                                                style={{ width: `${Math.min(100, Math.max(5, hub.utilization))}%` }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between text-[10px] font-mono text-slate-400 mt-1.5">
                                            <span>{hub.parcel_count} parcels held</span>
                                            <span>{hub.capacity.toLocaleString()} max</span>
                                        </div>
                                    </div>

                                    {/* Coverage Barangays */}
                                    {hub.coverage_barangays && hub.coverage_barangays.length > 0 && (
                                        <div className="mt-4">
                                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono mb-1.5">
                                                Coverage Area ({hub.coverage_barangays.length} Barangays)
                                            </p>
                                            <div className="flex flex-wrap gap-1 max-h-20 overflow-y-auto pr-1">
                                                {hub.coverage_barangays.map((bg, idx) => (
                                                    <span
                                                        key={idx}
                                                        className="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-700 font-mono"
                                                    >
                                                        {bg}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Card Footer Actions */}
                                <div className="mt-5 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                                    <div className="flex items-center gap-3 text-slate-500 text-[11px]">
                                        <span className="flex items-center gap-1">
                                            <Truck className="w-3 h-3 text-slate-400" />
                                            {hub.fleet_count} vehicles
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Users className="w-3 h-3 text-slate-400" />
                                            {hub.handlers_count} staff
                                        </span>
                                    </div>

                                    {!isCurrent && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                router.post(route('hub.switchHub'), { hub_id: hub.id }, { preserveScroll: true });
                                            }}
                                            className="font-bold text-blue-600 hover:text-blue-700 transition cursor-pointer flex items-center gap-1"
                                        >
                                            <span>Set Active</span>
                                            <ArrowUpRight className="w-3 h-3" />
                                        </button>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>

            </div>
        </DashboardLayout>
    );
}
