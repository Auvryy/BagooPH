import React, { FormEventHandler, useRef, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { ArrowRight, Lock, Mail, Store, User, Upload, FileText, X, Phone, MapPin, Sparkles, ShoppingBag, ShieldCheck } from 'lucide-react';

export default function SellerRegister() {
    const idInputRef = useRef<HTMLInputElement>(null);
    const permitInputRef = useRef<HTMLInputElement>(null);

    const [idFileName, setIdFileName] = useState<string | null>(null);
    const [idFileSize, setIdFileSize] = useState<string | null>(null);
    const [permitFileName, setPermitFileName] = useState<string | null>(null);
    const [permitFileSize, setPermitFileSize] = useState<string | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        shop_name: string;
        email: string;
        phone: string;
        address: string;
        city: string;
        role: 'seller';
        password: string;
        password_confirmation: string;
        id_document: File | null;
        business_permit: File | null;
    }>({
        name: '',
        shop_name: '',
        email: '',
        phone: '',
        address: '',
        city: '',
        role: 'seller',
        password: '',
        password_confirmation: '',
        id_document: null,
        business_permit: null,
    });

    const handleIdChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('id_document', file);
            setIdFileName(file.name);
            setIdFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removeIdFile = () => {
        setData('id_document', null);
        setIdFileName(null);
        setIdFileSize(null);
        if (idInputRef.current) idInputRef.current.value = '';
    };

    const handlePermitChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('business_permit', file);
            setPermitFileName(file.name);
            setPermitFileSize((file.size / (1024 * 1024)).toFixed(2) + ' MB');
        }
    };

    const removePermitFile = () => {
        setData('business_permit', null);
        setPermitFileName(null);
        setPermitFileSize(null);
        if (permitInputRef.current) permitInputRef.current.value = '';
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            forceFormData: true,
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout 
            title="Open Seller Store" 
            subtitle="Sell across 14 departments with waybill generation & 10% flat commission"
            headerBadge="MERCHANT STUDIO // 03"
        >
            <Head title="Seller Registration — BagooPH" />

            <form onSubmit={submit} className="space-y-4 font-mono">
                {/* Store Name Input */}
                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Store / Merchant Business Name *
                    </label>
                    <div className="relative">
                        <Store className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="shop_name"
                            type="text"
                            name="shop_name"
                            value={data.shop_name}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="e.g. Apex Athletics & Apparel"
                            autoFocus
                            onChange={(e) => setData('shop_name', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.shop_name} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Merchant Owner Full Name *
                        </label>
                        <div className="relative">
                            <User className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="name"
                                type="text"
                                name="name"
                                value={data.name}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="e.g. Maria Santos"
                                autoComplete="name"
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.name} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Business Email Address *
                        </label>
                        <div className="relative">
                            <Mail className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="email"
                                type="email"
                                name="email"
                                value={data.email}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="merchant@brand.com"
                                autoComplete="username"
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.email} className="mt-1" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Business Phone Number *
                        </label>
                        <div className="relative">
                            <Phone className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="phone"
                                type="tel"
                                name="phone"
                                value={data.phone}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="+63 917 000 0000"
                                onChange={(e) => setData('phone', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.phone} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Business City / Region *
                        </label>
                        <input
                            id="city"
                            type="text"
                            name="city"
                            value={data.city}
                            className="w-full px-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="e.g. Makati City"
                            onChange={(e) => setData('city', e.target.value)}
                            required
                        />
                        <InputError message={errors.city} className="mt-1" />
                    </div>
                </div>

                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Pickup Store Address *
                    </label>
                    <div className="relative">
                        <MapPin className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="address"
                            type="text"
                            name="address"
                            value={data.address}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="Unit / Building / Street for courier dispatch pickup"
                            onChange={(e) => setData('address', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.address} className="mt-1" />
                </div>

                {/* KYC Uploads: Gov ID & Business Permit */}
                <div className="space-y-3 pt-1">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>1. Merchant Owner Government ID *</span>
                            <span className="text-[10px] text-[#E00D42] font-normal">Passport / UMID / Driver's License</span>
                        </label>
                        <input
                            type="file"
                            ref={idInputRef}
                            onChange={handleIdChange}
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="hidden"
                        />
                        {!idFileName ? (
                            <div 
                                onClick={() => idInputRef.current?.click()}
                                className="border-2 border-dashed border-black/20 hover:border-[#E00D42] hover:bg-[#E00D42]/5 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                            >
                                <Upload className="w-4 h-4 text-black/40" />
                                <span className="text-[11px] text-black/70">Upload Owner ID (JPG, PNG, PDF max 5MB)</span>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between p-2.5 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                <div className="flex items-center gap-2 truncate">
                                    <FileText className="w-4 h-4 text-[#E00D42] shrink-0" />
                                    <span className="font-bold truncate text-[11px]">{idFileName}</span>
                                    <span className="text-[10px] text-black/50 shrink-0">({idFileSize})</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={removeIdFile}
                                    className="p-1 hover:bg-black/10 rounded text-black/60 hover:text-black transition"
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}
                        <InputError message={errors.id_document} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1 flex items-center justify-between">
                            <span>2. Business Permit / DTI Certificate *</span>
                            <span className="text-[10px] text-[#E00D42] font-normal">DTI / SEC / Mayor's Permit</span>
                        </label>
                        <input
                            type="file"
                            ref={permitInputRef}
                            onChange={handlePermitChange}
                            accept="image/jpeg,image/png,image/webp,application/pdf"
                            className="hidden"
                        />
                        {!permitFileName ? (
                            <div 
                                onClick={() => permitInputRef.current?.click()}
                                className="border-2 border-dashed border-black/20 hover:border-[#E00D42] hover:bg-[#E00D42]/5 rounded-lg p-3 text-center cursor-pointer transition flex items-center justify-center gap-2 bg-white"
                            >
                                <Upload className="w-4 h-4 text-black/40" />
                                <span className="text-[11px] text-black/70">Upload DTI / Business Permit (JPG, PNG, PDF max 5MB)</span>
                            </div>
                        ) : (
                            <div className="flex items-center justify-between p-2.5 bg-[#F4F2EC] border border-black/15 rounded-lg text-xs">
                                <div className="flex items-center gap-2 truncate">
                                    <ShieldCheck className="w-4 h-4 text-emerald-600 shrink-0" />
                                    <span className="font-bold truncate text-[11px]">{permitFileName}</span>
                                    <span className="text-[10px] text-black/50 shrink-0">({permitFileSize})</span>
                                </div>
                                <button
                                    type="button"
                                    onClick={removePermitFile}
                                    className="p-1 hover:bg-black/10 rounded text-black/60 hover:text-black transition"
                                >
                                    <X className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        )}
                        <InputError message={errors.business_permit} className="mt-1" />
                    </div>
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password"
                                type="password"
                                name="password"
                                value={data.password}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="••••••••"
                                autoComplete="new-password"
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Confirm Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="password_confirmation"
                                type="password"
                                name="password_confirmation"
                                value={data.password_confirmation}
                                className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                                placeholder="••••••••"
                                autoComplete="new-password"
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                required
                            />
                        </div>
                        <InputError message={errors.password_confirmation} className="mt-1" />
                    </div>
                </div>

                {/* Merchant Studio Benefits Card */}
                <div className="p-3 rounded-lg bg-[#F4F2EC] border border-black/10 text-[10px] text-black/70 space-y-1.5 font-mono">
                    <div className="flex items-center gap-1.5 font-bold text-black text-[11px]">
                        <Sparkles className="w-3.5 h-3.5 text-[#E00D42]" />
                        <span>Merchant Studio Power Suite:</span>
                    </div>
                    <div className="grid grid-cols-2 gap-1 text-[9px] text-black/80">
                        <span className="flex items-center gap-1">✓ Printable Thermal Waybills</span>
                        <span className="flex items-center gap-1">✓ 10% Flat Fair Fee</span>
                        <span className="flex items-center gap-1">✓ Live Courier Fleet</span>
                        <span className="flex items-center gap-1">✓ Admin KYC Protected</span>
                    </div>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-black hover:bg-[#E00D42] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                    >
                        <span>{processing ? 'Submitting Application...' : 'Submit Seller Application'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Switchers */}
                <div className="pt-4 border-t border-black/10 space-y-3 font-sans text-xs">
                    <div className="text-center">
                        <span className="text-black/60">Already have a store account? </span>
                        <Link 
                            href={route('login')} 
                            className="text-black font-bold hover:text-[#E00D42] transition underline underline-offset-2"
                        >
                            Sign In
                        </Link>
                    </div>

                    <div className="p-3 rounded-xl bg-white border border-black/15 flex items-center justify-between gap-3 shadow-2xs">
                        <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-md bg-black/5 flex items-center justify-center text-black">
                                <ShoppingBag className="w-3.5 h-3.5" />
                            </div>
                            <div>
                                <span className="block font-bold text-[11px] text-black">Looking to buy instead?</span>
                                <span className="block text-[9px] text-black/60 font-mono">Regular shopper account</span>
                            </div>
                        </div>

                        <Link
                            href={route('register')}
                            className="px-2.5 py-1.5 bg-black hover:bg-[#E00D42] text-white rounded-md font-mono text-[10px] font-bold uppercase shrink-0 transition"
                        >
                            Buyer Sign Up
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
