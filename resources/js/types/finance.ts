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
