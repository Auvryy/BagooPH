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
                <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase font-mono">
                    Tier 1 • Last-Mile
                </span>
            );
        }
        if (type === 'l300_van') {
            return (
                <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-blue-50 text-blue-700 border border-blue-200 uppercase font-mono">
                    Tier 2 • Feeder Van
                </span>
            );
        }
        return (
            <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-purple-50 text-purple-700 border border-purple-200 uppercase font-mono">
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
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold font-mono rounded-lg shadow-xs transition uppercase tracking-wider"
                >
                    <Truck className="w-3.5 h-3.5" />
                    <span>View Waybills</span>
                </Link>
            }
        >
            <Head title="Fleet Management — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP BUSINESS KPI TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Total Fleet</span>
                                <Truck className="w-4 h-4 text-blue-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.total}{' '}
                                    <span className="text-sm font-bold text-slate-400">vehicles</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Active Duty:</span>
                            <span className="font-bold text-emerald-600">{stats.active} units</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Last-Mile Couriers</span>
                                <Bike className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.motorcycles}{' '}
                                    <span className="text-sm font-bold text-slate-400">bikes</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Coverage:</span>
                            <span className="font-bold text-slate-800">Barangay Streets</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Bayan Feeder Vans</span>
                                <Truck className="w-4 h-4 text-blue-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.vans}{' '}
                                    <span className="text-sm font-bold text-slate-400">L300s</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Capacity:</span>
                            <span className="font-bold text-slate-800">1,200 kg / shuttle</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Wing Trucks (Trunk)</span>
                                <Truck className="w-4 h-4 text-purple-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.trucks}{' '}
                                    <span className="text-sm font-bold text-slate-400">heavy</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Transfer Route:</span>
                            <span className="font-bold text-slate-800">Inter-Mother Hub</span>
                        </div>
                    </div>
                </div>

                {/* 2. FILTER TABS */}
                <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4">
                    <div className="flex flex-wrap items-center gap-1.5 p-1 bg-slate-100 rounded-xl font-mono text-xs w-full sm:w-auto">
                        <button
                            type="button"
                            onClick={() => handleFilter('all')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filters.tier === 'all'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            ALL TIERS ({stats.total})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('motorcycle')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filters.tier === 'motorcycle'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            MOTORCYCLES ({stats.motorcycles})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('l300_van')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filters.tier === 'l300_van'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            FEEDER VANS ({stats.vans})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilter('wing_truck')}
                            className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                filters.tier === 'wing_truck'
                                    ? 'bg-white text-slate-900 shadow-2xs'
                                    : 'text-slate-500 hover:text-slate-800'
                            }`}
                        >
                            WING TRUCKS ({stats.trucks})
                        </button>
                    </div>

                    <div className="flex items-center gap-2 w-full sm:w-auto justify-end">
                        <span className="text-xs font-mono text-slate-400">Status:</span>
                        <select
                            value={filters.status}
                            onChange={(e) => handleFilter(undefined, e.target.value)}
                            className="bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-mono font-bold focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-blue-600/20"
                        >
                            <option value="all">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="idle">Idle / Available</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>

                {/* 3. FLEET TABLE */}
                <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50/75 text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                                    <th className="py-3 px-4">Vehicle / Model</th>
                                    <th className="py-3 px-4">Tier Classification</th>
                                    <th className="py-3 px-4">Payload Capacity</th>
                                    <th className="py-3 px-4">Assigned Driver</th>
                                    <th className="py-3 px-4">Home Facility</th>
                                    <th className="py-3 px-4 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-xs">
                                {fleet.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-12 text-center text-slate-400 font-mono">
                                            No vehicles found matching current filter.
                                        </td>
                                    </tr>
                                ) : (
                                    fleet.map((vehicle) => (
                                        <tr key={vehicle.id} className="hover:bg-slate-50/60 transition">
                                            <td className="py-3.5 px-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="w-9 h-9 rounded-xl bg-slate-100 flex items-center justify-center shrink-0">
                                                        {getTierIcon(vehicle.vehicle_type)}
                                                    </div>
                                                    <div>
                                                        <span className="font-mono font-bold text-slate-900 block text-xs">
                                                            {vehicle.plate_number}
                                                        </span>
                                                        <span className="text-[11px] text-slate-500 truncate block max-w-xs">
                                                            {vehicle.model}
                                                        </span>
                                                    </div>
                                                </div>
                                            </td>

                                            <td className="py-3.5 px-4">
                                                {getTierBadge(vehicle.vehicle_type)}
                                            </td>

                                            <td className="py-3.5 px-4 font-mono font-bold text-slate-800">
                                                {vehicle.capacity_kg.toLocaleString()} kg
                                            </td>

                                            <td className="py-3.5 px-4">
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

                                            <td className="py-3.5 px-4">
                                                <span className="font-mono font-bold text-blue-600 block text-xs">
                                                    {vehicle.hub_code}
                                                </span>
                                                <span className="text-[11px] text-slate-500">
                                                    {vehicle.hub_name}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4 text-right">
                                                <span className={`inline-block px-2 py-0.5 text-[10px] font-bold rounded-md uppercase font-mono ${
                                                    vehicle.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                        : vehicle.status === 'maintenance'
                                                        ? 'bg-rose-50 text-rose-700 border border-rose-200'
                                                        : 'bg-slate-100 text-slate-600'
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
