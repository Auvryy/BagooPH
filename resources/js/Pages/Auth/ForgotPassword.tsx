import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import { ArrowRight, Mail } from 'lucide-react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout 
            title="Reset Password" 
            subtitle="We will email you a secure link to reset your account password"
            headerBadge="RECOVERY // 04"
        >
            <Head title="Forgot Password — BagooPH" />

            {status && (
                <div className="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <label className="block text-[11px] font-bold text-black uppercase tracking-wider mb-1">
                        Registered Email Address
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
                            autoFocus
                            onChange={(e) => setData('email', e.target.value)}
                            required
                        />
                    </div>
                    <InputError message={errors.email} className="mt-1" />
                </div>

                <div className="pt-2">
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>Send Password Reset Link</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                <div className="text-center pt-2 text-xs font-sans text-black/60">
                    Remember your credentials?{' '}
                    <Link href={route('login')} className="text-black font-bold hover:text-[#E00D42] transition underline underline-offset-2">
                        Back to Sign In
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
