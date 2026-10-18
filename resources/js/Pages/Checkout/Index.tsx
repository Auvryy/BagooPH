import PriceSegments from '@/Components/PriceSegments';
import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import PhoneInput from '@/Components/PhoneInput';
import BuyerVoucherPicker from '@/Components/Vouchers/BuyerVoucherPicker';
import CheckoutVoucherBreakdown from '@/Components/Vouchers/CheckoutVoucherBreakdown';
import axios from 'axios';
import usePurchaseQuote, { PurchaseQuote, VoucherOffer } from '@/hooks/usePurchaseQuote';
import { chooseVoucher, withoutVoucher, repairedVoucherSelection, isVoucherRequestCurrent } from '@/utils/voucherSelection';
import { Cart, CartItem, PageProps, User, Address } from '@/types';
import { 
    ShoppingBag, 
    ArrowLeft, 
    Truck, 
    Wallet, 
    ShieldCheck, 
    MapPin, 
    Check, 
    AlertCircle,
    Tag,
    Clock,
    X,
    Gift,
    Upload,
    FileText,
    ShieldAlert,
    AlertTriangle,
    Lock,
    Store,
    Crosshair,
    Navigation,
    Building2
} from 'lucide-react';

export interface PickupHubItem {
    id: number;
    name: string;
    code: string;
    city_municipality: string;
    address: string;
    province: string;
}

interface Props {
    cart: Cart;
    items: CartItem[];
    subtotal: number;
    shippingFee: number;
    total: number;
    user: User;
    availableVouchers?: VoucherOffer[];
    quote: PurchaseQuote;
    kycStatus?: 'none' | 'pending_approval' | 'approved' | 'rejected';
    kycFeedback?: string | null;
    addresses?: Address[];
    defaultAddressId?: number | null;
    addressBookLimit?: number;
    pickupHubs?: PickupHubItem[];
    checkoutToken: string;
    itemQuantities?: Record<number, number>;
}

export default function CheckoutIndex({ 
    cart, 
    items, 
    quote: initialQuote,
    user, 
    availableVouchers = [],
    kycStatus = 'none',
    kycFeedback = null,
    addresses = [],
    defaultAddressId = null,
    addressBookLimit = 10,
    pickupHubs = [],
    checkoutToken,
    itemQuantities = {},
}: Props) {
    const { flash } = usePage<PageProps>().props;

    const [voucherCodeInput, setVoucherCodeInput] = useState('');
    const [voucherError, setVoucherError] = useState<string>('');

    const [isLocating, setIsLocating] = useState(false);
    const [gpsStatus, setGpsStatus] = useState<string | null>(null);

    const handleCaptureCurrentLocation = () => {
        if (!navigator.geolocation) {
            setGpsStatus('Geolocation is not supported by your browser.');
            return;
        }

        setIsLocating(true);
        setGpsStatus('Acquiring precise GPS fix...');

        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const lat = parseFloat(pos.coords.latitude.toFixed(6));
                const lng = parseFloat(pos.coords.longitude.toFixed(6));
                setData(prev => ({
                    ...prev,
                    shipping_latitude: lat,
                    shipping_longitude: lng,
                }));
                setIsLocating(false);
                setGpsStatus(`GPS coordinates pinned (accuracy ±${Math.round(pos.coords.accuracy)}m)`);
            },
            (err) => {
                setIsLocating(false);
                setGpsStatus('GPS error: ' + err.message);
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    };

    // Inline KYC ID upload form
    const kycForm = useForm<{ id_document: File | null }>({
        id_document: null,
    });
    const [kycFileName, setKycFileName] = useState<string | null>(null);
    const kycFileInputRef = useRef<HTMLInputElement>(null);

    const handleKycFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            kycForm.setData('id_document', file);
            setKycFileName(file.name);
        }
    };

    const handleKycSubmit = (e?: React.FormEvent) => {
        if (e) e.preventDefault();
        if (!kycForm.data.id_document) return;

        kycForm.post(route('checkout.kyc.upload'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setKycFileName(null);
                kycForm.reset();
            },
        });
    };

    // Find initial default address: prefers defaultAddressId or is_default or first
    const initialAddress = addresses.find(a => a.id === defaultAddressId)
        || addresses.find(a => a.is_default)
        || addresses[0]
        || null;

    const [selectedAddressId, setSelectedAddressId] = useState<string>(
        initialAddress ? String(initialAddress.id) : 'new'
    );

    const { data, setData, post, processing, errors } = useForm({
        checkout_token: checkoutToken,
        item_ids: items.map(i => i.id),
        item_quantities: itemQuantities,
        recipient_name: initialAddress?.recipient_name || user.name || '',
        recipient_phone: initialAddress?.phone || user.phone || '',
        shipping_address: initialAddress?.street || user.address || '',
        shipping_city: initialAddress?.city || user.city || '',
        shipping_province: initialAddress?.province || user.province || '',
        shipping_postal_code: initialAddress?.postal_code || user.postal_code || '',
        destination_barangay: initialAddress?.barangay || user.barangay || '',
        shipping_latitude: initialAddress?.latitude ?? '',
        shipping_longitude: initialAddress?.longitude ?? '',
        landmark: initialAddress?.landmark || '',
        delivery_type: 'doorstep' as 'doorstep' | 'hub_self_pickup',
        pickup_hub_id: '' as string | number,
        payment_method: 'cod',
        notes: '',
        voucher_code: '',
        platform_voucher_revision_id: null as number | null,
        shop_voucher_revision_ids: {} as Record<number, number | null>,
        save_address: false,
    });

    const handleAddressChange = (addressId: string) => {
        setSelectedAddressId(addressId);
        if (addressId === 'new') {
            setData(prev => ({
                ...prev,
                recipient_name: user.name || '',
                recipient_phone: user.phone || '',
                shipping_address: '',
                shipping_city: '',
                shipping_province: '',
                shipping_postal_code: '',
                destination_barangay: '',
                shipping_latitude: '',
                shipping_longitude: '',
                landmark: '',
                save_address: addresses.length < addressBookLimit,
            }));
        } else {
            const found = addresses.find(a => String(a.id) === addressId);
            if (found) {
                setData(prev => ({
                    ...prev,
                    recipient_name: found.recipient_name || user.name || '',
                    recipient_phone: found.phone,
                    shipping_address: found.street,
                    shipping_city: found.city,
                    shipping_province: found.province || '',
                    shipping_postal_code: found.postal_code || '',
                    destination_barangay: found.barangay || '',
                    shipping_latitude: found.latitude ?? '',
                    shipping_longitude: found.longitude ?? '',
                    landmark: found.landmark || '',
                    save_address: false,
                }));
            }
        }
    };

    const [showConfirmModal, setShowConfirmModal] = useState(false);
    const orderPending = useRef(false);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const [hasConfirmedAgreement, setHasConfirmedAgreement] = useState(false);
    const [validationError, setValidationError] = useState('');

    const quoteInput = {
        item_ids: data.item_ids,
        item_quantities: data.item_quantities,
        delivery_type: data.delivery_type,
        code: data.voucher_code,
        platform_voucher_revision_id: data.platform_voucher_revision_id,
        shop_voucher_revision_ids: data.shop_voucher_revision_ids,
        shipping_province: data.shipping_province,
        shipping_city: data.shipping_city,
        pickup_hub_id: data.delivery_type === 'hub_self_pickup' ? data.pickup_hub_id : '',
        revision: JSON.stringify([items.map(item => [item.id, item.quantity, item.product?.price]),
            data.shipping_address, data.destination_barangay, data.shipping_postal_code,
            data.shipping_latitude, data.shipping_longitude, checkoutToken, flash?.error]),
    };
    const pricing = usePurchaseQuote(quoteInput, initialQuote, availableVouchers);
    const quote = pricing.quote;
    const [pickerSlot, setPickerSlot] = useState<number | null | undefined>(undefined);
    const [bestBusy, setBestBusy] = useState(false);
    const bestPending = useRef(false);
    const currentQuoteKey = useRef('');
    currentQuoteKey.current = JSON.stringify(quoteInput);
    const bestController = useRef<AbortController | null>(null);
    useEffect(() => () => bestController.current?.abort(), []);
    const quotedItems = new Map(quote.items.map(item => [item.id, item]));
    const subtotal = quote.subtotal;
    const isHubPickup = data.delivery_type === 'hub_self_pickup';
    const selectedPickupHub = pickupHubs.find(hub => String(hub.id) === String(data.pickup_hub_id));
    const appliedVouchers = pricing.current ? quote.vouchers ?? (quote.voucher ? [quote.voucher] : []) : [];
    const finalShippingFee = quote.shipping_fee - quote.shipping_discount;
    const grandTotal = quote.total;

    useEffect(() => {
        setShowConfirmModal(false);
        setHasConfirmedAgreement(false);
    }, [JSON.stringify(quoteInput)]);

    useEffect(() => {
        if (!pricing.current || !pricing.validSelection) return;
        const corrected = repairedVoucherSelection(data, pricing.validSelection);
        const current = { voucher_code: data.voucher_code, platform_voucher_revision_id: data.platform_voucher_revision_id, shop_voucher_revision_ids: data.shop_voucher_revision_ids };
        if (JSON.stringify(current) !== JSON.stringify(corrected)) {
            if (quote.voucher_error) setVoucherError(quote.voucher_error + ' Your other choices are retained. Choose another offer if you wish.');
            setData(prev => ({ ...prev, ...corrected }));
        }
    }, [pricing.current, pricing.validSelection, quote.voucher_error]);

    const useBestVouchers = async () => {
        if (bestPending.current || !pricing.ready) return;
        bestPending.current = true; setBestBusy(true); setVoucherError('');
        const requested = currentQuoteKey.current;
        const controller = new AbortController(); bestController.current = controller;
        try {
            const { revision, ...body } = quoteInput;
            const response = await axios.post(route('buyer.vouchers.best'), body, { signal: controller.signal });
            if (isVoucherRequestCurrent(requested, currentQuoteKey.current, controller.signal.aborted)) {
                setData(prev => ({ ...prev, ...repairedVoucherSelection(prev, response.data.selection) }));
            } else if (!controller.signal.aborted) setVoucherError('Your checkout changed. Compare vouchers again for the updated items and delivery.');
        } catch (failure) {
            if (!controller.signal.aborted && requested === currentQuoteKey.current) setVoucherError(axios.isAxiosError(failure) && failure.response?.status === 422
                ? failure.response.data.message : 'Could not compare vouchers. Try again.');
        } finally { bestPending.current = false; if (!controller.signal.aborted) setBestBusy(false); }
    };

    const selectOffer = (offer: VoucherOffer) => {
        if (!offer.revision_id) return;
        setVoucherError('');
        setData(prev => ({ ...prev, ...chooseVoucher(prev, offer, appliedVouchers) }));
    };

    const applyVoucher = (codeToApply?: string) => {
        const code = (codeToApply || voucherCodeInput).trim().toUpperCase();
        if (!code) return;
        setVoucherError('');
        setVoucherCodeInput(code);
        const offer = pricing.offers.find(offer => offer.code === code);
        if (offer?.revision_id) {
            selectOffer(offer);
            return;
        }
        setData('voucher_code', code);
    };

    const removeVoucher = (shopId: number | null) => {
        setVoucherError('');
        setVoucherCodeInput('');
        setData(prev => ({ ...prev, ...withoutVoucher(prev, shopId, appliedVouchers) }));
    };

    const handlePromptConfirmation = (e: React.FormEvent) => {
        e.preventDefault();
        setValidationError('');

        if (!pricing.ready || bestBusy) {
            setValidationError(pricing.failure || 'Please wait for your total to update.');
            return;
        }

        if (kycStatus !== 'approved') {
            if (kycStatus === 'pending_approval') {
                setValidationError('Order placement is paused while your ID verification is under review.');
            } else if (kycStatus === 'rejected') {
                setValidationError('Your ID was rejected. Please re-upload a valid ID to proceed with checkout.');
            } else {
                setValidationError('Identity verification required. Please upload your ID to enable purchasing.');
            }
            return;
        }

        if (data.delivery_type === 'hub_self_pickup' && !data.pickup_hub_id) {
            setValidationError('Please select a designated Bayan Station Hub for self-pickup collection.');
            return;
        }

        if (!data.recipient_name.trim() || !data.recipient_phone.trim() || !data.shipping_address.trim()
            || !data.shipping_city.trim() || !data.shipping_province.trim() || !data.destination_barangay.trim() || !data.shipping_postal_code.trim()) {
            setValidationError('Please complete all required recipient and address fields.');
            return;
        }

        setHasConfirmedAgreement(false);
        setShowConfirmModal(true);
    };

    const submitFinalOrder = () => {
        if (orderPending.current || processing) return;
        if (!pricing.ready || bestBusy || !hasConfirmedAgreement || kycStatus !== 'approved') {
            setShowConfirmModal(false);
            return;
        }
        orderPending.current = true;
        post(route('checkout.store'), {
            preserveScroll: true,
            onError: () => requestAnimationFrame(() => errorSummaryRef.current?.focus()),
            onFinish: () => { orderPending.current = false; setShowConfirmModal(false); },
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

    const quotePrice = (amount: number) => pricing.pending ? 'Updating…' : pricing.failure ? 'Unavailable' : formatPrice(amount);

    return (
        <BuyerLayout>
            <Head title="Secure Checkout — BagooPH" />

            <div className="space-y-6">
                
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-200">
                    <div className="min-w-0">
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Finalize Checkout</h1>
                        <p className="text-xs text-slate-500 font-sans mt-0.5">Verify delivery destination, applied vouchers, and payment mode</p>
                    </div>

                    <Link
                        href={route('buyer.cart')}
                        className="text-xs font-semibold text-[#E00D42] hover:underline flex items-center gap-1 font-sans w-fit"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        <span>Back to My Bag</span>
                    </Link>
                </div>

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 text-slate-700 text-xs flex items-center gap-2.5 font-sans">
                        <Check className="w-4 h-4 text-[#E00D42] shrink-0" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash?.error && (
                    <div className="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2.5 font-sans">
                        <AlertCircle className="w-4 h-4 text-rose-600 shrink-0" />
                        <span>{flash.error}</span>
                    </div>
                )}
                {Object.keys(errors).length > 0 && (
                    <div ref={errorSummaryRef} role="alert" tabIndex={-1} className="p-4 rounded-xl bg-rose-50 border border-rose-300 text-rose-800 text-xs font-sans space-y-2">
                        <p className="font-semibold">Please check your checkout details.</p>
                        <ul className="list-disc pl-4 space-y-1">
                            {Array.from(new Set(Object.values(errors))).map(error => <li key={error}>{error}</li>)}
                        </ul>
                        {errors.checkout_token && <Link href={route('checkout.index', { items: data.item_ids.join(','), ...(Object.keys(data.item_quantities).length === 1 ? { quantity: Object.values(data.item_quantities)[0] } : {}) })} className="font-semibold underline">Reload checkout</Link>}
                    </div>
                )}

                <form onSubmit={handlePromptConfirmation} className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left Column: Forms */}
                    <div className="lg:col-span-8 space-y-6 font-sans">
                        
                        {/* 1. Verified Delivery Destination & Fulfillment Method */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-5">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    01
                                </div>
                                <div>
                                    <h2 className="font-bold text-sm text-slate-900">
                                        Fulfillment Method & Destination
                                    </h2>
                                    <p className="text-[11px] text-slate-500">Choose doorstep courier dispatch or free collection at a Bayan Hub</p>
                                </div>
                            </div>

                            {/* Fulfillment Method Selector Cards */}
                            <fieldset className="min-w-0 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <legend className="sr-only">Delivery method</legend>
                                {/* Option 1: Doorstep Delivery */}
                                <label
                                    className={`relative focus-within:ring-2 focus-within:ring-[#E00D42] focus-within:ring-offset-2 p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                        data.delivery_type === 'doorstep'
                                            ? 'border-[#E00D42] bg-red-50/20 shadow-xs'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
                                    <input type="radio" name="delivery_type" value="doorstep" checked={data.delivery_type === 'doorstep'} onChange={() => setData('delivery_type', 'doorstep')} className="sr-only" />
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-2.5">
                                            <div className={`p-2 rounded-lg ${data.delivery_type === 'doorstep' ? 'bg-[#E00D42] text-white' : 'bg-slate-100 text-slate-600'}`}>
                                                <Truck className="w-4 h-4" />
                                            </div>
                                            <div>
                                                <p className="text-xs font-bold text-slate-900">Doorstep Delivery</p>
                                                <p className="text-[11px] text-slate-500">Direct handover at your gate / door</p>
                                            </div>
                                        </div>
                                        <span className="text-xs font-bold font-sans text-slate-900">
                                            {quotePrice(quote.doorstep_shipping_fee)}
                                        </span>
                                    </div>
                                    <p className="text-[10px] text-slate-400 mt-2 font-sans">
                                        Shipping is calculated separately for each shop.
                                    </p>
                                </label>

                                {/* Option 2: Free Bayan Hub Self-Pickup */}
                                <label
                                    className={`relative focus-within:ring-2 focus-within:ring-[#E00D42] focus-within:ring-offset-2 p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                        data.delivery_type === 'hub_self_pickup'
                                            ? 'border-[#E00D42] bg-rose-50/30 shadow-xs'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
                                    <input type="radio" name="delivery_type" value="hub_self_pickup" checked={data.delivery_type === 'hub_self_pickup'} onChange={() => setData('delivery_type', 'hub_self_pickup')} className="sr-only" />
                                    <div className="flex items-start justify-between">
                                        <div className="flex items-center gap-2.5">
                                            <div className={`p-2 rounded-lg ${data.delivery_type === 'hub_self_pickup' ? 'bg-[#E00D42] text-white' : 'bg-slate-100 text-slate-600'}`}>
                                                <Store className="w-4 h-4" />
                                            </div>
                                            <div>
                                                <div className="flex items-center gap-1.5">
                                                    <p className="text-xs font-bold text-slate-900">Bayan Hub Self-Pickup</p>
                                                    <span className="px-1.5 py-0.5 text-[9px] font-black uppercase rounded bg-rose-100 text-[#E00D42] font-sans">
                                                        100% FREE
                                                    </span>
                                                </div>
                                                <p className="text-[11px] text-slate-500">Pick up at municipal station</p>
                                            </div>
                                        </div>
                                        <span className="text-xs font-black font-sans text-[#E00D42]">
                                            ₱0.00
                                        </span>
                                    </div>
                                    <p className="text-[10px] text-slate-600 mt-2 font-sans">
                                        No delivery fee. Private claim code and identity check at collection.
                                    </p>
                                </label>
                            </fieldset>

                            {/* When Bayan Hub Pickup is selected */}
                            {data.delivery_type === 'hub_self_pickup' && (
                                <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-4">
                                    <div className="flex items-start gap-2.5">
                                        <Building2 className="w-5 h-5 text-[#E00D42] shrink-0 mt-0.5" />
                                        <div>
                                            <h3 className="text-xs font-bold text-slate-900">Select Collection Station (Bayan Hub)</h3>
                                            <p className="text-[11px] text-slate-600 mt-0.5">
                                                Choose a hub serving the recipient address below. Your parcel travels through the origin Bayan Hub and Mother Hub network before reaching this station. Check order tracking for collection readiness and private claim-code access.
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-pickup-hub" className="block text-xs font-semibold text-slate-800 mb-1.5">
                                            Designated Bayan Station *
                                        </label>
                                        <select
                                            id="checkout-pickup-hub"
                                            aria-invalid={Boolean(errors.pickup_hub_id)}
                                            aria-describedby={errors.pickup_hub_id ? 'checkout-pickup-hub-error' : undefined}
                                            required
                                            value={data.pickup_hub_id}
                                            onChange={(e) => setData('pickup_hub_id', e.target.value)}
                                            className="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-slate-900 text-xs font-medium focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] shadow-2xs"
                                        >
                                            <option value="">-- Choose a Local Bayan Hub Station --</option>
                                            {pickupHubs.map((hub) => (
                                                <option key={hub.id} value={hub.id}>
                                                    [{hub.code}] {hub.name} — {hub.city_municipality}, {hub.province}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.pickup_hub_id && (
                                            <p id="checkout-pickup-hub-error" className="text-rose-700 text-[11px] mt-1">{errors.pickup_hub_id}</p>
                                        )}
                                    </div>

                                    {data.pickup_hub_id && (
                                        <div className="p-3 bg-white rounded-lg border border-slate-200 text-xs space-y-1">
                                            {(() => {
                                                const selectedHub = pickupHubs.find(h => String(h.id) === String(data.pickup_hub_id));
                                                if (!selectedHub) return null;
                                                return (
                                                    <>
                                                        <p className="font-bold text-slate-900">{selectedHub.name} ({selectedHub.code})</p>
                                                        <p className="text-slate-600">{selectedHub.address}, {selectedHub.city_municipality}, {selectedHub.province}</p>
                                                        <p className="text-[10px] text-slate-600 font-sans pt-1">
                                                            Check the recorded hub hours in order tracking before travelling. Bring identification and your private claim code when collection is ready.
                                                        </p>
                                                    </>
                                                );
                                            })()}
                                        </div>
                                    )}

                                </div>
                            )}
                            <div className="space-y-4">
                                <div className="space-y-1">
                                    <h3 className="text-sm font-bold text-slate-900">Recipient and address</h3>
                                    {isHubPickup && <p className="text-xs leading-5 text-slate-600">Keep your own address here so we can check that the selected hub serves your area. Collection takes place at the selected hub, and this address is saved only if you choose to save it.</p>}
                                </div>
                                {/* Saved Address Dropdown Selector */}
                                {addresses.length > 0 && (
                                    <div className="p-4 bg-slate-50/80 border border-slate-200 rounded-xl space-y-2">
                                        <div className="flex items-center justify-between">
                                            <label htmlFor="checkout-saved-address" className="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
                                                <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                                <span>Select Saved Delivery Destination</span>
                                            </label>
                                            <Link
                                                href={route('buyer.profile', { tab: 'addresses' })}
                                                className="text-[11px] font-semibold text-[#E00D42] hover:underline"
                                                target="_blank"
                                            >
                                                Manage Address Book
                                            </Link>
                                        </div>
                                        <select
                                            id="checkout-saved-address"
                                            value={selectedAddressId}
                                            onChange={(e) => handleAddressChange(e.target.value)}
                                            className="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-slate-900 text-xs font-medium focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition cursor-pointer shadow-2xs"
                                        >
                                            {addresses.map((addr) => (
                                                <option key={addr.id} value={String(addr.id)}>
                                                    {addr.is_default ? '[DEFAULT] ' : ''}{addr.recipient_name} — {addr.street}, {addr.city} ({addr.phone})
                                                </option>
                                            ))}
                                            <option value="new">+ Enter New / Different Address</option>
                                        </select>
                                    </div>
                                )}

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-sans">
                                    <div>
                                        <label htmlFor="checkout-recipient-name" className="mb-1.5 block font-semibold text-slate-700">Recipient name *</label>
                                        <input id="checkout-recipient-name" name="recipient_name" type="text" required minLength={2} maxLength={100}
                                            value={data.recipient_name} onChange={event => setData('recipient_name', event.target.value)}
                                            aria-invalid={Boolean(errors.recipient_name)}
                                            className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2.5 text-slate-900 focus:border-[#E00D42] focus:bg-white focus:ring-[#E00D42]" />
                                        {errors.recipient_name && <p className="text-rose-500 text-[11px] mt-1">{errors.recipient_name}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-phone" className="block font-semibold text-slate-700 mb-1.5">Contact phone *</label>
                                        <PhoneInput
                                            id="checkout-phone"
                                            error={errors.recipient_phone}
                                            value={data.recipient_phone}
                                            onChange={(val) => setData('recipient_phone', val)}
                                            placeholder="917 123 4567"
                                            accentColor="primary"
                                            helperText="10-digit mobile number (e.g. 917 123 4567)"
                                            required
                                        />
                                    </div>

                                    <div className="sm:col-span-2">
                                        <label htmlFor="checkout-shipping_address" className="block font-semibold text-slate-700 mb-1.5">Street Address, Unit / House No. *</label>
                                        <input
                                            id="checkout-shipping_address"
                                            aria-invalid={Boolean(errors.shipping_address)}
                                            aria-describedby={errors.shipping_address ? 'checkout-shipping_address-error' : undefined}
                                            type="text"
                                            maxLength={500}
                                            minLength={5}
                                            value={data.shipping_address}
                                            onChange={(e) => setData('shipping_address', e.target.value)}
                                            required
                                            placeholder="House/Unit No., Street name, Subdivision"
                                            className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                        />
                                        {errors.shipping_address && <p id="checkout-shipping_address-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_address}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-destination_barangay" className="block font-semibold text-slate-700 mb-1.5">Barangay *</label>
                                        <input
                                            id="checkout-destination_barangay"
                                            aria-invalid={Boolean(errors.destination_barangay)}
                                            aria-describedby={errors.destination_barangay ? 'checkout-destination_barangay-error' : undefined}
                                            type="text"
                                            minLength={2}
                                            value={data.destination_barangay}
                                            required
                                            maxLength={100}
                                            onChange={(e) => setData('destination_barangay', e.target.value)}
                                            placeholder="e.g. Brgy. Santisimo Rosario"
                                            className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                        />
                                        {errors.destination_barangay && <p id="checkout-destination_barangay-error" className="text-rose-700 text-[11px] mt-1">{errors.destination_barangay}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-shipping_city" className="block font-semibold text-slate-700 mb-1.5">City / Municipality *</label>
                                        <input
                                            id="checkout-shipping_city"
                                            aria-invalid={Boolean(errors.shipping_city)}
                                            aria-describedby={errors.shipping_city ? 'checkout-shipping_city-error' : undefined}
                                            type="text"
                                            maxLength={100}
                                            minLength={2}
                                            value={data.shipping_city}
                                            onChange={(e) => setData('shipping_city', e.target.value)}
                                            required
                                            placeholder="e.g. San Pablo City"
                                            className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                        />
                                        {errors.shipping_city && <p id="checkout-shipping_city-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_city}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-shipping_province" className="block font-semibold text-slate-700 mb-1.5">Province *</label>
                                        <input
                                            id="checkout-shipping_province"
                                            aria-invalid={Boolean(errors.shipping_province)}
                                            aria-describedby={errors.shipping_province ? 'checkout-shipping_province-error' : undefined}
                                            type="text"
                                            minLength={2}
                                            value={data.shipping_province}
                                            required
                                            maxLength={100}
                                            onChange={(e) => setData('shipping_province', e.target.value)}
                                            placeholder="e.g. Laguna"
                                            className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                        />
                                        {errors.shipping_province && <p id="checkout-shipping_province-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_province}</p>}
                                    </div>

                                    <div>
                                        <label htmlFor="checkout-shipping_postal_code" className="block font-semibold text-slate-700 mb-1.5">Postal Code *</label>
                                        <input
                                            id="checkout-shipping_postal_code"
                                            aria-invalid={Boolean(errors.shipping_postal_code)}
                                            aria-describedby={errors.shipping_postal_code ? 'checkout-shipping_postal_code-error' : undefined}
                                            type="text"
                                            inputMode="numeric"
                                            maxLength={4}
                                            required
                                            pattern="[0-9]{4}"
                                            placeholder="e.g. 4000"
                                            minLength={4}
                                            value={data.shipping_postal_code}
                                            onChange={(e) => setData('shipping_postal_code', e.target.value)}
                                            className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                        />
                                        {errors.shipping_postal_code && <p id="checkout-shipping_postal_code-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_postal_code}</p>}
                                    </div>

                                    {/* Geolocation Pin Coordinates & Landmark Block */}
                                    <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 sm:col-span-2 space-y-2.5">
                                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                            <span className="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                                <Crosshair className="w-3.5 h-3.5 text-[#E00D42]" />
                                                Delivery location (optional)
                                            </span>
                                            <button
                                                type="button"
                                                onClick={handleCaptureCurrentLocation}
                                                disabled={isLocating}
                                                className="px-2.5 py-1 text-[11px] font-semibold bg-white border border-slate-300 hover:border-[#E00D42] text-slate-700 hover:text-[#E00D42] rounded-lg transition flex items-center gap-1 shadow-2xs cursor-pointer disabled:opacity-50 w-fit"
                                            >
                                                <MapPin className="w-3 h-3 text-[#E00D42]" />
                                                <span>{isLocating ? 'Acquiring GPS...' : 'Pin My Current Location'}</span>
                                            </button>
                                        </div>

                                        {gpsStatus && (
                                            <p className="text-[10px] font-sans text-slate-700 bg-slate-100 px-2 py-1 rounded">
                                                {gpsStatus}
                                            </p>
                                        )}

                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label htmlFor="checkout-shipping_latitude" className="block text-[10px] text-slate-500 font-sans mb-0.5">Latitude</label>
                                                <input
                                                    id="checkout-shipping_latitude"
                                                    aria-invalid={Boolean(errors.shipping_latitude)}
                                                    aria-describedby={errors.shipping_latitude ? 'checkout-shipping_latitude-error' : undefined}
                                                    type="number"
                                                    step="any"
                                                    min={-90}
                                                    max={90}
                                                    placeholder="e.g. 14.0682"
                                                    value={data.shipping_latitude}
                                                    onChange={(e) => setData('shipping_latitude', e.target.value)}
                                                    className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-sans"
                                                />
                                                {errors.shipping_latitude && <p id="checkout-shipping_latitude-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_latitude}</p>}
                                            </div>
                                            <div>
                                                <label htmlFor="checkout-shipping_longitude" className="block text-[10px] text-slate-500 font-sans mb-0.5">Longitude</label>
                                                <input
                                                    id="checkout-shipping_longitude"
                                                    aria-invalid={Boolean(errors.shipping_longitude)}
                                                    aria-describedby={errors.shipping_longitude ? 'checkout-shipping_longitude-error' : undefined}
                                                    type="number"
                                                    step="any"
                                                    min={-180}
                                                    max={180}
                                                    placeholder="e.g. 121.3256"
                                                    value={data.shipping_longitude}
                                                    onChange={(e) => setData('shipping_longitude', e.target.value)}
                                                    className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-sans"
                                                />
                                                {errors.shipping_longitude && <p id="checkout-shipping_longitude-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_longitude}</p>}
                                            </div>
                                        </div>

                                        <div>
                                            <label htmlFor="checkout-landmark" className="block text-[10px] text-slate-600 font-bold mb-0.5">
                                                Delivery landmark (optional)
                                            </label>
                                            <input
                                                id="checkout-landmark"
                                                aria-invalid={Boolean(errors.landmark)}
                                                aria-describedby={errors.landmark ? 'checkout-landmark-error' : undefined}
                                                type="text"
                                                placeholder="e.g. Tapat ng Barangay Hall, Dilaw na Gate na may black fence, tabi ng tindahan"
                                                maxLength={255}
                                                minLength={5}
                                                value={data.landmark}
                                                onChange={(e) => setData('landmark', e.target.value)}
                                                className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs"
                                            />
                                            {errors.landmark && <p id="checkout-landmark-error" className="text-rose-700 text-[11px] mt-1">{errors.landmark}</p>}
                                            <p className="text-[10px] text-slate-400 mt-0.5">
                                                Add a useful landmark or location to help the rider find your address. Check the pin before placing your order.
                                            </p>
                                        </div>
                                    </div>

                                    {selectedAddressId === 'new' && (
                                        <label className="flex items-center gap-2 cursor-pointer text-xs text-slate-700 select-none pt-1 sm:col-span-2">
                                            <input
                                                type="checkbox"
                                                checked={data.save_address}
                                                disabled={addresses.length >= addressBookLimit}
                                                onChange={(e) => setData('save_address', e.target.checked)}
                                                className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                            />
                                            <span>{addresses.length >= addressBookLimit ? `Your address book has ${addressBookLimit} addresses. You can use this address for this order without saving it.` : 'Save this address to my address book'}</span>
                                        </label>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* 2. Package Items in this Order */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    02
                                </div>
                                <h2 className="font-bold text-sm text-slate-900">
                                    Items In Your Shopping Bag ({items.length})
                                </h2>
                            </div>

                            <div className="divide-y divide-slate-100 font-sans text-xs">
                                {quote.shops.map(shop => <section key={shop.shop_id} className="space-y-3 py-4" aria-label={shop.shop_name}>
                                    <h3 className="font-bold text-sm text-slate-900">{shop.shop_name}</h3>
                                    {items.filter(item => item.product?.shop?.id === shop.shop_id).map((item) => (
                                    <div key={item.id} className="py-3.5 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3.5 min-w-0">
                                            <img
                                                src={(item.color && item.product?.variants?.colors?.find(c => c.name === item.color)?.image_url) || item.product?.featured_image || ''}
                                                alt=""
                                                className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate text-xs">{item.product?.name}</p>
                                                <p className="text-slate-500 text-[11px]">
                                                    Shop: {item.product?.shop?.name || 'Bagoo Prime Store'}
                                                </p>
                                                {(item.color || item.size) && (
                                                    <p className="text-slate-400 text-[11px]">
                                                        Variant: {[item.color, item.size].filter(Boolean).join(' / ')}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="text-right shrink-0">
                                            <p className="text-slate-500 text-[11px]">
                                                <PriceSegments segments={quotedItems.get(item.id)?.price_segments} quantity={quotedItems.get(item.id)?.quantity ?? item.quantity} unitPrice={quotedItems.get(item.id)?.unit_price ?? Number(item.unit_price)} />
                                            </p>
                                            <p className="font-bold text-slate-900 text-sm">
                                                {quotePrice(quotedItems.get(item.id)?.subtotal ?? Number(item.product?.price) * item.quantity)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                                    <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-300 p-3">
                                        <span className="text-xs text-slate-600">Shop voucher · {appliedVouchers.find(offer => offer.shop_id === shop.shop_id)?.code ?? 'No voucher'}</span>
                                        <button type="button" disabled={pricing.pending || bestBusy} onClick={() => setPickerSlot(shop.shop_id)} className="min-h-11 rounded-xl border border-slate-300 px-3 text-xs font-bold text-[#C20836] disabled:opacity-50">Choose voucher</button>
                                    </div>
                                    <CheckoutVoucherBreakdown quote={quote} shopId={shop.shop_id} price={quotePrice} />
                                </section>)}
                            </div>
                        </div>

                        {/* 3. Logistics & Delivery Carrier */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    03
                                </div>
                                <h2 className="font-bold text-sm text-slate-900">
                                    Logistics Carrier & Delivery
                                </h2>
                            </div>

                            <div className="p-4 rounded-xl border border-slate-200 bg-slate-50/50 flex items-start justify-between gap-4 text-xs font-sans">
                                <div className="flex items-start gap-3.5">
                                    <div className="w-9 h-9 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center shrink-0 mt-0.5">
                                        {isHubPickup ? (
                                            <Store className="w-5 h-5 text-[#E00D42]" />
                                        ) : (
                                            <Truck className="w-5 h-5 text-[#E00D42]" />
                                        )}
                                    </div>
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2 font-bold text-slate-900 text-xs">
                                            <span>{isHubPickup ? 'Bayan Hub Municipal Station Network' : 'Bagoo Express Logistics'}</span>
                                            <span className={`px-2 py-0.5 rounded-xs text-[10px] font-sans font-bold ${
                                                isHubPickup ? 'bg-rose-100 text-[#E00D42]' : 'bg-slate-200 text-slate-800'
                                            }`}>
                                                {isHubPickup ? 'FREE SELF-PICKUP' : 'CONTIGUOUS ROAD FREIGHT'}
                                            </span>
                                        </div>
                                        <p className="text-slate-500 text-[11px] leading-relaxed">
                                            {isHubPickup
                                                ? 'Seller → Pickup Rider → Origin Bayan Hub → Mother Hub network → Destination Bayan Hub → Buyer collection.'
                                                : 'Seller → Pickup Rider → Origin Bayan Hub → Mother Hub network → Destination Bayan Hub → Delivery Rider → Buyer.'}
                                        </p>
                                        <p className="text-slate-400 text-[10px] font-sans">
                                            Follow recorded parcel updates in My Purchases after ordering.
                                        </p>
                                    </div>
                                </div>
                                <div className="text-right shrink-0">
                                    <span className="text-[10px] text-slate-400 block font-sans">SHIPPING RATE</span>
                                    <span className={`text-sm font-black ${finalShippingFee === 0 ? 'text-[#E00D42]' : 'text-slate-900'}`}>
                                        {quotePrice(finalShippingFee)}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* 4. Payment Method */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    04
                                </div>
                                <div className="flex items-center justify-between flex-1">
                                    <h2 className="font-bold text-sm text-slate-900">
                                        Payment Method
                                    </h2>
                                    <span className="text-[10px] font-sans font-bold px-2 py-0.5 rounded-xs bg-slate-200 text-slate-700">
                                        EXCLUSIVE PAYMENT MODE
                                    </span>
                                </div>
                            </div>

                            <div className="p-4 rounded-xl border border-slate-300 bg-slate-50 text-xs font-sans space-y-3">
                                <div className="flex items-start gap-3.5">
                                    <div className="w-9 h-9 rounded-lg bg-[#E00D42] text-white flex items-center justify-center shrink-0">
                                        <Wallet className="w-5 h-5" />
                                    </div>
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-2 font-bold text-slate-900 text-xs">
                                            <span>Cash on Delivery (COD)</span>
                                            <span className="px-2 py-0.5 rounded-xs bg-slate-900 text-white text-[10px] font-sans">CASH PAYMENT</span>
                                        </div>
                                        <p className="text-slate-600 text-[11px] leading-relaxed">
                                            {isHubPickup ? 'Pay the amount due in cash at the destination hub when you collect your parcel. Follow the collection instructions in order tracking.' : 'Pay the amount due in cash to the assigned delivery rider when your parcel is handed over. No advance online payment is required.'}
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 pt-2 border-t border-slate-200 text-[11px] text-slate-700 font-medium">
                                    <ShieldCheck className="w-4 h-4 text-[#E00D42] shrink-0" />
                                    <span>Review the final COD amount before confirming your order.</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    {/* Right Column: Order Total Breakdown */}
                    <div className="lg:col-span-4 space-y-6 font-sans text-xs">
                        
                        <div className="space-y-3 rounded-2xl border border-slate-300 bg-white p-5 shadow-2xs">
                            <div className="flex items-center justify-between gap-3">
                                <div><h3 className="font-bold text-slate-900">Bagoo voucher</h3><p className="mt-1 text-xs text-slate-600">{appliedVouchers.find(offer => offer.issuer === 'platform')?.code ?? 'No voucher'}</p></div>
                                <button type="button" disabled={pricing.pending || bestBusy} onClick={() => setPickerSlot(null)} className="min-h-11 rounded-xl border border-slate-300 px-3 text-xs font-bold text-[#C20836] disabled:opacity-50">Choose voucher</button>
                            </div>
                            <button type="button" onClick={useBestVouchers} disabled={!pricing.ready || bestBusy} className="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 disabled:opacity-50">{bestBusy ? 'Comparing offers…' : 'Use best vouchers'}</button>
                            <p className="text-xs leading-5 text-slate-600">One offer per shop and one Bagoo product or shipping offer. Manual choices and No voucher stay selected until you change them or they become unavailable.</p>
                            <p className="text-xs leading-5 text-slate-500">Cancelled orders keep their voucher use. Delivery issues or returns do not automatically refund a payment or restore voucher use.</p>
                            {voucherError && <p role="alert" className="text-xs text-rose-700">{voucherError}</p>}
                        </div>

                        {/* ORDER TOTAL SUMMARY */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 sticky top-24">
                            <h3 className="font-bold text-slate-900 text-sm pb-3 border-b border-slate-100">
                                Order Total Breakdown
                            </h3>

                            <CheckoutVoucherBreakdown quote={quote} price={quotePrice} />
                            <p className="text-xs text-slate-600">Payment mode: Cash on Delivery (COD)</p>

                            <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                <span className="font-bold text-slate-900">Total Payment:</span>
                                <span className="text-2xl font-black text-[#E00D42]">
                                    {quotePrice(grandTotal)}
                                </span>
                            </div>

                            <p role="status" aria-live="polite" className="text-xs text-slate-600">
                                {pricing.pending ? 'Updating prices, delivery fees and voucher eligibility…' : pricing.failure || 'Current prices and delivery fees checked.'}
                            </p>
                            {quote.shops.length > 1 && <div className="space-y-2 border-t border-slate-200 pt-3">
                                {quote.shops.map(shop => <div key={shop.shop_id} className="flex justify-between gap-3 text-xs text-slate-600">
                                    <span>{shop.shop_name}</span><span className="font-semibold">{quotePrice(shop.total)}</span>
                                </div>)}
                            </div>}

                            {validationError && (
                                <div role="alert" className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                                    {validationError}
                                </div>
                            )}

                            {/* KYC Verification Alert & Upload */}
                            {kycStatus !== 'approved' && (
                                <div className={`p-4 rounded-xl border space-y-3 ${
                                    kycStatus === 'pending_approval'
                                        ? 'bg-amber-50/90 border-amber-200 text-amber-900'
                                        : kycStatus === 'rejected'
                                        ? 'bg-rose-50/90 border-rose-200 text-rose-900'
                                        : 'bg-indigo-50/90 border-indigo-200 text-indigo-900'
                                }`}>
                                    <div className="flex items-start gap-2.5">
                                        {kycStatus === 'pending_approval' ? (
                                            <Clock className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                                        ) : kycStatus === 'rejected' ? (
                                            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                                        ) : (
                                            <ShieldAlert className="w-4 h-4 text-indigo-600 shrink-0 mt-0.5" />
                                        )}
                                        <div className="space-y-1">
                                            <h4 className="font-bold text-xs">
                                                {kycStatus === 'pending_approval' && 'ID Verification Pending'}
                                                {kycStatus === 'rejected' && 'ID Verification Action Required'}
                                                {kycStatus === 'none' && 'Government ID Required'}
                                            </h4>
                                            <p className="text-[11px] leading-relaxed opacity-90">
                                                {kycStatus === 'pending_approval' && 'Your ID is currently under compliance review. Browsing and carting are enabled, but purchasing is paused until verified.'}
                                                {kycStatus === 'rejected' && (kycFeedback || 'Your previous document could not be verified. Please re-upload a clear government ID.')}
                                                {kycStatus === 'none' && 'To comply with marketplace security, please submit a valid government ID to unlock checkout.'}
                                            </p>
                                        </div>
                                    </div>

                                    {(kycStatus === 'none' || kycStatus === 'rejected') && (
                                        <div className="pt-2 border-t border-indigo-200/50 space-y-2">
                                            <input
                                                ref={kycFileInputRef}
                                                type="file"
                                                accept="image/png,image/jpeg,image/webp,application/pdf"
                                                onChange={handleKycFileSelect}
                                                className="hidden"
                                            />
                                            <UploadHint />
                                            <button
                                                type="button"
                                                onClick={() => kycFileInputRef.current?.click()}
                                                className="w-full py-2 px-3 border border-dashed border-indigo-300 hover:border-indigo-400 bg-white rounded-lg text-center flex items-center justify-center gap-1.5 cursor-pointer transition text-[11px] font-semibold text-indigo-700 shadow-2xs"
                                            >
                                                <Upload className="w-3.5 h-3.5" />
                                                <span className="truncate">{kycFileName ? kycFileName : 'Upload Government ID (PNG, JPG, PDF)'}</span>
                                            </button>
                                            {kycForm.errors.id_document && (
                                                <p className="text-rose-600 text-[11px]">{kycForm.errors.id_document}</p>
                                            )}
                                            {kycFileName && (
                                                <button
                                                    type="button"
                                                    onClick={handleKycSubmit}
                                                    disabled={kycForm.processing}
                                                    className="w-full py-2 bg-indigo-600 hover:bg-indigo-700 active:scale-[0.98] text-white font-bold rounded-lg text-xs transition cursor-pointer disabled:opacity-50 flex items-center justify-center gap-1.5 shadow-xs"
                                                >
                                                    <span>{kycForm.processing ? 'Submitting ID...' : 'Submit ID for Verification'}</span>
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={processing || bestBusy || !pricing.ready || kycStatus !== 'approved'}
                                className={`w-full py-3.5 text-white font-bold rounded-xl transition shadow-md flex items-center justify-center gap-2 text-xs ${
                                    kycStatus !== 'approved'
                                        ? 'bg-slate-400 cursor-not-allowed opacity-80'
                                        : 'bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] cursor-pointer'
                                }`}
                            >
                                <span>
                                    {processing
                                        ? 'Processing Order...'
                                        : kycStatus === 'pending_approval'
                                        ? 'Purchases Paused (Awaiting ID Approval)'
                                        : kycStatus === 'rejected'
                                        ? 'Action Required: Re-upload ID'
                                        : kycStatus === 'none'
                                        ? 'Upload Valid ID to Enable Purchasing'
                                        : 'Review & Place Order'}
                                </span>
                            </button>

                            <div className="flex items-center justify-center gap-2 text-[11px] text-slate-400 pt-2 font-sans">
                                <ShieldCheck className="w-4 h-4 text-[#E00D42]" />
                                <span>BagooPH Buyer Protection Guarantee</span>
                            </div>
                        </div>
                    </div>

                </form>

                {/* ORDER PLACEMENT CONFIRMATION MODAL */}
                <Dialog open={showConfirmModal} onClose={() => { if (!processing) setShowConfirmModal(false); }} className="relative z-50">
                    <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-xs overflow-y-auto flex items-center justify-center p-3 sm:p-4 animate-fade-in">
                        <DialogPanel className="bg-white rounded-3xl max-w-xl w-full max-h-[92vh] overflow-y-auto p-4 sm:p-8 shadow-2xl border border-slate-200 space-y-5 sm:space-y-6 font-sans my-auto">
                            
                            {/* Modal Header */}
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2 text-xs font-semibold text-[#E00D42]">
                                        <ShoppingBag className="w-4 h-4" />
                                        <span>Final Review Step</span>
                                    </div>
                                    <DialogTitle as="h3" className="text-lg sm:text-xl font-bold text-slate-900 mt-0.5">
                                        Confirm & Place Order
                                    </DialogTitle>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmModal(false)}
                                    disabled={processing}
                                    aria-label="Close order review"
                                    className="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            {/* Summary Cards */}
                            <div className="space-y-3 font-sans text-xs">
                                
                                {/* Recipient & Destination Summary */}
                                <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-1">
                                    <span className="text-[11px] text-slate-400 font-semibold block flex items-center gap-1.5">
                                        <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>{isHubPickup ? 'Recipient address' : 'Delivery destination'}</span>
                                    </span>
                                    <p className="font-bold text-slate-900 text-sm">{data.recipient_name} ({data.recipient_phone})</p>
                                    <p className="break-words text-slate-600 text-xs">{[data.shipping_address, data.destination_barangay, data.shipping_city, data.shipping_province, data.shipping_postal_code].filter(Boolean).join(', ')}</p>
                                    {isHubPickup && selectedPickupHub && <div className="border-t border-slate-300 pt-2">
                                        <p className="font-bold text-slate-900">Collect at {selectedPickupHub.name} ({selectedPickupHub.code})</p>
                                        <p className="break-words text-slate-600">{[selectedPickupHub.address, selectedPickupHub.city_municipality, selectedPickupHub.province].filter(Boolean).join(', ')}</p>
                                    </div>}
                                </div>

                                {/* Payment Mode & Voucher Summary */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[11px] text-slate-400 font-semibold block">Payment Mode</span>
                                        <span className="font-bold text-slate-700 font-sans">CASH ON DELIVERY (COD)</span>
                                    </div>
                                    <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[11px] text-slate-400 font-semibold block">Applied Vouchers</span>
                                        <span className="font-bold text-slate-800">{appliedVouchers.length ? appliedVouchers.map(offer => offer.code).join(', ') : 'None'}</span>
                                    </div>
                                </div>

                                {/* Items Preview List */}
                                <div className="p-4 bg-slate-50 border border-slate-200 rounded-2xl space-y-2.5 max-h-40 overflow-y-auto">
                                    <span className="text-[11px] text-slate-400 font-semibold block">Package Items ({items.length})</span>
                                    <div className="divide-y divide-slate-200">
                                        {items.map(item => (
                                            <div key={item.id} className="py-2 flex items-center justify-between text-xs">
                                                <div className="truncate pr-2">
                                                    <span className="font-semibold text-slate-800 block truncate">{item.product?.name}</span>
                                                    <span className="text-slate-500 text-[11px]">Qty: <PriceSegments segments={quotedItems.get(item.id)?.price_segments} quantity={quotedItems.get(item.id)?.quantity ?? item.quantity} unitPrice={quotedItems.get(item.id)?.unit_price ?? Number(item.unit_price)} /></span>
                                                </div>
                                                <span className="font-bold text-slate-900 shrink-0">{quotePrice(quotedItems.get(item.id)?.subtotal ?? Number(item.product?.price) * item.quantity)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Total Amount */}
                                <div className="p-4 bg-rose-50/70 border border-rose-200 rounded-2xl flex items-center justify-between">
                                    <span className="font-semibold text-slate-800 text-xs">Total Amount Due:</span>
                                    <span className="text-xl font-black text-[#E00D42]">{quotePrice(grandTotal)}</span>
                                </div>
                            </div>

                            {/* Verification Checkbox */}
                            <label className="flex items-start gap-2.5 cursor-pointer text-xs text-slate-700 select-none p-1 font-sans">
                                <input
                                    type="checkbox"
                                    checked={hasConfirmedAgreement}
                                    onChange={(e) => setHasConfirmedAgreement(e.target.checked)}
                                    className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                />
                                <span>I have reviewed my shipping details and confirm this order placement.</span>
                            </label>

                            {/* Modal Action Buttons */}
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 font-sans text-xs">
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmModal(false)}
                                    disabled={processing}
                                    className="py-3 px-4 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 font-semibold transition text-center cursor-pointer"
                                >
                                    Edit Details
                                </button>
                                <button
                                    type="button"
                                    disabled={!hasConfirmedAgreement || processing || bestBusy || !pricing.ready}
                                    onClick={submitFinalOrder}
                                    className="py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold transition text-center shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 cursor-pointer"
                                >
                                    <span>{processing ? 'Submitting...' : 'Confirm & Place Order'}</span>
                                </button>
                            </div>

                        </DialogPanel>
                    </div>
                </Dialog>

                {pickerSlot !== undefined && <BuyerVoucherPicker
                    title={pickerSlot === null ? 'Bagoo voucher' : `Shop voucher · ${quote.shops.find(shop => shop.shop_id === pickerSlot)?.shop_name ?? 'Shop'}`}
                    shopId={pickerSlot} currentId={pickerSlot === null ? data.platform_voucher_revision_id : data.shop_voucher_revision_ids[pickerSlot] ?? null}
                    context={quoteInput} onClose={() => setPickerSlot(undefined)}
                    onApply={offer => { if (offer) selectOffer(offer); else removeVoucher(pickerSlot); setPickerSlot(undefined); }}
                    onCode={code => { applyVoucher(code); setPickerSlot(undefined); }} />}
            </div>
        </BuyerLayout>
    );
}
