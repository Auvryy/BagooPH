import React, { FormEventHandler } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import { ArrowRight, MailCheck } from 'lucide-react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout 
            title="Verify Email" 
            subtitle="Please verify your email address to complete your account setup"
            headerBadge="VERIFICATION // 06"
        >
            <Head title="Email Verification — BagooPH" />

            <div className="mb-4 text-xs font-mono text-black/70 leading-relaxed uppercase">
                THANKS FOR SIGNING UP! BEFORE GETTING STARTED, PLEASE VERIFY YOUR EMAIL ADDRESS BY CLICKING THE LINK WE JUST EMAILED TO YOU.
            </div>

            {status === 'verification-link-sent' && (
                <div className="mb-4 p-3 rounded-lg bg-emerald-50 border border-emerald-200 text-xs font-mono font-bold text-emerald-800">
                    A new verification link has been sent to the email address you provided during registration.
                </div>
            )}

            <form onSubmit={submit} className="space-y-4 font-mono">
                <div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider flex items-center justify-center gap-2 disabled:opacity-50"
                    >
                        <span>Resend Verification Email</span>
                        <ArrowRight className="w-4 h-4" />
                    </button>
                </div>

                <div className="text-center pt-2 text-xs font-sans">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="text-black/60 hover:text-[#E00D42] transition underline underline-offset-2"
                    >
                        Log Out
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
