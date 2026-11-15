import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import BuyerOrderPackage from '@/Components/BuyerOrderPackage';
import FinancialReviewLinks, { type FinancialReviews } from '@/Components/Finance/FinancialReviewLinks';
import BuyerCancelOrder from '@/Components/BuyerCancelOrder';
import OrderCancellationFeedback, { type CancellationFeedback } from '@/Components/OrderCancellationFeedback';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';
import BuyAgainButton from '@/Components/BuyAgainButton';
import OrderTrackingPanel, { trackingDate } from '@/Components/OrderTrackingPanel';
import { claimWindow, nextClaimBoundary } from '@/utils/orderTracking';
import { Order, PageProps } from '@/types';
import { type BuyerOrderNotice } from '@/utils/buyerParcelProgress';
import { ArrowLeft, CheckCircle2, Check, Star, X, Camera } from 'lucide-react';
import { Button, Notice, buttonClasses, inputClasses, labelClasses } from '@/Components/ui';

interface Props {
    order: Order;
    canUsePortal: boolean;
    buyAgainUrl?: string | null;
    buyAgainToken?: string | null;
    cancelOrderUrl?: string | null;
    cancellationReasons?: string[];
    cancellationRequestUrl?: string | null;
    cancellationFeedback?: CancellationFeedback;
    cancellationReason?: string | null;
    canConfirmReceipt: boolean;
    returnAvailability?: { can_request: boolean; until: string | null; reason: string | null } | null;
    buyerReturns?: Array<{ id: number; reference: string; status: string }>;
    shipmentFinance?: { id: number; reference: string } | null;
    shipmentFinances?: FinancialReviews;
    pickupClaim: { reference: string; status: string; expires_at: string; code_issued_at: string | null; locked_until: string | null } | null;
    pickupHub: {name: string; address: string; operating_hours: string | null} | null;
    pickupCodeUrl: string | null;
    pickupCodeAvailable?: boolean;
    orderNotices: BuyerOrderNotice[];
    deliveryFeedback?: DeliveryFeedbackItem | null;
    deliveryFeedbackUrl?: string | null;
    safetyCheckinSignals?: SafetyCheckinSignalItem[];
    activeSanctions?: ActiveSanctionItem[];
}

import CourierDeliveryFeedbackSection, { type DeliveryFeedbackItem, type SafetyCheckinSignalItem } from '@/Components/CourierDeliveryFeedbackSection';
import AccountEnforcementNotices, { type ActiveSanctionItem } from '@/Components/AccountEnforcementNotices';

export default function BuyerOrderDetail({ order, canUsePortal, buyAgainUrl, buyAgainToken, cancelOrderUrl, cancellationRequestUrl, cancellationFeedback, cancellationReasons = [], cancellationReason, canConfirmReceipt, returnAvailability, buyerReturns = [], shipmentFinance, shipmentFinances, pickupClaim, pickupHub, pickupCodeUrl, pickupCodeAvailable = false, orderNotices = [], deliveryFeedback = null, deliveryFeedbackUrl = null, safetyCheckinSignals = [], activeSanctions = [] }: Props) {
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
        router.reload({ only: ['order', 'canUsePortal', 'buyAgainUrl', 'buyAgainToken', 'cancelOrderUrl', 'cancellationRequestUrl', 'cancellationFeedback', 'cancellationReasons', 'cancellationReason', 'canConfirmReceipt', 'returnAvailability', 'buyerReturns', 'shipmentFinance', 'shipmentFinances', 'pickupClaim', 'pickupHub', 'pickupCodeUrl', 'pickupCodeAvailable', 'orderNotices', 'auth', 'flash'],
            onError: errors => setTrackingError(Object.values(errors).join(' ') || 'Tracking could not refresh. Try again.'),
            onFinish: () => { refreshPending.current = false; setTrackingRefreshing(false); },
        });
    };
    useEffect(() => {
        const request = cancellationFeedback?.request;
        if (request?.status !== 'pending') return;
        const delay = Date.parse(request.respond_by) - Date.now();
        const timer = window.setTimeout(() => refreshTracking(), Math.min(2147483647, Math.max(0, delay) + 100));
        return () => window.clearTimeout(timer);
    }, [order.id, cancellationFeedback?.request?.reference, cancellationFeedback?.request?.status]);
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
    useBodyScrollLock(reviewModalOpen);
    const reviewableItems = (order.items ?? []).filter(item => !item.review);
    const [reviewSaved, setReviewSaved] = useState(false);
    const previewRef = useRef<string[]>([]);
    useEffect(() => () => previewRef.current.forEach(url => URL.revokeObjectURL(url)), []);
    const [previewImages, setPreviewImages] = useState<string[]>([]);
    const [photoError, setPhotoError] = useState('');

    const { data, setData, post, processing, reset, recentlySuccessful, errors, clearErrors, setError } = useForm<{
        order_item_id: number | null;
        rating: number;
        comment: string;
        images: File[];
    }>({
        order_item_id: reviewableItems[0]?.id ?? null,
        rating: 0,
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
        if (data.rating < 1 || data.rating > 5) {
            setError('rating', 'Choose a rating from 1 to 5 stars.');
            return;
        }
        post(route('buyer.reviews.store'), {
            forceFormData: true,
            preserveScroll: true,
            onError: (validation) => {
                if (validation.rating) setError('rating', 'Choose a rating from 1 to 5 stars.');
            },
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


    const cardClasses = 'rounded-card border border-line-200 bg-white p-5 sm:p-6';
    const linkClasses = 'font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900';

    return (
        <BuyerOrderAccessLayout canUsePortal={canUsePortal}>
            <Head title={`Order #${order.order_number} Details — BagooPH`} />
            <div className="mx-auto w-full max-w-[960px] space-y-6 pb-12 pt-2 text-ink-900">

                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="min-w-0 space-y-2">
                        <Link href={route(adminOversight ? 'admin.dashboard' : 'buyer.orders.index')} className="inline-flex items-center gap-1.5 text-sm text-ink-600 hover:text-ink-900">
                            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                            <span>{adminOversight ? 'Back to Admin' : 'Back to My Purchases'}</span>
                        </Link>
                        <div>
                            <h1 className="break-words text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">
                                Order #{order.order_number}
                            </h1>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-ink-600">
                                <span>Placed {trackingDate(order.created_at)} · Philippine time</span>
                                {order.status === 'completed' && (
                                    <span className="inline-flex items-center gap-1 rounded-chip bg-success-soft px-2 py-0.5 text-xs font-medium text-success">
                                        <Check className="h-3.5 w-3.5" aria-hidden="true" />
                                        <span>Order Completed</span>
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                        {canConfirmReceipt && (
                            <button type="button" className={buttonClasses('primary', 'md')} disabled={receiptBusy || trackingRefreshing || codeBusy} onClick={confirmReceipt}>
                                <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                                <span>{receiptBusy ? 'Confirming receipt…' : 'Confirm Order Received'}</span>
                            </button>
                        )}
                        {!order.tracking && canUsePortal && order.status === 'completed' && reviewableItems.length > 0 && (
                            <Button variant="primary" onClick={openReview}>
                                <Star className="h-4 w-4" aria-hidden="true" />
                                <span>Rate & Upload Photos</span>
                            </Button>
                        )}
                        {canUsePortal && buyAgainUrl && buyAgainToken && <BuyAgainButton url={buyAgainUrl} token={buyAgainToken} disabled={trackingRefreshing || receiptBusy || codeBusy} />}
                        {canUsePortal && (cancelOrderUrl || cancellationRequestUrl) && <BuyerCancelOrder url={(cancellationRequestUrl || cancelOrderUrl)!} mode={cancellationRequestUrl ? 'request' : 'direct'} description={cancellationFeedback?.message} paymentMessage={cancellationFeedback?.payment_message} reasons={cancellationReasons} disabled={trackingRefreshing || receiptBusy || codeBusy} />}
                    </div>
                </header>

                {receiptError && <Notice tone="danger" role="alert">{receiptError}</Notice>}

                {order.payment_method === 'wallet' && order.wallet_payment && (
                    <section aria-label="Wallet payment" className={`${cardClasses} flex flex-wrap items-center justify-between gap-4`}>
                        <div className="min-w-0">
                            <h2 className="text-base font-semibold text-ink-900">Bagoo Wallet · virtual credits</h2>
                            <p className="mt-0.5 text-sm text-ink-600">{order.payment_status === 'paid' ? 'This order was paid with virtual credits. No cash is due at delivery.' : order.payment_status === 'refunded' ? 'The original payment was returned to your wallet.' : order.payment_status === 'cancelled' ? 'This unpaid wallet order is closed.' : 'Complete payment within one hour of placing the order. The seller can prepare it after payment.'}</p>
                        </div>
                        <Link href={`/wallet/payments/${order.wallet_payment.id}`} className={buttonClasses(order.payment_status === 'pending' ? 'primary' : 'secondary', 'md')}>{order.payment_status === 'pending' ? 'Pay with wallet' : 'View payment'}</Link>
                    </section>
                )}

                <OrderCancellationFeedback feedback={cancellationFeedback} />
                {order.status === 'cancelled' && cancellationReason && <Notice tone="neutral" role="status">Cancellation reason: {cancellationReason}</Notice>}

                <OrderTrackingPanel variant="buyer" tracking={order.tracking} buyerOrder={order} notices={orderNotices}
                    onReview={canUsePortal && order.status === 'completed' && reviewableItems.length > 0 ? openReview : undefined}
                    onRefresh={refreshTracking} refreshing={trackingRefreshing || codeBusy || receiptBusy} error={trackingError} />

                {pickupClaim && (
                    <section aria-label="Your hub pickup" className={`${cardClasses} space-y-3`}>
                        <h2 className="text-lg font-semibold text-ink-900">Your hub pickup</h2>
                        <div className="rounded-control bg-surface-100 p-4 text-sm">
                            <p className="font-medium text-ink-900">{pickupHub?.name ?? 'Hub details not recorded'}</p>
                            {pickupHub?.address && <p className="mt-0.5 text-ink-700">{pickupHub.address}</p>}
                            <p className="mt-1 text-[13px] text-ink-600">{pickupHub?.operating_hours ?? 'Contact the hub for its actual operating hours.'}</p>
                        </div>
                        {(counterWindow === 'ready' || counterWindow === 'locked') && <p className="text-sm text-ink-700">Collect before <span className="font-medium text-ink-900">{trackingDate(pickupClaim.expires_at)}</span> Philippine time. Bring your valid photo ID{order.payment_method === 'cod' ? ' and exact COD payment.' : '. This wallet parcel requires no cash.'}</p>}
                        {counterWindow === 'expired' && <Notice tone="warning" role="status">The collection deadline has passed. Refresh tracking for the hub's return review.</Notice>}
                        {counterWindow === 'locked' && <Notice tone="warning" role="status">Counter verification is locked until {trackingDate(pickupClaim.locked_until)} Philippine time. Refresh after that time.</Notice>}
                        {counterWindow === 'unavailable' && <p className="text-sm font-medium text-ink-700">{pickupClaim.status === 'collected'
                            ? (order.logistics_flow_version ?? 1) >= 2
                                ? (order.status === 'completed' ? 'Your verified collection completed this order automatically.' : 'Your collection is recorded. Refresh tracking to check order completion.')
                                : (order.status === 'completed' ? 'Collection and your receipt confirmation are recorded.' : 'Collection is recorded. Confirm receipt after checking your items.')
                            : 'The recorded pickup state needs hub review.'}</p>}
                        {counterWindow === 'ready' && pickupCodeAvailable && !pickupCode && !pickupClaim.code_issued_at && <>
                            <p className="text-sm text-ink-700">Your private code is shown once. Save it when it appears; it cannot be revealed again.</p>
                            <Button variant="primary" disabled={codeBusy || trackingRefreshing} onClick={showPickupCode}>{codeBusy ? 'Preparing code…' : 'Show my code once'}</Button>
                        </>}
                        {counterWindow === 'ready' && pickupCode && <p className="break-all rounded-control border-2 border-dashed border-brand-200 bg-brand-50 p-4 text-center text-2xl font-bold tracking-widest text-ink-900" aria-live="polite">{pickupCode}</p>}
                        {counterWindow === 'ready' && pickupClaim.code_issued_at && !pickupCode && <p className="text-sm text-ink-700">Your code was already shown. Use the code you saved; contact the hub if it was lost.</p>}
                        {counterWindow === 'ready' && !pickupCodeAvailable && !pickupCode && !pickupClaim.code_issued_at && <p className="text-sm text-ink-700">Refresh tracking to check whether code access is available. Contact the hub if it remains unavailable.</p>}
                        {codeError && <p role="alert" className="text-[13px] text-brand-700">{codeError}</p>}
                    </section>
                )}

                <BuyerOrderPackage order={order} canUsePortal={canUsePortal} />
                <FinancialReviewLinks value={shipmentFinances} fallbackUrl={shipmentFinance ? `/shipment-finances/${shipmentFinance.id}` : null} />

                {(returnAvailability?.can_request || buyerReturns.length > 0) && (
                    <section className={`${cardClasses} flex flex-wrap items-center justify-between gap-3`}>
                        <div className="min-w-0">
                            <h2 className="text-base font-semibold text-ink-900">Returns and refunds</h2>
                            {returnAvailability?.can_request && <p className="mt-0.5 text-sm text-ink-600">You can request a return within seven days of delivery, including after confirming receipt.</p>}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {returnAvailability?.can_request && <Link href={`/buyer-returns/orders/${order.id}/create`} className={buttonClasses('secondary', 'md')}>Request a return</Link>}
                            {buyerReturns.map(item => <Link key={item.id} href={`/buyer-returns/${item.id}`} className={buttonClasses('ghost', 'md')}>{item.reference}</Link>)}
                        </div>
                    </section>
                )}

                {reviewSaved && <Notice tone="success" role="status">Your purchase review has been saved.</Notice>}
                <AccountEnforcementNotices sanctions={activeSanctions} title="Account Restrictions Affecting Checkout or Reviews" />
                <CourierDeliveryFeedbackSection deliveryFeedback={deliveryFeedback} deliveryFeedbackUrl={deliveryFeedbackUrl} safetyCheckinSignals={safetyCheckinSignals} />

                {/* Product review */}
                {canUsePortal && reviewModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto overscroll-contain bg-brand-900/45 animate-fade-in sm:items-center sm:p-4">
                        <div role="dialog" aria-modal="true" aria-labelledby="review-title" className="my-auto max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-card bg-white shadow-float sm:rounded-card">
                            <div className="flex items-center justify-between gap-4 border-b border-line-200 px-6 py-4">
                                <h2 id="review-title" className="text-lg font-semibold text-ink-900">Rate your purchase</h2>
                                <button type="button" onClick={() => setReviewModalOpen(false)} aria-label="Close review" className="rounded-control p-1.5 text-ink-500 hover:bg-surface-100 hover:text-ink-900">
                                    <X className="h-5 w-5" aria-hidden="true" />
                                </button>
                            </div>

                            <form onSubmit={submitReview}>
                                <div className="space-y-5 px-6 py-5">
                                    {Object.keys(errors).length > 0 && <Notice tone="danger" role="alert">{Object.values(errors).map((error, index) => <p key={index}>{error}</p>)}</Notice>}
                                    <div>
                                        <label htmlFor="review-item" className={labelClasses}>Item</label>
                                        <select id="review-item" value={data.order_item_id || ''} onChange={(e) => setData('order_item_id', Number(e.target.value))} className={inputClasses}>
                                            {reviewableItems.map((item) => (
                                                <option key={item.id} value={item.id}>
                                                    {item.product?.name}{item.color ? ` · ${item.color}` : ''}{item.size ? ` · ${item.size}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <p className={labelClasses}>Your rating</p>
                                        <div role="group" aria-label="Your product rating" aria-describedby={errors.rating ? 'review-rating-error' : undefined} className="flex items-center gap-1">
                                            {[1, 2, 3, 4, 5].map((star) => (
                                                <button
                                                    key={star}
                                                    type="button"
                                                    aria-label={`Rate ${star} ${star === 1 ? 'star' : 'stars'}`}
                                                    aria-pressed={data.rating === star}
                                                    onClick={() => { setData('rating', star); clearErrors('rating'); }}
                                                    className="rounded-control p-1 transition-transform hover:scale-110 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 motion-reduce:transition-none"
                                                >
                                                    <Star className={`h-8 w-8 ${star <= data.rating ? 'fill-brand-500 text-brand-500' : 'text-line-300'}`} aria-hidden="true" />
                                                </button>
                                            ))}
                                        </div>
                                        {errors.rating && <p id="review-rating-error" role="alert" className="mt-1.5 text-[13px] text-brand-700">{errors.rating}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="review-comment" className={labelClasses}>Your review</label>
                                        <textarea
                                            id="review-comment"
                                            value={data.comment}
                                            onChange={(e) => setData('comment', e.target.value)}
                                            rows={3}
                                            maxLength={1000}
                                            placeholder="Tell other buyers about product sizing, quality, and material..."
                                            className={`${inputClasses} h-auto py-2`}
                                            required
                                        />
                                    </div>

                                    <div>
                                        <p className={labelClasses}>Photos <span className="font-normal text-ink-500">(up to 5)</span></p>
                                        <div className="flex flex-wrap items-center gap-2.5">
                                            {previewImages.map((src, idx) => (
                                                <div key={idx} className="group relative h-16 w-16 overflow-hidden rounded-control bg-surface-100">
                                                    <img src={src} alt="Upload preview" className="h-full w-full object-cover" />
                                                    <button
                                                        type="button"
                                                        onClick={() => removeImage(idx)}
                                                        aria-label="Remove photo"
                                                        className="absolute right-1 top-1 flex h-5 w-5 items-center justify-center rounded-full border border-line-200 bg-white text-ink-900 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                                                    >
                                                        <X className="h-3 w-3" aria-hidden="true" />
                                                    </button>
                                                </div>
                                            ))}

                                            {data.images.length < 5 && (
                                                <label className="flex h-16 w-16 cursor-pointer flex-col items-center justify-center rounded-control border-2 border-dashed border-line-300 text-ink-500 transition hover:border-brand-500 hover:text-brand-600">
                                                    <Camera className="h-5 w-5" aria-hidden="true" />
                                                    <span className="mt-0.5 text-[11px] font-medium">Add</span>
                                                    <input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={handleImageChange} className="hidden" />
                                                </label>
                                            )}
                                        </div>
                                        <div className="mt-2"><UploadHint formats="JPG, PNG or WEBP" /></div>
                                        {photoError && <p role="alert" className="mt-1.5 text-[13px] text-brand-700">{photoError}</p>}
                                    </div>

                                    {recentlySuccessful && <Notice tone="success">Review and photos submitted.</Notice>}
                                </div>

                                <div className="flex flex-col-reverse gap-3 border-t border-line-200 px-6 py-4 sm:flex-row sm:justify-end">
                                    <Button onClick={() => setReviewModalOpen(false)}>Cancel</Button>
                                    <Button type="submit" variant="primary" disabled={processing}>{processing ? 'Submitting…' : 'Post review'}</Button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

            </div>
        </BuyerOrderAccessLayout>
    );
}
