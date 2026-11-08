import PriceSegments from '@/Components/PriceSegments';
import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import BuyerPaymentChoice from '@/Components/BuyerPaymentChoice';
import PhoneInput from '@/Components/PhoneInput';
import ShippingCalculation from '@/Components/ShippingCalculation';
import LocationPinPicker from '@/Components/LocationPinPicker';
import useCheckoutGps from '@/hooks/useCheckoutGps';
import { changedDeliveryAddress, withDoorstepDelivery, validDeliveryPin, defaultLogisticsCompanyId, type CheckoutAddressField } from '@/utils/checkoutLocation';
import { checkoutErrorMessages, deliveryLocationErrorMessage } from '@/utils/checkoutErrorMessages';
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
    Building2,
    Package
} from 'lucide-react';

export interface PickupHubItem {
    logistics_company_id: number;
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
    walletBalanceCents?: number | null;
    logisticsCompanies?: { id: number; name: string; logo?: string | null; base_shipping_cents: number; included_weight_grams: number; additional_kg_cents: number }[];
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
    walletBalanceCents = 0,
    logisticsCompanies = [],
}: Props) {
    const { flash } = usePage<PageProps>().props;

    const [voucherCodeInput, setVoucherCodeInput] = useState('');
    const [voucherError, setVoucherError] = useState<string>('');

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
        logistics_company_id: (logisticsCompanies[0]?.id ?? '') as string | number,
        payment_method: 'cod',
        notes: '',
        voucher_code: '',
        platform_voucher_revision_id: null as number | null,
        shop_voucher_revision_ids: {} as Record<number, number | null>,
        save_address: !initialAddress && addresses.length < addressBookLimit,
        saved_address_id: (initialAddress?.id ?? null) as number | null,
        saved_address_location_token: (initialAddress?.location_token ?? null) as string | null,
    });
    const [isEditingAddress, setIsEditingAddress] = useState(!initialAddress || !validDeliveryPin(initialAddress.latitude ?? null, initialAddress.longitude ?? null));
    const gps = useCheckoutGps(point => setData(previous => ({ ...previous, shipping_latitude: point.latitude, shipping_longitude: point.longitude })));

    const handleAddressChange = (addressId: string) => {
        gps.cancel();
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
                saved_address_id: null,
                saved_address_location_token: null,
                logistics_company_id: '',
                pickup_hub_id: '',
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
                    saved_address_id: found.id,
                    saved_address_location_token: found.location_token ?? null,
                    logistics_company_id: '',
                    pickup_hub_id: '',
                }));
            }
        }
    };

    const updateDeliveryAddress = (field: CheckoutAddressField, value: string) => {
        if (data[field] === value) return;
        gps.cancel();
        if (data.saved_address_id !== null) setSelectedAddressId('new');
        setData(previous => ({
            ...changedDeliveryAddress(previous, field, value, addresses.length < addressBookLimit),
            logistics_company_id: '',
            pickup_hub_id: '',
        }));
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
        logistics_company_id: data.logistics_company_id,
        shipping_postal_code: data.shipping_postal_code,
        destination_barangay: data.destination_barangay,
        pickup_hub_id: data.delivery_type === 'hub_self_pickup' ? data.pickup_hub_id : '',
        revision: JSON.stringify([items.map(item => [item.id, item.quantity, item.product?.price]),
            data.shipping_address, data.destination_barangay, data.shipping_postal_code,
            data.shipping_latitude, data.shipping_longitude, checkoutToken, flash?.error]),
    };
    const pricing = usePurchaseQuote(quoteInput, initialQuote, availableVouchers);
    const quote = pricing.quote;
    const servingCompanies = pricing.companies ?? logisticsCompanies;
    const completeDoorstepDestination = Boolean(data.shipping_province.trim() && data.shipping_city.trim()
        && /^[0-9]{4}$/.test(data.shipping_postal_code));
    const noServingCompany = data.delivery_type === 'doorstep' && completeDoorstepDestination
        && pricing.current && pricing.companies !== undefined && pricing.companies.length === 0;
    const selectedCompany = servingCompanies.find(company => String(company.id) === String(data.logistics_company_id));
    const hasInvalidDeliveryLocation = Boolean(errors.shipping_latitude || errors.shipping_longitude);
    const visibleCheckoutErrors = checkoutErrorMessages(errors);
    const routeDetailsReady = completeDoorstepDestination
        && (data.delivery_type === 'doorstep' || Boolean(data.pickup_hub_id));
    const canShowQuote = pricing.ready && routeDetailsReady && Boolean(selectedCompany);
    const quoteStatus = pricing.pending ? 'Updating prices, delivery fees and voucher eligibility…'
        : pricing.failure ? pricing.failure
            : noServingCompany ? 'No eligible logistics company serves this address and every seller in your Bag. Try another address.'
                : !completeDoorstepDestination ? 'Enter a province, city and four-digit postal code to check delivery coverage.'
                    : data.delivery_type === 'hub_self_pickup' && !data.pickup_hub_id ? 'Choose a Bayan Hub to check collection coverage.'
                    : !selectedCompany ? 'Choose an eligible logistics company to confirm your delivery total.'
                        : 'Current prices and delivery fees checked.';
    useEffect(() => {
        if (!pricing.companies) return;
        const defaultCompanyId = defaultLogisticsCompanyId(pricing.companies, data.logistics_company_id);
        if (String(defaultCompanyId) !== String(data.logistics_company_id)) {
            setData(previous => ({ ...previous, logistics_company_id: defaultCompanyId, pickup_hub_id: '' }));
        }
    }, [pricing.companies, data.logistics_company_id]);
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

        if (noServingCompany || !data.logistics_company_id || !selectedCompany) {
            setValidationError(noServingCompany
                ? 'No eligible logistics company currently serves this address and every seller in your Bag. Try another address.'
                : 'Choose a logistics company that serves this delivery route.');
            return;
        }

        if (!data.recipient_name.trim() || !data.recipient_phone.trim() || !data.shipping_address.trim()
            || !data.shipping_city.trim() || !data.shipping_province.trim() || !data.destination_barangay.trim() || !data.shipping_postal_code.trim()) {
            setValidationError('Please complete all required recipient and address fields.');
            return;
        }

        if (data.delivery_type === 'doorstep' && !validDeliveryPin(data.shipping_latitude, data.shipping_longitude)) {
            setValidationError(deliveryLocationErrorMessage);
            return;
        }
        if (data.delivery_type === 'doorstep' && data.saved_address_id === null && !data.save_address) {
            setValidationError(addresses.length >= addressBookLimit ? 'Choose a saved address, or remove one in your address book before saving a new delivery address.' : 'Save this delivery address, or choose an existing saved address.');
            return;
        }

        setHasConfirmedAgreement(false);
        setShowConfirmModal(true);
    };

    const submitFinalOrder = () => {
        if (orderPending.current || processing) return;
        if (!canShowQuote || !pricing.ready || bestBusy || !hasConfirmedAgreement || kycStatus !== 'approved') {
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

    const quotePrice = (amount: number) => pricing.pending ? 'Updating…' : pricing.failure || noServingCompany ? 'Unavailable'
        : !routeDetailsReady ? 'Add delivery details' : !selectedCompany ? 'Choose a company' : formatPrice(amount);

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
                            {visibleCheckoutErrors.map(error => <li key={error}>{error}</li>)}
                        </ul>
                        {errors.checkout_token && <Link href={route('checkout.index', { items: data.item_ids.join(','), ...(Object.keys(data.item_quantities).length === 1 ? { quantity: Object.values(data.item_quantities)[0] } : {}) })} className="font-semibold underline">Reload checkout</Link>}
                    </div>
                )}

                <form onSubmit={handlePromptConfirmation} className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left Column: Forms */}
                    <div className="lg:col-span-8 space-y-6 font-sans">
                        
                        {/* 0. Items in this Order (Prominently Placed at the Top) */}
                        <div className="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-2xs space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2">
                                    <Package className="h-4 w-4 text-[#E00D42]" />
                                    <h2 className="font-bold text-xs uppercase tracking-wider text-slate-900">
                                        Items in this Order ({items.length})
                                    </h2>
                                </div>
                                <Link href={route('buyer.cart')} className="text-[11px] font-semibold text-[#E00D42] hover:underline flex items-center gap-1">
                                    <span>Modify Bag</span>
                                </Link>
                            </div>

                            <div className="divide-y divide-slate-100 font-sans text-xs">
                                {quote.shops.map(shop => (
                                    <div key={shop.shop_id} className="py-3 first:pt-0 last:pb-0 space-y-3" aria-label={shop.shop_name}>
                                        <div className="flex items-center gap-2 text-xs font-semibold text-slate-700">
                                            <Store className="h-3.5 w-3.5 text-[#E00D42]" />
                                            <span>{shop.shop_name}</span>
                                        </div>

                                        <div className="space-y-3">
                                            {items.filter(item => item.product?.shop?.id === shop.shop_id).map((item) => (
                                                <div key={item.id} className="flex items-center justify-between gap-3">
                                                    <div className="flex items-center gap-3 min-w-0">
                                                        <span className="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-slate-200 bg-slate-50 text-slate-400">
                                                            <Package className="h-4 w-4" aria-hidden="true" />
                                                            {((item.color && item.product?.variants?.colors?.find(c => c.name === item.color)?.image_url) || item.product?.featured_image) && (
                                                                <img
                                                                    src={(item.color && item.product?.variants?.colors?.find(c => c.name === item.color)?.image_url) || item.product?.featured_image || ''}
                                                                    alt=""
                                                                    loading="lazy"
                                                                    onError={event => event.currentTarget.remove()}
                                                                    className="absolute inset-0 h-full w-full object-cover"
                                                                />
                                                            )}
                                                        </span>
                                                        <div className="min-w-0">
                                                            <p className="font-bold text-slate-900 truncate text-xs">{item.product?.name}</p>
                                                            <div className="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5">
                                                                <span className="font-semibold text-slate-700">Qty: {item.quantity}</span>
                                                                {(item.color || item.size) && (
                                                                    <span>· {[item.color, item.size].filter(Boolean).join(' / ')}</span>
                                                                )}
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div className="text-right shrink-0">
                                                        <p className="font-bold text-slate-900 text-xs tabular-nums">
                                                            {quotePrice(quotedItems.get(item.id)?.subtotal ?? Number(item.product?.price) * item.quantity)}
                                                        </p>
                                                        {item.quantity > 1 && (
                                                            <p className="text-[10px] text-slate-400 mt-0.5">
                                                                {formatPrice(item.product?.price)} each
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        {/* Shop Voucher selector */}
                                        <div className="flex items-center justify-between text-xs py-1.5 px-3 rounded-xl bg-slate-50 border border-slate-200/60">
                                            <span className="flex items-center gap-1.5 text-[11px] text-slate-600">
                                                <Tag className="h-3 w-3 text-[#E00D42]" />
                                                <span>Shop voucher: <strong className="text-slate-800">{appliedVouchers.find(offer => offer.shop_id === shop.shop_id)?.code ?? 'No voucher applied'}</strong></span>
                                            </span>
                                            <button
                                                type="button"
                                                disabled={pricing.pending || bestBusy}
                                                onClick={() => setPickerSlot(shop.shop_id)}
                                                className="text-[11px] font-bold text-[#E00D42] hover:underline disabled:opacity-50 cursor-pointer"
                                            >
                                                Choose voucher
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* 1. Fulfillment & Delivery */}
                        <div className="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/80 shadow-2xs space-y-5">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-7 h-7 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    01
                                </div>
                                <h2 className="font-bold text-sm text-slate-900">
                                    Fulfillment Method & Destination
                                </h2>
                            </div>

                            {/* Minimalist Logistics Carrier Dropdown */}
                            <div>
                                <label htmlFor="checkout-carrier-select" className="block text-xs font-bold text-slate-800 mb-1.5">
                                    Logistics Carrier
                                </label>
                                <select
                                    id="checkout-carrier-select"
                                    required
                                    value={data.logistics_company_id}
                                    onChange={event => setData({ ...data, logistics_company_id: event.target.value, pickup_hub_id: '' })}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs font-medium text-slate-900 shadow-2xs focus:border-[#E00D42] focus:ring-[#E00D42]"
                                >
                                    <option value="">Choose a logistics carrier</option>
                                    {servingCompanies.map(company => (
                                        <option key={company.id} value={company.id}>{company.name}</option>
                                    ))}
                                </select>
                                {noServingCompany && (
                                    <span className="mt-1.5 block text-xs text-rose-600">
                                        No approved logistics carrier currently serves this route.
                                    </span>
                                )}
                                {errors.logistics_company_id && (
                                    <span className="mt-1 block text-xs text-rose-700">{errors.logistics_company_id}</span>
                                )}
                            </div>
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
                                    <input type="radio" name="delivery_type" value="doorstep" checked={data.delivery_type === 'doorstep'} onChange={() => setData(previous => withDoorstepDelivery(previous, addresses.length < addressBookLimit))} className="sr-only" />
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
                                </label>
                            </fieldset>

                            {/* When Bayan Hub Pickup is selected */}
                            {data.delivery_type === 'hub_self_pickup' && (
                                <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-4">
                                    <div className="flex items-start gap-2.5">
                                        <Building2 className="w-5 h-5 text-[#E00D42] shrink-0 mt-0.5" />
                                        <div>
                                            <h3 className="text-xs font-bold text-slate-900">Select Collection Station (Bayan Hub)</h3>
                                            <p className="text-[11px] text-slate-500 mt-0.5">
                                                Select your local Bayan Hub for free self-collection.
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
                                            {pickupHubs.filter(hub => String(hub.logistics_company_id) === String(data.logistics_company_id)).map((hub) => (
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
                                                    </>
                                                );
                                            })()}
                                        </div>
                                    )}

                                </div>
                            )}
                            <div className="space-y-4">
                                <div className="flex items-center justify-between pb-1">
                                    <h3 className="text-sm font-bold text-slate-900">Delivery Address</h3>
                                    {addresses.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => setIsEditingAddress(!isEditingAddress)}
                                            className="text-xs font-bold text-[#E00D42] hover:underline cursor-pointer"
                                        >
                                            {isEditingAddress ? 'Minimize' : 'Edit / Change'}
                                        </button>
                                    )}
                                </div>

                                {/* Minimized Single-Line Address Summary */}
                                {!isEditingAddress && (
                                    <div className="flex items-center justify-between p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                                        <div className="flex items-center gap-2.5 min-w-0">
                                            <MapPin className="w-4 h-4 text-[#E00D42] shrink-0" />
                                            <div className="min-w-0">
                                                <p className="text-xs font-bold text-slate-900 truncate">
                                                    {data.recipient_name || 'No recipient name'} <span className="font-normal text-slate-500">({data.recipient_phone || 'No phone'})</span>
                                                </p>
                                                <p className="text-[11px] text-slate-600 truncate mt-0.5">
                                                    {[data.shipping_address, data.destination_barangay, data.shipping_city, data.shipping_province, data.shipping_postal_code].filter(Boolean).join(', ') || 'No address line'}
                                                </p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setIsEditingAddress(true)}
                                            className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] shrink-0 ml-3 px-3 py-1.5 rounded-lg border border-slate-300 bg-white shadow-2xs hover:bg-slate-50 transition cursor-pointer"
                                        >
                                            Edit
                                        </button>
                                    </div>
                                )}

                                {/* Collapsible Address Editor Form and Map Pin Picker */}
                                {isEditingAddress && (
                                    <div className="space-y-4 pt-1">
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
                                                    onChange={(e) => {
                                                        handleAddressChange(e.target.value);
                                                        if (e.target.value !== 'new') {
                                                            setIsEditingAddress(false);
                                                        }
                                                    }}
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
                                                    onChange={(e) => updateDeliveryAddress('shipping_address', e.target.value)}
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
                                                    onChange={(e) => updateDeliveryAddress('destination_barangay', e.target.value)}
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
                                                    onChange={(e) => updateDeliveryAddress('shipping_city', e.target.value)}
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
                                                    onChange={(e) => updateDeliveryAddress('shipping_province', e.target.value)}
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
                                                    onChange={(e) => updateDeliveryAddress('shipping_postal_code', e.target.value)}
                                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                                />
                                                {errors.shipping_postal_code && <p id="checkout-shipping_postal_code-error" className="text-rose-700 text-[11px] mt-1">{errors.shipping_postal_code}</p>}
                                            </div>

                                            {/* Delivery pin and landmark block */}
                                            <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 sm:col-span-2 space-y-2.5">
                                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                                    <span className="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                                        <Crosshair className="w-3.5 h-3.5 text-[#E00D42]" />
                                                        Delivery location (required for doorstep delivery)
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={gps.capture}
                                                        disabled={gps.locating}
                                                        className="min-h-[44px] w-fit rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-[#E00D42] hover:text-[#E00D42] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42] flex items-center gap-1.5 shadow-2xs cursor-pointer disabled:opacity-50"
                                                    >
                                                        <MapPin className="w-3 h-3 text-[#E00D42]" />
                                                        <span>{gps.locating ? 'Acquiring GPS...' : 'Pin My Current Location'}</span>
                                                    </button>
                                                </div>

                                                {gps.status && (
                                                    <p role="status" className="text-xs font-sans text-slate-700 bg-slate-100 px-3 py-2 rounded-lg">
                                                        {gps.status}
                                                    </p>
                                                )}

                                                <LocationPinPicker
                                                    value={data.shipping_latitude !== '' && data.shipping_longitude !== '' && Number.isFinite(Number(data.shipping_latitude)) && Number.isFinite(Number(data.shipping_longitude))
                                                        ? { latitude: Number(data.shipping_latitude), longitude: Number(data.shipping_longitude) } : null}
                                                    onChange={point => { gps.cancel(); setData(previous => ({ ...previous, shipping_latitude: point.latitude, shipping_longitude: point.longitude })); }}
                                                />
                                                {hasInvalidDeliveryLocation && <p role="alert" className="text-xs text-rose-700">{deliveryLocationErrorMessage}</p>}

                                                <div>
                                                    <label htmlFor="checkout-landmark" className="block text-[10px] text-slate-600 font-bold mb-0.5">
                                                        Delivery landmark (optional)
                                                    </label>
                                                    <input
                                                        id="checkout-landmark"
                                                        aria-invalid={Boolean(errors.landmark)}
                                                        aria-describedby={errors.landmark ? 'checkout-landmark-error' : undefined}
                                                        type="text"
                                                        list="checkout-landmark-suggestions"
                                                        placeholder="e.g. Tapat ng Barangay Hall, Dilaw na Gate na may black fence, tabi ng tindahan"
                                                        maxLength={255}
                                                        minLength={5}
                                                        value={data.landmark}
                                                        onChange={(e) => setData('landmark', e.target.value)}
                                                        className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs"
                                                    />
                                                    <datalist id="checkout-landmark-suggestions">
                                                        <option value="Near the barangay hall" />
                                                        <option value="Near the public market" />
                                                        <option value="Near the school" />
                                                        <option value="Near the church" />
                                                        <option value="Near the health center" />
                                                        <option value="At the building gate" />
                                                    </datalist>
                                                    {errors.landmark && <p id="checkout-landmark-error" className="text-rose-700 text-[11px] mt-1">{errors.landmark}</p>}
                                                </div>
                                            </div>

                                            {selectedAddressId === 'new' && (
                                                <label className="flex items-center gap-2 cursor-pointer text-xs text-slate-700 select-none pt-1 sm:col-span-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={data.save_address}
                                                        disabled={addresses.length >= addressBookLimit || !isHubPickup}
                                                        onChange={(e) => setData('save_address', e.target.checked)}
                                                        className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                                    />
                                                    <span>{addresses.length >= addressBookLimit ? (!isHubPickup ? `Your address book has ${addressBookLimit} addresses. Choose one or remove an address before saving a new one.` : `Your address book has ${addressBookLimit} addresses. You can use this address for this order without saving it.`) : !isHubPickup ? 'This delivery address and pin will be saved to your address book.' : 'Save this address to my address book'}</span>
                                                </label>
                                            )}
                                        </div>

                                        <div className="flex justify-end pt-2">
                                            <button
                                                type="button"
                                                onClick={() => setIsEditingAddress(false)}
                                                className="px-4 py-2 rounded-xl bg-slate-900 text-white text-xs font-bold hover:bg-slate-800 transition shadow-2xs cursor-pointer"
                                            >
                                                Done / Minimize Address
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* 2. Payment Method */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    02
                                </div>
                                <div className="flex items-center justify-between flex-1">
                                    <h2 className="font-bold text-sm text-slate-900">
                                        Payment Method
                                    </h2>
                                </div>
                            </div>

                            <BuyerPaymentChoice value={data.payment_method} onChange={method => setData('payment_method', method)} balanceCents={walletBalanceCents} disabled={processing} />
                            {errors.payment_method && <p role="alert" className="text-xs text-rose-700">{errors.payment_method}</p>}
                        </div>

                    </div>

                    {/* Right Column: Order Total Breakdown */}
                    <div className="lg:col-span-4 space-y-6 font-sans text-xs">
                        
                        <div className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xs">
                            <div className="flex items-center justify-between gap-3">
                                <div><h3 className="flex items-center gap-2 font-bold text-slate-900"><Gift className="h-4 w-4 text-[#C20836]" aria-hidden="true" />Bagoo voucher</h3><p className="mt-1 text-xs text-slate-600">{appliedVouchers.find(offer => offer.issuer === 'platform')?.code ?? 'No voucher'}</p></div>
                                <button type="button" disabled={pricing.pending || bestBusy} onClick={() => setPickerSlot(null)} className="flex min-h-11 items-center gap-1.5 rounded-xl border border-slate-300 px-3 text-xs font-bold text-[#C20836] disabled:opacity-50 hover:bg-slate-50 transition"><Tag className="h-3.5 w-3.5" aria-hidden="true" />Choose voucher</button>
                            </div>
                            <button type="button" onClick={useBestVouchers} disabled={!pricing.ready || bestBusy} className="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 transition disabled:opacity-50">{bestBusy ? 'Comparing offers…' : 'Use best vouchers'}</button>
                            {voucherError && <p role="alert" className="text-xs text-rose-700">{voucherError}</p>}
                        </div>

                        {/* ORDER TOTAL SUMMARY */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 sticky top-24">
                            <h3 className="font-bold text-slate-900 text-sm pb-3 border-b border-slate-100">
                                Order Total Breakdown
                            </h3>

                            <CheckoutVoucherBreakdown quote={quote} price={quotePrice} />
                            <p className="text-xs text-slate-600">Payment mode: {data.payment_method === 'wallet' ? 'Bagoo Wallet · virtual credits' : 'Cash on Delivery (COD)'}</p>

                            <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                <span className="font-bold text-slate-900">Total Payment:</span>
                                <span className="text-2xl font-black text-[#E00D42]">
                                    {quotePrice(grandTotal)}
                                </span>
                            </div>

                            <p role="status" aria-live="polite" className="text-xs text-slate-600">{quoteStatus}</p>
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
                                disabled={processing || bestBusy || !canShowQuote || !pricing.ready || kycStatus !== 'approved'}
                                className={`w-full py-3.5 text-white font-bold rounded-xl transition shadow-md flex items-center justify-center gap-2 text-xs ${
                                    kycStatus !== 'approved'
                                        ? 'bg-slate-400 cursor-not-allowed opacity-80'
                                        : 'bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] cursor-pointer'
                                }`}
                            >
                                {processing ? (
                                    <div className="flex items-center justify-center gap-2.5">
                                        <div className="relative flex flex-col items-center justify-center w-12 h-6 overflow-hidden">
                                            <Truck className="w-4 h-4 text-white animate-truck-drive" />
                                            <div className="w-16 flex gap-1 animate-road-dash overflow-hidden">
                                                <span className="w-3 h-0.5 bg-white/70 rounded-full shrink-0" />
                                                <span className="w-2 h-0.5 bg-white/50 rounded-full shrink-0" />
                                                <span className="w-4 h-0.5 bg-white/80 rounded-full shrink-0" />
                                                <span className="w-2 h-0.5 bg-white/60 rounded-full shrink-0" />
                                            </div>
                                        </div>
                                        <span className="tracking-wide font-extrabold">Summing & Processing Order…</span>
                                    </div>
                                ) : (
                                    <span>
                                        {kycStatus === 'pending_approval'
                                            ? 'Purchases Paused (Awaiting ID Approval)'
                                            : kycStatus === 'rejected'
                                            ? 'Action Required: Re-upload ID'
                                            : kycStatus === 'none'
                                            ? 'Upload Valid ID to Enable Purchasing'
                                            : 'Review & Place Order'}
                                    </span>
                                )}
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
                                        <span className="font-bold text-slate-700 font-sans">{data.payment_method === 'wallet' ? 'BAGOO WALLET · VIRTUAL CREDITS' : 'CASH ON DELIVERY (COD)'}</span>
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

                            {/* Interactive Dispatch Processing Animation Card */}
                            {processing && (
                                <div className="p-4 rounded-2xl bg-gradient-to-r from-rose-50 via-red-50 to-amber-50 border border-red-200 shadow-sm animate-fade-in font-sans">
                                    <div className="flex items-center gap-3">
                                        <div className="relative w-12 h-12 rounded-xl bg-[#E00D42] text-white flex flex-col items-center justify-center shadow-md overflow-hidden shrink-0">
                                            <Truck className="w-5 h-5 text-white animate-truck-drive" />
                                            {/* Road dash lines summing up underneath the truck */}
                                            <div className="w-16 flex gap-1 animate-road-dash overflow-hidden mt-1">
                                                <span className="w-2.5 h-0.5 bg-white/70 rounded-full shrink-0" />
                                                <span className="w-1.5 h-0.5 bg-white/50 rounded-full shrink-0" />
                                                <span className="w-3 h-0.5 bg-white/90 rounded-full shrink-0" />
                                            </div>
                                        </div>
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between text-xs font-bold text-slate-900">
                                                <span>Connecting to BagooPH Express Hub</span>
                                                <span className="text-[#E00D42] font-black text-[11px] animate-pulse">Summing items & route…</span>
                                            </div>
                                            {/* Moving line summation progress */}
                                            <div className="relative w-full h-1.5 bg-slate-200/90 rounded-full overflow-hidden mt-2">
                                                <div className="absolute inset-y-0 w-2/5 bg-gradient-to-r from-[#E00D42] to-amber-500 rounded-full animate-line-sum" />
                                            </div>
                                            <p className="text-[10px] text-slate-500 mt-1.5">Locking inventory with verified store and reserving doorstep COD dispatch slot.</p>
                                        </div>
                                    </div>
                                </div>
                            )}

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
                                    disabled={!hasConfirmedAgreement || processing || bestBusy || !canShowQuote || !pricing.ready}
                                    onClick={submitFinalOrder}
                                    className="py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold transition text-center shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2 cursor-pointer"
                                >
                                    {processing ? (
                                        <div className="flex items-center justify-center gap-2">
                                            <div className="relative flex flex-col items-center justify-center w-10 h-5 overflow-hidden">
                                                <Truck className="w-3.5 h-3.5 text-white animate-truck-drive" />
                                                <div className="w-14 flex gap-1 animate-road-dash overflow-hidden">
                                                    <span className="w-2.5 h-0.5 bg-white/70 rounded-full shrink-0" />
                                                    <span className="w-1.5 h-0.5 bg-white/50 rounded-full shrink-0" />
                                                    <span className="w-3 h-0.5 bg-white/90 rounded-full shrink-0" />
                                                </div>
                                            </div>
                                            <span className="font-extrabold tracking-wide">Dispatching Order…</span>
                                        </div>
                                    ) : (
                                        <span>Confirm & Place Order</span>
                                    )}
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
