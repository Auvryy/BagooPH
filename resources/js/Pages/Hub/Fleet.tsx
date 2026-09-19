import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    Truck, 
    Bike, 
    ShieldCheck, 
    Search, 
    CheckCircle2, 
    Clock, 
    AlertCircle,
    User,
    Phone,
    Layers,
    Filter
} from 'lucide-react';

interface FleetVehicle {
    id: number;
    plate_number: string;
    vehicle_type: string;
    model: string;
    capacity_kg: number;
    status: string;
    hub_name: string;
    hub_code: string;
    driver_name: string;
    driver_phone: string;
    driver_email: string;
}

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    hubs: any[];
    fleet: FleetVehicle[];
    stats: {
        total: number;
        active: number;
        motorcycles: number;
        vans: number;
        trucks: number;
    };
    filters: {
        tier: string;
        status: string;
    };
}

export default function HubFleet({ activeHub, fleet, stats, filters }: Props) {
    const handleFilter = (tier?: string, status?: string) => {
        router.get(
            route('hub.fleet'),
            {
                tier: tier !== undefined ? tier : filters.tier,
                status: status !== undefined ? status : filters.status,
            },
            { preserveState: true, preserveScroll: true }
        );
    };

    const getTierIcon = (type: string) => {
        if (type === 'motorcycle') return <Bike className="w-4 h-4 text-emerald-600" />;
        if (type === 'wing_truck') return <Truck className="w-4 h-4 text-purple-600" />;
        return <Truck className="w-4 h-4 text-blue-600" />;
    };

    const getTierBadge = (type: string) => {
        if (type === 'motorcycle') {
            return (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-xs bg-emerald-50 text-emerald-700 border border-emerald-300 uppercase font-mono">
                    Tier 1 • Last-Mile
                </span>
            );
        }
        if (type === 'l300_van') {
            return (
                <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-xs bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] uppercase font-mono">
                    Tier 2 • Feeder Van
                </span>
            );
        }
        return (
            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-xs bg-slate-100 text-slate-800 border border-slate-300 uppercase font-mono">
                Tier 3 • Wing Truck
            </span>
        );
    };

    return (
        <DashboardLayout
            title="Fleet Management"
            subtitle="Multi-tier dispatch vehicles & assigned drivers"
            actions={
                <Link
                    href={route('hub.deliveries')}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-mono rounded-xs shadow-xs transition uppercase tracking-wider"
                >
                    <Truck className="w-3.5 h-3.5" />
                    <span>View Waybills</span>
                </Link>
            }
        >
            <Head title="Fleet Management — BagooPH" />

            <div className="space-y-5 font-sans">
                
                {/* 1. TOP BUSINESS KPI TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Total Fleet</span>
                                <Truck className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.total}{' '}
                                    <span className="text-xs font-bold text-slate-400">vehicles</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Active Duty:</span>
                            <span className="font-bold text-emerald-700">{stats.active} units</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Last-Mile Couriers</span>
                                <Bike className="w-3.5 h-3.5 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.motorcycles}{' '}
                                    <span className="text-xs font-bold text-slate-400">bikes</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Coverage:</span>
                            <span className="font-bold text-slate-800">Barangay Streets</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Bayan Feeder Vans</span>
                                <Truck className="w-3.5 h-3.5 text-[#E00D42]" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.vans}{' '}
                                    <span className="text-xs font-bold text-slate-400">L300s</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Capacity:</span>
                            <span className="font-bold text-slate-800">1,200 kg / shuttle</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Wing Trucks (Trunk)</span>
                                <Truck className="w-3.5 h-3.5 text-slate-800" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.trucks}{' '}
                                    <span className="text-xs font-bold text-slate-400">heavy</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Transfer Route:</span>
                            <span className="font-bold text-slate-800">Inter-Mother Hub</span>
                        </div>
                    </div>
                </div>

                {/* 2. FILTER TABS */}
                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3 font-mono">
                    <div className="flex flex-wrap items-center gap-1 p-0.5 bg-slate-100 rounded-xs border border-slate-200 text-xs w-full sm:w-auto">
                        <button
                            type="button"
                            onClick={() => handleFilter('all')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filters.tier === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            ALL TIERS ({stats.total})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('motorcycle')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filters.tier === 'motorcycle'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            MOTORCYCLES ({stats.motorcycles})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('l300_van')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filters.tier === 'l300_van'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            FEEDER VANS ({stats.vans})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('wing_truck')}
                            className={`px-2.5 py-1 rounded-xs text-[10px] font-bold transition cursor-pointer ${
                                filters.tier === 'wing_truck'
                                    ? 'bg-white text-slate-900 shadow-2xs border border-slate-300'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            WING TRUCKS ({stats.trucks})
                        </button>
                    </div>

                    <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                        <span className="text-xs text-slate-500">Status:</span>
                        <select
                            value={filters.status}
                            onChange={(e) => handleFilter(undefined, e.target.value)}
                            className="bg-white border border-slate-300 rounded-xs px-2.5 py-1.5 text-xs font-mono font-bold focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                        >
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="idle">Idle / Available</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>

                {/* 3. FLEET TABLE */}
                <div className="bg-white rounded-xs border border-slate-300 shadow-xs overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50/90 text-[10px] font-bold uppercase tracking-wider text-slate-500 font-mono">
                                    <th className="py-2.5 px-3.5">Vehicle / Model</th>
                                    <th className="py-2.5 px-3.5">Tier Classification</th>
                                    <th className="py-2.5 px-3.5">Payload Capacity</th>
                                    <th className="py-2.5 px-3.5">Assigned Driver</th>
                                    <th className="py-2.5 px-3.5">Home Facility</th>
                                    <th className="py-2.5 px-3.5 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 text-xs">
                                {fleet.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-10 text-center text-slate-400 font-mono">
                                            No vehicles found matching current filter.
                                        </td>
                                    </tr>
                                ) : (
                                    fleet.map((vehicle) => (
                                        <tr key={vehicle.id} className="hover:bg-slate-50/80 transition">
                                            <td className="py-3 px-3.5">
                                                <div className="flex items-center gap-2.5">
                                                    <div className="w-8 h-8 rounded-xs bg-slate-100 flex items-center justify-center shrink-0 border border-slate-200">
                                                        {getTierIcon(vehicle.vehicle_type)}
                                                    </div>
                                                    <div>
                                                        <span className="font-mono font-bold text-slate-900 block text-xs">
                                                            {vehicle.plate_number}
                                                        </span>
                                                        <span className="text-[11px] text-slate-500 truncate block max-w-xs font-sans">
                                                            {vehicle.model}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="py-3 px-3.5">
                                                {getTierBadge(vehicle.vehicle_type)}
                                            </td>

                                            <td className="py-3 px-3.5 font-mono font-bold text-slate-800">
                                                {vehicle.capacity_kg.toLocaleString()} kg
                                            </td>

                                            <td className="py-3 px-3.5">
                                                <div className="flex items-center gap-1.5">
                                                    <User className="w-3.5 h-3.5 text-slate-400" />
                                                    <span className="font-medium text-slate-800">{vehicle.driver_name}</span>
                                                </div>
                                                {vehicle.driver_phone !== 'N/A' && (
                                                    <span className="text-[10px] text-slate-400 font-mono block mt-0.5">
                                                        {vehicle.driver_phone}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="py-3 px-3.5">
                                                <span className="font-mono font-bold text-[#E00D42] block text-xs">
                                                    {vehicle.hub_code}
                                                </span>
                                                <span className="text-[11px] text-slate-500">
                                                    {vehicle.hub_name}
                                                </span>
                                            </td>

                                            <td className="py-3 px-3.5 text-right">
                                                <span className={`inline-block px-1.5 py-0.5 text-[10px] font-bold rounded-xs uppercase font-mono ${
                                                    vehicle.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 border border-emerald-300'
                                                        : vehicle.status === 'maintenance'
                                                        ? 'bg-rose-50 text-rose-700 border border-rose-300'
                                                        : 'bg-slate-100 text-slate-600 border border-slate-200'
                                                }`}>
                                                    {vehicle.status}
                                                </span>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </DashboardLayout>
    );
}
