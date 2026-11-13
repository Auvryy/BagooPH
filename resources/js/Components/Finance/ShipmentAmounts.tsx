export interface ShipmentAmounts {
    actual_buyer_paid_cents: number; original_merchandise_cents: number; buyer_outbound_shipping_cents: number;
    reverse_fee_cents: number; reverse_fee_withheld_cents: number; buyer_refund_cents: number; buyer_liability_cents: number;
}
export const shipmentMoney = (cents: number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(cents / 100);
export const shipmentStatuses: Record<string, string> = { awaiting_execution: 'Awaiting execution', shipping_due: 'Shipping balance due', resolved: 'Resolved' };

export default function ShipmentAmountSummary({ value, preview = false, outstanding }: { value: ShipmentAmounts; preview?: boolean; outstanding?: number }) {
    return <section className="space-y-4" aria-label={preview ? 'Proposed financial amounts' : 'Reviewed financial amounts'}>
        <div className="grid gap-3 sm:grid-cols-3">{[
            [preview ? 'Proposed buyer refund' : 'Approved buyer refund', value.buyer_refund_cents],
            ['Outbound shipping spent', value.buyer_outbound_shipping_cents],
            [outstanding === undefined ? 'Separate shipping balance' : 'Shipping still outstanding', outstanding ?? value.buyer_liability_cents],
        ].map(([label, cents], index) => <div key={String(label)} className={`rounded-xl border p-4 ${index === 0 ? 'border-rose-200 bg-rose-50/70' : 'border-slate-300 bg-white'}`}><p className="text-xs text-slate-600">{label}</p><p className={`mt-2 text-xl font-bold ${index === 0 ? 'text-[#C20836]' : 'text-slate-900'}`}>{shipmentMoney(Number(cents))}</p></div>)}</div>
        <details className="rounded-xl border border-slate-300 bg-white p-4"><summary className="cursor-pointer text-sm font-semibold text-[#C20836]">Original amounts and reverse charge</summary><dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">{[
            ['Actual original buyer payment', value.actual_buyer_paid_cents], ['Items after original discounts', value.original_merchandise_cents],
            ['Reviewed reverse shipping', value.reverse_fee_cents], ['Reverse fee withheld from items', value.reverse_fee_withheld_cents],
            ['Total separate shipping charge', value.buyer_liability_cents],
        ].map(([label, cents]) => <div key={String(label)}><dt className="text-xs text-slate-600">{label}</dt><dd className="mt-1 font-bold text-slate-900">{shipmentMoney(Number(cents))}</dd></div>)}</dl></details>
    </section>;
}
