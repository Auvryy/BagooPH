import { useEffect, useRef, useState, type FormEvent } from 'react';
import useOperationalRefresh from '@/hooks/useOperationalRefresh';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Banknote, Camera, RefreshCw, Search, Truck } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import CourierJobMap from '@/Components/CourierJobMap';
import CourierCurrentJob from '@/Components/CourierCurrentJob';
import CourierTaskCard, { type CourierTaskItem } from '@/Components/CourierTaskCard';
import WaybillCamera from '@/Components/WaybillCamera';
import { CourierDeliveryOverview, CourierRecentDeliveries, type CourierActivity } from '@/Components/CourierDashboard';
import { CourierDialog, CourierEmpty, CourierFieldError, CourierPanel, CourierParcelIdentifiers, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierGreeting, courierMoney, courierPath, selectCourierMapJob, type CourierMapJob, type CourierPlace, type CourierScope } from '@/utils/courier';
import { PageProps, OrderTracking } from '@/types';
import { currentCourierAction } from '@/utils/orderTracking';
import useCourierRequestError from '@/hooks/useCourierRequestError';
import { cashInputCents } from '@/utils/codCash';

interface PickupTask {
    tracking?: OrderTracking | null;
    handoverHold?: boolean;
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    items?: CourierTaskItem[];
    retainedByCurrentCourier?: boolean;
    status: string;
    itemCount: number;
    merchant: CourierPlace & { phone: string | null };
    originHub: CourierPlace;
    assignedAt: string | null;
    nextAction: 'claim_pickup' | 'confirm_pickup' | 'await_origin_hub_scan' | null;
    canMessage: boolean;
}

interface FinalMileTask {
    tracking?: OrderTracking | null;
    handoverHold?: boolean;
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    items?: CourierTaskItem[];
    itemCount?: number;
    retainedByCurrentCourier?: boolean;
    status: string;
    recipient: CourierPlace & { phone: string | null };
    payment: { method: string; codAmount: number | null; codAmountCents: number | null };
    destinationHub: CourierPlace;
    assignedAt: string | null;
    nextAction: 'start_delivery' | 'complete_delivery' | 'return_to_hub' | null;
    failureAttempts: number;
    failureReason: string | null;
    canMessage: boolean;
}

interface Props {
    recoveryRequestToken: string;
    scope?: CourierScope;
    isOnline?: boolean;
    stats?: { availablePickups?: number; activePickups?: number; activePickupLimit?: number; finalMileTasks?: number; completedToday?: number };
    queues?: { availablePickups?: PickupTask[]; pickupTasks?: PickupTask[]; finalMileTasks?: FinalMileTask[]; recentActivity?: CourierActivity[] };
}

type WorkspaceView = 'assigned' | 'carrying' | 'available';
type WorkspaceEntry = { phase: 'pickup'; task: PickupTask } | { phase: 'available'; task: PickupTask } | { phase: 'final_mile'; task: FinalMileTask };
type ActionTarget = { id: number; trackingNumber: string; status: 'picked_up' | 'out_for_delivery' | 'delivered' | 'delivery_failed' };
const actionLabels = { picked_up: 'Confirm pickup', out_for_delivery: 'Start delivery', delivered: 'Record delivery', delivery_failed: 'Record failed attempt' };

export default function CourierDeliveries({ scope, isOnline = false, stats, queues, recoveryRequestToken }: Props) {
    const { auth } = usePage<PageProps>().props;
    const pickups = queues?.pickupTasks ?? [];
    const available = queues?.availablePickups ?? [];
    const deliveries = queues?.finalMileTasks ?? [];
    const activity = queues?.recentActivity ?? [];
    const [view, setView] = useState<WorkspaceView>(() => {
        if ([...pickups, ...deliveries].some((task) => task.retainedByCurrentCourier === true)) return 'carrying';
        return deliveries.length || pickups.length ? 'assigned' : 'available';
    });
    const [search, setSearch] = useState('');
    const [sampleMapOpen, setSampleMapOpen] = useState(true);
    const [selectedMapKey, setSelectedMapKey] = useState<string | null>(null);
    const mapRegion = useRef<HTMLDivElement>(null);
    const [target, setTarget] = useState<ActionTarget | null>(null);
    const [notes, setNotes] = useState('');
    const [failureReason, setFailureReason] = useState('');
    const [attemptLocation, setAttemptLocation] = useState('');
    const [requestToken, setRequestToken] = useState('');
    const [barcode, setBarcode] = useState('');
    const [recipientName, setRecipientName] = useState('');
    const [recipientRelationship, setRecipientRelationship] = useState('');
    const [cashReceived, setCashReceived] = useState('');
    const [changeGiven, setChangeGiven] = useState('0.00');
    const [cashConfirmed, setCashConfirmed] = useState(false);
    const [proof, setProof] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loadingId, setLoadingId] = useState<number | null>(null);
    useOperationalRefresh(loadingId !== null, ['scope', 'isOnline', 'stats', 'queues', 'recoveryRequestToken']);
    const pending = useRef(false);
    useCourierRequestError(pending, (message) => setErrors({ status: message }));
    const normalizedSearch = search.trim().toLowerCase();
    const matches = (...values: Array<string | null | undefined>) => values.some((value) => value?.toLowerCase().includes(normalizedSearch));
    const assignedEntries: WorkspaceEntry[] = [
        ...pickups.filter((task) => !task.retainedByCurrentCourier).map((task) => ({ phase: 'pickup' as const, task })),
        ...deliveries.filter((task) => !task.retainedByCurrentCourier).map((task) => ({ phase: 'final_mile' as const, task })),
    ];
    const carryingEntries: WorkspaceEntry[] = [
        ...pickups.filter((task) => task.retainedByCurrentCourier).map((task) => ({ phase: 'pickup' as const, task })),
        ...deliveries.filter((task) => task.retainedByCurrentCourier).map((task) => ({ phase: 'final_mile' as const, task })),
    ];
    const availableEntries: WorkspaceEntry[] = available.map((task) => ({ phase: 'available', task }));
    const viewEntries = view === 'assigned' ? assignedEntries : view === 'carrying' ? carryingEntries : availableEntries;
    const visibleEntries = viewEntries.filter(({ phase, task }) => {
        const itemNames = task.items?.map((item) => item.name) ?? [];
        if (phase === 'pickup' || phase === 'available') {
            return matches(task.trackingNumber, task.orderNumber, task.merchant.name, task.merchant.address, task.originHub.name, task.originHub.address, ...itemNames);
        }
        return matches(task.trackingNumber, task.orderNumber, task.recipient.name, task.recipient.address, task.destinationHub.name, task.destinationHub.address, ...itemNames);
    });
    const mapJobs: CourierMapJob[] = visibleEntries.map(({ phase, task }) => {
        if (phase === 'pickup' || phase === 'available') {
            const retained = task.retainedByCurrentCourier === true;
            const hasRecordedPickup = task.nextAction === 'await_origin_hub_scan';
            const custodyReview = hasRecordedPickup && !retained;
            return {
                key: `${phase}-${task.id}`, status: task.status, trackingNumber: task.trackingNumber,
                stage: task.handoverHold ? retained ? 'Carrying · recovery required' : 'Handover hold' : phase === 'available' ? 'Available to claim' : retained ? 'Carrying · awaiting hub intake' : hasRecordedPickup ? 'Custody review' : 'Pickup assigned',
                stopLabel: task.handoverHold ? 'Authorized recovery destination' : custodyReview ? 'Recorded holder needs review' : retained ? 'Origin Bayan Hub' : 'Seller pickup point',
                place: task.handoverHold || custodyReview ? {} : retained ? task.originHub : task.merchant, preview: phase === 'available',
                instruction: task.handoverHold ? retained ? 'You still hold this parcel. Follow the authorized recovery instruction and return it to the named hub.' : 'Complete the authorized handover recovery before ordinary parcel work continues.' : retained ? 'Bring this parcel to the origin Bayan Hub. Hub staff must scan it into custody.' : hasRecordedPickup ? 'The pickup stage is recorded, but the latest custody record does not confirm that you hold this parcel. Review tracking before moving it.' : phase === 'available' ? 'Claim this pickup before collecting the parcel from the seller.' : 'Go to the seller, check the parcel, and confirm pickup through the authorized scan.',
            };
        }
        const retained = task.retainedByCurrentCourier === true;
        const custodyReview = !retained && (task.nextAction === 'return_to_hub' || task.nextAction === 'complete_delivery');
        const atHub = task.nextAction === 'start_delivery' || task.nextAction === 'return_to_hub' || !retained;
        return {
            key: `final_mile-${task.id}`, status: task.status, trackingNumber: task.trackingNumber,
            stage: task.handoverHold ? retained ? 'Carrying · recovery required' : 'Handover hold' : custodyReview ? 'Custody review' : task.nextAction === 'return_to_hub' ? 'Carrying · return to hub' : retained ? 'Carrying · out for delivery' : task.nextAction === 'start_delivery' ? 'Final-mile assignment' : 'Custody review',
            stopLabel: task.handoverHold ? 'Authorized recovery destination' : custodyReview ? 'Recorded holder needs review' : atHub ? 'Destination Bayan Hub' : 'Saved buyer destination',
            place: task.handoverHold || custodyReview ? {} : atHub ? task.destinationHub : task.recipient, preview: false,
            instruction: task.handoverHold ? retained ? 'You still hold this parcel. Follow the authorized recovery instruction and return it to the named hub.' : 'Complete the authorized handover recovery before ordinary parcel work continues.' : custodyReview ? 'The parcel stage is recorded, but the latest custody record does not confirm that you hold this parcel. Review tracking before moving it.' : task.nextAction === 'return_to_hub' ? 'Return this parcel to the destination hub. Its handler must scan it back before retry or reverse routing.' : task.nextAction === 'start_delivery' ? 'Collect the assigned parcel from this hub and confirm the actual scan before starting delivery.' : retained ? 'Deliver to the saved destination and record the actual handoff with proof. The buyer confirms receipt separately.' : 'Review the parcel tracking before moving it.',
        };
    });
    const selectedMapJob = selectCourierMapJob(mapJobs, selectedMapKey);
    const showOnMap = (key: string) => {
        setSelectedMapKey(key);
        requestAnimationFrame(() => {
            mapRegion.current?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
            mapRegion.current?.querySelector<HTMLElement>('#rider-current-job')?.focus({ preventScroll: true });
        });
    };
    const selectView = (next: WorkspaceView) => { setView(next); setSelectedMapKey(null); };
    const viewQueue = (next: WorkspaceView) => { setSearch(''); selectView(next); };
    const activeCount = stats?.activePickups ?? pickups.length;
    const limit = stats?.activePickupLimit;
    const claimReason = !scope?.isAssigned ? 'A logistics company and hub assignment is needed.'
        : !scope.isOperational ? 'New work is paused at your company or hub.'
        : !isOnline ? 'Go on duty to claim new pickups.'
        : limit !== undefined && activeCount >= limit ? `Your pickup limit of ${limit} has been reached. Bring collected parcels to the origin hub before claiming more.` : '';
    const actionDue = target?.status === 'delivered' ? deliveries.find((task) => task.id === target.id)?.payment.codAmountCents ?? null : null;
    const walletHandover = target?.status === 'delivered' && deliveries.find(task => task.id === target.id)?.payment.method.toLowerCase() === 'wallet';
    const tenderCents = cashInputCents(cashReceived);
    const changeCents = cashInputCents(changeGiven);
    const netCash = tenderCents !== null && changeCents !== null ? tenderCents - changeCents : null;

    useEffect(() => {
        if (!proof) { setPreview(null); return; }
        const url = URL.createObjectURL(proof);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [proof]);

    const resetAction = () => { setTarget(null); setNotes(''); setBarcode(''); setProof(null); setErrors({}); };
    useEffect(() => {
        if (target && !pending.current && !currentCourierAction(target, pickups, deliveries)) {
            setTarget(null); setProof(null); setErrors({ status: 'This parcel changed. Check its refreshed tracking before another action.' });
        }
    }, [target, queues, loadingId]);
    const openAction = (id: number, trackingNumber: string, status: ActionTarget['status']) => {
        if (pending.current) return;
        if (!currentCourierAction({ id, status }, pickups, deliveries)) {
            setErrors({ status: 'This parcel changed. Refresh tracking before another action.' });
            return;
        }
        setNotes(''); setBarcode(''); setProof(null); setErrors({});
        setFailureReason(''); setAttemptLocation(''); setRequestToken(recoveryRequestToken);
        setRecipientName(''); setRecipientRelationship(''); setCashReceived(''); setChangeGiven('0.00'); setCashConfirmed(false);
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
                else setView('assigned');
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
        if (!currentCourierAction(target, pickups, deliveries)) { setErrors({ status: 'This parcel changed. Refresh tracking before another action.' }); return; }
        if (!barcode.trim()) {
            setErrors({ barcode: 'Enter the code scanned from the parcel waybill.' });
            return;
        }
        if (barcode.trim() !== target.trackingNumber) {
            setErrors({ barcode: 'The scanned waybill must match the selected parcel before its action can be recorded.' });
            return;
        }
        if ((target.status === 'delivered' || target.status === 'delivery_failed') && !proof) {
            setErrors({ proof_image_file: 'Add a proof of delivery photo before recording the handoff.' });
            return;
        }
        if (target.status === 'delivered' && !walletHandover && (actionDue === null || netCash !== actionDue || !cashConfirmed)) {
            setErrors({ cash_received: 'Count the saved COD amount, give the correct change, and confirm collection before recording delivery.' });
            return;
        }
        pending.current = true;
        setLoadingId(target.id);
        setErrors({});
        router.post(courierPath(`/deliveries/${target.id}/status`), {
            _method: 'patch', status: target.status, courier_notes: notes.trim() || undefined,
            barcode,
            failure_reason: target.status === 'delivery_failed' ? failureReason : undefined,
            location_name: target.status === 'delivery_failed' ? attemptLocation : undefined,
            request_token: target.status === 'delivery_failed' || target.status === 'delivered' ? requestToken : undefined,
            recipient_name: target.status === 'delivered' ? recipientName.trim() : undefined,
            recipient_relationship: target.status === 'delivered' ? recipientRelationship : undefined,
            cash_received: target.status === 'delivered' && !walletHandover ? cashReceived : undefined,
            change_given: target.status === 'delivered' && !walletHandover ? changeGiven : undefined,
            cash_confirmed: target.status === 'delivered' && !walletHandover ? cashConfirmed : undefined,
            proof_image_file: (target.status === 'delivered' || target.status === 'delivery_failed') ? proof ?? undefined : undefined,
        }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setErrors({ status: error });
                else {
                    if (target.status === 'picked_up' || target.status === 'out_for_delivery') setView('carrying');
                    resetAction();
                }
            },
            onError: (validation) => setErrors(validation),
            onFinish: () => { pending.current = false; setLoadingId(null); },
        });
    };

    const selectedEntry = visibleEntries.find(({ phase, task }) => selectedMapJob?.key === `${phase}-${task.id}`);
    const selectedPickup = selectedEntry && (selectedEntry.phase === 'pickup' || selectedEntry.phase === 'available') ? selectedEntry.task : undefined;
    const selectedDelivery = selectedEntry?.phase === 'final_mile' ? selectedEntry.task : undefined;
    const selectedHeldTask = selectedEntry?.task.handoverHold ? selectedEntry.task : undefined;
    const selectedNeedsCustodyReview = Boolean(selectedEntry && !selectedEntry.task.handoverHold && !selectedEntry.task.retainedByCurrentCourier && (
        (selectedEntry.phase === 'pickup' && selectedEntry.task.nextAction === 'await_origin_hub_scan')
        || (selectedEntry.phase === 'final_mile' && (selectedEntry.task.nextAction === 'complete_delivery' || selectedEntry.task.nextAction === 'return_to_hub'))
    ));
    const selectedReviewTask = selectedNeedsCustodyReview ? selectedEntry?.task : undefined;
    const selectedPanelTask = selectedHeldTask ?? selectedReviewTask;
    const collected = selectedPickup?.retainedByCurrentCourier === true;
    const assignedCount = assignedEntries.length;
    const carryingCount = carryingEntries.length;

    const renderTaskCard = (entry: WorkspaceEntry) => {
        const { phase, task } = entry;
        const key = `${phase}-${task.id}`;
        const job = mapJobs.find((candidate) => candidate.key === key);
        if (!job) return null;
        const held = task.handoverHold === true;
        const retained = task.retainedByCurrentCourier === true;
        const itemCount = task.itemCount ?? (task.items ?? []).reduce((total, item) => total + item.quantity, 0);
        const tone = held || job.stage === 'Custody review' || job.stage === 'Return stage' ? 'waiting' as const : 'brand' as const;
        const actionButtons = held
            ? <Link href="/handover-issues" className={`${courierButton} w-full sm:w-auto`}>Open handover recovery</Link>
            : phase === 'available'
                ? <><button type="button" onClick={() => claim(task.id)} disabled={loadingId !== null || Boolean(claimReason)} className={`${courierPrimary} w-full sm:w-auto`}>{loadingId === task.id ? 'Claiming…' : 'Claim pickup'}</button>{claimReason && <p className="self-center text-sm leading-relaxed text-slate-600 sm:max-w-52">{claimReason}</p>}</>
                : phase === 'pickup'
                    ? task.nextAction === 'confirm_pickup'
                        ? <button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, 'picked_up')} className={`${courierPrimary} w-full sm:w-auto`}>Scan waybill · confirm pickup</button>
                        : task.nextAction === 'await_origin_hub_scan'
                            ? <span className="inline-flex min-h-12 items-center rounded-[8px] border border-amber-300 bg-[#FFF4DF] px-4 py-3 text-sm font-semibold text-[#92400E]">{retained ? 'Awaiting origin hub intake' : 'Custody review required'}</span>
                            : null
                    : task.nextAction === 'return_to_hub' && retained
                        ? <span className="inline-flex min-h-12 items-center rounded-[8px] border border-amber-300 bg-[#FFF4DF] px-4 py-3 text-sm font-semibold text-[#92400E]">Return to destination hub for intake</span>
                        : task.nextAction === 'return_to_hub'
                            ? <span className="inline-flex min-h-12 items-center rounded-[8px] border border-amber-300 bg-[#FFF4DF] px-4 py-3 text-sm font-semibold text-[#92400E]">Custody review required before return</span>
                        : task.nextAction === 'start_delivery'
                            ? <button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, 'out_for_delivery')} className={`${courierPrimary} w-full sm:w-auto`}><Truck className="h-5 w-5" aria-hidden="true" />Scan waybill · start delivery</button>
                    : task.nextAction === 'complete_delivery' && retained
                                ? <><button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, 'delivered')} className={`${courierPrimary} w-full sm:w-auto`}><Truck className="h-5 w-5" aria-hidden="true" />Scan waybill · record delivery</button><button type="button" disabled={loadingId !== null} onClick={() => openAction(task.id, task.trackingNumber, 'delivery_failed')} className={`${courierButton} w-full sm:w-auto`}>Report failed attempt</button></>
                                : phase === 'final_mile' && task.nextAction === 'complete_delivery'
                                    ? <span className="inline-flex min-h-12 items-center rounded-[8px] border border-amber-300 bg-[#FFF4DF] px-4 py-3 text-sm font-semibold text-[#92400E]">Custody review required before delivery</span>
                                    : null;
        const paymentMethod = phase === 'final_mile' ? task.payment.method.toUpperCase() : '';
        const paymentSummary = phase === 'final_mile'
            ? paymentMethod === 'COD'
                ? task.payment.codAmountCents === null ? 'COD amount unavailable · refresh tracking' : `Cash due at delivery · ${courierMoney(task.payment.codAmountCents / 100)}`
                : task.payment.method ? `${task.payment.method} · No COD due` : 'Payment method not provided'
            : undefined;
        const extraInstruction = phase === 'final_mile' && task.nextAction === 'return_to_hub'
            ? `${job.instruction} Attempt ${task.failureAttempts}: ${task.failureReason || 'Reason not provided'}.`
            : job.instruction;
        const needsCustodyReview = job.stage === 'Custody review';
        return <CourierTaskCard key={key} id={`courier-task-${key}`} title={phase === 'available' ? 'Available pickup' : phase === 'pickup' ? 'Pickup task' : 'Final-mile delivery'} stage={job.stage} tone={tone} trackingNumber={task.trackingNumber} orderNumber={task.orderNumber} items={task.items ?? []} itemCount={itemCount} stopLabel={job.stopLabel} stopName={held ? 'See handover recovery' : needsCustodyReview ? 'Tracking review required' : job.place.name ?? null} stopAddress={held ? 'Open recovery details for the authorized receiving hub.' : needsCustodyReview ? 'Do not move this parcel until its current holder is reviewed.' : job.place.address ?? null} instruction={extraInstruction} paymentSummary={paymentSummary} selected={selectedMapJob?.key === key} preview={job.preview} onView={() => showOnMap(key)}>
            {actionButtons}
        </CourierTaskCard>;
    };
    const targetOrderNumber = target
        ? target.status === 'picked_up'
            ? pickups.find((task) => task.id === target.id)?.orderNumber ?? null
            : deliveries.find((task) => task.id === target.id)?.orderNumber ?? null
        : null;
    const waybillMismatch = Boolean(target && barcode.trim() && barcode.trim() !== target.trackingNumber);

    return (
        <CourierLayout title={courierGreeting(auth.user?.name)} pageLabel="Tasks" subtitle={scope?.isAssigned ? 'See what is assigned to you, what you are carrying, and what you can claim.' : 'Your logistics team needs to assign a company and hub before new work is available.'} isOnline={isOnline} scope={scope}>
            <Head title="Rider dashboard — BagooPH" />
            <div className="space-y-5">
                <details className="rounded-[8px] border border-slate-200 bg-white p-4" open={sampleMapOpen} onToggle={(event) => setSampleMapOpen(event.currentTarget.open)}><summary className="cursor-pointer py-2 text-sm font-semibold">Sample delivery map</summary>{sampleMapOpen && <div className="pt-3"><CourierJobMap /></div>}</details>
                <div className="flex flex-wrap justify-end gap-2"><button type="button" disabled={loadingId !== null} onClick={() => {
                    if (pending.current) return;
                    pending.current = true; setLoadingId(-1); setErrors({});
                    router.reload({ only: ['scope', 'isOnline', 'stats', 'queues', 'recoveryRequestToken', 'flash'],
                        onError: setErrors, onFinish: () => { pending.current = false; setLoadingId(null); },
                    });
                }} className={`${courierButton} gap-2`}><RefreshCw className="h-4 w-4" aria-hidden="true" />{loadingId === -1 ? 'Refreshing…' : 'Refresh tracking'}</button><Link href={courierPath('/cod')} className={`${courierButton} gap-2`}><Banknote className="h-4 w-4" aria-hidden="true" />COD cash handovers</Link></div>
                {!isOnline && <p className="rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">You are off duty. Existing assigned parcels remain available below; go on duty in the header to receive new work.</p>}
                {scope?.isAssigned && !scope.isOperational && <p className="rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">New work is paused at your company or hub. Continue handling parcels already assigned to you.</p>}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Task filters" className="flex max-w-full gap-1 overflow-x-auto rounded-[8px] bg-slate-100 p-1">
                        {([{ key: 'assigned', label: 'Assigned to you', count: assignedCount }, { key: 'carrying', label: 'Carrying', count: carryingCount }, { key: 'available', label: 'Available to claim', count: stats?.availablePickups ?? available.length }] as const).map((item) => <button key={item.key} type="button" aria-pressed={view === item.key} onClick={() => selectView(item.key)} className={courierClasses(courierButton, 'shrink-0 gap-2 border-transparent bg-transparent px-3 text-sm font-medium hover:bg-slate-200', view === item.key && 'bg-white text-slate-950 shadow-sm hover:bg-white')}><span>{item.label}</span><span className={`rounded-[8px] px-2 py-0.5 text-xs tabular-nums ${view === item.key ? 'bg-slate-100 text-slate-700' : 'bg-slate-200/70 text-slate-600'}`}>{item.count}</span></button>)}
                    </div>
                    <label className="relative block w-full sm:w-72"><span className="sr-only">Search this queue</span><Search className="pointer-events-none absolute left-3 top-4 h-4 w-4 text-slate-500" aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Waybill, order, product, or address" className={`${courierInput} mt-0 pl-10 text-sm`} /></label>
                </div>
                {!target && <CourierFieldError message={errors.status || Object.values(errors).find(Boolean)} />}
                {visibleEntries.length ? <div className="grid items-start gap-5 min-[860px]:grid-cols-[minmax(0,1fr)_minmax(20rem,1.05fr)]">
                    <section aria-label={`${view === 'assigned' ? 'Assigned to you' : view === 'carrying' ? 'Parcels you are carrying' : 'Available pickups'} queue`} className="space-y-4">
                        {visibleEntries.map(renderTaskCard)}
                    </section>
                    <div className="space-y-4">
                        <label className="block text-sm font-medium text-slate-700" htmlFor="current-parcel">Selected task
                            <select id="current-parcel" value={selectedMapJob?.key ?? ''} onChange={(event) => showOnMap(event.target.value)} className={`${courierInput} mt-2 text-sm`}>
                                {mapJobs.map((job) => <option key={job.key} value={job.key}>Waybill: {job.trackingNumber} · {job.stage}</option>)}
                            </select>
                        </label>
                        {selectedMapJob && <div id="rider-job-map" ref={mapRegion}>
                            {selectedPanelTask && <CourierPanel className="min-w-0 p-4 sm:p-5"><div className="space-y-4"><div className="flex flex-wrap items-center gap-3"><h2 id="rider-current-job" tabIndex={-1} className="text-lg font-semibold focus:outline-none">{selectedHeldTask ? 'Handover recovery required' : 'Custody record needs review'}</h2><span className="rounded-[8px] bg-[#FFF4DF] px-3 py-1.5 text-xs font-semibold text-[#92400E]">{selectedHeldTask?.retainedByCurrentCourier ? 'Carrying · recovery required' : selectedHeldTask ? 'Custody review' : 'Do not move parcel'}</span></div><CourierParcelIdentifiers trackingNumber={selectedPanelTask.trackingNumber} orderNumber={selectedPanelTask.orderNumber} /><p className="text-sm leading-relaxed text-slate-700">{selectedHeldTask ? selectedHeldTask.retainedByCurrentCourier ? 'The latest recorded custody identifies you as the current holder. Keep the parcel secure and follow its authorized recovery instruction.' : 'This assignment does not confirm that you currently hold the parcel. Review the recorded recovery instruction before moving it.' : 'The parcel stage is recorded, but the latest custody record does not confirm that you hold it. Review tracking before any movement.'}</p>{selectedPanelTask.tracking?.custodian && <p className="text-sm text-slate-700"><span className="font-semibold">Recorded holder:</span> {selectedPanelTask.tracking.custodian}</p>}{selectedPanelTask.tracking?.notice && <p role="status" className="rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-3 text-sm leading-relaxed text-[#92400E]">{selectedPanelTask.tracking.notice}</p>}{selectedHeldTask && <Link href="/handover-issues" className={`${courierPrimary} w-full sm:w-auto`}>Open handover recovery</Link>}</div></CourierPanel>}
                            {!selectedPanelTask && selectedPickup && <CourierCurrentJob job={selectedMapJob} orderNumber={selectedPickup.orderNumber} stops={[{ place: selectedPickup.merchant, label: 'Seller pickup point' }, { place: selectedPickup.originHub, label: 'Origin Bayan Hub' }]} currentStop={collected ? 1 : 0} parcelLabel={`${selectedPickup.itemCount} ${selectedPickup.itemCount === 1 ? 'item' : 'items'} · Pickup leg`} phone={selectedPickup.nextAction === 'confirm_pickup' ? selectedPickup.merchant.phone : null} messageDeliveryId={!collected && selectedEntry?.phase === 'pickup' && selectedPickup.canMessage ? selectedPickup.id : undefined} messagePhase="pickup" tracking={selectedPickup.tracking}>
                                <details className="mt-2 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-medium text-slate-800">Assignment details</summary><div className="space-y-2 pb-4"><p>Assigned: {courierDate(selectedPickup.assignedAt)}</p>{!collected && selectedPickup.canMessage && selectedEntry?.phase === 'pickup' && <Link href={`${courierPath('/messages')}?delivery=${selectedPickup.id}&phase=pickup`} className={courierButton}>Message seller</Link>}</div></details>
                            </CourierCurrentJob>}
                            {!selectedPanelTask && selectedDelivery && <CourierCurrentJob job={selectedMapJob} orderNumber={selectedDelivery.orderNumber} stops={[{ place: selectedDelivery.destinationHub, label: 'Destination Bayan Hub' }, { place: selectedDelivery.recipient, label: 'Saved buyer destination' }]} currentStop={selectedDelivery.nextAction === 'return_to_hub' || !selectedDelivery.retainedByCurrentCourier ? 0 : 1} futureStopLabel={selectedDelivery.nextAction === 'return_to_hub' ? 'Reference only · if reassigned' : undefined} parcelLabel={`${selectedDelivery.payment.method || 'Payment method not provided'} · Delivery leg`} phone={selectedDelivery.retainedByCurrentCourier && selectedDelivery.nextAction === 'complete_delivery' ? selectedDelivery.recipient.phone : null} messageDeliveryId={selectedDelivery.retainedByCurrentCourier && selectedDelivery.nextAction === 'complete_delivery' && selectedDelivery.canMessage ? selectedDelivery.id : undefined} messagePhase="final_mile" tracking={selectedDelivery.tracking}>
                                <details className="mt-2 text-sm text-slate-600"><summary className="min-h-12 cursor-pointer py-3 font-medium text-slate-800">Assignment details</summary><div className="space-y-2 pb-4"><p>Assigned: {courierDate(selectedDelivery.assignedAt)}</p></div></details>
                            </CourierCurrentJob>}
                        </div>}
                    </div>
                </div> : <CourierEmpty title={normalizedSearch ? 'No matching parcels' : view === 'available' ? 'No available pickups' : view === 'carrying' ? 'No parcels recorded in your custody' : 'No tasks assigned to you'}>{normalizedSearch ? 'Try another waybill, order reference, product, or address.' : view === 'available' ? claimReason || 'Eligible pickups from your origin hub will appear here.' : view === 'carrying' ? 'Parcels appear here only after the latest recorded custody identifies you as their current holder.' : 'Pickup and final-mile work assigned to you will appear here. Assignments do not by themselves mean you are carrying a parcel.'}</CourierEmpty>}
                <CourierDeliveryOverview activity={activity} activePickups={activeCount} pickupLimit={limit} awaitingIntake={pickups.filter((task) => task.retainedByCurrentCourier && task.nextAction === 'await_origin_hub_scan').length} claimReason={claimReason} onViewPickups={() => viewQueue('assigned')} />
                <CourierRecentDeliveries activity={activity} completedToday={stats?.completedToday} />
            </div>

            <CourierDialog open={target !== null} title={target ? actionLabels[target.status] : 'Parcel action'} onClose={() => { if (!pending.current) resetAction(); }} busy={loadingId !== null}>
                {target && <form onSubmit={submit} className="space-y-4">
                    <div className="rounded-[8px] border border-slate-300 bg-[#FFFAFB] p-3"><CourierParcelIdentifiers trackingNumber={target.trackingNumber} orderNumber={targetOrderNumber} /></div>
                    <p className="text-base leading-relaxed text-slate-700">{target.status === 'picked_up' ? 'Confirm only after you have collected this parcel from the seller. Next, bring it to the origin Bayan Hub.' : target.status === 'out_for_delivery' ? 'Confirm that you collected this assigned parcel from the destination Bayan Hub and are starting its delivery leg.' : target.status === 'delivery_failed' ? 'Record the actual attempt, then return the parcel to the destination Bayan Hub. A hub scan is required before recovery.' : 'Record the actual handoff with a proof photo. Only the buyer can confirm receipt and complete the order.'}</p>
                    <CourierFieldError message={errors.status} />
                    {target.status === 'delivery_failed' && <label className="block text-sm font-semibold" htmlFor="failure-reason">Failure reason<select id="failure-reason" required value={failureReason} disabled={loadingId !== null} onChange={event => setFailureReason(event.target.value)} className={courierInput}><option value="">Choose the actual reason</option><option value="customer_unreachable">Customer unreachable</option><option value="customer_unavailable">Customer unavailable or requested another date</option><option value="address_clarification">Wrong or incomplete address</option><option value="unsafe_conditions">Unsafe conditions or severe weather</option><option value="cod_unavailable">Exact COD payment unavailable</option><option value="customer_refused">Customer refused the parcel</option></select><CourierFieldError message={errors.failure_reason} /></label>}
                    {target.status === 'delivery_failed' && <label className="block text-sm font-semibold" htmlFor="attempt-location">Actual attempt location<input id="attempt-location" type="text" required maxLength={255} value={attemptLocation} disabled={loadingId !== null} onChange={event => setAttemptLocation(event.target.value)} className={courierInput} /><CourierFieldError message={errors.location_name} /></label>}
                    <div>
                        <label htmlFor="pickup-waybill" className="block text-sm font-semibold text-slate-800">Parcel waybill</label>
                        <p id="waybill-help" className="mt-1 text-sm text-slate-600">Scan the actual label or enter its waybill manually. Scanning fills this field; it does not record the parcel action.</p>
                        <input id="pickup-waybill" type="text" autoComplete="off" spellCheck={false} maxLength={255} required value={barcode} disabled={loadingId !== null} aria-invalid={Boolean(errors.barcode) || waybillMismatch} aria-describedby="waybill-help waybill-error" onChange={(event) => { setBarcode(event.target.value); setErrors((current) => ({ ...current, barcode: '' })); }} className={courierInput} />
                        <CourierFieldError id="waybill-error" message={errors.barcode} />
                        {waybillMismatch && <p id="waybill-mismatch" role="status" className="mt-2 rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-3 text-sm leading-relaxed text-[#92400E]">This scanned code does not match the selected parcel waybill. Check the physical label, then scan or enter the matching code. The server verifies it again before recording.</p>}
                        <WaybillCamera onScan={(value) => { setBarcode(value); setErrors((current) => ({ ...current, barcode: '' })); }} disabled={loadingId !== null} instruction="A camera scan only fills the waybill field. Compare it with the parcel above, then submit the permitted rider action." />
                    </div>
                    {target.status === 'delivered' && <section className="space-y-4 rounded-[8px] border border-slate-300 bg-[#FFFAFB] p-4" aria-label="Recipient and COD collection">
                        <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="flex items-center gap-2 font-semibold"><Banknote className="h-5 w-5 text-[#C20836]" aria-hidden="true" />{walletHandover ? 'Prepaid wallet delivery' : 'Count cash at handoff'}</h3><span className="font-semibold tabular-nums text-[#C20836]">{walletHandover ? 'No cash due' : `Due: ${actionDue === null ? 'Unavailable' : courierMoney(actionDue / 100)}`}</span></div>
                        <label className="block text-sm font-semibold" htmlFor="cash-recipient">Person receiving the parcel<input id="cash-recipient" required maxLength={255} value={recipientName} disabled={loadingId !== null} onChange={(event) => setRecipientName(event.target.value)} className={courierInput} /><CourierFieldError message={errors.recipient_name} /></label>
                        <label className="block text-sm font-semibold" htmlFor="cash-relationship">Relationship to buyer<select id="cash-relationship" required value={recipientRelationship} disabled={loadingId !== null} onChange={(event) => setRecipientRelationship(event.target.value)} className={courierInput}><option value="">Choose the actual recipient</option><option value="buyer">Buyer</option><option value="household">Household member</option><option value="authorized_recipient">Authorized recipient</option></select><CourierFieldError message={errors.recipient_relationship} /></label>
                        {!walletHandover && <><div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm font-semibold" htmlFor="cash-tender">Cash received (PHP)<input id="cash-tender" type="text" inputMode="decimal" required value={cashReceived} disabled={loadingId !== null} onChange={(event) => setCashReceived(event.target.value)} placeholder="0.00" className={courierInput} /><CourierFieldError message={errors.cash_received} /></label><label className="block text-sm font-semibold" htmlFor="cash-change">Change given (PHP)<input id="cash-change" type="text" inputMode="decimal" required value={changeGiven} disabled={loadingId !== null} onChange={(event) => setChangeGiven(event.target.value)} className={courierInput} /><CourierFieldError message={errors.change_given} /></label></div>
                        <p role="status" className="text-sm text-slate-600">{netCash === null || netCash < 0 ? 'Enter the cash counted and any change actually given.' : `Cash kept for this order: ${courierMoney(netCash / 100)}.${netCash === actionDue ? ' This matches the amount due.' : ' This must match the saved amount due.'}`}</p>
                        <label className="flex items-start gap-3 text-sm leading-relaxed"><input type="checkbox" checked={cashConfirmed} disabled={loadingId !== null} onChange={(event) => setCashConfirmed(event.target.checked)} className="mt-1 rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]" /><span>I counted the cash and gave the stated change. I will hand over the recorded amount separately.</span></label></>}
                        {walletHandover && <p className="text-sm leading-relaxed text-slate-600">The buyer paid with virtual wallet credits. Record the actual recipient and delivery proof; do not collect cash.</p>}<CourierFieldError message={errors.cash_confirmed || errors.request_token} />
                    </section>}
                    {(target.status === 'delivered' || target.status === 'delivery_failed') && <div>
                        <label htmlFor="delivery-proof" className="block text-sm font-semibold text-slate-800">{target.status === 'delivery_failed' ? 'Attempt proof photo' : 'Proof of delivery photo'}</label>
                        <p id="proof-help" className="mt-1 text-xs text-slate-600">JPG, PNG or WEBP. Maximum 5 MB per file. {target.status === 'delivery_failed' ? 'Choosing a photo does not record the attempt.' : 'Choosing a photo does not record delivery.'}</p>
                        <input id="delivery-proof" type="file" accept="image/jpeg,image/png,image/webp" disabled={loadingId !== null} aria-invalid={Boolean(errors.proof_image_file)} aria-describedby="proof-help proof-error" onChange={(event) => { selectProof(event.target.files?.[0] ?? null); event.target.value = ''; }} className={`${courierInput} file:mr-3 file:rounded-[8px] file:border-0 file:bg-[#FDF2F4] file:px-3 file:py-2 file:text-sm file:font-semibold file:text-[#C20836]`} />
                        <CourierFieldError id="proof-error" message={errors.proof_image_file} />
                        {proof && <div className="mt-3 space-y-2">{preview && <img src={preview} alt="Selected delivery proof preview" className="max-h-56 w-full rounded-[8px] border border-slate-300 object-contain" />}<p className="break-words text-sm text-slate-600">{proof.name}</p><button type="button" disabled={loadingId !== null} onClick={() => selectProof(null)} className={courierButton}>Remove photo</button></div>}
                    </div>}
                    <label className="block text-sm font-semibold text-slate-800" htmlFor="courier-notes">{target.status === 'delivery_failed' ? 'Attempt notes' : 'Optional note'}<textarea required={target.status === 'delivery_failed'} id="courier-notes" rows={3} maxLength={500} value={notes} disabled={loadingId !== null} aria-invalid={Boolean(errors.courier_notes || errors.notes)} aria-describedby="notes-help notes-error" onChange={(event) => setNotes(event.target.value)} className={`${courierInput} resize-y`} /></label>
                    <p id="notes-help" className="text-sm text-slate-600">Up to 500 characters.{target.status === 'picked_up' ? ' Pickup notes are also sent to the seller.' : ''}</p>
                    <CourierFieldError id="notes-error" message={errors.courier_notes || errors.notes} />
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={loadingId !== null} onClick={resetAction} className={courierButton}>Cancel</button><button type="submit" disabled={loadingId !== null || waybillMismatch} className={courierPrimary}>{target.status === 'delivered' && <Camera className="h-5 w-5" aria-hidden="true" />}{loadingId !== null ? 'Recording…' : actionLabels[target.status]}</button></div>
                </form>}
            </CourierDialog>
        </CourierLayout>
    );
}
