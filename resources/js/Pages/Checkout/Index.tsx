import React, { useState } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Cart, CartItem, User } from '@/types';
import { 
    CreditCard, 
    Truck, 
    ShieldCheck, 
    Banknote, 
    Wallet, 
    Building2, 
    ArrowLeft,
    CheckCircle2,
    MapPin,
    Tag,
    Clock,
    Check
} from 'lucide-react';

interface Props {
    cart: Cart;
    items: CartItem[];
    subtotal: number;
    shippingFee: number;
    total: number;
    user: User;
}

export default function CheckoutIndex({ cart, items, subtotal, shippingFee, total, user }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        recipient_name: user.name || '',
        recipient_phone: user.phone || '',
        shipping_address: user.address || '',
        shipping_city: user.city || '',
        shipping_postal_code: user.postal_code || '',
        payment_method: 'cod',
        notes: '',
    });

    const [shippingOption, setShippingOption] = useState<'standard' | 'express'>('standard');
    const actualShippingFee = shippingOption === 'express' ? 95 : (subtotal > 1500 ? 0 : 50);
    const grandTotal = Number(subtotal) + actualShippingFee;

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
                        <p className="text-xs text-slate-500 font-mono">Verify delivery destination, shipping method, and payment mode</p>
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
                    
                    {/* Left Column: Details, Shipping & Payment */}
                    <div className="lg:col-span-8 space-y-6">
                        
                        {/* 1. Delivery Address Card */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 font-mono uppercase">
                                <MapPin className="w-4 h-4 text-[#E00D42]" />
                                <span>Delivery Address Destination</span>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-sans">
                                <div>
                                    <label className="block text-slate-600 font-bold mb-1">Recipient Full Name</label>
                                    <input
                                        type="text"
                                        value={data.recipient_name}
                                        onChange={(e) => setData('recipient_name', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.recipient_name && <p className="text-rose-600 text-[11px] mt-1">{errors.recipient_name}</p>}
                                </div>

                                <div>
                                    <label className="block text-slate-600 font-bold mb-1">Contact Phone Number</label>
                                    <input
                                        type="text"
                                        value={data.recipient_phone}
                                        onChange={(e) => setData('recipient_phone', e.target.value)}
                                        required
                                        placeholder="+63 9XX XXX XXXX"
                                        className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.recipient_phone && <p className="text-rose-600 text-[11px] mt-1">{errors.recipient_phone}</p>}
                                </div>

                                <div className="sm:col-span-2">
                                    <label className="block text-slate-600 font-bold mb-1">Street Address / House No. / Building / Barangay</label>
                                    <input
                                        type="text"
                                        value={data.shipping_address}
                                        onChange={(e) => setData('shipping_address', e.target.value)}
                                        required
                                        placeholder="Unit/House No., Street Name, Barangay"
                                        className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.shipping_address && <p className="text-rose-600 text-[11px] mt-1">{errors.shipping_address}</p>}
                                </div>

                                <div>
                                    <label className="block text-slate-600 font-bold mb-1">City / Municipality / Province</label>
                                    <input
                                        type="text"
                                        value={data.shipping_city}
                                        onChange={(e) => setData('shipping_city', e.target.value)}
                                        required
                                        placeholder="e.g. Quezon City, Metro Manila"
                                        className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {errors.shipping_city && <p className="text-rose-600 text-[11px] mt-1">{errors.shipping_city}</p>}
                                </div>

                                <div>
                                    <label className="block text-slate-600 font-bold mb-1">Postal / ZIP Code</label>
                                    <input
                                        type="text"
                                        value={data.shipping_postal_code}
                                        onChange={(e) => setData('shipping_postal_code', e.target.value)}
                                        placeholder="e.g. 1100"
                                        className="w-full px-3.5 py-2 bg-slate-50 border border-slate-200 rounded-lg text-xs focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* 2. Products Ordered Preview */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 font-mono uppercase">
                                <span>Products Ordered ({items.length})</span>
                                <span className="text-slate-400">Unit Price & Subtotal</span>
                            </div>

                            <div className="divide-y divide-slate-100">
                                {items.map((item) => (
                                    <div key={item.id} className="py-3 flex items-center justify-between gap-4 text-xs">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt={item.product?.name}
                                                className="w-12 h-12 rounded-lg object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate">
                                                <p className="font-bold text-slate-800 truncate">{item.product?.name}</p>
                                                <p className="text-slate-400 font-mono text-[11px]">Qty: {item.quantity}</p>
                                            </div>
                                        </div>

                                        <span className="font-bold font-mono text-slate-900 shrink-0">
                                            {formatPrice(Number(item.unit_price) * item.quantity)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* 3. Shipping Carrier Speed */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 font-mono uppercase">
                                <Truck className="w-4 h-4 text-[#E00D42]" />
                                <span>Select Shipping Carrier Option</span>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 font-mono text-xs">
                                <label className={`p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                    shippingOption === 'standard' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <div className="flex items-center justify-between">
                                        <span className="font-bold text-slate-900">Bagoo Express Standard</span>
                                        <input
                                            type="radio"
                                            name="shippingOption"
                                            checked={shippingOption === 'standard'}
                                            onChange={() => setShippingOption('standard')}
                                            className="text-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                    </div>
                                    <p className="text-slate-500 text-[11px] mt-1 font-sans">Guaranteed 2-3 day doorstep delivery across all regional hubs.</p>
                                    <span className="text-sm font-black text-slate-900 mt-2">
                                        {subtotal > 1500 ? 'FREE (₱0.00)' : formatPrice(50)}
                                    </span>
                                </label>

                                <label className={`p-4 rounded-xl border-2 cursor-pointer transition flex flex-col justify-between ${
                                    shippingOption === 'express' ? 'border-[#E00D42] bg-[#E00D42]/5' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <div className="flex items-center justify-between">
                                        <span className="font-bold text-slate-900">Priority Next-Day Dispatch</span>
                                        <input
                                            type="radio"
                                            name="shippingOption"
                                            checked={shippingOption === 'express'}
                                            onChange={() => setShippingOption('express')}
                                            className="text-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                    </div>
                                    <p className="text-slate-500 text-[11px] mt-1 font-sans">Express 24-hour courier priority routing and handling.</p>
                                    <span className="text-sm font-black text-[#E00D42] mt-2">
                                        {formatPrice(95)}
                                    </span>
                                </label>
                            </div>
                        </div>

                        {/* 4. Payment Method Selection */}
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                            <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-900 font-mono uppercase">
                                <CreditCard className="w-4 h-4 text-[#E00D42]" />
                                <span>Payment Method</span>
                            </div>

                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 font-mono text-xs">
                                <label className={`p-3.5 rounded-xl border-2 cursor-pointer text-center transition flex flex-col items-center justify-between ${
                                    data.payment_method === 'cod' ? 'border-[#E00D42] bg-[#E00D42]/5 text-[#E00D42]' : 'border-slate-200 hover:border-slate-300'
                                }`}>
                                    <Banknote className="w-6 h-6 mb-1 text-slate-700" />
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

                    {/* Right Column: Order Payment Summary */}
                    <div className="lg:col-span-4 space-y-4">
                        <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 font-mono text-xs sticky top-24">
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
                                    <span className={actualShippingFee === 0 ? 'text-emerald-600 font-bold' : 'font-bold text-slate-900'}>
                                        {actualShippingFee === 0 ? 'FREE' : formatPrice(actualShippingFee)}
                                    </span>
                                </div>

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
