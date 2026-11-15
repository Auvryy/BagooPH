import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData, Shop } from '@/types';
import CampaignForm from '@/Components/Vouchers/CampaignForm';
import CampaignActionDialog, { CampaignAction } from '@/Components/Vouchers/CampaignActionDialog';
import CampaignTable from '@/Components/Vouchers/CampaignTable';
import CampaignStats from '@/Components/Vouchers/CampaignStats';
import { Campaign, CampaignStats as Stats } from '@/Components/Vouchers/campaignTypes';

export default function SellerVouchers({ campaigns, stats, shop }: { campaigns: PaginatedData<Campaign>; stats: Stats; shop: Shop }) {
    const [editor, setEditor] = useState<{ campaign: Campaign | null } | null>(null);
    const [decision, setDecision] = useState<{ campaign: Campaign; action: CampaignAction } | null>(null);
    return <DashboardLayout title="Vouchers" subtitle={`Manage product offers for ${shop.name || 'your approved shop'}`} actions={
        <button type="button" onClick={() => setEditor({ campaign: null })} className="flex min-h-10 items-center gap-2 rounded-control bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600"><Plus className="h-4 w-4" />New voucher</button>
    }>
        <Head title="Vouchers — BagooPH Seller" />
        <div className="space-y-4">
            <p className="inline-flex rounded-chip bg-brand-50 px-2.5 py-1 text-[13px] font-medium text-brand-600">Your vouchers are paid from your shop payout, only when an order with the voucher completes.</p>
            <CampaignStats stats={stats} />
            <CampaignTable campaigns={campaigns} platform={false} onEdit={campaign => setEditor({ campaign })} onAction={(campaign, action) => setDecision({ campaign, action })} onCreate={() => setEditor({ campaign: null })} />
        </div>
        {editor && <CampaignForm campaign={editor.campaign} platform={false} onClose={() => setEditor(null)} />}
        {decision && <CampaignActionDialog {...decision} platform={false} onClose={() => setDecision(null)} />}
    </DashboardLayout>;
}
