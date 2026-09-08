import React, { FormEventHandler, useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { 
    ArrowRight, 
    Lock, 
    Mail, 
    Store, 
    Eye, 
    EyeOff, 
    Zap 
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

const SHOWCASE_IMAGES = [
    {
        id: 'duffel',
        url: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=1200&q=80',
        alt: 'Bagoo Tactical Travel Duffel 45L',
    },
    {
        id: 'headphones',
        url: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=1200&q=80',
        alt: 'Bagoo Studio Wireless ANC Headphones',
    },
    {
        id: 'runners',
        url: 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=1200&q=80',
        alt: 'Bagoo HyperStrides Performance Runners',
    },
    {
        id: 'watch',
        url: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=1200&q=80',
        alt: 'Bagoo Precision Chronograph Watch',
    },
    {
        id: 'backpack',
        url: 'https://images.unsplash.com/photo-1548036328-c9fa89d128fa?auto=format&fit=crop&w=1200&q=80',
        alt: 'Bagoo Minimalist Urban Commuter Pack',
    },
];

export default function Login({ status, canResetPassword }: Props) {
    const [showPassword, setShowPassword] = useState(false);
    const [currentImageIndex, setCurrentImageIndex] = useState(0);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: true as boolean,
    });

    useEffect(() => {
        const savedEmail = localStorage.getItem('bagoo_saved_email');
        if (savedEmail) {
            setData('email', savedEmail);
        }
    }, []);

    // Automatic product image loop crossfade
    useEffect(() => {
        const timer = setInterval(() => {
            setCurrentImageIndex((prev) => (prev + 1) % SHOWCASE_IMAGES.length);
        }, 3500);

        return () => clearInterval(timer);
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (data.remember && data.email) {
            localStorage.setItem('bagoo_saved_email', data.email);
        } else {
            localStorage.removeItem('bagoo_saved_email');
        }

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const fillDemoAccount = () => {
        setData({
            email: 'buyer@bagoo.ph',
            password: 'password',
            remember: true,
        });
    };

    return (
        <GuestLayout maxWidth="5xl" noCard={true}>
            <Head title="Shopper Sign In — BagooPH" />

            <div className="bg-white rounded-2xl border border-black/15 shadow-2xl relative overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[580px]">
                {/* Top Crimson Accent Line */}
                <div className="absolute top-0 left-0 right-0 h-1 bg-[#E00D42] z-30"></div>

                {/* LEFT COLUMN: Login Form */}
                <div className="lg:col-span-7 p-6 sm:p-8 lg:p-10 flex flex-col justify-between">
                    <div>
                        {/* Header Title Section */}
                        <div className="mb-6 pb-4 border-b border-black/10">
                            <span className="inline-block px-2 py-0.5 mb-2 rounded bg-[#E00D42]/10 text-[#E00D42] font-mono text-[9px] font-bold uppercase tracking-widest border border-[#E00D42]/20">
                                BUYER STOREFRONT // 01
                            </span>
                            <h1 className="text-2xl font-black tracking-tight text-black font-sans">
                                Shopper Sign In
                            </h1>
                            <p className="text-xs text-black/60 font-mono mt-1 uppercase">
                                Access your Bagoo Shopping Bag, Order Tracking & Member Perks
                            </p>
                        </div>

                        {status && (
                            <div className="mb-5 p-3 rounded-lg bg-emerald-50 border border-emerald-300 text-xs font-mono font-bold text-emerald-800">
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
                                        type={showPassword ? 'text' : 'password'}
                                        name="password"
                                        value={data.password}
                                        className="w-full pl-9 pr-10 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                        placeholder="••••••••••••"
                                        autoComplete="current-password"
                                        onChange={(e) => setData('password', e.target.value)}
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition"
                                        title={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                                    </button>
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
                                    <span className="text-[11px] text-slate-700 font-mono">Remember email & session</span>
                                </label>
                            </div>

                            <div className="pt-2 space-y-2">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                                >
                                    <span>{processing ? 'Authenticating...' : 'Sign In to Account'}</span>
                                    <ArrowRight className="w-4 h-4" />
                                </button>

                                <button
                                    type="button"
                                    onClick={fillDemoAccount}
                                    className="w-full py-2 px-3 bg-slate-100 hover:bg-slate-200 active:scale-[0.99] text-slate-700 text-[11px] font-mono rounded-lg transition border border-slate-300 flex items-center justify-center gap-1.5 cursor-pointer"
                                >
                                    <Zap className="w-3.5 h-3.5 text-[#E00D42]" />
                                    <span>1-Click Demo Fill (buyer@bagoo.ph)</span>
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Role Gateways & Register Footer */}
                    <div className="pt-5 mt-6 border-t border-slate-200 space-y-3 font-sans text-xs">
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

                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="px-2.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-mono text-[10px] font-bold uppercase shrink-0 transition"
                            >
                                Open Store
                            </a>
                        </div>
                    </div>
                </div>

                {/* RIGHT COLUMN: Pure Product Image Looping Showcase (Zero Overlaid Elements) */}
                <div className="hidden lg:block lg:col-span-5 relative bg-slate-950 overflow-hidden border-l border-black/10">
                    {SHOWCASE_IMAGES.map((item, idx) => (
                        <div
                            key={item.id}
                            className={`absolute inset-0 bg-cover bg-center transition-all duration-1000 ease-in-out transform ${
                                idx === currentImageIndex 
                                    ? 'opacity-100 scale-100' 
                                    : 'opacity-0 scale-105 pointer-events-none'
                            }`}
                            style={{ backgroundImage: `url(${item.url})` }}
                            role="img"
                            aria-label={item.alt}
                        />
                    ))}
                </div>
            </div>
        </GuestLayout>
    );
}
