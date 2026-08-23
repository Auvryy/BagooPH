import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Cart, CartItem } from '@/types';
import { 
    ShoppingBag, 
    Trash2, 
    Plus, 
    Minus, 
    ArrowRight, 
    ShieldCheck, 
    Truck, 
    Tag, 
    Check, 
    Store,
    ArrowLeft,
    Sparkles
} from 'lucide-react';

interface Props {
    cart: Cart;
    items: CartItem[];
    total: number;
}

export default function CartIndex({ cart, items, total }: Props) {
    const [voucherCode, setVoucherCode] = useState('');
    const [appliedDiscount, setAppliedDiscount] = useState<number>(0);
    const [voucherSuccess, setVoucherSuccess] = useState<string | null>(null);
    const [voucherError, setVoucherError] = useState<string | null>(null);

    const updateQuantity = (item: CartItem, newQty: number) => {
        if (newQty < 1) return;
        router.patch(route('cart.update', item.id), { quantity: newQty }, { preserveScroll: true });
    };

    const removeItem = (item: CartItem) => {
        router.delete(route('cart.destroy', item.id), { preserveScroll: true });
    };

    const applyVoucher = (e: React.FormEvent) => {
        e.preventDefault();
        setVoucherError(null);
        setVoucherSuccess(null);

        const code = voucherCode.trim().toUpperCase();
        if (code === 'BAGOO10' || code === 'PAYDAY70') {
            setAppliedDiscount(200);
            setVoucherSuccess(`Promo code ${code} applied! ₱200 discount active.`);
        } else if (code === 'FREESHIP') {
            setAppliedDiscount(50);
            setVoucherSuccess(`Free shipping voucher ${code} applied!`);
        } else {
            setVoucherError('Invalid promo code. Try "BAGOO10" or "FREESHIP".');
        }
    };

    const subtotal = Number(total || 0);
    const shipping = subtotal > 1500 || voucherCode.toUpperCase() === 'FREESHIP' ? 0 : (subtotal > 0 ? 50 : 0);
    const discount = appliedDiscount;
    const grandTotal = Math.max(0, subtotal + shipping - discount);

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
            <Head title="Shopping Cart — BagooPH" />

            <div className="space-y-6">
                
                {/* Header */}
                <div className="flex items-center justify-between pb-4 border-b border-slate-200">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center font-bold">
                            <ShoppingBag className="w-5 h-5" />
                        </div>
                        <div>
                            <h1 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight">Shopping Bag</h1>
                            <p className="text-xs text-slate-500 font-mono">{items.length} items ready for doorstep dispatch</p>
                        </div>
                    </div>

                    <Link
                        href={route('buyer.index')}
                        className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase"
                    >
                        <ArrowLeft className="w-4 h-4" />
                        <span>Continue Shopping</span>
                    </Link>
                </div>

                {items.length === 0 ? (
                    <div className="bg-white rounded-2xl border border-slate-200 p-12 text-center space-y-4 max-w-lg mx-auto shadow-xs">
                        <div className="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mx-auto text-slate-400">
                            <ShoppingBag className="w-8 h-8" />
                        </div>
                        <h2 className="text-lg font-bold text-slate-800">Your shopping bag is empty</h2>
                        <p className="text-xs text-slate-500">Discover authentic products across 14 verified departments!</p>
                        <Link
                            href={route('buyer.index')}
                            className="inline-flex items-center gap-2 px-6 py-2.5 bg-[#E00D42] text-white text-xs font-bold rounded-xl uppercase tracking-wider shadow-sm hover:bg-[#C20836] transition"
                        >
                            <span>Browse 14 Departments</span>
                            <ArrowRight className="w-4 h-4" />
                        </Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                        
                        {/* Cart Items List (Grouped by Shop) */}
                        <div className="lg:col-span-8 space-y-4">
                            <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                                <div className="flex items-center gap-2 pb-3 border-b border-slate-100 text-xs font-bold text-slate-800">
                                    <Store className="w-4 h-4 text-[#E00D42]" />
                                    <span>Bagoo Flagship & Verified Merchants</span>
                                </div>

                                <div className="divide-y divide-slate-100">
                                    {items.map((item) => (
                                        <div key={item.id} className="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                            
                                            {/* Product Info */}
                                            <div className="flex items-center gap-4 min-w-0">
                                                <img
                                                    src={item.product?.featured_image || ''}
                                                    alt={item.product?.name}
                                                    className="w-16 h-16 rounded-xl object-cover bg-slate-100 shrink-0 border border-slate-200"
                                                />
                                                <div className="truncate space-y-1">
                                                    <Link 
                                                        href={route('buyer.products.show', item.product?.slug || '')}
                                                        className="font-bold text-sm text-slate-900 hover:text-[#E00D42] transition truncate block"
                                                    >
                                                        {item.product?.name}
                                                    </Link>
                                                    <div className="flex items-center gap-2 text-xs font-mono">
                                                        <span className="font-black text-[#E00D42]">
                                                            {formatPrice(item.unit_price)}
                                                        </span>
                                                        <span className="text-slate-400">•</span>
                                                        <span className="text-slate-500 text-[11px]">In Stock ({item.product?.stock ?? 45})</span>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Quantity & Delete Controls */}
                                            <div className="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4 font-mono text-xs">
                                                <div className="flex items-center border border-slate-300 rounded-lg overflow-hidden">
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(item, item.quantity - 1)}
                                                        className="px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                                    >
                                                        <Minus className="w-3 h-3" />
                                                    </button>
                                                    <span className="px-3 py-1 font-bold text-slate-900">{item.quantity}</span>
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(item, item.quantity + 1)}
                                                        className="px-2.5 py-1 bg-slate-50 hover:bg-slate-100 text-slate-700 disabled:opacity-40"
                                                    >
                                                        <Plus className="w-3 h-3" />
                                                    </button>
                                                </div>

                                                <span className="font-bold text-slate-900 min-w-[80px] text-right">
                                                    {formatPrice(Number(item.unit_price) * item.quantity)}
                                                </span>

                                                <button
                                                    type="button"
                                                    onClick={() => removeItem(item)}
                                                    className="p-1.5 text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {/* Order Summary & Voucher Box */}
                        <div className="lg:col-span-4 space-y-4">
                            
                            {/* Voucher Applicator Card */}
                            <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-xs space-y-3 font-sans">
                                <div className="flex items-center gap-2 font-mono text-xs font-bold uppercase text-slate-800">
                                    <Tag className="w-4 h-4 text-[#E00D42]" />
                                    <span>Apply Promo Voucher</span>
                                </div>

                                <form onSubmit={applyVoucher} className="flex gap-2">
                                    <input
                                        type="text"
                                        value={voucherCode}
                                        onChange={(e) => setVoucherCode(e.target.value)}
                                        placeholder="e.g. BAGOO10, FREESHIP"
                                        className="flex-1 px-3 py-2 text-xs bg-slate-50 border border-slate-200 rounded-lg focus:ring-1 focus:ring-[#E00D42] uppercase font-mono"
                                    />
                                    <button
                                        type="submit"
                                        className="px-4 py-2 bg-slate-900 hover:bg-[#E00D42] text-white text-xs font-bold rounded-lg uppercase tracking-wider transition font-mono"
                                    >
                                        Apply
                                    </button>
                                </form>

                                {voucherSuccess && (
                                    <p className="text-xs text-emerald-600 font-mono flex items-center gap-1">
                                        <Check className="w-3.5 h-3.5" /> {voucherSuccess}
                                    </p>
                                )}

                                {voucherError && (
                                    <p className="text-xs text-rose-600 font-mono">
                                        {voucherError}
                                    </p>
                                )}
                            </div>

                            {/* Summary Totals Card */}
                            <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4 font-mono text-xs">
                                <h3 className="font-black text-slate-900 text-sm uppercase tracking-wider pb-3 border-b border-slate-100">
                                    Order Summary
                                </h3>

                                <div className="space-y-2 text-slate-600">
                                    <div className="flex justify-between">
                                        <span>Merchandise Subtotal:</span>
                                        <span className="font-bold text-slate-900">{formatPrice(subtotal)}</span>
                                    </div>

                                    <div className="flex justify-between">
                                        <span>Courier Shipping Fee:</span>
                                        <span className={shipping === 0 ? 'text-emerald-600 font-bold' : 'font-bold text-slate-900'}>
                                            {shipping === 0 ? 'FREE' : formatPrice(shipping)}
                                        </span>
                                    </div>

                                    {discount > 0 && (
                                        <div className="flex justify-between text-[#E00D42] font-bold">
                                            <span>Voucher Discount:</span>
                                            <span>-{formatPrice(discount)}</span>
                                        </div>
                                    )}
                                </div>

                                <div className="pt-3 border-t border-slate-100 flex justify-between items-baseline text-sm">
                                    <span className="font-bold text-slate-900">Total Payment:</span>
                                    <span className="text-xl font-black text-[#E00D42] font-sans">
                                        {formatPrice(grandTotal)}
                                    </span>
                                </div>

                                <Link
                                    href={route('checkout.index')}
                                    className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold rounded-xl uppercase tracking-wider transition shadow-md flex items-center justify-center gap-2 text-xs"
                                >
                                    <span>Proceed to Checkout</span>
                                    <ArrowRight className="w-4 h-4" />
                                </Link>

                                <div className="flex items-center justify-center gap-2 text-[10px] text-slate-400 pt-2">
                                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
                                    <span>100% Secure Checkout Guaranteed</span>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
