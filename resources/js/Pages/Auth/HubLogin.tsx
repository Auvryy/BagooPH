import React, { FormEventHandler, useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { 
    ArrowRight, 
    Lock, 
    Mail, 
    Eye, 
    EyeOff 
} from 'lucide-react';
import { getDomainUrl } from '@/utils/domain';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function HubLogin({ status, canResetPassword }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: true as boolean,
    });

    useEffect(() => {
        const savedEmail = localStorage.getItem('bagoo_hub_email');
        if (savedEmail) {
            setData('email', savedEmail);
        }
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (data.remember && data.email) {
            localStorage.setItem('bagoo_hub_email', data.email);
        } else {
            localStorage.removeItem('bagoo_hub_email');
        }

        post('/login', {
            onError: () => reset('password'),
        });
    };

    return (
        <GuestLayout 
            formPosition="left"
            imageSrc="/images/auth/hub_login.jpg?v=20260919"
            imageAlt="BagooPH Logistics Sorting Hub Visual"
            imageBadge="Logistics Hub"
            imageHeadline="Manage Your Sorting Hub"
            imageDescription="Access real-time parcel intake scanning, automated destination dispatching, inter-hub linehaul tracking, and courier delivery management."
            title="Logistics Sign In"
            subtitle="Sign in to your logistics account to manage your hub"
            alternatePortal={{
                label: 'Buyer Marketplace',
                subtext: 'Looking to shop?',
                href: getDomainUrl('buyer', '/login'),
                buttonText: 'Buyer Login →',
            }}
        >
            <Head title="Logistics Partner Sign In — BagooPH" />

            {status && (
                <div className="mb-5 p-3 rounded-lg bg-emerald-50 border border-emerald-300 text-xs font-sans font-bold text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider mb-1 font-sans">
                        Operator / Company Email *
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-10 pr-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 outline-hidden transition text-slate-900 placeholder-slate-400 font-sans"
                            placeholder="hub.operator@bagooph.shop"
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
                        <label className="block text-xs font-semibold text-slate-800 uppercase tracking-wider font-sans">
                            Password *
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-xs text-slate-500 hover:text-indigo-600 transition"
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
                            className="w-full pl-10 pr-10 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 outline-hidden transition text-slate-900 placeholder-slate-400 font-sans"
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
                        <span className="text-xs text-slate-700 font-sans">Keep station signed in</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold text-sm rounded-lg shadow-xs transition flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer uppercase tracking-wider font-sans"
                    >
                        <span>{processing ? 'Connecting Station...' : 'Sign In to Logistics Hub'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                {/* Switcher & Onboarding Links */}
                <div className="mt-6 pt-5 border-t border-slate-200 text-center font-sans">
                    <p className="text-xs text-slate-600">
                        New logistics partner?{' '}
                        <a 
                            href={getDomainUrl('hub', '/register')}
                            className="text-indigo-600 font-semibold hover:underline ml-1"
                        >
                            Register Logistics Company
                        </a>
                    </p>
                </div>
            </form>
            <p className="mt-4 text-center text-xs text-slate-600">Existing COD responsibility? <a href="/cash-handover/sign-in" className="font-semibold text-[#C20836] hover:underline">Open cash handover access</a></p>
        </GuestLayout>
    );
}
