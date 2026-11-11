import { trackingDate } from '@/Components/OrderTrackingPanel';

export interface CancellationRequest {
    id: number; reference: string; version: number; status: string; reason: string; notes: string | null;
    respond_by: string; decision_reason: string | null; can_review: boolean; approve_url?: string; reject_url?: string;
}
export interface CancellationFeedback {
    allowed: boolean; mode: 'request' | 'direct'; code: string; message: string; payment_message: string;
    refund: { reference: string; amount_cents: number; created_at: string } | null; request: CancellationRequest | null;
}
export default function OrderCancellationFeedback({ feedback }: { feedback?: CancellationFeedback | null }) {
    if (!feedback) return null;
    const request = feedback.request;
    return <section aria-label="Cancellation status" className="space-y-2 rounded-xl border border-rose-200 bg-rose-50/60 p-4 text-sm text-slate-700">
        <h3 className="font-bold text-[#9F1239]">{feedback.code === 'cancelled' ? 'Order cancelled' : 'Cancellation'}</h3>
        <p role="status">{feedback.message}</p>
        {request && <div className="space-y-1 border-t border-rose-200 pt-2">
            <p className="font-semibold">{request.reference} · {request.status.replaceAll('_', ' ')}</p>
            <p>{request.reason}{request.notes ? `: ${request.notes}` : ''}</p>
            {request.status === 'pending' && <p>Seller response due {trackingDate(request.respond_by)}</p>}
            {request.status === 'expired' && <p>The response window ended. Your order and payment are unchanged.</p>}
            {request.decision_reason && <p>Review outcome: {request.decision_reason}</p>}
        </div>}
        <p className="text-xs">{feedback.payment_message}</p>
        {feedback.refund && <p className="font-semibold">Wallet refund: ₱{(feedback.refund.amount_cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2 })} · {feedback.refund.reference} · {trackingDate(feedback.refund.created_at)}</p>}
    </section>;
}
