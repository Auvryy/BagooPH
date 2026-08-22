import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Cart, CartItem } from '@/types';
import { ShoppingBag, Trash2, Plus, Minus, ArrowRight, ShieldCheck, Truck } from 'lucide-react';

interface Props {
    cart: Cart;
    items: CartItem[];
    total: number;
}

export default function CartIndex({ cart, items, total }: Props) {
    const updateQuantity = (item: CartItem, newQty: number) => {
        if (newQty < 1) return;
        router.patch(route('cart.update', item.id), { quantity: newQty }, { preserveScroll: true });
    };

    const removeItem = (item: CartItem) => {
        router.delete(route('cart.destroy', item.id), { preserveScroll: true });
    };

    const subtotal = Number(total || 0);
    const shipping = subtotal > 100 ? 0 : (subtotal > 0 ? 10 : 0);
    const grandTotal = subtotal + shipping;

    return (
        <MarketplaceLayout>
            <Head title="Your Shopping Cart — Bagoo" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div className="flex items-center gap-3 mb-8">
                    <div className="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold">
                        <ShoppingBag className="w-5 h-5" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-black text-slate-900 tracking-tight">Shopping Cart</h1>
                        <p className="text-xs text-slate-500">{items.length} unique items in your Bagoo bag</p>
                    </div>
                </div>

                {items.length === 0 ? (
                    <div className="bg-white rounded-3xl border border-slate-200 p-12 text-center space-y-4 max-w-lg mx-auto">
                        <div className="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center mx-auto text-slate-400">
                            <ShoppingBag className="w-8 h-8" />
                        </div>
                        <h2 className="text-lg font-bold text-slate-800">Your cart is currently empty</h2>
                        <p className="text-xs text-slate-500">Discover our handcrafted bags, electronics, and accessories.</p>
                        <Link
                            href={route('marketplace')}
                            className="inline-flex items-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-2xl shadow-md shadow-indigo-600/20 transition"
                        >
                            <span>Browse Catalog</span>
                            <ArrowRight className="w-4 h-4" />
                        </Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                        {/* Cart Items List */}
                        <div className="lg:col-span-8 space-y-4">
                            {items.map((item) => (
                                <div
                                    key={item.id}
                                    className="bg-white rounded-3xl border border-slate-200 p-5 flex flex-col sm:flex-row items-center gap-5 justify-between"
                                >
                                    <div className="flex items-center gap-4 w-full sm:w-auto">
                                        <div className="w-20 h-20 rounded-2xl bg-slate-100 overflow-hidden shrink-0">
                                            <img
                                                src={item.product.featured_image || ''}
                                                alt={item.product.name}
                                                className="w-full h-full object-cover"
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <span className="text-[10px] uppercase font-bold text-slate-400">
                                                {item.product.shop?.name || 'Seller'}
                                            </span>
                                            <Link
                                                href={route('products.show', item.product.slug)}
                                                className="font-bold text-sm text-slate-900 hover:text-indigo-600 block line-clamp-1"
                                            >
                                                {item.product.name}
                                            </Link>
                                            <p className="text-xs font-black text-indigo-600">
                                                ${Number(item.unit_price).toFixed(2)} each
                                            </p>
                                        </div>
                                    </div>

                                    {/* Qty & Remove */}
                                    <div className="flex items-center justify-between sm:justify-end gap-6 w-full sm:w-auto pt-3 sm:pt-0 border-t sm:border-t-0 border-slate-100">
                                        <div className="flex items-center border border-slate-200 rounded-xl bg-slate-50 p-1">
                                            <button
                                                onClick={() => updateQuantity(item, item.quantity - 1)}
                                                disabled={item.quantity <= 1}
                                                className="p-1 text-slate-600 hover:text-black disabled:opacity-30 rounded-lg hover:bg-white transition"
                                            >
                                                <Minus className="w-3.5 h-3.5" />
                                            </button>
                                            <span className="px-3 text-xs font-bold text-slate-900">{item.quantity}</span>
                                            <button
                                                onClick={() => updateQuantity(item, item.quantity + 1)}
                                                disabled={item.quantity >= item.product.stock}
                                                className="p-1 text-slate-600 hover:text-black disabled:opacity-30 rounded-lg hover:bg-white transition"
                                            >
                                                <Plus className="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        <div className="text-right min-w-[70px]">
                                            <span className="text-sm font-black text-slate-900">
                                                ${(Number(item.unit_price) * item.quantity).toFixed(2)}
                                            </span>
                                        </div>

                                        <button
                                            onClick={() => removeItem(item)}
                                            className="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition"
                                            title="Remove item"
                                        >
                                            <Trash2 className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {/* Order Summary Box */}
                        <div className="lg:col-span-4">
                            <div className="bg-white rounded-3xl border border-slate-200 p-6 space-y-6 sticky top-28">
                                <h3 className="text-base font-bold text-slate-900">Order Summary</h3>

                                <div className="space-y-3 text-xs">
                                    <div className="flex justify-between text-slate-600">
                                        <span>Subtotal ({items.length} items)</span>
                                        <span className="font-bold text-slate-900">${subtotal.toFixed(2)}</span>
                                    </div>
                                    <div className="flex justify-between text-slate-600">
                                        <span>Standard Courier Shipping</span>
                                        <span className="font-bold text-slate-900">
                                            {shipping === 0 ? <span className="text-emerald-600 uppercase font-black">FREE</span> : `$${shipping.toFixed(2)}`}
                                        </span>
                                    </div>
                                    {subtotal < 100 && (
                                        <p className="text-[11px] text-indigo-600 bg-indigo-50 p-2 rounded-xl">
                                            Add ${(100 - subtotal).toFixed(2)} more for free express shipping!
                                        </p>
                                    )}
                                    <div className="border-t border-slate-100 pt-3 flex justify-between text-sm font-bold text-slate-900">
                                        <span>Total Amount</span>
                                        <span className="text-lg font-black text-indigo-600">${grandTotal.toFixed(2)}</span>
                                    </div>
                                </div>

                                <Link
                                    href={route('checkout.index')}
                                    className="w-full py-3.5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs rounded-2xl shadow-lg shadow-indigo-600/25 transition flex items-center justify-center gap-2 active:scale-95"
                                >
                                    <span>Proceed to Checkout</span>
                                    <ArrowRight className="w-4 h-4" />
                                </Link>

                                <div className="pt-2 border-t border-slate-100 flex items-center gap-2 text-[11px] text-slate-400">
                                    <ShieldCheck className="w-4 h-4 text-emerald-500 shrink-0" />
                                    <span>Guaranteed secure payment & checkout</span>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </MarketplaceLayout>
    );
}
