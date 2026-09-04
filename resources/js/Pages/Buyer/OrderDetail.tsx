import React, { useState, useEffect } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
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

    useEffect(() => {
        if (reviewModalOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'unset';
        }
        return () => {
            document.body.style.overflow = 'unset';
        };
    }, [reviewModalOpen]);

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

    // Canonical 13-Stage Bagoo Delivery Milestones
    const isPacked = ['preparing', 'processing', 'ready_for_pickup', 'picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'delivered', 'completed'].includes(order.status);
    const isPickedUp = ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'delivered', 'completed'].includes(order.status) || ['picked_up', 'at_sorting_center', 'sorted', 'assigned_to_rider', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || '');
    const isInTransit = ['at_sorting_center', 'sorted', 'assigned_to_rider', 'out_for_delivery', 'shipped', 'delivered', 'completed'].includes(order.status) || ['at_sorting_center', 'sorted', 'assigned_to_rider', 'in_transit', 'out_for_delivery', 'delivered'].includes(delivery?.status || '');
    const isDelivered = ['delivered', 'completed'].includes(order.status) || delivery?.status === 'delivered';
    const isCompleted = order.status === 'completed';

    // Determine the exact active next step index (0 to 4)
    let nextStepIndex = 1;
    if (isDelivered) {
        nextStepIndex = -1; // All complete
    } else if (isInTransit) {
        nextStepIndex = 4; // Delivered
    } else if (isPickedUp) {
        nextStepIndex = 3; // In Transit
    } else if (isPacked) {
        nextStepIndex = 2; // Courier Picked Up
    } else {
        nextStepIndex = 1; // Merchant Packaging
    }

    const steps = [
        { 
            key: 'placed', 
            label: 'Order Placed', 
            done: true, 
            isNext: false,
            subtext: order.payment_status === 'paid' ? 'Payment Verified' : 'Order Placed (COD)' 
        },
        { 
            key: 'packaging', 
            label: 'Merchant Packaging', 
            done: isPacked, 
            isNext: nextStepIndex === 1,
            subtext: isPacked ? 'Prepared by Shop' : 'Waiting for Merchant' 
        },
        { 
            key: 'pickup', 
            label: 'Courier Picked Up', 
            done: isPickedUp, 
            isNext: nextStepIndex === 2,
            subtext: isPickedUp ? 'Handed to Dispatch' : 'Waiting for Courier' 
        },
        { 
            key: 'in_transit', 
            label: 'In Transit', 
            done: isInTransit, 
            isNext: nextStepIndex === 3,
            subtext: isInTransit ? 'On Route to Destination' : 'Pending Transit' 
        },
        { 
            key: 'delivered', 
            label: 'Delivered', 
            done: isDelivered, 
            isNext: nextStepIndex === 4,
            subtext: isDelivered ? 'Received at Doorstep' : 'Doorstep Drop-off' 
        },
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
                        <Link href={route('buyer.orders.index')} className="text-xs text-slate-500 hover:text-[#E00D42] flex items-center gap-1.5 mb-1 font-semibold transition">
                            <ArrowLeft className="w-4 h-4" />
                            <span>Back to My Purchases</span>
                        </Link>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">
                            Order #{order.order_number}
                        </h1>
                        <p className="text-xs text-slate-500 font-sans mt-0.5">
                            Placed on {new Date(order.created_at).toLocaleString()}
                        </p>
                    </div>

                    <div className="flex items-center gap-3 font-sans text-xs">
                        {order.status === 'delivered' && (
                            <button
                                type="button"
                                onClick={() => {
                                    if (confirm('Confirm that you have received your order in good condition?')) {
                                        router.post(route('buyer.orders.confirm', order.id), {}, { preserveScroll: true });
                                    }
                                }}
                                className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center gap-2 cursor-pointer"
                            >
                                <CheckCircle2 className="w-4 h-4" />
                                <span>Confirm Order Received</span>
                            </button>
                        )}
                        {(order.status === 'delivered' || order.status === 'completed') && (
                            <button
                                type="button"
                                onClick={() => setReviewModalOpen(true)}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center gap-2 cursor-pointer"
                            >
                                <Star className="w-4 h-4 fill-white" />
                                <span>Rate & Upload Photos</span>
                            </button>
                        )}
                        {order.status === 'completed' && (
                            <span className="px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold rounded-xl flex items-center gap-1.5">
                                <Check className="w-4 h-4 text-emerald-600" />
                                <span>Order Completed</span>
                            </span>
                        )}
                    </div>
                </div>

                {/* 1. 5-STEP DELIVERY MILESTONE TRACKER */}
                <div className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200 shadow-xs space-y-6">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100 text-xs">
                        <div className="flex items-center gap-2">
                            <Truck className="w-4 h-4 text-[#E00D42]" />
                            <span className="font-bold text-slate-900 text-sm">Live Delivery Tracking Milestones</span>
                        </div>

                        {delivery && (
                            <span className="text-[#E00D42] font-bold font-mono text-xs">
                                Tracking No: {delivery.tracking_number}
                            </span>
                        )}
                    </div>

                    {/* Milestone Progress Tracker with Connecting Lines & Active Yellow/Amber Next Step */}
                    <div className="relative py-2 font-sans">
                        <div className="grid grid-cols-1 sm:grid-cols-5 gap-6 sm:gap-2 relative">
                            {steps.map((step, idx) => {
                                const isNext = step.isNext;
                                const isDone = step.done;
                                return (
                                    <div key={step.key} className="flex sm:flex-col items-center sm:text-center gap-4 sm:gap-2 relative group">
                                        
                                        {/* Horizontal Connecting Line (Desktop) */}
                                        {idx < steps.length - 1 && (
                                            <div className="hidden sm:block absolute top-5 left-1/2 w-full h-0.5 z-0">
                                                <div className={`w-full h-full ${
                                                    steps[idx + 1].done
                                                        ? 'bg-[#E00D42]'
                                                        : steps[idx + 1].isNext
                                                        ? 'border-t-2 border-dashed border-amber-400'
                                                        : 'border-t-2 border-dashed border-slate-200'
                                                }`} />
                                            </div>
                                        )}

                                        {/* Step Circle */}
                                        <div className={`w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs shrink-0 transition relative z-10 ${
                                            isDone
                                                ? 'bg-[#E00D42] text-white shadow-md'
                                                : isNext
                                                ? 'bg-amber-50 text-amber-800 border-2 border-amber-400 shadow-sm ring-4 ring-amber-100'
                                                : 'bg-slate-100 text-slate-400 border border-slate-200'
                                        }`}>
                                            {isDone ? (
                                                <Check className="w-5 h-5" />
                                            ) : isNext ? (
                                                <span className="font-bold text-amber-700">{idx + 1}</span>
                                            ) : (
                                                idx + 1
                                            )}

                                            {/* Pulsing Next Step Indicator Pill */}
                                            {isNext && (
                                                <span className="absolute -top-2.5 px-2 py-0.5 rounded-full bg-amber-500 text-white font-sans text-[10px] font-bold shadow-xs animate-pulse">
                                                    Next
                                                </span>
                                            )}
                                        </div>

                                        {/* Step Text Info */}
                                        <div className="flex flex-col sm:items-center">
                                            <span className={`text-xs font-bold ${
                                                isDone 
                                                    ? 'text-slate-900' 
                                                    : isNext 
                                                    ? 'text-amber-800 font-extrabold' 
                                                    : 'text-slate-400'
                                            }`}>
                                                {step.label}
                                            </span>
                                            <span className={`text-xs ${
                                                isNext ? 'text-amber-700 font-semibold' : 'text-slate-400'
                                            }`}>
                                                {step.subtext}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>

                {/* 2. DISPATCH & COURIER TELEMETRY */}
                {delivery && (
                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                        <div className="flex items-center gap-2 text-sm font-bold text-slate-900 pb-3 border-b border-slate-100">
                            <Truck className="w-4 h-4 text-indigo-600" />
                            <span>Assigned Courier Fleet & Logistics</span>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 font-sans text-xs">
                            <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[11px] font-semibold block">Courier Service</span>
                                <p className="font-bold text-slate-900 text-sm">{delivery.logistics_partner}</p>
                                <p className="text-indigo-600 font-semibold">{delivery.status.toUpperCase()}</p>
                            </div>

                            <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[11px] font-semibold block">Pickup Merchant Hub</span>
                                <p className="font-bold text-slate-900 text-sm">{delivery.pickup_store_name || 'Bagoo Prime Store'}</p>
                                <p className="text-slate-500 text-xs truncate">{delivery.pickup_address}</p>
                            </div>

                            <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[11px] font-semibold block">Recipient Destination</span>
                                <p className="font-bold text-slate-900 text-sm">{delivery.delivery_recipient_name}</p>
                                <p className="text-slate-500 text-xs truncate">{delivery.delivery_address}</p>
                            </div>
                        </div>

                        {delivery.courier_notes && (
                            <div className="p-3.5 bg-indigo-50/60 border border-indigo-100 rounded-xl text-xs font-sans text-indigo-950 flex items-start gap-2">
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
                    <div className="flex items-center gap-2 text-sm font-bold text-slate-900 pb-3 border-b border-slate-100">
                        <Package className="w-4 h-4 text-[#E00D42]" />
                        <span>Package Items</span>
                    </div>

                    <div className="divide-y divide-slate-100 font-sans">
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
                                        <p className="text-xs text-slate-500 font-sans">
                                            Quantity: {item.quantity} × {formatPrice(item.unit_price)}
                                        </p>
                                    </div>
                                </div>

                                <div className="text-sm font-bold text-slate-900">
                                    {formatPrice(Number(item.unit_price) * item.quantity)}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Subtotal / Shipping / Total */}
                    <div className="border-t border-slate-100 pt-4 font-sans text-xs space-y-2.5">
                        <div className="flex justify-between text-slate-600">
                            <span>Subtotal</span>
                            <span className="font-semibold text-slate-900">{formatPrice(order.subtotal)}</span>
                        </div>
                        <div className="flex justify-between text-slate-600">
                            <span>Shipping Fee</span>
                            <span className="font-semibold text-slate-900">{formatPrice(order.shipping_fee)}</span>
                        </div>
                        <div className="flex justify-between text-slate-900 font-bold text-sm pt-2.5 border-t border-slate-100">
                            <span>Total Amount</span>
                            <span className="text-[#E00D42] font-black text-lg">{formatPrice(order.total_amount)}</span>
                        </div>
                    </div>
                </div>

                {/* 4. RATE & REVIEW MODAL WITH PHOTO UPLOAD SUPPORT */}
                {reviewModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/75 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in overflow-y-auto">
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-5 font-sans my-auto">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2 text-slate-900 font-bold text-base">
                                    <Star className="w-5 h-5 text-amber-400 fill-amber-400" />
                                    <span>Rate & Review Purchased Items</span>
                                </div>
                                <button onClick={() => setReviewModalOpen(false)} className="text-slate-400 hover:text-slate-700 p-1 rounded-lg">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            <form onSubmit={submitReview} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Select Product to Review:
                                    </label>
                                    <select
                                        value={data.product_id || ''}
                                        onChange={(e) => setData('product_id', Number(e.target.value))}
                                        className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42]"
                                    >
                                        {order.items?.map((item) => (
                                            <option key={item.product_id} value={item.product_id}>
                                                {item.product?.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-2">
                                        Your Rating (1 to 5 Stars):
                                    </label>
                                    <div className="flex items-center gap-2">
                                        {[1, 2, 3, 4, 5].map((star) => (
                                            <button
                                                key={star}
                                                type="button"
                                                onClick={() => setData('rating', star)}
                                                className="p-1 focus:outline-hidden hover:scale-110 transition-transform cursor-pointer"
                                            >
                                                <Star className={`w-8 h-8 ${
                                                    star <= data.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-300'
                                                }`} />
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Detailed Review & Feedback:
                                    </label>
                                    <textarea
                                        value={data.comment}
                                        onChange={(e) => setData('comment', e.target.value)}
                                        rows={3}
                                        placeholder="Tell other buyers about product build, size fitting, and delivery condition..."
                                        className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42]"
                                        required
                                    />
                                </div>

                                {/* Multi-Image Upload Section */}
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1.5">
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
