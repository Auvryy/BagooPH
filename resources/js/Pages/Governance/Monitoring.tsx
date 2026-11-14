import React, { useState } from 'react';
import axios from 'axios';
import { Head, Link, router, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    PauseCircle,
    PlayCircle,
    Search,
    ShieldAlert,
    Truck,
} from 'lucide-react';
import type { EtaPresentation, PageProps } from '@/types';

interface SlaPauseItem {
    id: number;
    reference: string;
    scope_type: string;
    scope_value: string | null;
    reason_code: string;
    title: string;
    notes: string | null;
    status: string;
    starts_at: string;
    ends_at: string | null;
    resumed_at: string | null;
    accumulated_paused_seconds: number;
    company?: { id: number; name: string; code: string } | null;
    hub?: { id: number; name: string; code: string } | null;
    created_by?: { id: number; name: string } | null;
    ended_by?: { id: number; name: string } | null;
}

interface SlaClockItem {
    id: number;
    subject_type: string;
    subject_id: number;
    clock_kind: string;
    status: string;
    pause_reason: string | null;
    started_at: string | null;
    warning_at: string | null;
    breach_at: string | null;
    critical_at: string | null;
    paused_seconds: number;
    is_breached: boolean;
    order?: { id: number; order_number: string; status: string } | null;
    delivery?: { id: number; tracking_number: string; status: string; eta?: EtaPresentation | null } | null;
    hub?: { id: number; name: string; code: string; tier: string } | null;
    company?: { id: number; name: string; code: string } | null;
    active_pause?: { id: number; reference: string; title: string; reason_code: string } | null;
}

interface InvestigationEvent {
    id: number;
    event_type: string;
    notes: string | null;
    created_at: string;
    actor?: { id: number; name: string } | null;
}

interface InvestigationItem {
    id: number;
    reference: string;
    source_kind: string;
    status: string;
    proves_loss: boolean;
    liability_determined: boolean;
    compensation_status: string;
    insurer_funding_status: string;
    shipment_financial_plan_id: number | null;
    summary: string;
    resolution_notes: string | null;
    opened_at: string;
    resolved_at: string | null;
    delivery?: { id: number; tracking_number: string; status: string } | null;
    order?: { id: number; order_number: string; status: string } | null;
    hub?: { id: number; name: string; code: string } | null;
    company?: { id: number; name: string; code: string } | null;
    investigator?: { id: number; name: string } | null;
    opened_by?: { id: number; name: string } | null;
    resolved_by?: { id: number; name: string } | null;
    events?: InvestigationEvent[];
}

interface MonitoringProps {
    scope: {
        is_admin: boolean;
        is_company_manager: boolean;
        company_id: number | null;
        hub_id: number | null;
        base_path: string;
    };
    pauses: SlaPauseItem[];
    clocks: SlaClockItem[];
    telemetry: {
        counts: {
            running_clocks: number;
            paused_clocks: number;
            breached_clocks: number;
            stalled_hub_alerts: number;
            urgent_pickups: number;
            active_pickup_pauses: number;
            overloaded_barangays: number;
        };
        escalations: Array<{
            id: number;
            current_tier: string;
            status: string;
            coverage_outcome: string;
            is_urgent: boolean;
            dispatch_delay_label: string | null;
            surge_deferred_note: string | null;
            penalty_exempt: boolean;
            pickup_sla_started_at: string | null;
            delivery?: { id: number; tracking_number: string; status: string } | null;
            order?: { id: number; order_number: string; status: string } | null;
            origin_hub?: { id: number; name: string; code: string } | null;
        }>;
        pickup_pauses: Array<{
            id: number;
            reference: string;
            logistics_hub_id: number;
            barangay: string | null;
            reason: string;
            paused_at: string;
            hub?: { id: number; name: string; code: string } | null;
        }>;
        overload_alerts: Array<{ hub_id: number; hub_name: string; barangay: string; parcel_count: number }>;
    };
    investigations: InvestigationItem[];
    companies: Array<{ id: number; name: string; code: string }>;
    hubs: Array<{ id: number; name: string; code: string; tier: string; province: string | null; city_municipality: string | null; logistics_company_id: number }>;
    investigators: Array<{ id: number; name: string; role: string }>;
    financialPlans: Array<{ id: number; reference: string; order_id: number | null; buyer_return_case_id: number | null }>;
    openDeliveries: Array<{ id: number; tracking_number: string; status: string }>;
    constants: {
        scopes: string[];
        reasons: string[];
    };
}

function formatPht(value: string | null | undefined): string {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Manila',
    }).format(new Date(value)) + ' PHT';
}

export default function Monitoring({
    scope,
    pauses,
    clocks,
    telemetry,
    investigations,
    companies,
    hubs,
    investigators,
    financialPlans,
    openDeliveries,
    constants,
}: MonitoringProps) {
    const { flash, errors } = usePage<PageProps>().props;
    const [tab, setTab] = useState<'overview' | 'pauses' | 'clocks' | 'dispatch' | 'investigations'>('overview');
    const [busy, setBusy] = useState(false);

    // Force-Majeure pause creation
    const availableScopes = scope.is_admin ? constants.scopes : constants.scopes.filter((s) => ['company', 'hub'].includes(s));
    const [pauseScope, setPauseScope] = useState(availableScopes[0] ?? 'company');
    const [pauseProvince, setPauseProvince] = useState('');
    const [pauseCompanyId, setPauseCompanyId] = useState(String(scope.company_id ?? companies[0]?.id ?? ''));
    const [pauseHubId, setPauseHubId] = useState(String(scope.hub_id ?? hubs[0]?.id ?? ''));
    const [pauseReason, setPauseReason] = useState(constants.reasons[0] ?? 'typhoon');
    const [pauseTitle, setPauseTitle] = useState('');
    const [pauseNotes, setPauseNotes] = useState('');

    // Dry-run state
    const [dryRunResult, setDryRunResult] = useState<Record<string, unknown> | null>(null);
    const [dryRunError, setDryRunError] = useState('');

    // Pickup pause form
    const [pickupPauseHubId, setPickupPauseHubId] = useState(String(scope.hub_id ?? hubs.find((h) => h.tier === 'local_bayan_hub')?.id ?? hubs[0]?.id ?? ''));
    const [pickupPauseBarangay, setPickupPauseBarangay] = useState('');
    const [pickupPauseReason, setPickupPauseReason] = useState('');

    // Manual investigation open form
    const [invDeliveryId, setInvDeliveryId] = useState(String(openDeliveries[0]?.id ?? ''));
    const [invInvestigatorId, setInvInvestigatorId] = useState('');
    const [invSummary, setInvSummary] = useState('');

    // Active investigation action state per investigation ID
    const [invNotes, setInvNotes] = useState<Record<number, string>>({});
    const [invAssignUser, setInvAssignUser] = useState<Record<number, string>>({});
    const [invPlanId, setInvPlanId] = useState<Record<number, string>>({});

    const submitPost = (url: string, payload: Record<string, unknown>, onDone?: () => void) => {
        setBusy(true);
        router.post(url, payload as never, {
            preserveScroll: true,
            onSuccess: () => onDone?.(),
            onFinish: () => setBusy(false),
        });
    };

    const runDryPreview = async () => {
        setDryRunError('');
        setBusy(true);
        try {
            const response = await axios.post('/governance/monitoring/dry-run', { limit: 100 });
            setDryRunResult(response.data);
        } catch (err) {
            setDryRunError(axios.isAxiosError(err) ? (err.response?.data?.message ?? 'Dry-run preview failed.') : 'Dry-run preview failed.');
        } finally {
            setBusy(false);
        }
    };

    const inputClass = 'mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 shadow-2xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15';
    const primaryBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg bg-[#E00D42] px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-[#C20836] disabled:opacity-50 transition';
    const outlineBtn = 'inline-flex items-center justify-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 disabled:opacity-50 transition';

    return (
        <DashboardLayout
            title="SLA monitoring & recovery"
            subtitle="State-aware SLA clocks, Force-Majeure pauses, dispatch recovery & missing-parcel investigations"
        >
            <Head title="SLA monitoring & recovery — BagooPH" />

            <div className="space-y-5 font-sans">
                {flash?.success && (
                    <div role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-900">
                        {flash.success}
                    </div>
                )}
                {(flash?.error || Object.keys(errors).length > 0) && (
                    <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-medium text-rose-900 space-y-1">
                        {flash?.error && <p>{flash.error}</p>}
                        {Object.values(errors).map((msg, i) => <p key={i}>{msg}</p>)}
                    </div>
                )}

                {/* Telemetry Metric Strip */}
                <section aria-label="Monitoring telemetry" className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    {[
                        ['Running clocks', telemetry.counts.running_clocks, false],
                        ['Paused (Force Majeure)', telemetry.counts.paused_clocks, false],
                        ['Breached SLA clocks', telemetry.counts.breached_clocks, telemetry.counts.breached_clocks > 0],
                        ['Stalled hubs', telemetry.counts.stalled_hub_alerts, telemetry.counts.stalled_hub_alerts > 0],
                        ['Urgent pickups', telemetry.counts.urgent_pickups, telemetry.counts.urgent_pickups > 0],
                        ['Pickup pauses', telemetry.counts.active_pickup_pauses, telemetry.counts.active_pickup_pauses > 0],
                        ['Overloaded barangays', telemetry.counts.overloaded_barangays, telemetry.counts.overloaded_barangays > 0],
                    ].map(([label, count, accent]) => (
                        <div key={String(label)} className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs">
                            <p className="text-[11px] font-medium text-zinc-500">{label}</p>
                            <p className={`mt-1.5 text-2xl font-bold tabular-nums ${accent ? 'text-[#E00D42]' : 'text-zinc-900'}`}>
                                {count}
                            </p>
                        </div>
                    ))}
                </section>

                {/* Navigation Tabs & Quick Dry-Run */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200/90 bg-white p-3 shadow-xs">
                    <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Monitoring views">
                        {[
                            ['overview', 'All overview'],
                            ['pauses', `Force-Majeure pauses (${pauses.filter((p) => p.status === 'active').length})`],
                            ['clocks', `SLA clocks (${clocks.length})`],
                            ['dispatch', `Dispatch & pickup pauses (${telemetry.escalations.length})`],
                            ['investigations', `Investigations (${investigations.filter((i) => i.status === 'investigating').length} open)`],
                        ].map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                role="tab"
                                aria-selected={tab === key}
                                onClick={() => setTab(key as typeof tab)}
                                className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                    tab === key
                                        ? 'bg-[#E00D42] text-white shadow-2xs'
                                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <div className="flex items-center gap-2">
                        <Link href="/exceptions" className={outlineBtn}>
                            Delivery exceptions
                        </Link>
                        {scope.is_admin && (
                            <button type="button" disabled={busy} onClick={runDryPreview} className={outlineBtn}>
                                <Search className="h-3.5 w-3.5" />
                                Preview scan (dry-run)
                            </button>
                        )}
                    </div>
                </div>

                {dryRunError && (
                    <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs text-rose-800">{dryRunError}</p>
                )}

                {dryRunResult && (
                    <section className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs text-xs space-y-2">
                        <div className="flex items-center justify-between">
                            <h2 className="font-bold text-zinc-900">Read-only SLA Scan Dry-Run Summary (zero DB writes)</h2>
                            <button type="button" onClick={() => setDryRunResult(null)} className="text-zinc-400 hover:text-zinc-700 font-semibold">Dismiss</button>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            {Object.entries(dryRunResult).map(([key, val]) => (
                                typeof val === 'number' || typeof val === 'boolean' || typeof val === 'string' ? (
                                    <div key={key} className="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2">
                                        <p className="text-[11px] text-zinc-500">{key.replaceAll('_', ' ')}</p>
                                        <p className="font-bold text-zinc-900 tabular-nums">{String(val)}</p>
                                    </div>
                                ) : null
                            ))}
                        </div>
                    </section>
                )}

                {/* Force-Majeure SLA Pauses */}
                {(tab === 'overview' || tab === 'pauses') && (
                    <section className="grid gap-5 lg:grid-cols-12">
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs lg:col-span-5 space-y-4">
                            <div className="flex items-center gap-2">
                                <PauseCircle className="h-4 w-4 text-[#E00D42]" />
                                <h2 className="text-sm font-bold text-zinc-900">Activate Force-Majeure SLA pause</h2>
                            </div>
                            <p className="text-xs text-zinc-600 leading-relaxed">
                                Pauses matching running SLA clocks immediately, records audit events, and shifts delivery ETA windows when traffic resumes. Counter pickup claims retain immutable expiry timestamps while gaining paused holding seconds.
                            </p>

                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    submitPost('/governance/monitoring/pauses', {
                                        scope_type: pauseScope,
                                        scope_value: pauseScope === 'province' ? pauseProvince : null,
                                        logistics_company_id: pauseScope === 'company' ? Number(pauseCompanyId) : null,
                                        logistics_hub_id: pauseScope === 'hub' ? Number(pauseHubId) : null,
                                        reason_code: pauseReason,
                                        title: pauseTitle,
                                        notes: pauseNotes || null,
                                    }, () => {
                                        setPauseTitle('');
                                        setPauseNotes('');
                                    });
                                }}
                                className="space-y-3"
                            >
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <label className="text-xs font-semibold text-zinc-800">
                                        Scope
                                        <select value={pauseScope} onChange={(e) => setPauseScope(e.target.value)} className={inputClass}>
                                            {availableScopes.map((s) => (
                                                <option key={s} value={s}>{s.toUpperCase()}</option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="text-xs font-semibold text-zinc-800">
                                        Advisory reason
                                        <select value={pauseReason} onChange={(e) => setPauseReason(e.target.value)} className={inputClass}>
                                            {constants.reasons.map((r) => (
                                                <option key={r} value={r}>{r.replaceAll('_', ' ')}</option>
                                            ))}
                                        </select>
                                    </label>
                                </div>

                                {pauseScope === 'province' && (
                                    <label className="block text-xs font-semibold text-zinc-800">
                                        Affected province
                                        <input required value={pauseProvince} onChange={(e) => setPauseProvince(e.target.value)} placeholder="e.g. Laguna" className={inputClass} />
                                    </label>
                                )}

                                {pauseScope === 'company' && (
                                    <label className="block text-xs font-semibold text-zinc-800">
                                        Logistics company
                                        <select required value={pauseCompanyId} onChange={(e) => setPauseCompanyId(e.target.value)} className={inputClass}>
                                            <option value="">Select carrier</option>
                                            {companies.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.code})</option>)}
                                        </select>
                                    </label>
                                )}

                                {pauseScope === 'hub' && (
                                    <label className="block text-xs font-semibold text-zinc-800">
                                        Logistics hub
                                        <select required value={pauseHubId} onChange={(e) => setPauseHubId(e.target.value)} className={inputClass}>
                                            <option value="">Select facility</option>
                                            {hubs.map((h) => <option key={h.id} value={h.id}>{h.name} ({h.code})</option>)}
                                        </select>
                                    </label>
                                )}

                                <label className="block text-xs font-semibold text-zinc-800">
                                    Advisory title
                                    <input required minLength={3} value={pauseTitle} onChange={(e) => setPauseTitle(e.target.value)} placeholder="e.g. Typhoon Signal No. 3 — Calabarzon Corridor" className={inputClass} />
                                </label>

                                <label className="block text-xs font-semibold text-zinc-800">
                                    Operational notes
                                    <textarea rows={2} value={pauseNotes} onChange={(e) => setPauseNotes(e.target.value)} placeholder="Evidence / NDRRMC or carrier bulletin reference" className={inputClass} />
                                </label>

                                <button type="submit" disabled={busy || pauseTitle.trim().length < 3} className={primaryBtn}>
                                    Activate SLA pause
                                </button>
                            </form>
                        </div>

                        <div className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs lg:col-span-7 space-y-3">
                            <h2 className="text-sm font-bold text-zinc-900">Force-Majeure pause ledger</h2>
                            {!pauses.length ? (
                                <p className="text-xs text-zinc-500 py-6 text-center">No Force-Majeure pauses recorded yet.</p>
                            ) : (
                                <div className="divide-y divide-zinc-100">
                                    {pauses.map((pause) => (
                                        <div key={pause.id} className="py-3 flex flex-wrap items-start justify-between gap-3 text-xs">
                                            <div className="space-y-1 min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-bold text-zinc-900 tabular-nums">{pause.reference}</span>
                                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                        pause.status === 'active'
                                                            ? 'border border-amber-300 bg-amber-50 text-amber-900'
                                                            : 'border border-zinc-200 bg-zinc-50 text-zinc-600'
                                                    }`}>
                                                        {pause.status}
                                                    </span>
                                                    <span className="rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium text-zinc-700">
                                                        {pause.scope_type}
                                                        {pause.scope_value ? `: ${pause.scope_value}` : ''}
                                                        {pause.company ? `: ${pause.company.name}` : ''}
                                                        {pause.hub ? `: ${pause.hub.name}` : ''}
                                                    </span>
                                                </div>
                                                <p className="font-semibold text-zinc-800">{pause.title}</p>
                                                <p className="text-zinc-500">
                                                    Started {formatPht(pause.starts_at)} · Reason: {pause.reason_code.replaceAll('_', ' ')}
                                                    {pause.resumed_at ? ` · Resumed ${formatPht(pause.resumed_at)} (${Math.round(pause.accumulated_paused_seconds / 60)}m shifted)` : ''}
                                                </p>
                                            </div>
                                            {pause.status === 'active' && (
                                                <button
                                                    type="button"
                                                    disabled={busy}
                                                    onClick={() => submitPost(`/governance/monitoring/pauses/${pause.id}/resume`, {})}
                                                    className={outlineBtn}
                                                >
                                                    <PlayCircle className="h-3.5 w-3.5 text-[#E00D42]" />
                                                    Resume &amp; extend clocks
                                                </button>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>
                )}

                {/* SLA Clocks */}
                {(tab === 'overview' || tab === 'clocks') && (
                    <section className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <Clock className="h-4 w-4 text-[#E00D42]" />
                                <h2 className="text-sm font-bold text-zinc-900">Active &amp; paused SLA clocks</h2>
                            </div>
                            <span className="text-xs text-zinc-500 tabular-nums">{clocks.length} open</span>
                        </div>
                        {!clocks.length ? (
                            <p className="text-xs text-zinc-500 py-4 text-center">No active SLA clocks in scope.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead className="border-b border-zinc-200 bg-zinc-50/70 text-[11px] font-semibold text-zinc-500">
                                        <tr>
                                            <th className="px-3 py-2.5">Subject / Order</th>
                                            <th className="px-3 py-2.5">Clock kind</th>
                                            <th className="px-3 py-2.5">Facility</th>
                                            <th className="px-3 py-2.5">State</th>
                                            <th className="px-3 py-2.5">Breach threshold</th>
                                            <th className="px-3 py-2.5">Delivery ETA</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {clocks.map((clock) => (
                                            <tr key={clock.id} className="hover:bg-zinc-50/50">
                                                <td className="px-3 py-2.5">
                                                    <p className="font-bold text-zinc-900 tabular-nums">
                                                        {clock.delivery?.tracking_number ?? clock.order?.order_number ?? `${clock.subject_type} #${clock.subject_id}`}
                                                    </p>
                                                    {clock.order?.order_number && clock.delivery?.tracking_number && (
                                                        <p className="text-[11px] text-zinc-500">{clock.order.order_number}</p>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5 font-medium text-zinc-800">{clock.clock_kind.replaceAll('_', ' ')}</td>
                                                <td className="px-3 py-2.5 text-zinc-600">{clock.hub?.name ?? clock.company?.name ?? 'Network'}</td>
                                                <td className="px-3 py-2.5">
                                                    <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                                        clock.is_breached
                                                            ? 'border border-rose-300 bg-rose-50 text-[#E00D42]'
                                                            : clock.status === 'paused'
                                                                ? 'border border-amber-300 bg-amber-50 text-amber-900'
                                                                : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
                                                    }`}>
                                                        {clock.is_breached ? 'breached' : clock.pause_reason ? `${clock.status} (${clock.pause_reason})` : clock.status}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2.5 text-zinc-600 tabular-nums">{formatPht(clock.breach_at)}</td>
                                                <td className="px-3 py-2.5 text-zinc-700">{clock.delivery?.eta?.label ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {/* Dispatch Recovery & Hub Pickup Pauses */}
                {(tab === 'overview' || tab === 'dispatch') && (
                    <section className="grid gap-5 lg:grid-cols-12">
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs lg:col-span-5 space-y-4">
                            <div className="flex items-center gap-2">
                                <Truck className="h-4 w-4 text-[#E00D42]" />
                                <h2 className="text-sm font-bold text-zinc-900">Hub pickup pause controls</h2>
                            </div>
                            <p className="text-xs text-zinc-600">
                                Explicit hub or barangay pickup pauses block new checkout placements for affected origin hubs while preserving `no_available_rider` checkout tolerance elsewhere.
                            </p>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    submitPost('/governance/monitoring/pickup-pauses', {
                                        logistics_hub_id: Number(pickupPauseHubId),
                                        barangay: pickupPauseBarangay || null,
                                        reason: pickupPauseReason,
                                    }, () => {
                                        setPickupPauseBarangay('');
                                        setPickupPauseReason('');
                                    });
                                }}
                                className="space-y-3"
                            >
                                <label className="block text-xs font-semibold text-zinc-800">
                                    Origin Bayan Hub
                                    <select required value={pickupPauseHubId} onChange={(e) => setPickupPauseHubId(e.target.value)} className={inputClass}>
                                        <option value="">Select hub</option>
                                        {hubs.map((h) => <option key={h.id} value={h.id}>{h.name} ({h.code})</option>)}
                                    </select>
                                </label>
                                <label className="block text-xs font-semibold text-zinc-800">
                                    Barangay (leave empty for entire hub)
                                    <input value={pickupPauseBarangay} onChange={(e) => setPickupPauseBarangay(e.target.value)} placeholder="Optional barangay filter" className={inputClass} />
                                </label>
                                <label className="block text-xs font-semibold text-zinc-800">
                                    Operational reason
                                    <textarea required minLength={5} rows={2} value={pickupPauseReason} onChange={(e) => setPickupPauseReason(e.target.value)} placeholder="e.g. Severe road flooding blocking origin dispatch" className={inputClass} />
                                </label>
                                <button type="submit" disabled={busy || !pickupPauseHubId || pickupPauseReason.trim().length < 5} className={primaryBtn}>
                                    Pause hub pickup
                                </button>
                            </form>

                            {telemetry.pickup_pauses.length > 0 && (
                                <div className="border-t border-zinc-100 pt-3 space-y-2">
                                    <h3 className="text-xs font-bold text-zinc-900">Active pickup pauses</h3>
                                    {telemetry.pickup_pauses.map((pause) => (
                                        <div key={pause.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-rose-200 bg-rose-50/60 p-2.5 text-xs">
                                            <div>
                                                <p className="font-bold text-rose-950">{pause.reference} · {pause.hub?.name ?? `Hub #${pause.logistics_hub_id}`}</p>
                                                <p className="text-rose-900">{pause.barangay ? `Barangay ${pause.barangay}: ` : ''}{pause.reason}</p>
                                            </div>
                                            <button type="button" disabled={busy} onClick={() => submitPost(`/governance/monitoring/pickup-pauses/${pause.id}/resume`, {})} className={outlineBtn}>
                                                Resume
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs lg:col-span-7 space-y-3">
                            <h2 className="text-sm font-bold text-zinc-900">4-step first-mile dispatch escalations</h2>
                            {!telemetry.escalations.length ? (
                                <p className="text-xs text-zinc-500 py-6 text-center">No active pickup escalations in scope.</p>
                            ) : (
                                <div className="divide-y divide-zinc-100">
                                    {telemetry.escalations.map((esc) => (
                                        <div key={esc.id} className="py-3 space-y-1 text-xs">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <span className="font-bold text-zinc-900 tabular-nums">
                                                    {esc.delivery?.tracking_number ?? `Escalation #${esc.id}`}
                                                    {esc.order?.order_number ? ` · ${esc.order.order_number}` : ''}
                                                </span>
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    {esc.is_urgent && (
                                                        <span className="rounded-full border border-rose-300 bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-[#E00D42]">
                                                            Urgent
                                                        </span>
                                                    )}
                                                    <span className="rounded-full border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] font-semibold text-zinc-700">
                                                        {esc.current_tier.replaceAll('_', ' ')}
                                                    </span>
                                                </div>
                                            </div>
                                            {esc.dispatch_delay_label && <p className="font-semibold text-amber-900">{esc.dispatch_delay_label}</p>}
                                            {esc.surge_deferred_note && <p className="text-zinc-600">{esc.surge_deferred_note}</p>}
                                            <p className="text-zinc-500">
                                                Origin: {esc.origin_hub?.name ?? 'Hub'} · SLA started: {formatPht(esc.pickup_sla_started_at)}
                                                {esc.penalty_exempt ? ' · Penalty exempt' : ''}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </section>
                )}

                {/* Missing-Parcel Investigations */}
                {(tab === 'overview' || tab === 'investigations') && (
                    <section className="space-y-4">
                        <div className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs space-y-3">
                            <div className="flex items-center gap-2">
                                <ShieldAlert className="h-4 w-4 text-[#E00D42]" />
                                <h2 className="text-sm font-bold text-zinc-900">Open missing-parcel investigation</h2>
                            </div>
                            <p className="text-xs text-zinc-600">
                                Scan timeouts or manual reports open an <span className="font-semibold text-zinc-900">investigating</span> case without declaring loss or liability. Any real physical scan automatically resolves the case to <span className="font-semibold text-zinc-900">recovered</span>; only Platform Admins may confirm permanent loss.
                            </p>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    submitPost('/governance/monitoring/investigations', {
                                        delivery_id: Number(invDeliveryId),
                                        investigator_id: invInvestigatorId ? Number(invInvestigatorId) : null,
                                        summary: invSummary,
                                    }, () => setInvSummary(''));
                                }}
                                className="grid gap-3 sm:grid-cols-12 items-end"
                            >
                                <label className="text-xs font-semibold text-zinc-800 sm:col-span-3">
                                    Open parcel
                                    <select required value={invDeliveryId} onChange={(e) => setInvDeliveryId(e.target.value)} className={inputClass}>
                                        <option value="">Select parcel</option>
                                        {openDeliveries.map((d) => (
                                            <option key={d.id} value={d.id}>{d.tracking_number} ({d.status})</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="text-xs font-semibold text-zinc-800 sm:col-span-3">
                                    Initial investigator (optional)
                                    <select value={invInvestigatorId} onChange={(e) => setInvInvestigatorId(e.target.value)} className={inputClass}>
                                        <option value="">Unassigned</option>
                                        {investigators.map((u) => <option key={u.id} value={u.id}>{u.name} ({u.role})</option>)}
                                    </select>
                                </label>
                                <label className="text-xs font-semibold text-zinc-800 sm:col-span-4">
                                    Investigation summary (min 10 chars)
                                    <input required minLength={10} value={invSummary} onChange={(e) => setInvSummary(e.target.value)} placeholder="Describe last known facility / manifest discrepancy" className={inputClass} />
                                </label>
                                <div className="sm:col-span-2">
                                    <button type="submit" disabled={busy || !invDeliveryId || invSummary.trim().length < 10} className={`w-full ${primaryBtn}`}>
                                        Open case
                                    </button>
                                </div>
                            </form>
                        </div>

                        <div className="space-y-3">
                            {investigations.length === 0 ? (
                                <div className="rounded-xl border border-zinc-200/90 bg-white p-6 text-center text-xs text-zinc-500 shadow-xs">
                                    <CheckCircle2 className="mx-auto mb-2 h-5 w-5 text-emerald-600" />
                                    No missing-parcel investigations recorded in this scope.
                                </div>
                            ) : (
                                investigations.map((inv) => {
                                    const noteValue = invNotes[inv.id] ?? '';
                                    const isOpen = inv.status === 'investigating';
                                    return (
                                        <article key={inv.id} className="rounded-xl border border-zinc-200/90 bg-white p-5 shadow-xs space-y-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-bold text-zinc-900 tabular-nums">{inv.reference}</span>
                                                        <span className={`rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${
                                                            inv.status === 'investigating'
                                                                ? 'border border-amber-300 bg-amber-50 text-amber-900'
                                                                : inv.status === 'confirmed_lost'
                                                                    ? 'border border-rose-300 bg-rose-50 text-[#E00D42]'
                                                                    : 'border border-emerald-200 bg-emerald-50 text-emerald-800'
                                                        }`}>
                                                            {inv.status.replaceAll('_', ' ')}
                                                        </span>
                                                        <span className="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] font-medium text-zinc-700">
                                                            {inv.source_kind.replaceAll('_', ' ')}
                                                        </span>
                                                        <span className="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] font-medium text-zinc-600">
                                                            Insurer: {inv.insurer_funding_status}
                                                        </span>
                                                    </div>
                                                    <p className="text-xs font-semibold text-zinc-800">
                                                        Parcel {inv.delivery?.tracking_number ?? `#${inv.delivery?.id}`}
                                                        {inv.order?.order_number ? ` · Order ${inv.order.order_number}` : ''}
                                                        {inv.hub?.name ? ` · ${inv.hub.name}` : ''}
                                                    </p>
                                                    <p className="text-xs text-zinc-600">{inv.summary}</p>
                                                    <p className="text-[11px] text-zinc-500">
                                                        Opened {formatPht(inv.opened_at)} · Investigator: {inv.investigator?.name ?? 'Unassigned'} · Proves loss: {inv.proves_loss ? 'Yes' : 'No'} · Compensation: {inv.compensation_status}
                                                    </p>
                                                </div>
                                            </div>

                                            {inv.events && inv.events.length > 0 && (
                                                <div className="rounded-lg border border-zinc-100 bg-zinc-50/70 p-3 space-y-1.5">
                                                    <p className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Investigation log</p>
                                                    {inv.events.slice(0, 4).map((ev) => (
                                                        <p key={ev.id} className="text-xs text-zinc-700">
                                                            <span className="font-semibold">{ev.event_type.replaceAll('_', ' ')}:</span>{' '}
                                                            {ev.notes || 'Recorded'} <span className="text-zinc-400">({ev.actor?.name ?? 'System'} · {formatPht(ev.created_at)})</span>
                                                        </p>
                                                    ))}
                                                </div>
                                            )}

                                            {isOpen && (
                                                <div className="border-t border-zinc-100 pt-3 space-y-3">
                                                    <div className="grid gap-2.5 sm:grid-cols-12 items-end">
                                                        <label className="text-xs font-semibold text-zinc-800 sm:col-span-5">
                                                            Finding / resolution notes (min 5 chars)
                                                            <input
                                                                value={noteValue}
                                                                onChange={(e) => setInvNotes((cur) => ({ ...cur, [inv.id]: e.target.value }))}
                                                                placeholder="Enter physical bay check or resolution evidence"
                                                                className={inputClass}
                                                            />
                                                        </label>
                                                        <label className="text-xs font-semibold text-zinc-800 sm:col-span-4">
                                                            Assign investigator
                                                            <select
                                                                value={invAssignUser[inv.id] ?? ''}
                                                                onChange={(e) => setInvAssignUser((cur) => ({ ...cur, [inv.id]: e.target.value }))}
                                                                className={inputClass}
                                                            >
                                                                <option value="">Choose operator</option>
                                                                {investigators.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                                            </select>
                                                        </label>
                                                        <div className="sm:col-span-3 flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                disabled={busy || !invAssignUser[inv.id]}
                                                                onClick={() => submitPost(`/governance/monitoring/investigations/${inv.id}/assign`, {
                                                                    investigator_id: Number(invAssignUser[inv.id]),
                                                                    notes: noteValue,
                                                                })}
                                                                className={outlineBtn}
                                                            >
                                                                Assign
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled={busy || noteValue.trim().length < 5}
                                                                onClick={() => submitPost(`/governance/monitoring/investigations/${inv.id}/finding`, { notes: noteValue }, () => setInvNotes((cur) => ({ ...cur, [inv.id]: '' })))}
                                                                className={outlineBtn}
                                                            >
                                                                Log finding
                                                            </button>
                                                        </div>
                                                    </div>

                                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                                        <div className="flex flex-wrap gap-2">
                                                            <button
                                                                type="button"
                                                                disabled={busy || noteValue.trim().length < 5}
                                                                onClick={() => submitPost(`/governance/monitoring/investigations/${inv.id}/recover`, { notes: noteValue })}
                                                                className={outlineBtn}
                                                            >
                                                                Mark recovered
                                                            </button>
                                                            <button
                                                                type="button"
                                                                disabled={busy || noteValue.trim().length < 5}
                                                                onClick={() => submitPost(`/governance/monitoring/investigations/${inv.id}/close`, { notes: noteValue })}
                                                                className={outlineBtn}
                                                            >
                                                                Close unfounded
                                                            </button>
                                                        </div>

                                                        {scope.is_admin && (
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <select
                                                                    value={invPlanId[inv.id] ?? ''}
                                                                    onChange={(e) => setInvPlanId((cur) => ({ ...cur, [inv.id]: e.target.value }))}
                                                                    aria-label="Optional financial plan"
                                                                    className="rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-xs text-zinc-800"
                                                                >
                                                                    <option value="">Optional financial plan link</option>
                                                                    {financialPlans.map((fp) => (
                                                                        <option key={fp.id} value={fp.id}>{fp.reference.slice(0, 8)}… (Order #{fp.order_id ?? 'N/A'})</option>
                                                                    ))}
                                                                </select>
                                                                <button
                                                                    type="button"
                                                                    disabled={busy || noteValue.trim().length < 5}
                                                                    onClick={() => submitPost(`/governance/monitoring/investigations/${inv.id}/confirm-loss`, {
                                                                        notes: noteValue,
                                                                        shipment_financial_plan_id: invPlanId[inv.id] ? Number(invPlanId[inv.id]) : null,
                                                                    })}
                                                                    className={primaryBtn}
                                                                >
                                                                    <AlertTriangle className="h-3.5 w-3.5" />
                                                                    Confirm permanent loss
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                        </article>
                                    );
                                })
                            )}
                        </div>
                    </section>
                )}
            </div>
        </DashboardLayout>
    );
}
