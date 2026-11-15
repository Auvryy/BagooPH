import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PortalLink from '@/Components/PortalLink';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    ChevronRight,
    Package,
    ScanLine,
    Store,
} from 'lucide-react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
    HubCategoryTabs,
    HubPageHeader,
    hubButton,
    hubInput,
    hubPrimary,
} from '@/Components/Logistics/Workspace';

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
    danger: 'border-red-200 bg-red-50 text-red-700',
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
    facilities,
    recentActivity,
}: DashboardProps) {
    const [activeCategory, setActiveCategory] = useState<'all' | 'urgent' | 'pipeline' | 'facilities'>('all');
    const activeAttention = attentionQueue.filter((item) => item.count > 0);
    const selectedHub = scope.hubs.find((hub) => hub.id === scope.selected_hub_id);
    const maxMovement = Math.max(1, ...movement.flatMap((day) => [day.inbound, day.outbound]));
    const hasMovement = movement.some((day) => day.inbound > 0 || day.outbound > 0);
    const totalAttentionCount = activeAttention.reduce((acc, item) => acc + item.count, 0);

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
            accent: false,
        },
        {
            label: 'Ready for dispatch',
            value: stats.ready_for_dispatch,
            accent: false,
        },
        {
            label: 'Needs attention',
            value: stats.exceptions,
            accent: stats.exceptions > 0,
        },
        {
            label: 'Dispatched today',
            value: stats.dispatched_today,
            accent: false,
        },
    ];

    return (
        <DashboardLayout
            title="Operations Overview"
            subtitle={
                <span className="flex items-center gap-1.5 text-xs text-zinc-600">
                    <span className="font-semibold text-ink-900">{scope.company_name}</span>
                    {scope.company_code && <span className="text-zinc-400">· {scope.company_code}</span>}
                </span>
            }
            actions={
                <div className="flex items-center gap-2">
                    <PortalLink href={route('hub.counter')} className={`hidden sm:inline-flex ${hubButton}`}>
                        <Store className="h-4 w-4 text-ink-500" aria-hidden="true" />
                        Counter pickup
                    </PortalLink>
                    <PortalLink href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')} className={hubPrimary}>
                        {scope.can_scan ? <ScanLine className="h-4 w-4" aria-hidden="true" /> : <Package className="h-4 w-4" aria-hidden="true" />}
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
                    roleLabel={scope.can_view_company ? `Company admin · ${scope.company_name}` : 'Hub operator'}
                    icon={Building2}
                    actions={
                        <div className="flex flex-wrap items-center gap-2.5">
                            <label className="flex items-center gap-2 text-sm text-ink-600">
                                <span>Showing</span>
                                {scope.can_view_company ? (
                                    <select
                                        value={scope.selected_hub_id ?? ''}
                                        onChange={(event) => changeScope(event.target.value)}
                                        className={`${hubInput} w-auto min-w-[220px] cursor-pointer font-medium`}
                                    >
                                        <option value="">All facilities ({scope.company_name})</option>
                                        {scope.hubs.map((hub) => (
                                            <option key={hub.id} value={hub.id}>
                                                {hub.name} · {hub.code}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <span className="font-semibold text-ink-900">
                                        {selectedHub ? `${selectedHub.name} (${selectedHub.code})` : 'Assigned facility'}
                                    </span>
                                )}
                            </label>
                            {activeAttention.length > 0 && (
                                <PortalLink href={activeAttention[0].href} className={hubButton}>
                                    <AlertTriangle className="h-4 w-4 text-warning" aria-hidden="true" />
                                    <span>Review {activeAttention[0].count} attention {activeAttention[0].count === 1 ? 'item' : 'items'}</span>
                                    <ChevronRight className="h-4 w-4" aria-hidden="true" />
                                </PortalLink>
                            )}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <HubCategoryTabs
                        label="Operations sections"
                        activeId={activeCategory}
                        onChange={(id) => setActiveCategory(id as typeof activeCategory)}
                        tabs={[
                            { id: 'all', label: 'Everything' },
                            { id: 'urgent', label: 'Needs action', count: totalAttentionCount },
                            { id: 'pipeline', label: 'Parcel movement' },
                            { id: 'facilities', label: 'Facilities', count: facilities.length },
                        ]}
                    />
                </div>

                {/* Unified Architectural 4-Column Metric Strip */}
                <section
                    aria-label="Operational metrics"
                    className="grid grid-cols-1 divide-y divide-line-200 overflow-hidden rounded-card border border-line-200 bg-white sm:grid-cols-2 sm:divide-y-0 sm:divide-x xl:grid-cols-4"
                >
                    {metrics.map((metric) => (
                        <div key={metric.label} className="p-5">
                            <p className="text-[13px] text-ink-600">{metric.label}</p>
                            <p className={`mt-1 text-[28px] font-bold leading-9 tabular-nums ${metric.accent ? 'text-warning' : 'text-ink-900'}`}>
                                {metric.value}
                            </p>
                        </div>
                    ))}
                </section>

                {/* Two-Column Operational Command Grid */}
                <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
                    {/* Left Column (7 cols): 7-Day Telemetry + Facilities Capacity Table */}
                    {(activeCategory === 'all' || activeCategory === 'pipeline' || activeCategory === 'facilities') && (
                        <div className="space-y-5 xl:col-span-7">
                            {/* 7-Day Parcel Movement */}
                            {(activeCategory === 'all' || activeCategory === 'pipeline') && (
                                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                                    <div className="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 pb-3">
                                        <div>
                                            <h2 className="text-base font-semibold text-ink-900">Parcel movement <span className="font-normal text-ink-500">· last 7 days</span></h2>
                                        </div>
                                        <div className="flex items-center gap-3 text-xs font-medium text-zinc-600">
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-2.5 w-2.5 rounded-xs bg-zinc-300" />
                                                Inbound
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                <span className="h-2.5 w-2.5 rounded-xs bg-brand-500" />
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
                                                            className="w-3 rounded-t-xs bg-zinc-300 sm:w-4 transition-all hover:opacity-80"
                                                            style={{ height: `${Math.max(day.inbound > 0 ? 10 : 2, (day.inbound / maxMovement) * 100)}%` }}
                                                            title={`${day.date}: ${day.inbound} inbound`}
                                                        />
                                                        <div
                                                            className="w-3 rounded-t-xs bg-brand-500 sm:w-4 transition-all hover:opacity-80"
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
                                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-100 text-ink-500">
                                                    <ScanLine className="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <p className="text-sm font-medium text-ink-900">No parcel movement this week</p>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </section>
                            )}

                            {/* Facilities Capacity & Load */}
                            {(activeCategory === 'all' || activeCategory === 'facilities') && (
                                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                                    <div className="flex items-center justify-between px-5 pt-5 pb-3">
                                        <div>
                                            <h2 className="text-base font-semibold text-ink-900">Facilities</h2>
                                        </div>
                                        <PortalLink
                                            href={route('hub.network')}
                                            className={hubButton}
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
                                                                <p className="font-semibold text-ink-900">{facility.name}</p>
                                                                <p className="text-[11px] font-medium text-zinc-400">
                                                                    {facility.code} · {facility.city_municipality}
                                                                </p>
                                                            </td>
                                                            <td className="px-4 py-3">
                                                                <span className="inline-flex rounded-md border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700">
                                                                    {formatTier(facility.tier)}
                                                                </span>
                                                            </td>
                                                            <td className="px-4 py-3 text-right font-semibold tabular-nums text-ink-900">
                                                                {facility.parcels}
                                                            </td>
                                                            <td
                                                                className={`px-4 py-3 text-right font-semibold tabular-nums ${
 facility.exceptions > 0 ? 'text-red-700' : 'text-zinc-500'
 }`}
                                                            >
                                                                {facility.exceptions}
                                                            </td>
                                                            <td className="px-4 py-3 text-right tabular-nums text-zinc-600">{facility.active_fleet}</td>
                                                            <td className="px-5 py-3 text-right">
                                                                <span className="font-semibold tabular-nums text-ink-900">
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
                            )}
                        </div>
                    )}

                    {/* Right Column (5 cols): Attention Worklist + Recent Scan Activity */}
                    {(activeCategory === 'all' || activeCategory === 'urgent' || activeCategory === 'facilities') && (
                        <div className="space-y-5 xl:col-span-5">
                            {/* Needs Attention Worklist */}
                            {(activeCategory === 'all' || activeCategory === 'urgent') && (
                                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                                    <div className="flex items-center justify-between px-5 pt-5 pb-3">
                                        <div>
                                            <h2 className="text-base font-semibold text-ink-900">Needs attention</h2>
                                        </div>
                                        <span className="rounded-md border border-zinc-200 bg-zinc-100 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-zinc-800">
                                            {totalAttentionCount} open
                                        </span>
                                    </div>

                                    {activeAttention.length === 0 ? (
                                        <div className="flex items-center justify-between gap-3 px-5 py-5">
                                            <div className="flex items-center gap-3">
                                                <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success-soft text-success">
                                                    <CheckCircle2 className="h-4 w-4" />
                                                </span>
                                                <div>
                                                    <p className="text-sm font-medium text-ink-900">Nothing needs attention right now</p>
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
                            )}

                            {/* Recent Scan Activity */}
                            {(activeCategory === 'all' || activeCategory === 'facilities') && (
                                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs">
                                    <div className="flex items-center justify-between px-5 pt-5 pb-3">
                                        <div>
                                            <h2 className="text-base font-semibold text-ink-900">Recent scan activity</h2>
                                        </div>
                                        <PortalLink
                                            href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')}
                                            className={hubButton}
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
                                                            <span className="inline-flex items-center rounded-md border border-zinc-200 bg-zinc-50 px-2 py-0.5 text-[11px] font-bold text-ink-900 tabular-nums">
                                                                {activity.tracking_number}
                                                            </span>
                                                            <span className="truncate font-semibold text-ink-900">
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
                            )}
                        </div>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
}
