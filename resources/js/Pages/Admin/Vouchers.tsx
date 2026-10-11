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
        <button type="button" onClick={() => setEditor({ campaign: null })} className="flex items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836]"><Plus className="h-4 w-4" />New Bagoo voucher</button>
    }>
        <Head title="Voucher campaigns — BagooPH Admin" />
        <div className="space-y-5">
            <p className="rounded-xl border border-slate-300 bg-white p-4 text-sm text-slate-600">Bagoo funds its product or shipping offers within the recorded ceiling. Shop offers retain their seller as issuer; suspend unsafe offers with a reason and a seller notice. Campaign history and placed-order savings remain recorded.</p>
            <CampaignStats stats={stats} />
            <CampaignTable campaigns={campaigns} platform onEdit={campaign => setEditor({ campaign })} onAction={(campaign, action) => setDecision({ campaign, action })} />
        </div>
        {editor && <CampaignForm campaign={editor.campaign} platform onClose={() => setEditor(null)} />}
        {decision && <CampaignActionDialog {...decision} platform onClose={() => setDecision(null)} />}
    </DashboardLayout>;
}
