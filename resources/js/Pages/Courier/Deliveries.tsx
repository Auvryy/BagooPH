import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Camera, Package, Search, Truck } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import CourierCurrentJob from '@/Components/CourierCurrentJob';
import { CourierDashboardSummary, CourierDeliveryOverview, CourierRecentDeliveries, type CourierActivity } from '@/Components/CourierDashboard';
import { CourierDialog, CourierEmpty, CourierFieldError, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierGreeting, courierMoney, courierPath, selectCourierMapJob, type CourierMapJob, type CourierPlace, type CourierScope } from '@/utils/courier';
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

interface Props {
    scope?: CourierScope;
    isOnline?: boolean;
    stats?: { availablePickups?: number; activePickups?: number; activePickupLimit?: number; finalMileTasks?: number; completedToday?: number };
    queues?: { availablePickups?: PickupTask[]; pickupTasks?: PickupTask[]; finalMileTasks?: FinalMileTask[]; recentActivity?: CourierActivity[] };
}

type Tab = 'pickup' | 'final_mile' | 'available';
type ActionTarget = { id: number; trackingNumber: string; status: 'picked_up' | 'out_for_delivery' | 'delivered' };
const actionLabels = { picked_up: 'Confirm pickup', out_for_delivery: 'Start delivery', delivered: 'Record delivery' };

export default function CourierDeliveries({ scope, isOnline = false, stats, queues }: Props) {
    const { auth } = usePage<PageProps>().props;
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
                instruction: collected ? 'Hub staff must scan this parcel into custody before it can travel onward.' : tab === 'available' ? 'This is a preview. Claim the pickup before collecting the parcel.' : 'Collect this parcel from the seller, then bring it to the origin Bayan Hub.',
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
            mapRegion.current?.querySelector<HTMLElement>('#rider-current-job')?.focus({ preventScroll: true });
        });
    };
    const selectTab = (next: Tab) => { setTab(next); setSelectedMapKey(null); };
    const viewQueue = (next: Tab) => { setSearch(''); selectTab(next); };
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

    const selectedPickup = tab === 'pickup' || tab === 'available'
        ? (tab === 'pickup' ? pickups : available).find((task) => selectedMapJob?.key === `${tab}-${task.id}`) : undefined;
    const selectedDelivery = tab === 'final_mile'
        ? deliveries.find((task) => selectedMapJob?.key === `final_mile-${task.id}`) : undefined;
    const collected = selectedPickup?.nextAction === 'await_origin_hub_scan';
    const departing = selectedDelivery?.nextAction === 'start_delivery';
    const isCod = selectedDelivery?.payment.method === 'COD';

    return (
        <CourierLayout title={courierGreeting(auth.user?.name)} pageLabel="Dashboard" subtitle={scope?.isAssigned ? 'Your day, one handoff at a time. Let’s keep each parcel moving.' : 'Your logistics team needs to assign a company and hub before new work is available.'} isOnline={isOnline} scope={scope}>
            <Head title="Rider dashboard — BagooPH" />
            <div className="space-y-5">
                <CourierDashboardSummary available={stats?.availablePickups ?? available.length} pickups={activeCount} deliveries={stats?.finalMileTasks ?? deliveries.length} completedToday={stats?.completedToday} onSelect={viewQueue} />
                {!isOnline && <p className="rounded-[4px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">You are off duty. Existing assigned parcels remain available below; go on duty in the header to receive new work.</p>}
                {scope?.isAssigned && !scope.isOperational && <p className="rounded-[4px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">New work is paused at your company or hub. Continue handling parcels already assigned to you.</p>}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Task filters" className="flex flex-wrap gap-2">
                        {([{ key: 'pickup', label: 'Pickups', count: pickups.length }, { key: 'final_mile', label: 'Deliveries', count: deliveries.length }, { key: 'available', label: 'Available', count: available.length }] as const).map((item) => <button key={item.key} type="button" aria-pressed={tab === item.key} onClick={() => selectTab(item.key)} className={courierClasses(courierButton, 'gap-2 px-3 text-sm font-medium', tab === item.key && 'border-[#E00D42] bg-white text-[#C20836]')}><span>{item.label}</span><span className={`rounded-[4px] px-2 py-0.5 text-xs tabular-nums ${tab === item.key ? 'bg-[#FCE7EA]' : 'bg-slate-100'}`}>{item.count}</span></button>)}
                    </div>
                    <label className="relative block w-full sm:w-60"><span className="sr-only">Search this queue</span><Search className="pointer-events-none absolute left-3 top-4 h-4 w-4 text-slate-500" aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tracking, name, or address" className={`${courierInput} mt-0 border-slate-300 pl-10 text-sm`} /></label>
                </div>
                {mapJobs.length > 1 && <label className="flex flex-wrap items-center gap-3 text-sm font-medium text-slate-600" htmlFor="current-parcel">Choose a parcel<select id="current-parcel" value={selectedMapJob?.key ?? ''} onChange={(event) => showOnMap(event.target.value)} className={`${courierInput} mt-0 min-w-0 flex-1 border-slate-300 text-sm sm:max-w-md`}>{mapJobs.map((job) => <option key={job.key} value={job.key}>{job.trackingNumber} · {job.stage}</option>)}</select><span className="text-xs">{mapJobs.length} {normalizedSearch ? 'matching' : 'queued'} parcels</span></label>}
                {!target && <CourierFieldError message={errors.status || Object.values(errors).find(Boolean)} />}
                {selectedMapJob ? <div id="rider-job-map" ref={mapRegion}>
                    {selectedPickup && <CourierCurrentJob job={selectedMapJob} stops={[{ place: selectedPickup.merchant, label: 'Seller pickup point' }, { place: selectedPickup.originHub, label: 'Origin Bayan Hub' }]} currentStop={collected ? 1 : 0} parcelLabel={`${selectedPickup.itemCount} ${selectedPickup.itemCount === 1 ? 'item' : 'items'} · Pickup leg`} phone={collected ? null : selectedPickup.merchant.phone} messageDeliveryId={!collected && selectedPickup.canMessage ? selectedPickup.id : undefined} messagePhase="pickup">
                        <details className="mt-2 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-medium text-slate-800">Parcel details</summary><div className="space-y-2 pb-4"><p>Order: {selectedPickup.orderNumber || 'Not provided'}</p><p>Items: {selectedPickup.itemCount}</p><p>Assigned: {courierDate(selectedPickup.assignedAt)}</p>{collected && selectedPickup.canMessage && <Link href={`${courierPath('/messages')}?delivery=${selectedPickup.id}&phase=pickup`} className={courierButton}>Message seller</Link>}</div></details>
                        {tab === 'available' && <><button type="button" onClick={() => claim(selectedPickup.id)} disabled={loadingId !== null || Boolean(claimReason)} className={`${courierPrimary} w-full`}>{loadingId === selectedPickup.id ? 'Claiming…' : 'Claim pickup'}</button>{claimReason && <p className="mt-2 text-sm leading-relaxed text-slate-600">{claimReason}</p>}</>}
                        {selectedPickup.nextAction === 'confirm_pickup' && <button type="button" disabled={loadingId !== null} onClick={() => openAction(selectedPickup.id, selectedPickup.trackingNumber, 'picked_up')} className={`${courierPrimary} w-full`}><Package className="h-5 w-5" aria-hidden="true" />Confirm pickup</button>}
                    </CourierCurrentJob>}
                    {selectedDelivery && <CourierCurrentJob job={selectedMapJob} stops={[{ place: selectedDelivery.destinationHub, label: 'Destination Bayan Hub' }, { place: selectedDelivery.recipient, label: 'Saved buyer destination' }]} currentStop={departing ? 0 : 1} parcelLabel={`${selectedDelivery.payment.method || 'Payment method not provided'} · Delivery leg`} phone={departing ? null : selectedDelivery.recipient.phone} messageDeliveryId={!departing && selectedDelivery.canMessage ? selectedDelivery.id : undefined} messagePhase="final_mile">
                        <div className="mt-4 flex flex-wrap items-baseline justify-between gap-2 rounded-[4px] border border-rose-200 bg-[#FFF2F4] p-3"><p className="text-xs font-medium text-slate-600">{isCod ? 'Cash due at delivery' : 'Payment method'}</p><p className="text-base font-semibold tabular-nums text-[#C20836]">{isCod ? courierMoney(selectedDelivery.payment.codAmount) : selectedDelivery.payment.method ? `${selectedDelivery.payment.method} · No COD due` : 'Not provided'}</p>{isCod && <p className="w-full text-xs leading-relaxed text-slate-600">Delivery proof does not record cash remittance or settle the order.</p>}</div>
                        <details className="mt-2 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-medium text-slate-800">Delivery details</summary><div className="space-y-2 pb-4"><p>Order: {selectedDelivery.orderNumber || 'Not provided'}</p><p>Assigned: {courierDate(selectedDelivery.assignedAt)}</p></div></details>
                        <button type="button" disabled={loadingId !== null} onClick={() => openAction(selectedDelivery.id, selectedDelivery.trackingNumber, departing ? 'out_for_delivery' : 'delivered')} className={`${courierPrimary} w-full`}><Truck className="h-5 w-5" aria-hidden="true" />{departing ? 'Start delivery' : 'Record delivery'}</button>
                    </CourierCurrentJob>}
                </div> : <CourierEmpty title={normalizedSearch ? 'No matching parcels' : tab === 'available' ? 'No available pickups' : tab === 'pickup' ? 'No assigned pickups' : 'No assigned deliveries'}>{normalizedSearch ? 'Try another tracking number, name, or address.' : tab === 'available' ? claimReason || 'Eligible pickups from your origin hub will appear here.' : tab === 'pickup' ? 'Pickups you claim will stay here until the origin hub records intake.' : 'Final-mile parcels appear after your destination hub assigns them to you.'}</CourierEmpty>}
                <CourierDeliveryOverview activity={activity} activePickups={activeCount} pickupLimit={limit} awaitingIntake={pickups.filter((task) => task.nextAction === 'await_origin_hub_scan').length} claimReason={claimReason} onViewPickups={() => viewQueue('pickup')} />
                <CourierRecentDeliveries activity={activity} completedToday={stats?.completedToday} />
            </div>

            <CourierDialog open={target !== null} title={target ? actionLabels[target.status] : 'Parcel action'} onClose={() => { if (!pending.current) resetAction(); }} busy={loadingId !== null}>
                {target && <form onSubmit={submit} className="space-y-4">
                    <p className="break-all text-sm font-semibold text-slate-700">{target.trackingNumber}</p>
                    <p className="text-base leading-relaxed text-slate-700">{target.status === 'picked_up' ? 'Confirm only after you have collected this parcel from the seller. Next, bring it to the origin Bayan Hub.' : target.status === 'out_for_delivery' ? 'Confirm that you collected this assigned parcel from the destination Bayan Hub and are starting its delivery leg.' : 'Record the actual handoff with a proof photo. Only the buyer can confirm receipt and complete the order.'}</p>
                    <CourierFieldError message={errors.status} />
                    {target.status === 'delivered' && <div>
                        <label htmlFor="delivery-proof" className="block text-sm font-semibold text-slate-800">Proof of delivery photo</label>
                        <p id="proof-help" className="mt-1 text-sm text-slate-600">An image is required, up to 5 MB. Choosing a photo does not record delivery.</p>
                        <input id="delivery-proof" type="file" accept="image/*" disabled={loadingId !== null} aria-invalid={Boolean(errors.proof_image_file)} aria-describedby="proof-help proof-error" onChange={(event) => { selectProof(event.target.files?.[0] ?? null); event.target.value = ''; }} className={`${courierInput} file:mr-3 file:rounded-[4px] file:border-0 file:bg-[#FDF2F4] file:px-3 file:py-2 file:text-sm file:font-semibold file:text-[#C20836]`} />
                        <CourierFieldError id="proof-error" message={errors.proof_image_file} />
                        {proof && <div className="mt-3 space-y-2">{preview && <img src={preview} alt="Selected delivery proof preview" className="max-h-56 w-full rounded-[4px] border border-slate-300 object-contain" />}<p className="break-words text-sm text-slate-600">{proof.name}</p><button type="button" disabled={loadingId !== null} onClick={() => selectProof(null)} className={courierButton}>Remove photo</button></div>}
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
