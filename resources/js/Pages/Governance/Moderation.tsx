import React, { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
    AlertTriangle,
    Banknote,
    CheckCircle2,
    MessageSquareWarning,
    Scale,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import type { PageProps } from '@/types';

interface AppealItem {
    id: number;
    reference: string;
    enforcement_sanction_id: number;
    appellant_user_id: number;
    reason: string;
    evidence_notes: string | null;
    status: string;
    review_notes: string | null;
    reviewed_at: string | null;
    created_at: string;
    appellant?: { id: number; name: string; role?: string } | null;
    reviewed_by?: { id: number; name: string } | null;
    sanction?: SanctionItem | null;
}

interface SanctionItem {
    id: number;
    reference: string;
    subject_type: string;
    subject_id: number;
    subject_user_id: number | null;
    scope: string;
    status: string;
    reason_code: string;
    reason: string;
    evidence_summary: string | null;
    auto_release_on_debt_clear: boolean;
    issued_by_id: number | null;
    issued_by_role: string | null;
    issued_at: string;
    expires_at: string | null;
    resolved_at: string | null;
    resolution_notes: string | null;
    subject_user?: { id: number; name: string; email: string; role: string } | null;
    issued_by?: { id: number; name: string } | null;
    appeals?: AppealItem[];
}

interface PenaltyItem {
    id: number;
    reference: string;
    subject_type: string;
    subject_id: number;
    subject_user_id: number | null;
    category: string;
    amount_cents: number;
    settled_cents: number;
    outstanding_cents: number;
    status: string;
    reason_code: string;
    reason: string;
    evidence_summary: string;
    decision_notes: string | null;
    proposed_at: string;
    decided_at: string | null;
    settled_at: string | null;
    subject_user?: { id: number; name: string; role: string } | null;
    order?: { id: number; order_number: string } | null;
    delivery?: { id: number; tracking_number: string } | null;
    hub?: { id: number; name: string } | null;
}

interface SignalItem {
    id: number;
    reference: string;
    signal_type: string;
    severity: string;
    status: string;
    is_punitive: boolean;
    summary: string;
    matched_terms: string[] | null;
    buyer_checkin_response: string | null;
    buyer_checkin_notes: string | null;
    created_at: string;
    subject_user?: { id: number; name: string; role: string } | null;
    order?: { id: number; order_number: string } | null;
    delivery?: { id: number; tracking_number: string } | null;
}

interface ScaleLogItem {
    id: number;
    reference: string;
    declared_weight_grams: number;
    measured_weight_grams: number;
    overage_grams: number;
    declared_shipping_cents: number;
    recalculated_shipping_cents: number;
    tariff_delta_cents: number;
    status: string;
    notes: string | null;
    recorded_at: string;
    order?: { id: number; order_number: string } | null;
    delivery?: { id: number; tracking_number: string } | null;
    shop?: { id: number; name: string } | null;
    seller?: { id: number; name: string } | null;
    penalty?: { id: number; reference: string; status: string } | null;
}

interface ReviewDisputeItem {
    id: number;
    reference: string;
    review_id: number;
    reason_code: string;
    seller_evidence: string;
    status: string;
    adjudicated_by_id: number | null;
    adjudication_notes: string | null;
    buyer_appeal_reason: string | null;
    appeal_notes: string | null;
    created_at: string;
    review?: {
        id: number;
        rating: number;
        comment: string | null;
        moderation_status: string;
        product?: { id: number; name: string; slug: string } | null;
        order?: { id: number; order_number: string } | null;
    } | null;
    shop?: { id: number; name: string } | null;
    seller?: { id: number; name: string } | null;
    buyer?: { id: number; name: string } | null;
}

interface HeldReviewItem {
    id: number;
    rating: number;
    comment: string | null;
    moderation_status: string;
    moderation_reason: string | null;
    created_at: string;
    product?: { id: number; name: string } | null;
    buyer?: { id: number; name: string } | null;
    order?: { id: number; order_number: string } | null;
}

interface BreachedClockItem {
    id: number;
    stage: string;
    breach_at: string | null;
    delivery?: { id: number; tracking_number: string } | null;
    company?: { id: number; name: string } | null;
    hub?: { id: number; name: string; province: string } | null;
}

interface DeliveryOption {
    id: number;
    tracking_number: string;
    status: string;
    order?: { id: number; order_number: string } | null;
}

interface Props {
    adminId: number;
    sanctions: SanctionItem[];
    appeals: AppealItem[];
    penalties: PenaltyItem[];
    signals: SignalItem[];
    scaleLogs: ScaleLogItem[];
    reviewDisputes: ReviewDisputeItem[];
    heldReviews: HeldReviewItem[];
    breachedSlaClocks: BreachedClockItem[];
    deliveriesForScale: DeliveryOption[];
    subjects: {
        buyers: Array<{ id: number; name: string; email: string }>;
        sellers: Array<{ id: number; name: string; email: string }>;
        shops: Array<{ id: number; name: string; user_id: number }>;
        companies: Array<{ id: number; name: string; user_id: number }>;
    };
    constants: {
        scopes: string[];
        reasonCodes: Record<string, string>;
        defaultSlaPenaltyCents: number;
    };
}

const formatPesos = (cents: number) =>
    `PHP ${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const scopeLabel = (scope: string) => scope.replaceAll('_', ' ');

export default function GovernanceModeration({
    adminId,
    sanctions,
    appeals,
    penalties,
    signals,
    scaleLogs,
    reviewDisputes,
    heldReviews,
    breachedSlaClocks,
    deliveriesForScale,
    subjects,
    constants,
}: Props) {
    const flash = usePage<PageProps>().props.flash as { success?: string; error?: string } | undefined;
    const [tab, setTab] = useState<'sanctions' | 'penalties' | 'scale' | 'reviews' | 'signals'>('sanctions');
    const [actionNotes, setActionNotes] = useState<Record<string, string>>({});
    const [busyKey, setBusyKey] = useState<string | null>(null);

    const sanctionForm = useForm({
        subject_type: 'buyer',
        subject_id: String(subjects.buyers[0]?.id ?? ''),
        scope: 'cod_restricted',
        reason_code: 'repeated_cod_refusal',
        reason: '',
        evidence_summary: '',
        expires_at: '',
    });

    const scaleForm = useForm({
        delivery_id: String(deliveriesForScale[0]?.id ?? ''),
        measured_weight_grams: '',
        notes: '',
    });

    const manualPenaltyForm = useForm({
        subject_type: 'seller',
        subject_id: String(subjects.sellers[0]?.id ?? ''),
        category: 'off_platform_bypass',
        amount_cents: '15000',
        reason_code: 'verified_off_platform_bypass',
        reason: '',
        evidence_summary: '',
    });

    const subjectChoices = () => {
        if (sanctionForm.data.subject_type === 'buyer') return subjects.buyers;
        if (sanctionForm.data.subject_type === 'seller') return subjects.sellers;
        if (sanctionForm.data.subject_type === 'shop') return subjects.shops;
        return subjects.companies;
    };

    const submitAction = (key: string, url: string, payload: Record<string, string | number | boolean | null | undefined>) => {
        setBusyKey(key);
        router.post(url, payload, {
            preserveScroll: true,
            onFinish: () => setBusyKey(null),
        });
    };

    return (
        <DashboardLayout
            title="Restrictions & Moderation"
            subtitle="Evidence-based enforcement, audited penalties, and review moderation"
        >
            <Head title="Governance Moderation — BagooPH" />
            <div className="space-y-6 font-sans">
                {flash?.success && (
                    <div role="status" className="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div role="alert" className="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900">
                        {flash.error}
                    </div>
                )}

                <section className="rounded-2xl border border-slate-300 bg-white p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 className="text-lg font-bold text-slate-900">Evidence-Based Enforcement & Review Moderation</h1>
                            <p className="mt-1 text-xs leading-relaxed text-slate-600">
                                Every scoped restriction, financial penalty, weigh-in tariff delta, and review action requires documented evidence and supports independent appeals. Keyword signals and last custody scans never auto-punish accounts.
                            </p>
                        </div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <span className="block text-slate-500">Active Sanctions</span>
                                <span className="text-base font-bold text-slate-900">{sanctions.filter(s => s.status === 'active').length}</span>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <span className="block text-slate-500">Open Appeals</span>
                                <span className="text-base font-bold text-amber-700">{appeals.filter(a => a.status === 'pending').length}</span>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <span className="block text-slate-500">Pending Penalties</span>
                                <span className="text-base font-bold text-rose-700">{penalties.filter(p => p.status === 'pending_review').length}</span>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <span className="block text-slate-500">Review Queue</span>
                                <span className="text-base font-bold text-indigo-700">
                                    {reviewDisputes.filter(d => d.status === 'pending' || d.status === 'buyer_appealed').length + heldReviews.length}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        {[
                            ['sanctions', 'Sanctions & Appeals', ShieldAlert],
                            ['penalties', 'Financial Penalties', Banknote],
                            ['scale', 'Scale & Tariff Logs', Scale],
                            ['reviews', 'Review Moderation', MessageSquareWarning],
                            ['signals', 'Non-Punitive Signals', AlertTriangle],
                        ].map(([id, label, Icon]) => {
                            const IconComp = Icon as React.ComponentType<{ className?: string }>;
                            const active = tab === id;
                            return (
                                <button
                                    key={String(id)}
                                    type="button"
                                    onClick={() => setTab(id as typeof tab)}
                                    className={`inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition ${
                                        active
                                            ? 'bg-slate-900 text-white'
                                            : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    <IconComp className="h-4 w-4" />
                                    {String(label)}
                                </button>
                            );
                        })}
                    </div>
                </section>

                {tab === 'sanctions' && (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <form
                            onSubmit={e => {
                                e.preventDefault();
                                sanctionForm.post('/governance/moderation/sanctions', { preserveScroll: true, onSuccess: () => sanctionForm.reset('reason', 'evidence_summary', 'expires_at') });
                            }}
                            className="space-y-4 rounded-2xl border border-slate-300 bg-white p-5"
                        >
                            <h2 className="text-sm font-bold text-slate-900">Issue Scoped Sanction or Warning</h2>
                            <div className="grid grid-cols-2 gap-3 text-xs">
                                <label className="block font-semibold text-slate-700">
                                    Subject Type
                                    <select
                                        value={sanctionForm.data.subject_type}
                                        onChange={e => {
                                            const next = e.target.value;
                                            const list = next === 'buyer' ? subjects.buyers : next === 'seller' ? subjects.sellers : next === 'shop' ? subjects.shops : subjects.companies;
                                            sanctionForm.setData({ ...sanctionForm.data, subject_type: next, subject_id: String(list[0]?.id ?? '') });
                                        }}
                                        className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                    >
                                        <option value="buyer">Buyer</option>
                                        <option value="seller">Seller</option>
                                        <option value="shop">Shop (Catalog)</option>
                                        <option value="company">Logistics Company</option>
                                    </select>
                                </label>
                                <label className="block font-semibold text-slate-700">
                                    Subject Account
                                    <select
                                        value={sanctionForm.data.subject_id}
                                        onChange={e => sanctionForm.setData('subject_id', e.target.value)}
                                        className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                    >
                                        {subjectChoices().map(item => (
                                            <option key={item.id} value={item.id}>#{item.id} — {item.name}</option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <label className="block text-xs font-semibold text-slate-700">
                                Restriction Scope
                                <select
                                    value={sanctionForm.data.scope}
                                    onChange={e => sanctionForm.setData('scope', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                >
                                    {constants.scopes.map(scope => (
                                        <option key={scope} value={scope}>{scopeLabel(scope)}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-xs font-semibold text-slate-700">
                                Reason Code
                                <select
                                    value={sanctionForm.data.reason_code}
                                    onChange={e => sanctionForm.setData('reason_code', e.target.value)}
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                >
                                    {Object.entries(constants.reasonCodes).map(([code, label]) => (
                                        <option key={code} value={code}>{label}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="block text-xs font-semibold text-slate-700">
                                User-Visible Reason
                                <textarea
                                    rows={3}
                                    required
                                    value={sanctionForm.data.reason}
                                    onChange={e => sanctionForm.setData('reason', e.target.value)}
                                    placeholder="Clear explanation of what happened and how the user can appeal or resolve it."
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                />
                            </label>
                            <label className="block text-xs font-semibold text-slate-700">
                                Verified Evidence Summary
                                <textarea
                                    rows={2}
                                    required={sanctionForm.data.scope !== 'warning'}
                                    value={sanctionForm.data.evidence_summary}
                                    onChange={e => sanctionForm.setData('evidence_summary', e.target.value)}
                                    placeholder="Order IDs, return fault references, signed delivery scans, or corroborated findings."
                                    className="mt-1 w-full rounded-lg border-slate-300 text-xs"
                                />
                            </label>
                            {Object.values(sanctionForm.errors).map((err, idx) => (
                                <p key={idx} role="alert" className="text-xs text-rose-700">{err}</p>
                            ))}
                            <button
                                type="submit"
                                disabled={sanctionForm.processing}
                                className="w-full rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836] disabled:opacity-50"
                            >
                                {sanctionForm.processing ? 'Issuing…' : 'Issue Scoped Enforcement'}
                            </button>
                        </form>

                        <div className="space-y-4 lg:col-span-2">
                            <div className="rounded-2xl border border-slate-300 bg-white p-5">
                                <h2 className="text-sm font-bold text-slate-900">Enforcement Sanctions ({sanctions.length})</h2>
                                <div className="mt-3 space-y-3">
                                    {sanctions.map(s => (
                                        <article key={s.id} className="rounded-xl border border-slate-200 p-4 text-xs">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-bold text-slate-900">{s.reference}</span>
                                                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold capitalize text-slate-700">{scopeLabel(s.scope)}</span>
                                                    <span className={`rounded-full px-2.5 py-0.5 font-semibold capitalize ${
                                                        s.status === 'active' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'
                                                    }`}>{s.status}</span>
                                                </div>
                                                <span className="text-slate-500">{s.subject_type} #{s.subject_id} {s.subject_user ? `· ${s.subject_user.name}` : ''}</span>
                                            </div>
                                            <p className="mt-2 font-medium text-slate-800">{s.reason}</p>
                                            {s.evidence_summary && <p className="mt-1 text-slate-600">Evidence: {s.evidence_summary}</p>}
                                            {s.status === 'active' && (
                                                <div className="mt-3 flex flex-wrap gap-2">
                                                    <input
                                                        type="text"
                                                        value={actionNotes[`res-${s.id}`] ?? ''}
                                                        onChange={e => setActionNotes({ ...actionNotes, [`res-${s.id}`]: e.target.value })}
                                                        placeholder="Resolution note before lifting…"
                                                        className="flex-1 rounded-lg border-slate-300 text-xs"
                                                    />
                                                    <button
                                                        type="button"
                                                        disabled={busyKey === `res-${s.id}`}
                                                        onClick={() => submitAction(`res-${s.id}`, `/governance/moderation/sanctions/${s.id}/resolve`, {
                                                            resolution_notes: actionNotes[`res-${s.id}`] || 'Resolved by governance review.',
                                                        })}
                                                        className="rounded-lg bg-slate-900 px-3 py-1.5 font-bold text-white disabled:opacity-50"
                                                    >
                                                        Lift Restriction
                                                    </button>
                                                </div>
                                            )}
                                        </article>
                                    ))}
                                    {sanctions.length === 0 && <p className="text-xs text-slate-500">No scoped sanctions recorded yet.</p>}
                                </div>
                            </div>

                            <div className="rounded-2xl border border-slate-300 bg-white p-5">
                                <h2 className="text-sm font-bold text-slate-900">Independent Appeals Queue ({appeals.length})</h2>
                                <div className="mt-3 space-y-3">
                                    {appeals.map(a => {
                                        const isSameIssuer = a.sanction?.issued_by_id !== null && a.sanction?.issued_by_id === adminId;
                                        return (
                                            <article key={a.id} className="rounded-xl border border-slate-200 p-4 text-xs">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="font-bold text-slate-900">{a.reference} · Appeal on {a.sanction?.reference ?? `Sanction #${a.enforcement_sanction_id}`}</span>
                                                    <span className="rounded-full bg-amber-100 px-2.5 py-0.5 font-semibold capitalize text-amber-900">{a.status}</span>
                                                </div>
                                                <p className="mt-2 text-slate-700">{a.reason}</p>
                                                {a.evidence_notes && <p className="mt-1 text-slate-600">Appellant evidence: {a.evidence_notes}</p>}
                                                {a.status === 'pending' && (
                                                    isSameIssuer ? (
                                                        <p className="mt-2 rounded-lg bg-amber-50 p-2 font-medium text-amber-900">
                                                            Independent separation: you issued this sanction, so another verified admin must decide this appeal.
                                                        </p>
                                                    ) : (
                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                            <input
                                                                type="text"
                                                                value={actionNotes[`apl-${a.id}`] ?? ''}
                                                                onChange={e => setActionNotes({ ...actionNotes, [`apl-${a.id}`]: e.target.value })}
                                                                placeholder="Decision notes…"
                                                                className="flex-1 rounded-lg border-slate-300 text-xs"
                                                            />
                                                            <button
                                                                type="button"
                                                                disabled={busyKey === `apl-${a.id}`}
                                                                onClick={() => submitAction(`apl-${a.id}`, `/governance/moderation/appeals/${a.id}/decide`, {
                                                                    decision: 'overturned',
                                                                    review_notes: actionNotes[`apl-${a.id}`] || 'Overturned after independent appeal review.',
                                                                })}
                                                                className="rounded-lg bg-emerald-700 px-3 py-1.5 font-bold text-white"
                                                            >
                                                                Overturn & Lift
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled={busyKey === `apl-${a.id}`}
                                                                onClick={() => submitAction(`apl-${a.id}`, `/governance/moderation/appeals/${a.id}/decide`, {
                                                                    decision: 'upheld',
                                                                    review_notes: actionNotes[`apl-${a.id}`] || 'Upheld after independent review.',
                                                                })}
                                                                className="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-800"
                                                            >
                                                                Uphold
                                                            </button>
                                                        </div>
                                                    )
                                                )}
                                            </article>
                                        );
                                    })}
                                    {appeals.length === 0 && <p className="text-xs text-slate-500">No enforcement appeals awaiting review.</p>}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'penalties' && (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <div className="space-y-5">
                            <div className="rounded-2xl border border-slate-300 bg-white p-5 space-y-3">
                                <h2 className="text-sm font-bold text-slate-900">Breached Pickup Clocks (Geo-Fenced {formatPesos(constants.defaultSlaPenaltyCents)})</h2>
                                <p className="text-xs text-slate-600">Automatically rejects paused, Force-Majeure, no-coverage, pickup-paused, and last-scan-only claims.</p>
                                {breachedSlaClocks.map(clock => (
                                    <div key={clock.id} className="rounded-xl border border-slate-200 p-3 text-xs space-y-2">
                                        <p className="font-bold text-slate-900">{clock.delivery?.tracking_number ?? `Clock #${clock.id}`} · {clock.company?.name ?? 'Unknown Co.'}</p>
                                        <p className="text-slate-600">{clock.hub?.name} ({clock.hub?.province})</p>
                                        <button
                                            type="button"
                                            disabled={busyKey === `sla-${clock.id}`}
                                            onClick={() => submitAction(`sla-${clock.id}`, `/governance/moderation/sla-clocks/${clock.id}/propose-penalty`, {})}
                                            className="rounded-lg bg-slate-900 px-3 py-1.5 font-bold text-white"
                                        >
                                            Propose {formatPesos(constants.defaultSlaPenaltyCents)} Penalty
                                        </button>
                                    </div>
                                ))}
                                {breachedSlaClocks.length === 0 && <p className="text-xs text-slate-500">No breached pickup SLA clocks found.</p>}
                            </div>

                            <form
                                onSubmit={e => {
                                    e.preventDefault();
                                    manualPenaltyForm.post('/governance/moderation/penalties', { preserveScroll: true, onSuccess: () => manualPenaltyForm.reset('reason', 'evidence_summary') });
                                }}
                                className="rounded-2xl border border-slate-300 bg-white p-5 space-y-3 text-xs"
                            >
                                <h2 className="text-sm font-bold text-slate-900">Propose Audited Penalty</h2>
                                <label className="block font-semibold text-slate-700">
                                    Category
                                    <select value={manualPenaltyForm.data.category} onChange={e => manualPenaltyForm.setData('category', e.target.value)} className="mt-1 w-full rounded-lg border-slate-300 text-xs">
                                        <option value="off_platform_bypass">Verified off-platform bypass</option>
                                        <option value="policy_breach">Policy breach</option>
                                        <option value="recovery_debt">Recovery / uncollected obligation</option>
                                    </select>
                                </label>
                                <label className="block font-semibold text-slate-700">
                                    Seller Account
                                    <select value={manualPenaltyForm.data.subject_id} onChange={e => manualPenaltyForm.setData('subject_id', e.target.value)} className="mt-1 w-full rounded-lg border-slate-300 text-xs">
                                        {subjects.sellers.map(s => <option key={s.id} value={s.id}>#{s.id} — {s.name}</option>)}
                                    </select>
                                </label>
                                <label className="block font-semibold text-slate-700">
                                    Amount (Centavos)
                                    <input type="number" min={100} value={manualPenaltyForm.data.amount_cents} onChange={e => manualPenaltyForm.setData('amount_cents', e.target.value)} className="mt-1 w-full rounded-lg border-slate-300 text-xs" />
                                </label>
                                <textarea rows={2} required value={manualPenaltyForm.data.reason} onChange={e => manualPenaltyForm.setData('reason', e.target.value)} placeholder="Penalty reason…" className="w-full rounded-lg border-slate-300 text-xs" />
                                <textarea rows={2} required value={manualPenaltyForm.data.evidence_summary} onChange={e => manualPenaltyForm.setData('evidence_summary', e.target.value)} placeholder="Corroborating evidence summary…" className="w-full rounded-lg border-slate-300 text-xs" />
                                <button type="submit" disabled={manualPenaltyForm.processing} className="w-full rounded-xl bg-[#E00D42] px-4 py-2 font-bold text-white">
                                    Queue For Admin Approval
                                </button>
                            </form>
                        </div>

                        <div className="rounded-2xl border border-slate-300 bg-white p-5 lg:col-span-2">
                            <h2 className="text-sm font-bold text-slate-900">Audited Financial Penalties ({penalties.length})</h2>
                            <p className="mt-1 text-xs text-slate-600">Insufficient wallet balance triggers an unpaid debt hold (payout hold + catalog shadowban for sellers) without making Wallet balances negative.</p>
                            <div className="mt-4 space-y-3">
                                {penalties.map(p => (
                                    <article key={p.id} className="rounded-xl border border-slate-200 p-4 text-xs">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <span className="font-bold text-slate-900">{p.reference}</span>
                                                <span className="font-bold text-[#C20836]">{formatPesos(p.amount_cents)}</span>
                                                <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold capitalize text-slate-700">{p.status.replaceAll('_', ' ')}</span>
                                            </div>
                                            <span className="text-slate-500">{p.subject_type} #{p.subject_id} {p.subject_user ? `· ${p.subject_user.name}` : ''}</span>
                                        </div>
                                        <p className="mt-2 font-medium text-slate-800">{p.reason}</p>
                                        <p className="mt-1 text-slate-600">Evidence: {p.evidence_summary}</p>
                                        {p.status === 'pending_review' && (
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <input
                                                    type="text"
                                                    value={actionNotes[`pen-${p.id}`] ?? ''}
                                                    onChange={e => setActionNotes({ ...actionNotes, [`pen-${p.id}`]: e.target.value })}
                                                    placeholder="Audit decision notes…"
                                                    className="flex-1 rounded-lg border-slate-300 text-xs"
                                                />
                                                <button
                                                    type="button"
                                                    disabled={busyKey === `pen-${p.id}`}
                                                    onClick={() => submitAction(`pen-${p.id}`, `/governance/moderation/penalties/${p.id}/decide`, {
                                                        decision: 'approve',
                                                        decision_notes: actionNotes[`pen-${p.id}`] || 'Approved after evidence verification.',
                                                    })}
                                                    className="rounded-lg bg-[#E00D42] px-3 py-1.5 font-bold text-white"
                                                >
                                                    Approve Charge / Hold
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => submitAction(`pen-${p.id}`, `/governance/moderation/penalties/${p.id}/decide`, {
                                                        decision: 'waive',
                                                        decision_notes: actionNotes[`pen-${p.id}`] || 'Waived on audit review.',
                                                    })}
                                                    className="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-700"
                                                >
                                                    Waive
                                                </button>
                                            </div>
                                        )}
                                    </article>
                                ))}
                                {penalties.length === 0 && <p className="text-xs text-slate-500">No financial penalties recorded yet.</p>}
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'scale' && (
                    <div className="grid gap-6 lg:grid-cols-3">
                        <form
                            onSubmit={e => {
                                e.preventDefault();
                                if (!scaleForm.data.delivery_id) return;
                                scaleForm.post(`/governance/moderation/deliveries/${scaleForm.data.delivery_id}/scale-log`, {
                                    preserveScroll: true,
                                    onSuccess: () => scaleForm.reset('measured_weight_grams', 'notes'),
                                });
                            }}
                            className="space-y-3 rounded-2xl border border-slate-300 bg-white p-5 text-xs"
                        >
                            <h2 className="text-sm font-bold text-slate-900">Log Hub Scale Discrepancy</h2>
                            <label className="block font-semibold text-slate-700">
                                Delivery / Order
                                <select value={scaleForm.data.delivery_id} onChange={e => scaleForm.setData('delivery_id', e.target.value)} className="mt-1 w-full rounded-lg border-slate-300 text-xs">
                                    {deliveriesForScale.map(d => <option key={d.id} value={d.id}>{d.tracking_number} ({d.order?.order_number ?? 'Order'})</option>)}
                                </select>
                            </label>
                            <label className="block font-semibold text-slate-700">
                                Measured Hub Weight (Grams)
                                <input type="number" min={1} required value={scaleForm.data.measured_weight_grams} onChange={e => scaleForm.setData('measured_weight_grams', e.target.value)} placeholder="e.g. 3200" className="mt-1 w-full rounded-lg border-slate-300 text-xs" />
                            </label>
                            <label className="block font-semibold text-slate-700">
                                Operator Notes
                                <textarea rows={2} value={scaleForm.data.notes} onChange={e => scaleForm.setData('notes', e.target.value)} className="mt-1 w-full rounded-lg border-slate-300 text-xs" />
                            </label>
                            <button type="submit" disabled={scaleForm.processing} className="w-full rounded-xl bg-slate-900 px-4 py-2 font-bold text-white">
                                Record Scale Reading
                            </button>
                        </form>

                        <div className="rounded-2xl border border-slate-300 bg-white p-5 lg:col-span-2">
                            <h2 className="text-sm font-bold text-slate-900">Scale Discrepancy Audit Logs ({scaleLogs.length})</h2>
                            <div className="mt-3 space-y-3">
                                {scaleLogs.map(log => (
                                    <article key={log.id} className="rounded-xl border border-slate-200 p-4 text-xs flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="font-bold text-slate-900">{log.reference} · {log.delivery?.tracking_number} ({log.shop?.name ?? 'Shop'})</p>
                                            <p className="mt-1 text-slate-600">
                                                Declared: {log.declared_weight_grams}g · Measured: {log.measured_weight_grams}g · Delta: +{log.overage_grams}g · Tariff difference: <strong>{formatPesos(log.tariff_delta_cents)}</strong>
                                            </p>
                                        </div>
                                        {log.tariff_delta_cents >= 100 && !log.penalty && (
                                            <button
                                                type="button"
                                                disabled={busyKey === `scl-${log.id}`}
                                                onClick={() => submitAction(`scl-${log.id}`, `/governance/moderation/scale-logs/${log.id}/propose-penalty`, {})}
                                                className="rounded-lg bg-[#E00D42] px-3 py-1.5 font-bold text-white"
                                            >
                                                Propose Tariff Charge
                                            </button>
                                        )}
                                        {log.penalty && <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold">{log.penalty.reference} ({log.penalty.status})</span>}
                                    </article>
                                ))}
                                {scaleLogs.length === 0 && <p className="text-xs text-slate-500">No scale discrepancy readings logged yet.</p>}
                            </div>
                        </div>
                    </div>
                )}

                {tab === 'reviews' && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-300 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-bold text-slate-900">Seller Review Disputes & Buyer Appeals ({reviewDisputes.length})</h2>
                            {reviewDisputes.map(d => (
                                <article key={d.id} className="rounded-xl border border-slate-200 p-4 text-xs space-y-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-bold text-slate-900">{d.reference} · {d.review?.product?.name ?? `Review #${d.review_id}`}</span>
                                        <span className="rounded-full bg-indigo-50 px-2.5 py-0.5 font-semibold text-indigo-800">{d.status.replaceAll('_', ' ')}</span>
                                    </div>
                                    <p className="text-slate-700">Review ({d.review?.rating}/5): “{d.review?.comment ?? 'Rating only'}”</p>
                                    <p className="text-slate-600">Seller ({d.shop?.name}) claim ({d.reason_code}): {d.seller_evidence}</p>
                                    {d.buyer_appeal_reason && <p className="rounded-lg bg-amber-50 p-2 text-amber-900">Buyer appeal: {d.buyer_appeal_reason}</p>}

                                    {d.status === 'pending' && (
                                        <div className="space-y-2 pt-2 border-t border-slate-100">
                                            <input
                                                type="text"
                                                value={actionNotes[`rvd-${d.id}`] ?? ''}
                                                onChange={e => setActionNotes({ ...actionNotes, [`rvd-${d.id}`]: e.target.value })}
                                                placeholder="Moderation adjudication notes…"
                                                className="w-full rounded-lg border-slate-300 text-xs"
                                            />
                                            <div className="flex flex-wrap gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => submitAction(`rvd-${d.id}`, `/governance/moderation/review-disputes/${d.id}/adjudicate`, {
                                                        outcome: 'upheld_excluded_courier',
                                                        adjudication_notes: actionNotes[`rvd-${d.id}`] || 'Complaint reflects courier delivery; kept visible and excluded from product/shop score.',
                                                    })}
                                                    className="rounded-lg bg-amber-600 px-3 py-1.5 font-bold text-white"
                                                >
                                                    Exclude Score (Keep Complaint)
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => submitAction(`rvd-${d.id}`, `/governance/moderation/review-disputes/${d.id}/adjudicate`, {
                                                        outcome: 'upheld_removed',
                                                        adjudication_notes: actionNotes[`rvd-${d.id}`] || 'Removed after moderation policy review.',
                                                    })}
                                                    className="rounded-lg bg-rose-700 px-3 py-1.5 font-bold text-white"
                                                >
                                                    Remove Review
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => submitAction(`rvd-${d.id}`, `/governance/moderation/review-disputes/${d.id}/adjudicate`, {
                                                        outcome: 'rejected',
                                                        adjudication_notes: actionNotes[`rvd-${d.id}`] || 'Genuine verified product review retained.',
                                                    })}
                                                    className="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-700"
                                                >
                                                    Reject Dispute
                                                </button>
                                            </div>
                                        </div>
                                    )}

                                    {d.status === 'buyer_appealed' && (
                                        d.adjudicated_by_id === adminId ? (
                                            <p className="rounded-lg bg-amber-50 p-2 font-medium text-amber-900">Independent admin required: you adjudicated the initial dispute.</p>
                                        ) : (
                                            <div className="space-y-2 pt-2 border-t border-slate-100">
                                                <input
                                                    type="text"
                                                    value={actionNotes[`rva-${d.id}`] ?? ''}
                                                    onChange={e => setActionNotes({ ...actionNotes, [`rva-${d.id}`]: e.target.value })}
                                                    placeholder="Independent appeal decision notes…"
                                                    className="w-full rounded-lg border-slate-300 text-xs"
                                                />
                                                <div className="flex gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => submitAction(`rva-${d.id}`, `/governance/moderation/review-disputes/${d.id}/appeal-decision`, {
                                                            outcome: 'appeal_overturned',
                                                            appeal_notes: actionNotes[`rva-${d.id}`] || 'Appeal granted; review restored to product score.',
                                                        })}
                                                        className="rounded-lg bg-emerald-700 px-3 py-1.5 font-bold text-white"
                                                    >
                                                        Restore Review & Rating
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => submitAction(`rva-${d.id}`, `/governance/moderation/review-disputes/${d.id}/appeal-decision`, {
                                                            outcome: 'appeal_upheld',
                                                            appeal_notes: actionNotes[`rva-${d.id}`] || 'Original moderation upheld.',
                                                        })}
                                                        className="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-700"
                                                    >
                                                        Uphold Removal
                                                    </button>
                                                </div>
                                            </div>
                                        )
                                    )}
                                </article>
                            ))}
                            {reviewDisputes.length === 0 && <p className="text-xs text-slate-500">No review disputes or appeals in queue.</p>}
                        </div>

                        <div className="rounded-2xl border border-slate-300 bg-white p-5 space-y-3">
                            <h2 className="text-sm font-bold text-slate-900">Velocity-Held Reviews ({heldReviews.length})</h2>
                            <p className="text-xs text-slate-600">Reviews captured by rapid velocity bursts are held cleanly for human verification without auto-banning buyers.</p>
                            {heldReviews.map(r => (
                                <article key={r.id} className="rounded-xl border border-slate-200 p-4 text-xs space-y-2">
                                    <p className="font-bold text-slate-900">Review #{r.id} · {r.product?.name} ({r.rating}/5)</p>
                                    <p className="text-slate-700">{r.comment ?? 'Rating only'}</p>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            onClick={() => submitAction(`vel-${r.id}`, `/governance/moderation/reviews/${r.id}/velocity-decision`, {
                                                decision: 'release',
                                                reason: 'Verified genuine purchase after velocity check.',
                                            })}
                                            className="rounded-lg bg-emerald-700 px-3 py-1.5 font-bold text-white"
                                        >
                                            Release & Score
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => submitAction(`vel-${r.id}`, `/governance/moderation/reviews/${r.id}/velocity-decision`, {
                                                decision: 'remove',
                                                reason: 'Confirmed coordinated spam burst.',
                                            })}
                                            className="rounded-lg bg-rose-700 px-3 py-1.5 font-bold text-white"
                                        >
                                            Confirm Spam Removal
                                        </button>
                                    </div>
                                </article>
                            ))}
                            {heldReviews.length === 0 && <p className="text-xs text-slate-500">No velocity-held reviews waiting.</p>}
                        </div>
                    </div>
                )}

                {tab === 'signals' && (
                    <div className="rounded-2xl border border-slate-300 bg-white p-5 space-y-3">
                        <h2 className="text-sm font-bold text-slate-900">Non-Punitive Moderation & Anti-Scam Signals ({signals.length})</h2>
                        <p className="text-xs text-slate-600">Signal detection alone never fines, shadowbans, or suspends accounts. Review buyer check-in responses before escalating.</p>
                        {signals.map(sig => (
                            <article key={sig.id} className="rounded-xl border border-slate-200 p-4 text-xs space-y-2">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="flex items-center gap-2">
                                        <span className="font-bold text-slate-900">{sig.reference}</span>
                                        <span className="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold">{sig.signal_type.replaceAll('_', ' ')}</span>
                                        <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 font-semibold text-emerald-800">Non-Punitive Signal</span>
                                    </div>
                                    <span className="capitalize font-semibold text-slate-700">{sig.status.replaceAll('_', ' ')}</span>
                                </div>
                                <p className="text-slate-700">{sig.summary}</p>
                                {sig.matched_terms && sig.matched_terms.length > 0 && (
                                    <p className="text-slate-600">Matched terms: {sig.matched_terms.join(', ')}</p>
                                )}
                                {sig.buyer_checkin_response && (
                                    <p className="rounded-lg bg-amber-50 p-2 font-medium text-amber-900">
                                        Buyer safety check-in: {sig.buyer_checkin_response.replaceAll('_', ' ')} {sig.buyer_checkin_notes ? `— ${sig.buyer_checkin_notes}` : ''}
                                    </p>
                                )}
                                {['open', 'checked_in'].includes(sig.status) && (
                                    <div className="flex flex-wrap gap-2 pt-2">
                                        <input
                                            type="text"
                                            value={actionNotes[`sig-${sig.id}`] ?? ''}
                                            onChange={e => setActionNotes({ ...actionNotes, [`sig-${sig.id}`]: e.target.value })}
                                            placeholder="Review findings…"
                                            className="flex-1 rounded-lg border-slate-300 text-xs"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => submitAction(`sig-${sig.id}`, `/governance/moderation/signals/${sig.id}/review`, {
                                                status: 'dismissed_benign',
                                                review_notes: actionNotes[`sig-${sig.id}`] || 'Benign context confirmed.',
                                            })}
                                            className="rounded-lg border border-slate-300 px-3 py-1.5 font-bold text-slate-700"
                                        >
                                            Dismiss Benign
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => submitAction(`sig-${sig.id}`, `/governance/moderation/signals/${sig.id}/review`, {
                                                status: 'confirmed_violation',
                                                review_notes: actionNotes[`sig-${sig.id}`] || 'Corroborated off-platform violation.',
                                            })}
                                            className="rounded-lg bg-rose-700 px-3 py-1.5 font-bold text-white"
                                        >
                                            Mark Corroborated
                                        </button>
                                    </div>
                                )}
                            </article>
                        ))}
                        {signals.length === 0 && <p className="text-xs text-slate-500">No moderation signals logged yet.</p>}
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
}
