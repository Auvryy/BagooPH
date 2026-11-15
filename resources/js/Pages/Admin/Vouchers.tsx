import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { PaginatedData } from '@/types';
import CampaignForm from '@/Components/Vouchers/CampaignForm';
import CampaignActionDialog, { CampaignAction } from '@/Components/Vouchers/CampaignActionDialog';
import CampaignTable from '@/Components/Vouchers/CampaignTable';
import CampaignStats from '@/Components/Vouchers/CampaignStats';
import { Campaign, CampaignStats as Stats } from '@/Components/Vouchers/campaignTypes';

export default function AdminVouchers({ campaigns, stats }: { campaigns: PaginatedData<Campaign>; stats: Stats }) {
    const [editor, setEditor] = useState<{ campaign: Campaign | null } | null>(null);
    const [decision, setDecision] = useState<{ campaign: Campaign; action: CampaignAction } | null>(null);
    return <DashboardLayout title="Voucher campaigns" subtitle="Bagoo-funded offers, approved spending ceilings and reasoned shop suspensions" actions={
        <button type="button" onClick={() => setEditor({ campaign: null })} className="flex min-h-10 items-center gap-2 rounded-control bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600"><Plus className="h-4 w-4" />New Bagoo voucher</button>
    }>
        <Head title="Voucher campaigns — BagooPH Admin" />
        <div className="space-y-4">
            <p className="inline-flex rounded-chip bg-info-soft px-2.5 py-1 text-[13px] font-medium text-info">Bagoo vouchers are paid from platform commission. Sellers keep their full share.</p>
            <CampaignStats stats={stats} />
            <CampaignTable campaigns={campaigns} platform onEdit={campaign => setEditor({ campaign })} onAction={(campaign, action) => setDecision({ campaign, action })} onCreate={() => setEditor({ campaign: null })} />
        </div>
        {editor && <CampaignForm campaign={editor.campaign} platform onClose={() => setEditor(null)} />}
        {decision && <CampaignActionDialog {...decision} platform onClose={() => setDecision(null)} />}
    </DashboardLayout>;
}
