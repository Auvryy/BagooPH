import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import PhoneInput from '@/Components/PhoneInput';
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

interface VoucherItem {
    id: number;
    code: string;
    name: string;
    description: string;
    discount_type: 'fixed' | 'percent' | 'free_shipping';
    discount_value: number;
    min_spend: number;
    max_discount?: number;
}

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
    availableVouchers?: VoucherItem[];
    kycStatus?: 'none' | 'pending_approval' | 'approved' | 'rejected';
    kycFeedback?: string | null;
    addresses?: Address[];
    defaultAddressId?: number | null;
    pickupHubs?: PickupHubItem[];
}

export default function CheckoutIndex({ 
    cart, 
    items, 
    subtotal, 
    shippingFee, 
    user, 
    availableVouchers = [],
    kycStatus = 'none',
    kycFeedback = null,
    addresses = [],
    defaultAddressId = null,
    pickupHubs = [],
}: Props) {
    const { flash } = usePage<PageProps>().props;

    const [voucherCodeInput, setVoucherCodeInput] = useState('');
    const [appliedVoucher, setAppliedVoucher] = useState<VoucherItem | null>(null);
    const [voucherDiscount, setVoucherDiscount] = useState<number>(0);
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

    // Format address string helper
    const formatAddressString = (addr: Address) => {
        const parts = [addr.street, addr.barangay, addr.province].filter(Boolean);
        return parts.length > 0 ? parts.join(', ') : addr.street;
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
        item_ids: items.map(i => i.id),
        recipient_name: user.name || initialAddress?.recipient_name || '',
        recipient_phone: initialAddress?.phone || user.phone || '',
        shipping_address: initialAddress ? formatAddressString(initialAddress) : (user.address || ''),
        shipping_city: initialAddress?.city || user.city || '',
        shipping_province: initialAddress?.province || 'Metro Manila',
        shipping_postal_code: initialAddress?.postal_code || user.postal_code || '',
        destination_barangay: initialAddress?.barangay || '',
        shipping_latitude: (initialAddress as any)?.latitude || '',
        shipping_longitude: (initialAddress as any)?.longitude || '',
        landmark: (initialAddress as any)?.landmark || '',
        delivery_type: 'doorstep' as 'doorstep' | 'hub_self_pickup',
        pickup_hub_id: '' as string | number,
        payment_method: 'cod',
        notes: '',
        voucher_code: '',
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
                shipping_province: 'Metro Manila',
                shipping_postal_code: '',
                destination_barangay: '',
                shipping_latitude: '',
                shipping_longitude: '',
                landmark: '',
                save_address: true,
            }));
        } else {
            const found = addresses.find(a => String(a.id) === addressId);
            if (found) {
                setData(prev => ({
                    ...prev,
                    recipient_name: user.name || found.recipient_name || '',
                    recipient_phone: found.phone,
                    shipping_address: formatAddressString(found),
                    shipping_city: found.city,
                    shipping_province: found.province || 'Metro Manila',
                    shipping_postal_code: found.postal_code || '',
                    destination_barangay: found.barangay || '',
                    shipping_latitude: (found as any).latitude || '',
                    shipping_longitude: (found as any).longitude || '',
                    landmark: (found as any).landmark || '',
                    save_address: false,
                }));
            }
        }
    };

    const [showConfirmModal, setShowConfirmModal] = useState(false);
    const [hasConfirmedAgreement, setHasConfirmedAgreement] = useState(false);
    const [validationError, setValidationError] = useState('');

    useEffect(() => {
        if (showConfirmModal) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = 'unset';
        }
        return () => {
            document.body.style.overflow = 'unset';
        };
    }, [showConfirmModal]);

    const isHubPickup = data.delivery_type === 'hub_self_pickup';
    const baseShippingFee = isHubPickup ? 0 : (subtotal > 1500 ? 0 : 50);
    
    // Calculate final shipping fee accounting for free shipping vouchers or hub pickup
    const finalShippingFee = (appliedVoucher?.discount_type === 'free_shipping' || isHubPickup) ? 0 : baseShippingFee;
    const finalDiscount = appliedVoucher?.discount_type === 'free_shipping' ? baseShippingFee : voucherDiscount;
    const grandTotal = Math.max(0, (Number(subtotal) + finalShippingFee) - (appliedVoucher?.discount_type === 'free_shipping' ? 0 : voucherDiscount));

    const applyVoucher = (codeToApply?: string) => {
        const code = (codeToApply || voucherCodeInput).trim().toUpperCase();
        if (!code) return;

        setVoucherError('');
        const matched = availableVouchers.find(v => v.code === code);

        if (!matched) {
            setVoucherError('Invalid voucher code.');
            return;
        }

        if (Number(subtotal) < Number(matched.min_spend)) {
            setVoucherError(`Requires minimum spend of ₱${Number(matched.min_spend).toFixed(2)}`);
            return;
        }

        let disc = 0;
        if (matched.discount_type === 'free_shipping') {
            disc = baseShippingFee;
        } else if (matched.discount_type === 'percent') {
            disc = (Number(subtotal) * Number(matched.discount_value)) / 100;
            if (matched.max_discount && disc > Number(matched.max_discount)) {
                disc = Number(matched.max_discount);
            }
        } else {
            disc = Math.min(Number(subtotal), Number(matched.discount_value));
        }

        setAppliedVoucher(matched);
        setVoucherDiscount(disc);
        setData('voucher_code', matched.code);
    };

    const removeVoucher = () => {
        setAppliedVoucher(null);
        setVoucherDiscount(0);
        setVoucherCodeInput('');
        setData('voucher_code', '');
    };

    const handlePromptConfirmation = (e: React.FormEvent) => {
        e.preventDefault();
        setValidationError('');

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

        if (!data.recipient_name.trim() || !data.recipient_phone.trim() || !data.shipping_address.trim() || !data.shipping_city.trim()) {
            setValidationError('Please complete all required recipient and address fields.');
            return;
        }

        setShowConfirmModal(true);
    };

    const submitFinalOrder = () => {
        if (kycStatus !== 'approved') {
            setShowConfirmModal(false);
            return;
        }
        post(route('checkout.store'), {
            onFinish: () => setShowConfirmModal(false),
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
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                {/* Option 1: Doorstep Delivery */}
                                <div 
                                    onClick={() => setData('delivery_type', 'doorstep')}
                                    className={`p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                        data.delivery_type === 'doorstep'
                                            ? 'border-[#E00D42] bg-red-50/20 shadow-xs'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
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
                                            {subtotal > 1500 ? 'FREE' : '₱50.00'}
                                        </span>
                                    </div>
                                    <p className="text-[10px] text-slate-400 mt-2 font-sans">
                                        {subtotal > 1500 ? '✓ Free shipping threshold reached' : 'Standard contiguous road freight'}
                                    </p>
                                </div>

                                {/* Option 2: Free Bayan Hub Self-Pickup */}
                                <div 
                                    onClick={() => setData('delivery_type', 'hub_self_pickup')}
                                    className={`p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                        data.delivery_type === 'hub_self_pickup'
                                            ? 'border-[#E00D42] bg-rose-50/30 shadow-xs'
                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                    }`}
                                >
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
                                        ✓ Zero delivery fees • QR claim code verification
                                    </p>
                                </div>
                            </div>

                            {/* When Bayan Hub Pickup is selected */}
                            {data.delivery_type === 'hub_self_pickup' ? (
                                <div className="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-4">
                                    <div className="flex items-start gap-2.5">
                                        <Building2 className="w-5 h-5 text-[#E00D42] shrink-0 mt-0.5" />
                                        <div>
                                            <h3 className="text-xs font-bold text-slate-900">Select Collection Station (Bayan Hub)</h3>
                                            <p className="text-[11px] text-slate-600 mt-0.5">
                                                Your parcel will be sorted and routed directly to the designated municipal station. You will receive an SMS and a claim QR code once staged.
                                            </p>
                                        </div>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-semibold text-slate-800 mb-1.5">
                                            Designated Bayan Station *
                                        </label>
                                        <select
                                            required
                                            value={data.pickup_hub_id}
                                            onChange={(e) => {
                                                const hubId = e.target.value;
                                                setData('pickup_hub_id', hubId);
                                                const hub = pickupHubs.find(h => String(h.id) === hubId);
                                                if (hub) {
                                                    setData(prev => ({
                                                        ...prev,
                                                        pickup_hub_id: hub.id,
                                                        shipping_address: hub.address,
                                                        shipping_city: hub.city_municipality,
                                                        shipping_province: hub.province,
                                                    }));
                                                }
                                            }}
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
                                            <p className="text-rose-500 text-[11px] mt-1">{errors.pickup_hub_id}</p>
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
                                                            Station Hours: Mon–Sat 8:00 AM – 6:00 PM • Bring valid ID & Order Pickup QR
                                                        </p>
                                                    </>
                                                );
                                            })()}
                                        </div>
                                    )}

                                    {/* Recipient Details for SMS verification */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t border-slate-200 text-xs">
                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5 flex items-center justify-between">
                                                <span>Claimant Name</span>
                                                <span className="text-[10px] text-slate-400 font-normal flex items-center gap-1">
                                                    <Lock className="w-3 h-3 text-slate-400" />
                                                    Verified Real Name
                                                </span>
                                            </label>
                                            <input
                                                type="text"
                                                value={data.recipient_name}
                                                readOnly
                                                disabled
                                                className="w-full px-3.5 py-2.5 bg-slate-100 border border-slate-200 rounded-xl text-slate-600 cursor-not-allowed select-none font-medium"
                                            />
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">Claimant Mobile (For SMS Pin)</label>
                                            <PhoneInput
                                                value={data.recipient_phone}
                                                onChange={(val) => setData('recipient_phone', val)}
                                                placeholder="917 123 4567"
                                                accentColor="primary"
                                                helperText="Will receive station arrival SMS & PIN"
                                                required
                                            />
                                            {errors.recipient_phone && <p className="text-rose-500 text-[11px] mt-1">{errors.recipient_phone}</p>}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                /* When Doorstep Delivery is selected */
                                <div className="space-y-4">
                                    {/* Saved Address Dropdown Selector */}
                                    {addresses.length > 0 && (
                                        <div className="p-4 bg-slate-50/80 border border-slate-200 rounded-xl space-y-2">
                                            <div className="flex items-center justify-between">
                                                <label className="block font-bold text-slate-800 text-xs flex items-center gap-1.5">
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
                                            <label className="block font-semibold text-slate-700 mb-1.5 flex items-center justify-between">
                                                <span>Full Name</span>
                                                <span className="text-[10px] text-slate-400 font-sans font-normal flex items-center gap-1">
                                                    <Lock className="w-3 h-3 text-slate-400" />
                                                    Verified Real Name
                                                </span>
                                            </label>
                                            <input
                                                type="text"
                                                value={data.recipient_name}
                                                readOnly
                                                disabled
                                                className="w-full px-3.5 py-2.5 bg-slate-100 border border-slate-200 rounded-xl text-slate-600 cursor-not-allowed select-none font-medium transition"
                                            />
                                            {errors.recipient_name && <p className="text-rose-500 text-[11px] mt-1">{errors.recipient_name}</p>}
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">Contact Phone</label>
                                            <PhoneInput
                                                value={data.recipient_phone}
                                                onChange={(val) => setData('recipient_phone', val)}
                                                placeholder="917 123 4567"
                                                accentColor="primary"
                                                helperText="10-digit mobile number (e.g. 917 123 4567)"
                                                required
                                            />
                                            {errors.recipient_phone && <p className="text-rose-500 text-[11px] mt-1">{errors.recipient_phone}</p>}
                                        </div>

                                        <div className="sm:col-span-2">
                                            <label className="block font-semibold text-slate-700 mb-1.5">Street Address, Unit / House No. *</label>
                                            <input
                                                type="text"
                                                value={data.shipping_address}
                                                onChange={(e) => setData('shipping_address', e.target.value)}
                                                required
                                                placeholder="House/Unit No., Street name, Subdivision"
                                                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                            />
                                            {errors.shipping_address && <p className="text-rose-500 text-[11px] mt-1">{errors.shipping_address}</p>}
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">Barangay</label>
                                            <input
                                                type="text"
                                                value={data.destination_barangay}
                                                onChange={(e) => setData('destination_barangay', e.target.value)}
                                                placeholder="e.g. Brgy. Santisimo Rosario"
                                                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                            />
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">City / Municipality *</label>
                                            <input
                                                type="text"
                                                value={data.shipping_city}
                                                onChange={(e) => setData('shipping_city', e.target.value)}
                                                required
                                                placeholder="e.g. San Pablo City"
                                                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                            />
                                            {errors.shipping_city && <p className="text-rose-500 text-[11px] mt-1">{errors.shipping_city}</p>}
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">Province</label>
                                            <input
                                                type="text"
                                                value={data.shipping_province}
                                                onChange={(e) => setData('shipping_province', e.target.value)}
                                                placeholder="e.g. Laguna"
                                                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                            />
                                        </div>

                                        <div>
                                            <label className="block font-semibold text-slate-700 mb-1.5">Postal Code (Optional)</label>
                                            <input
                                                type="text"
                                                inputMode="numeric"
                                                maxLength={4}
                                                placeholder="e.g. 4000"
                                                value={data.shipping_postal_code}
                                                onChange={(e) => setData('shipping_postal_code', e.target.value.replace(/\D/g, '').slice(0, 4))}
                                                className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42] transition"
                                            />
                                        </div>

                                        {/* Geolocation Pin Coordinates & Landmark Block */}
                                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200 sm:col-span-2 space-y-2.5">
                                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                                <span className="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                                                    <Crosshair className="w-3.5 h-3.5 text-[#E00D42]" />
                                                    GPS Delivery Geofencing Pin
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
                                                    <label className="block text-[10px] text-slate-500 font-sans mb-0.5">Latitude</label>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        placeholder="e.g. 14.0682"
                                                        value={data.shipping_latitude}
                                                        onChange={(e) => setData('shipping_latitude', e.target.value)}
                                                        className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-sans"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-[10px] text-slate-500 font-sans mb-0.5">Longitude</label>
                                                    <input
                                                        type="number"
                                                        step="any"
                                                        placeholder="e.g. 121.3256"
                                                        value={data.shipping_longitude}
                                                        onChange={(e) => setData('shipping_longitude', e.target.value)}
                                                        className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs font-sans"
                                                    />
                                                </div>
                                            </div>

                                            <div>
                                                <label className="block text-[10px] text-slate-600 font-bold mb-0.5">
                                                    Delivery Landmark & Visual Cue (Eliminates Failed Attempts)
                                                </label>
                                                <input
                                                    type="text"
                                                    placeholder="e.g. Tapat ng Barangay Hall, Dilaw na Gate na may black fence, tabi ng tindahan"
                                                    value={data.landmark}
                                                    onChange={(e) => setData('landmark', e.target.value)}
                                                    className="w-full px-2.5 py-1.5 bg-white border border-slate-200 rounded-lg text-xs"
                                                />
                                                <p className="text-[10px] text-slate-400 mt-0.5">
                                                    Riders navigate straight to this landmark and geofenced pin within ≤100m for guaranteed delivery.
                                                </p>
                                            </div>
                                        </div>

                                        {selectedAddressId === 'new' && (
                                            <label className="flex items-center gap-2 cursor-pointer text-xs text-slate-700 select-none pt-1 sm:col-span-2">
                                                <input
                                                    type="checkbox"
                                                    checked={data.save_address}
                                                    onChange={(e) => setData('save_address', e.target.checked)}
                                                    className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                                />
                                                <span>Save this verified pin and address to my address book</span>
                                            </label>
                                        )}
                                    </div>
                                </div>
                            )}
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
                                {items.map((item) => (
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
                                                {item.quantity} × {formatPrice(item.unit_price)}
                                            </p>
                                            <p className="font-bold text-slate-900 text-sm">
                                                {formatPrice(Number(item.unit_price) * item.quantity)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
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
                                                ? 'Feeder transit straight to local municipal station. Package stored in designated bin for collection via QR code.'
                                                : 'Integrated sorting center routing: Merchant Pickup → Sorting Hub → Assigned Rider → Doorstep Handover.'}
                                        </p>
                                        <p className="text-slate-400 text-[10px] font-sans">
                                            {isHubPickup ? 'Estimated Transit to Hub: 1–2 Business Days' : 'Estimated Doorstep Delivery: 2–4 Business Days'}
                                        </p>
                                    </div>
                                </div>
                                <div className="text-right shrink-0">
                                    <span className="text-[10px] text-slate-400 block font-sans">SHIPPING RATE</span>
                                    <span className={`text-sm font-black ${finalShippingFee === 0 ? 'text-[#E00D42]' : 'text-slate-900'}`}>
                                        {finalShippingFee === 0 ? 'FREE' : formatPrice(finalShippingFee)}
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
                                            <span className="px-2 py-0.5 rounded-xs bg-slate-900 text-white text-[10px] font-sans">DOORSTEP CASH</span>
                                        </div>
                                        <p className="text-slate-600 text-[11px] leading-relaxed">
                                            Pay in cash directly to your assigned Bagoo Express rider only after inspecting your parcel at your doorstep. Zero advance online payment required.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 pt-2 border-t border-slate-200 text-[11px] text-slate-700 font-medium">
                                    <ShieldCheck className="w-4 h-4 text-[#E00D42] shrink-0" />
                                    <span>Protected by 100% Escrow & Doorstep Inspection Protocol</span>
                                </div>
                            </div>
                        </div>

                    </div>

                    {/* Right Column: Order Total Breakdown */}
                    <div className="lg:col-span-4 space-y-6 font-sans text-xs">
                        
                        {/* VOUCHER INPUT CARD */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-3.5">
                            <div className="flex items-center gap-2 font-bold text-slate-900 text-xs">
                                <Tag className="w-4 h-4 text-[#E00D42]" />
                                <span>Platform & Shop Vouchers</span>
                            </div>

                            {appliedVoucher ? (
                                <div className="p-3.5 bg-rose-50 border border-rose-200 rounded-xl flex items-center justify-between">
                                    <div className="space-y-0.5">
                                        <span className="font-bold text-[#E00D42] block text-xs">{appliedVoucher.code}</span>
                                        <span className="text-[11px] text-[#C20836]">
                                            {appliedVoucher.discount_type === 'free_shipping' ? 'Free Shipping Applied' : `₱${appliedVoucher.discount_value} Discount Applied`}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeVoucher}
                                        className="text-[#E00D42] hover:text-[#C20836] p-1"
                                    >
                                        <X className="w-4 h-4" />
                                    </button>
                                </div>
                            ) : (
                                <div className="space-y-2.5">
                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={voucherCodeInput}
                                            onChange={(e) => setVoucherCodeInput(e.target.value)}
                                            placeholder="Enter Promo Code (e.g. PAYDAY70)"
                                            className="flex-1 px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:ring-2 focus:ring-[#E00D42]/15 focus:border-[#E00D42]"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => applyVoucher()}
                                            className="px-4 py-2.5 bg-slate-900 hover:bg-black active:scale-[0.98] text-white font-bold rounded-xl transition text-xs cursor-pointer shadow-xs"
                                        >
                                            Apply
                                        </button>
                                    </div>
                                    {voucherError && <p className="text-rose-500 text-[11px]">{voucherError}</p>}
                                </div>
                            )}
                        </div>

                        {/* ORDER TOTAL SUMMARY */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 sticky top-24">
                            <h3 className="font-bold text-slate-900 text-sm pb-3 border-b border-slate-100">
                                Order Total Breakdown
                            </h3>

                            <div className="space-y-3 text-slate-600 text-xs">
                                <div className="flex justify-between">
                                    <span>Merchandise Subtotal:</span>
                                    <span className="font-semibold text-slate-900">{formatPrice(subtotal)}</span>
                                </div>

                                <div className="flex justify-between">
                                    <span>{isHubPickup ? 'Bayan Hub Collection Fee:' : 'Courier Shipping Fee:'}</span>
                                    <span className={finalShippingFee === 0 ? 'text-[#E00D42] font-bold' : 'font-semibold text-slate-900'}>
                                        {finalShippingFee === 0 ? 'FREE' : formatPrice(finalShippingFee)}
                                    </span>
                                </div>

                                {appliedVoucher && (
                                    <div className="flex justify-between text-[#E00D42] font-bold">
                                        <span>Voucher Discount:</span>
                                        <span>-{formatPrice(finalDiscount)}</span>
                                    </div>
                                )}

                                <div className="flex justify-between">
                                    <span>Payment Mode:</span>
                                    <span className="font-bold text-slate-700 font-sans">CASH ON DELIVERY (COD)</span>
                                </div>
                            </div>

                            <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                <span className="font-bold text-slate-900">Total Payment:</span>
                                <span className="text-2xl font-black text-[#E00D42]">
                                    {formatPrice(grandTotal)}
                                </span>
                            </div>

                            {validationError && (
                                <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs">
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
                                disabled={processing || kycStatus !== 'approved'}
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
                {showConfirmModal && (
                    <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-xs overflow-y-auto flex items-center justify-center p-3 sm:p-4 animate-fade-in">
                        <div className="bg-white rounded-3xl max-w-xl w-full max-h-[92vh] overflow-y-auto p-4 sm:p-8 shadow-2xl border border-slate-200 space-y-5 sm:space-y-6 font-sans my-auto">
                            
                            {/* Modal Header */}
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2 text-xs font-semibold text-[#E00D42]">
                                        <ShoppingBag className="w-4 h-4" />
                                        <span>Final Review Step</span>
                                    </div>
                                    <h3 className="text-lg sm:text-xl font-bold text-slate-900 mt-0.5">
                                        Confirm & Place Order
                                    </h3>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmModal(false)}
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
                                        <span>Delivery Destination</span>
                                    </span>
                                    <p className="font-bold text-slate-900 text-sm">{data.recipient_name} ({data.recipient_phone})</p>
                                    <p className="text-slate-600 text-xs">{data.shipping_address}, {data.shipping_city} {data.shipping_postal_code || ''}</p>
                                </div>

                                {/* Payment Mode & Voucher Summary */}
                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[11px] text-slate-400 font-semibold block">Payment Mode</span>
                                        <span className="font-bold text-slate-700 font-sans">CASH ON DELIVERY (COD)</span>
                                    </div>
                                    <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[11px] text-slate-400 font-semibold block">Applied Voucher</span>
                                        <span className="font-bold text-slate-800">{appliedVoucher ? appliedVoucher.code : 'None'}</span>
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
                                                    <span className="text-slate-400 text-[11px]">Qty: {item.quantity} × {formatPrice(item.unit_price)}</span>
                                                </div>
                                                <span className="font-bold text-slate-900 shrink-0">{formatPrice(Number(item.unit_price) * item.quantity)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Total Amount */}
                                <div className="p-4 bg-rose-50/70 border border-rose-200 rounded-2xl flex items-center justify-between">
                                    <span className="font-semibold text-slate-800 text-xs">Total Amount Due:</span>
                                    <span className="text-xl font-black text-[#E00D42]">{formatPrice(grandTotal)}</span>
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
                                    className="py-3 px-4 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 font-semibold transition text-center cursor-pointer"
                                >
                                    Edit Details
                                </button>
                                <button
                                    type="button"
                                    disabled={!hasConfirmedAgreement || processing}
                                    onClick={submitFinalOrder}
                                    className="py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold transition text-center shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 cursor-pointer"
                                >
                                    <span>{processing ? 'Submitting...' : 'Confirm & Place Order'}</span>
                                </button>
                            </div>

                        </div>
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
