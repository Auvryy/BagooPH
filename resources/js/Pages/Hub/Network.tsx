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
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-mono rounded-xs shadow-xs transition uppercase tracking-wider"
                >
                    <Building2 className="w-3.5 h-3.5" />
                    <span>Station Scanner</span>
                </Link>
            }
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-5 font-sans">
                
                {/* 1. TOP NETWORK KPI TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Total Facilities</span>
                                <Building2 className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {hubs.length}{' '}
                                    <span className="text-xs font-bold text-slate-400">stations</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Network Tier:</span>
                            <span className="font-bold text-slate-800">
                                {motherHubs.length} Mother / {bayanHubs.length} Bayan
                            </span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Self-Pickup Hubs</span>
                                <Store className="w-3.5 h-3.5 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {bayanHubs.filter((h) => h.allows_self_pickup).length}{' '}
                                    <span className="text-xs font-bold text-slate-400">counters</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Customer Pickup:</span>
                            <span className="font-bold text-emerald-700">Zero-Fee Ready</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Network Capacity</span>
                                <Layers className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {hubs.reduce((acc, h) => acc + h.capacity, 0).toLocaleString()}{' '}
                                    <span className="text-xs font-bold text-slate-400">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Corridor:</span>
                            <span className="font-bold text-slate-800">SLEX / Laguna</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Active Station</span>
                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-xs bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] uppercase font-mono">
                                    Current
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-lg sm:text-xl font-black text-slate-900 truncate">
                                    {activeHub?.name || 'Central Terminal'}
                                </p>
                                <p className="text-xs text-[#E00D42] font-mono font-bold mt-0.5">
                                    {activeHub?.code || 'STATION-01'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Workstation Mode:</span>
                            <span className="font-bold text-slate-800 capitalize">
                                {activeHub?.tier.replace(/_/g, ' ') || 'Facility'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH & FILTER CONTROLS */}
                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3 font-mono">
                    <div className="relative w-full sm:w-80">
                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search facility name, code, or town..."
                            className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-300 rounded-xs text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] transition"
                        />
                    </div>

                    <div className="flex items-center gap-1 p-0.5 bg-slate-100 rounded-xs border border-slate-200 text-xs w-full sm:w-auto justify-center">
                        <button
                            type="button"
                            onClick={() => setFilterTier('all')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filterTier === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            ALL ({hubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('regional_mother_hub')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filterTier === 'regional_mother_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            MOTHER HUBS ({motherHubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('local_bayan_hub')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filterTier === 'local_bayan_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            BAYAN HUBS ({bayanHubs.length})
                        </button>
                    </div>
                </div>

                {/* 3. FACILITY CARDS GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {filteredHubs.map((hub) => {
                        const isCurrent = activeHub?.id === hub.id;
                        return (
                            <div
                                key={hub.id}
                                className={`bg-white rounded-xs p-4.5 border transition flex flex-col justify-between ${
                                    isCurrent
                                        ? 'border-[#E00D42] ring-1 ring-[#E00D42] shadow-xs'
                                        : 'border-slate-300 hover:border-slate-400 shadow-xs'
                                }`}
                            >
                                <div>
                                    {/* Header Badges */}
                                    <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                                        <div className="flex items-center gap-1.5 font-mono">
                                            <span className={`text-[10px] font-bold px-2 py-0.5 rounded-xs uppercase tracking-wider ${
                                                hub.tier === 'regional_mother_hub'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'bg-slate-100 text-slate-800 border border-slate-300'
                                            }`}>
                                                {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                            </span>
                                            {hub.allows_self_pickup && (
                                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 border border-emerald-300">
                                                    Counter Pickup
                                                </span>
                                            )}
                                        </div>

                                        {isCurrent ? (
                                            <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-xs border border-emerald-300 font-mono">
                                                <Check className="w-3 h-3" /> Active Facility
                                            </span>
                                        ) : (
                                            <span className="text-[10px] font-mono text-slate-400">
                                                ID: #{hub.id}
                                            </span>
                                        )}
                                    </div>

                                    {/* Facility Name & Location */}
                                    <div className="mt-3">
                                        <h3 className="text-sm font-black text-slate-900 leading-snug">
                                            {hub.name}
                                        </h3>
                                        <p className="text-xs text-slate-500 font-mono font-bold mt-0.5">
                                            <span className="text-[#E00D42]">{hub.code}</span> • {hub.company_name}
                                        </p>
                                        <div className="flex items-start gap-1.5 text-xs text-slate-500 mt-2">
                                            <MapPin className="w-3.5 h-3.5 text-slate-400 shrink-0 mt-0.5" />
                                            <span>{hub.address}, {hub.city_municipality}, {hub.province}</span>
                                        </div>
                                    </div>

                                    {/* Capacity Utilization Bar */}
                                    <div className="mt-3.5 p-2.5 rounded-xs bg-slate-50 border border-slate-300">
                                        <div className="flex items-center justify-between text-[11px] font-mono mb-1.5">
                                            <span className="text-slate-500 font-bold">Throughput Load</span>
                                            <span className="font-bold text-slate-800">{hub.utilization}%</span>
                                        </div>
                                        <div className="w-full bg-slate-200 rounded-xs h-1.5 overflow-hidden">
                                            <div
                                                className={`h-full rounded-xs ${
                                                    hub.utilization > 80 ? 'bg-rose-500' : 'bg-[#E00D42]'
                                                }`}
                                                style={{ width: `${Math.min(100, Math.max(5, hub.utilization))}%` }}
                                            />
                                        </div>
                                        <div className="flex items-center justify-between text-[10px] font-mono text-slate-400 mt-1.5">
                                            <span>{hub.parcel_count} parcels held</span>
                                            <span>{hub.capacity.toLocaleString()} max</span>
                                        </div>
                                    </div>

                                    {/* Coverage Barangays Summary */}
                                    {hub.coverage_barangays && hub.coverage_barangays.length > 0 && (
                                        <div className="mt-3">
                                            <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono mb-1">
                                                Service Coverage ({hub.coverage_barangays.length} Barangays)
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {hub.coverage_barangays.slice(0, 4).map((bg, idx) => (
                                                    <span
                                                        key={idx}
                                                        className="text-[10px] font-medium px-1.5 py-0.5 rounded-xs bg-slate-100 text-slate-700 font-mono border border-slate-200"
                                                    >
                                                        {bg}
                                                    </span>
                                                ))}
                                                {hub.coverage_barangays.length > 4 && (
                                                    <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-xs bg-slate-50 text-slate-500 font-mono border border-slate-200">
                                                        +{hub.coverage_barangays.length - 4} more
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </div>

                                {/* Card Footer with Single Unified Action Button */}
                                <div className="mt-4 pt-3 border-t border-slate-200 flex items-center justify-between text-xs font-mono">
                                    <div className="flex items-center gap-3 text-slate-500 text-[11px]">
                                        <span className="flex items-center gap-1">
                                            <Truck className="w-3 h-3 text-slate-400" />
                                            {hub.fleet_count} fleet
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Users className="w-3 h-3 text-slate-400" />
                                            {hub.handlers_count} staff
                                        </span>
                                    </div>

                                    {isCurrent ? (
                                        <div className="inline-flex items-center gap-1 text-[11px] font-bold text-emerald-700 font-mono">
                                            <Check className="w-3.5 h-3.5 text-emerald-600" />
                                            <span>Current Station</span>
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                router.post(route('hub.switchHub'), { hub_id: hub.id }, { preserveScroll: true });
                                            }}
                                            className="px-3 py-1.5 bg-slate-900 hover:bg-[#E00D42] text-white text-[11px] font-bold rounded-xs transition shadow-2xs cursor-pointer flex items-center gap-1.5 font-mono"
                                        >
                                            <span>Switch Facility</span>
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
