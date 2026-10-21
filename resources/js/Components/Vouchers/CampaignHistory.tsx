import { Campaign, campaignBenefit, campaignDate, campaignMoney } from './campaignTypes';
import VoucherPagination from './VoucherPagination';
import { PaginatedData } from '@/types';

export interface CampaignHistoryEntry { id: number; action: string; actor_name: string; reason: string | null; voucher_revision_id: number | null; budget_increase_cents: number; before_state: { approved_budget_cents?: number | null }; after_state: { approved_budget_cents?: number | null }; created_at: string }
export default function CampaignHistory({ campaign, history }: { campaign: Campaign; history: PaginatedData<CampaignHistoryEntry> }) {
    const terms = campaign.published_terms ?? campaign.draft_terms;
    return <div className="space-y-5">
        <div className="space-y-3 rounded-2xl border border-slate-300 bg-white p-5 text-sm text-slate-600">
            <h2 className="text-lg font-bold text-slate-900">{campaign.name} · {campaign.code}</h2><p>{campaign.shop_name ?? 'Bagoo'} · {campaign.state.replaceAll('_', ' ')}</p>
            <p>{campaignBenefit(terms)}</p>
            {terms && campaign.issuer_type === 'shop' && <p>{terms.product_scope === 'selected' ? `Selected products only (${terms.product_ids?.length ?? 0})` : 'All shop products'}</p>}
            {campaign.approved_budget_cents !== null && <p>Approved spending ceiling: <strong>{campaignMoney(campaign.approved_budget_cents)}</strong></p>}
            <p>Published terms and placed-order savings stay recorded after edits, pauses or an ended campaign.</p>
        </div>
        <div className="overflow-hidden rounded-2xl border border-slate-300 bg-white">
            {!history.data.length ? <div className="flex min-h-52 items-center justify-center p-6 text-center text-sm text-slate-600">No campaign decisions were recorded for this older offer.</div>
                : <ol className="divide-y divide-slate-200">{history.data.map(entry => <li key={entry.id} className="space-y-2 p-5 text-sm text-slate-600">
                    <p className="font-bold capitalize text-slate-900">{entry.action.replaceAll('_', ' ')}</p><p>{entry.actor_name} · {campaignDate(entry.created_at)}{entry.voucher_revision_id ? ` · Revision #${entry.voucher_revision_id}` : ''}</p>
                    {entry.reason && <p>{entry.reason}</p>}
                    {entry.budget_increase_cents > 0 && <p>Budget increased by {campaignMoney(entry.budget_increase_cents)}: {campaignMoney(entry.before_state.approved_budget_cents)} to {campaignMoney(entry.after_state.approved_budget_cents)}.</p>}
                </li>)}</ol>}
            <VoucherPagination page={history} label="Campaign history pages" />
        </div>
    </div>;
}
