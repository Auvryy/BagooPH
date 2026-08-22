import React from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Order } from '@/types';
import { 
    Package, 
    Truck, 
    ArrowLeft, 
    CheckCircle2, 
    Clock, 
    MapPin, 
    Phone, 
    ShieldCheck, 
    User,
    Check
} from 'lucide-react';

interface Props {
    order: Order;
}

export default function BuyerOrderDetail({ order }: Props) {
    const delivery = order.delivery;

    // Shipment steps
    const steps = [
        { key: 'placed', label: 'Order Placed', done: true, date: order.created_at },
        { key: 'packaging', label: 'Seller Packaging', done: ['processing', 'ready_for_pickup', 'shipped', 'delivered'].includes(order.status), date: null },
        { key: 'picked_up', label: 'Courier Picked Up', done: ['shipped', 'delivered'].includes(order.status) || ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || ''), date: delivery?.picked_up_at },
        { key: 'delivered', label: 'Delivered', done: order.status === 'delivered' || delivery?.status === 'delivered', date: delivery?.delivered_at },
    ];

    return (
        <MarketplaceLayout>
            <Head title={`Order #${order.order_number} — Bagoo`} />

            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 space-y-8">
                {/* Header & Back */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <Link href={route('orders.index')} className="text-xs text-slate-500 hover:text-indigo-600 flex items-center gap-1 mb-2">
                            <ArrowLeft className="w-3.5 h-3.5" />
                            <span>Back to My Orders</span>
                        </Link>
                        <h1 className="text-2xl font-black text-slate-900 tracking-tight">
                            Order #{order.order_number}
                        </h1>
                        <p className="text-xs text-slate-500">
                            Placed on {new Date(order.created_at).toLocaleString()}
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="px-3.5 py-1.5 rounded-full text-xs font-bold uppercase bg-indigo-50 text-indigo-700 border border-indigo-200">
                            {order.status.replace('_', ' ')}
                        </span>
                    </div>
                </div>

                {/* Delivery Tracker Progress */}
                {delivery && (
                    <div className="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 space-y-6">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900 flex items-center gap-2">
                                    <Truck className="w-4 h-4 text-indigo-600" />
                                    <span>Dispatch & Courier Tracking</span>
                                </h3>
                                <p className="text-xs text-slate-500 font-mono mt-0.5">
                                    Tracking #: {delivery.tracking_number} • Partner: {delivery.logistics_partner}
                                </p>
                            </div>
                            {delivery.courier && (
                                <div className="text-right text-xs">
                                    <span className="text-slate-400">Assigned Courier:</span>
                                    <p className="font-bold text-slate-800">{delivery.courier.name}</p>
                                </div>
                            )}
                        </div>

                        {/* Visual Progress Steps */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-2">
                            {steps.map((step, idx) => (
                                <div key={step.key} className="relative flex flex-col items-center text-center space-y-2">
                                    <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs transition ${
                                        step.done 
                                            ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30' 
                                            : 'bg-slate-100 text-slate-400 border border-slate-200'
                                    }`}>
                                        {step.done ? <Check className="w-4 h-4" /> : idx + 1}
                                    </div>
                                    <div>
                                        <p className={`text-xs font-bold ${step.done ? 'text-slate-900' : 'text-slate-400'}`}>
                                            {step.label}
                                        </p>
                                        {step.date && (
                                            <p className="text-[10px] text-slate-400">
                                                {new Date(step.date).toLocaleDateString()}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        {delivery.courier_notes && (
                            <div className="p-3 bg-amber-50 border border-amber-200 rounded-2xl text-xs text-amber-800">
                                <span className="font-bold">Courier Note:</span> {delivery.courier_notes}
                            </div>
                        )}
                    </div>
                )}

                {/* Details Breakdown Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {/* Left: Items */}
                    <div className="lg:col-span-8 bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                        <h3 className="font-bold text-sm text-slate-900 pb-3 border-b border-slate-100">
                            Purchased Items ({order.items?.length || 0})
                        </h3>

                        <div className="divide-y divide-slate-100 space-y-3">
                            {order.items?.map((item) => (
                                <div key={item.id} className="pt-3 first:pt-0 flex items-center justify-between gap-4">
                                    <div className="flex items-center gap-3">
                                        <img
                                            src={item.product?.featured_image || ''}
                                            alt={item.product?.name || ''}
                                            className="w-14 h-14 rounded-2xl object-cover bg-slate-100"
                                        />
                                        <div className="text-xs">
                                            <span className="text-[10px] text-slate-400 font-bold uppercase">
                                                {item.shop?.name || 'Seller'}
                                            </span>
                                            <p className="font-bold text-slate-800">{item.product?.name}</p>
                                            <p className="text-slate-500">Qty: {item.quantity} × ${Number(item.unit_price).toFixed(2)}</p>
                                        </div>
                                    </div>
                                    <span className="font-black text-sm text-slate-900">
                                        ${(Number(item.unit_price) * item.quantity).toFixed(2)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Right: Shipping Address & Totals */}
                    <div className="lg:col-span-4 space-y-6">
                        <div className="bg-white rounded-3xl border border-slate-200 p-6 space-y-4">
                            <h3 className="font-bold text-sm text-slate-900 pb-2 border-b border-slate-100 flex items-center gap-2">
                                <MapPin className="w-4 h-4 text-indigo-600" />
                                <span>Delivery Address</span>
                            </h3>
                            <div className="text-xs space-y-1 text-slate-600">
                                <p className="font-bold text-slate-900">{order.recipient_name}</p>
                                <p>{order.recipient_phone}</p>
                                <p>{order.shipping_address}</p>
                                <p>{order.shipping_city} {order.shipping_postal_code}</p>
                                {order.notes && (
                                    <p className="pt-2 text-[11px] text-slate-400 italic">"{order.notes}"</p>
                                )}
                            </div>
                        </div>

                        <div className="bg-white rounded-3xl border border-slate-200 p-6 space-y-3 text-xs">
                            <div className="flex justify-between text-slate-600">
                                <span>Subtotal</span>
                                <span className="font-bold text-slate-900">${Number(order.subtotal).toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600">
                                <span>Shipping Fee</span>
                                <span className="font-bold text-slate-900">${Number(order.shipping_fee).toFixed(2)}</span>
                            </div>
                            <div className="border-t border-slate-100 pt-3 flex justify-between text-sm font-bold text-slate-900">
                                <span>Total Paid</span>
                                <span className="text-lg font-black text-indigo-600">${Number(order.total_amount).toFixed(2)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </MarketplaceLayout>
    );
}
