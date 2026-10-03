import React, { useState } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Delivery } from '@/types';
import { 
    Truck, 
    Package, 
    Search, 
    Filter, 
    CheckCircle2, 
    Clock, 
    MapPin, 
    User, 
    Building2, 
    TrendingUp, 
    RotateCcw, 
    DollarSign, 
    AlertTriangle, 
    RefreshCw 
} from 'lucide-react';

interface CourierRider {
    id: number;
    name: string;
    email: string;
    phone: string;
    active_jobs: number;
    completed_jobs: number;
    status: string;
}

interface Props {
    deliveries: PaginatedData<Delivery>;
    couriers: CourierRider[];
    filters: {
        search?: string;
        status?: string;
    };
    stats: {
        total: number;
        inTransit: number;
        unassigned: number;
        delivered: number;
        totalShippingRevenue: number;
        courierPayouts: number;
        hubFee: number;
        activeFleetCount: number;
    };
}

export default function AdminLogistics({ deliveries, couriers, filters, stats }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all');
    const handleFilterSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('admin.logistics'), {
            search: searchTerm || undefined,
            status: statusFilter === 'all' ? undefined : statusFilter,
        }, { preserveState: true });
    };

    const formatPrice = (val: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(val);
    };

    return (
        <DashboardLayout
            title="Central Logistics Sorting Hub"
            subtitle="Platform-wide parcel telemetry, fleet dispatch supervision, and revenue split ledger"
        >
            <Head title="Logistics Sorting Hub — Platform Admin" />

            <div className="mb-5 rounded-lg border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
                Parcel custody changes must be recorded by assigned riders and logistics operators.
                Platform corrections are currently unavailable.
            </div>

            <div className="space-y-6 font-sans">
                
                {/* 1. LOGISTICS FACILITY METRICS */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 font-sans text-xs">
                    
                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold shrink-0">
                            <Truck className="w-6 h-6 animate-pulse" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">In-Transit Parcels</span>
                            <h3 className="text-2xl font-black text-slate-900">{stats.inTransit}</h3>
                            <p className="text-[10px] text-amber-600 font-bold">Active in sorting/delivery</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold shrink-0">
                            <Package className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Unassigned Broadcasts</span>
                            <h3 className="text-2xl font-black text-[#E00D42]">{stats.unassigned}</h3>
                            <p className="text-[10px] text-slate-500">Awaiting rider claim (FCFS)</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold shrink-0">
                            <CheckCircle2 className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Delivered Packages</span>
                            <h3 className="text-2xl font-black text-emerald-600">{stats.delivered}</h3>
                            <p className="text-[10px] text-slate-500">100% Successful Drop-offs</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold shrink-0">
                            <Building2 className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Hub Facility Share</span>
                            <h3 className="text-2xl font-black text-indigo-600">{formatPrice(stats.hubFee)}</h3>
                            <p className="text-[10px] text-slate-500">20% Sorting Hub fee split</p>
                        </div>
                    </div>

                </div>

                {/* 2. FLEET ROSTER STRIP */}
                <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs space-y-3 font-sans text-xs">
                    <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                        <h4 className="font-bold text-slate-900 uppercase">Active Bagoo Express Fleet ({couriers.length} Drivers)</h4>
                        <span className="text-emerald-600 font-bold">● All Systems Nominal</span>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
                        {couriers.map((courier) => (
                            <div key={courier.id} className="p-3 bg-slate-50 rounded-xl border border-slate-200/80 space-y-1">
                                <div className="flex items-center justify-between font-bold">
                                    <span className="text-slate-900 truncate">{courier.name}</span>
                                    <span className="text-[9px] px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800">
                                        {courier.status}
                                    </span>
                                </div>
                                <p className="text-[10px] text-slate-500">{courier.phone}</p>
                                <div className="flex items-center justify-between pt-1 border-t border-slate-200/60 text-[10px] text-slate-600">
                                    <span>Active: <strong>{courier.active_jobs}</strong></span>
                                    <span>Done: <strong>{courier.completed_jobs}</strong></span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                {/* 3. PARCEL DISPATCH TABLE */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                    
                    {/* Search & Filter Form */}
                    <form onSubmit={handleFilterSubmit} className="flex flex-col sm:flex-row items-center justify-between gap-3 pb-4 border-b border-slate-100 font-sans text-xs">
                        <div className="relative w-full sm:w-80">
                            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="Search Tracking #, Order #, Recipient..."
                                className="w-full pl-9 pr-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                            />
                        </div>

                        <div className="flex items-center gap-2 w-full sm:w-auto">
                            <select
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="rounded-xl bg-slate-50 border border-slate-200 text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                            >
                                <option value="all">All Statuses</option>
                                <option value="unassigned">Unassigned</option>
                                <option value="assigned">Assigned</option>
                                <option value="picked_up">Picked Up</option>
                                <option value="in_transit">In Transit</option>
                                <option value="out_for_delivery">Out for Delivery</option>
                                <option value="delivered">Delivered</option>
                            </select>

                            <button
                                type="submit"
                                className="px-4 py-2 bg-slate-900 text-white font-bold rounded-xl uppercase hover:bg-black transition"
                            >
                                Filter
                            </button>
                        </div>
                    </form>

                    {/* Table */}
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs font-sans">
                            <thead>
                                <tr className="border-b border-slate-200 font-sans text-[10px] text-slate-400 uppercase">
                                    <th className="pb-3 pr-4">Tracking #</th>
                                    <th className="pb-3 px-4">Merchant Origin</th>
                                    <th className="pb-3 px-4">Recipient Destination</th>
                                    <th className="pb-3 px-4">Assigned Driver</th>
                                    <th className="pb-3 px-4">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 font-sans">
                                {deliveries.data.map((del) => (
                                    <tr key={del.id} className="hover:bg-slate-50/60 transition">
                                        <td className="py-3.5 pr-4">
                                            <span className="font-bold text-slate-900">#{del.tracking_number}</span>
                                            <span className="block text-[10px] text-slate-400">Order #{del.order?.order_number}</span>
                                        </td>
                                        <td className="py-3.5 px-4 font-sans">
                                            <span className="font-bold text-slate-800">{del.pickup_store_name || 'Bagoo Merchant'}</span>
                                        </td>
                                        <td className="py-3.5 px-4 font-sans">
                                            <span className="font-bold text-slate-800">{del.delivery_recipient_name}</span>
                                            <span className="block text-[10px] text-slate-500 font-sans truncate max-w-xs">{del.delivery_address}</span>
                                        </td>
                                        <td className="py-3.5 px-4">
                                            {del.courier ? (
                                                <span className="font-bold text-slate-900">{del.courier.name}</span>
                                            ) : (
                                                <span className="text-[10px] font-bold text-rose-500 bg-rose-50 px-2 py-0.5 rounded">
                                                    UNASSIGNED
                                                </span>
                                            )}
                                        </td>
                                        <td className="py-3.5 px-4">
                                            <span className={`px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase ${
                                                del.status === 'delivered' 
                                                    ? 'bg-emerald-100 text-emerald-800' 
                                                    : 'bg-amber-100 text-amber-800'
                                            }`}>
                                                {del.status.replace('_', ' ')}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </DashboardLayout>
    );
}
