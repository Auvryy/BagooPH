import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CampaignHistory, { CampaignHistoryEntry } from '@/Components/Vouchers/CampaignHistory';
import { Campaign } from '@/Components/Vouchers/campaignTypes';
import { PaginatedData } from '@/types';

export default function SellerVoucherHistory(props: { campaign: Campaign; history: PaginatedData<CampaignHistoryEntry>; backUrl: string }) {
    return <DashboardLayout title="Voucher history" subtitle="Your shop’s original terms and recorded changes" actions={<Link href={props.backUrl} className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold">Back to vouchers</Link>}>
        <Head title="Voucher history — BagooPH Seller" /><CampaignHistory {...props} />
    </DashboardLayout>;
}
