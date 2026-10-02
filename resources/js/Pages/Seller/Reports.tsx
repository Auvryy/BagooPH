import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { OrderItem, Shop } from '@/types';
import { Calendar, CheckCircle2, Clock3, Landmark, Printer, ReceiptText } from 'lucide-react';

interface Props {
    shop: Shop;
    filters: {
        from_date: string;
        to_date: string;
    };
    report: {
        completedGrossSales: number;
        completedUnits: number;
        completedOrderCount: number;
        averageCompletedOrderValue: number;
        estimatedPlatformCommission: number;
        estimatedSellerShare: number;
        settledSellerAmount: number;
        pendingSettlementAmount: number;
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
            title="Finances"
            subtitle={`Completed sales and COD settlement status for ${shop.name}`}
            actions={
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="flex items-center gap-1.5 rounded-sm border border-slate-300 bg-white px-4 py-2 font-sans text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-100"
                >
                    <Printer className="w-3.5 h-3.5" />
                    <span>Print Statement</span>
                </button>
            }
        >
            <Head title="Finances — BagooPH Seller" />

            <div className="space-y-6 font-sans">
                
                {/* 1. DATE RANGE FILTER FORM */}
                <form onSubmit={handleDateFilter} className="flex flex-col items-center justify-between gap-4 rounded-lg border border-slate-300 bg-white p-5 font-sans text-xs shadow-2xs sm:flex-row">
                    <div className="flex items-center gap-2 text-slate-800 font-bold">
                        <Calendar className="w-4 h-4 text-[#E00D42]" />
                        <span>Completed between</span>
                    </div>

                    <div className="flex flex-wrap items-center gap-3 w-full sm:w-auto">
                        <div className="flex items-center gap-2">
                            <span className="text-slate-500">From:</span>
                            <input
                                type="date"
                                value={fromDate}
                                onChange={(e) => setFromDate(e.target.value)}
                                className="rounded-sm border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <div className="flex items-center gap-2">
                            <span className="text-slate-500">To:</span>
                            <input
                                type="date"
                                value={toDate}
                                onChange={(e) => setToDate(e.target.value)}
                                className="rounded-sm border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs text-slate-800 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <button
                            type="submit"
                            className="rounded-sm bg-[#E00D42] px-4 py-1.5 font-bold text-white shadow-2xs transition hover:bg-[#C20836]"
                        >
                            Update Filter
                        </button>
                    </div>
                </form>

                {/* 2. REVENUE, COMMISSION & PROFIT KPIS */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                        <div className="flex items-center justify-between text-xs font-semibold text-slate-500"><span>Completed product sales</span><CheckCircle2 className="h-4 w-4 text-emerald-600" /></div>
                        <h3 className="text-2xl font-black text-slate-900">{formatPrice(report.completedGrossSales)}</h3>
                        <p className="text-[11px] text-slate-500 font-sans">{report.completedUnits} items across {report.completedOrderCount} orders · {formatPrice(report.averageCompletedOrderValue)} average</p>
                    </div>

                    <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                        <div className="flex items-center justify-between text-xs font-semibold text-slate-500"><span>Estimated seller share</span><ReceiptText className="h-4 w-4 text-slate-500" /></div>
                        <h3 className="text-2xl font-black text-slate-900">{formatPrice(report.estimatedSellerShare)}</h3>
                        <p className="text-[11px] text-slate-500 font-sans">After estimated 10% commission of {formatPrice(report.estimatedPlatformCommission)}</p>
                    </div>

                    <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                        <div className="flex items-center justify-between text-xs font-semibold text-slate-500"><span>Awaiting settlement</span><Clock3 className="h-4 w-4 text-amber-600" /></div>
                        <h3 className="text-2xl font-black text-slate-900">{formatPrice(report.pendingSettlementAmount)}</h3>
                        <p className="text-[11px] text-slate-500 font-sans">Completed orders still waiting for recorded COD reconciliation</p>
                    </div>

                    <div className="space-y-2 rounded-lg border border-slate-300 bg-white p-5 shadow-2xs">
                        <div className="flex items-center justify-between text-xs font-semibold text-slate-500"><span>Recorded settled payout</span><Landmark className="h-4 w-4 text-emerald-600" /></div>
                        <h3 className="text-2xl font-black text-slate-900">{formatPrice(report.settledSellerAmount)}</h3>
                        <p className="text-[11px] text-slate-500 font-sans">Only completed, paid orders with a settled ledger entry</p>
                    </div>
                </div>

                {/* 3. TRANSACTION AUDIT TABLE */}
                <div className="space-y-4 rounded-lg border border-slate-300 bg-white p-6 shadow-2xs">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                        <div>
                            <h3 className="font-bold text-sm text-slate-900">Completed sales statement</h3>
                            <p className="text-xs text-slate-500 font-sans">Buyer-confirmed items and their recorded settlement state</p>
                        </div>
                        <span className="text-xs font-sans text-slate-500">Showing {orderItems.length} transactions</span>
                    </div>

                    {orderItems.length === 0 ? (
                        <p className="text-xs text-slate-500 py-8 text-center font-sans">No buyer-completed sales were recorded in this date range.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left font-sans text-xs text-slate-700">
                                <thead>
                                    <tr className="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase text-[10px] tracking-wider">
                                        <th className="py-3.5 px-4 font-bold">Completed</th>
                                        <th className="py-3.5 px-4 font-bold">Order #</th>
                                        <th className="py-3.5 px-4 font-bold">Product Item</th>
                                        <th className="py-3.5 px-4 font-bold">Qty</th>
                                        <th className="py-3.5 px-4 font-bold">Product Subtotal</th>
                                        <th className="py-3.5 px-4 font-bold">Seller Share (90%)</th>
                                        <th className="py-3.5 px-4 font-bold">Settlement</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {orderItems.map((item) => (
                                        <tr key={item.id} className="hover:bg-slate-50 transition">
                                            <td className="py-3.5 px-4 text-slate-500">{item.order?.completed_at ? new Date(item.order.completed_at).toLocaleDateString() : 'Completed'}</td>
                                            <td className="py-3.5 px-4 font-bold text-slate-900">#{item.order?.order_number}</td>
                                            <td className="py-3.5 px-4 font-sans font-bold text-slate-900 truncate max-w-xs">{item.product?.name}</td>
                                            <td className="py-3.5 px-4">{item.quantity}</td>
                                            <td className="py-3.5 px-4 font-bold text-slate-900">{formatPrice(item.subtotal)}</td>
                                            <td className="py-3.5 px-4 font-bold text-emerald-600">{formatPrice(Number(item.subtotal) * 0.9)}</td>
                                            <td className="py-3.5 px-4">
                                                {item.order?.payment_status === 'paid' && item.order?.commission_ledger?.status === 'settled' ? (
                                                    <span className="rounded-sm border border-emerald-200 bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700">SETTLED</span>
                                                ) : (
                                                    <span className="rounded-sm border border-amber-200 bg-amber-50 px-2 py-1 text-[10px] font-bold text-amber-700">COD PENDING</span>
                                                )}
                                            </td>
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
