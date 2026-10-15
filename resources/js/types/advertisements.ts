export interface Destination { label?: string; url?: string | null }
export interface OfferTerms {
    code: string; name: string; description: string | null; issuer_type: string; shop_name: string | null;
    discount_type: string; value_cents: number; percentage_bps: number | null; minimum_cents: number;
    maximum_cents: number | null; per_buyer_limit: number; starts_at: string; ends_at: string;
}
export interface Draft {
    id: number; revision_number: number; title: string; alt_text: string; summary: string | null;
    purpose: string; destination_type: string; destination_id: number | null; product_ids: number[];
    offer_revision_id: number | null; slot: string | null; starts_at: string | null; ends_at: string | null;
    imported_candidate: boolean; image: string; image_bytes: number; width: number; height: number;
    target: Destination; offer: OfferTerms | null;
}
export interface Publication {
    id: number; revision_id: number; slot: string; title: string; state: string; reason: string | null;
    starts_at: string; ends_at: string; effective_end: string;
    replaces_publication_id?: number | null;
}
export interface AdvertisementSummary {
    id: number; version: number; title: string; creator: string; target: Destination; state: string; reason: string | null;
    has_draft: boolean; archived: boolean; slot: string | null; image: string | null; starts_at: string | null; ends_at: string | null;
    owner_type?: 'platform' | 'seller'; shop_name?: string | null; review_state?: string; current_submission_id?: number | null;
}
export interface Pagination<T> {
    data: T[]; current_page: number; last_page: number; total: number;
    prev_page_url: string | null; next_page_url: string | null;
}
export interface History {
    id: number; action: string; actor: string; reason: string | null; created_at: string;
    before_version: number; after_version: number; revision_id: number;
}
export interface AdvertisementDetail extends AdvertisementSummary {
    slug: string; draft: Draft; publications: Publication[]; history: Pagination<History>;
}
export interface Options {
    products: { id: number; name: string; shop_id: number }[];
    shops: { id: number; name: string }[]; categories: { id: number; name: string }[];
    offers: { id: number; name: string; code: string; shop_id: number | null; starts_at: string; ends_at: string }[];
}
export const slotLabel = (slot: string | null) => slot ? `${slot.startsWith('main') ? 'Main slide' : 'Side image'} ${slot.split('_')[1]}` : 'Not selected';
export interface PromotionSubmission {
    id: number; revision_id: number; state: string; submitted_at: string; revision: Draft; can_review: boolean;
    context_current?: boolean; shop_scope?: { shop_id: number; seller_id: number; name: string; root_category_id: number; root_category_name: string | null };
    eligibility_problem?: string | null; products?: { id: number; name: string; price: string | number; url: string }[];
    reviews: { id: number; decision: string; actor: string; actor_role: string; reason: string | null; created_at: string }[];
}
export interface PromotionDetail extends AdvertisementDetail {
    can_submit_revision: boolean; can_stop: boolean; submissions: PromotionSubmission[];
}
export const reviewLabel = (state: string = 'not_submitted') => (({
    not_submitted: 'Not submitted', pending: 'Under review', approved: 'Approved', changes_requested: 'Changes requested',
    rejected: 'Rejected', withdrawn: 'Withdrawn', superseded: 'Replaced',
} as Record<string, string>)[state] ?? stateLabel(state));
export const displayLabel = (state: string) => state === 'draft' ? 'Not published' : stateLabel(state);
export const manilaInput = (iso: string) => new Date(new Date(iso).getTime() + 8 * 3600000).toISOString().slice(0, 16);
export const dateLabel = (value: string | null) => value ? new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short',
}).format(new Date(value)) : 'Not scheduled';
export const stateLabel = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);
