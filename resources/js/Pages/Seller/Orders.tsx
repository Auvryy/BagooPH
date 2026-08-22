import React from 'react';
import { Head, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { OrderItem, PaginatedData, Shop } from '@/types';
import { ShoppingCart, PackageCheck, Truck, CheckCircle2 } from 'lucide-react';

interface Props {
    orderItems: PaginatedData<OrderItem>;
    shop: Shop;
}

export default function SellerOrders({ orderItems, shop }: Props) {
    const markReady = (orderId: number) => {
        router.post(route('seller.orders.ready', orderId));
    };

    return (
        <DashboardLayout
            title="Customer Orders & Dispatch Preparation"
            subtitle={`Incoming order flow for ${shop?.name || 'Merchant'}`}
        >
            <Head title="Store Orders — Seller Center" />

            <div className="bg-white rounded-3xl border border-slate-200 overflow-hidden shadow-xs">
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-slate-50 border-b border-slate-200 text-slate-400 font-bold uppercase text-[10px] tracking-wider">
                            <tr>
                                <th className="py-3.5 px-6">Order ID</th>
                                <th className="py-3.5 px-4">Item</th>
                                <th className="py-3.5 px-4">Customer</th>
                                <th className="py-3.5 px-4">Amount</th>
                                <th className="py-3.5 px-4">Order Status</th>
                                <th className="py-3.5 px-6 text-right">Dispatch Action</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {orderItems.data.map((item) => (
                                <tr key={item.id} className="hover:bg-slate-50 transition">
                                    <td className="py-4 px-6 font-mono font-bold text-slate-900">
                                        #{item.order?.order_number}
                                    </td>
                                    <td className="py-4 px-4">
                                        <div className="flex items-center gap-2.5">
                                            <img src={item.product?.featured_image || ''} alt="" className="w-9 h-9 rounded-xl object-cover bg-slate-100" />
                                            <div>
                                                <p className="font-bold text-slate-800">{item.product?.name}</p>
                                                <p className="text-slate-400">Qty: {item.quantity}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td className="py-4 px-4 text-slate-700">
                                        <p className="font-bold">{item.order?.buyer?.name || 'Buyer'}</p>
                                        <p className="text-[11px] text-slate-400">{item.order?.shipping_city}</p>
                                    </td>
                                    <td className="py-4 px-4 font-black text-slate-900">
                                        ${Number(item.subtotal).toFixed(2)}
                                    </td>
                                    <td className="py-4 px-4">
                                        <span className="capitalize px-2.5 py-1 rounded-full text-[11px] font-bold bg-slate-100 text-slate-700">
                                            {item.order?.status.replace('_', ' ')}
                                        </span>
                                    </td>
                                    <td className="py-4 px-6 text-right">
                                        {item.order?.status === 'processing' ? (
                                            <button
                                                onClick={() => markReady(item.order_id)}
                                                className="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs rounded-xl shadow-xs transition inline-flex items-center gap-1.5"
                                            >
                                                <PackageCheck className="w-3.5 h-3.5" />
                                                <span>Ready for Courier</span>
                                            </button>
                                        ) : (
                                            <span className="text-slate-400 text-xs font-medium">Dispatched</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </DashboardLayout>
    );
}
