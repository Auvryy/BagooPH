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
    X, 
    ScanLine, 
    Package, 
    ChevronRight, 
    Activity
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
    const [selectedHub, setSelectedHub] = useState<HubItem | null>(null);
    const [coverageSearch, setCoverageSearch] = useState('');

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

    const handleSwitchHub = (hubId: number, e?: React.MouseEvent) => {
        if (e) e.stopPropagation();
        router.post(route('hub.switchHub'), { hub_id: hubId }, { 
            preserveScroll: true,
            onSuccess: () => {
                if (selectedHub && selectedHub.id === hubId) {
                    setSelectedHub((prev) => prev ? { ...prev } : null);
                }
            }
        });
    };

    return (
        <DashboardLayout
            title="Facility Network"
            subtitle="Regional Mother Hubs & Local Bayan Hub topology"
            actions={
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-mono rounded-lg shadow-xs transition uppercase tracking-wider"
                >
                    <Building2 className="w-3.5 h-3.5" />
                    <span>Station Scanner</span>
                </Link>
            }
        >
            <Head title="Facility Network — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP BUSINESS KPI TILES (MATCHING SELLER DASHBOARD AESTHETIC) */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Total Facilities</span>
                                <Building2 className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {hubs.length} <span className="text-sm font-bold text-slate-500">stations</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Network Tier:</span>
                            <span className="font-bold text-slate-800">
                                {motherHubs.length} Mother • {bayanHubs.length} Bayan
                            </span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Pickup Counters</span>
                                <Store className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {bayanHubs.filter((h) => h.allows_self_pickup).length} <span className="text-sm font-bold text-slate-500">counters</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Customer Pickup:</span>
                            <span className="font-bold text-emerald-600">₱0.00 Free Counter Ready</span>
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
                                    {hubs.reduce((acc, h) => acc + h.capacity, 0).toLocaleString()} <span className="text-sm font-bold text-slate-500">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Active Corridor:</span>
                            <span className="font-bold text-slate-800">SLEX / Laguna Route</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Active Station</span>
                                <span className="inline-flex items-center gap-1 text-[#E00D42] text-[10px] font-bold bg-[#FDF2F4] px-1.5 py-0.5 rounded border border-[#FCE7EA]">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42]"></span> Operating
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight truncate" title={activeHub?.name}>
                                    {activeHub?.name || 'Central Terminal'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Station Code:</span>
                            <span className="font-bold text-[#E00D42]">{activeHub?.code || 'STATION-01'}</span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH & FILTER CONTROLS */}
                <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4 font-mono">
                    <div className="relative w-full sm:w-80">
                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search station name, code, or town..."
                            className="w-full pl-9 pr-3.5 py-2 bg-slate-50 border border-slate-200/90 rounded-xl text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] transition"
                        />
                    </div>

                    <div className="flex items-center gap-1.5 p-1 bg-slate-100 rounded-lg font-mono text-xs w-full sm:w-auto justify-center">
                        <button
                            type="button"
                            onClick={() => setFilterTier('all')}
                            className={`px-3 py-1 rounded-md text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            All ({hubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('regional_mother_hub')}
                            className={`px-3 py-1 rounded-md text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'regional_mother_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            Mother Hubs ({motherHubs.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTier('local_bayan_hub')}
                            className={`px-3 py-1 rounded-md text-[11px] font-bold transition cursor-pointer ${
                                filterTier === 'local_bayan_hub'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            Bayan Hubs ({bayanHubs.length})
                        </button>
                    </div>
                </div>

                {/* 3. CLEAN ARCHITECTURAL BENTO FACILITY GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {filteredHubs.map((hub) => {
                        const isCurrent = activeHub?.id === hub.id;
                        return (
                            <div
                                key={hub.id}
                                onClick={() => setSelectedHub(hub)}
                                className={`bg-white rounded-2xl p-5 sm:p-6 border transition-all duration-150 flex flex-col justify-between group cursor-pointer relative hover:shadow-md hover:border-slate-300 ${
                                    isCurrent
                                        ? 'border-[#E00D42] ring-2 ring-[#E00D42]/15 shadow-xs'
                                        : 'border-slate-200/90 shadow-2xs'
                                }`}
                            >
                                <div>
                                    {/* Top Header Row with Icon Emblem & Status Badges */}
                                    <div className="flex items-center justify-between gap-3">
                                        <div className={`w-11 h-11 rounded-xl flex items-center justify-center transition border ${
                                            isCurrent
                                                ? 'bg-[#FDF2F4] text-[#E00D42] border-[#FCE7EA]'
                                                : 'bg-slate-50 text-slate-700 border-slate-100 group-hover:bg-[#FDF2F4] group-hover:text-[#E00D42]'
                                        }`}>
                                            {hub.tier === 'regional_mother_hub' ? (
                                                <Building2 className="w-5 h-5" />
                                            ) : (
                                                <Store className="w-5 h-5" />
                                            )}
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <span className={`text-[10px] font-bold font-mono px-2 py-0.5 rounded uppercase tracking-wider ${
                                                hub.tier === 'regional_mother_hub'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'bg-slate-100 text-slate-700 border border-slate-200'
                                            }`}>
                                                {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                            </span>

                                            {isCurrent ? (
                                                <span className="inline-flex items-center gap-1 font-bold text-[10px] font-mono text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200">
                                                    <Check className="w-3 h-3 text-emerald-600" /> Active
                                                </span>
                                            ) : (
                                                <span className="text-xs font-mono font-bold text-slate-400 group-hover:text-slate-700 transition">
                                                    {hub.code}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Facility Name & Location */}
                                    <div className="mt-4">
                                        <h3 className="text-base font-black text-slate-900 tracking-tight leading-snug group-hover:text-[#E00D42] transition line-clamp-1">
                                            {hub.name}
                                        </h3>
                                        <div className="flex items-center gap-1.5 text-xs text-slate-500 mt-1 font-medium">
                                            <MapPin className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                            <span className="truncate">{hub.city_municipality}, {hub.province}</span>
                                        </div>
                                    </div>

                                    {/* Bento Inner Metric Block */}
                                    <div className="mt-4 p-3.5 rounded-xl bg-slate-50/80 border border-slate-100 space-y-2.5 font-mono text-xs">
                                        <div className="flex items-center justify-between text-[11px] text-slate-600">
                                            <span className="text-slate-400 font-bold uppercase tracking-tight text-[10px]">Throughput Load</span>
                                            <span className="font-bold text-slate-900">
                                                {hub.utilization}% <span className="text-slate-400 font-normal">({hub.parcel_count} / {hub.capacity.toLocaleString()})</span>
                                            </span>
                                        </div>
                                        <div className="w-full bg-slate-200/80 rounded-full h-1.5 overflow-hidden">
                                            <div
                                                className={`h-full rounded-full transition-all duration-300 ${
                                                    hub.utilization > 80 ? 'bg-rose-500' : 'bg-[#E00D42]'
                                                }`}
                                                style={{ width: `${Math.min(100, Math.max(3, hub.utilization))}%` }}
                                            />
                                        </div>

                                        <div className="grid grid-cols-3 gap-1 pt-1 text-center divide-x divide-slate-200 text-[10px]">
                                            <div>
                                                <span className="text-slate-400 block">Fleet</span>
                                                <span className="font-bold text-slate-800 font-mono mt-0.5 block">{hub.fleet_count} units</span>
                                            </div>
                                            <div className="pl-1">
                                                <span className="text-slate-400 block">Staff</span>
                                                <span className="font-bold text-slate-800 font-mono mt-0.5 block">{hub.handlers_count} staff</span>
                                            </div>
                                            <div className="pl-1">
                                                <span className="text-slate-400 block">Coverage</span>
                                                <span className="font-bold text-slate-800 font-mono mt-0.5 block">{hub.coverage_barangays?.length || 0} brgys</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Card Footer */}
                                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                                    <div>
                                        {hub.allows_self_pickup ? (
                                            <span className="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 inline-flex items-center gap-1">
                                                <Store className="w-3 h-3 text-emerald-600" /> Free Counter
                                            </span>
                                        ) : (
                                            <span className="text-[10px] text-slate-400">
                                                Line-Haul Hub
                                            </span>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-1 text-[#E00D42] group-hover:translate-x-0.5 transition-transform text-[11px] font-bold">
                                        <span>Inspect Telemetry</span>
                                        <ChevronRight className="w-3.5 h-3.5" />
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

            </div>

            {/* 4. SLIDE-OVER FACILITY TELEMETRY DRAWER */}
            {selectedHub && (
                <div className="fixed inset-0 z-50 overflow-hidden font-sans">
                    {/* Dark Backdrop */}
                    <div 
                        className="fixed inset-0 bg-black/40 backdrop-blur-xs transition-opacity animate-fade-in"
                        onClick={() => {
                            setSelectedHub(null);
                            setCoverageSearch('');
                        }}
                    />

                    {/* Slide-Over Panel */}
                    <div className="fixed inset-y-0 right-0 max-w-lg w-full bg-white border-l border-slate-200 shadow-2xl flex flex-col z-50 animate-slide-in-right">
                        
                        {/* Drawer Header */}
                        <div className="p-6 bg-slate-900 text-white shrink-0">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-800 font-mono">
                                <div className="flex items-center gap-2">
                                    <span className="px-2 py-0.5 rounded bg-white/10 text-white text-[10px] font-bold uppercase tracking-wider">
                                        {selectedHub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                    </span>
                                    <span className="text-xs text-[#E00D42] font-bold">
                                        {selectedHub.code}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelectedHub(null);
                                        setCoverageSearch('');
                                    }}
                                    className="p-1 text-slate-400 hover:text-white rounded-lg transition cursor-pointer"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <div className="mt-4">
                                <h2 className="text-xl font-black text-white tracking-tight">
                                    {selectedHub.name}
                                </h2>
                                <p className="text-xs text-slate-400 font-mono mt-1">
                                    {selectedHub.company_name}
                                </p>
                            </div>

                            {/* Active Status & Switcher CTA */}
                            <div className="mt-4 pt-3 border-t border-slate-800 flex items-center justify-between font-mono">
                                {activeHub?.id === selectedHub.id ? (
                                    <div className="flex items-center gap-2 text-emerald-400 text-xs font-bold">
                                        <Check className="w-4 h-4" />
                                        <span>Currently Active Operating Workstation</span>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => handleSwitchHub(selectedHub.id)}
                                        className="w-full py-2.5 px-4 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-lg transition shadow-xs flex items-center justify-center gap-1.5 cursor-pointer"
                                    >
                                        <Building2 className="w-3.5 h-3.5" />
                                        <span>Set as Active Operating Workstation</span>
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Drawer Scrollable Body Content */}
                        <div className="flex-1 overflow-y-auto p-6 space-y-6 divide-y divide-slate-100 font-sans">
                            
                            {/* Operational Health & Metrics */}
                            <div className="space-y-3">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                                    Operational Health & Capacity
                                </h4>
                                <div className="grid grid-cols-2 gap-3 font-mono text-xs">
                                    <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-100">
                                        <span className="text-[10px] text-slate-500 block uppercase font-bold">Utilization</span>
                                        <span className="text-xl font-black text-slate-900 mt-0.5 block">{selectedHub.utilization}%</span>
                                        <span className="text-[10px] text-slate-400">{selectedHub.parcel_count} parcels held</span>
                                    </div>
                                    <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-100">
                                        <span className="text-[10px] text-slate-500 block uppercase font-bold">Max Capacity</span>
                                        <span className="text-xl font-black text-slate-900 mt-0.5 block">{selectedHub.capacity.toLocaleString()}</span>
                                        <span className="text-[10px] text-slate-400">staging limit</span>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between p-3 bg-slate-50 rounded-xl border border-slate-100 text-xs font-mono">
                                    <span className="text-slate-600">Self-Pickup Counter:</span>
                                    <span className={`font-bold ${selectedHub.allows_self_pickup ? 'text-emerald-600' : 'text-slate-400'}`}>
                                        {selectedHub.allows_self_pickup ? '₱0.00 Free Counter Ready' : 'Disabled (Line-Haul Only)'}
                                    </span>
                                </div>
                            </div>

                            {/* Physical Location & GPS Coordinates */}
                            <div className="pt-5 space-y-2.5 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Physical Address & GPS Pin
                                </h4>
                                <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-100 space-y-2.5">
                                    <div className="flex items-start gap-2.5 text-slate-700">
                                        <MapPin className="w-4 h-4 text-[#E00D42] shrink-0 mt-0.5" />
                                        <span className="leading-relaxed font-sans text-xs">
                                            {selectedHub.address}, {selectedHub.barangay}, {selectedHub.city_municipality}, {selectedHub.province}
                                        </span>
                                    </div>
                                    <div className="pt-2 border-t border-slate-200/80 flex items-center justify-between text-[11px] text-slate-500">
                                        <span>GPS Pin:</span>
                                        <span className="font-bold text-slate-800">
                                            {selectedHub.latitude.toFixed(4)}° N, {selectedHub.longitude.toFixed(4)}° E
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Assigned Fleet & Station Handlers */}
                            <div className="pt-5 space-y-2.5 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Station Personnel & Vehicle Fleet
                                </h4>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-100 flex items-center gap-3">
                                        <div className="p-2.5 bg-blue-50 text-blue-700 rounded-lg">
                                            <Truck className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <span className="text-[10px] text-slate-500 block uppercase font-bold">Assigned Fleet</span>
                                            <span className="text-sm font-black text-slate-900">{selectedHub.fleet_count} units</span>
                                        </div>
                                    </div>
                                    <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-100 flex items-center gap-3">
                                        <div className="p-2.5 bg-purple-50 text-purple-700 rounded-lg">
                                            <Users className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <span className="text-[10px] text-slate-500 block uppercase font-bold">Station Staff</span>
                                            <span className="text-sm font-black text-slate-900">{selectedHub.handlers_count} staff</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Full Service Coverage Barangays */}
                            <div className="pt-5 space-y-3 font-mono text-xs">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        Service Coverage ({selectedHub.coverage_barangays?.length || 0} Barangays)
                                    </h4>
                                    <span className="text-[10px] text-slate-400">Dedicated Courier Delivery Zone</span>
                                </div>

                                {selectedHub.coverage_barangays && selectedHub.coverage_barangays.length > 5 && (
                                    <div className="relative">
                                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            value={coverageSearch}
                                            onChange={(e) => setCoverageSearch(e.target.value)}
                                            placeholder="Search barangay..."
                                            className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-200/90 rounded-lg text-[11px] font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42]"
                                        />
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-1.5 max-h-48 overflow-y-auto p-2 bg-slate-50 rounded-xl border border-slate-100">
                                    {selectedHub.coverage_barangays
                                        ?.filter((bg) => bg.toLowerCase().includes(coverageSearch.toLowerCase()))
                                        .map((bg, idx) => (
                                            <span
                                                key={idx}
                                                className="px-2.5 py-1 bg-white text-slate-800 text-[11px] font-medium rounded-md border border-slate-200 shadow-2xs"
                                            >
                                                {bg}
                                            </span>
                                        ))}
                                    {selectedHub.coverage_barangays?.length === 0 && (
                                        <span className="text-slate-400 text-xs p-2">No specific barangays assigned</span>
                                    )}
                                </div>
                            </div>

                            {/* Direct Workstation Shortcuts */}
                            <div className="pt-5 space-y-2 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Workstation Shortcuts
                                </h4>
                                <div className="grid grid-cols-1 gap-2">
                                    <Link
                                        href={route('hub.scan.station')}
                                        className="p-3 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xl border border-slate-200/90 transition flex items-center justify-between group"
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <ScanLine className="w-4 h-4 text-emerald-600" />
                                            <span className="font-bold">Open Floor Barcode Scanner</span>
                                        </div>
                                        <ChevronRight className="w-3.5 h-3.5 text-slate-400 group-hover:translate-x-0.5 transition-transform" />
                                    </Link>
                                    <Link
                                        href={route('hub.deliveries')}
                                        className="p-3 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xl border border-slate-200/90 transition flex items-center justify-between group"
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <Package className="w-4 h-4 text-[#E00D42]" />
                                            <span className="font-bold">View Parcels & Waybills Registry</span>
                                        </div>
                                        <ChevronRight className="w-3.5 h-3.5 text-slate-400 group-hover:translate-x-0.5 transition-transform" />
                                    </Link>
                                    {selectedHub.allows_self_pickup && (
                                        <Link
                                            href={route('hub.counter')}
                                            className="p-3 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xl border border-slate-200/90 transition flex items-center justify-between group"
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <Store className="w-4 h-4 text-blue-600" />
                                                <span className="font-bold">Counter Self-Pickup Desk</span>
                                            </div>
                                            <ChevronRight className="w-3.5 h-3.5 text-slate-400 group-hover:translate-x-0.5 transition-transform" />
                                        </Link>
                                    )}
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            )}

        </DashboardLayout>
    );
}

