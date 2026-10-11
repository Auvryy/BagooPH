import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import CampaignHistory, { CampaignHistoryEntry } from '@/Components/Vouchers/CampaignHistory';
import { Campaign } from '@/Components/Vouchers/campaignTypes';
import { PaginatedData } from '@/types';

export default function AdminVoucherHistory(props: { campaign: Campaign; history: PaginatedData<CampaignHistoryEntry>; backUrl: string }) {
    return <DashboardLayout title="Campaign decisions" subtitle="Recorded issuers, funding ceilings and promotion decisions" actions={<Link href={props.backUrl} className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold">Back to campaigns</Link>}>
        <Head title="Campaign decisions — BagooPH Admin" /><CampaignHistory {...props} />
    </DashboardLayout>;
}
