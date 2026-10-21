import { Head, Link, router, usePage } from '@inertiajs/react';
import GuestLayout from '@/Layouts/GuestLayout';
import NotificationLink from '@/Components/NotificationLink';
import AccountReviewRequestForm from '@/Components/AccountReviewRequestForm';
import { reviewStatusLabels, reviewActionLabels, type ReviewRequest, type ReviewSource } from '@/types/accountReviews';

type Status = {
    title: string; message: string;
    account: { id: number; name: string; email: string; role: string; status: string; kyc_status: string; email_verified_at: string | null };
    sources: ReviewSource[]; requests: ReviewRequest[];
    can_correct_application: boolean; can_correct_identity: boolean; can_open_portal: boolean;
    email_update: { pending: boolean; attempts: number } | null;
};

export default function AccountStatus({ status }: { status: Status }) {
    const flash = usePage().props.flash as { success?: string; error?: string; info?: string } | undefined;
    const role = status.account.role === 'courier' ? 'rider' : status.account.role;
    return <GuestLayout title={status.title} subtitle={status.message} showMarketplaceLink={status.account.role === 'buyer'}>
        <Head title="Account status — BagooPH" />
        <div className="space-y-5 font-sans">
            {flash?.success && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800">{flash.success}</p>}
            <div className="flex items-start justify-between gap-3 border-b border-slate-300 pb-4">
                <div className="min-w-0 text-sm"><p className="font-semibold text-slate-900">{status.account.name}</p><p className="break-all text-slate-600">{status.account.email}</p><p className="mt-1 capitalize text-slate-600">{role} account</p></div>
                <NotificationLink />
            </div>
            {status.email_update?.pending && <p role="status" className="text-xs leading-relaxed text-amber-800">{status.email_update.attempts ? "We couldn't send the email update yet. We'll try again. You can read your current status here." : 'An email update is waiting to send. You can read your current status here.'}</p>}
            {status.sources.map(source => <section key={source.type + source.id + source.source_token} className="rounded-lg border border-slate-300 bg-white p-4">
                <h2 className="text-sm font-semibold text-slate-900">{source.name}</h2>
                <p className="mt-1 text-xs font-semibold capitalize text-rose-800">{source.status === 'inactive' ? 'Deactivated' : source.status}</p>
                <p className="mt-2 whitespace-pre-line text-sm leading-relaxed text-slate-700">{source.reason}</p>
                <AccountReviewRequestForm source={source} />
            </section>)}
            <div className="flex flex-wrap gap-2">
                <Link href="/account/security" className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Login activity</Link>
                {status.can_correct_application && <Link href={route('kyc.pending')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Correct application</Link>}
                {status.can_correct_identity && !status.can_open_portal && <Link href={route('identity-corrections.own')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Correct reviewed details</Link>}
                {!status.account.email_verified_at && <Link href={route('verification.notice')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Verify email</Link>}
                {status.can_open_portal && <Link href={route('dashboard')} className="rounded-lg bg-[#E00D42] px-3 py-2 text-sm font-semibold text-white">{status.account.role === 'buyer' ? 'Open marketplace' : 'Open dashboard'}</Link>}
                {status.account.role === 'buyer' && <Link href={route('buyer.orders.index')} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Your orders</Link>}
                <button type="button" onClick={() => router.reload({ only: ['status'] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Check status</button>
            </div>
            {status.requests.length > 0 && <section className="space-y-3 border-t border-slate-300 pt-4">
                <h2 className="text-sm font-semibold text-slate-900">Recent review requests</h2>
                {status.requests.map(item => <article key={item.id} className="rounded-lg border border-slate-300 p-3 text-sm">
                    <p className="font-semibold">Request #{item.id}: {reviewStatusLabels[item.status]}</p>
                    <p className="mt-1 whitespace-pre-line leading-relaxed text-slate-700">{item.message}</p>
                    {item.decision && <><p className="mt-2 font-semibold">{reviewActionLabels[item.decision.action]}</p><p className="mt-1 whitespace-pre-line text-slate-700">{item.decision.reason}</p></>}
                    {item.status === 'superseded' && !item.decision && <p className="mt-2 text-slate-600">Your status changed after this request. Check the current details above.</p>}
                    <p className="mt-2 text-xs text-slate-500">Sent {new Date(item.created_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })}</p>
                </article>)}
            </section>}
            <button type="button" onClick={() => router.post(route('logout'))} className="text-sm font-semibold text-slate-600 underline underline-offset-2">Sign out</button>
        </div>
    </GuestLayout>;
}
