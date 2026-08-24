import React from 'react';
import { Head, Link } from '@inertiajs/react';
import CourierLayout from '@/Layouts/CourierLayout';
import { 
    DollarSign, 
    CheckCircle2, 
    Calendar, 
    Truck, 
    ArrowUpRight, 
    CreditCard, 
    TrendingUp, 
    ShieldCheck, 
    Download, 
    Clock, 
    MapPin 
} from 'lucide-react';

interface TripItem {
    id: number;
    tracking_number: string;
    order_number: string;
    store_name: string;
    delivery_address: string;
    recipient_name: string;
    delivered_at: string;
    payment_method: string;
    cod_amount: number;
    payout: number;
}

interface Props {
    stats: {
        totalCompleted: number;
        totalEarnings: number;
        codCollected: number;
        remittanceStatus: string;
        payoutRate: string;
    };
    trips: TripItem[];
}

export default function CourierEarnings({ stats, trips }: Props) {
    const formatPrice = (amount: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(amount);
    };

    return (
        <CourierLayout
            title="Courier Earnings & Remittance Ledger"
            subtitle="Real-time delivery compensation, trip history, and COD collection balance"
        >
            <Head title="Courier Earnings — Bagoo Express" />

            <div className="space-y-6 font-sans">
                
                {/* 1. EARNINGS OVERVIEW CARDS */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-5 font-mono">
                    
                    {/* Total Driver Payouts Earned */}
                    <div className="bg-slate-900 rounded-2xl p-6 text-white border border-slate-800 shadow-xl space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] uppercase font-bold text-slate-400">Total Driver Payouts</span>
                            <span className="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-400 text-[10px] font-bold border border-emerald-500/30">
                                80% REVENUE SHARE
                            </span>
                        </div>
                        <h3 className="text-3xl font-black text-emerald-400">{formatPrice(stats.totalEarnings)}</h3>
                        <p className="text-xs text-slate-400 font-sans">
                            Accumulated across <strong className="text-white">{stats.totalCompleted}</strong> fulfilled doorstep drop-offs ({stats.payoutRate}).
                        </p>
                    </div>

                    {/* Cash on Delivery (COD) Collected */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] uppercase font-bold text-slate-400">COD Cash On-Hand</span>
                            <span className="px-2 py-0.5 rounded bg-rose-50 text-[#E00D42] text-[10px] font-bold">
                                REMITTANCE DUE
                            </span>
                        </div>
                        <h3 className="text-3xl font-black text-[#E00D42]">{formatPrice(stats.codCollected)}</h3>
                        <p className="text-xs text-slate-500 font-sans">
                            Physical cash collected from COD parcels. Remit to Hub coordinator daily.
                        </p>
                    </div>

                    {/* Remittance & Account Standing */}
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-[10px] uppercase font-bold text-slate-400">Account Standing</span>
                            <ShieldCheck className="w-5 h-5 text-emerald-600" />
                        </div>
                        <h3 className="text-xl font-black text-slate-900 uppercase">GOOD STANDING</h3>
                        <p className="text-xs text-slate-500 font-sans">
                            Zero dispute strikes. Eligible for high-volume priority dispatch batches.
                        </p>
                    </div>

                </div>

                {/* 2. COMPLETED TRIPS LEDGER TABLE */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <Truck className="w-4 h-4 text-[#E00D42]" />
                            <h3 className="font-bold text-slate-900 text-sm uppercase">Completed Trip History Log</h3>
                        </div>
                        <span className="text-slate-500">{trips.length} Total Trip Records</span>
                    </div>

                    {trips.length === 0 ? (
                        <div className="py-12 text-center text-slate-400 font-mono text-xs">
                            No completed delivery trips yet. Claim your first task in the Dispatch Board.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs font-sans">
                                <thead>
                                    <tr className="border-b border-slate-200 font-mono text-[10px] text-slate-400 uppercase">
                                        <th className="pb-3 pr-4">Tracking Number</th>
                                        <th className="pb-3 px-4">Merchant Origin</th>
                                        <th className="pb-3 px-4">Recipient Drop-off</th>
                                        <th className="pb-3 px-4">Delivered Time</th>
                                        <th className="pb-3 px-4">Payment</th>
                                        <th className="pb-3 pl-4 text-right">Rider Payout</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 font-mono">
                                    {trips.map((trip) => (
                                        <tr key={trip.id} className="hover:bg-slate-50/60 transition">
                                            <td className="py-3.5 pr-4">
                                                <span className="font-bold text-slate-900">#{trip.tracking_number}</span>
                                                <span className="block text-[10px] text-slate-400">Order #{trip.order_number}</span>
                                            </td>
                                            <td className="py-3.5 px-4 font-sans">
                                                <span className="font-bold text-slate-800">{trip.store_name}</span>
                                            </td>
                                            <td className="py-3.5 px-4 font-sans">
                                                <span className="font-bold text-slate-800">{trip.recipient_name}</span>
                                                <span className="block text-[10px] text-slate-500 font-mono truncate max-w-xs">{trip.delivery_address}</span>
                                            </td>
                                            <td className="py-3.5 px-4 text-slate-500 text-[11px]">
                                                {trip.delivered_at}
                                            </td>
                                            <td className="py-3.5 px-4">
                                                <span className="px-2 py-0.5 rounded bg-slate-100 font-bold text-slate-700 text-[10px]">
                                                    {trip.payment_method}
                                                </span>
                                                {trip.payment_method === 'COD' && (
                                                    <span className="block text-[10px] text-[#E00D42] font-bold mt-0.5">
                                                        {formatPrice(trip.cod_amount)}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="py-3.5 pl-4 text-right">
                                                <span className="font-black text-emerald-600 text-sm">
                                                    +{formatPrice(trip.payout)}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

            </div>
        </CourierLayout>
    );
}
