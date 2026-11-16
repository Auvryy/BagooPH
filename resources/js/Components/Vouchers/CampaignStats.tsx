import { CampaignStats as Stats, campaignMoney } from './campaignTypes';

export default function CampaignStats({ stats }: { stats: Stats }) {
    const values = [
        { label: 'Live vouchers', value: String(stats.active), detail: `${stats.campaigns} campaigns across all pages` },
        { label: 'Times used', value: String(stats.redemptions), detail: stats.cancelled_allocations ? `${stats.cancelled_allocations} cancelled` : null },
        { label: 'Reserved for open orders', value: campaignMoney(stats.committed_cents), detail: null },
        { label: 'Spent', value: campaignMoney(stats.spent_cents), detail: `${campaignMoney(stats.remaining_cents)} left to spend` },
    ];
    return <div className="space-y-2">
        <div className="grid grid-cols-2 divide-x divide-y divide-line-200 overflow-hidden rounded-card border border-line-200 bg-white lg:grid-cols-4 lg:divide-y-0">
            {values.map(row => <div key={row.label} className="p-4 sm:p-5">
                <h2 className="text-[13px] text-ink-600">{row.label}</h2>
                <p className="mt-1 text-[24px] font-bold leading-8 tabular-nums text-ink-900">{row.value}</p>
                {row.detail && <p className="text-xs text-ink-500">{row.detail}</p>}
            </div>)}
        </div>
        {stats.legacy_uses > 0 && <p className="text-xs text-ink-600">{stats.legacy_uses} older uses keep their original counts.</p>}
    </div>;
}
