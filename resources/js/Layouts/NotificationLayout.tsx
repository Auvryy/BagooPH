import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import NotificationLink from '@/Components/NotificationLink';
import BuyerLayout from '@/Layouts/BuyerLayout';
import CourierLayout from '@/Layouts/CourierLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
import GuestLayout from '@/Layouts/GuestLayout';
import type { PageProps } from '@/types';
import type { CourierScope } from '@/utils/courier';

export interface NotificationPortal {
    portal: 'buyer' | 'courier' | 'dashboard' | 'account';
    accountUrl: string;
    isOnline?: boolean;
    scope?: CourierScope | null;
}

export default function NotificationLayout({ children, portal, accountUrl, isOnline = false, scope }: PropsWithChildren<NotificationPortal>) {
    const { flash } = usePage<PageProps>().props;
    if (portal === 'buyer') return <BuyerLayout>{children}</BuyerLayout>;
    if (portal === 'courier') return <CourierLayout title="Notifications" pageLabel="Notifications" hidePageHeading isOnline={isOnline} scope={scope ?? undefined}>{children}</CourierLayout>;
    if (portal === 'dashboard') return <DashboardLayout title="Notifications" subtitle="Your account and work updates">{children}</DashboardLayout>;

    return <GuestLayout maxWidth="4xl" noCard showFooter={false}>
        <div className="mb-5 flex items-center justify-between gap-3">
            <Link href={accountUrl} className="inline-flex min-h-11 items-center gap-2 rounded-[8px] border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-[#E00D42]"><ArrowLeft className="h-4 w-4" aria-hidden="true" />Account status</Link>
            <NotificationLink />
        </div>
        {flash?.success && <p role="status" className="mb-4 rounded-[8px] border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-800">{flash.success}</p>}
        {flash?.error && <p role="alert" className="mb-4 rounded-[8px] border border-rose-300 bg-rose-50 p-4 text-sm text-rose-800">{flash.error}</p>}
        {children}
    </GuestLayout>;
}
