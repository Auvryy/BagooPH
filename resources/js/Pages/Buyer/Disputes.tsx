import React, { useState } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Order } from '@/types';
import { 
    ShieldAlert, 
    CheckCircle2, 
    Clock, 
    AlertTriangle, 
    Plus, 
    Upload, 
    FileText, 
    ArrowRight, 
    ExternalLink, 
    Check, 
    RefreshCw, 
    Image as ImageIcon 
} from 'lucide-react';

interface DisputeItem {
    id: string;
    order_number: string;
    product_name: string;
    shop_name: string;
    reason: string;
    refund_amount: number;
    status: string;
    status_label: string;
    created_at: string;
    proof_image?: string;
    seller_response?: string;
    timeline: {
        title: string;
        time: string;
        done: boolean;
    }[];
}

interface Props {
    disputes: DisputeItem[];
    eligibleOrders: Order[];
}

export default function BuyerDisputes({ disputes, eligibleOrders }: Props) {
    const [showNewModal, setShowNewModal] = useState(false);

    const { data, setData, post, processing, errors, reset, recentlySuccessful } = useForm({
        order_id: eligibleOrders.length > 0 ? eligibleOrders[0].id : '',
        reason: 'Damaged item / Stitching defect',
        description: '',
        proof_image: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&auto=format&fit=crop&q=60',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.disputes.store'), {
            onSuccess: () => {
                setShowNewModal(false);
                reset();
            },
        });
    };

    const formatPrice = (amount: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(amount);
    };

    return (
        <BuyerLayout>
            <Head title="Returns & Dispute Center — BagooPH" />

            <div className="space-y-6">
                
                {/* 1. HEADER BANNER */}
                <div className="bg-white rounded-3xl p-6 sm:p-8 border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div className="flex items-center gap-2">
                            <ShieldAlert className="w-6 h-6 text-[#E00D42]" />
                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 font-sans">Returns, Defect & Dispute Center</h1>
                        </div>
                        <p className="text-xs text-slate-500 font-mono mt-1">
                            Tripartite mediation system protecting buyers, merchants, and logistics couriers.
                        </p>
                    </div>

                    <button
                        onClick={() => setShowNewModal(true)}
                        className="px-5 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-mono font-bold uppercase transition text-xs shadow-xs flex items-center gap-2 w-fit"
                    >
                        <Plus className="w-4 h-4" />
                        <span>File Return / Defect Report</span>
                    </button>
                </div>

                {/* 2. DISPUTES LIST */}
                <div className="space-y-4">
                    {disputes.length === 0 ? (
                        <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                            <CheckCircle2 className="w-12 h-12 text-emerald-500 mx-auto" />
                            <h3 className="text-base font-bold text-slate-800">No active returns or disputes</h3>
                            <p className="text-xs text-slate-500 font-mono">
                                All your delivered orders are in good standing with 100% resolution.
                            </p>
                        </div>
                    ) : (
                        disputes.map((item) => (
                            <div 
                                key={item.id}
                                className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-5 font-sans"
                            >
                                {/* Header Bar */}
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100 font-mono text-xs">
                                    <div className="flex items-center gap-3">
                                        <span className="font-bold text-slate-900 text-sm">{item.id}</span>
                                        <span className="text-slate-400">•</span>
                                        <span className="text-slate-600">Order #{item.order_number}</span>
                                        <span className="text-slate-400">•</span>
                                        <span className="text-slate-500">{item.created_at}</span>
                                    </div>

                                    <span className="px-3 py-1 rounded-full bg-amber-100 text-amber-900 font-bold text-[11px] w-fit flex items-center gap-1.5">
                                        <Clock className="w-3.5 h-3.5" />
                                        {item.status_label}
                                    </span>
                                </div>

                                {/* Content Body */}
                                <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
                                    
                                    {/* Left: Product & Reason */}
                                    <div className="md:col-span-7 space-y-3">
                                        <div>
                                            <span className="text-[10px] font-mono text-slate-400 uppercase">Affected Item & Merchant</span>
                                            <h4 className="font-bold text-slate-900 text-sm mt-0.5">{item.product_name}</h4>
                                            <p className="text-xs text-slate-500 font-mono">Store: {item.shop_name}</p>
                                        </div>

                                        <div className="p-3 bg-slate-50 rounded-xl border border-slate-200 text-xs space-y-1">
                                            <span className="font-bold text-slate-700 block">Reported Issue:</span>
                                            <p className="text-slate-600 font-mono">{item.reason}</p>
                                        </div>

                                        {item.seller_response && (
                                            <div className="p-3 bg-emerald-50 rounded-xl border border-emerald-200 text-xs space-y-1">
                                                <span className="font-bold text-emerald-900 block">Merchant Response:</span>
                                                <p className="text-emerald-800">{item.seller_response}</p>
                                            </div>
                                        )}
                                    </div>

                                    {/* Right: Photographic Evidence & Refund */}
                                    <div className="md:col-span-5 space-y-3 font-mono text-xs">
                                        <div>
                                            <span className="text-[10px] text-slate-400 uppercase block mb-1">Uploaded Evidence</span>
                                            {item.proof_image && (
                                                <div className="aspect-video w-full rounded-xl overflow-hidden bg-slate-100 border border-slate-200 relative group">
                                                    <img src={item.proof_image} alt="Defect proof" className="w-full h-full object-cover" />
                                                    <div className="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition flex items-center justify-center text-white font-bold text-xs">
                                                        Inspect Evidence
                                                    </div>
                                                </div>
                                            )}
                                        </div>

                                        <div className="pt-2 flex items-center justify-between text-xs">
                                            <span className="text-slate-500">Claim Value:</span>
                                            <span className="font-bold text-slate-900 text-sm text-[#E00D42]">
                                                {formatPrice(item.refund_amount)}
                                            </span>
                                        </div>
                                    </div>

                                </div>

                                {/* Tripartite Timeline Progression */}
                                <div className="pt-4 border-t border-slate-100 font-mono text-xs">
                                    <span className="text-[10px] text-slate-400 uppercase font-bold block mb-3">Tripartite Mediation Workflow</span>
                                    <div className="grid grid-cols-1 sm:grid-cols-4 gap-2">
                                        {item.timeline.map((step, idx) => (
                                            <div 
                                                key={idx}
                                                className={`p-2.5 rounded-xl border ${
                                                    step.done 
                                                        ? 'bg-emerald-50 border-emerald-200 text-emerald-900' 
                                                        : 'bg-slate-50 border-slate-200 text-slate-400'
                                                }`}
                                            >
                                                <div className="flex items-center gap-1.5 font-bold text-[11px]">
                                                    {step.done ? <Check className="w-3.5 h-3.5 text-emerald-600" /> : <Clock className="w-3.5 h-3.5" />}
                                                    <span>{step.title}</span>
                                                </div>
                                                <span className="text-[10px] block mt-1 opacity-80">{step.time}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))
                    )}
                </div>

                {/* 3. MODAL FOR FILING NEW DISPUTE */}
                {showNewModal && (
                    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-4 font-sans animate-scale-in">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono">
                                <h3 className="font-bold text-slate-900 text-base">File Return & Defect Claim</h3>
                                <button 
                                    onClick={() => setShowNewModal(false)}
                                    className="text-slate-400 hover:text-slate-700 text-sm font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4 text-xs">
                                <div>
                                    <label className="block font-bold text-slate-700 mb-1 font-mono">Select Delivered Order</label>
                                    <select
                                        value={data.order_id}
                                        onChange={(e) => setData('order_id', e.target.value)}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs py-2.5 px-3"
                                    >
                                        {eligibleOrders.map((ord) => (
                                            <option key={ord.id} value={ord.id}>
                                                #{ord.order_number} — {ord.items?.[0]?.product?.name || 'Delivered Package'}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 mb-1 font-mono">Reason for Claim</label>
                                    <select
                                        value={data.reason}
                                        onChange={(e) => setData('reason', e.target.value)}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs py-2.5 px-3"
                                    >
                                        <option value="Damaged item / Stitching defect">Damaged item / Stitching defect</option>
                                        <option value="Wrong product or variation received">Wrong product or variation received</option>
                                        <option value="Missing parts / accessories">Missing parts / accessories</option>
                                        <option value="Parcel damaged during courier transit">Parcel damaged during courier transit</option>
                                    </select>
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 mb-1 font-mono">Detailed Issue Description</label>
                                    <textarea
                                        rows={3}
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="Describe the defect, when you opened the parcel, and packaging condition..."
                                        className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs p-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        required
                                    />
                                </div>

                                <div className="flex items-center justify-end gap-3 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setShowNewModal(false)}
                                        className="px-4 py-2 rounded-xl border border-slate-200 font-mono text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-5 py-2 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-mono text-xs font-bold uppercase shadow-xs transition"
                                    >
                                        {processing ? 'Submitting...' : 'Submit Claim'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
