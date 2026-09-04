import React, { useState } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import CourierLayout from '@/Layouts/CourierLayout';
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
    Store,
    DollarSign,
    Navigation,
    Camera,
    MessageSquare,
    ChevronRight,
    Sparkles,
    Building2,
    Calendar
} from 'lucide-react';

interface Props {
    myDeliveries: Delivery[];
    availableJobs: Delivery[];
    isOnline: boolean;
    stats: {
        active: number;
        completed: number;
        available: number;
        todayEarnings: number;
        codOnHand: number;
    };
}

export default function CourierDeliveries({ myDeliveries, availableJobs, isOnline, stats }: Props) {
    const [activeTab, setActiveTab] = useState<'my' | 'pool'>('my');
    const [selectedDelivery, setSelectedDelivery] = useState<Delivery | null>(null);
    const [statusModalOpen, setStatusModalOpen] = useState(false);
    const [nextStatus, setNextStatus] = useState<string>('');
    const [courierNotes, setCourierNotes] = useState<string>('');
    const [proofImage, setProofImage] = useState<string>('https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?w=500&auto=format&fit=crop&q=60');
    const [actionLoading, setActionLoading] = useState(false);

    const claimDelivery = (deliveryId: number) => {
        setActionLoading(true);
        router.post(route('courier.claim', deliveryId), {}, {
            preserveScroll: true,
            onFinish: () => setActionLoading(false),
        });
    };

    const openStatusModal = (delivery: Delivery, targetStatus: string) => {
        setSelectedDelivery(delivery);
        setNextStatus(targetStatus);
        setCourierNotes('');
        setStatusModalOpen(true);
    };

    const submitStatusUpdate = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedDelivery || !nextStatus) return;

        setActionLoading(true);
        router.patch(route('courier.updateStatus', selectedDelivery.id), {
            status: nextStatus,
            courier_notes: courierNotes || undefined,
            proof_image: nextStatus === 'delivered' ? proofImage : undefined,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setStatusModalOpen(false);
                setSelectedDelivery(null);
                setActionLoading(false);
            },
            onError: () => setActionLoading(false),
        });
    };

    const formatPrice = (val?: number | string | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(num);
    };

    return (
        <CourierLayout
            title="Express Dispatch & Field Operations"
            subtitle="First-come, first-served delivery broadcasts and live route execution"
            isOnline={isOnline}
        >
            <Head title="Courier Dispatch Hub — Bagoo Express" />

            <div className="space-y-6 font-sans">
                
                {/* 1. RIDER TELEMETRY COCKPIT METRICS */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 font-mono">
                    
                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold shrink-0">
                            <Navigation className="w-6 h-6 animate-pulse" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Active Route Tasks</span>
                            <h3 className="text-2xl font-black text-slate-900">{stats.active}</h3>
                            <p className="text-[10px] text-amber-600 font-bold">In transit & out for delivery</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold shrink-0">
                            <CheckCircle2 className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Completed Trips</span>
                            <h3 className="text-2xl font-black text-slate-900">{stats.completed}</h3>
                            <p className="text-[10px] text-emerald-600 font-bold">100% On-Time Succeeded</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold shrink-0">
                            <DollarSign className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">Trip Earnings</span>
                            <h3 className="text-2xl font-black text-indigo-600">{formatPrice(stats.todayEarnings)}</h3>
                            <p className="text-[10px] text-slate-500 font-sans">₱60.00 / drop-off rate</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs flex items-center gap-4">
                        <div className="w-12 h-12 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold shrink-0">
                            <Store className="w-6 h-6" />
                        </div>
                        <div>
                            <span className="text-[10px] text-slate-400 uppercase font-bold">COD Cash on Hand</span>
                            <h3 className="text-2xl font-black text-[#E00D42]">{formatPrice(stats.codOnHand)}</h3>
                            <p className="text-[10px] text-rose-600 font-bold">Pending Remittance</p>
                        </div>
                    </div>

                </div>

                {/* 2. TAB TOGGLE: ACTIVE ROUTE VS AVAILABLE JOBS BROADCAST */}
                <div className="bg-white rounded-2xl p-2 border border-slate-200 shadow-xs flex items-center justify-between font-mono text-xs">
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setActiveTab('my')}
                            className={`px-4 py-2 rounded-xl font-bold uppercase transition flex items-center gap-2 ${
                                activeTab === 'my'
                                    ? 'bg-slate-900 text-white shadow-xs'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            <Truck className="w-4 h-4 text-amber-400" />
                            <span>My Active Route ({myDeliveries.filter(d => d.status !== 'delivered').length})</span>
                        </button>

                        <button
                            onClick={() => setActiveTab('pool')}
                            className={`px-4 py-2 rounded-xl font-bold uppercase transition flex items-center gap-2 ${
                                activeTab === 'pool'
                                    ? 'bg-[#E00D42] text-white shadow-xs'
                                    : 'text-slate-600 hover:bg-slate-100'
                            }`}
                        >
                            <Sparkles className="w-4 h-4" />
                            <span>Available Jobs Board ({availableJobs.length})</span>
                        </button>
                    </div>

                    <span className="hidden sm:inline text-[11px] text-slate-500 pr-3">
                        {activeTab === 'pool' ? '⚡ Broadcasted in real-time (First-Come, First-Served)' : '📍 Follow standard 4-step dispatch workflow'}
                    </span>
                </div>

                {/* 3. TAB A: MY ACTIVE ROUTE */}
                {activeTab === 'my' && (
                    <div className="space-y-4">
                        {myDeliveries.length === 0 ? (
                            <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                                <Package className="w-12 h-12 text-slate-300 mx-auto" />
                                <h3 className="text-base font-bold text-slate-800">No active assigned deliveries</h3>
                                <p className="text-xs text-slate-500 font-mono">
                                    Switch to the "Available Jobs Board" tab to claim ready packages from nearby Bagoo Mall merchants.
                                </p>
                                <button
                                    onClick={() => setActiveTab('pool')}
                                    className="px-4 py-2 bg-[#E00D42] text-white rounded-xl text-xs font-mono font-bold uppercase"
                                >
                                    Browse Available Jobs ({availableJobs.length})
                                </button>
                            </div>
                        ) : (
                            myDeliveries.map((delivery) => {
                                const isDelivered = delivery.status === 'delivered';
                                return (
                                    <div
                                        key={delivery.id}
                                        className={`bg-white rounded-2xl p-6 border transition space-y-5 ${
                                            isDelivered ? 'border-slate-200 opacity-80' : 'border-slate-300 shadow-sm'
                                        }`}
                                    >
                                        {/* Header Bar */}
                                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100 font-mono text-xs">
                                            <div className="flex items-center gap-3">
                                                <span className="font-bold text-slate-900 text-sm">#{delivery.tracking_number}</span>
                                                <span className="text-slate-400">•</span>
                                                <span className="text-slate-600">Order #{delivery.order?.order_number}</span>
                                                <span className="text-slate-400">•</span>
                                                <span className="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-bold uppercase">
                                                    {delivery.order?.payment_method?.toUpperCase() || 'COD'}
                                                </span>
                                            </div>

                                            <span className={`px-3 py-1 rounded-full font-bold text-[11px] w-fit flex items-center gap-1.5 ${
                                                isDelivered 
                                                    ? 'bg-emerald-100 text-emerald-900' 
                                                    : 'bg-amber-100 text-amber-900'
                                            }`}>
                                                {isDelivered ? <Check className="w-3.5 h-3.5" /> : <Clock className="w-3.5 h-3.5" />}
                                                {delivery.status.replace('_', ' ').toUpperCase()}
                                            </span>
                                        </div>

                                        {/* Route Origin & Destination Matrix */}
                                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6 items-start font-sans">
                                            
                                            {/* Pickup Store (Origin) */}
                                            <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                                                <div className="flex items-center justify-between font-mono text-xs">
                                                    <span className="text-[10px] text-amber-600 font-bold uppercase flex items-center gap-1">
                                                        <Store className="w-3.5 h-3.5" /> 1. Merchant Store Pickup
                                                    </span>
                                                    <span className="text-slate-400 text-[10px]">Origin</span>
                                                </div>
                                                <h4 className="font-bold text-slate-900 text-sm">{delivery.pickup_store_name || 'Bagoo Merchant Flagship'}</h4>
                                                <p className="text-xs text-slate-600 font-mono flex items-start gap-1.5">
                                                    <MapPin className="w-3.5 h-3.5 text-slate-400 shrink-0 mt-0.5" />
                                                    <span>{delivery.pickup_address}</span>
                                                </p>
                                                {delivery.pickup_phone && (
                                                    <p className="text-xs text-slate-500 font-mono flex items-center gap-1.5">
                                                        <Phone className="w-3.5 h-3.5 text-slate-400" />
                                                        <span>{delivery.pickup_phone}</span>
                                                    </p>
                                                )}
                                            </div>

                                            {/* Delivery Destination */}
                                            <div className="p-4 rounded-xl bg-rose-50/40 border border-rose-200/60 space-y-2">
                                                <div className="flex items-center justify-between font-mono text-xs">
                                                    <span className="text-[10px] text-[#E00D42] font-bold uppercase flex items-center gap-1">
                                                        <MapPin className="w-3.5 h-3.5" /> 2. Buyer Doorstep Drop-off
                                                    </span>
                                                    <span className="text-[#E00D42] font-bold">
                                                        COD: {delivery.order ? formatPrice(delivery.order.total_amount) : '₱0.00'}
                                                    </span>
                                                </div>
                                                <h4 className="font-bold text-slate-900 text-sm">{delivery.delivery_recipient_name}</h4>
                                                <p className="text-xs text-slate-600 font-mono flex items-start gap-1.5">
                                                    <MapPin className="w-3.5 h-3.5 text-[#E00D42] shrink-0 mt-0.5" />
                                                    <span>{delivery.delivery_address}</span>
                                                </p>
                                                <p className="text-xs text-slate-500 font-mono flex items-center gap-1.5">
                                                    <Phone className="w-3.5 h-3.5 text-slate-400" />
                                                    <span>{delivery.delivery_phone}</span>
                                                </p>
                                            </div>

                                        </div>

                                        {/* Action Progression Controls */}
                                        <div className="pt-3 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-mono text-xs">
                                            <div className="flex items-center gap-2">
                                                <Link
                                                    href={route('courier.messages')}
                                                    className="px-3 py-1.5 rounded-xl border border-slate-200 text-slate-700 hover:bg-slate-50 font-bold flex items-center gap-1.5"
                                                >
                                                    <MessageSquare className="w-3.5 h-3.5 text-slate-400" />
                                                    <span>Chat Merchant / Buyer</span>
                                                </Link>
                                            </div>

                                            {/* Stage Transitions */}
                                            <div className="flex items-center gap-2">
                                                {delivery.status === 'assigned' && (
                                                    <button
                                                        onClick={() => openStatusModal(delivery, 'picked_up')}
                                                        className="px-4 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                    >
                                                        <Store className="w-3.5 h-3.5" />
                                                        <span>Confirm Item Pickup at Store</span>
                                                    </button>
                                                )}

                                                {delivery.status === 'picked_up' && (
                                                    <button
                                                        onClick={() => openStatusModal(delivery, 'in_transit')}
                                                        className="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                    >
                                                        <Building2 className="w-3.5 h-3.5" />
                                                        <span>Scan into Sorting Hub</span>
                                                    </button>
                                                )}

                                                {delivery.status === 'in_transit' && (
                                                    <button
                                                        onClick={() => openStatusModal(delivery, 'out_for_delivery')}
                                                        className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                    >
                                                        <Truck className="w-3.5 h-3.5" />
                                                        <span>Dispatch: Out for Delivery</span>
                                                    </button>
                                                )}

                                                {delivery.status === 'out_for_delivery' && (
                                                    <div className="flex items-center gap-2">
                                                        <button
                                                            onClick={() => openStatusModal(delivery, 'delivered')}
                                                            className="px-4 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                        >
                                                            <CheckCircle2 className="w-3.5 h-3.5" />
                                                            <span>Complete Delivery & Photo Proof</span>
                                                        </button>
                                                        <button
                                                            onClick={() => openStatusModal(delivery, 'failed')}
                                                            className="px-3 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-bold uppercase transition flex items-center gap-1.5"
                                                        >
                                                            <AlertCircle className="w-3.5 h-3.5 text-rose-600" />
                                                            <span>Delivery Failed</span>
                                                        </button>
                                                    </div>
                                                )}

                                                {isDelivered && (
                                                    <span className="text-emerald-600 font-bold flex items-center gap-1.5">
                                                        <CheckCircle2 className="w-4 h-4" /> Delivery Completed & Settled (+₱60.00 Payout)
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                )}

                {/* 4. TAB B: AVAILABLE JOBS BOARD (FIRST-COME, FIRST-SERVED) */}
                {activeTab === 'pool' && (
                    <div className="space-y-4">
                        {availableJobs.length === 0 ? (
                            <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                                <CheckCircle2 className="w-12 h-12 text-emerald-500 mx-auto" />
                                <h3 className="text-base font-bold text-slate-800">All available jobs are currently assigned!</h3>
                                <p className="text-xs text-slate-500 font-mono">
                                    Standing by for new orders marked "Ready for Pickup" by Bagoo merchants.
                                </p>
                            </div>
                        ) : (
                            availableJobs.map((job) => (
                                <div
                                    key={job.id}
                                    className="bg-white rounded-2xl p-6 border border-slate-200 hover:border-slate-300 shadow-xs transition space-y-4"
                                >
                                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 font-mono text-xs">
                                        <div className="flex items-center gap-3">
                                            <span className="font-bold text-slate-900 text-sm">#{job.tracking_number}</span>
                                            <span className="text-slate-400">•</span>
                                            <span className="text-slate-600">Order #{job.order?.order_number}</span>
                                            <span className="text-slate-400">•</span>
                                            <span className="px-2 py-0.5 rounded bg-emerald-50 text-emerald-800 font-bold uppercase">
                                                EST. EARNING: ₱60.00
                                            </span>
                                        </div>

                                        <span className="px-3 py-1 rounded-full bg-rose-50 text-[#E00D42] font-bold text-[11px] w-fit flex items-center gap-1">
                                            <Sparkles className="w-3.5 h-3.5" /> UNCLAIMED JOB
                                        </span>
                                    </div>

                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-sans">
                                        <div>
                                            <span className="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Merchant Store (Pickup)</span>
                                            <h4 className="font-bold text-slate-900">{job.pickup_store_name || 'Bagoo Merchant Store'}</h4>
                                            <p className="text-slate-600 font-mono mt-0.5">{job.pickup_address}</p>
                                        </div>
                                        <div>
                                            <span className="text-[10px] font-mono text-slate-400 uppercase font-bold block mb-1">Buyer Destination (Drop-off)</span>
                                            <h4 className="font-bold text-slate-900">{job.delivery_recipient_name}</h4>
                                            <p className="text-slate-600 font-mono mt-0.5">{job.delivery_address}</p>
                                        </div>
                                    </div>

                                    <div className="pt-3 border-t border-slate-100 flex items-center justify-between font-mono text-xs">
                                        <div className="text-slate-500 text-[11px]">
                                            Package Items: <strong className="text-slate-900">{job.order?.items?.length || 1} unit(s)</strong>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => claimDelivery(job.id)}
                                            disabled={actionLoading}
                                            className="px-5 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition flex items-center gap-2 shadow-xs"
                                        >
                                            <Check className="w-4 h-4" />
                                            <span>Claim Delivery Request</span>
                                        </button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}

                {/* 5. MODAL: STEP STATUS UPDATE WITH NOTES & PROOF */}
                {statusModalOpen && selectedDelivery && (
                    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="bg-white rounded-3xl p-6 max-w-md w-full shadow-2xl border border-slate-200 space-y-4 font-sans animate-scale-in">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono">
                                <h3 className="font-bold text-slate-900 text-sm uppercase">
                                    Update Task #{selectedDelivery.tracking_number}
                                </h3>
                                <button
                                    onClick={() => setStatusModalOpen(false)}
                                    className="text-slate-400 hover:text-slate-700 font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={submitStatusUpdate} className="space-y-4 text-xs">
                                <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 font-mono space-y-1">
                                    <span className="text-[10px] text-slate-400 uppercase">Target Status</span>
                                    <h4 className="font-bold text-slate-900 uppercase text-sm text-[#E00D42]">
                                        {nextStatus.replace('_', ' ')}
                                    </h4>
                                </div>

                                {nextStatus === 'delivered' && (
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">
                                            Drop-off Proof Photo (Required for Settlement)
                                        </label>
                                        <div className="aspect-video w-full rounded-xl overflow-hidden bg-slate-100 border border-slate-200 relative group">
                                            <img src={proofImage} alt="Drop-off Proof" className="w-full h-full object-cover" />
                                            <div className="absolute inset-0 bg-black/30 flex items-center justify-center text-white font-mono text-[10px] font-bold">
                                                Photo Captured with GPS Metadata
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {(nextStatus === 'failed' || nextStatus === 'delivery_failed') && (
                                    <div>
                                        <label className="block font-bold text-slate-700 mb-1 font-mono">
                                            Delivery Failure Reason (Required)
                                        </label>
                                        <select
                                            value={courierNotes}
                                            onChange={(e) => setCourierNotes(e.target.value)}
                                            className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        >
                                            <option value="">Select reason for failed attempt...</option>
                                            <option value="Customer unreachable / phone off">Customer unreachable / phone off</option>
                                            <option value="Customer not present at delivery address">Customer not present at delivery address</option>
                                            <option value="Incorrect / incomplete delivery address">Incorrect / incomplete delivery address</option>
                                            <option value="Customer requested reschedule">Customer requested reschedule</option>
                                            <option value="Customer refused package / cancelled COD">Customer refused package / cancelled COD</option>
                                        </select>
                                    </div>
                                )}

                                <div>
                                    <label className="block font-bold text-slate-700 mb-1 font-mono">
                                        Rider Operational Notes (Optional)
                                    </label>
                                    <textarea
                                        rows={2}
                                        value={courierNotes}
                                        onChange={(e) => setCourierNotes(e.target.value)}
                                        placeholder="e.g. Left with guard, buyer paid exact COD amount..."
                                        className="w-full rounded-xl bg-slate-50 border border-slate-200 p-2.5 text-xs focus:ring-[#E00D42] focus:border-[#E00D42]"
                                    />
                                </div>

                                <div className="flex items-center justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setStatusModalOpen(false)}
                                        className="px-4 py-2 rounded-xl border border-slate-200 font-mono font-bold text-slate-600"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={actionLoading}
                                        className="px-5 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-mono font-bold uppercase transition shadow-xs"
                                    >
                                        {actionLoading ? 'Saving...' : 'Confirm Step'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>
        </CourierLayout>
    );
}
