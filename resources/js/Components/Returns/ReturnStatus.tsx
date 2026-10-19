export const returnLabels: Record<string, string> = {
    requested: 'Seller review', rejected: 'Seller rejected', escalated: 'Admin review', approved: 'Ready to return',
    withdrawn: 'Withdrawn', expired: 'Drop-off expired', denied: 'Return declined',
    at_destination_hub: 'Return accepted', in_transit: 'Travelling to seller', at_origin_hub: 'At seller’s hub',
    ready_for_seller: 'Ready for seller', seller_received: 'Seller received', condition_review: 'Condition review',
    repayment_ready: 'Repayment review', payment_recorded: 'Awaiting your receipt', repayment_disputed: 'Payment under review', repaid: 'Repaid',
};

export default function ReturnStatus({ status }: { status: string }) {
    return <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-semibold ${
        ['rejected', 'denied', 'condition_review', 'repayment_disputed'].includes(status) ? 'border-amber-300 bg-amber-50 text-amber-900'
            : ['repaid', 'seller_received'].includes(status) ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                : 'border-slate-300 bg-slate-50 text-slate-700'}`}>{returnLabels[status] ?? 'Needs review'}</span>;
}

export const returnDate = (date: string | null) => date ? new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila',
}).format(new Date(date)) : 'Not set';
