import { useEffect, useRef, useState, type FormEvent } from 'react';
import useOperationalRefresh from '@/hooks/useOperationalRefresh';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Banknote, Camera, Compass, MapPin, Navigation, RefreshCw, Search, Truck } from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import CourierJobMap from '@/Components/CourierJobMap';
import CourierCurrentJob from '@/Components/CourierCurrentJob';
import CourierTaskCard, { type CourierTaskItem } from '@/Components/CourierTaskCard';
import WaybillCamera from '@/Components/WaybillCamera';
import { CourierDeliveryOverview, CourierRecentDeliveries, type CourierActivity } from '@/Components/CourierDashboard';
import { CourierDialog, CourierEmpty, CourierFieldError, CourierPanel, CourierParcelIdentifiers, courierButton, courierClasses, courierInput, courierPrimary } from '@/Components/CourierUI';
import { courierDate, courierGreeting, courierMoney, courierPath, directionsUrl, selectCourierMapJob, type CourierMapJob, type CourierPlace, type CourierScope } from '@/utils/courier';
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
    cashSummary?: {
        held_cents: number;
        max_float_cents: number;
        warning_float_cents: number;
        is_locked: boolean;
        is_warning: boolean;
    };
}

function calculateDistanceMeters(lat1: number, lon1: number, lat2: number, lon2: number): number {
    const R = 6371e3; // Earth radius in meters
    const rad = (deg: number) => (deg * Math.PI) / 180;
    const dLat = rad(lat2 - lat1);
    const dLon = rad(lon2 - lon1);
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return Math.round(R * c);
}

type WorkspaceView = 'assigned' | 'available';
type WorkspaceEntry = { phase: 'pickup'; task: PickupTask } | { phase: 'available'; task: PickupTask } | { phase: 'final_mile'; task: FinalMileTask };
type ActionTarget = { id: number; trackingNumber: string; status: 'picked_up' | 'out_for_delivery' | 'delivered' | 'delivery_failed' };
const actionLabels = { picked_up: 'Confirm pickup', out_for_delivery: 'Start delivery', delivered: 'Record delivery', delivery_failed: 'Record failed attempt' };

export default function CourierDeliveries({ scope, isOnline = false, stats, queues, recoveryRequestToken, cashSummary }: Props) {
    const { auth } = usePage<PageProps>().props;
    const pickups = queues?.pickupTasks ?? [];
    const available = queues?.availablePickups ?? [];
    const deliveries = queues?.finalMileTasks ?? [];
    const activity = queues?.recentActivity ?? [];
    const [view, setView] = useState<WorkspaceView>(() => {
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
    const [isRepresentative, setIsRepresentative] = useState(false);
    const [cashReceived, setCashReceived] = useState('');
    const [changeGiven, setChangeGiven] = useState('0.00');
    const [cashConfirmed, setCashConfirmed] = useState(false);
    const [proof, setProof] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [loadingId, setLoadingId] = useState<number | null>(null);
    const [riderGps, setRiderGps] = useState<{ lat: number; lng: number } | null>(null);
    const [gpsDistance, setGpsDistance] = useState<number | null>(null);
    useOperationalRefresh(loadingId !== null, ['scope', 'isOnline', 'stats', 'queues', 'recoveryRequestToken']);
    const pending = useRef(false);
    useCourierRequestError(pending, (message) => setErrors({ status: message }));
    const normalizedSearch = search.trim().toLowerCase();
    const matches = (...values: Array<string | null | undefined>) => values.some((value) => value?.toLowerCase().includes(normalizedSearch));
    const carryingPickups = pickups.filter((task) => task.retainedByCurrentCourier);
    const carryingCount = carryingPickups.length;
    const assignedEntries: WorkspaceEntry[] = [
        ...pickups.filter((task) => !task.retainedByCurrentCourier).map((task) => ({ phase: 'pickup' as const, task })),
        ...deliveries.map((task) => ({ phase: 'final_mile' as const, task })),
    ];
    const availableEntries: WorkspaceEntry[] = available.map((task) => ({ phase: 'available', task }));
    const viewEntries = view === 'assigned' ? assignedEntries : availableEntries;
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
                stopKind: retained ? 'origin_hub' : 'seller',
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
            stopKind: atHub ? 'destination_hub' : 'buyer',
            place: task.handoverHold || custodyReview ? {} : atHub ? task.destinationHub : task.recipient, preview: false,
            instruction: task.handoverHold ? retained ? 'You still hold this parcel. Follow the authorized recovery instruction and return it to the named hub.' : 'Complete the authorized handover recovery before ordinary parcel work continues.' : custodyReview ? 'The parcel stage is recorded, but the latest custody record does not confirm that you hold this parcel. Review tracking before moving it.' : task.nextAction === 'return_to_hub' ? 'Return this parcel to the destination hub. Its handler must scan it back before retry or reverse routing.' : task.nextAction === 'start_delivery' ? 'Collect the assigned parcel from this hub and confirm the actual scan before starting delivery.' : retained ? 'Deliver to the saved destination and record the actual handoff with proof. Verified delivery completes new orders automatically.' : 'Review the parcel tracking before moving it.',
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
        const deliveryTask = deliveries.find((task) => task.id === id);
        const defaultBuyerName = deliveryTask?.recipient?.name || '';
        setRecipientName(status === 'delivered' ? defaultBuyerName : '');
        setRecipientRelationship(status === 'delivered' ? 'buyer' : '');
        setIsRepresentative(false);
        setCashReceived(''); setChangeGiven('0.00'); setCashConfirmed(false);
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
        if (target.status === 'delivered' && !walletHandover && cashSummary?.is_locked) {
            setErrors({ status: 'Cannot complete COD delivery: You have reached the ₱20,000 unremitted cash float limit. Please remit cash at a Bayan Hub first.' });
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
            latitude: riderGps?.lat,
            longitude: riderGps?.lng,
            geofence_verified: gpsDistance !== null ? gpsDistance <= 100 : undefined,
        }, {
            forceFormData: true, preserveScroll: true,
            onSuccess: (page) => {
                const error = (page.props as PageProps).flash?.error;
                if (error) setErrors({ status: error });
                else {
                    setView('assigned');
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

    useEffect(() => {
        if (target?.status === 'delivered' && typeof navigator !== 'undefined' && 'geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    setRiderGps({ lat, lng });
                    if (selectedDelivery?.recipient?.latitude && selectedDelivery?.recipient?.longitude) {
                        const dist = calculateDistanceMeters(
                            lat,
                            lng,
                            selectedDelivery.recipient.latitude,
                            selectedDelivery.recipient.longitude
                        );
                        setGpsDistance(dist);
                    }
                },
                () => {},
                { enableHighAccuracy: true, timeout: 8000 }
            );
        } else if (!target) {
            setRiderGps(null);
            setGpsDistance(null);
        }
    }, [target?.status, selectedDelivery?.id]);
    const collected = selectedPickup?.retainedByCurrentCourier === true;
    const assignedCount = assignedEntries.length;

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
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-rose-200/90 bg-rose-50/60 p-3.5 shadow-2xs">
                    <div className="flex items-center gap-2.5">
                        <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[#E00D42] text-white shadow-sm">
                            <Compass className="h-5 w-5" />
                        </span>
                        <div>
                            <h2 className="text-sm font-bold text-slate-900">Live Road Navigation & Dynamic Route</h2>
                            <p className="text-xs text-slate-600">Lively tracks your GPS with AH26 national highway prioritization and fullscreen bike mount view.</p>
                        </div>
                    </div>
                    <Link
                        href={courierPath('/navigation')}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#18181B] px-3.5 py-2 text-xs font-bold text-white hover:bg-zinc-800 shadow-sm transition-colors"
                    >
                        <Navigation className="h-4 w-4 text-[#E00D42]" />
                        <span>Launch Fullscreen Map</span>
                    </Link>
                </div>

                <details className="rounded-[8px] border border-slate-200 bg-white p-4" open={sampleMapOpen} onToggle={(event) => setSampleMapOpen(event.currentTarget.open)}>
                    <summary className="cursor-pointer py-2 text-sm font-semibold flex items-center justify-between">
                        <span className="flex items-center gap-2">
                            <Compass className="h-4 w-4 text-[#E00D42]" />
                            <span>Route Map Preview (Next Stop)</span>
                        </span>
                        <span className="text-xs font-normal text-slate-500">
                            {selectedMapJob ? selectedMapJob.stopLabel : mapJobs[0] ? mapJobs[0].stopLabel : 'Active assignment'}
                        </span>
                    </summary>
                    {sampleMapOpen && (
                        <div className="pt-3">
                            <CourierJobMap job={selectedMapJob || mapJobs[0]} />
                        </div>
                    )}
                </details>
                <div className="flex flex-wrap justify-end gap-2"><button type="button" disabled={loadingId !== null} onClick={() => {
                    if (pending.current) return;
                    pending.current = true; setLoadingId(-1); setErrors({});
                    router.reload({ only: ['scope', 'isOnline', 'stats', 'queues', 'recoveryRequestToken', 'flash'],
                        onError: setErrors, onFinish: () => { pending.current = false; setLoadingId(null); },
                    });
                }} className={`${courierButton} gap-2`}><RefreshCw className="h-4 w-4" aria-hidden="true" />{loadingId === -1 ? 'Refreshing…' : 'Refresh tracking'}</button><Link href={courierPath('/cod')} className={`${courierButton} gap-2`}><Banknote className="h-4 w-4" aria-hidden="true" />COD cash handovers</Link></div>
                {!isOnline && <p className="rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">You are off duty. Existing assigned parcels remain available below; go on duty in the header to receive new work.</p>}
                {scope?.isAssigned && !scope.isOperational && <p className="rounded-[8px] border border-amber-300 bg-[#FFF4DF] p-4 text-sm leading-relaxed text-[#92400E]">New work is paused at your company or hub. Continue handling parcels already assigned to you.</p>}
                {cashSummary && (
                    <div className={`rounded-xl border p-4 shadow-sm ${cashSummary.is_locked ? 'border-rose-300 bg-rose-50 text-rose-950' : cashSummary.is_warning ? 'border-amber-300 bg-[#FFF4DF] text-[#92400E]' : 'border-slate-200 bg-white text-slate-900'}`}>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${cashSummary.is_locked ? 'bg-rose-100 text-rose-700' : cashSummary.is_warning ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700'}`}>
                                    <Banknote className="h-5 w-5" />
                                </span>
                                <div>
                                    <h3 className="text-sm font-bold">Rider COD Cash Float Shield</h3>
                                    <p className="text-xs text-slate-600">
                                        {cashSummary.is_locked
                                            ? '🛑 Delivery Locked: You have reached the ₱20,000 unremitted COD cash limit. Visit a Bayan Hub to remit funds and unlock COD deliveries.'
                                            : cashSummary.is_warning
                                            ? '⚠️ Caution: You are nearing the ₱20,000 unremitted COD cash limit. Plan a remittance drop at your Bayan Hub.'
                                            : 'Unremitted cash collected on the road is tracked for safety.'}
                                    </p>
                                </div>
                            </div>
                            <div className="text-right">
                                <p className="text-base font-extrabold tabular-nums">
                                    ₱{(cashSummary.held_cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2 })}
                                    <span className="text-xs font-normal text-slate-500"> / ₱20,000.00</span>
                                </p>
                                <div className="mt-1 h-2 w-32 overflow-hidden rounded-full bg-slate-200">
                                    <div
                                        className={`h-full transition-all duration-300 ${cashSummary.is_locked ? 'bg-rose-600' : cashSummary.is_warning ? 'bg-amber-500' : 'bg-emerald-500'}`}
                                        style={{ width: `${Math.min(100, (cashSummary.held_cents / cashSummary.max_float_cents) * 100)}%` }}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                )}
                {carryingCount > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50/70 p-3.5 text-xs text-sky-900 shadow-2xs">
                        <div className="flex items-center gap-2.5">
                            <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-sky-600 text-white font-bold shrink-0">
                                {carryingCount}
                            </span>
                            <div>
                                <p className="font-bold">You are carrying {carryingCount} collected pickup{carryingCount === 1 ? '' : 's'} (in custody). Hub staff must scan it into custody.</p>
                                <p className="text-sky-700">Existing assigned parcels remain available. Carrying pickups are tracked in Trips & History to keep your main delivery queue uncluttered.</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {carryingPickups[0]?.originHub && directionsUrl(carryingPickups[0].originHub) && (
                                <a
                                    href={directionsUrl(carryingPickups[0].originHub)!}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1 rounded-lg border border-sky-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-sky-800 hover:bg-sky-50 transition-colors"
                                >
                                    Directions to Hub
                                </a>
                            )}
                            <Link
                                href={courierPath('/earnings')}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-sky-700 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-sky-800 transition-colors shadow-2xs"
                            >
                                <span>View Pickups in Trips</span>
                                <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        </div>
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="group" aria-label="Task filters" className="flex max-w-full gap-1 overflow-x-auto rounded-[8px] bg-slate-100 p-1">
                        {([
                            { key: 'assigned', label: 'Assigned to you', count: assignedCount },
                            { key: 'available', label: 'Available to claim (pickups)', count: stats?.availablePickups ?? available.length },
                        ] as const).map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                aria-pressed={view === item.key}
                                onClick={() => selectView(item.key)}
                                className={courierClasses(
                                    courierButton,
                                    'shrink-0 gap-2 border-transparent bg-transparent px-3 text-sm font-medium hover:bg-slate-200',
                                    view === item.key && 'bg-white text-slate-950 shadow-sm hover:bg-white'
                                )}
                            >
                                <span>{item.label}</span>
                                <span
                                    className={`rounded-[8px] px-2 py-0.5 text-xs tabular-nums ${
                                        view === item.key ? 'bg-slate-100 text-slate-700' : 'bg-slate-200/70 text-slate-600'
                                    }`}
                                >
                                    {item.count}
                                </span>
                            </button>
                        ))}
                    </div>
                    <label className="relative block w-full sm:w-72"><span className="sr-only">Search this queue</span><Search className="pointer-events-none absolute left-3 top-4 h-4 w-4 text-slate-500" aria-hidden="true" /><input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Waybill, order, product, or address" className={`${courierInput} mt-0 pl-10 text-sm`} /></label>
                </div>
                {!target && <CourierFieldError message={errors.status || Object.values(errors).find(Boolean)} />}
                {visibleEntries.length ? <div className="grid items-start gap-5 min-[860px]:grid-cols-[minmax(0,1fr)_minmax(20rem,1.05fr)]">
                    <section aria-label={`${view === 'assigned' ? 'Assigned to you' : 'Available pickups'} queue`} className="space-y-4">
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
                </div> : <CourierEmpty title={normalizedSearch ? 'No matching parcels' : view === 'available' ? 'No available pickups' : 'No tasks assigned to you'}>{normalizedSearch ? 'Try another waybill, order reference, product, or address.' : view === 'available' ? claimReason || 'Eligible pickups from your origin hub will appear here.' : 'Pickup and final-mile work assigned to you will appear here. Assignments do not by themselves mean you are carrying a parcel.'}</CourierEmpty>}
                <CourierDeliveryOverview activity={activity} activePickups={activeCount} pickupLimit={limit} awaitingIntake={pickups.filter((task) => task.retainedByCurrentCourier && task.nextAction === 'await_origin_hub_scan').length} claimReason={claimReason} onViewPickups={() => viewQueue('assigned')} />
                <CourierRecentDeliveries activity={activity} completedToday={stats?.completedToday} />
            </div>

            <CourierDialog open={target !== null} title={target ? actionLabels[target.status] : 'Parcel action'} onClose={() => { if (!pending.current) resetAction(); }} busy={loadingId !== null}>
                {target && <form onSubmit={submit} className="space-y-4">
                    <CourierParcelIdentifiers trackingNumber={target.trackingNumber} orderNumber={targetOrderNumber} />
                    <p className="text-base leading-relaxed text-slate-700">{target.status === 'picked_up' ? 'Confirm only after you have collected this parcel from the seller. Next, bring it to the origin Bayan Hub.' : target.status === 'out_for_delivery' ? 'Confirm that you collected this assigned parcel from the destination Bayan Hub and are starting its delivery leg.' : target.status === 'delivery_failed' ? 'Record the actual attempt, then return the parcel to the destination Bayan Hub. A hub scan is required before recovery.' : 'Record the actual recipient, payment and proof photo. The order updates after these delivery checks.'}</p>
                    <CourierFieldError message={errors.status} />
                    {target.status === 'delivery_failed' && <label className="block text-sm font-semibold" htmlFor="failure-reason">Failure reason<select id="failure-reason" required value={failureReason} disabled={loadingId !== null} onChange={event => setFailureReason(event.target.value)} className={courierInput}><option value="">Choose the actual reason</option><option value="customer_unreachable">Customer unreachable</option><option value="customer_unavailable">Customer unavailable or requested another date</option><option value="address_clarification">Wrong or incomplete address</option><option value="unsafe_conditions">Unsafe conditions or severe weather</option><option value="cod_unavailable">Exact COD payment unavailable</option><option value="customer_refused">Customer refused the parcel</option></select><CourierFieldError message={errors.failure_reason} /></label>}
                    {target.status === 'delivery_failed' && <label className="block text-sm font-semibold" htmlFor="attempt-location">Actual attempt location<input id="attempt-location" type="text" required maxLength={255} value={attemptLocation} disabled={loadingId !== null} onChange={event => setAttemptLocation(event.target.value)} className={courierInput} /><CourierFieldError message={errors.location_name} /></label>}
                    <div className="space-y-2">
                        <label htmlFor="pickup-waybill" className="block text-sm font-bold text-slate-900">Physical Parcel Waybill</label>
                        <p id="waybill-help" className="text-xs text-slate-600">Camera auto-opens below. If barcode is damaged, type the exact code from the thermal label manually.</p>
                        <input id="pickup-waybill" type="text" autoComplete="off" spellCheck={false} maxLength={255} required value={barcode} disabled={loadingId !== null} aria-invalid={Boolean(errors.barcode) || waybillMismatch} aria-describedby="waybill-help waybill-error" onChange={(event) => { setBarcode(event.target.value); setErrors((current) => ({ ...current, barcode: '' })); }} className={`${courierInput} font-bold text-sm tracking-wide text-[#C20836]`} placeholder="Scan or type waybill (e.g. BG-DEL-...)" />
                        <CourierFieldError id="waybill-error" message={errors.barcode} />
                        {waybillMismatch && <p id="waybill-mismatch" role="status" className="mt-2 rounded-xl border border-amber-300 bg-[#FFF4DF] p-3 text-sm leading-relaxed text-[#92400E]">This scanned code does not match the selected parcel waybill. Check the physical label, then scan or enter the matching code. The server verifies it again before recording.</p>}
                        <WaybillCamera
                            autoStart={target.status === 'picked_up' || target.status === 'out_for_delivery' || target.status === 'delivered'}
                            onScan={(value) => { setBarcode(value); setErrors((current) => ({ ...current, barcode: '' })); }}
                            disabled={loadingId !== null}
                            instruction="Point camera directly at the physical box waybill barcode to fill this code automatically."
                        />
                    </div>
                    {target.status === 'delivered' && (
                        <>
                            {gpsDistance !== null && (
                                <div className={`flex items-center gap-2 rounded-xl border p-3 text-xs font-semibold ${gpsDistance <= 100 ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-amber-300 bg-amber-50 text-[#92400E]'}`}>
                                    <MapPin className="h-4 w-4 shrink-0 text-[#E00D42]" />
                                    <span>
                                        {gpsDistance <= 100
                                            ? `GPS Verified: Within customer delivery radius (~${gpsDistance}m away)`
                                            : `Location Notice: ~${gpsDistance}m from registered delivery coordinates. Ensure you are at the correct location.`}
                                    </span>
                                </div>
                            )}
                            {cashSummary?.is_locked && !walletHandover && (
                                <div className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-xs text-rose-800">
                                    <strong>COD Delivery Locked:</strong> You have reached the ₱20,000 unremitted cash limit. You cannot collect more COD cash until you make a deposit at a Bayan Hub.
                                </div>
                            )}
                        </>
                    )}
                    {target.status === 'delivered' && <section className="space-y-4 rounded-xl border border-slate-300 bg-[#FFFAFB] p-4" aria-label="Recipient and COD collection">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-rose-100 pb-3">
                            <h3 className="flex items-center gap-2 font-bold text-sm text-slate-900">
                                <Banknote className="h-5 w-5 text-[#C20836]" aria-hidden="true" />
                                {walletHandover ? 'Prepaid Wallet Delivery' : 'Count Cash at Handoff'}
                            </h3>
                            <span className="font-extrabold tabular-nums text-sm text-[#C20836]">
                                {walletHandover ? 'No cash due' : `Due: ${actionDue === null ? 'Unavailable' : courierMoney(actionDue / 100)}`}
                            </span>
                        </div>

                        {/* Streamlined Recipient Handover */}
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 space-y-3">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <span className="text-xs font-bold text-slate-900 block">Recipient Verification</span>
                                    <p className="text-xs text-slate-500">
                                        {isRepresentative ? 'Delivering to an authorized representative / guard' : 'Direct handover to buyer: '}
                                        {!isRepresentative && <strong className="text-slate-900">{recipientName || 'Buyer'}</strong>}
                                    </p>
                                </div>
                                <label className="inline-flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 px-2.5 py-1.5 rounded-lg hover:bg-slate-100 transition">
                                    <input
                                        type="checkbox"
                                        checked={isRepresentative}
                                        disabled={loadingId !== null}
                                        onChange={(e) => {
                                            const checked = e.target.checked;
                                            setIsRepresentative(checked);
                                            const defaultBuyer = deliveries.find((t) => t.id === target.id)?.recipient?.name || '';
                                            if (!checked) {
                                                setRecipientRelationship('buyer');
                                                setRecipientName(defaultBuyer);
                                            } else {
                                                setRecipientRelationship('authorized_recipient');
                                                setRecipientName('');
                                            }
                                        }}
                                        className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <span>Received by representative</span>
                                </label>
                            </div>

                            {isRepresentative && (
                                <div className="pt-2 border-t border-slate-100 space-y-3">
                                    <label className="block text-xs font-bold text-slate-700" htmlFor="cash-recipient">
                                        Representative Full Name
                                        <input
                                            id="cash-recipient"
                                            required
                                            maxLength={255}
                                            placeholder="Enter name of person who received the parcel"
                                            value={recipientName}
                                            disabled={loadingId !== null}
                                            onChange={(event) => setRecipientName(event.target.value)}
                                            className={`${courierInput} mt-1 text-sm`}
                                        />
                                        <CourierFieldError message={errors.recipient_name} />
                                    </label>
                                    <label className="block text-xs font-bold text-slate-700" htmlFor="cash-relationship">
                                        Relationship to Buyer
                                        <select
                                            id="cash-relationship"
                                            required
                                            value={recipientRelationship}
                                            disabled={loadingId !== null}
                                            onChange={(event) => setRecipientRelationship(event.target.value)}
                                            className={`${courierInput} mt-1 text-sm`}
                                        >
                                            <option value="household">Household member / Family</option>
                                            <option value="authorized_recipient">Authorized representative / Guard / Neighbor</option>
                                        </select>
                                        <CourierFieldError message={errors.recipient_relationship} />
                                    </label>
                                </div>
                            )}
                        </div>

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
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" disabled={loadingId !== null} onClick={resetAction} className={courierButton}>Cancel</button><button type="submit" disabled={loadingId !== null || waybillMismatch || Boolean(target.status === 'delivered' && !walletHandover && cashSummary?.is_locked)} className={courierPrimary}>{target.status === 'delivered' && <Camera className="h-5 w-5" aria-hidden="true" />}{loadingId !== null ? 'Recording…' : actionLabels[target.status]}</button></div>
                </form>}
            </CourierDialog>
        </CourierLayout>
    );
}
