import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    Package, 
    Search, 
    Filter, 
    Truck, 
    Store, 
    MapPin, 
    ChevronLeft, 
    ChevronRight, 
    ScanLine,
    ArrowUpRight,
    CheckCircle2,
    Clock,
    Tag
} from 'lucide-react';

interface DeliveryItem {
    id: number;
    tracking_number: string;
    order_number: string;
    buyer_name: string;
    buyer_phone: string;
    destination_barangay: string;
    shipping_city: string;
    status: string;
    delivery_type: string;
    destination_bin: string;
    current_hub: string;
    destination_hub: string;
    rider_name: string;
    total_amount: number;
    payment_method: string;
    item_count: number;
    created_at: string;
}

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    deliveries: {
        data: DeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    counts: {
        all: number;
        in_hub: number;
        out_for_delivery: number;
        ready_pickup: number;
        completed: number;
    };
    filters: {
        search: string;
        status: string;
        delivery_type: string;
    };
}

export default function HubDeliveries({ activeHub, deliveries, counts, filters }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');

    const handleFilter = (status?: string, deliveryType?: string, search?: string) => {
        router.get(
            route('hub.deliveries'),
            {
                status: status !== undefined ? status : filters.status,
                delivery_type: deliveryType !== undefined ? deliveryType : filters.delivery_type,
                search: search !== undefined ? search : searchTerm,
            },
            { preserveState: true, preserveScroll: true }
        );
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        handleFilter(undefined, undefined, searchTerm);
    };

    const getStatusPill = (status: string) => {
        if (status === 'delivered' || status === 'customer_collected') {
            return (
                <span className="px-2 py-0.5 text-[10px] font-bold rounded uppercase font-mono bg-emerald-50 text-emerald-700 border border-emerald-200">
                    {status.replace(/_/g, ' ')}
                </span>
            );
        }
        if (status === 'out_for_delivery') {
            return (
                <span className="px-2 py-0.5 text-[10px] font-bold rounded uppercase font-mono bg-amber-50 text-amber-700 border border-amber-200">
                    Out for Delivery
                </span>
            );
        }
        if (status.includes('ready_for_hub_pickup') || status.includes('arrived_at_destination_hub')) {
            return (
                <span className="px-2 py-0.5 text-[10px] font-bold rounded uppercase font-mono bg-blue-50 text-blue-700 border border-blue-200">
                    At Local Hub
                </span>
            );
        }
        return (
            <span className="px-2 py-0.5 text-[10px] font-bold rounded uppercase font-mono bg-slate-100 text-slate-700">
                {status.replace(/_/g, ' ')}
            </span>
        );
    };

    return (
        <DashboardLayout
            title="Parcels & Waybills"
            subtitle="Waybill tracking registry & delivery staging bins"
            actions={
                <div className="flex items-center gap-2">
                    <Link
                        href={route('hub.scan.station')}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold font-mono rounded-lg shadow-xs transition uppercase tracking-wider"
                    >
                        <ScanLine className="w-3.5 h-3.5" />
                        <span>Floor Scanner</span>
                    </Link>
                </div>
            }
        >
            <Head title="Parcels & Waybills — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP PARCEL KPI TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Total Tracked</span>
                                <Package className="w-4 h-4 text-blue-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {counts.all}{' '}
                                    <span className="text-sm font-bold text-slate-400">waybills</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Registry:</span>
                            <span className="font-bold text-slate-800">Station Active</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">In Sorting Hub</span>
                                <span className="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {counts.in_hub}{' '}
                                    <span className="text-sm font-bold text-slate-400">staged</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Dock Flow:</span>
                            <span className="font-bold text-blue-600">Pending Dispatch</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Out for Delivery</span>
                                <Truck className="w-4 h-4 text-amber-500" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {counts.out_for_delivery}{' '}
                                    <span className="text-sm font-bold text-slate-400">on route</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Last-Mile:</span>
                            <span className="font-bold text-amber-600">Active Transit</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Delivered / Collected</span>
                                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {counts.completed}{' '}
                                    <span className="text-sm font-bold text-slate-400">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Success Rate:</span>
                            <span className="font-bold text-emerald-600">100% Fulfilled</span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH AND FILTER CONTROLS */}
                <div className="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4">
                    <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-80">
                        <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            placeholder="Search tracking number or recipient..."
                            className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-blue-600/20 focus:border-blue-600 transition"
                        />
                    </form>

                    <div className="flex flex-wrap items-center gap-2 w-full sm:w-auto justify-end">
                        <div className="flex items-center gap-1.5 p-1 bg-slate-100 rounded-xl font-mono text-xs">
                            <button
                                type="button"
                                onClick={() => handleFilter('all')}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                    filters.status === 'all'
                                        ? 'bg-white text-slate-900 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                ALL ({counts.all})
                            </button>
                            <button
                                type="button"
                                onClick={() => handleFilter('in_hub')}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                    filters.status === 'in_hub'
                                        ? 'bg-white text-slate-900 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                IN HUB ({counts.in_hub})
                            </button>
                            <button
                                type="button"
                                onClick={() => handleFilter('out_for_delivery')}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                    filters.status === 'out_for_delivery'
                                        ? 'bg-white text-slate-900 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                OUT FOR DELIVERY ({counts.out_for_delivery})
                            </button>
                            <button
                                type="button"
                                onClick={() => handleFilter('ready_pickup')}
                                className={`px-3 py-1.5 rounded-lg text-[11px] font-bold transition cursor-pointer ${
                                    filters.status === 'ready_pickup'
                                        ? 'bg-white text-slate-900 shadow-2xs'
                                        : 'text-slate-500 hover:text-slate-800'
                                }`}
                            >
                                SELF-PICKUP ({counts.ready_pickup})
                            </button>
                        </div>

                        <select
                            value={filters.delivery_type}
                            onChange={(e) => handleFilter(undefined, e.target.value)}
                            className="bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 text-xs font-mono font-bold focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-blue-600/20"
                        >
                            <option value="all">All Types</option>
                            <option value="doorstep">Doorstep Delivery</option>
                            <option value="hub_self_pickup">Hub Counter Pickup</option>
                        </select>
                    </div>
                </div>

                {/* 3. WAYBILLS TABLE */}
                <div className="bg-white rounded-2xl border border-slate-200/90 shadow-2xs overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left border-collapse">
                            <thead>
                                <tr className="border-b border-slate-100 bg-slate-50/75 text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                                    <th className="py-3 px-4">Tracking Number</th>
                                    <th className="py-3 px-4">Recipient / Destination</th>
                                    <th className="py-3 px-4">Delivery Mode</th>
                                    <th className="py-3 px-4">Sorting Bin</th>
                                    <th className="py-3 px-4">Courier / Rider</th>
                                    <th className="py-3 px-4">Order Value</th>
                                    <th className="py-3 px-4 text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 text-xs">
                                {deliveries.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="py-12 text-center text-slate-400 font-mono">
                                            No waybills found matching current filter criteria.
                                        </td>
                                    </tr>
                                ) : (
                                    deliveries.data.map((delivery) => (
                                        <tr key={delivery.id} className="hover:bg-slate-50/60 transition">
                                            <td className="py-3.5 px-4">
                                                <span className="font-mono font-bold text-slate-900 block text-xs">
                                                    {delivery.tracking_number}
                                                </span>
                                                <span className="text-[10px] text-slate-400 font-mono block mt-0.5">
                                                    Order: {delivery.order_number}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4">
                                                <span className="font-bold text-slate-800 block">
                                                    {delivery.buyer_name}
                                                </span>
                                                <span className="text-[11px] text-slate-500 flex items-center gap-1 mt-0.5">
                                                    <MapPin className="w-3 h-3 text-slate-400 shrink-0" />
                                                    {delivery.destination_barangay}, {delivery.shipping_city}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4">
                                                <span className={`inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded font-mono ${
                                                    delivery.delivery_type === 'hub_self_pickup'
                                                        ? 'bg-blue-50 text-blue-700 border border-blue-200'
                                                        : 'bg-slate-100 text-slate-700'
                                                }`}>
                                                    {delivery.delivery_type === 'hub_self_pickup' ? (
                                                        <>
                                                            <Store className="w-3 h-3" /> Counter Pickup
                                                        </>
                                                    ) : (
                                                        <>
                                                            <Truck className="w-3 h-3" /> Doorstep
                                                        </>
                                                    )}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4">
                                                <span className="font-mono font-bold text-slate-700 px-2 py-0.5 rounded bg-slate-100 border border-slate-200 text-[10px] inline-block">
                                                    {delivery.destination_bin}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4 text-slate-700 font-medium">
                                                {delivery.rider_name}
                                            </td>

                                            <td className="py-3.5 px-4 font-mono">
                                                <span className="font-bold text-slate-900 block">
                                                    ₱{delivery.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                                <span className="text-[10px] text-slate-400 uppercase">
                                                    {delivery.payment_method}
                                                </span>
                                            </td>

                                            <td className="py-3.5 px-4 text-right">
                                                {getStatusPill(delivery.status)}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {deliveries.last_page > 1 && (
                        <div className="p-4 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                            <span className="text-slate-500">
                                Page {deliveries.current_page} of {deliveries.last_page} ({deliveries.total} total)
                            </span>
                            <div className="flex items-center gap-1">
                                {deliveries.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url || '#'}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`px-3 py-1 rounded-md text-xs font-bold transition ${
                                            link.active
                                                ? 'bg-blue-600 text-white'
                                                : link.url
                                                ? 'bg-slate-50 text-slate-700 hover:bg-slate-100 border border-slate-200'
                                                : 'text-slate-300 pointer-events-none'
                                        }`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>

            </div>
        </DashboardLayout>
    );
}
