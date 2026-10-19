import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import BuyerOrderPackage from '@/Components/BuyerOrderPackage';
import BuyerCancelOrder from '@/Components/BuyerCancelOrder';
import BuyAgainButton from '@/Components/BuyAgainButton';
import OrderTrackingPanel, { trackingDate } from '@/Components/OrderTrackingPanel';
import { claimWindow, nextClaimBoundary } from '@/utils/orderTracking';
import { Order, PageProps } from '@/types';
import { type BuyerOrderNotice } from '@/utils/buyerParcelProgress';
import { ArrowLeft, CheckCircle2, Check, Star, X, Camera } from 'lucide-react';

interface Props {
    order: Order;
    canUsePortal: boolean;
    buyAgainUrl?: string | null;
    buyAgainToken?: string | null;
    cancelOrderUrl?: string | null;
    cancellationReasons?: string[];
    cancellationReason?: string | null;
    canConfirmReceipt: boolean;
    returnAvailability?: { can_request: boolean; until: string | null; reason: string | null } | null;
    buyerReturns?: Array<{ id: number; reference: string; status: string }>;
    pickupClaim: { reference: string; status: string; expires_at: string; code_issued_at: string | null; locked_until: string | null } | null;
    pickupHub: {name: string; address: string; operating_hours: string | null} | null;
    pickupCodeUrl: string | null;
    pickupCodeAvailable?: boolean;
    orderNotices: BuyerOrderNotice[];
}

export default function BuyerOrderDetail({ order, canUsePortal, buyAgainUrl, buyAgainToken, cancelOrderUrl, cancellationReasons = [], cancellationReason, canConfirmReceipt, returnAvailability, buyerReturns = [], pickupClaim, pickupHub, pickupCodeUrl, pickupCodeAvailable = false, orderNotices = [] }: Props) {
    const { auth } = usePage<PageProps>().props;
    const adminOversight = auth.user?.role === 'admin';
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
    const [receiptBusy, setReceiptBusy] = useState(false);
    const [receiptError, setReceiptError] = useState('');
    const receiptPending = useRef(false);
    const activeReceipt = useRef({ orderId: order.id, allowed: canConfirmReceipt });
    activeReceipt.current = { orderId: order.id, allowed: canConfirmReceipt };
    const confirmReceipt = () => {
        if (!activeReceipt.current.allowed || activeReceipt.current.orderId !== order.id || receiptPending.current || refreshPending.current || codePending.current) return;
        if (!confirm('Confirm that you have received your order in good condition?')) return;
        receiptPending.current = true; setReceiptBusy(true); setReceiptError('');
        router.post(route('buyer.orders.confirm', order.id), {}, { preserveScroll: true,
            onError: errors => setReceiptError(Object.values(errors).join(' ') || 'Receipt confirmation could not be saved. Try again.'),
            onFinish: () => { receiptPending.current = false; setReceiptBusy(false); },
        });
    };
    const refreshPending = useRef(false);
    const refreshTracking = () => {
        if (refreshPending.current || codePending.current || receiptPending.current) return;
        refreshPending.current = true; setTrackingRefreshing(true); setTrackingError('');
        router.reload({ only: ['order', 'canUsePortal', 'buyAgainUrl', 'buyAgainToken', 'cancelOrderUrl', 'cancellationReasons', 'cancellationReason', 'canConfirmReceipt', 'returnAvailability', 'buyerReturns', 'pickupClaim', 'pickupHub', 'pickupCodeUrl', 'pickupCodeAvailable', 'orderNotices', 'auth', 'flash'],
            onError: errors => setTrackingError(Object.values(errors).join(' ') || 'Tracking could not refresh. Try again.'),
            onFinish: () => { refreshPending.current = false; setTrackingRefreshing(false); },
        });
    };
    const showPickupCode = async () => {
        if (!pickupCodeUrl || codePending.current || refreshPending.current || receiptPending.current || !pickupCodeAvailable || !pickupClaim || claimWindow(pickupClaim, Date.now()) !== 'ready' || pickupClaim.code_issued_at || pickupCode) return;
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
    const [photoError, setPhotoError] = useState('');

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

    const openReview = () => {
        if (!canUsePortal || order.status !== 'completed' || reviewableItems.length === 0 || trackingRefreshing || receiptBusy || codeBusy) return;
        setData('order_item_id', reviewableItems[0].id);
        setReviewModalOpen(true);
    };

    const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        if (e.target.files) {
            const filesArray = Array.from(e.target.files);
            e.target.value = '';
            if (filesArray.length + data.images.length > 5) {
                setPhotoError('Choose up to five photos in total.');
                return;
            }
            if (filesArray.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024)) {
                setPhotoError('Choose JPG, PNG or WEBP images, up to 5 MB each.');
                return;
            }
            setPhotoError('');
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
        setPhotoError('');
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

    return (
        <BuyerOrderAccessLayout canUsePortal={canUsePortal}>
            <Head title={`Order #${order.order_number} Details — BagooPH`} />
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
                                disabled={receiptBusy || trackingRefreshing || codeBusy}
                                onClick={confirmReceipt}
                                className="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <CheckCircle2 className="w-4 h-4" />
                                <span>{receiptBusy ? 'Confirming receipt…' : 'Confirm Order Received'}</span>
                            </button>
                        )}
                        {!order.tracking && canUsePortal && order.status === 'completed' && reviewableItems.length > 0 && (
                            <button
                                type="button"
                                onClick={openReview}
                                className="px-4 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl transition shadow-sm flex items-center justify-center gap-2 cursor-pointer"
                            >
                                <Star className="w-4 h-4 fill-white" />
                                <span>Rate & Upload Photos</span>
                            </button>
                        )}
                        {canUsePortal && buyAgainUrl && buyAgainToken && <BuyAgainButton url={buyAgainUrl} token={buyAgainToken} disabled={trackingRefreshing || receiptBusy || codeBusy} />}
                        {canUsePortal && cancelOrderUrl && <BuyerCancelOrder url={cancelOrderUrl} reasons={cancellationReasons} disabled={trackingRefreshing || receiptBusy || codeBusy} />}
                        {order.status === 'completed' && (
                            <span className="px-3 py-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold rounded-xl flex items-center gap-1.5">
                                <Check className="w-4 h-4 text-emerald-600" />
                                <span>Order Completed</span>
                            </span>
                        )}
                    </div>
                </div>

                {receiptError && <p role="alert" className="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{receiptError}</p>}

                {order.status === 'cancelled' && cancellationReason && <p role="status" className="rounded-xl border border-slate-300 bg-white p-4 text-sm text-slate-700">Cancellation reason: {cancellationReason}</p>}
                <BuyerOrderPackage order={order} canUsePortal={canUsePortal} />
                <OrderTrackingPanel variant="buyer" tracking={order.tracking} buyerOrder={order} notices={orderNotices}
                    onReview={canUsePortal && order.status === 'completed' && reviewableItems.length > 0 ? openReview : undefined}
                    onRefresh={refreshTracking} refreshing={trackingRefreshing || codeBusy || receiptBusy} error={trackingError} />

                {(returnAvailability?.can_request || buyerReturns.length > 0) && <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white p-4"><div><h2 className="font-bold text-slate-900">Returns and refunds</h2>{returnAvailability?.can_request && <p className="mt-1 text-sm text-slate-600">You can request a return within seven days of delivery, including after confirming receipt.</p>}</div><div className="flex flex-wrap gap-2">{returnAvailability?.can_request && <Link href={`/buyer-returns/orders/${order.id}/create`} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-[#C20836]">Request a return</Link>}{buyerReturns.map(item => <Link key={item.id} href={`/buyer-returns/${item.id}`} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">{item.reference}</Link>)}</div></section>}
                {pickupClaim && <section aria-label="Your hub pickup" className="space-y-3 rounded-xl border border-slate-300 bg-white p-4">
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
                {reviewSaved && <p role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-700">Your purchase review has been saved.</p>}

                {/* Product review */}
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

                                <UploadHint formats="JPG, PNG or WEBP" />
                                {photoError && <p role="alert" className="text-xs text-red-700">{photoError}</p>}
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
