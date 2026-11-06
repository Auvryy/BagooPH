import { Archive, BadgeCheck, CircleDot, FileText, Pencil, Play, ShieldAlert, ShieldCheck, Wallet } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Campaign, campaignBenefit, campaignDate, campaignMoney } from './campaignTypes';
import VoucherPagination from './VoucherPagination';
import { PaginatedData } from '@/types';

export interface CampaignHistoryEntry { id: number; action: string; actor_name: string; reason: string | null; voucher_revision_id: number | null; budget_increase_cents: number; before_state: { approved_budget_cents?: number | null }; after_state: { approved_budget_cents?: number | null }; created_at: string }
export interface CampaignRedemption { id: number; created_at: string | null; discount_cents: number; products: { id: number; name: string; quantity: number; image: string | null }[] }
const actionIcons: Record<string, LucideIcon> = {
    draft_saved: Pencil, publish: BadgeCheck, pause: CircleDot, resume: Play, archive: Archive,
    budget_increased: Wallet, suspend: ShieldAlert, reinstate: ShieldCheck,
};
export default function CampaignHistory({ campaign, history, redemptions }: { campaign: Campaign; history: PaginatedData<CampaignHistoryEntry>; redemptions: PaginatedData<CampaignRedemption> }) {
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
                : <ol className="divide-y divide-slate-200">{history.data.map(entry => {
                    const Icon = actionIcons[entry.action] ?? CircleDot;
                    return <li key={entry.id} className="space-y-2 p-5 text-sm text-slate-600">
                    <p className="flex items-center gap-2 font-bold capitalize text-slate-900"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-[#E00D42]"><Icon aria-hidden="true" className="h-4 w-4" /></span>{entry.action.replaceAll('_', ' ')}</p><p>{entry.actor_name} · {campaignDate(entry.created_at)}{entry.voucher_revision_id ? ` · Revision #${entry.voucher_revision_id}` : ''}</p>
                    {entry.reason && <p>{entry.reason}</p>}
                    {entry.budget_increase_cents > 0 && <p>Budget increased by {campaignMoney(entry.budget_increase_cents)}: {campaignMoney(entry.before_state.approved_budget_cents)} to {campaignMoney(entry.after_state.approved_budget_cents)}.</p>}
                </li>})}</ol>}
            <VoucherPagination page={history} label="Campaign history pages" />
        </div>
        <section className="space-y-3">
            <div><h2 className="text-lg font-bold text-slate-900">Redeemed products</h2><p className="mt-1 text-sm text-slate-600">Products shown here came from recorded voucher redemptions. Buyer identity and order contact details are not displayed.</p></div>
            <div className="overflow-hidden rounded-2xl border border-slate-300 bg-white">
                {!redemptions.data.length ? <div className="flex min-h-32 items-center justify-center p-6 text-center text-sm text-slate-600">No product redemptions have been recorded for this voucher.</div>
                    : <ol className="divide-y divide-slate-200">{redemptions.data.map(redemption => <li key={redemption.id} className="space-y-3 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2 text-sm"><p className="font-semibold text-slate-900">Redemption #{redemption.id}</p><p className="text-slate-600">{redemption.created_at ? campaignDate(redemption.created_at) : 'Date unavailable'} · Saved {campaignMoney(redemption.discount_cents)}</p></div>
                        {redemption.products.length ? <ul className="flex flex-wrap gap-3">{redemption.products.map(product => <li key={`${redemption.id}-${product.id}`} className="flex min-w-48 items-center justify-between gap-3 rounded-lg border border-slate-200 p-2 text-xs">
                            <span className="min-w-0 flex-1"><strong className="block truncate text-slate-900">{product.name}</strong><span className="text-slate-600">Quantity {product.quantity}</span></span>
                            {product.image ? <img src={product.image} alt="" loading="lazy" className="h-10 w-10 shrink-0 rounded-md border border-slate-200 object-cover" /> : <span aria-hidden="true" className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100"><FileText className="h-4 w-4 text-slate-500" /></span>}
                        </li>)}</ul> : <p className="text-xs text-slate-500">No product detail is available for this historical redemption.</p>}
                    </li>)}</ol>}
                <VoucherPagination page={redemptions} label="Redemption pages" />
            </div>
        </section>
    </div>;
}
