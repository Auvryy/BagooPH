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
    Compass,
    X,
    ScanLine,
    Package,
    Navigation,
    ExternalLink,
    ChevronRight,
    SlidersHorizontal
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
                                    Operating
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

                {/* 3. CLEAN MINIMALIST FACILITY CARDS GRID */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    {filteredHubs.map((hub) => {
                        const isCurrent = activeHub?.id === hub.id;
                        return (
                            <div
                                key={hub.id}
                                onClick={() => setSelectedHub(hub)}
                                className={`group bg-white rounded-xs p-4.5 border transition cursor-pointer flex flex-col justify-between hover:shadow-md hover:border-slate-400 ${
                                    isCurrent
                                        ? 'border-[#E00D42] ring-1 ring-[#E00D42] shadow-xs'
                                        : 'border-slate-300 shadow-2xs'
                                }`}
                            >
                                <div className="space-y-3">
                                    {/* Top Metadata Row */}
                                    <div className="flex items-center justify-between text-[10px] font-mono">
                                        <div className="flex items-center gap-1.5">
                                            <span className={`font-bold px-1.5 py-0.5 rounded-xs uppercase tracking-wider ${
                                                hub.tier === 'regional_mother_hub'
                                                    ? 'bg-slate-900 text-white'
                                                    : 'bg-slate-100 text-slate-800 border border-slate-200'
                                            }`}>
                                                {hub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                            </span>
                                            {hub.allows_self_pickup && (
                                                <span className="font-bold px-1.5 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                    Pickup Counter
                                                </span>
                                            )}
                                        </div>

                                        {isCurrent ? (
                                            <span className="inline-flex items-center gap-1 font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-xs border border-emerald-300">
                                                <Check className="w-3 h-3 text-emerald-600" /> Active
                                            </span>
                                        ) : (
                                            <span className="text-slate-400 group-hover:text-slate-700 transition font-bold">
                                                {hub.code}
                                            </span>
                                        )}
                                    </div>

                                    {/* Facility Name & Primary Location */}
                                    <div>
                                        <h3 className="text-sm font-black text-slate-900 leading-snug group-hover:text-[#E00D42] transition">
                                            {hub.name}
                                        </h3>
                                        <div className="flex items-center gap-1.5 text-xs text-slate-500 mt-1">
                                            <MapPin className="w-3.5 h-3.5 text-slate-400 shrink-0" />
                                            <span className="truncate">{hub.city_municipality}, {hub.province}</span>
                                        </div>
                                    </div>

                                    {/* Minimalist Capacity Progress */}
                                    <div className="space-y-1.5 pt-1">
                                        <div className="flex items-center justify-between text-[11px] font-mono text-slate-600">
                                            <span>Throughput Load</span>
                                            <span className="font-bold text-slate-900">{hub.utilization}% ({hub.parcel_count} / {hub.capacity.toLocaleString()})</span>
                                        </div>
                                        <div className="w-full bg-slate-100 rounded-xs h-1.5 overflow-hidden border border-slate-200">
                                            <div
                                                className={`h-full rounded-xs transition-all ${
                                                    hub.utilization > 80 ? 'bg-rose-500' : 'bg-[#E00D42]'
                                                }`}
                                                style={{ width: `${Math.min(100, Math.max(3, hub.utilization))}%` }}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* Card Footer: Quick Meta & Direct Action */}
                                <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                                    <div className="flex items-center gap-2.5 text-slate-500 text-[11px]">
                                        <span>{hub.fleet_count} fleet</span>
                                        <span>•</span>
                                        <span>{hub.handlers_count} staff</span>
                                        <span>•</span>
                                        <span>{hub.coverage_barangays?.length || 0} brgys</span>
                                    </div>

                                    {isCurrent ? (
                                        <span className="text-[11px] font-bold text-emerald-700 flex items-center gap-1">
                                            <Check className="w-3.5 h-3.5" /> Operating
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={(e) => handleSwitchHub(hub.id, e)}
                                            className="px-2.5 py-1 bg-slate-900 hover:bg-[#E00D42] text-white text-[10px] font-bold rounded-xs transition shadow-2xs cursor-pointer flex items-center gap-1"
                                        >
                                            <span>Switch</span>
                                            <ArrowUpRight className="w-3 h-3" />
                                        </button>
                                    )}
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
                    <div className="fixed inset-y-0 right-0 max-w-lg w-full bg-white border-l border-slate-300 shadow-2xl flex flex-col z-50 animate-slide-in-right">
                        
                        {/* Drawer Header */}
                        <div className="p-5 bg-slate-900 text-white shrink-0">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-800 font-mono">
                                <div className="flex items-center gap-2">
                                    <span className="px-2 py-0.5 rounded-xs bg-white/10 text-white text-[10px] font-bold uppercase tracking-wider">
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
                                    className="p-1 text-slate-400 hover:text-white rounded-xs transition cursor-pointer"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <div className="mt-3">
                                <h2 className="text-lg font-black text-white tracking-tight">
                                    {selectedHub.name}
                                </h2>
                                <p className="text-xs text-slate-400 font-mono mt-0.5">
                                    {selectedHub.company_name}
                                </p>
                            </div>

                            {/* Active Status & Switcher CTA */}
                            <div className="mt-4 pt-3 border-t border-slate-800 flex items-center justify-between font-mono">
                                {activeHub?.id === selectedHub.id ? (
                                    <div className="flex items-center gap-2 text-emerald-400 text-xs font-bold">
                                        <Check className="w-4 h-4" />
                                        <span>Currently Active Workstation</span>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => handleSwitchHub(selectedHub.id)}
                                        className="w-full py-2 px-4 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold rounded-xs transition shadow-xs flex items-center justify-center gap-1.5 cursor-pointer"
                                    >
                                        <Building2 className="w-3.5 h-3.5" />
                                        <span>Set as Active Operating Workstation</span>
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Drawer Scrollable Body Content */}
                        <div className="flex-1 overflow-y-auto p-5 space-y-5 divide-y divide-slate-100 font-sans">
                            
                            {/* Operational Health & Metrics */}
                            <div className="space-y-3">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                                    Operational Health & Capacity
                                </h4>
                                <div className="grid grid-cols-2 gap-3 font-mono text-xs">
                                    <div className="p-3 bg-slate-50 rounded-xs border border-slate-200">
                                        <span className="text-[10px] text-slate-500 block uppercase">Capacity Utilization</span>
                                        <span className="text-lg font-black text-slate-900 mt-0.5 block">{selectedHub.utilization}%</span>
                                        <span className="text-[10px] text-slate-400">{selectedHub.parcel_count} parcels held</span>
                                    </div>
                                    <div className="p-3 bg-slate-50 rounded-xs border border-slate-200">
                                        <span className="text-[10px] text-slate-500 block uppercase">Max Capacity</span>
                                        <span className="text-lg font-black text-slate-900 mt-0.5 block">{selectedHub.capacity.toLocaleString()}</span>
                                        <span className="text-[10px] text-slate-400">staging limit</span>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between p-2.5 bg-slate-50 rounded-xs border border-slate-200 text-xs font-mono">
                                    <span className="text-slate-600">Self-Pickup Counter:</span>
                                    <span className={`font-bold ${selectedHub.allows_self_pickup ? 'text-emerald-700' : 'text-slate-400'}`}>
                                        {selectedHub.allows_self_pickup ? '₱0.00 Free Counter Ready' : 'Disabled (Line-Haul Only)'}
                                    </span>
                                </div>
                            </div>

                            {/* Physical Location & GPS Coordinates */}
                            <div className="pt-4 space-y-2.5 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Physical Address & GPS Pin
                                </h4>
                                <div className="p-3 bg-slate-50 rounded-xs border border-slate-200 space-y-2">
                                    <div className="flex items-start gap-2 text-slate-700">
                                        <MapPin className="w-4 h-4 text-[#E00D42] shrink-0 mt-0.5" />
                                        <span className="leading-relaxed">
                                            {selectedHub.address}, {selectedHub.barangay}, {selectedHub.city_municipality}, {selectedHub.province}
                                        </span>
                                    </div>
                                    <div className="pt-2 border-t border-slate-200 flex items-center justify-between text-[11px] text-slate-500">
                                        <span>GPS Coordinates:</span>
                                        <span className="font-bold text-slate-800">
                                            {selectedHub.latitude.toFixed(4)}° N, {selectedHub.longitude.toFixed(4)}° E
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Assigned Fleet & Station Handlers */}
                            <div className="pt-4 space-y-2.5 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Station Personnel & Vehicle Fleet
                                </h4>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="p-3 bg-slate-50 rounded-xs border border-slate-200 flex items-center gap-3">
                                        <div className="p-2 bg-blue-50 text-blue-700 rounded-xs">
                                            <Truck className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <span className="text-[10px] text-slate-500 block uppercase">Assigned Fleet</span>
                                            <span className="text-sm font-black text-slate-900">{selectedHub.fleet_count} units</span>
                                        </div>
                                    </div>
                                    <div className="p-3 bg-slate-50 rounded-xs border border-slate-200 flex items-center gap-3">
                                        <div className="p-2 bg-purple-50 text-purple-700 rounded-xs">
                                            <Users className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <span className="text-[10px] text-slate-500 block uppercase">Station Staff</span>
                                            <span className="text-sm font-black text-slate-900">{selectedHub.handlers_count} handlers</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Full Service Coverage Barangays */}
                            <div className="pt-4 space-y-3 font-mono text-xs">
                                <div className="flex items-center justify-between">
                                    <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                        Service Coverage ({selectedHub.coverage_barangays?.length || 0} Barangays)
                                    </h4>
                                    <span className="text-[10px] text-slate-400">Dedicated Courier Delivery Zone</span>
                                </div>

                                {selectedHub.coverage_barangays && selectedHub.coverage_barangays.length > 5 && (
                                    <div className="relative">
                                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                                        <input
                                            type="text"
                                            value={coverageSearch}
                                            onChange={(e) => setCoverageSearch(e.target.value)}
                                            placeholder="Search barangay..."
                                            className="w-full pl-8 pr-3 py-1 bg-slate-50 border border-slate-200 rounded-xs text-[11px] font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42]"
                                        />
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-1.5 max-h-48 overflow-y-auto p-1 bg-slate-50 rounded-xs border border-slate-200">
                                    {selectedHub.coverage_barangays
                                        ?.filter((bg) => bg.toLowerCase().includes(coverageSearch.toLowerCase()))
                                        .map((bg, idx) => (
                                            <span
                                                key={idx}
                                                className="px-2 py-1 bg-white text-slate-800 text-[11px] font-medium rounded-xs border border-slate-200 shadow-2xs"
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
                            <div className="pt-4 space-y-2 font-mono text-xs">
                                <h4 className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                                    Workstation Shortcuts
                                </h4>
                                <div className="grid grid-cols-1 gap-2">
                                    <Link
                                        href={route('hub.scan.station')}
                                        className="p-2.5 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xs border border-slate-300 transition flex items-center justify-between"
                                    >
                                        <div className="flex items-center gap-2">
                                            <ScanLine className="w-4 h-4 text-emerald-600" />
                                            <span>Open Floor Barcode Scanner</span>
                                        </div>
                                        <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                                    </Link>
                                    <Link
                                        href={route('hub.deliveries')}
                                        className="p-2.5 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xs border border-slate-300 transition flex items-center justify-between"
                                    >
                                        <div className="flex items-center gap-2">
                                            <Package className="w-4 h-4 text-[#E00D42]" />
                                            <span>View Parcels & Waybills Registry</span>
                                        </div>
                                        <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
                                    </Link>
                                    {selectedHub.allows_self_pickup && (
                                        <Link
                                            href={route('hub.counter')}
                                            className="p-2.5 bg-slate-50 hover:bg-slate-100 text-slate-800 rounded-xs border border-slate-300 transition flex items-center justify-between"
                                        >
                                            <div className="flex items-center gap-2">
                                                <Store className="w-4 h-4 text-blue-600" />
                                                <span>Counter Self-Pickup Desk</span>
                                            </div>
                                            <ChevronRight className="w-3.5 h-3.5 text-slate-400" />
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

