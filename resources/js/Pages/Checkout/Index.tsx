import React, { useState } from 'react';
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

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('checkout.store'));
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

                <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
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
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Street Address, Unit / Building</label>
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
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Municipality / City</label>
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
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Postal Code</label>
                                    <input
                                        type="text"
                                        value={data.shipping_postal_code}
                                        onChange={(e) => setData('shipping_postal_code', e.target.value)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* 2. Parcel Items Ordered */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2">
                                    <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold font-mono text-xs">
                                        02
                                    </div>
                                    <h2 className="font-bold text-sm text-slate-900 uppercase font-mono tracking-wider">
                                        Items In Shopping Bag ({items.length})
                                    </h2>
                                </div>
                            </div>

                            <div className="divide-y divide-slate-100 font-mono text-xs">
                                {items.map((item) => (
                                    <div key={item.id} className="py-3 first:pt-0 flex items-center justify-between gap-4">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt=""
                                                className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate">
                                                <h4 className="font-bold text-slate-900 font-sans truncate">{item.product?.name}</h4>
                                                <p className="text-slate-400 text-[11px]">
                                                    Qty: {item.quantity} • {formatPrice(item.product?.price)} each
                                                </p>
                                            </div>
                                        </div>
                                        <span className="font-bold text-slate-900 font-sans shrink-0">
                                            {formatPrice(Number(item.product?.price || 0) * item.quantity)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* 3. Payment Method Selection */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-4 font-mono">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-bold text-xs">
                                    03
                                </div>
                                <h2 className="font-bold text-sm text-slate-900 uppercase tracking-wider">
                                    Payment Method Selection
                                </h2>
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <label className={`p-3.5 rounded-xl border-2 cursor-pointer text-center transition flex flex-col items-center justify-between ${
                                    data.payment_method === 'cod' ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42]' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Truck className="w-6 h-6 mb-1 text-slate-700" />
                                    <span className="font-bold text-[11px] uppercase">Cash on Delivery</span>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'cod'}
                                        onChange={() => setData('payment_method', 'cod')}
                                        className="mt-2 text-[#E00D42]"
                                    />
                                </label>

                                <label className={`p-3.5 rounded-xl border-2 cursor-pointer text-center transition flex flex-col items-center justify-between ${
                                    data.payment_method === 'card' ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42]' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <CreditCard className="w-6 h-6 mb-1 text-slate-700" />
                                    <span className="font-bold text-[11px] uppercase">Credit / Debit</span>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'card'}
                                        onChange={() => setData('payment_method', 'card')}
                                        className="mt-2 text-[#E00D42]"
                                    />
                                </label>

                                <label className={`p-3.5 rounded-xl border-2 cursor-pointer text-center transition flex flex-col items-center justify-between ${
                                    data.payment_method === 'e_wallet' ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42]' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Wallet className="w-6 h-6 mb-1 text-slate-700" />
                                    <span className="font-bold text-[11px] uppercase">GCash / Maya</span>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'e_wallet'}
                                        onChange={() => setData('payment_method', 'e_wallet')}
                                        className="mt-2 text-[#E00D42]"
                                    />
                                </label>

                                <label className={`p-3.5 rounded-xl border-2 cursor-pointer text-center transition flex flex-col items-center justify-between ${
                                    data.payment_method === 'bank_transfer' ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42]' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Building2 className="w-6 h-6 mb-1 text-slate-700" />
                                    <span className="font-bold text-[11px] uppercase">Bank Transfer</span>
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        checked={data.payment_method === 'bank_transfer'}
                                        onChange={() => setData('payment_method', 'bank_transfer')}
                                        className="mt-2 text-[#E00D42]"
                                    />
                                </label>
                            </div>
                        </div>

                    </div>

                    {/* Right Column: Order Payment Summary & Voucher Claim */}
                    <div className="lg:col-span-4 space-y-4 font-mono text-xs">
                        
                        {/* VOUCHER APPLICATION CARD */}
                        <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-3">
                            <div className="flex items-center gap-2 pb-2 border-b border-slate-100">
                                <Tag className="w-4 h-4 text-[#E00D42]" />
                                <h3 className="font-bold text-xs uppercase text-slate-900 tracking-wider">
                                    Vouchers & Discounts
                                </h3>
                            </div>

                            {appliedVoucher ? (
                                <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center justify-between">
                                    <div className="space-y-0.5">
                                        <span className="text-[10px] uppercase font-bold text-emerald-800 font-mono">
                                            Code: {appliedVoucher.code}
                                        </span>
                                        <p className="font-bold text-emerald-700 font-sans text-xs">{appliedVoucher.name}</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={removeVoucher}
                                        className="p-1 rounded-lg text-emerald-600 hover:bg-emerald-100 transition"
                                        title="Remove voucher"
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
                                            onChange={(e) => setVoucherCodeInput(e.target.value.toUpperCase())}
                                            placeholder="Enter Promo Code"
                                            className="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs uppercase font-bold text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => applyVoucher()}
                                            className="px-4 py-2 bg-slate-900 hover:bg-[#E00D42] text-white font-bold rounded-xl uppercase transition shadow-2xs text-xs"
                                        >
                                            Apply
                                        </button>
                                    </div>
                                    {voucherError && <p className="text-rose-500 text-[10px]">{voucherError}</p>}

                                    {/* Available Vouchers Chips */}
                                    {availableVouchers.length > 0 && (
                                        <div className="pt-2 space-y-1.5">
                                            <span className="text-[10px] text-slate-400 uppercase block font-bold">Recommended Vouchers:</span>
                                            <div className="flex flex-wrap gap-1.5">
                                                {availableVouchers.map((v) => (
                                                    <button
                                                        key={v.code}
                                                        type="button"
                                                        onClick={() => applyVoucher(v.code)}
                                                        className="px-2 py-1 rounded-lg bg-slate-100 hover:bg-[#E00D42]/10 hover:text-[#E00D42] border border-slate-200 text-slate-700 text-[10px] font-bold transition flex items-center gap-1"
                                                    >
                                                        <Sparkles className="w-3 h-3 text-[#E00D42]" />
                                                        <span>{v.code}</span>
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
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

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-3.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl uppercase tracking-wider transition shadow-md flex items-center justify-center gap-2 text-xs disabled:opacity-50"
                            >
                                <span>{processing ? 'Processing Order...' : 'Place Order Now'}</span>
                            </button>

                            <div className="flex items-center justify-center gap-2 text-[10px] text-slate-400 pt-2 font-sans">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                <span>BagooPH Buyer Protection Guarantee</span>
                            </div>
                        </div>
                    </div>

                </form>

            </div>
        </BuyerLayout>
    );
}
