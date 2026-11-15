import React, { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PortalLink from '@/Components/PortalLink';
import { visitPortal } from '@/utils/catalogueRuntime';
import { paginationLabel } from '@/utils/catalogueNavigation';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RiderPicker from '@/Components/Logistics/RiderPicker';
import { CourierDialog } from '@/Components/CourierUI';
import {
    HubBadge,
    HubCategoryTabs,
    HubEmpty,
    HubPageHeader,
    hubButton,
    hubInput,
    hubPrimary,
} from '@/Components/Logistics/Workspace';
import { HubParcelState, HubParcelNextStep, ParcelGuidance } from '@/Components/HubParcelGuidance';
import type { EtaPresentation } from '@/types';
import { ChevronRight, Package, Search, Store, MapPin, ScanLine, UserRound, RefreshCw, ArrowRight, ExternalLink } from 'lucide-react';

interface DeliveryItem {
    id: number;
    tracking_number: string;
    order_number: string;
    buyer_name: string;
    buyer_phone: string;
    pickup_store_name?: string | null;
    pickup_address?: string | null;
    pickup_latitude?: number | null;
    pickup_longitude?: number | null;
    delivery_address?: string | null;
    destination_barangay: string;
    shipping_city: string;
    destination_latitude?: number | null;
    destination_longitude?: number | null;
    status: string;
    delivery_type: string;
    destination_bin: string | null;
    current_hub: string;
    awaiting_origin_intake: boolean;
    origin_hub?: string | null;
    origin_hub_latitude?: number | null;
    origin_hub_longitude?: number | null;
    destination_hub: string;
    destination_hub_latitude?: number | null;
    destination_hub_longitude?: number | null;
    rider_name: string;
    total_amount: number;
    payment_method: string;
    item_count: number;
    items: Array<{ name: string; quantity: number }>;
    created_at: string;
    guidance: ParcelGuidance;
    eta?: EtaPresentation | null;
    dispatch_delay_label?: string | null;
    dispatch_escalation_tier?: string | null;
    can_reassign_first_mile?: boolean;
    can_reassign_final_mile?: boolean;
    reassign_riders?: Array<{
        id: number;
        name: string;
        assigned_barangay: string | null;
        vehicle_type: string;
    }>;
}

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    deliveries: {
        data: DeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    counts: {
        all: number;
        in_hub: number;
        out_for_delivery: number;
        ready_pickup: number;
        handovers_today: number;
    };
    filters: {
        search: string;
        status: string;
        delivery_type: string;
    };
    eligibleRiders: Array<{
        id: number;
        name: string;
        assigned_barangay: string | null;
        vehicle_type: string;
    }>;
    basePath: string;
    canScan: boolean;
}

function deliveryDirectionsUrl(delivery: DeliveryItem): string | null {
    const origin =
        delivery.pickup_latitude && delivery.pickup_longitude
            ? `${delivery.pickup_latitude},${delivery.pickup_longitude}`
            : delivery.origin_hub_latitude && delivery.origin_hub_longitude
                ? `${delivery.origin_hub_latitude},${delivery.origin_hub_longitude}`
                : delivery.pickup_address || null;
    const destination =
        delivery.delivery_type === 'hub_self_pickup' && delivery.destination_hub_latitude && delivery.destination_hub_longitude
            ? `${delivery.destination_hub_latitude},${delivery.destination_hub_longitude}`
            : delivery.destination_latitude && delivery.destination_longitude
                ? `${delivery.destination_latitude},${delivery.destination_longitude}`
                : delivery.delivery_address || `${delivery.destination_barangay}, ${delivery.shipping_city}`;
    if (!destination) return null;
    const params = new URLSearchParams({ api: '1', destination, travelmode: 'driving' });
    if (origin) params.set('origin', origin);
    return `https://www.google.com/maps/dir/?${params.toString()}`;
}

export default function HubDeliveries({ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }: Props) {
    return <HubRegistry key={activeHub?.id ?? 'unassigned'} {...{ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }} />;
}

function HubRegistry({ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [choosingId, setChoosingId] = useState<number | null>(null);
    const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
    const [reassignTarget, setReassignTarget] = useState<DeliveryItem | null>(null);
    const [reassignRiderId, setReassignRiderId] = useState('');
    const [reassignReason, setReassignReason] = useState('');
    const pending = useRef(false);
    const [busy, setBusy] = useState(false);
    const [readError, setReadError] = useState('');
    const [activeCategory, setActiveCategory] = useState<'all' | 'action' | 'waiting' | 'counter'>('all');
    useEffect(() => setSearchTerm(filters.search || ''), [filters.search]);
    const finish = () => { pending.current = false; setBusy(false); };
    const listProps = ['auth', 'activeHub', 'hubs', 'basePath', 'deliveries', 'counts', 'eligibleRiders', 'filters', 'canScan', 'flash', 'errors'];

    const handleFilter = (status?: string, deliveryType?: string, search?: string) => {
        if (pending.current) return;
        pending.current = true; setBusy(true); setReadError('');
        const query = new URLSearchParams({ status: status ?? filters.status, delivery_type: deliveryType ?? filters.delivery_type, search: search ?? searchTerm });
        if (activeHub) query.set('hub_id', String(activeHub.id));
        void visitPortal(`${basePath}/deliveries?${query}`, {
            preserveState: true, preserveScroll: true, only: listProps,
            onError: errors => setReadError(Object.values(errors)[0] || 'Could not update the parcel list. Check your connection and try again.'),
            onFinish: finish, onCancel: finish,
        });
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        handleFilter(undefined, undefined, searchTerm);
    };

    const sortParcel = (delivery: DeliveryItem) => {
        if (pending.current || !delivery.guidance.can_act || delivery.guidance.action !== 'sort') return;
        pending.current = true; setBusy(true); setReadError('');
        router.post(`${basePath}/sort`, {
            delivery_id: delivery.id,
            hub_id: activeHub?.id,
            barangay: delivery.destination_barangay,
            bin: delivery.destination_bin,
        }, { preserveScroll: true, onError: errors => setReadError(Object.values(errors)[0] || 'Could not sort this parcel. Refresh the list and try again.'), onFinish: finish });
    };

    const assignRider = (delivery: DeliveryItem) => {
        const riderId = selectedRiders[delivery.id];
        if (pending.current || !delivery.guidance.can_act || delivery.guidance.action !== 'assign' || !delivery.guidance.eligible_rider_ids.includes(Number(riderId))) return;
        pending.current = true; setBusy(true); setReadError('');

        router.post(`${basePath}/deliveries/${delivery.id}/assign-rider`, {
            rider_id: Number(riderId),
            hub_id: activeHub?.id,
        }, { preserveScroll: true, onSuccess: page => { const error = (page.props.flash as { error?: string } | undefined)?.error;if (error) setReadError(error);else setChoosingId(null); }, onError: errors => setReadError(Object.values(errors)[0] || 'Could not assign this rider. Refresh the list and try again.'), onFinish: finish });
    };

    const submitReassign = () => {
        if (!reassignTarget || pending.current) return;
        pending.current = true; setBusy(true); setReadError('');
        router.post(`${basePath}/deliveries/${reassignTarget.id}/reassign-rider`, {
            new_rider_id: reassignRiderId ? Number(reassignRiderId) : null,
            reason: reassignReason,
        }, {
            preserveScroll: true,
            onSuccess: page => {
                const error = (page.props.flash as { error?: string } | undefined)?.error;
                if (error) {
                    setReadError(error);
                } else {
                    setReassignTarget(null);
                    setReassignRiderId('');
                    setReassignReason('');
                }
            },
            onError: errors => setReadError(Object.values(errors)[0] || 'Could not reassign rider. Verify eligibility and try again.'),
            onFinish: finish,
        });
    };

    const choosing = deliveries.data.find(delivery => delivery.id === choosingId) ?? null;
    const ridersForChoice = choosing ? eligibleRiders.filter(rider => choosing.guidance.eligible_rider_ids.includes(rider.id)) : [];
    const actionRequiredCount = deliveries.data.filter(delivery => delivery.guidance.can_act).length;
    const waitingCount = deliveries.data.filter(delivery => !delivery.guidance.can_act && delivery.delivery_type !== 'hub_self_pickup').length;
    const counterReadyCount = deliveries.data.filter(delivery => delivery.delivery_type === 'hub_self_pickup' || delivery.guidance.ready_for_collection).length;
    const visibleDeliveries = deliveries.data.filter((delivery) => {
        if (activeCategory === 'action') return delivery.guidance.can_act;
        if (activeCategory === 'waiting') return !delivery.guidance.can_act && delivery.delivery_type !== 'hub_self_pickup';
        if (activeCategory === 'counter') return delivery.delivery_type === 'hub_self_pickup' || delivery.guidance.ready_for_collection;
        return true;
    });

    return <DashboardLayout title="Parcels & waybills"><Head title="Parcels & waybills — BagooPH" />
        <div className="space-y-5 font-sans" aria-busy={busy || undefined}>
            <HubPageHeader
                title="Parcels & waybills"
                roleLabel={canScan ? 'Hub operator' : 'View only'}
                icon={Package}
                actions={canScan && <PortalLink href={`${basePath}/scan${activeHub ? `?hub_id=${activeHub.id}` : ''}`} className={hubPrimary}><ScanLine className="h-4 w-4" aria-hidden="true" />Open scanner</PortalLink>}
            />
            <p role="status" className={busy ? 'text-sm text-ink-600' : 'sr-only'}>{busy ? 'Updating parcel records…' : ''}</p>
            {readError && <p role="alert" className="rounded-control border border-brand-100 bg-brand-50 p-4 text-sm text-brand-700">{readError}</p>}

            {/* Numbers double as queue filters */}
            <div role="group" aria-label="Queue" className="grid grid-cols-2 overflow-hidden rounded-card border border-line-200 bg-white lg:grid-cols-4">
                {[
                    { key: 'all', label: 'All parcels', value: counts.all },
                    { key: 'in_hub', label: 'In hub custody', value: counts.in_hub },
                    { key: 'ready_pickup', label: 'Counter queue', value: counts.ready_pickup },
                ].map(({ key, label, value }) => {
                    const selected = filters.status === key;
                    return (
                        <button
                            key={key}
                            type="button"
                            disabled={busy}
                            aria-pressed={selected}
                            onClick={() => handleFilter(key)}
                            className={`group relative border-b border-r border-line-200 p-4 pr-9 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:p-5 lg:border-b-0 ${selected ? 'bg-brand-50/60' : 'hover:bg-surface-50'}`}
                        >
                            {selected && <span aria-hidden="true" className="absolute inset-x-0 bottom-0 h-0.5 bg-brand-500" />}
                            <ChevronRight aria-hidden="true" className={`absolute right-3 top-4 h-4 w-4 transition-transform group-hover:translate-x-0.5 ${selected ? 'text-brand-500' : 'text-ink-400'}`} />
                            <span className={`block text-[13px] ${selected ? 'font-medium text-brand-600' : 'text-ink-600'}`}>{label}</span>
                            <span className="mt-1 block text-[28px] font-bold leading-9 tabular-nums text-ink-900">{value}</span>
                        </button>
                    );
                })}
                <div className="p-4 sm:p-5">
                    <span className="block text-[13px] text-ink-600">Handed over today</span>
                    <span className="mt-1 block text-[28px] font-bold leading-9 tabular-nums text-success">{counts.handovers_today}</span>
                </div>
            </div>

            <section className="flex flex-wrap items-center gap-3" aria-label="Filter parcels">
                <form onSubmit={handleSearchSubmit} className="flex min-w-56 flex-1 gap-2">
                    <div className="relative min-w-0 flex-1">
                        <label className="sr-only" htmlFor="parcel-search">Search waybill, order or buyer</label>
                        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-500" aria-hidden="true" />
                        <input
                            id="parcel-search"
                            maxLength={120}
                            value={searchTerm}
                            disabled={busy}
                            onChange={event => setSearchTerm(event.target.value)}
                            className={`${hubInput} pl-9`}
                            placeholder="Search waybill, order or buyer"
                        />
                    </div>
                    <button disabled={busy} className={hubButton}>Search</button>
                </form>
                <div role="group" aria-label="Delivery method" className="inline-flex rounded-control border border-line-300 bg-white p-0.5">
                    {[
                        { key: 'all', label: 'All' },
                        { key: 'doorstep', label: 'Doorstep' },
                        { key: 'hub_self_pickup', label: 'Counter' },
                    ].map(option => {
                        const selected = filters.delivery_type === option.key;
                        return (
                            <button
                                key={option.key}
                                type="button"
                                disabled={busy}
                                aria-pressed={selected}
                                onClick={() => handleFilter(undefined, option.key)}
                                className={`min-h-9 rounded-[4px] px-3 text-[13px] font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 ${selected ? 'bg-brand-50 text-brand-600' : 'text-ink-700 hover:bg-surface-100'}`}
                            >
                                {option.label}
                            </button>
                        );
                    })}
                </div>
            </section>

            <HubCategoryTabs
                label="Parcel work categories"
                activeId={activeCategory}
                onChange={(id) => setActiveCategory(id as typeof activeCategory)}
                tabs={[
                    { id: 'all', label: 'This page', count: deliveries.data.length },
                    { id: 'action', label: 'Needs action', count: actionRequiredCount },
                    { id: 'waiting', label: 'Waiting', count: waitingCount },
                    ...(counterReadyCount > 0 ? [{ id: 'counter', label: 'For counter', count: counterReadyCount }] : []),
                ]}
            />

            {/* Parcel Registry Cards */}
            {!visibleDeliveries.length ? (
                <div className="rounded-xl border border-zinc-200/90 bg-white p-6 shadow-xs">
                    <HubEmpty title="No parcels here" description="Try another filter or search." />
                </div>
            ) : (
                <div className="grid items-start gap-4 xl:grid-cols-2">
                    {visibleDeliveries.map(delivery => (
                        <article
                            key={delivery.id}
                            className="overflow-hidden rounded-xl border border-zinc-200/90 bg-white shadow-xs transition hover:border-zinc-300"
                        >
                            {/* Top Notion Header Bar */}
                            <header className="flex items-start justify-between gap-4 border-b border-zinc-100 bg-zinc-50/40 px-5 py-4">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-zinc-100 px-2.5 py-0.5 text-xs font-bold tabular-nums tracking-wide text-ink-900">
                                            <span className="text-[10px] font-sans font-bold uppercase text-zinc-500">WAYBILL:</span>
                                            {delivery.tracking_number}
                                        </span>
                                        <span className="rounded-md border border-zinc-200 bg-white px-2 py-0.5 tabular-nums text-[11px] font-semibold text-zinc-600">
                                            Order Ref: #{delivery.order_number}
                                        </span>
                                    </div>
                                    <h2 className="mt-2.5 text-sm font-bold text-zinc-900">{delivery.buyer_name}</h2>
                                    <p className="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-500">
                                        <MapPin className="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                        <span>{delivery.destination_barangay}, {delivery.shipping_city}</span>
                                    </p>
                                </div>
                                <div className="shrink-0 text-right space-y-1.5">
                                    <HubBadge>{delivery.delivery_type === 'hub_self_pickup' ? 'Counter pickup' : 'Doorstep'}</HubBadge>
                                    <div>
                                        <p className="text-sm font-bold text-zinc-900 tabular-nums">
                                            ₱{delivery.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                        </p>
                                        <p className="text-[11px] uppercase font-medium text-zinc-500">
                                            {delivery.payment_method.replaceAll('_', ' ')}
                                        </p>
                                    </div>
                                </div>
                            </header>

                            {/* Card Body */}
                            <div className="space-y-4 p-5">
                                <div className="flex items-center justify-between gap-3 text-xs text-zinc-700 border-b border-zinc-100 pb-3">
                                    <p className="font-medium text-zinc-800 truncate">
                                        {delivery.items?.[0]?.name ?? `${delivery.item_count} items`}
                                        {delivery.items?.length > 1 ? ` + ${delivery.items.length - 1} more` : ''}
                                    </p>
                                    <span className="shrink-0 tabular-nums text-[11px] text-zinc-400">
                                        {delivery.created_at}
                                    </span>
                                </div>

                                {/* Custody & Route Strip */}
                                <div className="grid gap-3 rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-3.5 sm:grid-cols-2">
                                    <HubParcelState guidance={delivery.guidance} />
                                    <div className="space-y-1.5 text-xs text-zinc-600 border-t sm:border-t-0 sm:border-l border-zinc-200/70 pt-2.5 sm:pt-0 sm:pl-3.5">
                                        <p className="flex items-center justify-between gap-2">
                                            <span className="text-zinc-500">Sorting bin:</span>
                                            <span className="tabular-nums font-semibold text-zinc-900">{delivery.destination_bin ?? 'Not assigned'}</span>
                                        </p>
                                        <p className="flex items-center justify-between gap-2">
                                            <span className="text-zinc-500">Delivery rider:</span>
                                            <span className="font-semibold text-zinc-900">{delivery.rider_name}</span>
                                        </p>
                                        <p className="flex items-center justify-between gap-2">
                                            <span className="text-zinc-500">Target hub:</span>
                                            <span className="font-semibold text-zinc-800 truncate">{delivery.destination_hub}</span>
                                        </p>
                                        {delivery.eta?.label && (
                                            <p className="flex items-center justify-between gap-2">
                                                <span className="text-zinc-500">ETA:</span>
                                                <span className="font-semibold text-zinc-900 truncate">{delivery.eta.label}</span>
                                            </p>
                                        )}
                                    </div>
                                </div>

                                {(delivery.pickup_address || delivery.delivery_address) && (
                                    <div className="rounded-lg border border-zinc-200/80 bg-white p-3 text-xs space-y-2">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="inline-flex items-center gap-1.5 font-semibold text-zinc-800">
                                                <Store className="h-3.5 w-3.5 text-zinc-500" />
                                                <span className="truncate">{delivery.pickup_store_name || 'Seller Pickup'}</span>
                                                <ArrowRight className="h-3 w-3 text-zinc-400 shrink-0" />
                                                <span className="truncate">{delivery.destination_hub}</span>
                                                <ArrowRight className="h-3 w-3 text-zinc-400 shrink-0" />
                                                <span className="truncate text-brand-500">
                                                    {delivery.delivery_type === 'hub_self_pickup' ? 'Counter' : delivery.destination_barangay}
                                                </span>
                                            </span>
                                            {deliveryDirectionsUrl(delivery) && (
                                                <a
                                                    href={deliveryDirectionsUrl(delivery)!}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded border border-zinc-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-ink-900 shadow-2xs transition hover:border-brand-500 hover:text-brand-500"
                                                >
                                                    <ExternalLink className="h-3 w-3" />
                                                    Route directions
                                                </a>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5 text-[11px] text-zinc-600 sm:grid-cols-2">
                                            {delivery.pickup_address && (
                                                <p className="truncate" title={delivery.pickup_address}>
                                                    <span className="font-semibold text-zinc-700">Pickup:</span> {delivery.pickup_address}
                                                </p>
                                            )}
                                            {delivery.delivery_address && (
                                                <p className="truncate" title={delivery.delivery_address}>
                                                    <span className="font-semibold text-zinc-700">Dropoff:</span> {delivery.delivery_address}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {delivery.dispatch_delay_label && (
                                    <p role="status" className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                                        {delivery.dispatch_delay_label}{delivery.dispatch_escalation_tier ? ` · Tier: ${delivery.dispatch_escalation_tier.replaceAll('_', ' ')}` : ''}
                                    </p>
                                )}

                                <HubParcelNextStep guidance={delivery.guidance} hideLink={['sort', 'assign'].includes(delivery.guidance.action)} />

                                <div className="flex flex-wrap items-center gap-2">
                                    {delivery.guidance.can_act && delivery.guidance.action === 'sort' && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => sortParcel(delivery)}
                                            className="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-brand-500 bg-brand-500 px-4 py-2 text-xs font-bold text-white hover:bg-[#c70b3a] shadow-2xs transition cursor-pointer"
                                        >
                                            <Package className="h-3.5 w-3.5" />
                                            Sort to bin
                                        </button>
                                    )}

                                    {delivery.guidance.can_act && delivery.guidance.action === 'assign' && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => { setChoosingId(delivery.id); setReadError(''); }}
                                            className="inline-flex min-h-9 items-center justify-center gap-2 rounded-lg border border-brand-500 bg-brand-500 px-4 py-2 text-xs font-bold text-white hover:bg-[#c70b3a] shadow-2xs transition cursor-pointer"
                                        >
                                            <UserRound className="h-3.5 w-3.5" />
                                            Choose delivery rider
                                        </button>
                                    )}

                                    {(delivery.can_reassign_first_mile || delivery.can_reassign_final_mile) && (
                                        <button
                                            type="button"
                                            disabled={busy}
                                            onClick={() => { setReassignTarget(delivery); setReassignRiderId(''); setReassignReason(''); setReadError(''); }}
                                            className="inline-flex min-h-9 items-center justify-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 shadow-2xs transition cursor-pointer"
                                        >
                                            <RefreshCw className="h-3.5 w-3.5" />
                                            {delivery.can_reassign_final_mile ? 'Reassign delivery rider' : 'Reassign pickup rider'}
                                        </button>
                                    )}
                                </div>
                            </div>
                        </article>
                    ))}
                </div>
            )}
            {deliveries.last_page > 1 && <nav aria-label="Parcel pages" className="flex flex-wrap items-center justify-between gap-3 text-xs text-zinc-500"><span>Page {deliveries.current_page} of {deliveries.last_page} · {deliveries.total} parcels</span><div className="flex flex-wrap gap-2">{deliveries.links.map((link, index) => link.url ? <PortalLink key={index} href={link.url} only={listProps} preserveScroll preserveState aria-current={link.active ? 'page' : undefined} className={`inline-flex min-h-9 items-center justify-center rounded-xl border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-800 shadow-xs hover:bg-zinc-50 transition cursor-pointer ${link.active ? 'border-brand-600 bg-brand-500 text-white font-bold' : ''}`}>{paginationLabel(link.label)}</PortalLink> : <span key={index} aria-disabled="true" className="px-3 py-1.5 text-zinc-400">{paginationLabel(link.label)}</span>)}</div></nav>}
        </div>
        <CourierDialog open={Boolean(choosing)} onClose={() => setChoosingId(null)} busy={busy} title="Choose delivery rider">{choosing && <div className="space-y-4"><p className="text-sm leading-6 text-zinc-600">{choosing.tracking_number} · {choosing.destination_barangay}. Only riders eligible for this parcel are listed.</p><RiderPicker riders={eligibleRiders} eligibleIds={choosing.guidance.eligible_rider_ids} value={selectedRiders[choosing.id] ?? ''} onChange={value => setSelectedRiders(current => ({ ...current, [choosing.id]: value }))} disabled={busy} />{!ridersForChoice.length && <HubEmpty title="No eligible riders available" description="Riders need the right service area and vehicle, duty enabled and acknowledged COD. Ask the rider or company manager to review readiness." icon={UserRound} action={<PortalLink href={route('hub.network')} className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 shadow-xs transition cursor-pointer">Review hub team</PortalLink>} />}{readError && <p role="alert" className="text-sm text-red-700">{readError}</p>}<div className="flex justify-end gap-2 border-t border-zinc-200 pt-4"><button type="button" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-sm font-semibold text-zinc-800 hover:bg-zinc-50 shadow-xs transition cursor-pointer" disabled={busy} onClick={() => setChoosingId(null)}>Cancel</button><button type="button" className="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-brand-500 bg-brand-500 px-3.5 py-2 text-sm font-semibold text-white hover:bg-[#c70b3a] shadow-xs transition cursor-pointer" disabled={busy || !choosing.guidance.eligible_rider_ids.includes(Number(selectedRiders[choosing.id]))} onClick={() => assignRider(choosing)}>{busy ? 'Assigning…' : 'Assign rider'}</button></div></div>}</CourierDialog>
        <CourierDialog open={Boolean(reassignTarget)} onClose={() => setReassignTarget(null)} busy={busy} title={reassignTarget?.can_reassign_final_mile ? 'Reassign delivery rider' : 'Reassign first-mile pickup rider'}>
            {reassignTarget && (
                <div className="space-y-4">
                    <p className="text-xs leading-5 text-zinc-600">
                        Waybill <span className="font-bold text-zinc-900">{reassignTarget.tracking_number}</span> · Current rider: <span className="font-semibold text-zinc-900">{reassignTarget.rider_name}</span>. Reassignment is permitted only before physical handover.
                    </p>
                    <label className="block text-xs font-semibold text-zinc-800">
                        Replacement rider
                        <select
                            disabled={busy}
                            value={reassignRiderId}
                            onChange={(e) => setReassignRiderId(e.target.value)}
                            className="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-brand-500 focus:ring-brand-500/15"
                        >
                            {reassignTarget.can_reassign_first_mile && <option value="">Return to open pickup pool</option>}
                            {reassignTarget.can_reassign_final_mile && <option value="">Select eligible destination rider</option>}
                            {(reassignTarget.reassign_riders ?? []).map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.name} ({r.vehicle_type}{r.assigned_barangay ? ` · ${r.assigned_barangay}` : ''})
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-xs font-semibold text-zinc-800">
                        Operational reason (min 5 characters)
                        <textarea
                            rows={2}
                            required
                            disabled={busy}
                            value={reassignReason}
                            onChange={(e) => setReassignReason(e.target.value)}
                            placeholder="e.g. Original rider unreachable; rebalancing route"
                            className="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-brand-500 focus:ring-brand-500/15"
                        />
                    </label>
                    {readError && <p role="alert" className="text-xs text-red-700">{readError}</p>}
                    <div className="flex justify-end gap-2 border-t border-zinc-200 pt-4">
                        <button type="button" disabled={busy} onClick={() => setReassignTarget(null)} className={hubButton}>Cancel</button>
                        <button
                            type="button"
                            disabled={busy || reassignReason.trim().length < 5 || (Boolean(reassignTarget.can_reassign_final_mile) && !reassignRiderId)}
                            onClick={submitReassign}
                            className={hubPrimary}
                        >
                            {busy ? 'Reassigning…' : 'Confirm reassignment'}
                        </button>
                    </div>
                </div>
            )}
        </CourierDialog>
    </DashboardLayout>;
}
