import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Banknote, CheckCircle2, ShieldAlert } from 'lucide-react';

export interface ActiveSanctionItem {
    id: number;
    reference: string;
    subject_type: string;
    scope: string;
    status: string;
    reason_code: string;
    reason: string;
    evidence_summary?: string | null;
    auto_release_on_debt_clear?: boolean;
    issued_at?: string | null;
    expires_at?: string | null;
    appeals?: Array<{
        id: number;
        reference: string;
        status: string;
        reason: string;
        review_notes?: string | null;
    }>;
}

export interface UnpaidPenaltyItem {
    id: number;
    reference: string;
    penalty_type: string;
    amount_cents: number;
    outstanding_cents: number;
    status: string;
    reason: string;
    evidence_summary?: string | null;
}

const SCOPE_LABELS: Record<string, string> = {
    warning: 'Formal Policy Warning',
    cod_restricted: 'Cash on Delivery (COD) Restricted',
    review_restricted: 'Product Review Privilege Restricted',
    catalog_visibility_restricted: 'Catalog Visibility Restricted',
    payout_hold: 'Seller Settlement Payout Hold',
    pickup_zone_restricted: 'Hub Pickup Zone Restricted',
};

function formatPhp(cents: number): string {
    return `PHP ${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function AccountEnforcementNotices({
    sanctions = [],
    penalties = [],
    title = 'Scoped Restrictions & Financial Obligations',
}: {
    sanctions?: ActiveSanctionItem[];
    penalties?: UnpaidPenaltyItem[];
    title?: string;
}) {
    const [appealingId, setAppealingId] = useState<number | null>(null);
    const [settlingId, setSettlingId] = useState<number | null>(null);
    const appealForm = useForm({ reason: '', evidence_notes: '' });

    if (sanctions.length === 0 && penalties.length === 0) {
        return null;
    }

    const submitAppeal = (e: React.FormEvent, sanctionId: number) => {
        e.preventDefault();
        appealForm.post(`/account/enforcement-sanctions/${sanctionId}/appeal`, {
            preserveScroll: true,
            onSuccess: () => {
                appealForm.reset();
                setAppealingId(null);
            },
        });
    };

    const settlePenalty = (penaltyId: number) => {
        setSettlingId(penaltyId);
        router.post(
            `/account/financial-penalties/${penaltyId}/settle`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSettlingId(null),
            },
        );
    };

    return (
        <section className="rounded-xl border border-amber-200 bg-amber-50/50 p-5 space-y-4">
            <div className="flex items-start gap-3">
                <ShieldAlert className="h-5 w-5 shrink-0 text-amber-700 mt-0.5" />
                <div>
                    <h2 className="text-sm font-bold text-slate-900">{title}</h2>
                    <p className="text-xs text-slate-600 mt-0.5">
                        Restrictions apply only to the stated scope with documented reasons. Existing completed orders, returns, and payouts continue under platform rules, and you may submit an appeal for independent admin review.
                    </p>
                </div>
            </div>

            {penalties.length > 0 && (
                <div className="space-y-2.5">
                    <h3 className="text-xs font-bold uppercase tracking-wider text-rose-800">Outstanding Financial Obligations</h3>
                    {penalties.map(penalty => (
                        <div key={penalty.id} className="rounded-xl border border-rose-200 bg-white p-4 space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2 text-xs font-bold text-slate-900">
                                    <Banknote className="h-4 w-4 text-rose-600" />
                                    <span>{penalty.reference}</span>
                                    <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 border border-rose-200">
                                        Balance: {formatPhp(penalty.outstanding_cents)}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    disabled={settlingId === penalty.id}
                                    onClick={() => settlePenalty(penalty.id)}
                                    className="rounded-lg bg-[#E00D42] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#c50b3a] disabled:opacity-50"
                                >
                                    {settlingId === penalty.id ? 'Settling...' : 'Pay from Bagoo Wallet'}
                                </button>
                            </div>
                            <p className="text-xs text-slate-700">{penalty.reason}</p>
                            {penalty.evidence_summary && (
                                <p className="text-[11px] text-slate-500">Audit evidence: {penalty.evidence_summary}</p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            {sanctions.length > 0 && (
                <div className="space-y-2.5">
                    <h3 className="text-xs font-bold uppercase tracking-wider text-amber-800">Active Scoped Restrictions</h3>
                    {sanctions.map(sanction => {
                        const openAppeal = sanction.appeals?.find(a => ['pending', 'under_review'].includes(a.status));
                        const latestAppeal = sanction.appeals?.[0];
                        return (
                            <div key={sanction.id} className="rounded-xl border border-amber-200 bg-white p-4 space-y-2.5">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs font-bold text-slate-900">{sanction.reference}</span>
                                        <span className="rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-semibold text-amber-800 border border-amber-200">
                                            {SCOPE_LABELS[sanction.scope] ?? sanction.scope}
                                        </span>
                                        {sanction.auto_release_on_debt_clear && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-700">
                                                <AlertTriangle className="h-3 w-3 text-amber-600" />
                                                Releases automatically once debt clears
                                            </span>
                                        )}
                                    </div>
                                    {!openAppeal && appealingId !== sanction.id && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                appealForm.reset();
                                                setAppealingId(sanction.id);
                                            }}
                                            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            Submit appeal
                                        </button>
                                    )}
                                </div>
                                <p className="text-xs text-slate-700">{sanction.reason}</p>
                                {sanction.evidence_summary && (
                                    <p className="text-[11px] text-slate-500">Evidence: {sanction.evidence_summary}</p>
                                )}
                                {openAppeal && (
                                    <div className="flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
                                        <CheckCircle2 className="h-4 w-4 text-sky-700 shrink-0" />
                                        <span>Appeal {openAppeal.reference} is pending review by an independent administrator.</span>
                                    </div>
                                )}
                                {!openAppeal && latestAppeal?.review_notes && (
                                    <p className="text-[11px] text-slate-600">
                                        Previous appeal ({latestAppeal.status}): {latestAppeal.review_notes}
                                    </p>
                                )}
                                {appealingId === sanction.id && (
                                    <form onSubmit={e => submitAppeal(e, sanction.id)} className="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2.5">
                                        <textarea
                                            value={appealForm.data.reason}
                                            onChange={e => appealForm.setData('reason', e.target.value)}
                                            rows={3}
                                            required
                                            placeholder="Explain why this restriction should be lifted or corrected..."
                                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900"
                                        />
                                        <input
                                            type="text"
                                            value={appealForm.data.evidence_notes}
                                            onChange={e => appealForm.setData('evidence_notes', e.target.value)}
                                            placeholder="Optional supporting references (waybill, chat timestamp, or receipt)..."
                                            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900"
                                        />
                                        {appealForm.errors.reason && <p className="text-xs text-rose-700">{appealForm.errors.reason}</p>}
                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                disabled={appealForm.processing}
                                                className="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                                            >
                                                {appealForm.processing ? 'Submitting...' : 'Send Appeal'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setAppealingId(null)}
                                                className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
