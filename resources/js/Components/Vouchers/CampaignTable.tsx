import { Link } from '@inertiajs/react';
import { PaginatedData } from '@/types';
import VoucherPagination from './VoucherPagination';
import { CampaignAction } from './CampaignActionDialog';
import { Campaign, campaignBenefit, campaignDate, campaignMoney } from './campaignTypes';

export default function CampaignTable({ campaigns, platform, onEdit, onAction }: { campaigns: PaginatedData<Campaign>; platform: boolean; onEdit: (campaign: Campaign) => void; onAction: (campaign: Campaign, action: CampaignAction) => void }) {
    const button = (campaign: Campaign, action: CampaignAction, label: string) => <button type="button" onClick={() => onAction(campaign, action)} className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-semibold text-slate-700 hover:border-[#E00D42] hover:text-[#E00D42]">{label}</button>;
    return <div className="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-xs">
        {!campaigns.data.length ? <div className="flex min-h-64 flex-col items-center justify-center gap-2 p-6 text-center"><h2 className="font-bold text-slate-900">No voucher campaigns yet</h2><p className="text-sm text-slate-600">Save a draft with clear dates, a spending ceiling and use limits.</p></div> : <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-xs text-slate-700">
                <thead className="border-b border-slate-300 bg-slate-50 text-slate-500"><tr><th className="p-4">Campaign</th><th className="p-4">Terms</th><th className="p-4">Uses and spending</th><th className="p-4">State</th><th className="p-4">Actions</th></tr></thead>
                <tbody className="divide-y divide-slate-200">{campaigns.data.map(campaign => {
                    const owned = !platform || campaign.issuer_type === 'platform';
                    const ended = campaign.state === 'archived';
                    const terminal = ['archived', 'expired', 'exhausted', 'unavailable', 'legacy_review'].includes(campaign.state);
                    const terms = campaign.published_terms ?? campaign.draft_terms;
                    return <tr key={campaign.id} className="align-top hover:bg-slate-50/70">
                        <td className="space-y-2 p-4"><p className="font-bold text-slate-900">{campaign.name}</p><span className="inline-block rounded-lg bg-slate-100 px-2 py-1 font-bold tracking-wide">{campaign.code}</span><p>{campaign.issuer_type === 'platform' ? 'Bagoo-funded' : campaign.shop_name ?? 'Legacy issuer needs review'}</p></td>
                        <td className="space-y-2 p-4"><p className="font-semibold text-[#E00D42]">{campaignBenefit(terms)}</p>{terms && <><p>Minimum {campaignMoney(terms.minimum_cents)}</p>{campaign.issuer_type === 'shop' && <p>{terms.product_scope === 'selected' ? `Selected products only (${terms.product_ids?.length ?? 0})` : 'All shop products'}</p>}<p>Ends {campaignDate(terms.ends_at)}</p><p>{terms.per_buyer_limit} use{terms.per_buyer_limit === 1 ? '' : 's'} per buyer</p></>}</td>
                        <td className="space-y-2 p-4">{campaign.balance ? <><p>{campaign.balance.redemption_count} / {campaign.published_terms?.global_limit} successful uses</p><p>Committed: {campaignMoney(campaign.balance.committed_cents)}</p><p>Settled expense: {campaignMoney(campaign.balance.spent_cents)}</p><p>Remaining: {campaignMoney((campaign.approved_budget_cents ?? 0) - campaign.balance.committed_cents - campaign.balance.spent_cents)}</p></> : <p>{campaign.legacy_used_count ? `${campaign.legacy_used_count} older recorded uses` : 'No published uses'}</p>}</td>
                        <td className="space-y-2 p-4"><span className={`inline-block rounded-lg px-2.5 py-1.5 font-semibold capitalize ${campaign.state === 'active' ? 'bg-emerald-50 text-emerald-700' : campaign.state === 'suspended' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'}`}>{campaign.state.replaceAll('_', ' ')}</span>{campaign.has_draft && campaign.current_revision_id && <p className="text-amber-700">Revised draft ready</p>}</td>
                        <td className="p-4"><div className="flex max-w-64 flex-wrap gap-2">
                            {owned && !ended && campaign.issuer_type !== 'legacy_unknown' && <button type="button" onClick={() => onEdit(campaign)} className="rounded-lg border border-slate-300 px-3 py-2 font-semibold hover:text-[#E00D42]">Edit draft</button>}
                            {owned && campaign.has_draft && !ended && !campaign.suspended_at && button(campaign, 'publish', 'Publish draft')}
                            {owned && ['active', 'scheduled'].includes(campaign.state) && button(campaign, 'pause', 'Pause')}
                            {owned && campaign.state === 'paused' && button(campaign, 'resume', 'Resume')}
                            {owned && campaign.current_revision_id && !ended && campaign.state !== 'expired' && !campaign.suspended_at && button(campaign, 'increase_budget', 'Budget')}
                            {owned && !ended && button(campaign, 'archive', 'End')}
                            {platform && !owned && campaign.current_revision_id && !ended && button(campaign, campaign.suspended_at ? 'reinstate' : 'suspend', campaign.suspended_at ? 'Clear suspension' : 'Suspend')}
                            <Link href={route(`${platform ? 'admin' : 'seller'}.vouchers.show`, campaign.id)} className="rounded-lg px-3 py-2 font-semibold text-[#E00D42] hover:underline">History</Link>
                        </div>{terminal && !campaign.has_draft && <p className="mt-2 text-slate-500">Original history retained.</p>}</td>
                    </tr>;
                })}</tbody>
            </table>
        </div>}
        <VoucherPagination page={campaigns} label="Campaign pages" />
    </div>;
}
