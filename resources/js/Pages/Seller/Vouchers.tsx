import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Shop } from '@/types';
import { 
    Tag, 
    Plus, 
    Trash2, 
    X, 
    Check, 
    Percent, 
    DollarSign, 
    Truck, 
    Clock, 
    Sparkles,
    Calendar,
    Layers,
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
    usage_limit?: number;
    used_count: number;
    is_active: boolean;
    expires_at?: string;
    created_at: string;
}

interface Props {
    vouchers: PaginatedData<VoucherItem>;
    shop: Shop;
}

export default function SellerVouchers({ vouchers, shop }: Props) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const form = useForm({
        code: '',
        name: '',
        description: '',
        discount_type: 'fixed',
        discount_value: '100',
        min_spend: '500',
        max_discount: '',
        usage_limit: '100',
        expires_at: '',
    });

    const handleCreate = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('seller.vouchers.store'), {
            onSuccess: () => {
                setIsCreateOpen(false);
                form.reset();
            },
        });
    };

    const handleToggle = (id: number) => {
        router.patch(route('seller.vouchers.toggle', id), {}, { preserveScroll: true });
    };

    const handleDelete = (id: number) => {
        if (confirm('Are you sure you want to remove this voucher?')) {
            router.delete(route('seller.vouchers.destroy', id), { preserveScroll: true });
        }
    };

    return (
        <DashboardLayout
            title="Store Vouchers & Promotions Engine"
            subtitle={`Issue custom promo codes and discounts for ${shop.name}`}
            actions={
                <button
                    onClick={() => setIsCreateOpen(true)}
                    className="flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-xl shadow-xs transition uppercase"
                >
                    <Plus className="w-3.5 h-3.5" />
                    <span>Create Voucher</span>
                </button>
            }
        >
            <Head title="Vouchers & Promotions — BagooPH Seller" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP PROMO STATS */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-2 font-mono">
                        <div className="flex items-center justify-between text-slate-500 text-xs font-bold uppercase">
                            <span>Active Promotions</span>
                            <Tag className="w-4 h-4 text-[#E00D42]" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900 font-sans">
                            {vouchers.data.filter(v => v.is_active).length} Vouchers
                        </h3>
                        <p className="text-[11px] text-slate-400">Published to your store</p>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-2 font-mono">
                        <div className="flex items-center justify-between text-slate-500 text-xs font-bold uppercase">
                            <span>Customer Redemptions</span>
                            <Gift className="w-4 h-4 text-emerald-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900 font-sans">
                            {vouchers.data.reduce((sum, v) => sum + v.used_count, 0)} Claims
                        </h3>
                        <p className="text-[11px] text-slate-400">Total applied in checkout</p>
                    </div>

                    <div className="bg-white rounded-2xl p-5 border border-slate-200 shadow-2xs space-y-2 font-mono">
                        <div className="flex items-center justify-between text-slate-500 text-xs font-bold uppercase">
                            <span>Default Delivery Voucher</span>
                            <Truck className="w-4 h-4 text-indigo-600" />
                        </div>
                        <h3 className="text-2xl font-black text-slate-900 font-sans">
                            FREESHIP
                        </h3>
                        <p className="text-[11px] text-emerald-600 font-bold">Network standard active</p>
                    </div>
                </div>

                {/* 2. VOUCHERS TABLE */}
                <div className="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-2xs">
                    <div className="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between">
                        <div>
                            <h3 className="font-bold text-sm text-slate-900">Store Promotional Codes</h3>
                            <p className="text-xs text-slate-400 font-mono">Manage store discounts applied by shoppers</p>
                        </div>
                        <span className="text-xs font-mono text-slate-500">Showing {vouchers.data.length} vouchers</span>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left font-mono text-xs text-slate-700">
                            <thead className="bg-slate-50 border-b border-slate-200 text-slate-500 uppercase text-[10px] tracking-wider">
                                <tr>
                                    <th className="py-3.5 px-5 font-bold">Voucher Code</th>
                                    <th className="py-3.5 px-4 font-bold">Discount Benefit</th>
                                    <th className="py-3.5 px-4 font-bold">Min Spend</th>
                                    <th className="py-3.5 px-4 font-bold">Redemptions</th>
                                    <th className="py-3.5 px-4 font-bold">Status</th>
                                    <th className="py-3.5 px-5 text-right font-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {vouchers.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="py-12 text-center text-slate-400">
                                            No store vouchers created yet. Click "Create Voucher" above to publish your first promotion.
                                        </td>
                                    </tr>
                                ) : (
                                    vouchers.data.map((voucher) => (
                                        <tr key={voucher.id} className="hover:bg-slate-50 transition">
                                            <td className="py-4 px-5">
                                                <div className="flex items-center gap-2">
                                                    <span className="px-2.5 py-1 rounded-lg bg-slate-900 text-white font-black text-xs tracking-wider">
                                                        {voucher.code}
                                                    </span>
                                                    <span className="text-xs font-sans font-bold text-slate-800 hidden sm:inline-block">
                                                        {voucher.name}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="py-4 px-4">
                                                <span className="font-bold text-[#E00D42] font-sans">
                                                    {voucher.discount_type === 'percent' 
                                                        ? `${Number(voucher.discount_value)}% OFF` 
                                                        : (voucher.discount_type === 'free_shipping' ? 'FREE DELIVERY' : formatPrice(voucher.discount_value) + ' OFF')}
                                                </span>
                                            </td>
                                            <td className="py-4 px-4 font-sans">
                                                {formatPrice(voucher.min_spend)}
                                            </td>
                                            <td className="py-4 px-4">
                                                <span>{voucher.used_count} {voucher.usage_limit ? `/ ${voucher.usage_limit}` : 'claims'}</span>
                                            </td>
                                            <td className="py-4 px-4">
                                                <button
                                                    onClick={() => handleToggle(voucher.id)}
                                                    className={`px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase transition ${
                                                        voucher.is_active 
                                                            ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' 
                                                            : 'bg-slate-100 text-slate-500'
                                                    }`}
                                                >
                                                    {voucher.is_active ? 'Active' : 'Disabled'}
                                                </button>
                                            </td>
                                            <td className="py-4 px-5 text-right space-x-1">
                                                <button
                                                    onClick={() => handleDelete(voucher.id)}
                                                    className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition"
                                                    title="Delete Voucher"
                                                >
                                                    <Trash2 className="w-4 h-4" />
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            {/* Create Voucher Modal */}
            {isCreateOpen && (
                <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4 backdrop-blur-xs animate-fade-in font-sans">
                    <div className="bg-white text-slate-900 rounded-3xl max-w-lg w-full p-6 sm:p-8 space-y-5 shadow-2xl border border-slate-200">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <Tag className="w-5 h-5 text-[#E00D42]" />
                                <h3 className="font-bold text-base text-slate-900">Issue Store Voucher</h3>
                            </div>
                            <button onClick={() => setIsCreateOpen(false)} className="p-1 text-slate-400 hover:text-slate-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleCreate} className="space-y-4 text-xs font-mono">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Voucher Code</label>
                                    <input
                                        type="text"
                                        value={form.data.code}
                                        onChange={(e) => form.setData('code', e.target.value.toUpperCase())}
                                        placeholder="e.g. PRIME200"
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 uppercase font-black focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                    {form.errors.code && <p className="text-rose-500 text-[10px] mt-1">{form.errors.code}</p>}
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Discount Type</label>
                                    <select
                                        value={form.data.discount_type}
                                        onChange={(e) => form.setData('discount_type', e.target.value as any)}
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    >
                                        <option value="fixed">Fixed Pesos (₱)</option>
                                        <option value="percent">Percentage (%)</option>
                                        <option value="free_shipping">Free Delivery</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 uppercase mb-1">Promotion Title</label>
                                <input
                                    type="text"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="e.g. VIP Merchant ₱150 Voucher"
                                    required
                                    className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Discount Value</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={form.data.discount_value}
                                        onChange={(e) => form.setData('discount_value', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                                <div>
                                    <label className="block font-bold text-slate-700 uppercase mb-1">Min Spend (₱)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        value={form.data.min_spend}
                                        onChange={(e) => form.setData('min_spend', e.target.value)}
                                        required
                                        className="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                    />
                                </div>
                            </div>

                            <div className="flex justify-end gap-3 pt-3 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setIsCreateOpen(false)}
                                    className="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl uppercase transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="px-6 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl uppercase shadow-xs transition"
                                >
                                    Publish Promotion
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
