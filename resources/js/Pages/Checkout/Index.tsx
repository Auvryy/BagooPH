import PriceSegments from '@/Components/PriceSegments';
import UploadHint from '@/Components/UploadHint';
import React, { useState, useEffect, useRef } from 'react';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import BuyerPaymentChoice from '@/Components/BuyerPaymentChoice';
import PhoneInput from '@/Components/PhoneInput';
import CheckoutRoutePreview from '@/Components/CheckoutRoutePreview';
import LocationPinPicker from '@/Components/LocationPinPicker';
import AddressLocationFields from '@/Components/AddressLocationFields';
import useCheckoutGps from '@/hooks/useCheckoutGps';
import { changedDeliveryAddress, changedDeliveryLocation, withDoorstepDelivery, validDeliveryPin, defaultLogisticsCompanyId, matchingPickupHubs, defaultPickupHubId, estimateSelfPickupFeeFromDoorstep, type CheckoutAddressField } from '@/utils/checkoutLocation';
import type { AddressLocation } from '@/utils/addressLocations';
import { checkoutErrorMessages, deliveryLocationErrorMessage } from '@/utils/checkoutErrorMessages';
import BuyerVoucherPicker from '@/Components/Vouchers/BuyerVoucherPicker';
import CheckoutVoucherBreakdown from '@/Components/Vouchers/CheckoutVoucherBreakdown';
import axios from 'axios';
import usePurchaseQuote, { PurchaseQuote, VoucherOffer } from '@/hooks/usePurchaseQuote';
import { chooseVoucher, withoutVoucher, repairedVoucherSelection, isVoucherRequestCurrent } from '@/utils/voucherSelection';
import { Cart, CartItem, PageProps, User, Address } from '@/types';
import { ArrowLeft, ChevronDown, ChevronUp, Truck, ShieldCheck, Check, Tag, Clock, X, Gift, Upload, Store, Crosshair, Package } from 'lucide-react';
import { Button, buttonClasses, Notice, Panel, inputClasses, labelClasses, fieldMessage } from '@/Components/ui';

export interface PickupHubItem {
    logistics_company_id: number;
    id: number;
    name: string;
    code: string;
    city_municipality: string;
    address: string;
    province: string;
    postal_codes?: string[] | null;
    coverage_barangays?: string[] | null;
    operating_hours?: string | null;
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
    codRestriction?: { id: number; reference: string; reason_code: string; reason: string; expires_at?: string | null } | null;
    logisticsCompanies?: { id: number; name: string; logo?: string | null; base_shipping_cents: number; included_weight_grams: number; additional_kg_cents: number; shipping_earnings_model?: 'standard' | 'dedicated_fleet' }[];
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
    codRestriction = null,
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
        shipping_region: initialAddress?.region || '',
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
        payment_method: codRestriction ? 'wallet' : 'cod',
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
                shipping_region: '',
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
                    shipping_region: found.region || '',
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

    const updateDeliveryLocationFields = (location: AddressLocation, userChange: boolean) => {
        if (!userChange) {
            if (location.region && data.shipping_region !== location.region) {
                setData(previous => ({ ...previous, shipping_region: location.region }));
            }
            return;
        }
        gps.cancel();
        if (data.saved_address_id !== null) setSelectedAddressId('new');
        setData(previous => ({
            ...changedDeliveryLocation(previous, {
                shipping_region: location.region,
                shipping_province: location.province,
                shipping_city: location.city,
                destination_barangay: location.barangay,
            }, addresses.length < addressBookLimit),
            logistics_company_id: '',
            pickup_hub_id: '',
        }));
    };

    const [showConfirmModal, setShowConfirmModal] = useState(false);
    const [orderDispatchStep, setOrderDispatchStep] = useState<'review' | 'dispatching' | 'confirmed'>('review');
    const [dispatchErrorMessage, setDispatchErrorMessage] = useState<string | null>(null);
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
    const servingCompanies = pricing.companies ?? (pricing.failure ? [] : logisticsCompanies);
    const missingLocation = !data.shipping_province.trim() || !data.shipping_city.trim();
    const missingValidPostal = !/^[0-9]{4}$/.test(data.shipping_postal_code.trim());
    const completeDoorstepDestination = !missingLocation && !missingValidPostal;
    const noServingCompany = completeDoorstepDestination
        && pricing.current && pricing.companies !== undefined && pricing.companies.length === 0;
    const selectedCompany = servingCompanies.find(company => String(company.id) === String(data.logistics_company_id));
    const effectivePickupHubs = pricing.pickupHubs ?? (pricing.failure ? [] : pickupHubs);
    const companyPickupHubs = selectedCompany
        ? matchingPickupHubs(effectivePickupHubs, selectedCompany.id, {
            province: data.shipping_province,
            city: data.shipping_city,
            postal_code: data.shipping_postal_code,
            barangay: data.destination_barangay,
        })
        : [];
    const noSelfPickupHubsForCompany = data.delivery_type === 'hub_self_pickup' && completeDoorstepDestination && Boolean(selectedCompany) && companyPickupHubs.length === 0;
    const logisticsUnavailableReason = pricing.pending ? ''
        : missingLocation ? 'Delivery province and city are required before matching a logistics company.'
            : missingValidPostal ? 'A valid 4-digit postal code is required for your delivery address to match a logistics hub. Add or edit the postal code in Delivery Address.'
                : pricing.failure ? pricing.failure
                    : noServingCompany ? `No available logistics company near ${[data.shipping_city, data.shipping_province].filter(Boolean).join(', ')} (${data.shipping_postal_code})${quote.shops.length > 1 ? ' serves all sellers in your bag' : ' serves this route'}.`
                        : noSelfPickupHubsForCompany ? `No Bayan Hub self-pickup counter is available near ${[data.shipping_city, data.shipping_province].filter(Boolean).join(', ')} (${data.shipping_postal_code}) for ${selectedCompany?.name}. Choose Doorstep Delivery or another address.`
                            : '';
    const hasInvalidDeliveryLocation = Boolean(errors.shipping_latitude || errors.shipping_longitude);
    const visibleCheckoutErrors = checkoutErrorMessages(errors);
    const routeDetailsReady = completeDoorstepDestination
        && (data.delivery_type === 'doorstep' || Boolean(data.pickup_hub_id));
    const canShowQuote = pricing.ready && routeDetailsReady && Boolean(selectedCompany);
    const quoteStatus = pricing.pending ? 'Updating prices, delivery fees and voucher eligibility…'
        : logisticsUnavailableReason ? logisticsUnavailableReason
            : data.delivery_type === 'hub_self_pickup' && !data.pickup_hub_id ? 'Choose a Bayan Hub to check collection coverage.'
                : !selectedCompany ? 'Choose an eligible logistics company to confirm your delivery total.'
                    : 'Current prices and delivery fees checked.';
    useEffect(() => {
        if (pricing.pending) return;
        const defaultCompanyId = defaultLogisticsCompanyId(servingCompanies, data.logistics_company_id);
        if (String(defaultCompanyId) !== String(data.logistics_company_id)) {
            setData(previous => ({ ...previous, logistics_company_id: defaultCompanyId, pickup_hub_id: '' }));
            return;
        }
        if (data.delivery_type !== 'hub_self_pickup') {
            if (data.pickup_hub_id !== '') {
                setData('pickup_hub_id', '');
            }
            return;
        }
        const defaultHubId = defaultPickupHubId(companyPickupHubs, data.pickup_hub_id);
        if (String(defaultHubId) !== String(data.pickup_hub_id)) {
            setData('pickup_hub_id', defaultHubId);
        }
    }, [pricing.pending, servingCompanies, data.logistics_company_id, data.delivery_type, companyPickupHubs, data.pickup_hub_id]);
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
    const selectedPickupHub = companyPickupHubs.find(hub => String(hub.id) === String(data.pickup_hub_id));
    const displayedSelfPickupFee = quote.self_pickup_shipping_fee
        ?? (isHubPickup ? quote.shipping_fee : estimateSelfPickupFeeFromDoorstep(quote.doorstep_shipping_fee, selectedCompany?.shipping_earnings_model));
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

        if (data.delivery_type === 'hub_self_pickup' && (!data.pickup_hub_id || !selectedPickupHub)) {
            setValidationError(logisticsUnavailableReason || 'Please select an available Bayan Station Hub serving this delivery address.');
            return;
        }

        if (noServingCompany || logisticsUnavailableReason || !data.logistics_company_id || !selectedCompany) {
            setValidationError(logisticsUnavailableReason || 'Choose a logistics company that serves this delivery route.');
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
        setOrderDispatchStep('review');
        setDispatchErrorMessage(null);
        setShowConfirmModal(true);
    };

    const submitFinalOrder = async () => {
        if (orderPending.current || processing || orderDispatchStep !== 'review') return;
        if (!canShowQuote || !pricing.ready || bestBusy || !hasConfirmedAgreement || kycStatus !== 'approved') {
            setShowConfirmModal(false);
            return;
        }

        orderPending.current = true;
        setOrderDispatchStep('dispatching');
        setDispatchErrorMessage(null);

        try {
            const response = await axios.post(route('checkout.store'), data, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            setOrderDispatchStep('confirmed');

            // Briefly confirm success before navigating
            setTimeout(() => {
                const redirectUrl = response.data?.redirect || route('buyer.orders.index');
                window.location.href = redirectUrl;
            }, 700);
        } catch (error: any) {
            orderPending.current = false;
            setOrderDispatchStep('review');
            const message = error.response?.data?.message || error.message || 'We could not complete your order. Please check your details.';
            setDispatchErrorMessage(message);
        }
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


    const addressLine = [data.shipping_address, data.destination_barangay, data.shipping_city, data.shipping_province, data.shipping_postal_code].filter(Boolean).join(', ');
    const itemImage = (item: CartItem) => (item.color && item.product?.variants?.colors?.find(c => c.name === item.color)?.image_url) || item.product?.featured_image || '';
    const selfPickupSaving = !pricing.pending && canShowQuote ? quote.doorstep_shipping_fee - displayedSelfPickupFee : 0;
    const submitDisabled = processing || bestBusy || !canShowQuote || !pricing.ready || kycStatus !== 'approved';
    const submitLabel = kycStatus === 'pending_approval' ? 'Waiting for ID approval'
        : kycStatus === 'rejected' ? 'Upload a new ID to continue'
            : kycStatus === 'none' ? 'Verify your ID to continue'
                : 'Review order';
    const paymentLabel = data.payment_method === 'wallet' ? 'Bagoo Wallet' : 'Cash on delivery';
    const platformVoucher = appliedVouchers.find(offer => offer.issuer === 'platform');
    const radioCard = (selected: boolean) => `relative flex cursor-pointer items-start gap-3 rounded-control border p-4 transition-colors focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-500 ${selected ? 'border-brand-500 bg-brand-50/60' : 'border-line-300 bg-white hover:border-ink-400'}`;
    const radioDot = (selected: boolean) => <span aria-hidden="true" className={`mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${selected ? 'border-brand-500' : 'border-line-300'}`}>{selected && <span className="h-2 w-2 rounded-full bg-brand-500" />}</span>;
    const linkClasses = 'text-sm font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900';

    return (
        <BuyerLayout>
            <Head title="Checkout — BagooPH" />

            <div className="mx-auto w-full max-w-[1200px] pb-28 pt-2 text-ink-900 lg:pb-12">

                <header className="space-y-2">
                    <Link href={route('buyer.cart')} className="inline-flex items-center gap-1.5 text-sm text-ink-600 hover:text-ink-900">
                        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
                        <span>Back to Bag</span>
                    </Link>
                    <h1 className="text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">Checkout</h1>
                </header>

                <div className="mt-6 space-y-3 empty:hidden">
                    {flash?.success && <Notice tone="success" role="status">{flash.success}</Notice>}
                    {flash?.error && <Notice tone="danger" role="alert">{flash.error}</Notice>}
                    {Object.keys(errors).length > 0 && (
                        <div ref={errorSummaryRef} role="alert" tabIndex={-1}>
                            <Notice tone="danger" title="Please check your checkout details"
                                action={errors.checkout_token && <Link href={route('checkout.index', { items: data.item_ids.join(','), ...(Object.keys(data.item_quantities).length === 1 ? { quantity: Object.values(data.item_quantities)[0] } : {}) })} className={linkClasses}>Reload checkout</Link>}>
                                <ul className="list-disc space-y-1 pl-4">
                                    {visibleCheckoutErrors.map(error => <li key={error}>{error}</li>)}
                                </ul>
                            </Notice>
                        </div>
                    )}
                    {kycStatus !== 'approved' && (
                        <Notice
                            tone={kycStatus === 'rejected' ? 'danger' : kycStatus === 'pending_approval' ? 'info' : 'warning'}
                            title={kycStatus === 'pending_approval' ? 'Your ID is being reviewed' : kycStatus === 'rejected' ? 'Your ID could not be verified' : 'Verify your ID to place orders'}
                            action={(kycStatus === 'none' || kycStatus === 'rejected') && (
                                <div className="space-y-2">
                                    <input ref={kycFileInputRef} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" onChange={handleKycFileSelect} className="hidden" />
                                    <UploadHint />
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button size="sm" onClick={() => kycFileInputRef.current?.click()}>
                                            <Upload className="h-4 w-4" aria-hidden="true" />
                                            <span className="max-w-[220px] truncate">{kycFileName ?? 'Choose ID file'}</span>
                                        </Button>
                                        {kycFileName && (
                                            <Button size="sm" variant="primary" onClick={() => handleKycSubmit()} disabled={kycForm.processing}>
                                                {kycForm.processing ? 'Submitting…' : 'Submit for review'}
                                            </Button>
                                        )}
                                    </div>
                                    {kycForm.errors.id_document && <p className="text-[13px] text-brand-700">{kycForm.errors.id_document}</p>}
                                </div>
                            )}>
                            {kycStatus === 'pending_approval' && 'You can keep shopping. Placing orders unlocks once your ID is approved.'}
                            {kycStatus === 'rejected' && (kycFeedback || 'Upload a clear photo or scan of a valid government ID.')}
                            {kycStatus === 'none' && 'Upload a valid government ID (PNG, JPG or PDF). Your Bag and checkout details are kept.'}
                        </Notice>
                    )}
                </div>

                <form id="checkout-form" onSubmit={handlePromptConfirmation} className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start">

                    <div className="space-y-6 lg:col-span-7">

                        {/* 1. Delivery address */}
                        <Panel step={1} title="Delivery address" aria-label="Delivery address"
                            action={<Link href={route('buyer.profile', { tab: 'addresses' })} className={linkClasses}>Manage addresses</Link>}>
                            <div className="space-y-4">
                                {addresses.length > 0 && (
                                    <div>
                                        <label htmlFor="checkout-saved-address" className={labelClasses}>Saved addresses</label>
                                        <select
                                            id="checkout-saved-address"
                                            value={selectedAddressId}
                                            onChange={(e) => {
                                                handleAddressChange(e.target.value);
                                                setIsEditingAddress(e.target.value === 'new');
                                            }}
                                            className={inputClasses}
                                        >
                                            {addresses.map((addr) => (
                                                <option key={addr.id} value={String(addr.id)}>
                                                    {addr.recipient_name} · {addr.street}, {addr.city}{addr.is_default ? ' (default)' : ''}
                                                </option>
                                            ))}
                                            <option value="new">Use a new address</option>
                                        </select>
                                    </div>
                                )}

                                {!isEditingAddress && (
                                    <div className="flex items-start justify-between gap-4 rounded-control bg-surface-100 p-4">
                                        <div className="min-w-0 space-y-1">
                                            <p className="text-sm font-semibold text-ink-900">
                                                {data.recipient_name || 'Add a recipient'}
                                                {data.recipient_phone && <span className="font-normal text-ink-600"> · {data.recipient_phone}</span>}
                                            </p>
                                            <p className="text-sm text-ink-700">{addressLine || 'No address yet'}</p>
                                            {data.landmark && <p className="text-[13px] text-ink-600">Landmark: {data.landmark}</p>}
                                        </div>
                                        <button type="button" onClick={() => setIsEditingAddress(true)} aria-expanded={false} aria-controls="checkout-address-editor" className={buttonClasses('secondary', 'sm', 'shrink-0')}>
                                            <span>Edit</span>
                                            <ChevronDown className="h-4 w-4" aria-hidden="true" />
                                        </button>
                                    </div>
                                )}

                                {isEditingAddress && (
                                    <div id="checkout-address-editor" className="space-y-4">
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <div>
                                                <label htmlFor="checkout-recipient-name" className={labelClasses}>Recipient name</label>
                                                <input id="checkout-recipient-name" name="recipient_name" type="text" required minLength={2} maxLength={100}
                                                    value={data.recipient_name} onChange={event => setData('recipient_name', event.target.value)}
                                                    aria-invalid={Boolean(errors.recipient_name)} autoComplete="name"
                                                    className={inputClasses} />
                                                {fieldMessage({ error: errors.recipient_name })}
                                            </div>

                                            <div>
                                                <label htmlFor="checkout-phone" className={labelClasses}>Mobile number</label>
                                                <PhoneInput
                                                    id="checkout-phone"
                                                    error={errors.recipient_phone}
                                                    value={data.recipient_phone}
                                                    onChange={(val) => setData('recipient_phone', val)}
                                                    placeholder="917 123 4567"
                                                    accentColor="primary"
                                                    helperText="10-digit mobile number"
                                                    required
                                                />
                                            </div>

                                            <div className="sm:col-span-2">
                                                <label htmlFor="checkout-shipping_address" className={labelClasses}>House number, street and subdivision</label>
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
                                                    autoComplete="street-address"
                                                    className={inputClasses}
                                                />
                                                {fieldMessage({ id: "checkout-shipping_address-error", error: errors.shipping_address })}
                                            </div>

                                            <div className="sm:col-span-2">
                                                <AddressLocationFields
                                                    values={{
                                                        region: data.shipping_region,
                                                        province: data.shipping_province,
                                                        city: data.shipping_city,
                                                        barangay: data.destination_barangay,
                                                    }}
                                                    onChange={updateDeliveryLocationFields}
                                                    errors={{
                                                        province: errors.shipping_province,
                                                        city: errors.shipping_city,
                                                        barangay: errors.destination_barangay,
                                                    }}
                                                    fieldIds={{
                                                        region: 'checkout-shipping_region',
                                                        province: 'checkout-shipping_province',
                                                        city: 'checkout-shipping_city',
                                                        barangay: 'checkout-destination_barangay',
                                                    }}
                                                    errorIds={{
                                                        province: 'checkout-shipping_province-error',
                                                        city: 'checkout-shipping_city-error',
                                                        barangay: 'checkout-destination_barangay-error',
                                                    }}
                                                    appearance="buyer"
                                                    accent="primary"
                                                />
                                            </div>

                                            <div>
                                                <label htmlFor="checkout-shipping_postal_code" className={labelClasses}>Postal code</label>
                                                <input
                                                    id="checkout-shipping_postal_code"
                                                    aria-invalid={Boolean(errors.shipping_postal_code)}
                                                    aria-describedby={errors.shipping_postal_code ? 'checkout-shipping_postal_code-error' : undefined}
                                                    type="text"
                                                    inputMode="numeric"
                                                    maxLength={4}
                                                    required
                                                    pattern="[0-9]{4}"
                                                    placeholder="4009"
                                                    minLength={4}
                                                    autoComplete="postal-code"
                                                    value={data.shipping_postal_code}
                                                    onChange={(e) => updateDeliveryAddress('shipping_postal_code', e.target.value)}
                                                    className={inputClasses}
                                                />
                                                {fieldMessage({ id: "checkout-shipping_postal_code-error", error: errors.shipping_postal_code, hint: "4 digits, used to match your nearest hub" })}
                                            </div>

                                            <div className="space-y-3 sm:col-span-2">
                                                <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                                                    <div>
                                                        <p className="text-[13px] font-medium text-ink-700">Pin your location</p>
                                                        <p className="text-xs text-ink-600">Needed for doorstep delivery so the rider finds you.</p>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={gps.capture}
                                                        disabled={gps.locating}
                                                        className={buttonClasses('secondary', 'sm', 'min-h-[44px] w-fit sm:min-h-0')}
                                                    >
                                                        <Crosshair className="h-4 w-4" aria-hidden="true" />
                                                        <span>{gps.locating ? 'Finding your location…' : 'Use my current location'}</span>
                                                    </button>
                                                </div>

                                                {gps.status && (
                                                    <p role="status" className="rounded-control bg-surface-100 px-3 py-2 text-[13px] text-ink-700">
                                                        {gps.status}
                                                    </p>
                                                )}

                                                <LocationPinPicker
                                                    value={data.shipping_latitude !== '' && data.shipping_longitude !== '' && Number.isFinite(Number(data.shipping_latitude)) && Number.isFinite(Number(data.shipping_longitude))
                                                        ? { latitude: Number(data.shipping_latitude), longitude: Number(data.shipping_longitude) } : null}
                                                    onChange={point => { gps.cancel(); setData(previous => ({ ...previous, shipping_latitude: point.latitude, shipping_longitude: point.longitude })); }}
                                                />
                                                {hasInvalidDeliveryLocation && <p role="alert" className="text-[13px] text-brand-700">{deliveryLocationErrorMessage}</p>}

                                                <div>
                                                    <label htmlFor="checkout-landmark" className={labelClasses}>
                                                        Landmark <span className="font-normal text-ink-500">(optional)</span>
                                                    </label>
                                                    <input
                                                        id="checkout-landmark"
                                                        aria-invalid={Boolean(errors.landmark)}
                                                        aria-describedby={errors.landmark ? 'checkout-landmark-error' : undefined}
                                                        type="text"
                                                        list="checkout-landmark-suggestions"
                                                        placeholder="e.g. Across the barangay hall, yellow gate"
                                                        maxLength={255}
                                                        minLength={5}
                                                        value={data.landmark}
                                                        onChange={(e) => setData('landmark', e.target.value)}
                                                        className={inputClasses}
                                                    />
                                                    <datalist id="checkout-landmark-suggestions">
                                                        <option value="Near the barangay hall" />
                                                        <option value="Near the public market" />
                                                        <option value="Near the school" />
                                                        <option value="Near the church" />
                                                        <option value="Near the health center" />
                                                        <option value="At the building gate" />
                                                    </datalist>
                                                    {fieldMessage({ id: "checkout-landmark-error", error: errors.landmark })}
                                                </div>
                                            </div>

                                            {selectedAddressId === 'new' && (
                                                <label className="flex cursor-pointer select-none items-start gap-2.5 text-sm text-ink-700 sm:col-span-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={data.save_address}
                                                        disabled={addresses.length >= addressBookLimit || !isHubPickup}
                                                        onChange={(e) => setData('save_address', e.target.checked)}
                                                        className="mt-0.5 rounded-chip border-line-300 text-brand-500 focus:ring-brand-500"
                                                    />
                                                    <span>{addresses.length >= addressBookLimit ? (!isHubPickup ? `Your address book is full (${addressBookLimit}). Choose a saved address or remove one first.` : `Your address book is full (${addressBookLimit}). This address will be used for this order only.`) : !isHubPickup ? 'This delivery address and pin will be saved to your address book.' : 'Save this address to my address book'}</span>
                                                </label>
                                            )}
                                        </div>

                                        <div className="flex justify-end">
                                            <button type="button" onClick={() => setIsEditingAddress(false)} aria-expanded={true} aria-controls="checkout-address-editor" className={buttonClasses('secondary', 'md')}>
                                                <span>Done</span>
                                                <ChevronUp className="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </Panel>

                        {/* 2. Delivery method */}
                        <Panel step={2} title="Delivery method">
                            <div className="space-y-5">
                                <fieldset className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
                                    <legend className="sr-only">Delivery method</legend>
                                    <label className={radioCard(data.delivery_type === 'doorstep')}>
                                        <input type="radio" name="delivery_type" value="doorstep" checked={data.delivery_type === 'doorstep'} onChange={() => setData(previous => withDoorstepDelivery(previous, addresses.length < addressBookLimit))} className="sr-only" />
                                        {radioDot(data.delivery_type === 'doorstep')}
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline justify-between gap-3">
                                                <span className="flex items-center gap-2 text-sm font-semibold text-ink-900"><Truck className="h-4 w-4 text-ink-600" aria-hidden="true" />Doorstep delivery</span>
                                                <span className="text-sm font-semibold tabular-nums text-ink-900">{quotePrice(quote.doorstep_shipping_fee)}</span>
                                            </span>
                                            <span className="mt-1 block text-[13px] text-ink-600">A rider brings it to your address.</span>
                                        </span>
                                    </label>

                                    <label className={radioCard(data.delivery_type === 'hub_self_pickup')}>
                                        <input type="radio" name="delivery_type" value="hub_self_pickup" checked={data.delivery_type === 'hub_self_pickup'} onChange={() => setData('delivery_type', 'hub_self_pickup')} className="sr-only" />
                                        {radioDot(data.delivery_type === 'hub_self_pickup')}
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline justify-between gap-3">
                                                <span className="flex items-center gap-2 text-sm font-semibold text-ink-900"><Store className="h-4 w-4 text-ink-600" aria-hidden="true" />Pick up at a Bayan Hub</span>
                                                <span className="text-sm font-semibold tabular-nums text-ink-900">{quotePrice(displayedSelfPickupFee)}</span>
                                            </span>
                                            <span className="mt-1 block text-[13px] text-ink-600">Collect it at a nearby hub counter.</span>
                                            {selfPickupSaving > 0 && <span className="mt-1.5 block text-[13px] font-medium text-success">Save {formatPrice(selfPickupSaving)} on shipping</span>}
                                        </span>
                                    </label>
                                </fieldset>

                                {isHubPickup && (
                                    <div className="space-y-3">
                                        <div>
                                            <label htmlFor="checkout-pickup-hub" className={labelClasses}>Pickup hub</label>
                                            <select
                                                id="checkout-pickup-hub"
                                                aria-invalid={Boolean(errors.pickup_hub_id)}
                                                aria-describedby={errors.pickup_hub_id ? 'checkout-pickup-hub-error' : undefined}
                                                required
                                                disabled={companyPickupHubs.length === 0}
                                                value={data.pickup_hub_id}
                                                onChange={(e) => setData('pickup_hub_id', e.target.value)}
                                                className={inputClasses}
                                            >
                                                <option value="">
                                                    {missingLocation || missingValidPostal
                                                        ? 'Complete your address first'
                                                        : companyPickupHubs.length === 0
                                                            ? 'No pickup hub covers this area'
                                                            : 'Choose a hub'}
                                                </option>
                                                {companyPickupHubs.map((hub) => (
                                                    <option key={hub.id} value={hub.id}>
                                                        {hub.name} · {hub.city_municipality}
                                                    </option>
                                                ))}
                                            </select>
                                            {fieldMessage({ id: "checkout-pickup-hub-error", error: errors.pickup_hub_id, hint: selectedPickupHub && !pricing.pending ? (companyPickupHubs.length === 1 ? 'Auto-matched for your area' : 'Auto-selected, nearest to your address') : undefined })}
                                        </div>
                                        {(missingLocation || missingValidPostal || noSelfPickupHubsForCompany) && (
                                            <Notice tone="warning" role="alert"
                                                action={(missingLocation || missingValidPostal) && !isEditingAddress && <Button size="sm" onClick={() => setIsEditingAddress(true)}>Edit address</Button>}>
                                                {missingLocation
                                                    ? 'Add your province and city to see pickup hubs near you.'
                                                    : missingValidPostal
                                                        ? 'Add your 4-digit postal code to see pickup hubs near you.'
                                                        : `No pickup hub covers ${[data.shipping_city, data.shipping_province].filter(Boolean).join(', ')} (${data.shipping_postal_code}) for ${selectedCompany?.name}.`}
                                            </Notice>
                                        )}
                                        {selectedPickupHub && (
                                            <div className="rounded-control bg-surface-100 p-4 text-sm">
                                                <p className="font-semibold text-ink-900">{selectedPickupHub.name} <span className="font-normal text-ink-500">· {selectedPickupHub.code}</span></p>
                                                <p className="mt-0.5 text-ink-700">{selectedPickupHub.address}, {selectedPickupHub.city_municipality}, {selectedPickupHub.province}</p>
                                                {selectedPickupHub.operating_hours && <p className="mt-1 flex items-center gap-1.5 text-[13px] text-ink-600"><Clock className="h-3.5 w-3.5" aria-hidden="true" />{selectedPickupHub.operating_hours}</p>}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div>
                                    <label htmlFor="checkout-carrier-select" className={labelClasses}>Delivery partner</label>
                                    <div className="flex items-center gap-3">
                                        {selectedCompany?.logo && <img src={selectedCompany.logo} alt="" className="h-10 w-10 shrink-0 rounded-control border border-line-200 bg-white object-contain p-1" />}
                                        <select
                                            id="checkout-carrier-select"
                                            required
                                            disabled={servingCompanies.length === 0}
                                            value={data.logistics_company_id}
                                            onChange={event => setData({ ...data, logistics_company_id: event.target.value, pickup_hub_id: '' })}
                                            className={inputClasses}
                                        >
                                            <option value="">{servingCompanies.length === 0 ? 'No delivery partner available' : 'Choose a delivery partner'}</option>
                                            {servingCompanies.map(company => (
                                                <option key={company.id} value={company.id}>{company.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    {selectedCompany && <span className="sr-only">Selected carrier: {selectedCompany.name}</span>}
                                    {fieldMessage({ error: errors.logistics_company_id, hint: selectedCompany && !pricing.pending ? (servingCompanies.length === 1 ? 'Auto-matched for your route' : 'Auto-selected for your route. You can change it.') : undefined })}
                                    {logisticsUnavailableReason && (
                                        <Notice tone="warning" role="alert" className="mt-3"
                                            action={(missingLocation || missingValidPostal) && !isEditingAddress && <Button size="sm" onClick={() => setIsEditingAddress(true)}>Edit address</Button>}>
                                            {logisticsUnavailableReason}
                                        </Notice>
                                    )}
                                </div>

                                {selectedCompany && canShowQuote && !pricing.pending && quote.shops.some(shop => shop.shipping_quote?.eta?.label) && (
                                    <dl className="space-y-1.5 border-t border-line-200 pt-4 text-sm">
                                        {quote.shops.map(shop => shop.shipping_quote?.eta ? (
                                            <div key={`eta-${shop.shop_id}`} className="flex flex-wrap items-baseline justify-between gap-2">
                                                <dt className="text-ink-600">{quote.shops.length > 1 ? `Arrives from ${shop.shop_name}` : 'Estimated arrival'}</dt>
                                                <dd className="font-semibold text-ink-900">{shop.shipping_quote.eta.label}</dd>
                                            </div>
                                        ) : null)}
                                    </dl>
                                )}
                                {selectedCompany && <CheckoutRoutePreview shops={quote.shops} ready={canShowQuote}
                                    pending={pricing.pending} selfPickup={isHubPickup} />}
                            </div>
                        </Panel>

                        {/* 3. Payment */}
                        <Panel step={3} title="Payment">
                            <div className="space-y-4">
                                {codRestriction && (
                                    <Notice tone="warning" role="alert" title={`Cash on delivery is restricted (${codRestriction.reference})`}
                                        action={<a href="/account/status" className={linkClasses}>View details or appeal</a>}>
                                        {codRestriction.reason} You can still pay with Bagoo Wallet.
                                    </Notice>
                                )}
                                <BuyerPaymentChoice value={data.payment_method} onChange={method => setData('payment_method', method)} balanceCents={walletBalanceCents} disabled={processing} codDisabled={Boolean(codRestriction)} codDisabledReason={codRestriction?.reason ?? null} />
                                {errors.payment_method && <p role="alert" className="text-[13px] text-brand-700">{errors.payment_method}</p>}
                            </div>
                        </Panel>
                    </div>

                    {/* Order summary */}
                    <aside className="lg:col-span-5 lg:sticky lg:top-24" aria-label="Order summary">
                        <div className="rounded-card border border-line-200 bg-white">
                            <div className="flex items-baseline justify-between gap-3 px-5 pt-5 sm:px-6">
                                <h2 className="text-lg font-semibold text-ink-900">Your order <span className="text-sm font-normal text-ink-500">· {items.length} {items.length === 1 ? 'item' : 'items'}</span></h2>
                                <Link href={route('buyer.cart')} className={linkClasses}>Edit Bag</Link>
                            </div>

                            <div className="divide-y divide-line-200 px-5 sm:px-6">
                                {quote.shops.map(shop => {
                                    const shopVoucher = appliedVouchers.find(offer => offer.shop_id === shop.shop_id);
                                    return (
                                        <div key={shop.shop_id} aria-label={shop.shop_name} className="space-y-3 py-4">
                                            <p className="flex items-center gap-1.5 text-[13px] font-medium text-ink-600">
                                                <Store className="h-3.5 w-3.5" aria-hidden="true" />{shop.shop_name}
                                            </p>
                                            <ul className="space-y-3">
                                                {items.filter(item => item.product?.shop?.id === shop.shop_id).map(item => {
                                                    const quoted = quotedItems.get(item.id);
                                                    const image = itemImage(item);
                                                    return (
                                                        <li key={item.id} className="flex gap-3">
                                                            <span className="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-control bg-surface-100 text-ink-400">
                                                                <Package className="h-5 w-5" aria-hidden="true" />
                                                                {image && <img src={image} alt="" loading="lazy" onError={event => event.currentTarget.remove()} className="absolute inset-0 h-full w-full object-cover" />}
                                                            </span>
                                                            <div className="min-w-0 flex-1">
                                                                <p className="line-clamp-2 text-sm font-medium leading-5 text-ink-900">{item.product?.name}</p>
                                                                {(item.color || item.size) && <p className="text-xs text-ink-600">{[item.color, item.size].filter(Boolean).join(' / ')}</p>}
                                                                <p className="mt-0.5 text-xs tabular-nums text-ink-600">{quoted?.quantity ?? item.quantity} × {formatPrice(quoted?.unit_price ?? item.product?.price)}</p>
                                                            </div>
                                                            <p className="shrink-0 text-sm font-semibold tabular-nums text-ink-900">
                                                                {quotePrice(quoted?.subtotal ?? Number(item.product?.price) * item.quantity)}
                                                            </p>
                                                        </li>
                                                    );
                                                })}
                                            </ul>
                                            <div className="flex items-center justify-between gap-3 text-[13px]">
                                                <span className="flex min-w-0 items-center gap-1.5 text-ink-600">
                                                    <Tag className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    <span className="truncate">Shop voucher{shopVoucher ? <>: <span className="font-medium text-ink-900">{shopVoucher.code}</span></> : ''}</span>
                                                </span>
                                                <button type="button" disabled={pricing.pending || bestBusy} onClick={() => setPickerSlot(shop.shop_id)} aria-label={`Choose voucher for ${shop.shop_name}`}
                                                    className="shrink-0 font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900 disabled:text-ink-400">
                                                    {shopVoucher ? 'Change' : 'Add'}
                                                </button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="space-y-2 border-t border-line-200 px-5 py-4 sm:px-6">
                                <div className="flex items-center justify-between gap-3 text-[13px]">
                                    <span className="flex min-w-0 items-center gap-1.5 text-ink-600">
                                        <Gift className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        <span className="truncate">Bagoo voucher{platformVoucher ? <>: <span className="font-medium text-ink-900">{platformVoucher.code}</span></> : ''}</span>
                                    </span>
                                    <button type="button" disabled={pricing.pending || bestBusy} onClick={() => setPickerSlot(null)} aria-label="Choose voucher from Bagoo"
                                        className="shrink-0 font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900 disabled:text-ink-400">
                                        {platformVoucher ? 'Change' : 'Add'}
                                    </button>
                                </div>
                                <Button size="sm" variant="ghost" className="-ml-3" onClick={useBestVouchers} disabled={!pricing.ready || bestBusy}>
                                    {bestBusy ? 'Comparing vouchers…' : 'Use best vouchers'}
                                </Button>
                                {voucherError && <p role="alert" className="text-[13px] text-brand-700">{voucherError}</p>}
                            </div>

                            <div className="space-y-4 border-t border-line-200 px-5 py-5 sm:px-6">
                                <h3 className="sr-only">Order total</h3>
                                <CheckoutVoucherBreakdown quote={quote} price={quotePrice} />
                                {quote.shops.length > 1 && (
                                    <dl className="space-y-1.5 border-t border-line-200 pt-3 text-[13px] text-ink-600">
                                        {quote.shops.map(shop => <div key={shop.shop_id} className="flex justify-between gap-3">
                                            <dt>{shop.shop_name}</dt><dd className="tabular-nums">{quotePrice(shop.total)}</dd>
                                        </div>)}
                                    </dl>
                                )}
                                <div className="flex items-baseline justify-between gap-3 border-t border-line-200 pt-4">
                                    <span className="text-base font-semibold text-ink-900">Total</span>
                                    <span className="text-[28px] font-bold leading-none tabular-nums text-ink-900">{quotePrice(grandTotal)}</span>
                                </div>
                                <p className="-mt-2 text-right text-[13px] text-ink-600">Paying with {paymentLabel}</p>
                                <p role="status" aria-live="polite" className={`text-[13px] ${pricing.pending || logisticsUnavailableReason ? 'text-ink-700' : 'text-ink-500'}`}>{quoteStatus}</p>

                                {validationError && <Notice tone="danger" role="alert">{validationError}</Notice>}

                                <Button type="submit" variant="primary" size="lg" className="w-full" disabled={submitDisabled}>
                                    {processing ? 'Placing order…' : submitLabel}
                                </Button>

                                <div className="space-y-2 text-xs text-ink-600">
                                    {quote.shops.some(shop => shop.shipping_quote?.cancellation_policy_version === 2) && (
                                        <p>You can cancel until the seller accepts. After that, you can request cancellation before the order is ready for pickup, and the seller has 24 hours to respond.</p>
                                    )}
                                    <p className="flex items-center gap-1.5"><ShieldCheck className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />Covered by BagooPH Buyer Protection</p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </form>

                {/* Mobile: total and action stay reachable */}
                <div className="fixed inset-x-0 bottom-0 z-20 border-t border-line-200 bg-white px-4 py-3 shadow-float lg:hidden">
                    <div className="mx-auto flex max-w-[1200px] items-center justify-between gap-4">
                        <div className="min-w-0">
                            <p className="text-xs text-ink-600">Total</p>
                            <p className="text-lg font-bold tabular-nums text-ink-900">{quotePrice(grandTotal)}</p>
                        </div>
                        <Button type="submit" form="checkout-form" variant="primary" size="lg" disabled={submitDisabled} className="shrink-0">
                            {processing ? 'Placing order…' : kycStatus === 'approved' ? 'Review order' : 'Verify ID first'}
                        </Button>
                    </div>
                </div>

                {/* Order review dialog */}
                <Dialog open={showConfirmModal} onClose={() => { if (orderDispatchStep === 'review') setShowConfirmModal(false); }} className="relative z-50">
                    <div className="fixed inset-0 z-50 flex items-end justify-center overflow-y-auto bg-ink-900/50 sm:items-center sm:p-4 animate-fade-in">
                        <DialogPanel className="my-auto max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-card bg-white shadow-float sm:rounded-card">

                            {orderDispatchStep !== 'review' && (
                                <div className="flex flex-col items-center gap-4 px-6 py-12 text-center">
                                    {orderDispatchStep === 'dispatching'
                                        ? <span className="h-10 w-10 animate-spin rounded-full border-2 border-line-200 border-t-brand-500 motion-reduce:animate-none" aria-hidden="true" />
                                        : <span className="flex h-12 w-12 items-center justify-center rounded-full bg-success-soft text-success"><Check className="h-6 w-6" aria-hidden="true" /></span>}
                                    <div className="space-y-1" role="status">
                                        <DialogTitle as="h3" className="text-lg font-semibold text-ink-900">
                                            {orderDispatchStep === 'dispatching' ? 'Placing your order…' : 'Order placed'}
                                        </DialogTitle>
                                        <p className="text-sm text-ink-600">
                                            {orderDispatchStep === 'dispatching' ? 'Please keep this page open.' : 'Taking you to your purchases.'}
                                        </p>
                                    </div>
                                </div>
                            )}

                            {orderDispatchStep === 'review' && (
                                <div>
                                    <div className="flex items-center justify-between gap-4 border-b border-line-200 px-6 py-4">
                                        <DialogTitle as="h3" className="text-lg font-semibold text-ink-900">Review your order</DialogTitle>
                                        <button type="button" onClick={() => setShowConfirmModal(false)} aria-label="Close order review"
                                            className="rounded-control p-1.5 text-ink-500 hover:bg-surface-100 hover:text-ink-900">
                                            <X className="h-5 w-5" aria-hidden="true" />
                                        </button>
                                    </div>

                                    <div className="space-y-5 px-6 py-5">
                                        {dispatchErrorMessage && <Notice tone="danger" role="alert">{dispatchErrorMessage}</Notice>}

                                        <dl className="divide-y divide-line-200 text-sm">
                                            <div className="flex gap-4 pb-3">
                                                <dt className="w-24 shrink-0 text-ink-600">{isHubPickup ? 'Collect at' : 'Deliver to'}</dt>
                                                <dd className="min-w-0 text-ink-900">
                                                    {isHubPickup && selectedPickupHub ? <>
                                                        <p className="font-medium">{selectedPickupHub.name}</p>
                                                        <p className="break-words text-ink-600">{[selectedPickupHub.address, selectedPickupHub.city_municipality, selectedPickupHub.province].filter(Boolean).join(', ')}</p>
                                                        <p className="mt-1 text-ink-600">Recipient: {data.recipient_name} · {data.recipient_phone}</p>
                                                    </> : <>
                                                        <p className="font-medium">{data.recipient_name} · {data.recipient_phone}</p>
                                                        <p className="break-words text-ink-600">{addressLine}</p>
                                                    </>}
                                                </dd>
                                            </div>
                                            <div className="flex gap-4 py-3">
                                                <dt className="w-24 shrink-0 text-ink-600">Payment</dt>
                                                <dd className="text-ink-900">{paymentLabel}</dd>
                                            </div>
                                            {appliedVouchers.length > 0 && (
                                                <div className="flex gap-4 py-3">
                                                    <dt className="w-24 shrink-0 text-ink-600">Vouchers</dt>
                                                    <dd className="text-ink-900">{appliedVouchers.map(offer => offer.code).join(', ')}</dd>
                                                </div>
                                            )}
                                            <div className="flex gap-4 pt-3">
                                                <dt className="w-24 shrink-0 text-ink-600">Items</dt>
                                                <dd className="min-w-0 flex-1">
                                                    <ul className="max-h-40 space-y-2 overflow-y-auto">
                                                        {items.map(item => (
                                                            <li key={item.id} className="flex justify-between gap-3">
                                                                <span className="min-w-0">
                                                                    <span className="block truncate text-ink-900">{item.product?.name}</span>
                                                                    <span className="text-xs text-ink-600"><PriceSegments segments={quotedItems.get(item.id)?.price_segments} quantity={quotedItems.get(item.id)?.quantity ?? item.quantity} unitPrice={quotedItems.get(item.id)?.unit_price ?? Number(item.unit_price)} /></span>
                                                                </span>
                                                                <span className="shrink-0 tabular-nums text-ink-900">{quotePrice(quotedItems.get(item.id)?.subtotal ?? Number(item.product?.price) * item.quantity)}</span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </dd>
                                            </div>
                                        </dl>

                                        <div className="flex items-baseline justify-between rounded-control bg-surface-100 px-4 py-3">
                                            <span className="font-semibold text-ink-900">Total to pay</span>
                                            <span className="text-xl font-bold tabular-nums text-ink-900">{quotePrice(grandTotal)}</span>
                                        </div>

                                        <label className="flex cursor-pointer select-none items-start gap-2.5 text-sm text-ink-700">
                                            <input
                                                type="checkbox"
                                                checked={hasConfirmedAgreement}
                                                onChange={(e) => setHasConfirmedAgreement(e.target.checked)}
                                                className="mt-0.5 rounded-chip border-line-300 text-brand-500 focus:ring-brand-500"
                                            />
                                            <span>I have checked my delivery details and total.</span>
                                        </label>
                                    </div>

                                    <div className="flex flex-col-reverse gap-3 border-t border-line-200 px-6 py-4 sm:flex-row sm:justify-end">
                                        <Button onClick={() => setShowConfirmModal(false)}>Edit details</Button>
                                        <Button variant="primary"
                                            disabled={!hasConfirmedAgreement || orderPending.current || bestBusy || !canShowQuote || !pricing.ready}
                                            onClick={submitFinalOrder}>
                                            Place order
                                        </Button>
                                    </div>
                                </div>
                            )}
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
