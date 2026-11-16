import { Link } from '@inertiajs/react';
import { PaginatedData } from '@/types';
import VoucherPagination from './VoucherPagination';
import { CampaignAction } from './CampaignActionDialog';
import { Campaign, campaignBenefit, campaignDate, campaignMoney } from './campaignTypes';

export default function CampaignTable({ campaigns, platform, onEdit, onAction, onCreate }: { campaigns: PaginatedData<Campaign>; platform: boolean; onEdit: (campaign: Campaign) => void; onAction: (campaign: Campaign, action: CampaignAction) => void; onCreate?: () => void }) {
    const button = (campaign: Campaign, action: CampaignAction, label: string) => <button type="button" onClick={() => onAction(campaign, action)} className={action === 'publish' ? 'min-h-8 rounded-control bg-brand-500 px-3 font-semibold text-white hover:bg-brand-600' : 'min-h-8 rounded-control border border-line-300 bg-white px-3 font-medium text-ink-900 hover:bg-surface-100'}>{label}</button>;
    return <div className="overflow-hidden rounded-card border border-line-200 bg-white">
        {!campaigns.data.length ? <div className="flex min-h-64 flex-col items-center justify-center gap-3 p-6 text-center"><h2 className="text-base font-semibold text-ink-900">No voucher campaigns yet</h2>{onCreate && <button type="button" onClick={onCreate} className="min-h-10 rounded-control bg-brand-500 px-4 text-sm font-semibold text-white hover:bg-brand-600">Create your first voucher</button>}</div> : <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-[13px] text-ink-700">
                <thead className="border-b border-line-200 bg-surface-50 text-ink-600"><tr><th className="p-4 font-medium">Voucher</th><th className="p-4 font-medium">Offer</th><th className="p-4 font-medium">Usage</th><th className="p-4 font-medium">Status</th><th className="p-4 font-medium">Actions</th></tr></thead>
                <tbody className="divide-y divide-line-200">{campaigns.data.map(campaign => {
                    const owned = !platform || campaign.issuer_type === 'platform';
                    const ended = campaign.state === 'archived';
                    const terminal = ['archived', 'expired', 'exhausted', 'unavailable', 'legacy_review'].includes(campaign.state);
                    const terms = campaign.published_terms ?? campaign.draft_terms;
                    return <tr key={campaign.id} className="align-top hover:bg-surface-50">
                        <td className="space-y-1 p-4"><p className="font-semibold text-ink-900">{campaign.name}</p><span className="inline-block rounded-chip bg-surface-100 px-1.5 py-0.5 text-xs font-medium tracking-wide text-ink-700">{campaign.code}</span><p className="text-xs text-ink-500">{campaign.issuer_type === 'platform' ? 'Paid by Bagoo' : campaign.shop_name ? `Paid by ${campaign.shop_name}` : 'Legacy issuer needs review'}</p></td>
                        <td className="space-y-1 p-4"><p className="font-semibold text-ink-900">{campaignBenefit(terms)}</p>{terms && <><p>Minimum {campaignMoney(terms.minimum_cents)}</p>{campaign.issuer_type === 'shop' && <p>{terms.product_scope === 'selected' ? `Selected products only (${terms.product_ids?.length ?? 0})` : 'All shop products'}</p>}<p>Ends {campaignDate(terms.ends_at)}</p><p>{terms.per_buyer_limit} use{terms.per_buyer_limit === 1 ? '' : 's'} per buyer</p></>}</td>
                        <td className="space-y-1.5 p-4">{campaign.balance ? (() => {
                            const limit = campaign.published_terms?.global_limit ?? 0;
                            const used = campaign.balance.redemption_count;
                            const left = (campaign.approved_budget_cents ?? 0) - campaign.balance.committed_cents - campaign.balance.spent_cents;
                            return <>
                                <p className="font-semibold text-ink-900">{campaign.remaining_uses ?? 0} uses remaining</p>
                                <span className="block h-1.5 w-40 overflow-hidden rounded-full bg-surface-100" role="img" aria-label={`${used} of ${limit} used`}>
                                    <span className="block h-full rounded-full bg-brand-500" style={{ width: `${limit ? Math.min(100, (used / limit) * 100) : 0}%` }} />
                                </span>
                                <p className="text-xs text-ink-600">{used} / {limit} used · {campaignMoney(campaign.balance.spent_cents + campaign.balance.committed_cents)} spent · {campaignMoney(left)} left</p>
                            </>;
                        })() : <p>{campaign.legacy_used_count ? `${campaign.legacy_used_count} older recorded uses` : 'No published uses'}</p>}</td>
                        <td className="space-y-1 p-4"><span className={`inline-block rounded-chip px-2 py-0.5 text-xs font-medium capitalize ${campaign.state === 'active' ? 'bg-success-soft text-success' : campaign.state === 'suspended' ? 'bg-brand-50 text-brand-700' : ['paused', 'scheduled', 'draft'].includes(campaign.state) ? 'bg-warning-soft text-warning' : 'bg-surface-100 text-ink-600'}`}>{campaign.state.replaceAll('_', ' ')}</span>{campaign.has_draft && campaign.current_revision_id && <p className="text-xs text-warning">Revised draft ready</p>}</td>
                        <td className="p-4"><div className="flex max-w-64 flex-wrap gap-2">
                            {owned && !ended && campaign.issuer_type !== 'legacy_unknown' && <button type="button" onClick={() => onEdit(campaign)} className="min-h-8 rounded-control border border-line-300 bg-white px-3 font-medium text-ink-900 hover:bg-surface-100">Edit draft</button>}
                            {owned && campaign.has_draft && !ended && !campaign.suspended_at && button(campaign, 'publish', 'Publish voucher')}
                            {owned && ['active', 'scheduled'].includes(campaign.state) && button(campaign, 'pause', 'Pause')}
                            {owned && campaign.state === 'paused' && button(campaign, 'resume', 'Resume')}
                            {owned && !ended && button(campaign, 'archive', 'End')}
                            {platform && !owned && campaign.current_revision_id && !ended && button(campaign, campaign.suspended_at ? 'reinstate' : 'suspend', campaign.suspended_at ? 'Clear suspension' : 'Suspend')}
                            <Link href={route(`${platform ? 'admin' : 'seller'}.vouchers.show`, campaign.id)} className="min-h-8 rounded-control px-2 py-1.5 font-medium text-ink-900 underline decoration-line-300 underline-offset-4 hover:decoration-ink-900">History</Link>
                        </div>{terminal && !campaign.has_draft && <p className="mt-2 text-xs text-ink-500">Original history retained.</p>}</td>
                    </tr>;
                })}</tbody>
            </table>
        </div>}
        <VoucherPagination page={campaigns} label="Campaign pages" />
    </div>;
}
