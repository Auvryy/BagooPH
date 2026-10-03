import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Camera, CheckCircle2, MapPin, Package, Search, Truck } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import CourierJobMap from '@/Components/CourierJobMap';
import { CourierBadge, CourierDialog, CourierEmpty, CourierFieldError, CourierPanel, CourierStop, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierMoney, courierPath, selectCourierMapJob, type CourierMapJob, type CourierPlace, type CourierScope } from '@/utils/courier';
import { PageProps } from '@/types';
import useCourierRequestError from '@/hooks/useCourierRequestError';

interface PickupTask {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    status: string;
    itemCount: number;
    merchant: CourierPlace & { phone: string | null };
    originHub: CourierPlace;
    assignedAt: string | null;
    nextAction: 'claim_pickup' | 'confirm_pickup' | 'await_origin_hub_scan' | null;
    canMessage: boolean;
}

interface FinalMileTask {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    status: string;
    recipient: CourierPlace & { phone: string | null };
    payment: { method: string; codAmount: number | null };
    destinationHub: CourierPlace;
    assignedAt: string | null;
    nextAction: 'start_delivery' | 'complete_delivery';
    canMessage: boolean;
}

interface Activity {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    recipientName: string;
    deliveryAddress: string;
    paymentMethod: string;
    destinationHub: string | null;
    deliveredAt: string | null;
}

interface Props {
    scope?: CourierScope;
    isOnline?: boolean;
    stats?: { availablePickups?: number; activePickups?: number; activePickupLimit?: number; finalMileTasks?: number; completedToday?: number };
    queues?: { availablePickups?: PickupTask[]; pickupTasks?: PickupTask[]; finalMileTasks?: FinalMileTask[]; recentActivity?: Activity[] };
}

type Tab = 'pickup' | 'final_mile' | 'available' | 'activity';
type ActionTarget = { id: number; trackingNumber: string; status: 'picked_up' | 'out_for_delivery' | 'delivered' };
const actionLabels = { picked_up: 'Confirm pickup', out_for_delivery: 'Start delivery', delivered: 'Record delivery' };

export default function CourierDeliveries({ scope, isOnline = false, stats, queues }: Props) {
    const pickups = queues?.pickupTasks ?? [];
    const available = queues?.availablePickups ?? [];
    const deliveries = queues?.finalMileTasks ?? [];
    const activity = queues?.recentActivity ?? [];
    const [tab, setTab] = useState<Tab>(deliveries.length ? 'final_mile' : pickups.length ? 'pickup' : 'available');
    const [search, setSearch] = useState('');
    const [selectedMapKey, setSelectedMapKey] = useState<string | null>(null);
    const mapRegion = useRef<HTMLDivElement>(null);
    const [target, setTarget] = useState<ActionTarget | null>(null);
    const [notes, setNotes] = useState('');
    const [proof, setProof] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loadingId, setLoadingId] = useState<number | null>(null);
    const pending = useRef(false);
    useCourierRequestError(pending, (message) => setErrors({ status: message }));
    const normalizedSearch = search.trim().toLowerCase();
    const matches = (...values: Array<string | null | undefined>) => values.some((value) => value?.toLowerCase().includes(normalizedSearch));
    const matchingPickups = (items: PickupTask[]) => items.filter((task) => matches(task.trackingNumber, task.orderNumber, task.merchant.name, task.merchant.address, task.originHub.name, task.originHub.address));
    const matchingDeliveries = deliveries.filter((task) => matches(task.trackingNumber, task.orderNumber, task.recipient.name, task.recipient.address, task.destinationHub.name, task.destinationHub.address));
    const mapJobs: CourierMapJob[] = tab === 'pickup' || tab === 'available'
        ? matchingPickups(tab === 'pickup' ? pickups : available).map((task) => {
            const collected = task.nextAction === 'await_origin_hub_scan';
            return {
                key: `${tab}-${task.id}`, trackingNumber: task.trackingNumber,
                stage: tab === 'available' ? 'Available pickup' : collected ? 'Bring to origin hub' : 'Collect from seller',
                stopLabel: collected ? 'Origin Bayan Hub' : 'Seller pickup point',
                place: collected ? task.originHub : task.merchant, preview: tab === 'available',
                instruction: collected ? 'Hub staff must record intake before this parcel travels onward.' : tab === 'available' ? 'This is a preview. Claim the pickup before collecting the parcel.' : 'Collect this parcel from the seller, then bring it to the origin Bayan Hub.',
            };
        }) : tab === 'final_mile' ? matchingDeliveries.map((task) => ({
            key: `final_mile-${task.id}`, trackingNumber: task.trackingNumber,
            stage: task.nextAction === 'start_delivery' ? 'Collect at destination hub' : 'Out for delivery',
            stopLabel: task.nextAction === 'start_delivery' ? 'Destination Bayan Hub' : 'Saved buyer destination',
            place: task.nextAction === 'start_delivery' ? task.destinationHub : task.recipient, preview: false,
            instruction: task.nextAction === 'start_delivery' ? 'Collect the assigned parcel from this hub before starting delivery.' : 'Record the handoff with a proof photo. The buyer confirms receipt separately.',
        })) : [];
    const selectedMapJob = selectCourierMapJob(mapJobs, selectedMapKey);
    const showOnMap = (key: string) => {
        setSelectedMapKey(key);
        requestAnimationFrame(() => {
            mapRegion.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
            mapRegion.current?.querySelector<HTMLElement>('#rider-map-title')?.focus({ preventScroll: true });
        });
    };
    const mapButton = (key: string) => <button type="button" onClick={() => showOnMap(key)} aria-pressed={selectedMapJob?.key === key} aria-controls="rider-job-map" className={courierClasses(courierButton, 'mt-3', selectedMapJob?.key === key && 'border-[#C20836] bg-[#FDF2F4] text-[#C20836]')}><MapPin className="h-4 w-4" aria-hidden="true" />{selectedMapJob?.key === key ? 'Shown on map' : 'View on map'}</button>;
    const activeCount = stats?.activePickups ?? pickups.length;
    const limit = stats?.activePickupLimit;
    const claimReason = !scope?.isAssigned ? 'A logistics company and hub assignment is needed.'
        : !scope.isOperational ? 'New work is paused at your company or hub.'
        : !isOnline ? 'Go on duty to claim new pickups.'
        : limit !== undefined && activeCount >= limit ? `Your pickup limit of ${limit} has been reached. Bring collected parcels to the origin hub before claiming more.` : '';

    useEffect(() => {
        if (!proof) { setPreview(null); return; }
        const url = URL.createObjectURL(proof);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [proof]);

    const resetAction = () => { setTarget(null); setNotes(''); setProof(null); setErrors({}); };
    const openAction = (id: number, trackingNumber: string, status: ActionTarget['status']) => {
        if (pending.current) return;
        setNotes(''); setProof(null); setErrors({});
        setTarget({ id, trackingNumber, status });
    };
    const claim = (id: number) => {
        if (pending.current || claimReason) return;
        pending.current = true;
        setLoadingId(id);
        setErrors({});
        router.post(courierPath(`/deliveries/${id}/claim`), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setErrors({ status: error });
                else setTab('pickup');
            },
            onError: (validation) => setErrors(validation),
            onFinish: () => { pending.current = false; setLoadingId(null); },
        });
    };
    const selectProof = (file: File | null) => {
        if (file && (!file.type.startsWith('image/') || file.size > 5 * 1024 * 1024)) {
            setErrors((current) => ({ ...current, proof_image_file: 'Choose an image up to 5 MB. Your previous photo is kept.' }));
            return;
        }
        setProof(file);
        setErrors((current) => ({ ...current, proof_image_file: '' }));
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!target || pending.current) return;
        if (target.status === 'delivered' && !proof) {
            setErrors({ proof_image_file: 'Add a proof of delivery photo before recording the handoff.' });
            return;
        }
        pending.current = true;
        setLoadingId(target.id);
        setErrors({});
        router.post(courierPath(`/deliveries/${target.id}/status`), {
            _method: 'patch', status: target.status, courier_notes: notes.trim() || undefined,
            proof_image_file: target.status === 'delivered' ? proof ?? undefined : undefined,
        }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setErrors({ status: error });
                else resetAction();
            },
            onError: (validation) => setErrors(validation),
            onFinish: () => { pending.current = false; setLoadingId(null); },
        });
    };

    const pickupCards = (items: PickupTask[], isAvailable: boolean) => {
        const filtered = matchingPickups(items);
        if (!filtered.length) return [<CourierEmpty key="empty" title={normalizedSearch ? 'No matching pickups' : isAvailable ? 'No available pickups' : 'No assigned pickups'}>{normalizedSearch ? 'Try another tracking number, store, or address.' : isAvailable ? (claimReason || 'Eligible pickups from your origin hub will appear here.') : 'Pickups you claim will stay here until the origin hub records intake.'}</CourierEmpty>];
        return filtered.map((task) => {
            const collected = task.nextAction === 'await_origin_hub_scan';
            return <CourierPanel key={task.id} className={courierClasses('min-w-0 p-4 sm:p-5', selectedMapJob?.key === `${tab}-${task.id}` && 'border-rose-400')}>
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><CourierBadge tone={collected ? 'waiting' : 'brand'}>{isAvailable ? 'Available pickup' : collected ? 'Bring to origin hub' : 'Collect from seller'}</CourierBadge><span className="break-all text-sm font-semibold text-slate-700">{task.trackingNumber}</span></div>
                <CourierStop place={collected ? task.originHub : task.merchant} label={collected ? 'Origin Bayan Hub' : 'Seller pickup point'} phone={collected ? null : task.merchant.phone} messageDeliveryId={!collected && task.canMessage ? task.id : undefined} messagePhase="pickup" />
                {mapButton(`${tab}-${task.id}`)}
                <p className={`mt-4 rounded-[18px] border p-3 text-base leading-relaxed ${collected ? 'border-amber-300 bg-[#FFF4DF] text-[#92400E]' : 'border-slate-300 bg-[#F8F6F2] text-slate-700'}`}>{collected ? 'Bring this parcel to the origin hub. Hub staff must scan it into custody before it can travel onward.' : isAvailable ? 'Claim this pickup before collecting the parcel from the seller.' : 'Check the parcel and waybill at the seller, then confirm collection.'}</p>
                <details className="mt-3 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-semibold text-slate-800">Parcel details</summary><div className="space-y-2 pb-3"><p>Order: {task.orderNumber || 'Not provided'}</p><p>Items: {task.itemCount}</p><p>Assigned: {courierDate(task.assignedAt)}</p>{collected && task.canMessage && <Link href={`${courierPath('/messages')}?delivery=${task.id}&phase=pickup`} className={courierButton}>Message seller</Link>}</div></details>
                {isAvailable && <><button type="button" onClick={() => claim(task.id)} disabled={loadingId !== null || Boolean(claimReason)} className={`${courierPrimary} w-full`}>{loadingId === task.id ? 'Claiming…' : 'Claim pickup'}</button>{claimReason && <p className="mt-2 text-sm text-slate-600">{claimReason}</p>}</>}
                {task.nextAction === 'confirm_pickup' && <button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, 'picked_up')} className={`${courierPrimary} w-full`}><Package className="h-5 w-5" aria-hidden="true" />Confirm pickup</button>}
            </CourierPanel>;
        });
    };

    const finalMileCards = () => {
        const filtered = matchingDeliveries;
        if (!filtered.length) return [<CourierEmpty key="empty" title={normalizedSearch ? 'No matching deliveries' : 'No assigned deliveries'}>{normalizedSearch ? 'Try another tracking number, recipient, or address.' : 'Final-mile parcels appear after your destination hub assigns them to you.'}</CourierEmpty>];
        return filtered.map((task) => {
            const departing = task.nextAction === 'start_delivery';
            const isCod = task.payment.method === 'COD';
            return <CourierPanel key={task.id} className={courierClasses('min-w-0 p-4 sm:p-5', selectedMapJob?.key === `final_mile-${task.id}` && 'border-rose-400')}>
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><CourierBadge tone="brand">{departing ? 'Collect at destination hub' : 'Out for delivery'}</CourierBadge><span className="break-all text-sm font-semibold text-slate-700">{task.trackingNumber}</span></div>
                <CourierStop place={departing ? task.destinationHub : task.recipient} label={departing ? 'Destination Bayan Hub' : 'Saved buyer destination'} phone={departing ? null : task.recipient.phone} messageDeliveryId={!departing && task.canMessage ? task.id : undefined} messagePhase="final_mile" />
                {mapButton(`final_mile-${task.id}`)}
                <div className="mt-4 rounded-[18px] border border-slate-300 bg-[#F8F6F2] p-3"><p className="text-sm font-semibold text-slate-600">{isCod ? 'Cash due at delivery' : 'Payment method'}</p><p className="mt-1 text-xl font-bold tabular-nums">{isCod ? courierMoney(task.payment.codAmount) : task.payment.method ? `${task.payment.method} · No COD due` : 'Not provided'}</p>{isCod && <p className="mt-2 text-sm text-slate-600">Delivery proof does not record cash remittance or settle the order.</p>}</div>
                <p className="mt-4 text-base leading-relaxed text-slate-700">{departing ? 'Collect the assigned parcel from this hub before starting the delivery leg.' : 'Hand the parcel to the recipient and upload a proof photo. The buyer confirms receipt separately.'}</p>
                <details className="mt-3 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-semibold text-slate-800">Delivery details</summary><div className="space-y-2 pb-4"><p>Order: {task.orderNumber || 'Not provided'}</p><p>Assigned: {courierDate(task.assignedAt)}</p>{departing && <p className="whitespace-pre-line break-words text-base">Recipient: {task.recipient.name || 'Not provided'}<br />{task.recipient.address || 'Address not provided'}</p>}</div></details>
                <button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, departing ? 'out_for_delivery' : 'delivered')} className={`${courierPrimary} w-full`}><Truck className="h-5 w-5" aria-hidden="true" />{departing ? 'Start delivery' : 'Record delivery'}</button>
            </CourierPanel>;
        });
    };

    const cards = tab === 'pickup' ? pickupCards(pickups, false) : tab === 'available' ? pickupCards(available, true) : tab === 'final_mile' ? finalMileCards() : [];

    return (
        <CourierLayout title="Your tasks" subtitle={scope?.isAssigned ? 'Choose a parcel to see its current stop and next step.' : 'Your logistics team needs to assign a company and hub before new work is available.'} isOnline={isOnline} scope={scope}>
            <Head title="Your tasks — BagooPH" />
            <div className="space-y-5">
                {!isOnline && <p className="rounded-[18px] border border-amber-300 bg-[#FFF4DF] p-4 text-base text-[#92400E]">You are off duty. Existing assigned parcels remain available below; go on duty in the header to receive new work.</p>}
                {scope?.isAssigned && !scope.isOperational && <p className="rounded-[18px] border border-amber-300 bg-[#FFF4DF] p-4 text-base text-[#92400E]">New work is paused at your company or hub. Continue handling parcels already assigned to you.</p>}
                <div role="group" aria-label="Task filters" className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    {([{ key: 'pickup', label: 'Pickups', count: pickups.length }, { key: 'final_mile', label: 'Deliveries', count: deliveries.length }, { key: 'available', label: 'Available', count: available.length }, { key: 'activity', label: 'Recent', count: activity.length }] as const).map((item) => <button key={item.key} type="button" aria-pressed={tab === item.key} onClick={() => setTab(item.key)} className={courierClasses(courierButton, 'min-w-0', tab === item.key && 'border-[#C20836] bg-[#FDF2F4] text-[#C20836]')}><span>{item.label}</span><span className="tabular-nums">{item.count}</span></button>)}
                </div>
                <label className="block text-sm font-semibold text-slate-700">Search this queue<span className="relative block"><Search className="pointer-events-none absolute left-3 top-6 h-5 w-5 text-slate-600" aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tracking, name, or address" className={`${courierInput} pl-10`} /></span></label>
                {!target && <CourierFieldError message={errors.status || Object.values(errors).find(Boolean)} />}
                <div className="grid items-start gap-4 xl:grid-cols-2">
                    {cards[0]}
                    {selectedMapJob && <div id="rider-job-map" ref={mapRegion} className="min-w-0"><CourierJobMap job={selectedMapJob} /></div>}
                    {cards.slice(1)}
                    {tab === 'activity' && (activity.filter((trip) => matches(trip.trackingNumber, trip.orderNumber, trip.recipientName, trip.deliveryAddress)).length ? activity.filter((trip) => matches(trip.trackingNumber, trip.orderNumber, trip.recipientName, trip.deliveryAddress)).map((trip) => <CourierPanel key={trip.id} className="p-4 sm:p-5"><CourierBadge tone="success"><CheckCircle2 className="h-4 w-4" aria-hidden="true" />Delivery recorded</CourierBadge><p className="mt-3 break-all text-sm font-semibold">{trip.trackingNumber}</p><h2 className="mt-2 break-words text-xl font-bold">{trip.recipientName}</h2><p className="mt-2 break-words text-base text-slate-700">{trip.deliveryAddress}</p><p className="mt-3 text-sm text-slate-600">{courierDate(trip.deliveredAt)}</p><p className="mt-2 text-sm text-slate-600">Buyer receipt confirmation is a separate step.</p></CourierPanel>) : <CourierEmpty title={normalizedSearch ? 'No matching records' : 'No recent deliveries'}>Recorded final-mile deliveries at your current hub appear here.</CourierEmpty>)}
                </div>
                {tab === 'activity' && <Link href={courierPath('/earnings')} className={courierButton}>View completed trips</Link>}
            </div>

            <CourierDialog open={target !== null} title={target ? actionLabels[target.status] : 'Parcel action'} onClose={() => { if (!pending.current) resetAction(); }} busy={loadingId !== null}>
                {target && <form onSubmit={submit} className="space-y-4">
                    <p className="break-all text-sm font-semibold text-slate-700">{target.trackingNumber}</p>
                    <p className="text-base leading-relaxed text-slate-700">{target.status === 'picked_up' ? 'Confirm only after you have collected this parcel from the seller. Next, bring it to the origin Bayan Hub.' : target.status === 'out_for_delivery' ? 'Confirm that you collected this assigned parcel from the destination Bayan Hub and are starting its delivery leg.' : 'Record the actual handoff with a proof photo. Only the buyer can confirm receipt and complete the order.'}</p>
                    <CourierFieldError message={errors.status} />
                    {target.status === 'delivered' && <div>
                        <label htmlFor="delivery-proof" className="block text-sm font-semibold text-slate-800">Proof of delivery photo</label>
                        <p id="proof-help" className="mt-1 text-sm text-slate-600">An image is required, up to 5 MB. Choosing a photo does not record delivery.</p>
                        <input id="delivery-proof" type="file" accept="image/*" disabled={loadingId !== null} aria-invalid={Boolean(errors.proof_image_file)} aria-describedby="proof-help proof-error" onChange={(event) => { selectProof(event.target.files?.[0] ?? null); event.target.value = ''; }} className={`${courierInput} file:mr-3 file:rounded-[14px] file:border-0 file:bg-[#FDF2F4] file:px-3 file:py-2 file:text-sm file:font-semibold file:text-[#C20836]`} />
                        <CourierFieldError id="proof-error" message={errors.proof_image_file} />
                        {proof && <div className="mt-3 space-y-2">{preview && <img src={preview} alt="Selected delivery proof preview" className="max-h-56 w-full rounded-[18px] border border-slate-300 object-contain" />}<p className="break-words text-sm text-slate-600">{proof.name}</p><button type="button" disabled={loadingId !== null} onClick={() => selectProof(null)} className={courierButton}>Remove photo</button></div>}
                    </div>}
                    <label className="block text-sm font-semibold text-slate-800" htmlFor="courier-notes">Optional note<textarea id="courier-notes" rows={3} maxLength={500} value={notes} disabled={loadingId !== null} aria-invalid={Boolean(errors.courier_notes)} aria-describedby="notes-help notes-error" onChange={(event) => setNotes(event.target.value)} className={`${courierInput} resize-y`} /></label>
                    <p id="notes-help" className="text-sm text-slate-600">Up to 500 characters.{target.status === 'picked_up' ? ' Pickup notes are also sent to the seller.' : ''}</p>
                    <CourierFieldError id="notes-error" message={errors.courier_notes} />
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={loadingId !== null} onClick={resetAction} className={courierButton}>Cancel</button><button type="submit" disabled={loadingId !== null} className={courierPrimary}>{target.status === 'delivered' && <Camera className="h-5 w-5" aria-hidden="true" />}{loadingId !== null ? 'Recording…' : actionLabels[target.status]}</button></div>
                </form>}
            </CourierDialog>
        </CourierLayout>
    );
}
