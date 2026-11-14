import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert } from 'lucide-react';

export interface ModeratedReviewContext {
    id: number;
    moderation_status?: string | null;
    moderation_reason?: string | null;
    appeal_url?: string | null;
    dispute?: {
        reference?: string | null;
        status?: string | null;
        adjudication_notes?: string | null;
        appeal_review_notes?: string | null;
    } | null;
}

const MODERATION_LABELS: Record<string, string> = {
    excluded_courier_issue: 'Excluded from product score (Delivery / courier issue)',
    held_velocity_review: 'Held for moderation verification',
    removed_policy: 'Removed by platform moderation',
    restored: 'Restored after independent buyer appeal',
};

export default function ReviewModerationNotice({ review }: { review: ModeratedReviewContext }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ appeal_reason: '' });

    const modStatus = review.moderation_status;
    const disputeStatus = review.dispute?.status;
    const showBadge =
        (modStatus && modStatus !== 'published') ||
        Boolean(disputeStatus);

    if (!showBadge && !review.appeal_url) {
        return null;
    }

    const submitAppeal = (e: React.FormEvent) => {
        e.preventDefault();
        if (!review.appeal_url) return;
        form.post(review.appeal_url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <div className="mt-2 space-y-2 rounded-lg border border-slate-200 bg-white p-2.5 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-1.5">
                    {modStatus && modStatus !== 'published' && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-800">
                            <ShieldAlert className="h-3 w-3" />
                            {MODERATION_LABELS[modStatus] ?? modStatus}
                        </span>
                    )}
                    {disputeStatus === 'buyer_appealed' && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                            <CheckCircle2 className="h-3 w-3" />
                            Buyer appeal pending independent review
                        </span>
                    )}
                </div>

                {review.appeal_url && !open && (
                    <button
                        type="button"
                        onClick={() => setOpen(true)}
                        className="text-xs font-semibold text-[#C20836] underline"
                    >
                        Appeal moderation decision
                    </button>
                )}
            </div>

            {review.moderation_reason && (
                <p className="text-[11px] text-slate-600">Moderation note: {review.moderation_reason}</p>
            )}
            {review.dispute?.appeal_review_notes && (
                <p className="text-[11px] text-slate-600">Appeal decision: {review.dispute.appeal_review_notes}</p>
            )}

            {open && review.appeal_url && (
                <form onSubmit={submitAppeal} className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                    <textarea
                        value={form.data.appeal_reason}
                        onChange={e => form.setData('appeal_reason', e.target.value)}
                        rows={2}
                        required
                        placeholder="Explain why your review accurately reflects the product you received..."
                        className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900"
                    />
                    {form.errors.appeal_reason && <p className="text-xs text-rose-700">{form.errors.appeal_reason}</p>}
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-slate-900 px-3 py-1 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            {form.processing ? 'Submitting...' : 'Submit appeal'}
                        </button>
                        <button
                            type="button"
                            onClick={() => setOpen(false)}
                            className="rounded-lg border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-600"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </div>
    );
}
