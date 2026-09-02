import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Order, PaginatedData } from '@/types';
import { 
    Package, 
    Truck, 
    ArrowRight, 
    Clock, 
    CheckCircle2, 
    ShoppingBag, 
    Store,
    MessageSquare,
    Star,
    Sparkles,
    Check
} from 'lucide-react';

interface Props {
    orders: PaginatedData<Order>;
}

export default function BuyerOrders({ orders }: Props) {
    const [selectedStatusTab, setSelectedStatusTab] = useState<string>('all');

    const getStatusPill = (status: string) => {
        switch (status) {
            case 'pending':
                return <span className="px-3 py-1 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-bold flex items-center gap-1"><Clock className="w-3.5 h-3.5" /> Order Placed (Pending Pack)</span>;
            case 'processing':
                return <span className="px-3 py-1 bg-blue-50 text-blue-700 border border-blue-200 rounded-full text-xs font-bold flex items-center gap-1"><Clock className="w-3.5 h-3.5" /> Merchant Packaging</span>;
            case 'ready_for_pickup':
                return <span className="px-3 py-1 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs font-bold flex items-center gap-1"><Clock className="w-3.5 h-3.5" /> Ready for Courier Pickup</span>;
            case 'shipped':
                return <span className="px-3 py-1 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-full text-xs font-bold flex items-center gap-1"><Truck className="w-3.5 h-3.5" /> In Transit</span>;
            case 'delivered':
                return <span className="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-xs font-bold flex items-center gap-1"><CheckCircle2 className="w-3.5 h-3.5" /> Order Completed</span>;
            default:
                return <span className="px-3 py-1 bg-slate-100 text-slate-700 rounded-full text-xs font-bold uppercase">{status}</span>;
        }
    };

    const filteredOrders = orders.data.filter(order => {
        if (selectedStatusTab === 'all') return true;
        if (selectedStatusTab === 'to_ship') return order.status === 'pending' || order.status === 'processing' || order.status === 'ready_for_pickup';
        if (selectedStatusTab === 'to_receive') return order.status === 'shipped';
        if (selectedStatusTab === 'completed') return order.status === 'delivered';
        return true;
    });

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    return (
        <BuyerLayout>
            <Head title="My Purchases & Order Tracking — BagooPH" />

            <div className="space-y-6">
                
                {/* Header */}
                <div className="flex items-center justify-between pb-4 border-b border-slate-200 font-sans">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold">
                            <Package className="w-5 h-5" />
                        </div>
                        <div>
                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">My Purchases & Orders</h1>
                            <p className="text-xs text-slate-500 font-sans mt-0.5">Live dispatch telemetry & past delivery history</p>
                        </div>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-xl text-xs font-bold transition shadow-xs"
                    >
                        Shop More
                    </Link>
                </div>

                {/* Order Status Tabs Strip */}
                <div className="bg-white rounded-2xl p-1.5 border border-slate-200 shadow-2xs flex items-center gap-1.5 overflow-x-auto scrollbar-none font-sans text-xs">
                    <button
                        onClick={() => setSelectedStatusTab('all')}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold transition text-center whitespace-nowrap cursor-pointer ${
                            selectedStatusTab === 'all' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        All Orders ({orders.data.length})
                    </button>
                    <button
                        onClick={() => setSelectedStatusTab('to_ship')}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold transition text-center whitespace-nowrap cursor-pointer ${
                            selectedStatusTab === 'to_ship' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        To Ship
                    </button>
                    <button
                        onClick={() => setSelectedStatusTab('to_receive')}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold transition text-center whitespace-nowrap cursor-pointer ${
                            selectedStatusTab === 'to_receive' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        To Receive
                    </button>
                    <button
                        onClick={() => setSelectedStatusTab('completed')}
                        className={`flex-1 py-2.5 px-4 rounded-xl font-bold transition text-center whitespace-nowrap cursor-pointer ${
                            selectedStatusTab === 'completed' ? 'bg-[#E00D42] text-white shadow-xs' : 'text-slate-700 hover:bg-slate-50'
                        }`}
                    >
                        Completed
                    </button>
                </div>

                {/* Orders List */}
                {filteredOrders.length === 0 ? (
                    <div className="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-4 max-w-lg mx-auto shadow-2xs font-sans">
                        <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                        <h3 className="text-base font-bold text-slate-800">No orders found in this category</h3>
                        <p className="text-xs text-slate-500">Explore products on BagooPH to start shopping!</p>
                        <Link
                            href={route('buyer.index')}
                            className="inline-flex items-center gap-2 px-6 py-2.5 bg-[#E00D42] text-white text-xs font-bold rounded-xl shadow-xs hover:bg-[#C20836] transition cursor-pointer"
                        >
                            Explore Marketplace
                        </Link>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {filteredOrders.map((order) => (
                            <div
                                key={order.id}
                                className="bg-white rounded-3xl border border-slate-200 p-6 shadow-2xs hover:shadow-md transition space-y-4 font-sans"
                            >
                                {/* Order Header */}
                                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 pb-3 border-b border-slate-100 text-xs">
                                    <div className="space-y-0.5">
                                        <div className="flex items-center gap-2">
                                            <span className="font-bold text-slate-900 text-sm">
                                                Order #{order.order_number}
                                            </span>
                                            <span className="text-slate-400">
                                                • {new Date(order.created_at).toLocaleDateString()}
                                            </span>
                                        </div>
                                        {order.delivery && (
                                            <p className="text-indigo-600 font-semibold flex items-center gap-1.5 text-xs">
                                                <Truck className="w-3.5 h-3.5" />
                                                Courier Tracking: <span className="font-mono">{order.delivery.tracking_number}</span> ({order.delivery.logistics_partner})
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex items-center gap-3">
                                        {getStatusPill(order.status)}
                                    </div>
                                </div>

                                {/* Order Items Preview */}
                                <div className="divide-y divide-slate-100">
                                    {order.items?.map((item) => (
                                        <div key={item.id} className="py-3 flex items-center justify-between gap-4 text-xs">
                                            <div className="flex items-center gap-3.5 min-w-0">
                                                <img
                                                    src={item.product?.featured_image || ''}
                                                    alt={item.product?.name}
                                                    className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                                />
                                                <div className="truncate space-y-0.5">
                                                    <p className="font-semibold text-slate-900 truncate text-xs">{item.product?.name}</p>
                                                    <p className="text-slate-400 text-[11px]">
                                                        Qty: {item.quantity} × {formatPrice(item.unit_price)}
                                                    </p>
                                                </div>
                                            </div>

                                            <span className="font-bold text-slate-900 shrink-0 text-xs">
                                                {formatPrice(Number(item.unit_price) * item.quantity)}
                                            </span>
                                        </div>
                                    ))}
                                </div>

                                {/* Order Bottom Actions */}
                                <div className="pt-3 border-t border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 text-xs font-sans">
                                    <div className="text-slate-500">
                                        Payment: <span className="font-semibold text-slate-800">{order.payment_method.toUpperCase()}</span> ({order.payment_status})
                                    </div>

                                    <div className="flex items-center gap-4">
                                        <div className="text-right">
                                            <span className="text-slate-400 text-[10px] uppercase block font-semibold">Order Total</span>
                                            <span className="font-black text-base text-[#E00D42]">
                                                {formatPrice(order.total_amount)}
                                            </span>
                                        </div>

                                        <Link
                                            href={route('buyer.orders.show', order.id)}
                                            className="px-4 py-2.5 bg-slate-900 hover:bg-[#E00D42] text-white text-xs font-bold rounded-xl flex items-center gap-1.5 transition shadow-2xs"
                                        >
                                            <span>View Details & Timeline</span>
                                            <ArrowRight className="w-3.5 h-3.5" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
