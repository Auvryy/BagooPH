import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
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
    danger: 'border-rose-300 bg-rose-50 text-rose-700',
    warning: 'border-amber-300 bg-amber-50 text-amber-800',
    info: 'border-sky-300 bg-sky-50 text-sky-700',
    neutral: 'border-slate-300 bg-slate-50 text-slate-700',
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
            iconClass: 'bg-slate-100 text-slate-700',
        },
        {
            label: 'Ready for dispatch',
            value: stats.ready_for_dispatch,
            helper: 'Sorted or assigned',
            icon: Send,
            iconClass: 'bg-sky-50 text-sky-700',
        },
        {
            label: 'Needs attention',
            value: stats.exceptions,
            helper: 'Failed or returning',
            icon: AlertTriangle,
            iconClass: stats.exceptions > 0 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700',
        },
        {
            label: 'Dispatched today',
            value: stats.dispatched_today,
            helper: 'Verified outbound scans',
            icon: Truck,
            iconClass: 'bg-emerald-50 text-emerald-700',
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
                <span className="flex items-center gap-1.5 text-xs text-slate-600">
                    <span className="font-semibold text-slate-800">{scope.company_name}</span>
                    {scope.company_code && <span className="text-slate-400">· {scope.company_code}</span>}
                </span>
            )}
            actions={(
                <div className="flex items-center gap-2">
                    <Link
                        href={route('hub.counter')}
                        className="hidden sm:inline-flex items-center gap-2 rounded-xs border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        <Store className="h-4 w-4" />
                        Counter pickup
                    </Link>
                    <Link
                        href={route('hub.scan.station')}
                        className="inline-flex items-center gap-2 rounded-xs bg-[#E00D42] px-3 py-2 text-xs font-semibold text-white transition hover:bg-[#C20836]"
                    >
                        <ScanLine className="h-4 w-4" />
                        Scan parcel
                    </Link>
                </div>
            )}
        >
            <Head title="Operations Overview — BagooPH" />

            <div className="space-y-5 font-sans">
                <section className="flex flex-col gap-3 rounded-lg border border-slate-300 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 className="text-sm font-bold text-slate-900">Network activity</h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Real parcel custody and facility activity for the selected scope.
                        </p>
                    </div>
                    <label className="flex items-center gap-2 text-xs font-semibold text-slate-700">
                        <span className="shrink-0">View</span>
                        {scope.can_view_company ? (
                            <select
                                value={scope.selected_hub_id ?? ''}
                                onChange={(event) => changeScope(event.target.value)}
                                className="min-w-52 rounded-xs border-slate-300 bg-white py-2 pl-3 pr-8 text-xs font-medium text-slate-800 focus:border-[#E00D42] focus:ring-[#E00D42]"
                            >
                                <option value="">All facilities</option>
                                {scope.hubs.map((hub) => (
                                    <option key={hub.id} value={hub.id}>
                                        {hub.name} · {hub.code}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <span className="rounded-xs border border-slate-300 bg-slate-50 px-3 py-2 text-slate-800">
                                {selectedHub?.name ?? 'Assigned facility'}
                            </span>
                        )}
                    </label>
                </section>

                <section className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {metrics.map((metric) => {
                        const Icon = metric.icon;
                        return (
                            <div key={metric.label} className="rounded-lg border border-slate-300 bg-white p-4 shadow-xs">
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-semibold text-slate-600">{metric.label}</p>
                                        <p className="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">{metric.value}</p>
                                    </div>
                                    <span className={`flex h-9 w-9 items-center justify-center rounded-md ${metric.iconClass}`}>
                                        <Icon className="h-4 w-4" />
                                    </span>
                                </div>
                                <p className="mt-2 text-xs text-slate-500">{metric.helper}</p>
                            </div>
                        );
                    })}
                </section>

                <section className="grid grid-cols-1 gap-4 xl:grid-cols-12">
                    <div className="rounded-lg border border-slate-300 bg-white shadow-xs xl:col-span-7">
                        <div className="flex items-center justify-between border-b border-slate-300 px-4 py-3">
                            <div>
                                <h2 className="text-sm font-bold text-slate-900">Parcel movement</h2>
                                <p className="mt-0.5 text-xs text-slate-500">Verified inbound and outbound scans · last 7 days</p>
                            </div>
                            <div className="flex items-center gap-3 text-[11px] font-medium text-slate-600">
                                <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-xs bg-slate-800" />Inbound</span>
                                <span className="flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-xs bg-[#E00D42]" />Outbound</span>
                            </div>
                        </div>
                        {hasMovement ? (
                            <div className="flex h-56 items-end gap-2 px-4 pb-4 pt-6 sm:gap-4">
                                {movement.map((day) => (
                                    <div key={day.date} className="flex h-full min-w-0 flex-1 flex-col justify-end">
                                        <div className="flex flex-1 items-end justify-center gap-1 sm:gap-1.5">
                                            <div
                                                className="w-2.5 rounded-t-xs bg-slate-800 sm:w-4"
                                                style={{ height: `${Math.max(day.inbound > 0 ? 8 : 0, (day.inbound / maxMovement) * 100)}%` }}
                                                title={`${day.date}: ${day.inbound} inbound`}
                                            />
                                            <div
                                                className="w-2.5 rounded-t-xs bg-[#E00D42] sm:w-4"
                                                style={{ height: `${Math.max(day.outbound > 0 ? 8 : 0, (day.outbound / maxMovement) * 100)}%` }}
                                                title={`${day.date}: ${day.outbound} outbound`}
                                            />
                                        </div>
                                        <div className="mt-2 border-t border-slate-200 pt-2 text-center">
                                            <p className="text-[11px] font-semibold text-slate-600">{day.label}</p>
                                            <p className="text-[10px] text-slate-400">{day.inbound}/{day.outbound}</p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex min-h-56 flex-col items-center justify-center px-5 py-8 text-center">
                                <ScanLine className="h-8 w-8 text-slate-300" />
                                <p className="mt-3 text-sm font-semibold text-slate-800">No parcel movement this week</p>
                                <p className="mt-1 text-xs text-slate-500">Inbound and outbound scans will build this view.</p>
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg border border-slate-300 bg-white shadow-xs xl:col-span-5">
                        <div className="border-b border-slate-300 px-4 py-3">
                            <h2 className="text-sm font-bold text-slate-900">Needs attention</h2>
                            <p className="mt-0.5 text-xs text-slate-500">Open operational work in this scope</p>
                        </div>
                        {activeAttention.length === 0 ? (
                            <div className="flex min-h-52 flex-col items-center justify-center px-5 py-8 text-center">
                                <CheckCircle2 className="h-8 w-8 text-emerald-600" />
                                <p className="mt-3 text-sm font-semibold text-slate-800">No urgent operational work</p>
                                <p className="mt-1 text-xs text-slate-500">New exceptions and handoffs will appear here.</p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-200">
                                {activeAttention.map((item) => (
                                    <Link
                                        key={item.key}
                                        href={item.href}
                                        className="flex items-center justify-between gap-4 px-4 py-3 transition hover:bg-slate-50"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className={`flex h-8 min-w-8 items-center justify-center rounded-xs border px-2 text-sm font-bold ${toneClasses[item.tone]}`}>
                                                {item.count}
                                            </span>
                                            <span className="truncate text-sm font-medium text-slate-800">{item.label}</span>
                                        </div>
                                        <ChevronRight className="h-4 w-4 shrink-0 text-slate-400" />
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>

                </section>

                <section className="rounded-lg border border-slate-300 bg-white shadow-xs">
                    <div className="flex items-center justify-between border-b border-slate-300 px-4 py-3">
                        <div>
                            <h2 className="text-sm font-bold text-slate-900">Parcel journey</h2>
                            <p className="mt-0.5 text-xs text-slate-500">Current physical custody across the road network</p>
                        </div>
                        <Link href={route('hub.deliveries')} className="text-xs font-semibold text-[#E00D42] hover:underline">
                            View parcels
                        </Link>
                    </div>

                    <div className="hidden p-6 md:block">
                        <div className="relative grid grid-cols-4 gap-6">
                            <div className="absolute left-[12.5%] right-[12.5%] top-6 border-t-2 border-slate-300" />
                            {flowStages.map((stage) => {
                                const Icon = stage.icon;
                                return (
                                    <Link
                                        key={stage.label}
                                        href={route('hub.deliveries', { status: stage.status })}
                                        className="group relative z-10 flex flex-col items-center text-center"
                                    >
                                        <span className="flex h-12 w-12 items-center justify-center rounded-md border-2 border-slate-300 bg-white text-slate-600 shadow-xs transition group-hover:border-[#E00D42] group-hover:text-[#E00D42]">
                                            <Icon className="h-5 w-5" />
                                        </span>
                                        <span className="mt-3 text-2xl font-extrabold tracking-tight text-slate-900">{stage.value}</span>
                                        <span className="mt-0.5 text-xs font-semibold text-slate-600 group-hover:text-slate-900">{stage.label}</span>
                                    </Link>
                                );
                            })}
                        </div>
                        <div className="mt-3 grid grid-cols-4 gap-6">
                            <Link
                                href={route('hub.counter')}
                                className="relative col-start-3 flex items-center justify-center gap-3 border-t border-dashed border-sky-300 pt-4 text-left group"
                            >
                                <span className="flex h-9 w-9 items-center justify-center rounded-md border border-sky-300 bg-sky-50 text-sky-700">
                                    <Store className="h-4 w-4" />
                                </span>
                                <span>
                                    <span className="block text-base font-extrabold text-slate-900">{parcelFlow.counter_pickup}</span>
                                    <span className="block text-xs font-semibold text-slate-600 group-hover:text-sky-700">Counter pickup branch</span>
                                </span>
                            </Link>
                        </div>
                    </div>

                    <div className="p-4 md:hidden">
                        {flowStages.map((stage, index) => {
                            const Icon = stage.icon;
                            return (
                                <React.Fragment key={stage.label}>
                                    <Link href={route('hub.deliveries', { status: stage.status })} className="grid grid-cols-[44px_1fr] gap-3">
                                        <div className="flex flex-col items-center">
                                            <span className="flex h-10 w-10 items-center justify-center rounded-md border-2 border-slate-300 bg-white text-slate-600">
                                                <Icon className="h-4 w-4" />
                                            </span>
                                            {index < flowStages.length - 1 && <span className="min-h-8 flex-1 border-l-2 border-slate-300" />}
                                        </div>
                                        <div className="pb-5">
                                            <p className="text-xl font-extrabold text-slate-900">{stage.value}</p>
                                            <p className="text-xs font-semibold text-slate-600">{stage.label}</p>
                                            {index === 2 && (
                                                <div className="mt-3 flex items-center gap-2 border-l-2 border-dashed border-sky-300 pl-3 text-sky-700">
                                                    <Store className="h-4 w-4" />
                                                    <span className="text-xs font-semibold">{parcelFlow.counter_pickup} at counter pickup</span>
                                                </div>
                                            )}
                                        </div>
                                    </Link>
                                </React.Fragment>
                            );
                        })}
                    </div>
                </section>

                <section className="rounded-lg border border-slate-300 bg-white shadow-xs">
                    <div className="flex items-center justify-between border-b border-slate-300 px-4 py-3">
                        <div>
                            <h2 className="text-sm font-bold text-slate-900">Facilities</h2>
                            <p className="mt-0.5 text-xs text-slate-500">Capacity and operational load</p>
                        </div>
                        <Link href={route('hub.network')} className="text-xs font-semibold text-[#E00D42] hover:underline">
                            Facility network
                        </Link>
                    </div>

                    {facilities.length === 0 ? (
                        <div className="px-4 py-10 text-center">
                            <Building2 className="mx-auto h-8 w-8 text-slate-300" />
                            <p className="mt-3 text-sm font-semibold text-slate-700">No accessible facilities</p>
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-3 p-4 md:hidden">
                                {facilities.map((facility) => (
                                    <div key={facility.id} className="rounded-md border border-slate-300 p-3">
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-bold text-slate-900">{facility.name}</p>
                                                <p className="mt-1 flex items-center gap-1 text-xs text-slate-500">
                                                    <MapPin className="h-3 w-3" /> {facility.code} · {facility.city_municipality}
                                                </p>
                                            </div>
                                            <span className="rounded-xs border border-slate-300 bg-slate-50 px-2 py-1 text-[10px] font-bold uppercase text-slate-600">
                                                {formatTier(facility.tier)}
                                            </span>
                                        </div>
                                        <div className="mt-3 grid grid-cols-3 gap-2 border-t border-slate-200 pt-3 text-center">
                                            <div><p className="text-sm font-bold text-slate-900">{facility.parcels}</p><p className="text-xs text-slate-500">Parcels</p></div>
                                            <div><p className="text-sm font-bold text-rose-700">{facility.exceptions}</p><p className="text-xs text-slate-500">Exceptions</p></div>
                                            <div><p className="text-sm font-bold text-slate-900">{facility.active_fleet}</p><p className="text-xs text-slate-500">Fleet</p></div>
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full border-collapse text-left text-xs">
                                    <thead className="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th className="px-4 py-3 font-semibold">Facility</th>
                                            <th className="px-4 py-3 font-semibold">Type</th>
                                            <th className="px-4 py-3 text-right font-semibold">Parcels</th>
                                            <th className="px-4 py-3 text-right font-semibold">Exceptions</th>
                                            <th className="px-4 py-3 text-right font-semibold">Active fleet</th>
                                            <th className="px-4 py-3 text-right font-semibold">Capacity used</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-200">
                                        {facilities.map((facility) => (
                                            <tr key={facility.id} className="text-slate-700 hover:bg-slate-50">
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold text-slate-900">{facility.name}</p>
                                                    <p className="mt-0.5 text-slate-500">{facility.code} · {facility.city_municipality}</p>
                                                </td>
                                                <td className="px-4 py-3">{formatTier(facility.tier)}</td>
                                                <td className="px-4 py-3 text-right font-semibold">{facility.parcels}</td>
                                                <td className={`px-4 py-3 text-right font-semibold ${facility.exceptions > 0 ? 'text-rose-700' : 'text-slate-700'}`}>{facility.exceptions}</td>
                                                <td className="px-4 py-3 text-right font-semibold">{facility.active_fleet}</td>
                                                <td className="px-4 py-3 text-right">
                                                    <span className="font-semibold text-slate-900">{facility.utilization_rate}%</span>
                                                    <span className="ml-1 text-slate-400">of {facility.capacity}</span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
                </section>

                <section className="rounded-lg border border-slate-300 bg-white shadow-xs">
                    <div className="flex items-center justify-between border-b border-slate-300 px-4 py-3">
                        <div>
                            <h2 className="text-sm font-bold text-slate-900">Recent scan activity</h2>
                            <p className="mt-0.5 text-xs text-slate-500">Latest verified custody events</p>
                        </div>
                        <Link href={route('hub.scan.station')} className="text-xs font-semibold text-[#E00D42] hover:underline">
                            Open scanner
                        </Link>
                    </div>

                    {recentActivity.length === 0 ? (
                        <div className="px-4 py-10 text-center">
                            <ScanLine className="mx-auto h-8 w-8 text-slate-300" />
                            <p className="mt-3 text-sm font-semibold text-slate-700">No scans recorded in this scope</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-slate-200">
                            {recentActivity.map((activity) => (
                                <div key={activity.id} className="grid gap-2 px-4 py-3 text-xs sm:grid-cols-[1.2fr_1fr_1fr_auto] sm:items-center sm:gap-4">
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-slate-900">{activity.tracking_number}</p>
                                        <p className="mt-0.5 text-slate-500 sm:hidden">{formatEvent(activity.event)}</p>
                                    </div>
                                    <p className="hidden truncate font-medium text-slate-700 sm:block">{formatEvent(activity.event)}</p>
                                    <div className="min-w-0">
                                        <p className="truncate text-slate-700">{activity.facility}</p>
                                        <p className="mt-0.5 truncate text-slate-400">by {activity.operator}</p>
                                    </div>
                                    <div className="text-left sm:text-right">
                                        <p className="font-medium text-slate-600">{activity.relative_time}</p>
                                        <p className="mt-0.5 text-slate-400">{activity.timestamp}</p>
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
