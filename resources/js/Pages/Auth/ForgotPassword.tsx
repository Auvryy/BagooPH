import React, { FormEventHandler, useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import InputError from '@/Components/InputError';
import OtpModal from '@/Components/OtpModal';
import { ArrowRight, ArrowLeft, Mail, Lock, Eye, EyeOff, ShieldCheck, Check } from 'lucide-react';

export default function ForgotPassword({ status }: { status?: string }) {
    const [step, setStep] = useState<'email' | 'new_password'>('email');
    const [showOtpModal, setShowOtpModal] = useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    const [emailError, setEmailError] = useState<string | null>(null);

    const emailForm = useForm({
        email: '',
    });

    const resetForm = useForm({
        email: '',
        token: '',
        password: '',
        password_confirmation: '',
    });

    const handleSendOtp = (e: React.FormEvent) => {
        e.preventDefault();
        setEmailError(null);

        if (!emailForm.data.email.trim()) {
            setEmailError('Please enter your registered email address.');
            return;
        }

        if (!/\S+@\S+\.\S+/.test(emailForm.data.email)) {
            setEmailError('Please enter a valid email address.');
            return;
        }

        setShowOtpModal(true);
    };

    const handleOtpSuccess = (token: string) => {
        setShowOtpModal(false);
        resetForm.setData({
            email: emailForm.data.email,
            token: token,
            password: '',
            password_confirmation: '',
        });
        setStep('new_password');
    };

    const handleSendLink: FormEventHandler = (e) => {
        e.preventDefault();
        emailForm.post(route('password.email'));
    };

    const handleResetPassword: FormEventHandler = (e) => {
        e.preventDefault();
        resetForm.post(route('password.reset.otp'), {
            onFinish: () => resetForm.reset('password', 'password_confirmation'),
        });
    };

    return (
        <GuestLayout 
            title={step === 'email' ? 'Reset Password' : 'Set New Password'}
            subtitle={step === 'email' 
                ? 'Verify your identity with a 6-digit code sent to your email' 
                : 'Choose a strong, secure password for your account'}
            headerBadge="RECOVERY // 04"
        >
            <Head title="Forgot Password — BagooPH" />

            {status && (
                <div className="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-mono font-bold text-emerald-800">
                    {status}
                </div>
            )}

            {step === 'email' ? (
                <div className="space-y-4 font-mono">
                    <form onSubmit={handleSendOtp} className="space-y-4">
                        <div>
                            <label className="block text-[11px] font-bold text-slate-900 uppercase tracking-wider mb-1">
                                Registered Email Address
                            </label>
                            <div className="relative">
                                <Mail className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value={emailForm.data.email}
                                    className="w-full pl-9 pr-3 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                    placeholder="name@domain.com"
                                    autoFocus
                                    onChange={(e) => {
                                        emailForm.setData('email', e.target.value);
                                        setEmailError(null);
                                    }}
                                    required
                                />
                            </div>
                            {(emailError || emailForm.errors.email) && (
                                <InputError message={emailError || emailForm.errors.email} className="mt-1" />
                            )}
                        </div>

                        <div className="pt-2">
                            <button
                                type="submit"
                                disabled={emailForm.processing}
                                className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                            >
                                <ShieldCheck className="w-4 h-4" />
                                <span>Verify with 6-Digit Code</span>
                                <ArrowRight className="w-4 h-4" />
                            </button>
                        </div>
                    </form>

                    <div className="relative flex py-2 items-center">
                        <div className="grow border-t border-slate-200"></div>
                        <span className="shrink mx-3 text-[10px] text-slate-400 uppercase tracking-wider">or</span>
                        <div className="grow border-t border-slate-200"></div>
                    </div>

                    <button
                        type="button"
                        onClick={handleSendLink}
                        disabled={emailForm.processing || !emailForm.data.email}
                        className="w-full py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-lg transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-40 cursor-pointer"
                    >
                        <span>Send Password Reset Link Instead</span>
                    </button>

                    <div className="text-center pt-2 text-xs font-sans text-slate-600">
                        Remember your credentials?{' '}
                        <Link href={route('login')} className="text-slate-900 font-bold hover:text-[#E00D42] transition underline underline-offset-2">
                            Back to Sign In
                        </Link>
                    </div>

                    <OtpModal
                        isOpen={showOtpModal}
                        email={emailForm.data.email}
                        purpose="password_reset"
                        onSuccess={handleOtpSuccess}
                        onClose={() => setShowOtpModal(false)}
                    />
                </div>
            ) : (
                <form onSubmit={handleResetPassword} className="space-y-4 font-mono">
                    <div className="p-3 bg-slate-50 border border-slate-200 rounded-lg flex items-center justify-between">
                        <div>
                            <span className="text-[10px] uppercase tracking-wider text-slate-500 block">Verified Account</span>
                            <span className="text-xs font-bold text-slate-900">{resetForm.data.email}</span>
                        </div>
                        <div className="flex items-center gap-1 text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded text-[10px] font-bold">
                            <Check className="w-3.5 h-3.5" />
                            <span>OTP Verified</span>
                        </div>
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-slate-900 uppercase tracking-wider mb-1">
                            New Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="new_password"
                                type={showPassword ? 'text' : 'password'}
                                name="password"
                                value={resetForm.data.password}
                                className="w-full pl-9 pr-9 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                placeholder="••••••••••••"
                                autoFocus
                                onChange={(e) => resetForm.setData('password', e.target.value)}
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword(!showPassword)}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition cursor-pointer"
                            >
                                {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                            </button>
                        </div>
                        <InputError message={resetForm.errors.password} className="mt-1" />
                    </div>

                    <div>
                        <label className="block text-[11px] font-bold text-slate-900 uppercase tracking-wider mb-1">
                            Confirm New Password *
                        </label>
                        <div className="relative">
                            <Lock className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                id="confirm_password"
                                type={showConfirmPassword ? 'text' : 'password'}
                                name="password_confirmation"
                                value={resetForm.data.password_confirmation}
                                className="w-full pl-9 pr-9 py-2.5 text-xs bg-white border border-slate-300 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden font-mono transition text-slate-900 placeholder-slate-400"
                                placeholder="••••••••••••"
                                onChange={(e) => resetForm.setData('password_confirmation', e.target.value)}
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 transition cursor-pointer"
                            >
                                {showConfirmPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                            </button>
                        </div>
                        <InputError message={resetForm.errors.password_confirmation} className="mt-1" />
                    </div>

                    <div className="flex items-center gap-3 pt-2">
                        <button
                            type="button"
                            onClick={() => setStep('email')}
                            className="w-1/3 py-3 bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs rounded-lg transition flex items-center justify-center gap-1.5 cursor-pointer"
                        >
                            <ArrowLeft className="w-4 h-4" />
                            <span>Back</span>
                        </button>
                        <button
                            type="submit"
                            disabled={resetForm.processing}
                            className="w-2/3 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-xs transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50 cursor-pointer"
                        >
                            <span>{resetForm.processing ? 'Saving...' : 'Set Password'}</span>
                            <ArrowRight className="w-4 h-4" />
                        </button>
                    </div>
                </form>
            )}
        </GuestLayout>
    );
}
