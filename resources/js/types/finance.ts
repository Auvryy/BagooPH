export interface SettlementHistory {
    id: number; reference: string; sequence: number; event_type: string; amount_cents: number;
    actor_name: string; actor_role: string; payment_reference: string | null; reason: string;
    source_reference: string | null; proof_url: string | null; created_at: string;
    context: { from: string; to: string; previous_reference: string | null; recipient_id: number };
}

export interface SettlementRecord {
    order_id: number; order_number: string; reference: string | null; seller_id: number | null;
    seller_name: string | null; shop_name: string | null; currency: 'PHP';
    status: 'pending' | 'eligible' | 'authorized' | 'settled'; version: number;
    product_cents: number; commission_cents: number; seller_cents: number; shipping_cents: number; discount_cents: number;
    blockers: string[]; order_status: string; buyer_completed: boolean; cash_reconciled: boolean;
    legacy_ledger: { id: number; status: string } | null;
    buyer_reference: string | null; cash_id: number | null; cash_reference: string | null;
    reconciliation_reference: string | null; history?: SettlementHistory[];
}

export interface FinancialPage<T> {
    data: T[]; current_page: number; last_page: number; total: number;
    prev_page_url: string | null; next_page_url: string | null;
}

export type OversightMode = 'admin' | 'seller' | 'company';
export interface FinancialTotal {
    key: string; label: string; definition: string; amount_cents: string | null; source_count: number; url: string | null;
}
export interface OversightCashEvent {
    reference: string; sequence: number; event_type: string; amount_cents: number; actor_id: number; actor_role: string;
    from_user_id: number | null; to_user_id: number | null; from_stage: string | null; to_stage: string | null;
    reason: string | null; evidence_reference: string | null; source_reference: string | null; created_at: string;
    before: Record<string, string>; after: Record<string, string>;
}
export interface OversightRecord {
    order_id: number; order_number: string; currency: 'PHP'; recorded_at: string; date_basis: string; cash_status: string;
    cash: { reference: string; journal_reference: string; status: string; company_id: number; hub_id: number;
        recorded_at: string; expected_cents: string; amounts: Record<string, string>; history?: OversightCashEvent[] } | null;
    proceeds: (Omit<SettlementRecord, 'status'> & { status: SettlementRecord['status'] | 'void' | 'unverified' }) | null;
    metrics: Record<string, string | null>; unavailable: boolean; url: string;
}
export interface OversightFilters {
    from?: string; to?: string; state?: string; company?: string | number; recipient?: string | number; metric?: string;
}
export interface OversightProps {
    records: FinancialPage<OversightRecord>; totals: FinancialTotal[]; filters: OversightFilters; mode: OversightMode;
    error: string | null; currency: 'PHP'; timezone: 'Asia/Manila'; stateOptions: string[];
    companies: { id: number; name: string }[]; recipients: { id: number; name: string }[];
}
