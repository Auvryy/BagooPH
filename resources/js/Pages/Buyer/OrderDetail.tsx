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
    X,
    Camera,
    Upload,
    Sparkles,
    Image as ImageIcon
} from 'lucide-react';

interface Props {
    order: Order;
}

export default function BuyerOrderDetail({ order }: Props) {
    const delivery = order.delivery;
    const [reviewModalOpen, setReviewModalOpen] = useState(false);
    const [selectedProductId, setSelectedProductId] = useState<number | null>(order.items?.[0]?.product_id || null);
    const [previewImages, setPreviewImages] = useState<string[]>([]);

    const { data, setData, post, processing, reset, recentlySuccessful, errors } = useForm<{
        product_id: number | null;
        order_id: number;
        rating: number;
        comment: string;
        images: File[];
    }>({
        product_id: selectedProductId,
        order_id: order.id,
        rating: 5,
        comment: '',
        images: [],
    });

    const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files) {
            const filesArray = Array.from(e.target.files).slice(0, 5 - data.images.length);
            const newFiles = [...data.images, ...filesArray];
            setData('images', newFiles);

            // Generate image preview URLs
            const newPreviews = filesArray.map(file => URL.createObjectURL(file));
            setPreviewImages(prev => [...prev, ...newPreviews]);
        }
    };

    const removeImage = (index: number) => {
        const updatedFiles = data.images.filter((_, i) => i !== index);
        const updatedPreviews = previewImages.filter((_, i) => i !== index);
        setData('images', updatedFiles);
        setPreviewImages(updatedPreviews);
    };

    const submitReview = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('buyer.reviews.store'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setPreviewImages([]);
                setTimeout(() => setReviewModalOpen(false), 2000);
            }
        });
    };

    // 5-Stage Bagoo Express Delivery Milestones
    const isPacked = ['processing', 'ready_for_pickup', 'shipped', 'delivered'].includes(order.status);
    const isPickedUp = ['shipped', 'delivered'].includes(order.status) || ['picked_up', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || '');
    const isInTransit = (order.status === 'shipped' || order.status === 'delivered') && ['in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || '');
    const isDelivered = order.status === 'delivered' || delivery?.status === 'delivered';

    const steps = [
        { key: 'placed', label: 'Order Placed', done: true, subtext: order.payment_status === 'paid' ? 'Payment Verified' : 'Order Placed (COD)' },
        { key: 'packaging', label: 'Merchant Packaging', done: isPacked, subtext: isPacked ? 'Prepared by Shop' : 'Waiting for Merchant' },
        { key: 'pickup', label: 'Courier Picked Up', done: isPickedUp, subtext: isPickedUp ? 'Handed to Dispatch' : 'Waiting for Courier' },
        { key: 'in_transit', label: 'In Transit', done: isInTransit, subtext: isInTransit ? 'On Route to Destination' : 'Pending Transit' },
        { key: 'delivered', label: 'Delivered', done: isDelivered, subtext: isDelivered ? 'Received at Doorstep' : 'Doorstep Drop-off' },
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
                            <span>Back to My Purchases</span>
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
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl uppercase tracking-wider transition shadow-sm flex items-center gap-2"
                            >
                                <Star className="w-4 h-4 fill-white" />
                                <span>Rate & Upload Photos</span>
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
                                <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs transition ${
                                    step.done ? 'bg-[#E00D42] text-white shadow-md' : 'bg-slate-100 text-slate-400 border border-slate-200'
                                }`}>
                                    {step.done ? <Check className="w-5 h-5" /> : idx + 1}
                                </div>
                                <span className={`text-xs font-bold ${step.done ? 'text-slate-900' : 'text-slate-400'}`}>
                                    {step.label}
                                </span>
                                <span className="text-[10px] text-slate-400 font-mono">
                                    {step.subtext}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* 2. DISPATCH & COURIER TELEMETRY */}
                {delivery && (
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                        <div className="flex items-center gap-2 text-xs font-bold text-slate-900 font-mono uppercase pb-3 border-b border-slate-100">
                            <Truck className="w-4 h-4 text-indigo-600" />
                            <span>Assigned Courier Fleet & Logistics</span>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 font-mono text-xs">
                            <div className="p-3 bg-slate-50 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase block">Courier Service</span>
                                <p className="font-bold text-slate-900">{delivery.logistics_partner}</p>
                                <p className="text-indigo-600 font-bold">{delivery.status.toUpperCase()}</p>
                            </div>

                            <div className="p-3 bg-slate-50 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase block">Pickup Merchant Hub</span>
                                <p className="font-bold text-slate-900">{delivery.pickup_store_name || 'Bagoo Prime Store'}</p>
                                <p className="text-slate-500 text-[11px] truncate">{delivery.pickup_address}</p>
                            </div>

                            <div className="p-3 bg-slate-50 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase block">Recipient Address</span>
                                <p className="font-bold text-slate-900">{delivery.delivery_recipient_name}</p>
                                <p className="text-slate-500 text-[11px] truncate">{delivery.delivery_address}</p>
                            </div>
                        </div>

                        {delivery.courier_notes && (
                            <div className="p-3 bg-indigo-50/60 border border-indigo-100 rounded-xl text-xs font-mono text-indigo-950 flex items-start gap-2">
                                <MessageSquare className="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                                <div>
                                    <span className="font-bold">Courier Dispatch Notes: </span>
                                    <span>{delivery.courier_notes}</span>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* 3. ORDER ITEMS BREAKDOWN */}
                <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                    <div className="flex items-center gap-2 text-xs font-bold text-slate-900 font-mono uppercase pb-3 border-b border-slate-100">
                        <Package className="w-4 h-4 text-[#E00D42]" />
                        <span>Package Items</span>
                    </div>

                    <div className="divide-y divide-slate-100">
                        {order.items?.map((item) => (
                            <div key={item.id} className="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                <div className="flex items-center gap-4">
                                    <img
                                        src={item.product?.featured_image || ''}
                                        alt={item.product?.name}
                                        className="w-16 h-16 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                    />
                                    <div className="space-y-1">
                                        <Link 
                                            href={route('buyer.products.show', item.product?.slug || '')}
                                            className="font-bold text-sm text-slate-900 hover:text-[#E00D42] transition"
                                        >
                                            {item.product?.name}
                                        </Link>
                                        <p className="text-xs font-mono text-slate-500">
                                            Quantity: {item.quantity} × {formatPrice(item.unit_price)}
                                        </p>
                                    </div>
                                </div>

                                <div className="font-mono text-sm font-black text-slate-900">
                                    {formatPrice(Number(item.unit_price) * item.quantity)}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Subtotal / Shipping / Total */}
                    <div className="border-t border-slate-100 pt-4 font-mono text-xs space-y-2">
                        <div className="flex justify-between text-slate-500">
                            <span>Subtotal</span>
                            <span>{formatPrice(order.subtotal)}</span>
                        </div>
                        <div className="flex justify-between text-slate-500">
                            <span>Shipping Fee</span>
                            <span>{formatPrice(order.shipping_fee)}</span>
                        </div>
                        <div className="flex justify-between text-slate-900 font-bold text-sm pt-2 border-t border-slate-100">
                            <span>Total Amount</span>
                            <span className="text-[#E00D42] font-black text-base">{formatPrice(order.total_amount)}</span>
                        </div>
                    </div>
                </div>

                {/* 4. RATE & REVIEW MODAL WITH PHOTO UPLOAD SUPPORT */}
                {reviewModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in">
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-5 font-sans">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2 text-slate-900 font-black text-base">
                                    <Star className="w-5 h-5 text-amber-400 fill-amber-400" />
                                    <span>Rate & Review Purchased Items</span>
                                </div>
                                <button onClick={() => setReviewModalOpen(false)} className="text-slate-400 hover:text-slate-700">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <form onSubmit={submitReview} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                        Select Product to Review:
                                    </label>
                                    <select
                                        value={data.product_id || ''}
                                        onChange={(e) => setData('product_id', Number(e.target.value))}
                                        className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-1 focus:ring-[#E00D42]"
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
                                        Your Rating (1 to 5 Stars):
                                    </label>
                                    <div className="flex items-center gap-2">
                                        {[1, 2, 3, 4, 5].map((star) => (
                                            <button
                                                key={star}
                                                type="button"
                                                onClick={() => setData('rating', star)}
                                                className="p-1 focus:outline-hidden hover:scale-110 transition-transform"
                                            >
                                                <Star className={`w-8 h-8 ${
                                                    star <= data.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-300'
                                                }`} />
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                        Detailed Review & Feedback:
                                    </label>
                                    <textarea
                                        value={data.comment}
                                        onChange={(e) => setData('comment', e.target.value)}
                                        rows={3}
                                        placeholder="Tell other buyers about product build, size fitting, and delivery condition..."
                                        className="w-full px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-1 focus:ring-[#E00D42]"
                                        required
                                    />
                                </div>

                                {/* Multi-Image Upload Section */}
                                <div>
                                    <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                        Attach Product Photos (Up to 5 Photos):
                                    </label>
                                    
                                    {/* Upload Trigger & Previews */}
                                    <div className="flex flex-wrap gap-2.5 items-center">
                                        {previewImages.map((src, idx) => (
                                            <div key={idx} className="relative w-16 h-16 rounded-xl overflow-hidden border border-slate-200 group">
                                                <img src={src} alt="Upload preview" className="w-full h-full object-cover" />
                                                <button
                                                    type="button"
                                                    onClick={() => removeImage(idx)}
                                                    className="absolute top-1 right-1 w-5 h-5 rounded-full bg-black/70 text-white flex items-center justify-center text-xs opacity-0 group-hover:opacity-100 transition"
                                                >
                                                    <X className="w-3 h-3" />
                                                </button>
                                            </div>
                                        ))}

                                        {data.images.length < 5 && (
                                            <label className="w-16 h-16 rounded-xl border-2 border-dashed border-slate-300 hover:border-[#E00D42] bg-slate-50 hover:bg-slate-100 flex flex-col items-center justify-center cursor-pointer transition text-slate-400 hover:text-[#E00D42]">
                                                <Camera className="w-5 h-5 mb-0.5" />
                                                <span className="text-[9px] font-mono font-bold uppercase">+ Add</span>
                                                <input
                                                    type="file"
                                                    accept="image/*"
                                                    multiple
                                                    onChange={handleImageChange}
                                                    className="hidden"
                                                />
                                            </label>
                                        )}
                                    </div>
                                </div>

                                {recentlySuccessful && (
                                    <p className="text-xs text-emerald-600 font-mono font-bold flex items-center gap-1.5 p-2 bg-emerald-50 rounded-xl border border-emerald-200">
                                        <Check className="w-4 h-4" /> Review & photos submitted successfully!
                                    </p>
                                )}

                                <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setReviewModalOpen(false)}
                                        className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 font-mono uppercase"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-6 py-2.5 rounded-xl text-xs font-bold bg-[#E00D42] hover:bg-[#C20836] text-white font-mono uppercase shadow-md disabled:opacity-50 flex items-center gap-1.5"
                                    >
                                        {processing ? 'Submitting...' : 'Post Verified Review'}
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
