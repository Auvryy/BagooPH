import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { OrderItem, Shop } from '@/types';
import { 
    DollarSign, 
    TrendingUp, 
    Calendar, 
    FileSpreadsheet, 
    Printer, 
    ArrowRight, 
    Package, 
    ShoppingBag, 
    ShieldCheck, 
    Percent,
    Download
} from 'lucide-react';

interface Props {
    shop: Shop;
    filters: {
        from_date: string;
        to_date: string;
    };
    report: {
        grossSales: number;
        totalUnits: number;
        platformCommission: number;
        netPayout: number;
        orderCount: number;
        avgOrderValue: number;
    };
    orderItems: OrderItem[];
}

export default function SellerReports({ shop, filters, report, orderItems }: Props) {
    const [fromDate, setFromDate] = useState(filters.from_date);
    const [toDate, setToDate] = useState(filters.to_date);

    const handleDateFilter = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('seller.reports'), {
            from_date: fromDate,
            to_date: toDate,
        }, { preserveState: true });
    };

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    return (
        <DashboardLayout
            title="Financial Statement & Payout Reconciliation"
            subtitle={`Gross earnings and platform commission ledger for ${shop.name}`}
            actions={
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="flex items-center gap-1.5 px-4 py-2 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold font-mono rounded-xl border border-slate-200 transition uppercase shadow-2xs"
                >
                    <Printer className="w-3.5 h-3.5" />
                    <span>Print Statement</span>
                </button>
            }
        >
            <Head title="Financial Reports — BagooPH Seller" />

            <div className="space-y-6 font-sans">
                
                {/* 1. DATE RANGE FILTER FORM */}
                <form onSubmit={handleDateFilter} className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col sm:flex-row items-center justify-between gap-4 font-mono text-xs">
                    <div className="flex items-center gap-2 text-slate-800 font-bold">
                        <Calendar className="w-4 h-4 text-[#E00D42]" />
                        <span>Filter Statement Period:</span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 w-full sm:w-auto">
                        <div className="flex items-center gap-2">
                            <span className="text-slate-500">From:</span>
                            <input
                                type="date"
                                value={fromDate}
                                onChange={(e) => setFromDate(e.target.value)}
                                className="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <div className="flex items-center gap-2">
                            <span className="text-slate-500">To:</span>
                            <input
                                type="date"
                                value={toDate}
                                onChange={(e) => setToDate(e.target.value)}
                                className="px-3 py-1.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-800 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <button
                            type="submit"
                            className="px-4 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl uppercase transition shadow-2xs"
                        >
                            Update Filter
                        </button>
                    </div>
                </form>

                {/* 2. REVENUE, COMMISSION & PROFIT KPIS */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                        <span className="text-xs font-bold uppercase text-slate-500 font-mono">Gross Sales</span>
                        <h3 className="text-2xl font-black text-slate-900">{formatPrice(report.grossSales)}</h3>
                        <p className="text-[11px] text-slate-400 font-mono">{report.totalUnits} items across {report.orderCount} orders</p>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                        <span className="text-xs font-bold uppercase text-slate-500 font-mono">Platform Commission (10%)</span>
                        <h3 className="text-2xl font-black text-rose-600">-{formatPrice(report.platformCommission)}</h3>
                        <p className="text-[11px] text-slate-400 font-mono">10% standard marketplace service fee</p>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                        <span className="text-xs font-bold uppercase text-slate-500 font-mono">Net Seller Disbursement</span>
                        <h3 className="text-2xl font-black text-emerald-600">{formatPrice(report.netPayout)}</h3>
                        <p className="text-[11px] text-emerald-600 font-mono font-bold">Available for payout disbursement</p>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-2">
                        <span className="text-xs font-bold uppercase text-slate-500 font-mono">Average Order Value</span>
                        <h3 className="text-2xl font-black text-indigo-600">{formatPrice(report.avgOrderValue)}</h3>
                        <p className="text-[11px] text-slate-400 font-mono">Average spend per basket</p>
                    </div>
                </div>

                {/* 3. TRANSACTION AUDIT TABLE */}
                <div className="bg-white rounded-2xl border border-slate-200/90 p-6 space-y-4 shadow-2xs">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                        <div>
                            <h3 className="font-bold text-sm text-slate-900">Itemized Sales Audit Trail</h3>
                            <p className="text-xs text-slate-400 font-mono">Historical transaction ledger for accounting</p>
                        </div>
                        <span className="text-xs font-mono text-slate-500">Showing {orderItems.length} transactions</span>
                    </div>

                    {orderItems.length === 0 ? (
                        <p className="text-xs text-slate-400 py-8 text-center font-mono">No transactions recorded for the selected date range.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left font-mono text-xs text-slate-700">
                                <thead>
                                    <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase text-[10px] tracking-wider">
                                        <th className="py-3.5 px-4 font-bold">Date</th>
                                        <th className="py-3.5 px-4 font-bold">Order #</th>
                                        <th className="py-3.5 px-4 font-bold">Product Item</th>
                                        <th className="py-3.5 px-4 font-bold">Qty</th>
                                        <th className="py-3.5 px-4 font-bold">Gross Subtotal</th>
                                        <th className="py-3.5 px-4 font-bold">Net Payout (90%)</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {orderItems.map((item) => (
                                        <tr key={item.id} className="hover:bg-slate-50 transition">
                                            <td className="py-3.5 px-4 text-slate-500">{item.order?.created_at ? new Date(item.order.created_at).toLocaleDateString() : 'Recent'}</td>
                                            <td className="py-3.5 px-4 font-bold text-slate-900">#{item.order?.order_number}</td>
                                            <td className="py-3.5 px-4 font-sans font-bold text-slate-900 truncate max-w-xs">{item.product?.name}</td>
                                            <td className="py-3.5 px-4">{item.quantity}</td>
                                            <td className="py-3.5 px-4 font-bold text-slate-900">{formatPrice(item.subtotal)}</td>
                                            <td className="py-3.5 px-4 font-bold text-emerald-600">{formatPrice(Number(item.subtotal) * 0.9)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

            </div>
        </DashboardLayout>
    );
}
