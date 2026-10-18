import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Clock, ShieldCheck, AlertCircle, RefreshCw, X, ArrowRight, CheckCircle2 } from 'lucide-react';
import { AuthRequestError, AuthRequestScope, requestOtp } from '@/utils/authRequests';

interface OtpModalProps {
    isOpen: boolean;
    email: string;
    purpose?: 'registration' | 'password_reset';
    role?: string;
    onSuccess: (token: string, code: string) => void;
    onClose: () => void;
    autoSendOnOpen?: boolean;
}

export default function OtpModal({
    isOpen,
    email,
    purpose = 'registration',
    role = 'buyer',
    onSuccess,
    onClose,
    autoSendOnOpen = true,
}: OtpModalProps) {
    const [digits, setDigits] = useState<string[]>(['', '', '', '', '', '']);
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [isVerifying, setIsVerifying] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [cooldown, setCooldown] = useState(0);
    const [codeSent, setCodeSent] = useState(false);
    const inputRefs = useRef<(HTMLInputElement | null)[]>([]);
    const scope = useRef(new AuthRequestScope());
    const context = JSON.stringify([isOpen, email, purpose, role]);
    const latestContext = useRef(context);
    latestContext.current = context;
    const canResend = cooldown === 0;
    const current = (request: AbortController) => latestContext.current === context && scope.current.current(request);

    useEffect(() => {
        if (!isOpen || cooldown === 0) return;
        const timer = setInterval(() => setCooldown(value => Math.max(0, value - 1)), 1000);
        return () => clearInterval(timer);
    }, [isOpen, cooldown > 0]);

    useEffect(() => {
        scope.current.cancel();
        setDigits(['', '', '', '', '', '']);
        setError(null);
        setSuccessMsg(null);
        setIsSending(false);
        setIsVerifying(false);
        setCooldown(0);
        setCodeSent(false);
        if (!isOpen) return;
        if (autoSendOnOpen && email) void sendOtpCode();
        const timer = setTimeout(() => inputRefs.current[0]?.focus(), 150);
        return () => {
            clearTimeout(timer);
            scope.current.cancel();
        };
    }, [isOpen, email, purpose, role, autoSendOnOpen]);

    async function sendOtpCode() {
        if (!isOpen || !email) return;
        const request = scope.current.begin();
        if (!request) return;
        setIsSending(true);
        setError(null);
        setSuccessMsg(null);
        setCodeSent(false);
        try {
            const reply = await requestOtp('send', { email, purpose, role }, request.signal);
            if (!current(request)) return;
            setCodeSent(true);
            setSuccessMsg('A verification code was sent to your email.');
            setCooldown(typeof reply.cooldown === 'number' && Number.isFinite(reply.cooldown) ? Math.max(0, Math.ceil(reply.cooldown)) : 60);
        } catch (failure) {
            if (!current(request)) return;
            setError(failure instanceof Error ? failure.message : 'We could not send a code. Please try again.');
            setCooldown(failure instanceof AuthRequestError ? failure.cooldown : 0);
        } finally {
            if (current(request)) setIsSending(false);
            scope.current.finish(request);
        }
    }

    const handleDigitChange = (index: number, value: string) => {
        if (value !== '' && !/^[0-9]$/.test(value)) {
            setError('Use only the six digits from your verification email.');
            return;
        }
        const next = [...digits];
        next[index] = value;
        setDigits(next);
        setError(null);
        if (value && index < 5) inputRefs.current[index + 1]?.focus();
        if (value && index === 5 && next.every(Boolean)) void submitVerification(next.join(''));
    };

    const handleKeyDown = (index: number, event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Backspace') {
            const next = [...digits];
            const target = !digits[index] && index > 0 ? index - 1 : index;
            next[target] = '';
            setDigits(next);
            inputRefs.current[target]?.focus();
        } else if (event.key === 'ArrowLeft' && index > 0) inputRefs.current[index - 1]?.focus();
        else if (event.key === 'ArrowRight' && index < 5) inputRefs.current[index + 1]?.focus();
    };

    const handlePaste = (event: React.ClipboardEvent) => {
        event.preventDefault();
        const value = event.clipboardData.getData('text');
        if (!/^[0-9]{1,6}$/.test(value)) {
            setError('Paste only the six digits from your verification email.');
            return;
        }
        setDigits(Array.from({ length: 6 }, (_, index) => value[index] ?? ''));
        setError(null);
        inputRefs.current[Math.min(value.length, 5)]?.focus();
        if (value.length === 6) void submitVerification(value);
    };

    async function submitVerification(codeOverride?: string) {
        const fullCode = codeOverride ?? digits.join('');
        if (!/^[0-9]{6}$/.test(fullCode)) {
            setError('Please enter all six digits of your verification code.');
            return;
        }
        if (!isOpen) return;
        const request = scope.current.begin();
        if (!request) return;
        setIsVerifying(true);
        setError(null);
        try {
            const reply = await requestOtp('verify', { email, code: fullCode, purpose, role }, request.signal);
            if (current(request)) onSuccess(reply.token as string, fullCode);
        } catch (failure) {
            if (current(request)) setError(failure instanceof Error ? failure.message : 'We could not verify the code. Please try again.');
        } finally {
            if (current(request)) setIsVerifying(false);
            scope.current.finish(request);
        }
    }

    const handleClose = () => {
        scope.current.cancel();
        onClose();
    };

    if (!isOpen || typeof document === 'undefined') return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm animate-in fade-in duration-200">
            <div role="dialog" aria-modal="true" aria-labelledby="otp-title" onKeyDown={event => { if (event.key === 'Escape') handleClose(); }} className="bg-white border border-slate-200 rounded-3xl max-w-md w-full p-6 sm:p-8 space-y-6 shadow-2xl relative font-sans max-h-[92vh] overflow-y-auto">
                {/* Close Button */}
                <button
                    type="button"
                    onClick={handleClose}
                    className="absolute right-5 top-5 p-1 rounded-xl text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition cursor-pointer"
                    title="Close"
                >
                    <X className="w-5 h-5" />
                </button>

                {/* Header Badge & Title */}
                <div className="text-center space-y-2">
                    <div className="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-rose-50 border border-rose-200 text-[#E00D42] mb-1 shadow-2xs">
                        <ShieldCheck className="w-6 h-6" />
                    </div>
                    <div className="inline-block px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-sans font-bold uppercase tracking-wider block mx-auto">
                        Bagoo Identity Verification
                    </div>
                    <h2 id="otp-title" className="text-xl font-black text-slate-900 tracking-tight">
                        Enter 6-Digit Code
                    </h2>
                    <p className="text-xs text-slate-500 max-w-xs mx-auto leading-relaxed">
                        {codeSent ? 'A single-use verification code was sent to' : isSending ? 'Sending a verification code to' : 'Verify your email address'}{' '}
                        <strong className="font-sans text-slate-900 font-semibold">{email}</strong>
                    </p>
                </div>

                {/* Status / Error Alerts */}
                {error && (
                    <div role="alert" className="p-3 bg-rose-50 border border-rose-200 rounded-2xl text-xs font-sans text-rose-800 flex items-start gap-2.5 animate-shake">
                        <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                        <span className="leading-snug">{error}</span>
                    </div>
                )}

                {successMsg && (
                    <div role="status" className="p-3 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs font-sans text-emerald-800 flex items-start gap-2.5">
                        <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                        <span className="leading-snug">{successMsg}</span>
                    </div>
                )}

                {/* 6 Digit Input Grid */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between gap-2 sm:gap-2.5" onPaste={handlePaste}>
                        {digits.map((digit, idx) => (
                            <input
                                key={idx}
                                aria-label={"Verification digit " + (idx + 1)}
                                aria-invalid={Boolean(error)}
                                autoComplete={idx === 0 ? 'one-time-code' : 'off'}
                                ref={(el) => (inputRefs.current[idx] = el)}
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                maxLength={1}
                                value={digit}
                                onChange={(e) => handleDigitChange(idx, e.target.value)}
                                onKeyDown={(e) => handleKeyDown(idx, e)}
                                disabled={isVerifying || isSending}
                                className={`w-11 sm:w-13 h-14 sm:h-16 text-center text-xl sm:text-2xl font-black font-sans rounded-2xl border transition focus:outline-none focus:ring-2 ${
                                    digit
                                        ? 'bg-slate-50 border-[#E00D42] text-slate-900 focus:ring-[#E00D42]/20'
                                        : 'bg-white border-slate-300 text-slate-900 hover:border-slate-400 focus:border-[#E00D42] focus:ring-[#E00D42]/20'
                                }`}
                            />
                        ))}
                    </div>

                    <p className="text-[11px] text-slate-400 text-center font-sans">
                        Valid for 10 minutes • Do not share this code
                    </p>
                </div>

                {/* Verify Button */}
                <div className="space-y-3 pt-1">
                    <button
                        type="button"
                        onClick={() => submitVerification()}
                        disabled={isVerifying || isSending || digits.some((d) => d === '')}
                        className="w-full py-3.5 px-4 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-2xl font-sans text-xs font-bold uppercase tracking-wider shadow-sm flex items-center justify-center gap-2 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        {isVerifying ? (
                            <>
                                <RefreshCw className="w-4 h-4 animate-spin" />
                                <span>Verifying Code...</span>
                            </>
                        ) : (
                            <>
                                <span>Confirm & Verify Email</span>
                                <ArrowRight className="w-4 h-4" />
                            </>
                        )}
                    </button>

                    {/* Resend Cooldown Section */}
                    <div className="text-center">
                        {canResend ? (
                            <button
                                type="button"
                                onClick={sendOtpCode}
                                disabled={isSending || isVerifying}
                                className="text-xs font-sans font-bold text-slate-700 hover:text-[#E00D42] underline underline-offset-4 transition cursor-pointer"
                            >
                                {isSending ? 'Sending code...' : codeSent ? 'Resend code' : 'Send code'}
                            </button>
                        ) : (
                            <p className="text-xs font-sans text-slate-400 flex items-center justify-center gap-1.5">
                                <Clock className="w-3.5 h-3.5" />
                                <span>Resend code in {cooldown}s</span>
                            </p>
                        )}
                    </div>
                </div>
            </div>
        </div>,
        document.body
    );
}
