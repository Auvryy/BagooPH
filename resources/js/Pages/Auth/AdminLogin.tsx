import React, { FormEventHandler, useState, useEffect } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import Checkbox from '@/Components/Checkbox';
import { ArrowRight, Lock, Mail, Shield, Server, Terminal, Eye, EyeOff } from 'lucide-react';

interface Props {
    status?: string;
    canResetPassword?: boolean;
}

export default function AdminLogin({ status, canResetPassword }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: true as boolean,
    });

    useEffect(() => {
        const savedEmail = localStorage.getItem('bagoo_admin_email');
        if (savedEmail) {
            setData('email', savedEmail);
        }
    }, []);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (data.remember && data.email) {
            localStorage.setItem('bagoo_admin_email', data.email);
        } else {
            localStorage.removeItem('bagoo_admin_email');
        }

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <GuestLayout 
            title="Governance Console" 
            subtitle="Platform Infrastructure & Administrative Authorization"
            headerBadge="GOVERNANCE // 00"
        >
            <Head title="Platform Governance Login — BagooPH" />

            {/* Admin Console Indicators */}
            <div className="grid grid-cols-3 gap-2 mb-4 font-mono text-[10px]">
                <div className="p-2 rounded-lg bg-amber-50/50 border border-amber-200 text-center">
                    <Shield className="w-3.5 h-3.5 text-amber-600 mx-auto mb-1" />
                    <span className="font-bold block text-amber-950">KYC Gate</span>
                    <span className="text-amber-700 text-[9px]">Document Audit</span>
                </div>
                <div className="p-2 rounded-lg bg-amber-50/50 border border-amber-200 text-center">
                    <Server className="w-3.5 h-3.5 text-amber-600 mx-auto mb-1" />
                    <span className="font-bold block text-amber-950">Ledger Audit</span>
                    <span className="text-amber-700 text-[9px]">Commission 10%</span>
                </div>
                <div className="p-2 rounded-lg bg-amber-50/50 border border-amber-200 text-center">
                    <Terminal className="w-3.5 h-3.5 text-amber-600 mx-auto mb-1" />
                    <span className="font-bold block text-amber-950">Dispute Arb</span>
                    <span className="text-amber-700 text-[9px]">Escrow Release</span>
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
                        Administrator Email Address
                    </label>
                    <div className="relative">
                        <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value={data.email}
                            className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-amber-600 focus:ring-1 focus:ring-amber-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                            placeholder="admin@domain.com"
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
                            Administrator Security Key / Password
                        </label>
                        {canResetPassword && (
                            <Link
                                href={route('password.request')}
                                className="text-[10px] text-slate-500 hover:text-amber-700 transition"
                            >
                                Reset key?
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
                            className="w-full pl-9 pr-10 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-amber-600 focus:ring-1 focus:ring-amber-600 outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
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
                        <span className="text-[11px] text-slate-700 font-mono">Store admin session</span>
                    </label>
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-amber-600 hover:bg-amber-700 active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                    >
                        <span>{processing ? 'Verifying Key...' : 'Authorize Admin Console'}</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                <div className="pt-4 border-t border-slate-200 text-center font-mono text-[11px]">
                    <span className="text-slate-500">Return to public store? </span>
                    <Link href={route('login')} className="text-slate-900 font-bold hover:text-amber-700 underline">
                        Buyer Login
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
