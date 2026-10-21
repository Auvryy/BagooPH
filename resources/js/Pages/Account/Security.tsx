import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import AccountSessionPanel from '@/Components/AccountSessionPanel';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import CourierLayout from '@/Layouts/CourierLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
import GuestLayout from '@/Layouts/GuestLayout';
import { refreshPage } from '@/utils/accountRequests';
import type { PageProps } from '@/types';
import type { AccountSessionProps } from '@/types/accountSessions';

/** Narrow access for applicants and existing links; portal settings embed the same controls. */
export default function Security(props: AccountSessionProps) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    if (!user) return <GuestLayout title="Sign in required" guestOnly={false}><p className="text-sm text-slate-600">Sign in to review your login activity.</p><Link href="/login" className="mt-4 inline-block font-semibold text-[#C20836]">Sign in</Link></GuestLayout>;

    const wrap = (children: ReactNode) => user.role === 'buyer'
        ? <BuyerOrderAccessLayout canUsePortal={props.canUsePortal}>{children}</BuyerOrderAccessLayout>
        : !props.canUsePortal ? <GuestLayout title="Login activity" subtitle="Manage access to your account." maxWidth="5xl" noCard guestOnly={false}>{children}</GuestLayout>
        : user.role === 'courier' ? <CourierLayout title="Login activity" isOnline={props.isOnline} scope={props.scope} hidePageHeading>{children}</CourierLayout>
            : <DashboardLayout title="Login activity" subtitle="Manage your account’s browser and app sessions.">{children}</DashboardLayout>;

    return wrap(<>
        <Head title="Login activity — BagooPH" />
        <div className="mx-auto max-w-5xl space-y-4 px-4 py-6 sm:px-6">
            <Link href={props.canUsePortal ? '/account/settings' : '/account/status'} className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-slate-600 hover:text-[#C20836]"><ArrowLeft className="h-4 w-4" aria-hidden="true" />{props.canUsePortal ? 'Back to settings' : 'Back to account status'}</Link>
            <AccountSessionPanel {...props} onRefresh={refreshPage} />
        </div>
    </>);
}
