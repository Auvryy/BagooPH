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
        <button type="button" onClick={() => setEditor({ campaign: null })} className="flex items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836]"><Plus className="h-4 w-4" />New voucher</button>
    }>
        <Head title="Vouchers — BagooPH Seller" />
        <div className="space-y-5">
            <p className="rounded-xl border border-slate-300 bg-white p-4 text-sm text-slate-600">Your shop funds its product offers from the seller share. Save a draft, review its dates and limits, then publish. Buyer saves are bookmarks; successful checkout uses the budget. A pause or ended campaign preserves placed orders.</p>
            <CampaignStats stats={stats} />
            <CampaignTable campaigns={campaigns} platform={false} onEdit={campaign => setEditor({ campaign })} onAction={(campaign, action) => setDecision({ campaign, action })} />
        </div>
        {editor && <CampaignForm campaign={editor.campaign} platform={false} onClose={() => setEditor(null)} />}
        {decision && <CampaignActionDialog {...decision} platform={false} onClose={() => setDecision(null)} />}
    </DashboardLayout>;
}
