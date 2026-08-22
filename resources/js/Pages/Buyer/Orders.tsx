import React from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Order, PaginatedData } from '@/types';
import { Package, Truck, ArrowRight, Clock, CheckCircle, ShieldAlert, ShoppingBag } from 'lucide-react';

interface Props {
    orders: PaginatedData<Order>;
}

export default function BuyerOrders({ orders }: Props) {
    const getStatusPill = (status: string) => {
        switch (status) {
            case 'delivered':
                return <span className="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold flex items-center gap-1"><CheckCircle className="w-3 h-3" /> Delivered</span>;
            case 'shipped':
                return <span className="px-3 py-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full text-xs font-bold flex items-center gap-1"><Truck className="w-3 h-3" /> On the Way</span>;
            case 'ready_for_pickup':
                return <span className="px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold flex items-center gap-1"><Clock className="w-3 h-3" /> Ready for Courier</span>;
            case 'processing':
                return <span className="px-3 py-1 bg-blue-50 text-blue-700 border border-blue-200 rounded-full text-xs font-bold flex items-center gap-1"><Clock className="w-3 h-3" /> Merchant Packaging</span>;
            default:
                return <span className="px-3 py-1 bg-slate-100 text-slate-700 rounded-full text-xs font-bold">{status}</span>;
        }
    };

    return (
        <MarketplaceLayout>
            <Head title="My Orders & Shipments — Bagoo" />

            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div className="flex items-center gap-3 mb-8">
                    <div className="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                        <Package className="w-5 h-5" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 tracking-tight">My Orders & Tracking</h1>
                        <p className="text-xs text-slate-500">Live order status and dispatch history</p>
                    </div>
                </div>

                {orders.data.length === 0 ? (
                    <div className="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-4 max-w-lg mx-auto">
                        <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                        <h3 className="text-base font-bold text-slate-800">You haven't placed any orders yet</h3>
                        <p className="text-xs text-slate-500">Browse our product catalog to make your first purchase!</p>
                        <Link
                            href={route('marketplace')}
                            className="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white text-xs font-bold rounded-xl"
                        >
                            Shop Catalog
                        </Link>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {orders.data.map((order) => (
                            <div
                                key={order.id}
                                className="bg-white rounded-3xl border border-slate-200 p-6 hover:shadow-md transition space-y-4"
                            >
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 pb-4 border-b border-slate-100">
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono font-bold text-sm text-slate-900">
                                                #{order.order_number}
                                            </span>
                                            <span className="text-xs text-slate-400">
                                                • {new Date(order.created_at).toLocaleDateString()}
                                            </span>
                                        </div>
                                        {order.delivery && (
                                            <p className="text-xs text-indigo-600 font-mono font-semibold flex items-center gap-1">
                                                <Truck className="w-3.5 h-3.5" />
                                                Tracking: {order.delivery.tracking_number}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-3">
                                        {getStatusPill(order.status)}
                                        <Link
                                            href={route('orders.show', order.id)}
                                            className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-xl flex items-center gap-1.5 transition"
                                        >
                                            <span>View Details</span>
                                            <ArrowRight className="w-3.5 h-3.5" />
                                        </Link>
                                    </div>
                                </div>

                                {/* Order Items Preview */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                    {order.items?.map((item) => (
                                        <div key={item.id} className="flex items-center gap-3 p-2 rounded-2xl bg-slate-50 border border-slate-100">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt={item.product?.name || ''}
                                                className="w-12 h-12 rounded-xl object-cover bg-white"
                                            />
                                            <div className="truncate text-xs">
                                                <p className="font-bold text-slate-800 truncate">{item.product?.name}</p>
                                                <p className="text-slate-400">Qty: {item.quantity} × ${Number(item.unit_price).toFixed(2)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="pt-2 flex justify-between items-center text-xs">
                                    <span className="text-slate-500">
                                        Payment: <span className="font-bold uppercase text-slate-700">{order.payment_method}</span> ({order.payment_status})
                                    </span>
                                    <span className="font-black text-sm text-slate-900">
                                        Total: ${Number(order.total_amount).toFixed(2)}
                                    </span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </MarketplaceLayout>
    );
}
