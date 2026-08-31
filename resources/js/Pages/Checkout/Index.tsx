import React, { useState, useEffect } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Cart, CartItem, PageProps, User } from '@/types';
import { 
    ShoppingBag, 
    ArrowLeft, 
    Truck, 
    CreditCard, 
    Wallet, 
    Building2, 
    ShieldCheck, 
    MapPin, 
    Check, 
    AlertCircle,
    Tag,
    Clock,
    X,
    Sparkles,
    Gift
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

interface Props {
    cart: Cart;
    items: CartItem[];
    subtotal: number;
    shippingFee: number;
    total: number;
    user: User;
    availableVouchers?: VoucherItem[];
}

export default function CheckoutIndex({ cart, items, subtotal, shippingFee, user, availableVouchers = [] }: Props) {
    const { flash } = usePage<PageProps>().props;

    const [voucherCodeInput, setVoucherCodeInput] = useState('');
    const [appliedVoucher, setAppliedVoucher] = useState<VoucherItem | null>(null);
    const [voucherDiscount, setVoucherDiscount] = useState<number>(0);
    const [voucherError, setVoucherError] = useState<string>('');

    const { data, setData, post, processing, errors } = useForm({
        recipient_name: user.name || '',
        recipient_phone: user.phone || '',
        shipping_address: user.address || '',
        shipping_city: user.city || '',
        shipping_postal_code: user.postal_code || '',
        payment_method: 'cod',
        notes: '',
        voucher_code: '',
    });

    const [shippingOption, setShippingOption] = useState<'standard' | 'express'>('standard');
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

    const baseShippingFee = shippingOption === 'express' ? 95 : (subtotal > 1500 ? 0 : 50);
    
    // Calculate final shipping fee accounting for free shipping vouchers
    const finalShippingFee = appliedVoucher?.discount_type === 'free_shipping' ? 0 : baseShippingFee;
    const finalDiscount = appliedVoucher?.discount_type === 'free_shipping' ? baseShippingFee : voucherDiscount;
    const grandTotal = Math.max(0, (Number(subtotal) + (appliedVoucher?.discount_type === 'free_shipping' ? 0 : baseShippingFee)) - (appliedVoucher?.discount_type === 'free_shipping' ? 0 : voucherDiscount));

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

        if (!data.recipient_name.trim() || !data.recipient_phone.trim() || !data.shipping_address.trim() || !data.shipping_city.trim()) {
            setValidationError('Please complete all required recipient and address fields.');
            return;
        }

        setShowConfirmModal(true);
    };

    const submitFinalOrder = () => {
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
                <div className="flex items-center justify-between pb-4 border-b border-slate-200">
                    <div>
                        <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Finalize Checkout</h1>
                        <p className="text-xs text-slate-500 font-mono">Verify delivery destination, applied vouchers, and payment mode</p>
                    </div>

                    <Link
                        href={route('buyer.cart')}
                        className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        <span>Back to My Bag</span>
                    </Link>
                </div>

                <form onSubmit={handlePromptConfirmation} className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left Column: Forms */}
                    <div className="lg:col-span-8 space-y-6 font-sans">
                        
                        {/* 1. Verified Delivery Destination */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold font-mono text-xs">
                                    01
                                </div>
                                <h2 className="font-bold text-sm text-slate-900 uppercase font-mono tracking-wider">
                                    Delivery Address & Recipient
                                </h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-mono">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Full Name</label>
                                    <input
                                        type="text"
                                        value={data.recipient_name}
                                        onChange={(e) => setData('recipient_name', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.recipient_name && <p className="text-rose-500 text-[10px] mt-1">{errors.recipient_name}</p>}
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Contact Phone</label>
                                    <input
                                        type="text"
                                        value={data.recipient_phone}
                                        onChange={(e) => setData('recipient_phone', e.target.value)}
                                        required
                                        placeholder="09XXXXXXXXX"
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.recipient_phone && <p className="text-rose-500 text-[10px] mt-1">{errors.recipient_phone}</p>}
                                </div>

                                <div className="sm:col-span-2">
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Street Address, Unit / House No.</label>
                                    <input
                                        type="text"
                                        value={data.shipping_address}
                                        onChange={(e) => setData('shipping_address', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.shipping_address && <p className="text-rose-500 text-[10px] mt-1">{errors.shipping_address}</p>}
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">City / Municipality</label>
                                    <input
                                        type="text"
                                        value={data.shipping_city}
                                        onChange={(e) => setData('shipping_city', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.shipping_city && <p className="text-rose-500 text-[10px] mt-1">{errors.shipping_city}</p>}
                                </div>

                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Postal Code (Optional)</label>
                                    <input
                                        type="text"
                                        value={data.shipping_postal_code}
                                        onChange={(e) => setData('shipping_postal_code', e.target.value)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* 2. Package Items in this Order */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold font-mono text-xs">
                                    02
                                </div>
                                <h2 className="font-bold text-sm text-slate-900 uppercase font-mono tracking-wider">
                                    Items In Your Shopping Bag ({items.length})
                                </h2>
                            </div>

                            <div className="divide-y divide-slate-100 font-mono text-xs">
                                {items.map((item) => (
                                    <div key={item.id} className="py-3 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt=""
                                                className="w-14 h-14 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate font-sans text-xs">{item.product?.name}</p>
                                                <p className="text-slate-500 text-[11px]">
                                                    Shop: {item.product?.shop?.name || 'Bagoo Prime Store'}
                                                </p>
                                                {(item.color || item.size) && (
                                                    <p className="text-slate-400 text-[10px]">
                                                        Variant: {[item.color, item.size].filter(Boolean).join(' / ')}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="text-right shrink-0">
                                            <p className="text-slate-500 text-[11px]">
                                                {item.quantity} × {formatPrice(item.unit_price)}
                                            </p>
                                            <p className="font-bold text-slate-900">
                                                {formatPrice(Number(item.unit_price) * item.quantity)}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* 3. Logistics & Delivery Carrier */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold font-mono text-xs">
                                    03
                                </div>
                                <h2 className="font-bold text-sm text-slate-900 uppercase font-mono tracking-wider">
                                    Shipping Carrier & Delivery Option
                                </h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs font-mono">
                                <label className={`p-4 rounded-xl border cursor-pointer transition flex items-start gap-3 ${
                                    shippingOption === 'standard' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 bg-white hover:border-slate-300'
                                }`}>
                                    <input
                                        type="radio"
                                        name="shipping_tier"
                                        checked={shippingOption === 'standard'}
                                        onChange={() => setShippingOption('standard')}
                                        className="mt-0.5 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Truck className="w-4 h-4 text-[#E00D42]" />
                                            <span>Bagoo Express Standard</span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">Delivery in 2-3 days</p>
                                        <p className="font-bold text-[#E00D42]">{subtotal > 1500 ? 'FREE' : '₱50.00'}</p>
                                    </div>
                                </label>

                                <label className={`p-4 rounded-xl border cursor-pointer transition flex items-start gap-3 ${
                                    shippingOption === 'express' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 bg-white hover:border-slate-300'
                                }`}>
                                    <input
                                        type="radio"
                                        name="shipping_tier"
                                        checked={shippingOption === 'express'}
                                        onChange={() => setShippingOption('express')}
                                        className="mt-0.5 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Sparkles className="w-4 h-4 text-amber-500" />
                                            <span>Priority Express Next-Day</span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">Priority dispatch & routing</p>
                                        <p className="font-bold text-slate-900">₱95.00</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        {/* 4. Payment Methods */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold font-mono text-xs">
                                    04
                                </div>
                                <h2 className="font-bold text-sm text-slate-900 uppercase font-mono tracking-wider">
                                    Payment Method
                                </h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs font-mono">
                                <label className={`p-4 rounded-xl border cursor-pointer transition flex items-start gap-3 ${
                                    data.payment_method === 'cod' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 bg-white hover:border-slate-300'
                                }`}>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'cod'}
                                        onChange={() => setData('payment_method', 'cod')}
                                        className="mt-0.5 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Wallet className="w-4 h-4 text-emerald-600" />
                                            <span>Cash On Delivery</span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">Pay upon doorstep receipt</p>
                                    </div>
                                </label>

                                <label className={`p-4 rounded-xl border cursor-pointer transition flex items-start gap-3 ${
                                    data.payment_method === 'card' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 bg-white hover:border-slate-300'
                                }`}>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'card'}
                                        onChange={() => setData('payment_method', 'card')}
                                        className="mt-0.5 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <CreditCard className="w-4 h-4 text-indigo-600" />
                                            <span>Credit / Debit Card</span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">Visa, Mastercard, JCB</p>
                                    </div>
                                </label>

                                <label className={`p-4 rounded-xl border cursor-pointer transition flex items-start gap-3 ${
                                    data.payment_method === 'e_wallet' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 bg-white hover:border-slate-300'
                                }`}>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'e_wallet'}
                                        onChange={() => setData('payment_method', 'e_wallet')}
                                        className="mt-0.5 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <div className="space-y-1">
                                        <div className="flex items-center gap-1.5 font-bold text-slate-900">
                                            <Building2 className="w-4 h-4 text-blue-600" />
                                            <span>E-Wallet / GCash</span>
                                        </div>
                                        <p className="text-slate-500 text-[11px]">Instant digital checkout</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                    </div>

                    {/* Right Column: Order Total Breakdown */}
                    <div className="lg:col-span-4 space-y-6 font-mono text-xs">
                        
                        {/* VOUCHER INPUT CARD */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-3">
                            <div className="flex items-center gap-2 font-bold text-slate-800 uppercase text-xs">
                                <Tag className="w-4 h-4 text-[#E00D42]" />
                                <span>Platform & Shop Vouchers</span>
                            </div>

                            {appliedVoucher ? (
                                <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center justify-between">
                                    <div className="space-y-0.5">
                                        <span className="font-bold text-emerald-800 block text-xs">{appliedVoucher.code}</span>
                                        <span className="text-[10px] text-emerald-600">
                                            {appliedVoucher.discount_type === 'free_shipping' ? 'Free Shipping Applied' : `₱${appliedVoucher.discount_value} Discount Applied`}
                                        </span>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeVoucher}
                                        className="text-emerald-700 hover:text-emerald-900 p-1"
                                    >
                                        <X className="w-4 h-4" />
                                    </button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={voucherCodeInput}
                                            onChange={(e) => setVoucherCodeInput(e.target.value)}
                                            placeholder="ENTER CODE (e.g. PAYDAY70)"
                                            className="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 uppercase text-xs focus:ring-1 focus:ring-[#E00D42]"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => applyVoucher()}
                                            className="px-3.5 py-2 bg-slate-900 hover:bg-slate-800 text-white font-bold rounded-xl uppercase transition text-xs"
                                        >
                                            Apply
                                        </button>
                                    </div>
                                    {voucherError && <p className="text-rose-500 text-[10px]">{voucherError}</p>}
                                </div>
                            )}
                        </div>

                        {/* ORDER TOTAL SUMMARY */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 sticky top-24">
                            <h3 className="font-black text-slate-900 text-sm uppercase tracking-wider pb-3 border-b border-slate-100">
                                Order Total Breakdown
                            </h3>

                            <div className="space-y-2.5 text-slate-600">
                                <div className="flex justify-between">
                                    <span>Merchandise Subtotal:</span>
                                    <span className="font-bold text-slate-900">{formatPrice(subtotal)}</span>
                                </div>

                                <div className="flex justify-between">
                                    <span>Courier Shipping Fee:</span>
                                    <span className={finalShippingFee === 0 ? 'text-emerald-600 font-bold' : 'font-bold text-slate-900'}>
                                        {finalShippingFee === 0 ? 'FREE' : formatPrice(finalShippingFee)}
                                    </span>
                                </div>

                                {appliedVoucher && (
                                    <div className="flex justify-between text-emerald-600 font-bold">
                                        <span>Voucher Discount:</span>
                                        <span>-{formatPrice(finalDiscount)}</span>
                                    </div>
                                )}

                                <div className="flex justify-between">
                                    <span>Payment Selected:</span>
                                    <span className="font-bold uppercase text-[#E00D42]">{data.payment_method}</span>
                                </div>
                            </div>

                            <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                <span className="font-bold text-slate-900">Total Payment:</span>
                                <span className="text-2xl font-black text-[#E00D42] font-sans">
                                    {formatPrice(grandTotal)}
                                </span>
                            </div>

                            {validationError && (
                                <div className="p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs">
                                    {validationError}
                                </div>
                            )}

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl uppercase tracking-wider transition shadow-md flex items-center justify-center gap-2 text-xs disabled:opacity-50 cursor-pointer"
                            >
                                <span>{processing ? 'Processing Order...' : 'Review & Place Order'}</span>
                            </button>

                            <div className="flex items-center justify-center gap-2 text-[10px] text-slate-400 pt-2 font-sans">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                <span>BagooPH Buyer Protection Guarantee</span>
                            </div>
                        </div>
                    </div>

                </form>

                {/* ORDER PLACEMENT CONFIRMATION MODAL */}
                {showConfirmModal && (
                    <div className="fixed inset-0 z-50 bg-black/75 backdrop-blur-xs overflow-y-auto flex items-center justify-center p-4 animate-fade-in">
                        <div className="bg-white rounded-3xl max-w-xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 space-y-6 font-sans my-auto">
                            
                            {/* Modal Header */}
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2 text-xs font-mono font-bold text-[#E00D42] uppercase tracking-wider">
                                        <ShoppingBag className="w-4 h-4" />
                                        <span>Final Review Step</span>
                                    </div>
                                    <h3 className="text-lg sm:text-xl font-black text-slate-900">
                                        Confirm & Place Order
                                    </h3>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmModal(false)}
                                    className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition"
                                >
                                    <X className="w-5 h-5" />
                                </button>
                            </div>

                            {/* Summary Cards */}
                            <div className="space-y-3 font-mono text-xs">
                                
                                {/* Recipient & Destination Summary */}
                                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-1">
                                    <span className="text-[10px] text-slate-400 uppercase font-bold block flex items-center gap-1">
                                        <MapPin className="w-3 h-3 text-[#E00D42]" />
                                        <span>Delivery Destination</span>
                                    </span>
                                    <p className="font-bold text-slate-900">{data.recipient_name} ({data.recipient_phone})</p>
                                    <p className="text-slate-600 text-[11px]">{data.shipping_address}, {data.shipping_city} {data.shipping_postal_code || ''}</p>
                                </div>

                                {/* Payment Mode & Voucher Summary */}
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[10px] text-slate-400 uppercase font-bold block">Payment Mode</span>
                                        <span className="font-bold text-[#E00D42] uppercase">{data.payment_method}</span>
                                    </div>
                                    <div className="p-3 bg-slate-50 border border-slate-200 rounded-xl">
                                        <span className="text-[10px] text-slate-400 uppercase font-bold block">Applied Voucher</span>
                                        <span className="font-bold text-slate-800">{appliedVoucher ? appliedVoucher.code : 'None'}</span>
                                    </div>
                                </div>

                                {/* Items Preview List */}
                                <div className="p-3.5 bg-slate-50 border border-slate-200 rounded-xl space-y-2 max-h-40 overflow-y-auto">
                                    <span className="text-[10px] text-slate-400 uppercase font-bold block">Package Items ({items.length})</span>
                                    <div className="divide-y divide-slate-200">
                                        {items.map(item => (
                                            <div key={item.id} className="py-1.5 flex items-center justify-between text-[11px]">
                                                <div className="truncate pr-2">
                                                    <span className="font-bold text-slate-800">{item.product?.name}</span>
                                                    <span className="text-slate-400 block text-[10px]">Qty: {item.quantity} × {formatPrice(item.unit_price)}</span>
                                                </div>
                                                <span className="font-bold text-slate-900 shrink-0">{formatPrice(Number(item.unit_price) * item.quantity)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                {/* Total Amount */}
                                <div className="p-3.5 bg-rose-50/70 border border-rose-200 rounded-xl flex items-center justify-between">
                                    <span className="font-bold text-slate-800 text-xs">Total Amount Due:</span>
                                    <span className="text-xl font-black text-[#E00D42] font-sans">{formatPrice(grandTotal)}</span>
                                </div>
                            </div>

                            {/* Verification Checkbox */}
                            <label className="flex items-start gap-2.5 cursor-pointer text-xs text-slate-700 select-none p-1 font-mono">
                                <input
                                    type="checkbox"
                                    checked={hasConfirmedAgreement}
                                    onChange={(e) => setHasConfirmedAgreement(e.target.checked)}
                                    className="mt-0.5 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                />
                                <span>I have reviewed my shipping details and confirm this order placement.</span>
                            </label>

                            {/* Modal Action Buttons */}
                            <div className="grid grid-cols-2 gap-3 pt-2 font-mono text-xs">
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmModal(false)}
                                    className="py-3 px-4 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 font-bold uppercase transition text-center"
                                >
                                    Edit Details
                                </button>
                                <button
                                    type="button"
                                    disabled={!hasConfirmedAgreement || processing}
                                    onClick={submitFinalOrder}
                                    className="py-3 px-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase tracking-wider transition text-center shadow-md disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5"
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
