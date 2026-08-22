import React, { useState } from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Cart, CartItem, User } from '@/types';
import { 
    CreditCard, 
    Truck, 
    ShieldCheck, 
    Banknote, 
    Wallet, 
    Building2, 
    ArrowLeft,
    CheckCircle2
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
        payment_method: 'card',
        notes: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('checkout.store'));
    };

    return (
        <MarketplaceLayout>
            <Head title="Secure Checkout — Bagoo" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div className="flex items-center gap-2 text-xs text-slate-500 mb-6">
                    <Link href={route('cart.index')} className="hover:text-indigo-600 flex items-center gap-1">
                        <ArrowLeft className="w-3.5 h-3.5" />
                        <span>Return to Cart</span>
                    </Link>
                    <span>/</span>
                    <span className="font-bold text-slate-800">Checkout</span>
                </div>

                <h1 className="text-2xl font-black text-slate-900 mb-8">Checkout & Dispatch Confirmation</h1>

                <form onSubmit={submit} className="grid grid-cols-1 lg:grid-cols-12 gap-10">
                    {/* Left Form: Shipping & Payment */}
                    <div className="lg:col-span-7 space-y-8">
                        {/* Recipient / Shipping Details */}
                        <div className="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <Truck className="w-5 h-5 text-indigo-600" />
                                <h2 className="font-bold text-base text-slate-900">Delivery Address & Recipient</h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-slate-700 mb-1">Full Name</label>
                                    <input
                                        type="text"
                                        value={data.recipient_name}
                                        onChange={(e) => setData('recipient_name', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                    />
                                    {errors.recipient_name && <p className="text-[11px] text-rose-500 mt-1">{errors.recipient_name}</p>}
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-700 mb-1">Phone Number</label>
                                    <input
                                        type="text"
                                        value={data.recipient_phone}
                                        onChange={(e) => setData('recipient_phone', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                    />
                                    {errors.recipient_phone && <p className="text-[11px] text-rose-500 mt-1">{errors.recipient_phone}</p>}
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">Street Address</label>
                                <textarea
                                    value={data.shipping_address}
                                    onChange={(e) => setData('shipping_address', e.target.value)}
                                    required
                                    rows={2}
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                />
                                {errors.shipping_address && <p className="text-[11px] text-rose-500 mt-1">{errors.shipping_address}</p>}
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-xs font-bold text-slate-700 mb-1">City / Region</label>
                                    <input
                                        type="text"
                                        value={data.shipping_city}
                                        onChange={(e) => setData('shipping_city', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-bold text-slate-700 mb-1">Postal / Zip Code</label>
                                    <input
                                        type="text"
                                        value={data.shipping_postal_code}
                                        onChange={(e) => setData('shipping_postal_code', e.target.value)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-bold text-slate-700 mb-1">Courier Delivery Instructions (Optional)</label>
                                <input
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="e.g. Leave at apartment reception, ring bell #302"
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-2 focus:ring-indigo-500 focus:bg-white"
                                />
                            </div>
                        </div>

                        {/* Payment Method Selector */}
                        <div className="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-slate-100">
                                <CreditCard className="w-5 h-5 text-indigo-600" />
                                <h2 className="font-bold text-base text-slate-900">Select Payment Method</h2>
                            </div>

                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                {[
                                    { id: 'card', name: 'Credit / Debit Card', desc: 'Visa, Mastercard, Amex', icon: CreditCard },
                                    { id: 'cod', name: 'Cash on Delivery (COD)', desc: 'Pay courier directly', icon: Banknote },
                                    { id: 'bank_transfer', name: 'Direct Bank Transfer', desc: 'Instant ACH/SEPA wire', icon: Building2 },
                                    { id: 'e_wallet', name: 'Digital E-Wallet', desc: 'Apple Pay, PayPal, Google Pay', icon: Wallet },
                                ].map((method) => (
                                    <label
                                        key={method.id}
                                        className={`p-4 rounded-2xl border-2 flex items-start gap-3 cursor-pointer transition ${
                                            data.payment_method === method.id
                                                ? 'border-indigo-600 bg-indigo-50/50 shadow-sm'
                                                : 'border-slate-200 hover:border-slate-300'
                                        }`}
                                    >
                                        <input
                                            type="radio"
                                            name="payment_method"
                                            value={method.id}
                                            checked={data.payment_method === method.id}
                                            onChange={(e) => setData('payment_method', e.target.value)}
                                            className="mt-1 text-indigo-600 focus:ring-indigo-500"
                                        />
                                        <div>
                                            <div className="flex items-center gap-1.5 font-bold text-xs text-slate-900">
                                                <method.icon className="w-3.5 h-3.5 text-indigo-600" />
                                                <span>{method.name}</span>
                                            </div>
                                            <p className="text-[11px] text-slate-500 mt-0.5">{method.desc}</p>
                                        </div>
                                    </label>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Right Summary */}
                    <div className="lg:col-span-5">
                        <div className="bg-white rounded-3xl border border-slate-200 p-6 space-y-6 sticky top-28">
                            <h3 className="text-base font-bold text-slate-900">Order Items ({items.length})</h3>

                            <div className="divide-y divide-slate-100 max-h-64 overflow-y-auto pr-2 space-y-3">
                                {items.map((item) => (
                                    <div key={item.id} className="pt-3 first:pt-0 flex items-center justify-between gap-3 text-xs">
                                        <div className="flex items-center gap-2.5 truncate">
                                            <img
                                                src={item.product.featured_image || ''}
                                                alt={item.product.name}
                                                className="w-10 h-10 rounded-xl object-cover shrink-0 bg-slate-100"
                                            />
                                            <div className="truncate">
                                                <p className="font-bold text-slate-800 truncate">{item.product.name}</p>
                                                <p className="text-slate-400">Qty: {item.quantity}</p>
                                            </div>
                                        </div>
                                        <span className="font-bold text-slate-900 shrink-0">
                                            ${(Number(item.unit_price) * item.quantity).toFixed(2)}
                                        </span>
                                    </div>
                                ))}
                            </div>

                            <div className="border-t border-slate-100 pt-4 space-y-2 text-xs">
                                <div className="flex justify-between text-slate-600">
                                    <span>Subtotal</span>
                                    <span className="font-bold text-slate-900">${Number(subtotal).toFixed(2)}</span>
                                </div>
                                <div className="flex justify-between text-slate-600">
                                    <span>Express Courier Shipping</span>
                                    <span className="font-bold text-slate-900">
                                        {Number(shippingFee) === 0 ? <span className="text-emerald-600 font-black">FREE</span> : `$${Number(shippingFee).toFixed(2)}`}
                                    </span>
                                </div>
                                <div className="border-t border-slate-100 pt-3 flex justify-between text-base font-bold text-slate-900">
                                    <span>Grand Total</span>
                                    <span className="text-xl font-black text-indigo-600">${Number(total).toFixed(2)}</span>
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full py-4 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-bold text-sm rounded-2xl shadow-xl shadow-indigo-600/30 transition active:scale-95 flex items-center justify-center gap-2"
                            >
                                <CheckCircle2 className="w-4 h-4" />
                                <span>{processing ? 'Processing Order...' : `Place Order — $${Number(total).toFixed(2)}`}</span>
                            </button>

                            <div className="p-3 rounded-2xl bg-slate-50 border border-slate-200 text-slate-500 text-[11px] space-y-1">
                                <div className="flex items-center gap-1.5 text-slate-700 font-bold">
                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                    <span>Automatic Courier Dispatch</span>
                                </div>
                                <p>Upon order placement, our dispatch system notifies the seller and assigns the closest courier.</p>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </MarketplaceLayout>
    );
}
