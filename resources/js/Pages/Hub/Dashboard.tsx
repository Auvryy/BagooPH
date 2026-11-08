import React from 'react';
import { Head, router } from '@inertiajs/react';
import PortalLink from '@/Components/PortalLink';
import {
    AlertTriangle,
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
import { HubNextStep, HubPageHeader } from '@/Components/Logistics/Workspace';

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
}

const toneClasses = {
    danger: 'border-rose-200 bg-rose-50 text-[#E00D42]',
    warning: 'border-amber-200 bg-amber-50 text-amber-800',
    info: 'border-sky-200 bg-sky-50 text-sky-800',
    neutral: 'border-zinc-200 bg-zinc-50 text-zinc-700',
};

const formatEvent = (event: string) => event
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());

const formatTier = (tier: string) => tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub';

export default function HubDashboard({
    scope,
    stats,
    attentionQueue,
    movement,
    parcelFlow,
    facilities,
    recentActivity,
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
            iconClass: 'border border-zinc-200/90 bg-zinc-50 text-[#1C1A17]',
        },
        {
            label: 'Ready for dispatch',
            value: stats.ready_for_dispatch,
            helper: 'Sorted or assigned',
            icon: Send,
            iconClass: 'border border-sky-200 bg-sky-50 text-sky-700',
        },
        {
            label: 'Needs attention',
            value: stats.exceptions,
            helper: 'Failed or returning',
            icon: AlertTriangle,
            iconClass: stats.exceptions > 0 ? 'border border-rose-200 bg-rose-50 text-[#E00D42]' : 'border border-emerald-200 bg-emerald-50 text-emerald-700',
        },
        {
            label: 'Dispatched today',
            value: stats.dispatched_today,
            helper: 'Verified outbound scans',
            icon: Truck,
            iconClass: 'border border-emerald-200 bg-emerald-50 text-emerald-700',
        },
    ];

    const flowStages = [
        { label: 'Origin Bayan Hub', value: parcelFlow.origin_hub, icon: Building2, status: 'arrived_at_origin_hub' },
        { label: 'Mother Hub route', value: parcelFlow.mother_hub_transit, icon: Truck, status: 'arrived_at_mother_hub' },
        { label: 'Destination Bayan Hub', value: parcelFlow.destination_hub, icon: MapPin, status: 'arrived_at_destination_hub' },
        { label: 'Final-mile delivery', value: parcelFlow.final_mile, icon: Send, status: 'out_for_delivery' },
    ];

    return (
        <DashboardLayout
            title="Operations Overview"
            subtitle={(
                <span className="flex items-center gap-1.5 text-xs text-zinc-600">
                    <span className="font-semibold text-[#1C1A17]">{scope.company_name}</span>
                    {scope.company_code && <span className="text-zinc-400">· {scope.company_code}</span>}
                </span>
            )}
            actions={(
                <div className="flex items-center gap-2.5">
                    <PortalLink
                        href={route('hub.counter')}
                        className="hidden sm:inline-flex items-center gap-2 rounded-xl border border-zinc-200/90 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 shadow-xs transition hover:bg-zinc-50 hover:text-zinc-900 active:scale-[0.99]"
                    >
                        <Store className="h-4 w-4 text-zinc-500" />
                        Counter pickup
                    </PortalLink>
                    <PortalLink
                        href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')}
                        className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-[#C20836] active:scale-[0.99]"
                    >
                        {scope.can_scan ? <ScanLine className="h-4 w-4" /> : <Package className="h-4 w-4" />}
                        {scope.can_scan ? 'Scan parcel' : 'View parcels'}
                    </PortalLink>
                </div>
            )}
        >
            <Head title="Operations Overview — BagooPH" />

            <div className="space-y-6 font-sans">
                <HubPageHeader title="Operations overview" description="Start with work that needs attention, then scan the next actual handover. Counts follow the facility view below." icon={Building2} />
                <HubNextStep title={activeAttention.length ? activeAttention[0].label : 'Ready for the next handover'} tone={activeAttention.length ? 'warning' : 'brand'} icon={activeAttention.length ? AlertTriangle : ScanLine} action={<PortalLink href={activeAttention[0]?.href ?? (scope.can_scan ? route('hub.scan.station') : route('hub.network'))} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-[#C20836] active:scale-[0.99]">{activeAttention.length ? 'Open worklist' : scope.can_scan ? 'Open scan station' : 'View hub network'}<ChevronRight className="h-3.5 w-3.5" /></PortalLink>}>
                    {activeAttention.length ? <p>{activeAttention[0].count} {activeAttention[0].count === 1 ? 'item needs' : 'items need'} review in this view. Open its worklist to see the responsible person and permitted next action.</p> : <p>No attention items are recorded in this view. Incoming parcels and new exceptions appear as their actual scans are saved.</p>}
                </HubNextStep>
                <section className="flex flex-col gap-3 rounded-xl border border-zinc-200/90 bg-white p-5 sm:p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-sm font-bold text-[#1C1A17]">Network activity</h2>
                        <p className="mt-1 text-xs text-zinc-500">
                            Real parcel custody and facility activity for the selected scope.
                        </p>
                    </div>
                    <label className="flex items-center gap-2.5 text-xs font-semibold text-zinc-700">
                        <span className="shrink-0 text-zinc-500">View</span>
                        {scope.can_view_company ? (
                            <select
                                value={scope.selected_hub_id ?? ''}
                                onChange={(event) => changeScope(event.target.value)}
                                className="min-w-56 rounded-xl border border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white py-2 pl-3.5 pr-8 text-xs font-medium text-[#1C1A17] shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition-colors"
                            >
                                <option value="">All facilities</option>
                                {scope.hubs.map((hub) => (
                                    <option key={hub.id} value={hub.id}>
                                        {hub.name} · {hub.code}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <span className="rounded-xl border border-zinc-200/90 bg-zinc-50 px-3.5 py-2 text-xs font-semibold text-[#1C1A17]">
                                {selectedHub?.name ?? 'Assigned facility'}
                            </span>
                        )}
                    </label>
                </section>

                <section className="grid grid-cols-1 gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
                    {metrics.map((metric) => {
                        const Icon = metric.icon;
                        return (
                            <div key={metric.label} className="flex flex-col justify-between rounded-xl border border-zinc-200/90 bg-white p-5 sm:p-6 shadow-sm hover:shadow-md transition-all duration-150">
                                <div>
                                    <div className="flex items-start justify-between gap-4">
                                        <p className="text-xs font-semibold text-zinc-500">{metric.label}</p>
                                        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl shadow-xs ${metric.iconClass}`}>
                                            <Icon className="h-5 w-5" />
                                        </span>
                                    </div>
                                    <p className="mt-2 text-3xl font-extrabold tracking-tight text-[#1C1A17]">{metric.value}</p>
                                </div>
                                <p className="mt-3 border-t border-zinc-100 pt-3 text-xs text-zinc-400 font-medium">{metric.helper}</p>
                            </div>
                        );
                    })}
                </section>

                <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <div className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm xl:col-span-7">
                        <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 sm:px-6">
                            <div>
                                <h2 className="text-sm font-bold text-[#1C1A17]">Parcel movement</h2>
                                <p className="mt-0.5 text-xs text-zinc-500">Verified inbound and outbound scans · last 7 days</p>
                            </div>
                            <div className="flex items-center gap-3.5 text-xs font-medium text-zinc-600">
                                <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-[#1C1A17]" />Inbound</span>
                                <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-[#E00D42]" />Outbound</span>
                            </div>
                        </div>
                        {hasMovement ? (
                            <div className="flex h-60 items-end gap-2 px-5 pb-5 pt-8 sm:gap-4 sm:px-6">
                                {movement.map((day) => (
                                    <div key={day.date} className="flex h-full min-w-0 flex-1 flex-col justify-end">
                                        <div className="flex flex-1 items-end justify-center gap-1 sm:gap-1.5">
                                            <div
                                                className="w-3 rounded-t-sm bg-[#1C1A17] sm:w-4.5 transition-all hover:opacity-80"
                                                style={{ height: `${Math.max(day.inbound > 0 ? 8 : 0, (day.inbound / maxMovement) * 100)}%` }}
                                                title={`${day.date}: ${day.inbound} inbound`}
                                            />
                                            <div
                                                className="w-3 rounded-t-sm bg-[#E00D42] sm:w-4.5 transition-all hover:opacity-80"
                                                style={{ height: `${Math.max(day.outbound > 0 ? 8 : 0, (day.outbound / maxMovement) * 100)}%` }}
                                                title={`${day.date}: ${day.outbound} outbound`}
                                            />
                                        </div>
                                        <div className="mt-3 border-t border-zinc-100 pt-2.5 text-center">
                                            <p className="text-xs font-semibold text-zinc-600">{day.label}</p>
                                            <p className="mt-0.5 text-xs text-zinc-400 font-mono">{day.inbound}/{day.outbound}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex min-h-60 flex-col items-center justify-center px-6 py-12 text-center">
                                <span className="flex h-12 w-12 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-400">
                                    <ScanLine className="h-5 w-5" />
                                </span>
                                <p className="mt-3 text-sm font-bold text-[#1C1A17]">No parcel movement this week</p>
                                <p className="mt-1 text-xs text-zinc-500">Inbound and outbound scans will build this view.</p>
                            </div>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm xl:col-span-5">
                        <div className="border-b border-zinc-100 px-5 py-4 sm:px-6">
                            <h2 className="text-sm font-bold text-[#1C1A17]">Needs attention</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Open operational work in this scope</p>
                        </div>
                        {activeAttention.length === 0 ? (
                            <div className="flex min-h-60 flex-col items-center justify-center px-6 py-12 text-center">
                                <span className="flex h-12 w-12 items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-600">
                                    <CheckCircle2 className="h-5 w-5" />
                                </span>
                                <p className="mt-3 text-sm font-bold text-[#1C1A17]">No urgent operational work</p>
                                <p className="mt-1 text-xs text-zinc-500">New exceptions and handoffs will appear here.</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-zinc-100">
                                {activeAttention.map((item) => (
                                    <PortalLink
                                        key={item.key}
                                        href={item.href}
                                        className="flex items-center justify-between gap-4 px-5 py-4 sm:px-6 transition-colors hover:bg-zinc-50/80"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className={`flex h-8 min-w-8 items-center justify-center rounded-lg border px-2 text-xs font-mono font-bold shadow-xs ${toneClasses[item.tone]}`}>
                                                {item.count}
                                            </span>
                                            <span className="truncate text-xs sm:text-sm font-medium text-zinc-800">{item.label}</span>
                                        </div>
                                        <ChevronRight className="h-4 w-4 shrink-0 text-zinc-400" />
                                    </PortalLink>
                                ))}
                            </div>
                        )}
                    </div>

                </section>

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 sm:px-6">
                        <div>
                            <h2 className="text-sm font-bold text-[#1C1A17]">Parcel journey</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Current physical custody across the road network</p>
                        </div>
                        <PortalLink href={route('hub.deliveries')} className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] transition-colors">
                            View parcels
                        </PortalLink>
                    </div>

                    <div className="hidden p-6 sm:p-8 md:block">
                        <div className="relative grid grid-cols-4 gap-6">
                            <div className="absolute left-[12.5%] right-[12.5%] top-6 border-t-2 border-zinc-200" />
                            {flowStages.map((stage) => {
                                const Icon = stage.icon;
                                return (
                                    <PortalLink
                                        key={stage.label}
                                        href={route('hub.deliveries', { status: stage.status })}
                                        className="group relative z-10 flex flex-col items-center text-center"
                                    >
                                        <span className="flex h-12 w-12 items-center justify-center rounded-xl border-2 border-zinc-200/90 bg-white text-zinc-600 shadow-xs transition-all group-hover:-translate-y-0.5 group-hover:border-[#E00D42] group-hover:text-[#E00D42] group-hover:shadow-md">
                                            <Icon className="h-5 w-5" />
                                        </span>
                                        <span className="mt-3 text-2xl font-black tracking-tight text-[#1C1A17]">{stage.value}</span>
                                        <span className="mt-0.5 text-xs font-semibold text-zinc-600 group-hover:text-[#1C1A17]">{stage.label}</span>
                                    </PortalLink>
                                );
                            })}
                        </div>
                        <div className="mt-4 grid grid-cols-4 gap-6">
                            <PortalLink
                                href={route('hub.counter')}
                                className="relative col-start-3 flex items-center justify-center gap-3 rounded-xl border border-sky-200/80 bg-sky-50/50 p-3.5 text-left transition hover:bg-sky-50 group"
                            >
                                <span className="flex h-9 w-9 items-center justify-center rounded-lg border border-sky-300 bg-white text-sky-700 shadow-xs">
                                    <Store className="h-4 w-4" />
                                </span>
                                <div>
                                    <span className="block text-base font-extrabold text-[#1C1A17]">{parcelFlow.counter_pickup}</span>
                                    <span className="block text-xs font-semibold text-zinc-600 group-hover:text-sky-800">Counter pickup branch</span>
                                </div>
                            </PortalLink>
                        </div>
                    </div>

                    <div className="p-5 md:hidden">
                        {flowStages.map((stage, index) => {
                            const Icon = stage.icon;
                            return (
                                <React.Fragment key={stage.label}>
                                    <PortalLink href={route('hub.deliveries', { status: stage.status })} className="grid grid-cols-[44px_1fr] gap-3">
                                        <div className="flex flex-col items-center">
                                            <span className="flex h-10 w-10 items-center justify-center rounded-xl border-2 border-zinc-200 bg-white text-zinc-600 shadow-xs">
                                                <Icon className="h-4 w-4" />
                                            </span>
                                            {index < flowStages.length - 1 && <span className="min-h-8 flex-1 border-l-2 border-zinc-200" />}
                                        </div>
                                        <div className="pb-5">
                                            <p className="text-xl font-extrabold text-[#1C1A17]">{stage.value}</p>
                                            <p className="text-xs font-semibold text-zinc-600">{stage.label}</p>
                                            {index === 2 && (
                                                <div className="mt-3 flex items-center gap-2 rounded-lg border border-sky-200 bg-sky-50/60 p-2 text-sky-700">
                                                    <Store className="h-4 w-4 shrink-0" />
                                                    <span className="text-xs font-semibold">{parcelFlow.counter_pickup} at counter pickup</span>
                                                </div>
                                            )}
                                        </div>
                                    </PortalLink>
                                </React.Fragment>
                            );
                        })}
                    </div>
                </section>

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 sm:px-6">
                        <div>
                            <h2 className="text-sm font-bold text-[#1C1A17]">Facilities</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Capacity and operational load</p>
                        </div>
                        <PortalLink href={route('hub.network')} className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] transition-colors">
                            Facility network
                        </PortalLink>
                    </div>

                    {facilities.length === 0 ? (
                        <div className="px-6 py-12 text-center">
                            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-400">
                                <Building2 className="h-5 w-5" />
                            </span>
                            <p className="mt-3 text-sm font-bold text-[#1C1A17]">No accessible facilities</p>
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-3.5 p-5 md:hidden">
                                {facilities.map((facility) => (
                                    <div key={facility.id} className="rounded-xl border border-zinc-200/90 bg-zinc-50/40 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-bold text-[#1C1A17]">{facility.name}</p>
                                                <p className="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                                                    <MapPin className="h-3 w-3" /> {facility.code} · {facility.city_municipality}
                                                </p>
                                            </div>
                                            <span className="rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-bold text-zinc-700 shadow-xs">
                                                {formatTier(facility.tier)}
                                            </span>
                                        </div>
                                        <div className="mt-3.5 grid grid-cols-3 gap-2 border-t border-zinc-200/80 pt-3 text-center">
                                            <div><p className="text-sm font-bold text-[#1C1A17]">{facility.parcels}</p><p className="text-xs text-zinc-500">Parcels</p></div>
                                            <div><p className="text-sm font-bold text-[#E00D42]">{facility.exceptions}</p><p className="text-xs text-zinc-500">Exceptions</p></div>
                                            <div><p className="text-sm font-bold text-[#1C1A17]">{facility.active_fleet}</p><p className="text-xs text-zinc-500">Fleet</p></div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full border-collapse text-left text-xs">
                                    <thead className="bg-zinc-50/70 border-b border-zinc-100 text-zinc-500 font-semibold">
                                        <tr>
                                            <th className="px-5 py-3.5">Facility</th>
                                            <th className="px-5 py-3.5">Type</th>
                                            <th className="px-5 py-3.5 text-right">Parcels</th>
                                            <th className="px-5 py-3.5 text-right">Exceptions</th>
                                            <th className="px-5 py-3.5 text-right">Active fleet</th>
                                            <th className="px-5 py-3.5 text-right">Capacity used</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-zinc-100">
                                        {facilities.map((facility) => (
                                            <tr key={facility.id} className="text-zinc-700 hover:bg-zinc-50/60 transition-colors">
                                                <td className="px-5 py-3.5">
                                                    <p className="font-bold text-[#1C1A17]">{facility.name}</p>
                                                    <p className="mt-0.5 text-zinc-500">{facility.code} · {facility.city_municipality}</p>
                                                </td>
                                                <td className="px-5 py-3.5 font-medium">{formatTier(facility.tier)}</td>
                                                <td className="px-5 py-3.5 text-right font-bold text-[#1C1A17]">{facility.parcels}</td>
                                                <td className={`px-5 py-3.5 text-right font-bold ${facility.exceptions > 0 ? 'text-[#E00D42]' : 'text-zinc-700'}`}>{facility.exceptions}</td>
                                                <td className="px-5 py-3.5 text-right font-medium">{facility.active_fleet}</td>
                                                <td className="px-5 py-3.5 text-right">
                                                    <span className="font-bold text-[#1C1A17]">{facility.utilization_rate}%</span>
                                                    <span className="ml-1 text-zinc-400">of {facility.capacity}</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>

                <section className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-sm">
                    <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-4 sm:px-6">
                        <div>
                            <h2 className="text-sm font-bold text-[#1C1A17]">Recent scan activity</h2>
                            <p className="mt-0.5 text-xs text-zinc-500">Latest verified custody events</p>
                        </div>
                        <PortalLink href={route(scope.can_scan ? 'hub.scan.station' : 'hub.deliveries')} className="text-xs font-bold text-[#E00D42] hover:text-[#C20836] transition-colors">
                            {scope.can_scan ? 'Open scanner' : 'View parcels'}
                        </PortalLink>
                    </div>

                    {recentActivity.length === 0 ? (
                        <div className="px-6 py-12 text-center">
                            <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-50 text-zinc-400">
                                <ScanLine className="h-5 w-5" />
                            </span>
                            <p className="mt-3 text-sm font-bold text-[#1C1A17]">No scans recorded in this scope</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-zinc-100">
                            {recentActivity.map((activity) => (
                                <div key={activity.id} className="grid gap-2 px-5 py-3.5 text-xs sm:grid-cols-[1.3fr_1fr_1fr_auto] sm:items-center sm:gap-4 sm:px-6 hover:bg-zinc-50/60 transition-colors">
                                    <div className="min-w-0">
                                        <span className="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-0.5 font-mono text-xs font-bold text-[#E00D42]">
                                            {activity.tracking_number}
                                        </span>
                                        <p className="mt-1 text-zinc-500 sm:hidden">{formatEvent(activity.event)}</p>
                                    </div>
                                    <p className="hidden truncate font-semibold text-zinc-700 sm:block">{formatEvent(activity.event)}</p>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium text-zinc-800">{activity.facility}</p>
                                        <p className="mt-0.5 truncate text-zinc-400">by {activity.operator}</p>
                                    </div>
                                    <div className="text-left sm:text-right">
                                        <p className="font-semibold text-zinc-700">{activity.relative_time}</p>
                                        <p className="mt-0.5 text-zinc-400 font-mono text-[11px]">{activity.timestamp}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </DashboardLayout>
    );
}
