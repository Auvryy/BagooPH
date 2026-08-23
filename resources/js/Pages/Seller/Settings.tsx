import React from 'react';
import { Head, useForm, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Shop } from '@/types';
import { 
    Store, 
    MapPin, 
    Phone, 
    FileText, 
    ShieldCheck, 
    Check, 
    ExternalLink,
    Camera,
    Image as ImageIcon
} from 'lucide-react';

interface Props {
    shop: Shop;
}

export default function SellerSettings({ shop }: Props) {
    const { data, setData, post, processing, recentlySuccessful, errors } = useForm({
        name: shop.name || '',
        description: shop.description || '',
        phone: shop.phone || '',
        address: shop.address || '',
        city: shop.city || '',
        logo: shop.logo || '',
        banner: shop.banner || '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('seller.settings.update'));
    };

    return (
        <DashboardLayout
            title="Storefront Profile & Logistics Configuration"
            subtitle="Configure public branding, dispatch pickup hub address, and seller credentials"
            actions={
                <Link
                    href={route('shop.show', shop.slug)}
                    target="_blank"
                    className="flex items-center gap-1.5 px-4 py-2 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold font-mono rounded-xl border border-slate-200 transition shadow-2xs"
                >
                    <ExternalLink className="w-3.5 h-3.5" />
                    <span>View Public Storefront</span>
                </Link>
            }
        >
            <Head title="Store Settings — BagooPH Seller" />

            <div className="max-w-4xl space-y-6 font-sans">
                
                {/* Store Status Banner */}
                <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex items-center justify-between font-mono text-xs">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-700 flex items-center justify-center font-bold">
                            <ShieldCheck className="w-5 h-5" />
                        </div>
                        <div>
                            <p className="font-bold text-slate-900 uppercase">Merchant Verification Status: PREFERRED MALL</p>
                            <p className="text-slate-500 text-[11px]">Business credentials verified • Auto-dispatch enabled</p>
                        </div>
                    </div>
                    <span className="px-3 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full font-bold uppercase text-[10px]">
                        Active Store
                    </span>
                </div>

                <form onSubmit={submit} className="bg-white rounded-2xl p-6 sm:p-8 border border-slate-200/90 shadow-2xs space-y-6">
                    
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Storefront Brand Name:
                            </label>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                required
                            />
                            {errors.name && <p className="text-xs text-rose-500 mt-1">{errors.name}</p>}
                        </div>

                        <div className="sm:col-span-2">
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Storefront Description & Brand Story:
                            </label>
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                rows={3}
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Merchant Hotline / Mobile:
                            </label>
                            <input
                                type="text"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                required
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Hub City / Region:
                            </label>
                            <input
                                type="text"
                                value={data.city}
                                onChange={(e) => setData('city', e.target.value)}
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                required
                            />
                        </div>

                        <div className="sm:col-span-2">
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Courier Pickup Hub Address (Warehouse):
                            </label>
                            <input
                                type="text"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                                required
                            />
                            <p className="text-[10px] text-slate-500 font-mono mt-1">This address is automatically printed on courier thermal waybills for package collection.</p>
                        </div>

                        <div>
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Store Logo Image URL:
                            </label>
                            <input
                                type="url"
                                value={data.logo}
                                onChange={(e) => setData('logo', e.target.value)}
                                placeholder="https://..."
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>

                        <div>
                            <label className="block text-xs font-mono font-bold text-slate-700 uppercase mb-1">
                                Store Banner Cover URL:
                            </label>
                            <input
                                type="url"
                                value={data.banner}
                                onChange={(e) => setData('banner', e.target.value)}
                                placeholder="https://..."
                                className="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 text-xs focus:bg-white focus:ring-1 focus:ring-[#E00D42]"
                            />
                        </div>
                    </div>

                    {recentlySuccessful && (
                        <p className="text-xs text-emerald-700 font-mono font-bold flex items-center gap-1.5 p-3 bg-emerald-50 border border-emerald-200 rounded-xl">
                            <Check className="w-4 h-4" /> Storefront settings successfully saved!
                        </p>
                    )}

                    <div className="flex items-center justify-end pt-4 border-t border-slate-100">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-6 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-mono font-bold uppercase shadow-sm transition disabled:opacity-50"
                        >
                            {processing ? 'Saving...' : 'Save Storefront Settings'}
                        </button>
                    </div>
                </form>

            </div>
        </DashboardLayout>
    );
}
