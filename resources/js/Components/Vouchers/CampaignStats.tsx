import { CampaignStats as Stats, campaignMoney } from './campaignTypes';

export default function CampaignStats({ stats }: { stats: Stats }) {
    const values = [{ label: 'Active campaigns', value: String(stats.active), detail: `${stats.campaigns} campaigns across all pages` },
        { label: 'Saved offers', value: String(stats.saves), detail: 'Bookmarks do not reserve uses or budget' },
        { label: 'Successful uses', value: String(stats.redemptions), detail: `${stats.cancelled_allocations} cancelled order allocations` },
        { label: 'Committed promotion cost', value: campaignMoney(stats.committed_cents), detail: 'Reserved for placed orders' },
        { label: 'Settled promotion expense', value: campaignMoney(stats.spent_cents), detail: `${campaignMoney(stats.released_cents)} released by cancellations` },
        { label: 'Unused recorded budget', value: campaignMoney(stats.remaining_cents), detail: `${campaignMoney(stats.budget_cents)} approved across retained revisions` }];
    return <div className="space-y-3"><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{values.map(row => <div key={row.label} className="space-y-2 rounded-2xl border border-slate-300 bg-white p-4 shadow-xs"><h2 className="text-xs font-semibold text-slate-500">{row.label}</h2><p className="text-xl font-bold text-slate-900">{row.value}</p><p className="text-xs text-slate-500">{row.detail}</p></div>)}</div>
        {stats.legacy_uses > 0 && <p className="text-xs text-slate-600">{stats.legacy_uses} older recorded uses retain their original counts. Buyer-level history and funding were not invented for those records.</p>}
    </div>;
}
