import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowRight, Lock, Mail, Store } from 'lucide-react';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function Login({ status, canResetPassword }: Props) {
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
            title="Sign In" 
            subtitle="Access your Buyer, Seller, Courier, Hub, or Admin Dashboard"
            headerBadge="UNIFIED ACCESS // 01"
        >
            <Head title="Sign In — BagooPH" />

            {status && (
                <div className="mb-5 p-3 rounded-xs bg-emerald-50 border border-emerald-300 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-slate-800 uppercase tracking-wider mb-1">
                        Email Address
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
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
                        <span className="text-[11px] text-slate-700 font-mono">Remember session</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>{processing ? 'Authenticating...' : 'Sign In to Account'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Role Registration Gateways */}
                <div className="pt-4 border-t border-slate-200 space-y-3 font-sans text-xs">
                    <div className="text-center font-mono text-[11px]">
                        <span className="text-slate-600">New shopper? </span>
                        <Link 
                            href={route('register')} 
                            className="text-slate-900 font-bold hover:text-[#E00D42] transition underline underline-offset-2"
                        >
                            Create Buyer Account
                        </Link>
                    </div>

                    <div className="p-3 rounded-xl bg-slate-900 text-white flex items-center justify-between gap-3 border border-slate-800">
                        <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-lg bg-white/10 flex items-center justify-center text-[#E00D42]">
                                <Store className="w-3.5 h-3.5" />
                            </div>
                            <div>
                                <span className="block font-bold text-[11px]">Want to sell on Bagoo?</span>
                                <span className="block text-[9px] text-slate-400 font-mono">10% Flat Fee & Waybill Printing</span>
                            </div>
                        </div>

                        <Link
                            href={route('seller.register')}
                            className="px-2.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-mono text-[10px] font-bold uppercase shrink-0 transition"
                        >
                            Open Store
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
