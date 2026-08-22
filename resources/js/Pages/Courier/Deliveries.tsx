import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Delivery } from '@/types';
import { 
    Truck, 
    Package, 
    MapPin, 
    Phone, 
    CheckCircle2, 
    Clock, 
    ArrowRight, 
    ShieldCheck, 
    Check, 
    AlertCircle,
    Store
} from 'lucide-react';

interface Props {
    myDeliveries: Delivery[];
    availableJobs: Delivery[];
    stats: {
        active: number;
        completed: number;
        available: number;
    };
}

export default function CourierDeliveries({ myDeliveries, availableJobs, stats }: Props) {
    const [activeTab, setActiveTab] = useState<'my' | 'pool'>('my');
    const [selectedDelivery, setSelectedDelivery] = useState<Delivery | null>(null);
    const [statusUpdate, setStatusUpdate] = useState<string>('');
    const [notes, setNotes] = useState<string>('');

    const claimDelivery = (deliveryId: number) => {
        router.post(route('courier.claim', deliveryId));
    };

    const handleUpdateStatus = (delivery: Delivery, newStatus: string) => {
        router.patch(route('courier.updateStatus', delivery.id), {
            status: newStatus,
            courier_notes: notes || undefined,
        }, {
            onSuccess: () => {
                setSelectedDelivery(null);
                setNotes('');
            }
        });
    };

    return (
        <DashboardLayout
            title="Courier & Logistics Dispatch Board"
            subtitle="Real-time delivery assignments and parcel routing"
        >
            <Head title="Courier Deliveries — Bagoo Logistics" />

            <div className="space-y-8">
                {/* Stats row */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
                            <Truck className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">My Active Tasks</p>
                            <h3 className="text-2xl font-black text-slate-900">{stats.active}</h3>
                            <span className="text-[11px] text-amber-600 font-bold">In transit & out for delivery</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
                            <CheckCircle2 className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">Completed Deliveries</p>
                            <h3 className="text-2xl font-black text-slate-900">{stats.completed}</h3>
                            <span className="text-[11px] text-emerald-600 font-bold">Successfully dropped off</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-3xl p-6 border border-slate-200 shadow-xs flex items-center gap-5">
                        <div className="w-14 h-14 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                            <Package className="w-7 h-7" />
                        </div>
                        <div>
                            <p className="text-xs font-bold uppercase text-slate-400">Available Job Pool</p>
                            <h3 className="text-2xl font-black text-slate-900">{stats.available}</h3>
                            <span className="text-[11px] text-indigo-600 font-bold">Awaiting pickup claims</span>
                        </div>
                    </div>
                </div>

                {/* Tabs */}
                <div className="flex gap-2 border-b border-slate-200 pb-2">
                    <button
                        onClick={() => setActiveTab('my')}
                        className={`px-5 py-2.5 rounded-2xl text-xs font-bold transition flex items-center gap-2 ${
                            activeTab === 'my'
                                ? 'bg-amber-600 text-white shadow-md shadow-amber-600/20'
                                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'
                        }`}
                    >
                        <Truck className="w-4 h-4" />
                        <span>My Assigned Deliveries ({myDeliveries.length})</span>
                    </button>
                    <button
                        onClick={() => setActiveTab('pool')}
                        className={`px-5 py-2.5 rounded-2xl text-xs font-bold transition flex items-center gap-2 ${
                            activeTab === 'pool'
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/20'
                                : 'bg-white text-slate-700 hover:bg-slate-50 border border-slate-200'
                        }`}
                    >
                        <Package className="w-4 h-4" />
                        <span>Available Dispatch Jobs ({availableJobs.length})</span>
                    </button>
                </div>

                {/* Tab 1: My Deliveries */}
                {activeTab === 'my' && (
                    <div className="space-y-4">
                        {myDeliveries.length === 0 ? (
                            <div className="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-3">
                                <Truck className="w-12 h-12 text-slate-300 mx-auto" />
                                <h3 className="text-base font-bold text-slate-800">No active assigned deliveries</h3>
                                <p className="text-xs text-slate-500">Switch to the "Available Dispatch Jobs" tab to accept pickup assignments.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {myDeliveries.map((delivery) => (
                                    <div
                                        key={delivery.id}
                                        className="bg-white rounded-3xl border border-slate-200 p-6 space-y-5 shadow-xs flex flex-col justify-between"
                                    >
                                        <div className="space-y-4">
                                            {/* Header */}
                                            <div className="flex justify-between items-start">
                                                <div>
                                                    <span className="font-mono text-xs font-bold text-slate-900 block">
                                                        #{delivery.tracking_number}
                                                    </span>
                                                    <span className="text-[11px] text-slate-400">
                                                        Order #{delivery.order?.order_number}
                                                    </span>
                                                </div>
                                                <span className="px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold capitalize">
                                                    {delivery.status.replace('_', ' ')}
                                                </span>
                                            </div>

                                            {/* Pickup & Dropoff Nodes */}
                                            <div className="space-y-3 p-4 bg-slate-50 rounded-2xl text-xs">
                                                <div className="flex items-start gap-2.5">
                                                    <Store className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                                                    <div>
                                                        <p className="font-bold text-slate-800">Pickup: {delivery.pickup_store_name || 'Seller Hub'}</p>
                                                        <p className="text-slate-500">{delivery.pickup_address}</p>
                                                    </div>
                                                </div>
                                                <div className="border-l-2 border-dashed border-slate-300 ml-2 h-4 my-1"></div>
                                                <div className="flex items-start gap-2.5">
                                                    <MapPin className="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                                                    <div>
                                                        <p className="font-bold text-slate-800">Dropoff: {delivery.delivery_recipient_name}</p>
                                                        <p className="text-slate-500">{delivery.delivery_address}</p>
                                                        <p className="text-slate-500 text-[11px] flex items-center gap-1 mt-0.5">
                                                            <Phone className="w-3 h-3 text-slate-400" />
                                                            {delivery.delivery_phone}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Parcels summary */}
                                            <div className="text-xs text-slate-500">
                                                <span className="font-bold text-slate-700">Parcels: </span>
                                                {delivery.order?.items?.map(i => `${i.product?.name} (x${i.quantity})`).join(', ')}
                                            </div>
                                        </div>

                                        {/* Status Action Buttons */}
                                        <div className="pt-4 border-t border-slate-100 flex flex-wrap gap-2">
                                            {delivery.status === 'assigned' && (
                                                <button
                                                    onClick={() => handleUpdateStatus(delivery, 'picked_up')}
                                                    className="flex-1 py-2.5 px-4 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded-xl shadow-xs transition"
                                                >
                                                    Confirm Picked Up from Seller
                                                </button>
                                            )}

                                            {delivery.status === 'picked_up' && (
                                                <button
                                                    onClick={() => handleUpdateStatus(delivery, 'out_for_delivery')}
                                                    className="flex-1 py-2.5 px-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-xs transition"
                                                >
                                                    Set: Out for Delivery
                                                </button>
                                            )}

                                            {['picked_up', 'out_for_delivery', 'in_transit'].includes(delivery.status) && (
                                                <button
                                                    onClick={() => handleUpdateStatus(delivery, 'delivered')}
                                                    className="flex-1 py-2.5 px-4 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition"
                                                >
                                                    Mark Delivered to Customer
                                                </button>
                                            )}

                                            {delivery.status === 'delivered' && (
                                                <div className="w-full py-2 bg-emerald-50 text-emerald-700 text-center font-bold text-xs rounded-xl flex items-center justify-center gap-1.5">
                                                    <CheckCircle2 className="w-4 h-4" />
                                                    <span>Completed on {delivery.delivered_at ? new Date(delivery.delivered_at).toLocaleDateString() : 'Today'}</span>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Tab 2: Available Jobs Pool */}
                {activeTab === 'pool' && (
                    <div className="space-y-4">
                        {availableJobs.length === 0 ? (
                            <div className="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-3">
                                <Package className="w-12 h-12 text-slate-300 mx-auto" />
                                <h3 className="text-base font-bold text-slate-800">No unassigned jobs at the moment</h3>
                                <p className="text-xs text-slate-500">All current orders have been claimed by riders.</p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {availableJobs.map((delivery) => (
                                    <div
                                        key={delivery.id}
                                        className="bg-white rounded-3xl border border-slate-200 p-6 space-y-5 shadow-xs flex flex-col justify-between"
                                    >
                                        <div className="space-y-4">
                                            <div className="flex justify-between items-start">
                                                <div>
                                                    <span className="font-mono text-xs font-bold text-slate-900 block">
                                                        #{delivery.tracking_number}
                                                    </span>
                                                    <span className="text-[11px] text-slate-400 font-medium">
                                                        Order #{delivery.order?.order_number}
                                                    </span>
                                                </div>
                                                <span className="px-3 py-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full text-xs font-bold">
                                                    Available for Pickup
                                                </span>
                                            </div>

                                            <div className="space-y-3 p-4 bg-slate-50 rounded-2xl text-xs">
                                                <div className="flex items-start gap-2.5">
                                                    <Store className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                                                    <div>
                                                        <p className="font-bold text-slate-800">Pickup: {delivery.pickup_store_name || 'Seller Store'}</p>
                                                        <p className="text-slate-500">{delivery.pickup_address}</p>
                                                    </div>
                                                </div>
                                                <div className="flex items-start gap-2.5">
                                                    <MapPin className="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                                                    <div>
                                                        <p className="font-bold text-slate-800">Destination: {delivery.delivery_recipient_name}</p>
                                                        <p className="text-slate-500">{delivery.delivery_address}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <button
                                            onClick={() => claimDelivery(delivery.id)}
                                            className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-xl shadow-md shadow-indigo-600/20 transition flex items-center justify-center gap-2 active:scale-95"
                                        >
                                            <Truck className="w-4 h-4" />
                                            <span>Accept & Claim Delivery Task</span>
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
