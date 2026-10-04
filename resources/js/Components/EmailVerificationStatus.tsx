import { useForm } from '@inertiajs/react';
import { BadgeCheck, MailWarning, Send } from 'lucide-react';

interface Props {
    email: string;
    verifiedAt?: string | null;
    className?: string;
    comfortable?: boolean;
}

export default function EmailVerificationStatus({
    email,
    verifiedAt,
    className = '',
    comfortable = false,
}: Props) {
    const verificationForm = useForm({ email });

    if (verifiedAt) {
        return (
            <div className={`flex items-start gap-3 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 ${comfortable ? '!rounded-[18px] [&_p]:text-sm' : ''} ${className}`}>
                <BadgeCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-700" />
                <div>
                    <p className="text-xs font-bold text-emerald-900">Email verified</p>
                    <p className="mt-0.5 break-all text-xs text-emerald-800">{email}</p>
                </div>
            </div>
        );
    }

    const sendVerificationEmail = () => {
        verificationForm.post('/email/verification-notification', {
            preserveScroll: true,
        });
    };

    return (
        <div className={`rounded-md border border-amber-300 bg-amber-50 px-4 py-3 ${comfortable ? '!rounded-[18px] [&_p]:text-sm [&_button]:min-h-12 [&_button]:rounded-[14px] [&_button]:text-sm' : ''} ${className}`}>
            <div className="flex items-start gap-3">
                <MailWarning className="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-bold text-amber-950">Email verification required</p>
                    <p className="mt-1 text-xs leading-5 text-amber-900">
                        Verify <span className="break-all font-semibold">{email}</span> before changing your password.
                    </p>
                    <button
                        type="button"
                        onClick={sendVerificationEmail}
                        disabled={verificationForm.processing}
                        className="mt-3 inline-flex items-center gap-1.5 rounded-sm bg-slate-900 px-3 py-2 text-xs font-bold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Send className="h-3.5 w-3.5" />
                        {verificationForm.processing ? 'Sending...' : 'Send verification email'}
                    </button>
                    {verificationForm.recentlySuccessful && (
                        <p className="mt-2 text-xs font-semibold text-emerald-700">
                            Verification email sent. Check your inbox.
                        </p>
                    )}
                    {verificationForm.errors.email && (
                        <p className="mt-2 text-xs font-semibold text-[#E00D42]">
                            {verificationForm.errors.email}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
