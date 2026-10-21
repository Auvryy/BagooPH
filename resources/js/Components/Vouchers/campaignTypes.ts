import { financeMoney, financeDate } from '@/utils/finance';

export interface CampaignTerms {
    code: string; name: string; description: string | null;
    discount_type: 'fixed' | 'percent' | 'free_shipping';
    value_cents: number; percentage_bps: number | null; minimum_cents: number; maximum_cents: number;
    budget_cents: number; global_limit: number; per_buyer_limit: number; starts_at: string; ends_at: string;
    product_scope?: 'all' | 'selected'; product_ids?: number[];
}
export interface Campaign {
    id: number; code: string; name: string; issuer_type: 'shop' | 'platform' | 'legacy_unknown';
    shop_name: string | null; state: string; version: number; has_draft: boolean;
    current_revision_id: number | null; draft_terms: CampaignTerms | null; terms: CampaignTerms | null;
    published_terms: CampaignTerms | null; approved_budget_cents: number | null; suspended_at: string | null;
    balance: { redemption_count: number; committed_cents: number; spent_cents: number; released_cents: number } | null;
    legacy_used_count: number | null;
    selected_products?: import('./VoucherProductSelection').VoucherProduct[];
}
export interface CampaignStats {
    campaigns: number; active: number; saves: number; redemptions: number; legacy_uses: number;
    cancelled_allocations: number; budget_cents: number; committed_cents: number; spent_cents: number;
    released_cents: number; remaining_cents: number;
}
export { financeMoney as campaignMoney, financeDate as campaignDate };
export function campaignBenefit(terms: CampaignTerms | null): string {
    if (!terms) return 'Older terms need funding review';
    if (terms.discount_type === 'percent') return `${(terms.percentage_bps ?? 0) / 100}% off, up to ${financeMoney(terms.maximum_cents)}`;
    if (terms.discount_type === 'free_shipping') return `Shipping discount up to ${financeMoney(Math.min(terms.maximum_cents, terms.value_cents || terms.maximum_cents))}`;
    return `${financeMoney(Math.min(terms.value_cents, terms.maximum_cents))} off products`;
}
export function manilaInput(value: string): string {
    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return '';
    // datetime-local has no timezone. Writers explicitly interpret these controls in Philippine time.
    return new Date(date.getTime() + 8 * 60 * 60 * 1000).toISOString().slice(0, 16);
}
export function previewPromotion(terms: { discount_type: string; discount_value: string; max_discount: string }, purchase: string) {
    const basis = Math.max(0, Math.round(Number(purchase || 0) * 100));
    const value = Math.round(Number(terms.discount_value || 0) * 100);
    const cap = Math.round(Number(terms.max_discount || 0) * 100);
    const sellerShare = basis - Math.round(basis / 10);
    const reduction = terms.discount_type === 'percent' ? Math.round(basis * value / 10000) : value;
    const discount = terms.discount_type === 'free_shipping' ? Math.min(5000, value || 5000, cap) : Math.min(basis, reduction, cap);
    return { basis, sellerShare, discount: Math.max(0, discount), netSeller: sellerShare - Math.max(0, discount) };
}
