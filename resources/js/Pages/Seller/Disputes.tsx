import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Shop } from '@/types';
import { 
    ShieldAlert, 
    CheckCircle2, 
    Clock, 
    Truck, 
    RotateCcw, 
    Check, 
    AlertTriangle, 
    User, 
    Phone, 
    Send, 
    Eye 
} from 'lucide-react';

interface DisputeItem {
    id: string;
    order_number: string;
    buyer_name: string;
    buyer_phone: string;
    product_name: string;
    reason: string;
    claim_type: string;
    amount: number;
    status: string;
    status_label: string;
    created_at: string;
    proof_image?: string;
    buyer_description: string;
}

interface Props {
    disputes: DisputeItem[];
    shop?: Shop | null;
}

export default function SellerDisputes({ disputes, shop }: Props) {
    const [actionSuccessId, setActionSuccessId] = useState<string | null>(null);

    const handleAction = (disputeId: string, action: string) => {
        setActionSuccessId(disputeId);
        setTimeout(() => setActionSuccessId(null), 3000);
    };

    const formatPrice = (amount: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(amount);
    };

    return (
        <DashboardLayout
            title="Dispute & Return Resolution"
            subtitle="Review customer defect claims, authorize replacements, and manage returns"
        >
            <Head title="Disputes & Returns — Merchant Cockpit" />

            <div className="space-y-6">
                
                {/* 1. DISPUTES OVERVIEW BANNER */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <ShieldAlert className="w-6 h-6 text-[#E00D42]" />
                            <h3 className="font-bold text-slate-900 text-base">Customer Claim Pipeline</h3>
                        </div>
                        <p className="text-xs text-slate-500 font-mono mt-1">
                            Tripartite mediation protects merchant payouts and ensures swift resolution for valid buyer defects.
                        </p>
                    </div>

                    <div className="flex items-center gap-2 font-mono text-xs">
                        <span className="px-3 py-1.5 rounded-lg bg-rose-50 text-[#E00D42] font-bold border border-rose-200">
                            {disputes.filter((d) => d.status === 'pending_seller').length} Action Required
                        </span>
                        <span className="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-700 font-bold">
                            {disputes.length} Total Claims
                        </span>
                    </div>
                </div>

                {/* 2. DISPUTE CARDS LIST */}
                <div className="space-y-4">
                    {disputes.map((item) => {
                        const isActioned = actionSuccessId === item.id;
                        return (
                            <div
                                key={item.id}
                                className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-5 font-sans"
                            >
                                {/* Header Meta */}
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100 font-mono text-xs">
                                    <div className="flex items-center gap-3">
                                        <span className="font-bold text-slate-900 text-sm">{item.id}</span>
                                        <span className="text-slate-400">•</span>
                                        <span className="text-slate-600">Order #{item.order_number}</span>
                                        <span className="text-slate-400">•</span>
                                        <span className="text-slate-500">{item.created_at}</span>
                                    </div>

                                    <span className={`px-3 py-1 rounded-full font-bold text-[11px] w-fit flex items-center gap-1.5 ${
                                        item.status === 'resolved'
                                            ? 'bg-emerald-100 text-emerald-900'
                                            : 'bg-rose-100 text-rose-900'
                                    }`}>
                                        {item.status === 'resolved' ? <Check className="w-3.5 h-3.5" /> : <Clock className="w-3.5 h-3.5" />}
                                        {item.status_label}
                                    </span>
                                </div>

                                {/* Content Details */}
                                <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
                                    
                                    {/* Left: Product & Buyer Complaint */}
                                    <div className="md:col-span-7 space-y-3">
                                        <div>
                                            <span className="text-[10px] font-mono text-slate-400 uppercase">Item Claimed</span>
                                            <h4 className="font-bold text-slate-900 text-sm mt-0.5">{item.product_name}</h4>
                                            <div className="flex items-center gap-3 text-xs text-slate-500 font-mono mt-1">
                                                <span>Buyer: {item.buyer_name}</span>
                                                <span>•</span>
                                                <span>{item.buyer_phone}</span>
                                            </div>
                                        </div>

                                        <div className="p-3.5 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-1">
                                            <span className="font-bold text-slate-800 block">Customer Reported Reason:</span>
                                            <p className="text-[#E00D42] font-mono font-bold">{item.reason}</p>
                                            <p className="text-slate-600 pt-1 leading-relaxed">{item.buyer_description}</p>
                                        </div>
                                    </div>

                                    {/* Right: Photographic Evidence & Value */}
                                    <div className="md:col-span-5 space-y-3 font-mono text-xs">
                                        <div>
                                            <span className="text-[10px] text-slate-400 uppercase block mb-1">Buyer Defect Photo</span>
                                            {item.proof_image && (
                                                <div className="aspect-video w-full rounded-xl overflow-hidden bg-slate-100 border border-slate-200">
                                                    <img src={item.proof_image} alt="Defect" className="w-full h-full object-cover" />
                                                </div>
                                            )}
                                        </div>

                                        <div className="pt-2 flex items-center justify-between text-xs">
                                            <span className="text-slate-500">Order Subtotal:</span>
                                            <span className="font-black text-slate-900 text-sm">
                                                {formatPrice(item.amount)}
                                            </span>
                                        </div>
                                    </div>

                                </div>

                                {/* Action Toolbar */}
                                <div className="pt-4 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-mono text-xs">
                                    {isActioned ? (
                                        <div className="p-2.5 bg-emerald-50 text-emerald-800 rounded-xl font-bold flex items-center gap-2">
                                            <Check className="w-4 h-4 text-emerald-600" />
                                            <span>Resolution dispatched: Exchange courier pickup requested!</span>
                                        </div>
                                    ) : item.status === 'resolved' ? (
                                        <div className="text-slate-500 italic">
                                            This claim has been fulfilled and closed.
                                        </div>
                                    ) : (
                                        <>
                                            <span className="text-slate-500">Select Merchant Action:</span>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => handleAction(item.id, 'accept_exchange')}
                                                    className="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold uppercase transition flex items-center gap-1.5 shadow-xs"
                                                >
                                                    <Truck className="w-3.5 h-3.5" />
                                                    <span>Accept & Dispatch Exchange</span>
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleAction(item.id, 'accept_refund')}
                                                    className="px-4 py-2 rounded-xl bg-slate-900 hover:bg-black text-white font-bold uppercase transition shadow-xs"
                                                >
                                                    Approve Refund
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => handleAction(item.id, 'dispute_claim')}
                                                    className="px-4 py-2 rounded-xl border border-slate-200 text-slate-700 font-bold hover:bg-slate-50 transition"
                                                >
                                                    Escalate to Admin
                                                </button>
                                            </div>
                                        </>
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
