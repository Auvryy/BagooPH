import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Flag, ShieldAlert } from 'lucide-react';
import type { Review } from '@/types';

const DISPUTE_REASONS: Array<{ value: string; label: string }> = [
    { value: 'courier_delivery_complaint', label: 'Courier or shipping delay complaint (product unaffected)' },
    { value: 'abusive_or_troll', label: 'Abusive, spam, or off-topic trolling' },
    { value: 'unverified_or_false_claim', label: 'Factually false claim contradicting order evidence' },
    { value: 'competitor_sabotage', label: 'Coordinated or competitor sabotage' },
];

const DISPUTE_STATUS_LABELS: Record<string, string> = {
    pending: 'Dispute pending admin review',
    buyer_appealed: 'Buyer appeal in progress',
    upheld_excluded_courier: 'Excluded from rating (Courier issue)',
    upheld_removed: 'Removed after moderation',
    rejected: 'Dispute declined — review stands',
    appeal_overturned: 'Restored after buyer appeal',
    appeal_upheld: 'Moderation upheld after appeal',
};

export default function SellerReviewDisputeControl({ review }: { review: Review }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        reason_code: 'courier_delivery_complaint',
        seller_evidence: '',
    });

    const dispute = review.dispute;
    const modStatus = review.moderation_status;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const url =
            typeof route === 'function'
                ? route('seller.reviews.dispute', review.id)
                : `/seller/reviews/${review.id}/dispute`;
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('seller_evidence');
                setOpen(false);
            },
        });
    };

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-2">
                {modStatus === 'excluded_courier_issue' && (
                    <span className="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2.5 py-0.5 text-[11px] font-semibold text-sky-800">
                        <ShieldAlert className="h-3 w-3" />
                        Excluded from product & shop score (Courier issue)
                    </span>
                )}
                {modStatus === 'held_velocity_review' && (
                    <span className="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800">
                        Held for moderation review
                    </span>
                )}
                {dispute && (
                    <span className="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                        {dispute.reference ? `${dispute.reference}: ` : ''}
                        {DISPUTE_STATUS_LABELS[dispute.status] ?? dispute.status}
                    </span>
                )}
                {!dispute && !open && (
                    <button
                        type="button"
                        onClick={() => setOpen(true)}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-[#E00D42]"
                    >
                        <Flag className="h-3.5 w-3.5" />
                        Dispute review
                    </button>
                )}
            </div>

            {dispute?.adjudication_notes && (
                <p className="text-xs text-slate-600">
                    Moderation decision note: {dispute.adjudication_notes}
                </p>
            )}

            {open && !dispute && (
                <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2.5">
                    <div className="space-y-1">
                        <label className="block text-xs font-semibold text-slate-700">Dispute reason</label>
                        <select
                            value={form.data.reason_code}
                            onChange={e => form.setData('reason_code', e.target.value)}
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-900"
                        >
                            {DISPUTE_REASONS.map(option => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1">
                        <label className="block text-xs font-semibold text-slate-700">Evidence for moderator review</label>
                        <textarea
                            value={form.data.seller_evidence}
                            onChange={e => form.setData('seller_evidence', e.target.value)}
                            rows={2}
                            required
                            maxLength={1000}
                            placeholder="Explain why the rating reflects courier delay or policy abuse rather than product quality..."
                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900"
                        />
                    </div>
                    {form.errors.seller_evidence && (
                        <p className="text-xs text-rose-700">{form.errors.seller_evidence}</p>
                    )}
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            {form.processing ? 'Submitting...' : 'Submit dispute'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
