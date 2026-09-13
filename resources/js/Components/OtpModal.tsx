import React, { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import { Mail, Clock, ShieldCheck, AlertCircle, RefreshCw, X, ArrowRight, CheckCircle2 } from 'lucide-react';

interface OtpModalProps {
    isOpen: boolean;
    email: string;
    purpose?: 'registration' | 'password_reset';
    onSuccess: (token: string, code: string) => void;
    onClose: () => void;
    autoSendOnOpen?: boolean;
}

export default function OtpModal({
    isOpen,
    email,
    purpose = 'registration',
    onSuccess,
    onClose,
    autoSendOnOpen = true,
}: OtpModalProps) {
    const [digits, setDigits] = useState<string[]>(['', '', '', '', '', '']);
    const [error, setError] = useState<string | null>(null);
    const [successMsg, setSuccessMsg] = useState<string | null>(null);
    const [isVerifying, setIsVerifying] = useState(false);
    const [isSending, setIsSending] = useState(false);
    const [cooldown, setCooldown] = useState(60);
    const [canResend, setCanResend] = useState(false);

    const inputRefs = useRef<(HTMLInputElement | null)[]>([]);

    // Countdown interval
    useEffect(() => {
        let timer: NodeJS.Timeout;
        if (isOpen && cooldown > 0) {
            timer = setInterval(() => {
                setCooldown((prev) => {
                    if (prev <= 1) {
                        setCanResend(true);
                        return 0;
                    }
                    return prev - 1;
                });
            }, 1000);
        }
        return () => {
            if (timer) clearInterval(timer);
        };
    }, [isOpen, cooldown]);

    // Send initial OTP when modal opens if autoSendOnOpen is true
    useEffect(() => {
        if (isOpen) {
            setDigits(['', '', '', '', '', '']);
            setError(null);
            setSuccessMsg(null);
            setCooldown(60);
            setCanResend(false);

            if (autoSendOnOpen && email) {
                sendOtpCode();
            }

            // Focus first input box
            setTimeout(() => {
                inputRefs.current[0]?.focus();
            }, 150);
        }
    }, [isOpen, email]);

    const sendOtpCode = async () => {
        if (!email) return;

        setIsSending(true);
        setError(null);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const response = await fetch('/api/otp/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({ email, purpose }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.message || 'Failed to dispatch verification code.');
                if (data.cooldown) {
                    setCooldown(data.cooldown);
                    setCanResend(false);
                }
            } else {
                setSuccessMsg('A new verification code has been dispatched to your email.');
                setCooldown(data.cooldown || 60);
                setCanResend(false);
                setTimeout(() => setSuccessMsg(null), 4000);
            }
        } catch (err) {
            setError('Network error while connecting to the email service.');
        } finally {
            setIsSending(false);
        }
    };

    const handleDigitChange = (index: number, val: string) => {
        // Only accept numbers
        const cleanVal = val.replace(/\D/g, '');
        if (!cleanVal && val !== '') return;

        const newDigits = [...digits];
        newDigits[index] = cleanVal.slice(-1); // keep last typed digit
        setDigits(newDigits);
        setError(null);

        // Auto-advance to next box if digit was typed
        if (cleanVal && index < 5) {
            inputRefs.current[index + 1]?.focus();
        }

        // Auto-submit if all 6 filled
        if (cleanVal && index === 5 && newDigits.every((d) => d !== '')) {
            submitVerification(newDigits.join(''));
        }
    };

    const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace') {
            if (!digits[index] && index > 0) {
                // Current box is already empty, move to previous box and clear it
                const newDigits = [...digits];
                newDigits[index - 1] = '';
                setDigits(newDigits);
                inputRefs.current[index - 1]?.focus();
            } else {
                const newDigits = [...digits];
                newDigits[index] = '';
                setDigits(newDigits);
            }
        } else if (e.key === 'ArrowLeft' && index > 0) {
            inputRefs.current[index - 1]?.focus();
        } else if (e.key === 'ArrowRight' && index < 5) {
            inputRefs.current[index + 1]?.focus();
        }
    };

    const handlePaste = (e: React.ClipboardEvent) => {
        e.preventDefault();
        const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
        if (!pasted) return;

        const newDigits = [...digits];
        for (let i = 0; i < 6; i++) {
            newDigits[i] = pasted[i] || '';
        }
        setDigits(newDigits);

        const lastFilledIndex = Math.min(pasted.length, 5);
        inputRefs.current[lastFilledIndex]?.focus();

        if (pasted.length === 6) {
            submitVerification(pasted);
        }
    };

    const submitVerification = async (codeOverride?: string) => {
        const fullCode = codeOverride || digits.join('');
        if (fullCode.length !== 6) {
            setError('Please enter all 6 digits of your verification code.');
            return;
        }

        setIsVerifying(true);
        setError(null);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const response = await fetch('/api/otp/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    email,
                    code: fullCode,
                    purpose,
                }),
            });

            const data = await response.json();

            if (!response.ok) {
                setError(data.message || 'Invalid or expired verification code.');
            } else {
                onSuccess(data.token, fullCode);
            }
        } catch (err) {
            setError('Network error while verifying the code. Please try again.');
        } finally {
            setIsVerifying(false);
        }
    };

    if (!isOpen || typeof document === 'undefined') return null;

    return createPortal(
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm animate-in fade-in duration-200">
            <div className="bg-white border border-slate-200 rounded-3xl max-w-md w-full p-6 sm:p-8 space-y-6 shadow-2xl relative font-sans">
                {/* Close Button */}
                <button
                    type="button"
                    onClick={onClose}
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
                    <div className="inline-block px-2.5 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[10px] font-mono font-bold uppercase tracking-wider block mx-auto">
                        Bagoo Identity Verification
                    </div>
                    <h2 className="text-xl font-black text-slate-900 tracking-tight">
                        Enter 6-Digit Code
                    </h2>
                    <p className="text-xs text-slate-500 max-w-xs mx-auto leading-relaxed">
                        We sent a single-use verification code to{' '}
                        <strong className="font-mono text-slate-900 font-semibold">{email}</strong>
                    </p>
                </div>

                {/* Status / Error Alerts */}
                {error && (
                    <div className="p-3 bg-rose-50 border border-rose-200 rounded-2xl text-xs font-mono text-rose-800 flex items-start gap-2.5 animate-shake">
                        <AlertCircle className="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                        <span className="leading-snug">{error}</span>
                    </div>
                )}

                {successMsg && (
                    <div className="p-3 bg-emerald-50 border border-emerald-200 rounded-2xl text-xs font-mono text-emerald-800 flex items-start gap-2.5">
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
                                ref={(el) => (inputRefs.current[idx] = el)}
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                maxLength={1}
                                value={digit}
                                onChange={(e) => handleDigitChange(idx, e.target.value)}
                                onKeyDown={(e) => handleKeyDown(idx, e)}
                                disabled={isVerifying}
                                className={`w-11 sm:w-13 h-14 sm:h-16 text-center text-xl sm:text-2xl font-black font-mono rounded-2xl border transition focus:outline-none focus:ring-2 ${
                                    digit
                                        ? 'bg-slate-50 border-[#E00D42] text-slate-900 focus:ring-[#E00D42]/20'
                                        : 'bg-white border-slate-300 text-slate-900 hover:border-slate-400 focus:border-[#E00D42] focus:ring-[#E00D42]/20'
                                }`}
                            />
                        ))}
                    </div>

                    <p className="text-[11px] text-slate-400 text-center font-mono">
                        Valid for 10 minutes • Do not share this code
                    </p>
                </div>

                {/* Verify Button */}
                <div className="space-y-3 pt-1">
                    <button
                        type="button"
                        onClick={() => submitVerification()}
                        disabled={isVerifying || digits.some((d) => d === '')}
                        className="w-full py-3.5 px-4 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white rounded-2xl font-mono text-xs font-bold uppercase tracking-wider shadow-sm flex items-center justify-center gap-2 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
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
                                disabled={isSending}
                                className="text-xs font-mono font-bold text-slate-700 hover:text-[#E00D42] underline underline-offset-4 transition cursor-pointer"
                            >
                                {isSending ? 'Sending new code...' : 'Resend Verification Code'}
                            </button>
                        ) : (
                            <p className="text-xs font-mono text-slate-400 flex items-center justify-center gap-1.5">
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
