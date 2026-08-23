import React, { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
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
    Check,
    Star,
    MessageSquare,
    Store,
    X
} from 'lucide-react';

interface Props {
    order: Order;
}

export default function BuyerOrderDetail({ order }: Props) {
    const delivery = order.delivery;
    const [reviewModalOpen, setReviewModalOpen] = useState(false);
    const [selectedProductId, setSelectedProductId] = useState<number | null>(order.items?.[0]?.product_id || null);

    const { data, setData, post, processing, reset, recentlySuccessful } = useForm({
        product_id: selectedProductId,
        order_id: order.id,
        rating: 5,
        comment: '',
    });

    const submitReview = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.reviews.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setTimeout(() => setReviewModalOpen(false), 1500);
            }
        });
    };

    // 5-Stage Shopee Delivery Milestones
    const steps = [
        { key: 'placed', label: 'Order Placed', done: true, subtext: 'Payment Verified' },
        { key: 'packaging', label: 'Merchant Packaging', done: ['processing', 'ready_for_pickup', 'shipped', 'delivered'].includes(order.status), subtext: 'Prepared by Shop' },
        { key: 'pickup', label: 'Courier Picked Up', done: ['shipped', 'delivered'].includes(order.status) || ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || ''), subtext: 'Handed to Dispatch' },
        { key: 'in_transit', label: 'In Transit', done: ['shipped', 'delivered'].includes(order.status) || ['in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || ''), subtext: 'On Route to Destination' },
        { key: 'delivered', label: 'Delivered', done: order.status === 'delivered' || delivery?.status === 'delivered', subtext: 'Received at Doorstep' },
    ];

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
            <Head title={`Order #${order.order_number} Details — BagooPH`} />

            <div className="max-w-5xl mx-auto space-y-6">
                
                {/* Header & Back Link */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 pb-4 border-b border-slate-200">
                    <div>
                        <Link href={route('buyer.orders.index')} className="text-xs text-slate-500 hover:text-[#E00D42] flex items-center gap-1 mb-1 font-mono uppercase">
                            <ArrowLeft className="w-3.5 h-3.5" />
                            <span>Back to My Orders</span>
                        </Link>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            Order #{order.order_number}
                        </h1>
                        <p className="text-xs text-slate-500 font-mono">
                            Placed on {new Date(order.created_at).toLocaleString()}
                        </p>
                    </div>

                    <div className="flex items-center gap-3 font-mono text-xs">
                        {order.status === 'delivered' && (
                            <button
                                type="button"
                                onClick={() => setReviewModalOpen(true)}
                                className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-lg uppercase tracking-wider transition shadow-sm flex items-center gap-1.5"
                            >
                                <Star className="w-3.5 h-3.5" />
                                <span>Rate & Review Products</span>
                            </button>
                        )}
                    </div>
                </div>

                {/* 1. 5-STEP DELIVERY MILESTONE TRACKER */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <Truck className="w-4 h-4 text-[#E00D42]" />
                            <span className="font-bold text-slate-900 uppercase">Live Delivery Tracking Milestones</span>
                        </div>

                        {delivery && (
                            <span className="text-[#E00D42] font-bold">
                                Tracking No: {delivery.tracking_number}
                            </span>
                        )}
                    </div>

                    {/* Milestone Progress Bar */}
                    <div className="grid grid-cols-2 sm:grid-cols-5 gap-4 relative">
                        {steps.map((step, idx) => (
                            <div key={step.key} className="flex flex-col items-center text-center space-y-2 relative">
                                <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm shadow-2xs z-10 transition ${
                                    step.done ? 'bg-[#E00D42] text-white' : 'bg-slate-100 text-slate-400'
                                }`}>
                                    {step.done ? <Check className="w-5 h-5" /> : idx + 1}
                                </div>
                                <div>
                                    <h4 className={`text-xs font-bold ${step.done ? 'text-slate-900' : 'text-slate-400'}`}>
                                        {step.label}
                                    </h4>
                                    <p className="text-[10px] text-slate-400 font-mono">{step.subtext}</p>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Driver Notes & Dispatch Status */}
                    {delivery && (
                        <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 text-xs font-mono">
                            <div className="space-y-1">
                                <p className="font-bold text-slate-900">
                                    Courier Logistics Partner: <span className="text-[#E00D42]">{delivery.logistics_partner}</span>
                                </p>
                                <p className="text-slate-600 text-[11px]">
                                    {delivery.courier_notes || 'Package loaded securely on regional dispatcher vehicle.'}
                                </p>
                            </div>

                            {delivery.estimated_delivery_at && (
                                <div className="text-right sm:border-l sm:border-slate-200 sm:pl-4">
                                    <span className="text-[10px] text-slate-400 uppercase block">Est. Arrival</span>
                                    <span className="text-emerald-600 font-bold">
                                        {new Date(delivery.estimated_delivery_at).toLocaleDateString()}
                                    </span>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* 2. DELIVERY DESTINATION & RECIPIENT */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-3 font-mono text-xs">
                        <div className="flex items-center gap-2 pb-2 border-b border-slate-100 font-bold text-slate-900 uppercase">
                            <MapPin className="w-4 h-4 text-[#E00D42]" />
                            <span>Delivery Address</span>
                        </div>
                        <div className="space-y-1 text-slate-700 font-sans">
                            <p className="font-bold text-slate-900">{order.recipient_name}</p>
                            <p className="text-slate-500 font-mono text-xs">{order.recipient_phone}</p>
                            <p className="text-xs text-slate-600 pt-1">
                                {order.shipping_address}, {order.shipping_city} {order.shipping_postal_code}
                            </p>
                            {order.notes && (
                                <p className="text-xs text-amber-700 bg-amber-50 p-2 rounded-lg mt-2 font-mono">
                                    Notes: "{order.notes}"
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-3 font-mono text-xs">
                        <div className="flex items-center gap-2 pb-2 border-b border-slate-100 font-bold text-slate-900 uppercase">
                            <ShieldCheck className="w-4 h-4 text-emerald-600" />
                            <span>Payment & Security</span>
                        </div>
                        <div className="space-y-2 text-slate-700">
                            <div className="flex justify-between">
                                <span>Payment Mode:</span>
                                <span className="font-bold uppercase text-slate-900">{order.payment_method}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Payment Status:</span>
                                <span className="font-bold uppercase text-emerald-600">{order.payment_status}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Subtotal:</span>
                                <span>{formatPrice(order.subtotal)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Shipping:</span>
                                <span>{formatPrice(order.shipping_fee)}</span>
                            </div>
                            <div className="flex justify-between pt-2 border-t border-slate-100 font-bold text-sm text-slate-900">
                                <span>Total Paid:</span>
                                <span className="text-[#E00D42]">{formatPrice(order.total_amount)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 3. ORDERED PRODUCTS */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono text-xs font-bold text-slate-900 uppercase">
                        <span>Ordered Items ({order.items?.length ?? 0})</span>
                        <span>Amount</span>
                    </div>

                    <div className="divide-y divide-slate-100">
                        {order.items?.map((item) => (
                            <div key={item.id} className="py-3 flex items-center justify-between gap-4 text-xs font-sans">
                                <div className="flex items-center gap-3 min-w-0">
                                    <img
                                        src={item.product?.featured_image || ''}
                                        alt={item.product?.name}
                                        className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                    />
                                    <div className="truncate space-y-0.5">
                                        <p className="font-bold text-slate-900 truncate">{item.product?.name}</p>
                                        <p className="text-slate-400 font-mono text-[11px]">
                                            Qty: {item.quantity} × {formatPrice(item.unit_price)}
                                        </p>
                                    </div>
                                </div>

                                <span className="font-bold font-mono text-slate-900 shrink-0">
                                    {formatPrice(Number(item.unit_price) * item.quantity)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* 4. RATING & REVIEW MODAL */}
                {reviewModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-xs flex items-center justify-center p-4">
                        <div className="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl space-y-4 font-sans animate-scale-in">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2">
                                    <Star className="w-5 h-5 text-amber-400 fill-amber-400" />
                                    <h3 className="font-bold text-slate-900 text-base">Rate Your Purchase</h3>
                                </div>
                                <button onClick={() => setReviewModalOpen(false)} className="text-slate-400 hover:text-slate-700">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <form onSubmit={submitReview} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                        Select Product:
                                    </label>
                                    <select
                                        value={data.product_id || ''}
                                        onChange={(e) => setData('product_id', Number(e.target.value))}
                                        className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42]"
                                    >
                                        {order.items?.map((item) => (
                                            <option key={item.product_id} value={item.product_id}>
                                                {item.product?.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-2">
                                        Rating Stars (1 to 5):
                                    </label>
                                    <div className="flex items-center gap-2">
                                        {[1, 2, 3, 4, 5].map((star) => (
                                            <button
                                                key={star}
                                                type="button"
                                                onClick={() => setData('rating', star)}
                                                className="p-1 focus:outline-hidden"
                                            >
                                                <Star className={`w-7 h-7 ${
                                                    star <= data.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-300'
                                                }`} />
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                        Your Review & Feedback:
                                    </label>
                                    <textarea
                                        value={data.comment}
                                        onChange={(e) => setData('comment', e.target.value)}
                                        rows={4}
                                        placeholder="Share details of the product quality, packaging, and courier speed..."
                                        className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42]"
                                        required
                                    />
                                </div>

                                {recentlySuccessful && (
                                    <p className="text-xs text-emerald-600 font-mono flex items-center gap-1">
                                        <Check className="w-4 h-4" /> Review submitted successfully!
                                    </p>
                                )}

                                <div className="flex items-center justify-end gap-3 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setReviewModalOpen(false)}
                                        className="px-4 py-2 rounded-lg text-xs font-bold text-slate-600 hover:bg-slate-100 font-mono uppercase"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-5 py-2 rounded-lg text-xs font-bold bg-[#E00D42] hover:bg-[#C20836] text-white font-mono uppercase shadow-sm disabled:opacity-50"
                                    >
                                        {processing ? 'Submitting...' : 'Submit Review'}
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
