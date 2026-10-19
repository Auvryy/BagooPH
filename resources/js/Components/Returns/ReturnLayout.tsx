import { PropsWithChildren } from 'react';
import { usePage } from '@inertiajs/react';
import BuyerOrderAccessLayout from '@/Layouts/BuyerOrderAccessLayout';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PageProps } from '@/types';

export default function ReturnLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<PageProps>().props;
    if (auth.user?.role === 'buyer') {
        const active = auth.user.status === 'active' && ['approved', 'verified'].includes(auth.user.kyc_status ?? '');
        return <BuyerOrderAccessLayout canUsePortal={active}>{children}</BuyerOrderAccessLayout>;
    }
    return <DashboardLayout title="Returns and refunds" subtitle="Review the original purchase, return and repayment history.">{children}</DashboardLayout>;
}
