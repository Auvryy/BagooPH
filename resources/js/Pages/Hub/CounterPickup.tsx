import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    Store, 
    Search, 
    CheckCircle2, 
    Clock, 
    Package, 
    User, 
    Phone, 
    ShieldCheck, 
    ScanLine,
    ArrowRight,
    Tag,
    AlertCircle,
    X
} from 'lucide-react';

interface CounterParcel {
    id: number;
    tracking_number: string;
    order_number: string;
    buyer_name: string;
    buyer_phone: string;
    status: string;
    destination_bin: string;
    total_amount: number;
    payment_method: string;
    item_count: number;
    items: Array<{
        name: string;
        quantity: number;
        price: number;
    }>;
    arrived_at: string;
}

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
        city_municipality: string;
    } | null;
    counterParcels: CounterParcel[];
    recentlyCollected: Array<{
        id: number;
        tracking_number: string;
        buyer_name: string;
        collected_at: string;
    }>;
    search: string;
}

export default function HubCounterPickup({ activeHub, counterParcels, recentlyCollected, search }: Props) {
    const [searchTerm, setSearchTerm] = useState(search || '');
    const [selectedParcel, setSelectedParcel] = useState<CounterParcel | null>(null);
    const [recipientName, setRecipientName] = useState('');
    const [claimCode, setClaimCode] = useState('');
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            route('hub.counter'),
            { search: searchTerm },
            { preserveState: true, preserveScroll: true }
        );
    };

    const openReleaseModal = (parcel: CounterParcel) => {
        setSelectedParcel(parcel);
        setRecipientName(parcel.buyer_name);
        setClaimCode('');
        setNotes('');
    };

    const handleReleaseSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedParcel) return;
        setSubmitting(true);

        router.post(
            route('hub.release'),
            {
                barcode: selectedParcel.tracking_number,
                claim_code: claimCode.trim() || undefined,
                recipient_name: recipientName.trim() || selectedParcel.buyer_name,
                hub_id: activeHub?.id,
                notes: notes.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedParcel(null);
                    setSubmitting(false);
                },
                onError: () => {
                    setSubmitting(false);
                },
            }
        );
    };

    return (
        <DashboardLayout
            title="Counter Self-Pickup"
            subtitle="Customer handover verification & zero-fee claim desk"
            actions={
                <Link
                    href={route('hub.scan.station')}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-mono rounded-xs shadow-xs transition uppercase tracking-wider"
                >
                    <ScanLine className="w-3.5 h-3.5" />
                    <span>Station Scanner</span>
                </Link>
            }
        >
            <Head title="Counter Self-Pickup — BagooPH" />

            <div className="space-y-5 font-sans">

                {/* 1. TOP STATS TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Awaiting Pickup</span>
                                <Store className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {counterParcels.length}{' '}
                                    <span className="text-xs font-bold text-slate-400">ready</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Station Shelves:</span>
                            <span className="font-bold text-slate-800">Organized</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Handover Fee</span>
                                <Tag className="w-3.5 h-3.5 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-emerald-700 font-mono tracking-tight">
                                    ₱0.00{' '}
                                    <span className="text-xs font-bold text-emerald-700">FREE</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Customer Incentive:</span>
                            <span className="font-bold text-emerald-700">Zero Shipping</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Recently Claimed</span>
                                <CheckCircle2 className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {recentlyCollected.length}{' '}
                                    <span className="text-xs font-bold text-slate-400">released</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Today's Handover:</span>
                            <span className="font-bold text-slate-800">100% Verified</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-xs p-4 border border-slate-300 shadow-xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Station Location</span>
                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-xs bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] font-mono">
                                    {activeHub?.code || 'STATION'}
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-base font-black text-slate-900 truncate">
                                    {activeHub?.name || 'Local Bayan Hub'}
                                </p>
                                <p className="text-xs text-slate-500 font-mono mt-0.5">
                                    {activeHub?.city_municipality || 'Laguna'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-200 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-500">Self-Pickup Desk:</span>
                            <span className="font-bold text-emerald-700">Open For Claims</span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH BAR & ACTION HEADER */}
                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
                    <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-96">
                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            placeholder="Scan or enter Tracking # / Recipient Name..."
                            className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-300 rounded-xs text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] transition"
                        />
                    </form>

                    <div className="text-xs font-mono text-slate-500 flex items-center gap-2">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span>Verify Customer Government ID or SMS Claim Code before release</span>
                    </div>
                </div>

                {/* 3. MAIN CONTENT: STAGED PARCELS QUEUE + RECENTLY COLLECTED */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">

                    {/* LEFT (8 COLS): STAGED PARCELS FOR HANDOVER */}
                    <div className="lg:col-span-8 bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                            <div className="flex items-center gap-2">
                                <Store className="w-4 h-4 text-slate-700" />
                                <h3 className="text-xs font-black text-slate-900 font-mono uppercase tracking-wider">
                                    Parcels Ready For Customer Handover
                                </h3>
                            </div>
                            <span className="text-xs font-mono text-slate-500 font-bold">
                                {counterParcels.length} on shelves
                            </span>
                        </div>

                        {counterParcels.length === 0 ? (
                            <div className="py-12 text-center font-mono">
                                <Store className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                                <p className="text-xs font-bold text-slate-700">No parcels waiting at this counter</p>
                                <p className="text-[11px] text-slate-400 mt-1">
                                    When inbound parcels are sorted to the pickup shelf, they appear here.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-200 mt-1">
                                {counterParcels.map((parcel) => (
                                    <div key={parcel.id} className="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap font-mono">
                                                <span className="font-bold text-slate-900 text-xs">
                                                    {parcel.tracking_number}
                                                </span>
                                                <span className="text-[10px] px-1.5 py-0.5 rounded-xs bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] font-bold">
                                                    {parcel.destination_bin}
                                                </span>
                                                <span className="text-[10px] uppercase px-1.5 py-0.5 rounded-xs bg-slate-100 text-slate-600 border border-slate-200">
                                                    {parcel.payment_method}
                                                </span>
                                            </div>

                                            <div className="mt-1 flex items-center gap-3 text-xs text-slate-600">
                                                <span className="font-bold text-slate-900 flex items-center gap-1 font-mono">
                                                    <User className="w-3 h-3 text-slate-400" />
                                                    {parcel.buyer_name}
                                                </span>
                                                {parcel.buyer_phone !== 'N/A' && (
                                                    <span className="text-slate-500 font-mono flex items-center gap-1 text-[11px]">
                                                        <Phone className="w-3 h-3 text-slate-400" />
                                                        {parcel.buyer_phone}
                                                    </span>
                                                )}
                                                <span className="text-slate-400 font-mono text-[10px]">
                                                    Arrived: {parcel.arrived_at}
                                                </span>
                                            </div>

                                            {/* Item breakdown preview */}
                                            {parcel.items && parcel.items.length > 0 && (
                                                <div className="mt-1.5 text-[11px] text-slate-500 font-mono">
                                                    {parcel.items.map((it, idx) => (
                                                        <span key={idx} className="mr-3">
                                                            {it.quantity}x {it.name}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-3 sm:text-right shrink-0 font-mono">
                                            <div>
                                                <span className="text-xs font-bold text-slate-900 block">
                                                    ₱{parcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                                <span className="text-[10px] text-slate-400 block uppercase">
                                                    {parcel.payment_method === 'cod' ? 'Collect COD' : 'Prepaid'}
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => openReleaseModal(parcel)}
                                                className="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xs text-xs font-bold font-mono shadow-xs transition flex items-center gap-1.5 cursor-pointer"
                                            >
                                                <CheckCircle2 className="w-3.5 h-3.5" />
                                                <span>Release</span>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* RIGHT (4 COLS): RECENTLY COLLECTED AUDIT LOG */}
                    <div className="lg:col-span-4 bg-white rounded-xs p-5 border border-slate-300 shadow-xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-200">
                            <div className="flex items-center gap-2">
                                <CheckCircle2 className="w-4 h-4 text-emerald-700" />
                                <h3 className="text-xs font-black text-slate-900 font-mono uppercase tracking-wider">
                                    Recent Handover Log
                                </h3>
                            </div>
                            <span className="text-[10px] font-mono font-bold text-slate-400 uppercase">
                                Verified
                            </span>
                        </div>

                        {recentlyCollected.length === 0 ? (
                            <div className="py-10 text-center text-slate-400 text-xs font-mono">
                                No claims released recently.
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-200 mt-1 font-mono">
                                {recentlyCollected.map((item) => (
                                    <div key={item.id} className="py-2.5">
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-xs text-slate-900">
                                                {item.tracking_number}
                                            </span>
                                            <span className="text-[10px] text-slate-400">
                                                {item.collected_at}
                                            </span>
                                        </div>
                                        <p className="text-xs text-slate-600 mt-0.5 font-sans">
                                            Handed over to: <span className="font-bold text-slate-800">{item.buyer_name}</span>
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="mt-3.5 p-2.5 rounded-xs bg-slate-50 border border-slate-300 text-xs text-slate-800">
                            <p className="font-bold flex items-center gap-1.5 font-mono text-[11px]">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-700" />
                                Verified Station Handover
                            </p>
                            <p className="text-[10px] text-slate-500 font-mono mt-0.5">
                                Counter handovers automatically resolve status to "Customer Collected" with audit timestamps.
                            </p>
                        </div>
                    </div>

                </div>

            </div>

            {/* RELEASE VERIFICATION MODAL */}
            {selectedParcel && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-fade-in font-mono">
                    <div className="bg-white rounded-xs max-w-md w-full p-5 shadow-2xl border border-slate-300 animate-scale-in">
                        <div className="flex items-center justify-between pb-2.5 border-b border-slate-200">
                            <div>
                                <h3 className="text-xs font-black text-slate-900 uppercase">
                                    Handover Verification
                                </h3>
                                <p className="text-xs text-[#E00D42] font-bold mt-0.5">
                                    {selectedParcel.tracking_number}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedParcel(null)}
                                className="p-1 text-slate-400 hover:text-slate-700 cursor-pointer rounded-xs"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleReleaseSubmit} className="space-y-3.5 mt-3.5">
                            <div>
                                <label className="block text-[10px] font-bold text-slate-700 uppercase mb-1">
                                    Recipient Name (ID Verified)
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={recipientName}
                                    onChange={(e) => setRecipientName(e.target.value)}
                                    className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-xs text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div>
                                <label className="block text-[10px] font-bold text-slate-700 uppercase mb-1">
                                    Claim Code (Optional)
                                </label>
                                <input
                                    type="text"
                                    value={claimCode}
                                    onChange={(e) => setClaimCode(e.target.value)}
                                    placeholder="e.g. CLAIM-8849"
                                    className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-xs text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div>
                                <label className="block text-[10px] font-bold text-slate-700 uppercase mb-1">
                                    Staff Handover Note
                                </label>
                                <input
                                    type="text"
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    placeholder="e.g. Valid Driver's License inspected"
                                    className="w-full px-2.5 py-1.5 bg-slate-50 border border-slate-300 rounded-xs text-xs font-mono focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            {selectedParcel.payment_method === 'cod' && (
                                <div className="p-2.5 rounded-xs bg-amber-50 border border-amber-300 text-amber-900 text-xs">
                                    <p className="font-bold text-[10px] uppercase">⚠️ Collect Cash on Delivery</p>
                                    <p className="text-sm font-black mt-0.5">
                                        ₱{selectedParcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-2 pt-2.5 border-t border-slate-200 font-mono">
                                <button
                                    type="button"
                                    onClick={() => setSelectedParcel(null)}
                                    className="px-3 py-1.5 rounded-xs text-xs font-bold text-slate-600 hover:bg-slate-100 border border-slate-300 cursor-pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    className="px-4 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-xs text-xs font-bold shadow-xs transition cursor-pointer disabled:opacity-50"
                                >
                                    {submitting ? 'Releasing...' : 'Confirm Handover'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
