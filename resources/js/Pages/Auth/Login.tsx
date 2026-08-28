import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowRight, Lock, Mail, Store, ShoppingBag } from 'lucide-react';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function Login({ status, canResetPassword }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const fillDemo = (email: string) => {
        setData({
            email,
            password: 'password',
            remember: true,
        });
    };

    return (
        <GuestLayout 
            title="Sign In" 
            subtitle="Access your Buyer, Seller, or Courier Dashboard"
            headerBadge="UNIFIED ACCESS // 01"
        >
            <Head title="Sign In — BagooPH" />

            {status && (
                <div className="mb-5 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            {/* Quick Demo Switcher */}
            <div className="mb-6 p-3.5 rounded-xl bg-[#F4F2EC] border border-black/10 space-y-2">
                <div className="flex items-center justify-between text-[10px] font-mono text-black/50 font-bold uppercase tracking-wider">
                    <span>DEMO CREDENTIALS</span>
                    <span>AUTO-ROUTING</span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5 font-mono text-xs">
                    <button
                        type="button"
                        onClick={() => fillDemo('buyer@bagoo.test')}
                        className="px-2.5 py-1.5 text-center bg-white hover:bg-[#E00D42] hover:text-white border border-black/10 rounded-lg transition text-[10px] font-bold shadow-2xs"
                    >
                        Buyer
                    </button>
                    <button
                        type="button"
                        onClick={() => fillDemo('seller@bagoo.test')}
                        className="px-2.5 py-1.5 text-center bg-white hover:bg-black hover:text-white border border-black/10 rounded-lg transition text-[10px] font-bold shadow-2xs"
                    >
                        Seller
                    </button>
                    <button
                        type="button"
                        onClick={() => fillDemo('courier@bagoo.test')}
                        className="px-2.5 py-1.5 text-center bg-white hover:bg-black hover:text-white border border-black/10 rounded-lg transition text-[10px] font-bold shadow-2xs"
                    >
                        Courier
                    </button>
                    <button
                        type="button"
                        onClick={() => fillDemo('admin@bagoo.test')}
                        className="px-2.5 py-1.5 text-center bg-white hover:bg-rose-900 hover:text-white border border-black/10 rounded-lg transition text-[10px] font-bold shadow-2xs"
                    >
                        Admin
                    </button>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Email Address
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="name@domain.com"
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
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider">
                            Password
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-[10px] text-black/50 hover:text-[#E00D42] transition"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>
                    <div className="relative">
                        <Lock className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="password"
                            type="password"
                            name="password"
                            value={data.password}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
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
                        <span className="text-[11px] text-black/70 font-mono">Remember session</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>Sign In to Account</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Role Registration Gateways */}
                <div className="pt-4 border-t border-black/10 space-y-3 font-sans text-xs">
                    <div className="text-center">
                        <span className="text-black/60">New shopper? </span>
                        <Link 
                            href={route('register')} 
                            className="text-black font-bold hover:text-[#E00D42] transition underline underline-offset-2"
                        >
                            Create Buyer Account
                        </Link>
                    </div>

                    <div className="p-3 rounded-xl bg-black text-white flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-md bg-white/10 flex items-center justify-center text-[#E00D42]">
                                <Store className="w-3.5 h-3.5" />
                            </div>
                            <div>
                                <span className="block font-bold text-[11px]">Want to sell on Bagoo?</span>
                                <span className="block text-[9px] text-white/60 font-mono">10% Flat Fee & Waybill Printing</span>
                            </div>
                        </div>

                        <Link
                            href={route('seller.register')}
                            className="px-2.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-md font-mono text-[10px] font-bold uppercase shrink-0 transition"
                        >
                            Open Store
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
