import React, { useEffect, useRef, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import PortalLink from '@/Components/PortalLink';
import { visitPortal } from '@/utils/catalogueRuntime';
import { paginationLabel } from '@/utils/catalogueNavigation';
import DashboardLayout from '@/Layouts/DashboardLayout';
import RiderPicker from '@/Components/Logistics/RiderPicker';
import { CourierDialog } from '@/Components/CourierUI';
import { HubBadge, HubEmpty, HubPageHeader, hubButton, hubInput, hubPrimary } from '@/Components/Logistics/Workspace';
import { HubParcelState, HubParcelNextStep, ParcelGuidance } from '@/Components/HubParcelGuidance';
import { Package, Search, Store, MapPin, ScanLine, CheckCircle2, UserRound } from 'lucide-react';

interface DeliveryItem {
    id: number;
    tracking_number: string;
    order_number: string;
    buyer_name: string;
    buyer_phone: string;
    destination_barangay: string;
    shipping_city: string;
    status: string;
    delivery_type: string;
    destination_bin: string | null;
    current_hub: string;
    awaiting_origin_intake: boolean;
    destination_hub: string;
    rider_name: string;
    total_amount: number;
    payment_method: string;
    item_count: number;
    items: Array<{ name: string; quantity: number }>;
    created_at: string;
    guidance: ParcelGuidance;
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

export default function HubDeliveries({ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }: Props) {
    return <HubRegistry key={activeHub?.id ?? 'unassigned'} {...{ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }} />;
}

function HubRegistry({ activeHub, deliveries, counts, filters, eligibleRiders, basePath, canScan }: Props) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [choosingId, setChoosingId] = useState<number | null>(null);
    const [selectedRiders, setSelectedRiders] = useState<Record<number, string>>({});
    const pending = useRef(false);
    const [busy, setBusy] = useState(false);
    const [readError, setReadError] = useState('');
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

    const choosing = deliveries.data.find(delivery => delivery.id === choosingId) ?? null;
    const ridersForChoice = choosing ? eligibleRiders.filter(rider => choosing.guidance.eligible_rider_ids.includes(rider.id)) : [];
    return <DashboardLayout title="Parcels & waybills"><Head title="Parcels & waybills — BagooPH" />
        <div className="space-y-6" aria-busy={busy || undefined}>
            <HubPageHeader title="Parcels & waybills" icon={Package} description="Find a parcel, check who holds it and take its next permitted action." actions={canScan && <PortalLink href={`${basePath}/scan${activeHub ? `?hub_id=${activeHub.id}` : ''}`} className={hubPrimary}><ScanLine className="h-4 w-4" />Open scanner</PortalLink>} />
            <p role="status" className={busy ? 'text-sm text-slate-600' : 'sr-only'}>{busy ? 'Updating parcel records…' : ''}</p>
            {readError && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800">{readError}</p>}
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[
                { label: 'Facility queue', value: counts.all, Icon: Package },
                { label: 'Recorded hub custody', value: counts.in_hub, Icon: MapPin },
                { label: 'Counter queue', value: counts.ready_pickup, Icon: Store },
                { label: 'Handed over today', value: counts.handovers_today, Icon: CheckCircle2 },
            ].map(({ label, value, Icon }) => <div key={label} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm"><Icon className="h-5 w-5 text-slate-400" /><div><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-xl font-bold text-slate-900">{value}</p></div></div>)}</div>
            <section className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm" aria-label="Filter parcels">
                <form onSubmit={handleSearchSubmit} className="flex min-w-56 flex-1 gap-2"><div className="relative min-w-0 flex-1"><label className="sr-only" htmlFor="parcel-search">Search waybill, order or buyer</label><Search className="absolute left-3 top-3 h-4 w-4 text-slate-400" /><input id="parcel-search" value={searchTerm} disabled={busy} onChange={event => setSearchTerm(event.target.value)} className={`${hubInput} pl-9`} placeholder="Waybill, order or buyer" /></div><button disabled={busy} className={hubButton}>Search</button></form>
                <label className="text-xs font-semibold text-slate-600">Queue<select disabled={busy} value={filters.status} onChange={event => handleFilter(event.target.value)} className={`${hubInput} mt-1`}><option value="all">All parcels</option><option value="in_hub">In hub custody</option><option value="ready_pickup">Counter queue</option></select></label>
                <label className="text-xs font-semibold text-slate-600">Delivery method<select disabled={busy} value={filters.delivery_type} onChange={event => handleFilter(undefined, event.target.value)} className={`${hubInput} mt-1`}><option value="all">All methods</option><option value="doorstep">Doorstep delivery</option><option value="hub_self_pickup">Counter pickup</option></select></label>
            </section>
            <p className="text-xs leading-5 text-slate-500">The queue includes rider handovers awaiting intake. A queue entry does not prove physical receipt. Today’s handovers use retained scans and Philippine dates.</p>
            {!deliveries.data.length ? <div className="rounded-xl border border-slate-200 bg-white"><HubEmpty title="No parcels in this view" description="Try another filter or search. Incoming parcels appear after their recorded pickup or transfer." /></div> : <div className="grid items-start gap-4 xl:grid-cols-2">{deliveries.data.map(delivery => <article key={delivery.id} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <header className="flex items-start justify-between gap-3 border-b border-slate-100 p-5"><div className="min-w-0"><h2 className="text-sm font-bold text-slate-900">{delivery.buyer_name}</h2><p className="mt-1 flex items-start gap-1.5 text-xs leading-5 text-slate-500"><MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" />{delivery.destination_barangay}, {delivery.shipping_city}</p><p className="mt-2 break-words text-xs font-medium text-slate-700">{delivery.tracking_number}</p></div><HubBadge>{delivery.delivery_type === 'hub_self_pickup' ? 'Counter pickup' : 'Doorstep'}</HubBadge></header>
                <div className="space-y-4 p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0 flex-1"><p className="text-sm font-medium text-slate-800">{delivery.items?.[0]?.name ?? `${delivery.item_count} items`}{delivery.items?.length > 1 ? ` + ${delivery.items.length - 1} more` : ''}</p><p className="mt-1 text-xs text-slate-500">Order {delivery.order_number}</p></div><div className="text-right"><p className="text-sm font-semibold text-slate-900">₱{delivery.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}</p><p className="mt-1 text-xs text-slate-500">{delivery.payment_method.replaceAll('_', ' ')}</p></div></div>
                    <div className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3.5 sm:grid-cols-2"><HubParcelState guidance={delivery.guidance} /><div className="space-y-2 text-xs text-slate-600"><p>Sorting bin: <span className="font-semibold text-slate-800">{delivery.destination_bin ?? 'Not assigned'}</span></p><p>Delivery rider: <span className="font-semibold text-slate-800">{delivery.rider_name}</span></p></div></div>
                    <HubParcelNextStep guidance={delivery.guidance} hideLink={['sort', 'assign'].includes(delivery.guidance.action)} />
                    {delivery.guidance.can_act && delivery.guidance.action === 'sort' && <button type="button" disabled={busy} onClick={() => sortParcel(delivery)} className={hubPrimary}><Package className="h-4 w-4" />Sort to bin</button>}
                    {delivery.guidance.can_act && delivery.guidance.action === 'assign' && <button type="button" disabled={busy} onClick={() => { setChoosingId(delivery.id);setReadError(''); }} className={hubPrimary}><UserRound className="h-4 w-4" />Choose delivery rider</button>}
                </div>
            </article>)}</div>}
            {deliveries.last_page > 1 && <nav aria-label="Parcel pages" className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>Page {deliveries.current_page} of {deliveries.last_page} · {deliveries.total} parcels</span><div className="flex flex-wrap gap-2">{deliveries.links.map((link, index) => link.url ? <PortalLink key={index} href={link.url} only={listProps} preserveScroll preserveState aria-current={link.active ? 'page' : undefined} className={`${hubButton} ${link.active ? 'border-rose-300 bg-rose-50 text-[#C20836]' : ''}`}>{paginationLabel(link.label)}</PortalLink> : <span key={index} aria-disabled="true" className="px-3 py-2 text-slate-400">{paginationLabel(link.label)}</span>)}</div></nav>}
        </div>
        <CourierDialog open={Boolean(choosing)} onClose={() => setChoosingId(null)} busy={busy} title="Choose delivery rider">{choosing && <div className="space-y-4"><p className="text-sm leading-6 text-slate-600">{choosing.tracking_number} · {choosing.destination_barangay}. Only riders eligible for this parcel are listed.</p><RiderPicker riders={eligibleRiders} eligibleIds={choosing.guidance.eligible_rider_ids} value={selectedRiders[choosing.id] ?? ''} onChange={value => setSelectedRiders(current => ({ ...current, [choosing.id]: value }))} disabled={busy} />{!ridersForChoice.length && <HubEmpty title="No eligible riders available" description="Riders need the right service area and vehicle, duty enabled and acknowledged COD. Ask the rider or company manager to review readiness." icon={UserRound} action={<PortalLink href={route('hub.network')} className={hubButton}>Review hub team</PortalLink>} />}{readError && <p role="alert" className="text-sm text-red-700">{readError}</p>}<div className="flex justify-end gap-2 border-t border-slate-200 pt-4"><button type="button" className={hubButton} disabled={busy} onClick={() => setChoosingId(null)}>Cancel</button><button type="button" className={hubPrimary} disabled={busy || !choosing.guidance.eligible_rider_ids.includes(Number(selectedRiders[choosing.id]))} onClick={() => assignRider(choosing)}>{busy ? 'Assigning…' : 'Assign rider'}</button></div></div>}</CourierDialog>
    </DashboardLayout>;
}
