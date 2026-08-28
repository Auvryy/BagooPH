import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { ArrowRight, Lock, Mail, User, Store, ShieldCheck, Check } from 'lucide-react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        role: 'buyer' as const,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout 
            title="Create Buyer Account" 
            subtitle="Shop across 14 departments with live doorstep telemetry"
            headerBadge="BUYER PORTAL // 02"
        >
            <Head title="Buyer Registration — BagooPH" />

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Full Name
                    </label>
                    <div className="relative">
                        <User className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="name"
                            type="text"
                            name="name"
                            value={data.name}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-black/20 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition"
                            placeholder="e.g. Juan Dela Cruz"
                            autoComplete="name"
                            autoFocus
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.name} className="mt-1" />
                </div>

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
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                            Password
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
                            Confirm Password
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

                <div className="p-3 rounded-lg bg-[#F4F2EC] border border-black/10 text-[10px] text-black/70 space-y-1">
                    <div className="flex items-center gap-1.5 font-bold text-black">
                        <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                        <span>Buyer Privileges Included:</span>
                    </div>
                    <p>Cascading PSGC address selector, automated vouchers, verified reviews, and real-time courier tracking telemetry.</p>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>Create Buyer Account</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Switchers */}
                <div className="pt-4 border-t border-black/10 space-y-3 font-sans text-xs">
                    <div className="text-center">
                        <span className="text-black/60">Already have an account? </span>
                        <Link 
                            href={route('login')} 
                            className="text-black font-bold hover:text-[#E00D42] transition underline underline-offset-2"
                        >
                            Sign In
                        </Link>
                    </div>

                    <div className="p-3 rounded-xl bg-black text-white flex items-center justify-between gap-3">
                        <div className="flex items-center gap-2.5">
                            <div className="w-7 h-7 rounded-md bg-white/10 flex items-center justify-center text-[#E00D42]">
                                <Store className="w-3.5 h-3.5" />
                            </div>
                            <div>
                                <span className="block font-bold text-[11px]">Are you a merchant?</span>
                                <span className="block text-[9px] text-white/60 font-mono">Create a Verified Seller Studio</span>
                            </div>
                        </div>

                        <Link
                            href={route('seller.register')}
                            className="px-2.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-md font-mono text-[10px] font-bold uppercase shrink-0 transition"
                        >
                            Register as Seller
                        </Link>
                    </div>
                </div>
            </form>
        </GuestLayout>
    );
}
