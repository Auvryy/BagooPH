import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowRight, Lock, Mail, Store, ShieldCheck, FileText, DollarSign } from 'lucide-react';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function SellerLogin({ status, canResetPassword }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: true as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout 
            title="Seller Centre" 
            subtitle="Merchant Command & Inventory Fulfillment Cockpit"
            headerBadge="MERCHANT PORTAL // 02"
        >
            <Head title="Seller Centre Sign In — BagooPH" />

            {/* Merchant Highlights Strip */}
            <div className="grid grid-cols-3 gap-2 mb-5 font-mono text-[10px]">
                <div className="p-2 rounded-lg bg-slate-100 border border-slate-200 text-center">
                    <DollarSign className="w-3.5 h-3.5 text-[#E00D42] mx-auto mb-1" />
                    <span className="font-bold block text-slate-800">10% Flat Fee</span>
                    <span className="text-slate-500 text-[9px]">Zero Hidden Surcharges</span>
                </div>
                <div className="p-2 rounded-lg bg-slate-100 border border-slate-200 text-center">
                    <FileText className="w-3.5 h-3.5 text-indigo-600 mx-auto mb-1" />
                    <span className="font-bold block text-slate-800">Thermal Labels</span>
                    <span className="text-slate-500 text-[9px]">1-Click Barcode Waybills</span>
                </div>
                <div className="p-2 rounded-lg bg-slate-100 border border-slate-200 text-center">
                    <ShieldCheck className="w-3.5 h-3.5 text-emerald-600 mx-auto mb-1" />
                    <span className="font-bold block text-slate-800">Verified Badge</span>
                    <span className="text-slate-500 text-[9px]">Fast KYC Activation</span>
                </div>
            </div>

            {status && (
                <div className="mb-5 p-3 rounded-lg bg-emerald-50 border border-emerald-300 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                        Merchant Email Address
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                            placeholder="merchant@domain.com"
                            autoComplete="username"
                            autoFocus
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div>
                    <div className="flex items-center justify-between mb-1">
                        <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider">
                            Password
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-[10px] text-slate-500 hover:text-[#E00D42] transition"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>
                    <div className="relative">
                        <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                            placeholder="••••••••••••"
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.password} className="mt-1" />
                </div>

                <div className="flex items-center justify-between text-xs pt-1">
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                        <Checkbox
                            name="remember"
                            checked={data.remember}
                            onChange={(e) => setData('remember', (e.target.checked || false) as false)}
                        />
                        <span className="text-[11px] text-slate-700 font-mono">Keep merchant signed in</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-slate-900 hover:bg-black active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>{processing ? 'Connecting Cockpit...' : 'Enter Seller Cockpit'}</span>
                        <ArrowRight className="w-4 h-4 text-[#E00D42]" />
                    </button>
                </div>

                {/* Onboarding Link */}
                <div className="pt-4 border-t border-slate-200 space-y-3 font-sans text-xs">
                    <div className="p-3 rounded-lg bg-[#ECEAE5] border border-black/10 flex items-center justify-between gap-3">
                        <div>
                            <span className="block font-bold text-slate-900 text-xs">Not yet a registered seller?</span>
                            <span className="block text-[10px] text-slate-600 font-mono">Submit DTI/Mayor's permit for KYC review.</span>
                        </div>
                        <Link
                            href={route('seller.register')}
                            className="px-3 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-mono text-[10px] font-bold uppercase rounded-lg shrink-0 transition"
                        >
                            Open Shop
                        </Link>
                    </div>

                    <div className="text-center font-mono text-[11px]">
                        <span className="text-slate-500">Shopper looking for marketplace? </span>
                        <Link href={route('login')} className="text-slate-900 font-bold hover:text-[#E00D42] underline">
                            Go to Buyer Login
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
