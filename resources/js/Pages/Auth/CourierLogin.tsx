import React, { FormEventHandler, useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowRight, Lock, Mail, Eye, EyeOff } from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function CourierLogin({ status, canResetPassword }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: true as boolean,
    });

    useEffect(() => {
        const savedEmail = localStorage.getItem('bagoo_courier_email');
        if (savedEmail) {
            setData('email', savedEmail);
        }
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (data.remember && data.email) {
            localStorage.setItem('bagoo_courier_email', data.email);
        } else {
            localStorage.removeItem('bagoo_courier_email');
        }

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout 
            formPosition="left"
            imageSrc="/images/auth/courier_login.jpg"
            imageAlt="Bagoo Express Courier Fleet Visual"
            imageBadge="Fleet Dispatch"
            imageHeadline="Courier Navigation & Dispatch"
            imageDescription="Access assigned doorstep pickups, sorting hub drops, and real-time delivery handovers across Metro Manila."
            title="Courier Sign In"
            subtitle="Sign in to your rider account to access the delivery queue"
        >
            <Head title="Courier Dispatch Sign In — BagooPH" />

            {status && (
                <div className="mb-5 p-3 rounded-lg bg-emerald-50 border border-emerald-300 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-mono">
                        Rider Email Address *
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
                            placeholder="rider@domain.com"
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
                        <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider font-mono">
                            Password *
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-xs text-slate-500 hover:text-emerald-600 transition"
                            >
                                Forgot password?
                            </Link>
                        )}
                    </div>
                    <div className="relative">
                        <Lock className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                        <input
                            id="password"
                            type={showPassword ? 'text' : 'password'}
                            name="password"
                            value={data.password}
                            className="w-full pl-10 pr-10 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-emerald-600 focus:ring-1 focus:ring-emerald-600 outline-hidden transition text-slate-900 placeholder-slate-400"
                            placeholder="••••••••••••"
                            autoComplete="current-password"
                            onChange={(e) => setData('password', e.target.value)}
                            required
                        />
                        <button
                            type="button"
                            onClick={() => setShowPassword(!showPassword)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition cursor-pointer"
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
                        <span className="text-xs text-slate-700">Keep rider signed in</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                    >
                        <span>{processing ? 'Connecting Fleet...' : 'Access Dispatch Dashboard'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Switcher & Portal Links */}
                <div className="mt-6 pt-5 border-t border-slate-200 text-center space-y-2 font-sans">
                    <p className="text-xs text-slate-600">
                        Join Bagoo Express Fleet?{' '}
                        <a 
                            href={getDomainUrl('courier', '/register')}
                            className="text-emerald-700 font-semibold hover:underline"
                        >
                            Apply as Driver
                        </a>
                    </p>

                    <p className="text-xs text-slate-500">
                        Looking for marketplace?{' '}
                        <a 
                            href={getDomainUrl('buyer', '/login')} 
                            className="text-slate-800 font-semibold hover:text-emerald-700 hover:underline"
                        >
                            Buyer Login →
                        </a>
                    </p>
                </div>
            </form>
        </GuestLayout>
    );
}
