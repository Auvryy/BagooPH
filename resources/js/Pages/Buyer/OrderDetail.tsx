import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import OrderTrackingPanel, { trackingDate } from '@/Components/OrderTrackingPanel';
import { claimWindow, nextClaimBoundary } from '@/utils/orderTracking';
import { Order, PageProps } from '@/types';
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
    canUsePortal: boolean;
    buyAgainUrl?: string | null;
    canConfirmReceipt: boolean;
    pickupClaim: { reference: string; status: string; expires_at: string; code_issued_at: string | null; locked_until: string | null } | null;
    pickupHub: {name: string; address: string; operating_hours: string | null} | null;
    pickupCodeUrl: string | null;
    pickupCodeAvailable?: boolean;
    orderNotices: Array<{id: string; data: {title: string; milestone: string}; read_at: string | null; created_at: string}>;
}

export default function BuyerOrderDetail({ order, canUsePortal, buyAgainUrl, canConfirmReceipt, pickupClaim, pickupHub, pickupCodeUrl, pickupCodeAvailable = false, orderNotices = [] }: Props) {
    const { auth } = usePage<PageProps>().props;
    const adminOversight = auth.user?.role === 'admin';
    const delivery = order.delivery;
    const [pickupCode,setPickupCode] = useState<string | null>(null);
    const [codeError,setCodeError] = useState('');
    const [codeBusy,setCodeBusy] = useState(false);
    const codePending = useRef(false);
    const [currentTime, setCurrentTime] = useState(Date.now);
    const counterWindow = pickupClaim ? claimWindow(pickupClaim, Math.max(currentTime, Date.now())) : 'unavailable';
    useEffect(() => {
        const delay = nextClaimBoundary(pickupClaim, Date.now());
        if (delay === null) return;
        const timer = window.setTimeout(() => setCurrentTime(Date.now()), delay);
        return () => window.clearTimeout(timer);
    }, [pickupClaim, currentTime]);
    useEffect(() => { setPickupCode(null); setCodeError(''); }, [order.id, pickupClaim?.reference]);
    const activeClaim = useRef({ orderId: order.id, reference: pickupClaim?.reference });
    activeClaim.current = { orderId: order.id, reference: pickupClaim?.reference };
    const [trackingRefreshing, setTrackingRefreshing] = useState(false);
    const [trackingError, setTrackingError] = useState('');
    const refreshPending = useRef(false);
    const refreshTracking = () => {
        if (refreshPending.current || codePending.current) return;
        refreshPending.current = true; setTrackingRefreshing(true); setTrackingError('');
        router.reload({ only: ['order', 'canUsePortal', 'buyAgainUrl', 'canConfirmReceipt', 'pickupClaim', 'pickupHub', 'pickupCodeUrl', 'pickupCodeAvailable', 'orderNotices', 'auth', 'flash'],
            onError: errors => setTrackingError(Object.values(errors).join(' ') || 'Tracking could not refresh. Try again.'),
            onFinish: () => { refreshPending.current = false; setTrackingRefreshing(false); },
        });
    };
    const showPickupCode = async () => {
        if (!pickupCodeUrl || codePending.current || refreshPending.current || !pickupCodeAvailable || !pickupClaim || claimWindow(pickupClaim, Date.now()) !== 'ready' || pickupClaim.code_issued_at || pickupCode) return;
        codePending.current = true;
        const requestedClaim = { orderId: order.id, reference: pickupClaim.reference };
        setCodeBusy(true); setCodeError('');
        try { const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(pickupCodeUrl,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':token,'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
            if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session may have changed. Refresh the page and sign in again if needed.');
            const result = await response.json(); if (!response.ok) throw new Error(response.status >= 500 ? 'Code access is temporarily unavailable. Refresh tracking and try again.' : result.message ?? 'Unable to show the pickup code.');
            if (typeof result.code !== 'string' || !result.code) throw new Error('The pickup code was not returned. Refresh tracking before trying again.');
            if (activeClaim.current.orderId === requestedClaim.orderId && activeClaim.current.reference === requestedClaim.reference) setPickupCode(result.code);
        } catch (error) { setCodeError(error instanceof Error ? error.message : 'Unable to show the pickup code.'); }
        finally { codePending.current = false; setCodeBusy(false); }
    };
    const [reviewModalOpen, setReviewModalOpen] = useState(false);
    const reviewableItems = (order.items ?? []).filter(item => !item.review);
    const [reviewSaved, setReviewSaved] = useState(false);
    const previewRef = useRef<string[]>([]);
    useEffect(() => () => previewRef.current.forEach(url => URL.revokeObjectURL(url)), []);
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
        order_item_id: number | null;
        rating: number;
        comment: string;
        images: File[];
    }>({
        order_item_id: reviewableItems[0]?.id ?? null,
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
            previewRef.current = [...previewRef.current, ...newPreviews];
            setPreviewImages(previewRef.current);
        }
    };

    const removeImage = (index: number) => {
        const updatedFiles = data.images.filter((_, i) => i !== index);
        URL.revokeObjectURL(previewImages[index]);
        const updatedPreviews = previewImages.filter((_, i) => i !== index);
        previewRef.current = updatedPreviews;
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
                previewRef.current.forEach(url => URL.revokeObjectURL(url));
                previewRef.current = [];
                setPreviewImages([]);
                setReviewSaved(true);
                setReviewModalOpen(false);
            }
        });
    };

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    return (
        <BuyerOrderAccessLayout canUsePortal={canUsePortal}>
            <Head title={`Order #${order.order_number} Details — BagooPH`} />
            {pickupClaim && <section aria-label="Your hub pickup" className="mb-5 space-y-3 rounded-xl border border-slate-300 bg-white p-4">
                <h2 className="font-bold text-slate-900">Your hub pickup</h2>
                <p className="text-sm text-slate-700">{pickupHub?.name ?? 'Hub details not recorded'} · {pickupHub?.address}</p>
                <p className="text-sm text-slate-700">{pickupHub?.operating_hours ?? 'Contact the hub for its actual operating hours.'}</p>
                {(counterWindow === 'ready' || counterWindow === 'locked') && <p className="text-sm text-slate-700">Collect before {trackingDate(pickupClaim.expires_at)} Philippine time. Bring your valid photo ID and exact COD payment.</p>}
                {counterWindow === 'expired' && <p role="status" className="text-sm font-semibold text-amber-900">The collection deadline has passed. Refresh tracking for the hub's return review.</p>}
                {counterWindow === 'locked' && <p role="status" className="text-sm font-semibold text-amber-900">Counter verification is locked until {trackingDate(pickupClaim.locked_until)} Philippine time. Refresh after that time.</p>}
                {counterWindow === 'unavailable' && <p className="text-sm font-semibold text-slate-700">{pickupClaim.status === 'collected' ? (order.status === 'completed' ? 'Collection and your receipt confirmation are recorded.' : 'Collection is recorded. Confirm receipt after checking your items.') : 'The recorded pickup state needs hub review.'}</p>}
                {counterWindow === 'ready' && pickupCodeAvailable && !pickupCode && !pickupClaim.code_issued_at && <><p className="text-sm text-slate-700">Your private code is shown once. Save it when it appears; it cannot be revealed again.</p><button disabled={codeBusy || trackingRefreshing} onClick={showPickupCode} className="rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{codeBusy ? 'Preparing code…' : 'Show my code once'}</button></>}
                {counterWindow === 'ready' && pickupCode && <p className="break-all rounded-lg border border-slate-300 p-3 text-lg font-bold" aria-live="polite">{pickupCode}</p>}
                {counterWindow === 'ready' && pickupClaim.code_issued_at && !pickupCode && <p className="text-sm text-slate-700">Your code was already shown. Use the code you saved; contact the hub if it was lost.</p>}
                {counterWindow === 'ready' && !pickupCodeAvailable && !pickupCode && !pickupClaim.code_issued_at && <p className="text-sm text-slate-700">Refresh tracking to check whether code access is available. Contact the hub if it remains unavailable.</p>}
                {codeError && <p role="alert" className="text-sm text-red-700">{codeError}</p>}
            </section>}
            {orderNotices.length > 0 && <section className="mb-5 rounded-lg border border-slate-300 bg-white p-4"><h2 className="font-bold">Order notices</h2><ul className="mt-3 space-y-2">{orderNotices.map(notice => <li key={notice.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 py-2 text-sm"><span>{notice.data.title} · {new Date(notice.created_at).toLocaleString('en-PH')}</span>{!notice.read_at && <button className="font-semibold text-[#E00D42]" onClick={() => router.patch(route('buyer.orders.notice-read',notice.id),{},{preserveScroll:true})}>Mark read</button>}</li>)}</ul></section>}


            <div className="w-full space-y-6">
                
                {/* Header & Back Link */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 pb-4 border-b border-slate-200">
                    <div className="min-w-0">
                        <Link href={route(adminOversight ? 'admin.dashboard' : 'buyer.orders.index')} className="text-xs text-slate-500 hover:text-[#E00D42] flex items-center gap-1.5 mb-1 font-semibold transition">
                            <ArrowLeft className="w-4 h-4" />
                            <span>{adminOversight ? 'Back to Admin' : 'Back to My Purchases'}</span>
                        </Link>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight break-words">
                            Order #{order.order_number}
                        </h1>
                        <p className="text-xs text-slate-500 font-sans mt-0.5">
                            Placed on {trackingDate(order.created_at)} · Philippine time
                        </p>
                    </div>

                    <div className="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-3 font-sans text-xs w-full sm:w-auto">
                        {canConfirmReceipt && (
                            <button
                                type="button"
                                onClick={() => {
                                    if (confirm('Confirm that you have received your order in good condition?')) {
                                        router.post(route('buyer.orders.confirm', order.id), {}, { preserveScroll: true });
                                    }
                                }}
                                className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <CheckCircle2 className="w-4 h-4" />
                                <span>Confirm Order Received</span>
                            </button>
                        )}
                        {canUsePortal && order.status === 'completed' && reviewableItems.length > 0 && (
                            <button
                                type="button"
                                onClick={() => { setData('order_item_id', reviewableItems[0]?.id ?? null); setReviewModalOpen(true); }}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <Star className="w-4 h-4 fill-white" />
                                <span>Rate & Upload Photos</span>
                            </button>
                        )}
                        {canUsePortal && buyAgainUrl && <Link href={buyAgainUrl} className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-bold text-[#E00D42] hover:bg-rose-50">Buy again</Link>}
                        {order.status === 'completed' && (
                            <span className="px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold rounded-xl flex items-center gap-1.5">
                                <Check className="w-4 h-4 text-emerald-600" />
                                <span>Order Completed</span>
                            </span>
                        )}
                    </div>
                </div>

                <OrderTrackingPanel tracking={order.tracking} onRefresh={refreshTracking} refreshing={trackingRefreshing || codeBusy} error={trackingError} />

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
                                <p className="text-indigo-600 font-semibold">{order.tracking?.stage_label ?? 'Parcel stage not loaded'}</p>
                            </div>

                            <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                                <span className="text-slate-400 text-[11px] font-semibold block">Seller pickup</span>
                                <p className="font-bold text-slate-900 text-sm">{delivery.pickup_store_name || 'Seller name not recorded'}</p>
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
                                <div className="flex items-center gap-4 min-w-0 w-full sm:w-auto">
                                    <img
                                        src={item.product?.featured_image || ''}
                                        alt={item.product?.name}
                                        className="w-16 h-16 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                    />
                                    <div className="space-y-1 min-w-0">
                                        <Link 
                                            href={route('buyer.products.show', item.product?.slug || '')}
                                            className="font-bold text-sm text-slate-900 hover:text-[#E00D42] transition break-words"
                                        >
                                            {item.product?.name}
                                        </Link>
                                        <p className="text-xs text-slate-500 font-sans">
                                            Quantity: {item.quantity} × {formatPrice(item.unit_price)}
                                        </p>
                                        {item.review && canUsePortal && <p className="text-xs font-semibold text-emerald-700">Review saved</p>}
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

                {reviewSaved && <p role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-700">Your purchase review has been saved.</p>}

                {/* 4. RATE & REVIEW MODAL WITH PHOTO UPLOAD SUPPORT */}
                {canUsePortal && reviewModalOpen && (
                    <div className="fixed inset-0 z-50 bg-black/75 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in overflow-y-auto">
                        <div className="bg-white rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl border border-slate-200 space-y-5 font-sans my-auto max-h-[92vh] overflow-y-auto">
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
                                {Object.keys(errors).length > 0 && <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-xs text-rose-700">{Object.values(errors).map((error, index) => <p key={index}>{error}</p>)}</div>}
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 mb-1.5">
                                        Select Purchased Item to Review:
                                    </label>
                                    <select
                                        value={data.order_item_id || ''}
                                        onChange={(e) => setData('order_item_id', Number(e.target.value))}
                                        className="w-full px-3.5 py-2.5 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42]"
                                    >
                                        {reviewableItems.map((item) => (
                                            <option key={item.id} value={item.id}>
                                                {item.product?.name} — Item #{item.id}{item.color ? ` · ${item.color}` : ''}{item.size ? ` · ${item.size}` : ''}
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
                                        maxLength={1000}
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
                                                <span className="text-[9px] font-sans font-bold uppercase">+ Add</span>
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    multiple
                                                    onChange={handleImageChange}
                                                    className="hidden"
                                                />
                                            </label>
                                        )}
                                    </div>
                                </div>

                                {recentlySuccessful && (
                                    <p className="text-xs text-emerald-600 font-sans font-bold flex items-center gap-1.5 p-2 bg-emerald-50 rounded-xl border border-emerald-200">
                                        <Check className="w-4 h-4" /> Review & photos submitted successfully!
                                    </p>
                                )}

                                <div className="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                                    <button
                                        type="button"
                                        onClick={() => setReviewModalOpen(false)}
                                        className="px-4 py-2.5 rounded-xl text-xs font-bold text-slate-600 hover:bg-slate-100 font-sans uppercase"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="px-6 py-2.5 rounded-xl text-xs font-bold bg-[#E00D42] hover:bg-[#C20836] text-white font-sans uppercase shadow-md disabled:opacity-50 flex items-center gap-1.5"
                                    >
                                        {processing ? 'Submitting...' : 'Post Verified Review'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>
        </BuyerOrderAccessLayout>
    );
}
