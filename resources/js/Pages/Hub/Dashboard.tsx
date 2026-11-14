import React from 'react';
import { Head, router } from '@inertiajs/react';
import PortalLink from '@/Components/PortalLink';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    ChevronRight,
    MapPin,
    Package,
    ScanLine,
    Send,
    Store,
    Truck,
} from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubPageHeader, hubButton, hubPrimary } from '@/Components/Logistics/Workspace';

interface HubOption {
    id: number;
    name: string;
    code: string;
    tier: string;
}

interface DashboardProps {
    scope: {
        mode: 'company' | 'hub';
        company_name: string;
        company_code: string | null;
        selected_hub_id: number | null;
        hubs: HubOption[];
        can_view_company: boolean;
        can_scan: boolean;
    };
    stats: {
        parcels_in_custody: number;
        ready_for_dispatch: number;
        exceptions: number;
        dispatched_today: number;
    };
    attentionQueue: Array<{
        key: string;
        label: string;
        count: number;
        href: string;
        tone: 'danger' | 'warning' | 'info' | 'neutral';
    }>;
    movement: Array<{
        date: string;
        label: string;
        inbound: number;
        outbound: number;
    }>;
    parcelFlow: {
        origin_hub: number;
        mother_hub_transit: number;
        destination_hub: number;
        final_mile: number;
        counter_pickup: number;
    };
    facilities: Array<{
        id: number;
        name: string;
        code: string;
        tier: string;
        city_municipality: string;
        parcels: number;
        exceptions: number;
        active_fleet: number;
        capacity: number;
        utilization_rate: number;
    }>;
    recentActivity: Array<{
        id: number;
        tracking_number: string;
        event: string;
        facility: string;
        operator: string;
        timestamp: string;
        relative_time: string;
    }>;
    monitoring?: {
        counts: {
            running_clocks: number;
            paused_clocks: number;
            breached_clocks: number;
            stalled_hub_alerts: number;
            urgent_pickups: number;
            active_pickup_pauses: number;
            overloaded_barangays: number;
        };
        stalled_hub_alerts: Array<{ id: number; clock_kind: string; delivery?: { tracking_number: string } | null; hub?: { name: string } | null }>;
        escalations: Array<{ id: number; current_tier: string; is_urgent: boolean; dispatch_delay_label: string | null; delivery?: { tracking_number: string } | null }>;
        pickup_pauses: Array<{ id: number; reference: string; barangay: string | null; reason: string; hub?: { name: string } | null }>;
        overload_alerts: Array<{ hub_id: number; hub_name: string; barangay: string; parcel_count: number; utilization_pct?: number }>;
    } | null;
    activeSlaPauses?: Array<{
        id: number;
        reference: string;
        title: string;
        reason_code: string;
        scope_type: string;
        scope_value: string | null;
    }>;
    openInvestigationsCount?: number;
}

const toneClasses = {
    danger: 'border-rose-200 bg-rose-50 text-[#E00D42]',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-zinc-200 bg-zinc-100 text-zinc-800',
    neutral: 'border-zinc-200 bg-zinc-50 text-zinc-700',
};

const formatEvent = (event: string) =>
    event
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatTier = (tier: string) => (tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub');

export default function HubDashboard({
    scope,
    stats,
    attentionQueue,
    movement,
    parcelFlow,
    facilities,
    recentActivity,
    monitoring,
    activeSlaPauses = [],
    openInvestigationsCount = 0,
}: DashboardProps) {
    const activeAttention = attentionQueue.filter((item) => item.count > 0);
    const selectedHub = scope.hubs.find((hub) => hub.id === scope.selected_hub_id);
    const maxMovement = Math.max(1, ...movement.flatMap((day) => [day.inbound, day.outbound]));
    const hasMovement = movement.some((day) => day.inbound > 0 || day.outbound > 0);

    const changeScope = (value: string) => {
        router.get(
            route('hub.dashboard'),
            value ? { hub_id: Number(value) } : {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const metrics = [
        {
            label: 'Parcels in custody',
            value: stats.parcels_in_custody,
            helper: scope.mode === 'company' ? 'Across the network' : selectedHub?.code ?? 'Selected facility',
            icon: Package,
            accent: false,
        },
        {
            label: 'Ready for dispatch',
            value: stats.ready_for_dispatch,
            helper: 'Sorted or assigned',
            icon: Send,
            accent: false,
        },
        {
            label: 'Needs attention',
            value: stats.exceptions,
            helper: 'Failed or returning',
            icon: AlertTriangle,
            accent: stats.exceptions > 0,
        },
        {
            label: 'Dispatched today',
            value: stats.dispatched_today,
            helper: 'Verified outbound scans',
            icon: Truck,
            accent: stats.dispatched_today > 0,
        },
    ];

    const flowStages = [
        { step: '01', label: 'Origin Bayan Hub', value: parcelFlow.origin_hub, icon: Building2, href: route('hub.deliveries', { status: 'arrived_at_origin_hub' }) },
        { step: '02', label: 'Mother Hub route', value: parcelFlow.mother_hub_transit, icon: Truck, href: route('hub.deliveries', { status: 'arrived_at_mother_hub' }) },
        { step: '03', label: 'Destination Bayan Hub', value: parcelFlow.destination_hub, icon: MapPin, href: route('hub.deliveries', { status: 'arrived_at_destination_hub' }) },
        { step: '04', label: 'Counter pickup branch', value: parcelFlow.counter_pickup, icon: Store, href: route('hub.counter') },
        { step: '05', label: 'Final-mile delivery', value: parcelFlow.final_mile, icon: Send, href: route('hub.deliveries', { status: 'out_for_delivery' }) },
    ];

    return (
        <DashboardLayout
            title="Operations Overview"
            subtitle={
                <span className="flex items-center gap-1.5 text-xs text-zinc-600">
                    <span className="font-semibold text-[#121316]">{scope.company_name}</span>
                    {scope.company_code && <span className="text-zinc-400">· {scope.company_code}</span>}
                </span>
            }
            actions={
                <div className="flex items-center gap-2">
                    <PortalLink href={route('hub.counter')} className={`hidden sm:inline-flex ${hubButton}`}>
                        <Store className="h-3.5 w-3.5 text-zinc-500" />
                        Counter pickup
                    </PortalLink>
                    <PortalLink href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')} className={hubPrimary}>
                        {scope.can_scan ? <ScanLine className="h-3.5 w-3.5" /> : <Package className="h-3.5 w-3.5" />}
                        {scope.can_scan ? 'Scan parcel' : 'View parcels'}
                    </PortalLink>
                </div>
            }
        >
            <Head title="Operations Overview — BagooPH" />

            <div className="space-y-5 font-sans">
                {/* Editorial Workspace Header with Integrated Facility Scope Selector */}
                <HubPageHeader
                    title="Operations overview"
                    description="Real-time parcel custody, dispatch readiness, and station telemetry for the active facility scope."
                    icon={Building2}
                    actions={
                        <div className="flex flex-wrap items-center gap-2.5">
                            <label className="flex items-center gap-2 rounded-lg border border-zinc-200/90 bg-white px-3 py-1.5 text-xs font-medium text-zinc-600 shadow-2xs">
                                <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400">Scope</span>
                                {scope.can_view_company ? (
                                    <select
                                        value={scope.selected_hub_id ?? ''}
                                        onChange={(event) => changeScope(event.target.value)}
                                        className="border-0 bg-transparent py-0.5 pl-1 pr-7 text-xs font-semibold text-[#121316] focus:ring-0 cursor-pointer"
                                    >
                                        <option value="">All facilities ({scope.company_name})</option>
                                        {scope.hubs.map((hub) => (
                                            <option key={hub.id} value={hub.id}>
                                                {hub.name} · {hub.code}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <span className="font-semibold text-[#121316]">
                                        {selectedHub ? `${selectedHub.name} (${selectedHub.code})` : 'Assigned facility'}
                                    </span>
                                )}
                            </label>
                            <PortalLink
                                href={activeAttention[0]?.href ?? (scope.can_scan ? route('hub.scan.station') : route('hub.network'))}
                                className={hubPrimary}
                            >
                                {activeAttention.length ? (
                                    <>
                                        <AlertTriangle className="h-3.5 w-3.5" />
                                        <span>Review {activeAttention[0].count} attention {activeAttention[0].count === 1 ? 'item' : 'items'}</span>
                                    </>
                                ) : (
                                    <>
                                        <ScanLine className="h-3.5 w-3.5" />
                                        <span>{scope.can_scan ? 'Open scan station' : 'View hub network'}</span>
                                    </>
                                )}
                                <ChevronRight className="h-3.5 w-3.5" />
                            </PortalLink>
                        </div>
                    }
                />

                {/* Unified Architectural 4-Column Metric Strip */}
                <section
                    aria-label="Operational metrics"
                    className="grid grid-cols-1 divide-y divide-zinc-200/80 overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs sm:grid-cols-2 sm:divide-y-0 sm:divide-x xl:grid-cols-4"
                >
                    {metrics.map((metric) => {
                        const Icon = metric.icon;
                        return (
                            <div key={metric.label} className="flex flex-col justify-between p-5 transition-colors hover:bg-zinc-50/50">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-xs font-medium text-zinc-500">{metric.label}</span>
                                    <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-rose-200/80 bg-rose-50/70 text-[#E00D42]">
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                </div>
                                <div className="mt-3 flex items-baseline justify-between gap-2">
                                    <p
                                        className={`text-3xl font-bold tracking-tight tabular-nums ${
                                            metric.accent ? 'text-[#E00D42]' : 'text-[#121316]'
                                        }`}
                                    >
                                        {metric.value}
                                    </p>
                                    <span className="truncate text-[11px] font-medium text-zinc-400">{metric.helper}</span>
                                </div>
                            </div>
                        );
                    })}
                </section>

                {(activeSlaPauses.length > 0 || monitoring) && (
                    <section className="rounded-xl border border-zinc-200/90 bg-white p-4 shadow-xs space-y-3" aria-label="SLA & Dispatch Telemetry">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h2 className="text-sm font-semibold text-[#121316]">SLA clocks, recovery &amp; area density</h2>
                                <p className="text-xs text-zinc-500">Live threshold monitoring, urgent seller pickups, overloaded barangays, and Force-Majeure advisories</p>
                            </div>
                            <PortalLink href="/governance/monitoring" className={hubButton}>
                                Open SLA monitoring
                            </PortalLink>
                        </div>

                        {activeSlaPauses.length > 0 && (
                            <div className="rounded-lg border border-amber-200 bg-amber-50/90 p-3 text-xs text-amber-900 space-y-1">
                                <p className="font-bold">Active Force-Majeure SLA pause affecting this scope:</p>
                                {activeSlaPauses.map((pause) => (
                                    <p key={pause.id}>
                                        {pause.reference} · {pause.title} ({pause.reason_code.replaceAll('_', ' ')})
                                    </p>
                                ))}
                            </div>
                        )}

                        {monitoring && (
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                {[
                                    ['Breached SLA clocks', monitoring.counts.breached_clocks],
                                    ['Stalled hub alerts', monitoring.counts.stalled_hub_alerts],
                                    ['Urgent pickups', monitoring.counts.urgent_pickups],
                                    ['Overloaded barangays', monitoring.counts.overloaded_barangays],
                                    ['Open investigations', openInvestigationsCount],
                                ].map(([label, value]) => (
                                    <div key={String(label)} className="rounded-lg border border-zinc-200/80 bg-zinc-50/60 px-3.5 py-2.5">
                                        <p className="text-[11px] font-medium text-zinc-500">{label}</p>
                                        <p className={`mt-1 text-xl font-bold tabular-nums ${Number(value) > 0 ? 'text-[#E00D42]' : 'text-[#121316]'}`}>{value}</p>
                                    </div>
                                ))}
                            </div>
                        )}

                        {monitoring && monitoring.pickup_pauses.length > 0 && (
                            <div className="rounded-lg border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-950 space-y-2">
                                <p className="font-bold">Active hub pickup pauses:</p>
                                {monitoring.pickup_pauses.map((pause) => (
                                    <div key={pause.id} className="flex flex-wrap items-center justify-between gap-2">
                                        <span>{pause.reference} · {pause.hub?.name ?? 'Hub'} {pause.barangay ? `(${pause.barangay})` : '(All barangays)'}: {pause.reason}</span>
                                        <button
                                            type="button"
                                            onClick={() => router.post(`/governance/monitoring/pickup-pauses/${pause.id}/resume`, {}, { preserveScroll: true })}
                                            className="rounded-md border border-rose-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-[#C20836] hover:bg-rose-50"
                                        >
                                            Resume pickup
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        {monitoring && monitoring.overload_alerts.length > 0 && (
                            <div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs text-zinc-700">
                                <p className="font-semibold text-zinc-900 mb-1">High-density barangay zones (&gt;= 40 active parcels):</p>
                                <div className="flex flex-wrap gap-2">
                                    {monitoring.overload_alerts.map((row, idx) => (
                                        <span key={`${row.hub_id}-${row.barangay}-${idx}`} className="rounded-md border border-zinc-200 bg-white px-2.5 py-1 font-medium">
                                            {row.hub_name}: {row.barangay} ({row.parcel_count} parcels)
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}
                    </section>
                )}

                {/* Above-the-Fold Network Custody Pipeline (Parcel Journey) */}
                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                        <div className="flex items-center gap-2.5">
                            <h2 className="text-sm font-semibold text-[#121316]">Parcel journey pipeline</h2>
                            <span className="hidden sm:inline text-xs text-zinc-400">·</span>
                            <p className="hidden sm:block text-xs text-zinc-500">Live physical custody across the hub hierarchy</p>
                        </div>
                        <PortalLink
                            href={route('hub.deliveries')}
                            className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50/80 px-3 py-1.5 text-xs font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] transition-all shadow-2xs"
                        >
                            <span>View all waybills</span>
                            <ArrowRight className="h-3.5 w-3.5" />
                        </PortalLink>
                    </div>

                    <div className="grid grid-cols-1 divide-y divide-zinc-100 sm:grid-cols-2 sm:divide-y-0 sm:divide-x lg:grid-cols-5">
                        {flowStages.map((stage) => {
                            const Icon = stage.icon;
                            return (
                                <PortalLink
                                    key={stage.label}
                                    href={stage.href}
                                    className="group flex items-center justify-between gap-3 p-4 transition-all hover:bg-rose-50/40 sm:flex-col sm:items-start sm:justify-between"
                                >
                                    <div className="flex items-center gap-2.5 sm:w-full sm:justify-between">
                                        <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-400 group-hover:text-[#C20836]">Stage {stage.step}</span>
                                        <span className="flex h-7 w-7 items-center justify-center rounded-md border border-rose-200/80 bg-rose-50/70 text-[#E00D42] transition-colors group-hover:border-[#E00D42] group-hover:bg-[#E00D42] group-hover:text-white">
                                            <Icon className="h-3.5 w-3.5" />
                                        </span>
                                    </div>
                                    <div className="sm:mt-3">
                                        <p className="text-2xl font-bold tracking-tight tabular-nums text-[#121316] group-hover:text-[#E00D42] transition-colors">
                                            {stage.value}
                                        </p>
                                        <p className="mt-0.5 text-xs font-medium text-zinc-600 group-hover:text-[#121316]">
                                            {stage.label}
                                        </p>
                                    </div>
                                </PortalLink>
                            );
                        })}
                    </div>
                </section>

                {/* Two-Column Operational Command Grid */}
                <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
                    {/* Left Column (7 cols): 7-Day Telemetry + Facilities Capacity Table */}
                    <div className="space-y-5 xl:col-span-7">
                        {/* 7-Day Parcel Movement */}
                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#121316]">Parcel movement</h2>
                                    <p className="text-xs text-zinc-500">Verified inbound and outbound scans · last 7 days</p>
                                </div>
                                <div className="flex items-center gap-3 text-xs font-medium text-zinc-600">
                                    <span className="flex items-center gap-1.5">
                                        <span className="h-2.5 w-2.5 rounded-xs bg-rose-300" />
                                        Inbound
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <span className="h-2.5 w-2.5 rounded-xs bg-[#E00D42]" />
                                        Outbound
                                    </span>
                                </div>
                            </div>

                            {hasMovement ? (
                                <div className="flex h-48 items-end gap-2 px-5 pb-4 pt-6 sm:gap-3.5">
                                    {movement.map((day) => (
                                        <div key={day.date} className="flex h-full min-w-0 flex-1 flex-col justify-end">
                                            <div className="flex flex-1 items-end justify-center gap-1">
                                                <div
                                                    className="w-3 rounded-t-xs bg-rose-300 sm:w-4 transition-all hover:opacity-80"
                                                    style={{ height: `${Math.max(day.inbound > 0 ? 10 : 2, (day.inbound / maxMovement) * 100)}%` }}
                                                    title={`${day.date}: ${day.inbound} inbound`}
                                                />
                                                <div
                                                    className="w-3 rounded-t-xs bg-[#E00D42] sm:w-4 transition-all hover:opacity-80"
                                                    style={{ height: `${Math.max(day.outbound > 0 ? 10 : 2, (day.outbound / maxMovement) * 100)}%` }}
                                                    title={`${day.date}: ${day.outbound} outbound`}
                                                />
                                            </div>
                                            <div className="mt-2.5 border-t border-zinc-100 pt-2 text-center">
                                                <p className="text-[11px] font-semibold text-zinc-600">{day.label}</p>
                                                <p className="text-[10px] font-medium tabular-nums text-zinc-400">
                                                    {day.inbound}/{day.outbound}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="flex items-center justify-between gap-4 px-5 py-6">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-rose-50 text-[#E00D42]">
                                            <ScanLine className="h-4 w-4" />
                                        </span>
                                        <div>
                                            <p className="text-xs font-semibold text-[#121316]">No parcel movement this week</p>
                                            <p className="text-xs text-zinc-500">Inbound and outbound scans will populate this 7-day chart.</p>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </section>

                        {/* Facilities Capacity & Load */}
                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#121316]">Facilities</h2>
                                    <p className="text-xs text-zinc-500">Capacity and operational load</p>
                                </div>
                                <PortalLink
                                    href={route('hub.network')}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50/80 px-3 py-1.5 text-xs font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] transition-all shadow-2xs"
                                >
                                    <span>Facility network</span>
                                    <ArrowRight className="h-3.5 w-3.5" />
                                </PortalLink>
                            </div>

                            {facilities.length === 0 ? (
                                <div className="px-5 py-8 text-center text-xs text-zinc-500">No accessible facilities in this scope.</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-zinc-100 bg-zinc-50/60 text-[11px] font-semibold text-zinc-500">
                                                <th className="px-5 py-3">Facility</th>
                                                <th className="px-4 py-3">Tier</th>
                                                <th className="px-4 py-3 text-right">Custody</th>
                                                <th className="px-4 py-3 text-right">Exceptions</th>
                                                <th className="px-4 py-3 text-right">Fleet</th>
                                                <th className="px-5 py-3 text-right">Utilization</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-zinc-100">
                                            {facilities.map((facility) => (
                                                <tr key={facility.id} className="transition-colors hover:bg-zinc-50/60">
                                                    <td className="px-5 py-3">
                                                        <p className="font-semibold text-[#121316]">{facility.name}</p>
                                                        <p className="text-[11px] font-medium text-zinc-400">
                                                            {facility.code} · {facility.city_municipality}
                                                        </p>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span className="inline-flex rounded-md border border-rose-200/80 bg-rose-50/60 px-2 py-0.5 text-[11px] font-semibold text-[#C20836]">
                                                            {formatTier(facility.tier)}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3 text-right font-semibold tabular-nums text-[#121316]">
                                                        {facility.parcels}
                                                    </td>
                                                    <td
                                                        className={`px-4 py-3 text-right font-semibold tabular-nums ${
                                                            facility.exceptions > 0 ? 'text-[#E00D42]' : 'text-zinc-500'
                                                        }`}
                                                    >
                                                        {facility.exceptions}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-zinc-600">{facility.active_fleet}</td>
                                                    <td className="px-5 py-3 text-right">
                                                        <span className="font-semibold tabular-nums text-[#121316]">
                                                            {facility.utilization_rate}%
                                                        </span>
                                                        <span className="ml-1 text-[11px] text-zinc-400">of {facility.capacity}</span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>
                    </div>

                    {/* Right Column (5 cols): Attention Worklist + Recent Scan Activity */}
                    <div className="space-y-5 xl:col-span-5">
                        {/* Needs Attention Worklist */}
                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#121316]">Needs attention</h2>
                                    <p className="text-xs text-zinc-500">Ready for the next handover &amp; open work</p>
                                </div>
                                <span className="rounded-md border border-rose-200/80 bg-rose-50/70 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-[#C20836]">
                                    {activeAttention.reduce((acc, item) => acc + item.count, 0)} open
                                </span>
                            </div>

                            {activeAttention.length === 0 ? (
                                <div className="flex items-center justify-between gap-3 px-5 py-5">
                                    <div className="flex items-center gap-3">
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-600">
                                            <CheckCircle2 className="h-4 w-4" />
                                        </span>
                                        <div>
                                            <p className="text-xs font-semibold text-[#121316]">No urgent operational work</p>
                                            <p className="text-xs text-zinc-500">No attention items are recorded in this view.</p>
                                        </div>
                                    </div>
                                    {scope.can_scan && (
                                        <PortalLink href={route('hub.scan.station')} className={hubButton}>
                                            Scan station
                                        </PortalLink>
                                    )}
                                </div>
                            ) : (
                                <div className="divide-y divide-zinc-100">
                                    {activeAttention.map((item) => (
                                        <PortalLink
                                            key={item.key}
                                            href={item.href}
                                            className="flex items-center justify-between gap-3 px-5 py-3.5 transition-colors hover:bg-zinc-50/80"
                                        >
                                            <div className="flex min-w-0 items-center gap-3">
                                                <span
                                                    className={`flex h-7 min-w-7 items-center justify-center rounded-md border px-1.5 text-xs font-bold tabular-nums ${toneClasses[item.tone]}`}
                                                >
                                                    {item.count}
                                                </span>
                                                <span className="truncate text-xs font-medium text-zinc-800">{item.label}</span>
                                            </div>
                                            <ChevronRight className="h-4 w-4 shrink-0 text-zinc-400" />
                                        </PortalLink>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* Recent Scan Activity */}
                        <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                            <div className="flex items-center justify-between border-b border-zinc-100 bg-zinc-50/40 px-5 py-3.5">
                                <div>
                                    <h2 className="text-sm font-semibold text-[#121316]">Recent scan activity</h2>
                                    <p className="text-xs text-zinc-500">Latest verified custody events</p>
                                </div>
                                <PortalLink
                                    href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')}
                                    className="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-rose-50/80 px-2.5 py-1 text-xs font-semibold text-[#C20836] hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] transition-all shadow-2xs"
                                >
                                    {scope.can_scan ? 'Open scanner' : 'View parcels'}
                                </PortalLink>
                            </div>

                            {recentActivity.length === 0 ? (
                                <div className="px-5 py-8 text-center text-xs text-zinc-500">No scans recorded in this scope.</div>
                            ) : (
                                <div className="divide-y divide-zinc-100">
                                    {recentActivity.map((activity) => (
                                        <div
                                            key={activity.id}
                                            className="flex items-center justify-between gap-3 px-5 py-3 text-xs transition-colors hover:bg-zinc-50/60"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-[#E00D42]">
                                                        {activity.tracking_number}
                                                    </span>
                                                    <span className="truncate font-semibold text-[#121316]">
                                                        {formatEvent(activity.event)}
                                                    </span>
                                                </div>
                                                <p className="mt-1 truncate text-[11px] text-zinc-500">
                                                    {activity.facility} · by {activity.operator}
                                                </p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className="font-medium text-zinc-700">{activity.relative_time}</p>
                                                <p className="text-[10px] tabular-nums text-zinc-400">{activity.timestamp}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
}
