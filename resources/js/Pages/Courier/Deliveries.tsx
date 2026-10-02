import React, { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    Building2,
    Camera,
    CheckCircle2,
    ClipboardCheck,
    Clock3,
    History,
    MapPin,
    MessageSquare,
    Package,
    Phone,
    Store,
    Truck,
    X,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Scope {
    company: string | null;
    hub: string | null;
    hubCode: string | null;
    barangay: string | null;
    isAssigned: boolean;
    isOperational: boolean;
}

interface PickupTask {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    status: string;
    itemCount: number;
    merchant: {
        name: string | null;
        address: string;
        phone: string | null;
    };
    originHub: {
        name: string | null;
        code: string | null;
    };
    assignedAt: string | null;
    nextAction: 'claim_pickup' | 'confirm_pickup' | 'await_origin_hub_scan' | null;
    canMessage: boolean;
}

interface FinalMileTask {
    id: number;
    trackingNumber: string;
    orderNumber: string | null;
    status: string;
    recipient: {
        name: string;
        address: string;
        phone: string;
    };
    payment: {
        method: string;
        codAmount: number | null;
    };
    destinationHub: {
        name: string | null;
        code: string | null;
    };
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
    scope: Scope;
    isOnline: boolean;
    stats: {
        availablePickups: number;
        activePickups: number;
        finalMileTasks: number;
        completedToday: number;
    };
    queues: {
        availablePickups: PickupTask[];
        pickupTasks: PickupTask[];
        finalMileTasks: FinalMileTask[];
        recentActivity: Activity[];
    };
}

type Tab = 'pickup' | 'available' | 'final_mile' | 'activity';
type ActionTarget = {
    deliveryId: number;
    trackingNumber: string;
    status: 'picked_up' | 'out_for_delivery' | 'delivered';
} | null;

export default function CourierDeliveries({ scope, isOnline, stats, queues }: Props) {
    const initialTab = useMemo<Tab>(() => {
        if (queues.finalMileTasks.length > 0) return 'final_mile';
        if (queues.pickupTasks.length > 0) return 'pickup';
        return 'available';
    }, [queues.finalMileTasks.length, queues.pickupTasks.length]);
    const [activeTab, setActiveTab] = useState<Tab>(initialTab);
    const [actionTarget, setActionTarget] = useState<ActionTarget>(null);
    const [notes, setNotes] = useState('');
    const [proofFile, setProofFile] = useState<File | null>(null);
    const [proofPreview, setProofPreview] = useState<string | null>(null);
    const [loadingId, setLoadingId] = useState<number | null>(null);

    const tabs: Array<{ id: Tab; label: string; count: number; icon: React.ElementType }> = [
        { id: 'pickup', label: 'My pickups', count: stats.activePickups, icon: Store },
        { id: 'available', label: 'Available pickups', count: stats.availablePickups, icon: Package },
        { id: 'final_mile', label: 'Final-mile tasks', count: stats.finalMileTasks, icon: Truck },
        { id: 'activity', label: 'Recent activity', count: queues.recentActivity.length, icon: History },
    ];

    const formatCurrency = (amount: number | null) =>
        amount === null
            ? 'Not applicable'
            : new Intl.NumberFormat('en-PH', {
                  style: 'currency',
                  currency: 'PHP',
              }).format(amount);

    const formatDate = (value: string | null) => {
        if (!value) return 'Not recorded';
        return new Intl.DateTimeFormat('en-PH', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        }).format(new Date(value));
    };

    const claimPickup = (deliveryId: number) => {
        setLoadingId(deliveryId);
        router.post(
            route('courier.claim', deliveryId),
            {},
            {
                preserveScroll: true,
                onFinish: () => setLoadingId(null),
            },
        );
    };

    const openAction = (
        deliveryId: number,
        trackingNumber: string,
        status: ActionTarget extends infer T
            ? T extends { status: infer S }
                ? S
                : never
            : never,
    ) => {
        setNotes('');
        setProofFile(null);
        setProofPreview(null);
        setActionTarget({ deliveryId, trackingNumber, status });
    };

    const closeAction = () => {
        if (loadingId !== null) return;
        if (proofPreview) URL.revokeObjectURL(proofPreview);
        setActionTarget(null);
        setProofFile(null);
        setProofPreview(null);
        setNotes('');
    };

    const submitAction = (event: React.FormEvent) => {
        event.preventDefault();
        if (!actionTarget) return;

        setLoadingId(actionTarget.deliveryId);
        router.post(
            route('courier.updateStatus', actionTarget.deliveryId),
            {
                _method: 'patch',
                status: actionTarget.status,
                courier_notes: notes.trim() || undefined,
                proof_image_file:
                    actionTarget.status === 'delivered' ? proofFile ?? undefined : undefined,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: closeAction,
                onFinish: () => setLoadingId(null),
            },
        );
    };

    return (
        <CourierLayout
            title="Rider operations"
            subtitle={
                scope.isAssigned
                    ? `${scope.hub} · ${scope.company}`
                    : 'Waiting for a logistics company and hub assignment'
            }
            isOnline={isOnline}
        >
            <Head title="Rider operations — BagooPH" />

            <div className="space-y-5">
                {!scope.isAssigned && (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        Your approved rider account does not have a working hub yet. A logistics administrator
                        must assign your company and facility before pickup jobs can appear.
                    </div>
                )}
                {scope.isAssigned && !scope.isOperational && (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">
                        Your assigned logistics company or working hub is inactive. New jobs are paused, but
                        any existing custody tasks remain visible and must be resolved with your administrator.
                    </div>
                )}

                <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <MetricCard label="Available pickups" value={stats.availablePickups} icon={Package} />
                    <MetricCard label="My pickup tasks" value={stats.activePickups} icon={Store} />
                    <MetricCard label="Final-mile tasks" value={stats.finalMileTasks} icon={Truck} />
                    <MetricCard label="Delivered today" value={stats.completedToday} icon={CheckCircle2} />
                </section>

                <section className="rounded-lg border border-slate-300 bg-white p-2">
                    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                        {tabs.map((tab) => (
                            <button
                                type="button"
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={`flex min-w-0 items-center justify-between gap-2 rounded-sm border px-3 py-2.5 text-left text-sm font-semibold transition ${
                                    activeTab === tab.id
                                        ? 'border-slate-900 bg-slate-900 text-white'
                                        : 'border-transparent text-slate-600 hover:border-slate-300 hover:bg-slate-50'
                                }`}
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <tab.icon className="h-4 w-4 shrink-0" />
                                    <span className="truncate">{tab.label}</span>
                                </span>
                                <span
                                    className={`rounded-sm px-1.5 py-0.5 text-xs ${
                                        activeTab === tab.id
                                            ? 'bg-white/15 text-white'
                                            : 'bg-slate-100 text-slate-600'
                                    }`}
                                >
                                    {tab.count}
                                </span>
                            </button>
                        ))}
                    </div>
                </section>

                {activeTab === 'pickup' && (
                    <QueueSection
                        title="My pickup tasks"
                        description="Collect the parcel from the merchant, then hand it only to the listed Origin Bayan Hub."
                        emptyTitle="No active pickup assignment"
                        emptyText="Eligible jobs assigned to you will appear here."
                    >
                        {queues.pickupTasks.map((task) => (
                            <PickupCard
                                key={task.id}
                                task={task}
                                loading={loadingId === task.id}
                                onConfirm={() =>
                                    openAction(task.id, task.trackingNumber, 'picked_up')
                                }
                            />
                        ))}
                    </QueueSection>
                )}

                {activeTab === 'available' && (
                    <QueueSection
                        title="Available pickup jobs"
                        description="Only ready parcels from your assigned company and Origin Bayan Hub are listed."
                        emptyTitle="No eligible pickup jobs"
                        emptyText="There are no ready parcels at your working hub right now."
                    >
                        {!isOnline && queues.availablePickups.length > 0 && (
                            <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                Go on duty before claiming a pickup. Existing assignments remain visible while
                                you are off duty.
                            </div>
                        )}
                        {queues.availablePickups.map((task) => (
                            <AvailablePickupCard
                                key={task.id}
                                task={task}
                                disabled={!isOnline || loadingId !== null}
                                loading={loadingId === task.id}
                                onClaim={() => claimPickup(task.id)}
                            />
                        ))}
                    </QueueSection>
                )}

                {activeTab === 'final_mile' && (
                    <QueueSection
                        title="Final-mile assignments"
                        description="These buyer details are visible because the Destination Bayan Hub assigned the parcel to you."
                        emptyTitle="No final-mile assignment"
                        emptyText="Destination-hub dispatches assigned to you will appear here."
                    >
                        {queues.finalMileTasks.map((task) => (
                            <FinalMileCard
                                key={task.id}
                                task={task}
                                codLabel={formatCurrency(task.payment.codAmount)}
                                loading={loadingId === task.id}
                                onStart={() =>
                                    openAction(task.id, task.trackingNumber, 'out_for_delivery')
                                }
                                onComplete={() =>
                                    openAction(task.id, task.trackingNumber, 'delivered')
                                }
                            />
                        ))}
                    </QueueSection>
                )}

                {activeTab === 'activity' && (
                    <QueueSection
                        title="Recent completed deliveries"
                        description="Recorded final-mile deliveries only. Financial reconciliation is handled separately."
                        emptyTitle="No completed deliveries"
                        emptyText="Your completed final-mile trips will appear here."
                    >
                        <div className="grid gap-3">
                            {queues.recentActivity.map((activity) => (
                                <div
                                    key={activity.id}
                                    className="grid gap-3 rounded-lg border border-slate-300 p-4 sm:grid-cols-[1.1fr_1fr_auto] sm:items-center"
                                >
                                    <div>
                                        <p className="text-sm font-bold text-slate-950">
                                            {activity.trackingNumber}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Order {activity.orderNumber} · {activity.paymentMethod}
                                        </p>
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium text-slate-800">
                                            {activity.recipientName}
                                        </p>
                                        <p className="mt-1 line-clamp-1 text-xs text-slate-500">
                                            {activity.deliveryAddress}
                                        </p>
                                    </div>
                                    <div className="text-left sm:text-right">
                                        <p className="text-xs font-medium text-slate-700">
                                            {formatDate(activity.deliveredAt)}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {activity.destinationHub ?? 'Destination hub unavailable'}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </QueueSection>
                )}
            </div>

            {actionTarget && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 p-4">
                    <div className="w-full max-w-lg rounded-lg border border-slate-300 bg-white shadow-2xl">
                        <div className="flex items-start justify-between border-b border-slate-200 p-5">
                            <div>
                                <h2 className="text-base font-bold text-slate-950">
                                    {actionTarget.status === 'picked_up' && 'Confirm merchant pickup'}
                                    {actionTarget.status === 'out_for_delivery' && 'Start final-mile delivery'}
                                    {actionTarget.status === 'delivered' && 'Record successful delivery'}
                                </h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    Tracking {actionTarget.trackingNumber}
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close"
                                onClick={closeAction}
                                className="rounded-sm border border-slate-300 p-1.5 text-slate-500 hover:bg-slate-100"
                            >
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={submitAction} className="space-y-4 p-5">
                            {actionTarget.status === 'picked_up' && (
                                <InstructionBox text="Confirm only after matching the parcel waybill at the merchant. Your custody continues until the assigned Origin Bayan Hub scans it inbound." />
                            )}
                            {actionTarget.status === 'out_for_delivery' && (
                                <InstructionBox text="Confirm that you received this parcel from the Destination Bayan Hub before leaving for the buyer address." />
                            )}
                            {actionTarget.status === 'delivered' && (
                                <>
                                    <InstructionBox text="Successful delivery requires a real handoff photo. Buyer confirmation and financial reconciliation remain separate." />
                                    <div>
                                        <label
                                            htmlFor="proof-image"
                                            className="mb-1.5 block text-sm font-semibold text-slate-800"
                                        >
                                            Handoff proof photo
                                        </label>
                                        <label
                                            htmlFor="proof-image"
                                            className="flex cursor-pointer items-center justify-center gap-2 rounded-sm border border-dashed border-slate-400 bg-slate-50 px-4 py-4 text-sm font-medium text-slate-700 hover:bg-slate-100"
                                        >
                                            <Camera className="h-5 w-5" />
                                            {proofFile ? proofFile.name : 'Choose or capture a photo'}
                                        </label>
                                        <input
                                            id="proof-image"
                                            type="file"
                                            className="sr-only"
                                            accept="image/*"
                                            capture="environment"
                                            required
                                            onChange={(event) => {
                                                const file = event.target.files?.[0] ?? null;
                                                if (proofPreview) URL.revokeObjectURL(proofPreview);
                                                setProofFile(file);
                                                setProofPreview(file ? URL.createObjectURL(file) : null);
                                            }}
                                        />
                                        {proofPreview && (
                                            <div className="mt-3 aspect-video overflow-hidden rounded-md border border-slate-300 bg-slate-100">
                                                <img
                                                    src={proofPreview}
                                                    alt="Selected handoff proof"
                                                    className="h-full w-full object-cover"
                                                />
                                            </div>
                                        )}
                                    </div>
                                </>
                            )}

                            <div>
                                <label
                                    htmlFor="courier-notes"
                                    className="mb-1.5 block text-sm font-semibold text-slate-800"
                                >
                                    Operational notes <span className="font-normal text-slate-500">(optional)</span>
                                </label>
                                <textarea
                                    id="courier-notes"
                                    rows={3}
                                    maxLength={500}
                                    value={notes}
                                    onChange={(event) => setNotes(event.target.value)}
                                    placeholder="Add useful handoff or delivery details."
                                    className="w-full rounded-sm border border-slate-300 px-3 py-2.5 text-sm focus:border-[#E00D42] focus:ring-[#E00D42]"
                                />
                            </div>

                            <div className="flex flex-col-reverse gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:justify-end">
                                <button
                                    type="button"
                                    onClick={closeAction}
                                    className="rounded-sm border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={
                                        loadingId !== null ||
                                        (actionTarget.status === 'delivered' && !proofFile)
                                    }
                                    className="rounded-sm bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#C50B39] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {loadingId !== null ? 'Saving...' : 'Confirm action'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CourierLayout>
    );
}

function MetricCard({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number;
    icon: React.ElementType;
}) {
    return (
        <div className="rounded-lg border border-slate-300 bg-white p-4">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-xs font-medium text-slate-500">{label}</p>
                    <p className="mt-2 text-2xl font-bold text-slate-950">{value}</p>
                </div>
                <span className="rounded-sm border border-slate-300 bg-slate-50 p-2 text-slate-600">
                    <Icon className="h-4 w-4" />
                </span>
            </div>
        </div>
    );
}

function QueueSection({
    title,
    description,
    emptyTitle,
    emptyText,
    children,
}: {
    title: string;
    description: string;
    emptyTitle: string;
    emptyText: string;
    children: React.ReactNode;
}) {
    const childrenArray = React.Children.toArray(children);
    const hasContent = childrenArray.some(
        (child) => React.isValidElement(child) || (typeof child === 'string' && child.trim() !== ''),
    );

    return (
        <section className="rounded-lg border border-slate-300 bg-white">
            <div className="border-b border-slate-200 p-4 sm:p-5">
                <h2 className="text-base font-bold text-slate-950">{title}</h2>
                <p className="mt-1 text-sm text-slate-500">{description}</p>
            </div>
            <div className="space-y-3 p-4 sm:p-5">
                {hasContent ? (
                    children
                ) : (
                    <div className="py-10 text-center">
                        <Package className="mx-auto h-9 w-9 text-slate-300" />
                        <p className="mt-3 text-sm font-semibold text-slate-800">{emptyTitle}</p>
                        <p className="mt-1 text-sm text-slate-500">{emptyText}</p>
                    </div>
                )}
            </div>
        </section>
    );
}

function AvailablePickupCard({
    task,
    disabled,
    loading,
    onClaim,
}: {
    task: PickupTask;
    disabled: boolean;
    loading: boolean;
    onClaim: () => void;
}) {
    return (
        <article className="rounded-lg border border-slate-300 p-4">
            <TaskHeader task={task} badge="READY" />
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <DetailBlock icon={Store} label="Merchant" title={task.merchant.name ?? 'Merchant store'}>
                    {task.merchant.address}
                </DetailBlock>
                <DetailBlock
                    icon={Building2}
                    label="Required handoff"
                    title={task.originHub.name ?? 'Origin Bayan Hub'}
                >
                    {task.originHub.code ?? 'Facility code unavailable'}
                </DetailBlock>
            </div>
            <div className="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-xs text-slate-500">{task.itemCount} parcel item(s)</p>
                <button
                    type="button"
                    onClick={onClaim}
                    disabled={disabled}
                    className="rounded-sm bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#C50B39] disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {loading ? 'Claiming...' : 'Claim pickup'}
                </button>
            </div>
        </article>
    );
}

function PickupCard({
    task,
    loading,
    onConfirm,
}: {
    task: PickupTask;
    loading: boolean;
    onConfirm: () => void;
}) {
    const awaitingHub = task.nextAction === 'await_origin_hub_scan';

    return (
        <article className="rounded-lg border border-slate-300 p-4">
            <TaskHeader task={task} badge={awaitingHub ? 'IN CUSTODY' : 'ASSIGNED'} />
            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                <DetailBlock icon={Store} label="Collect from" title={task.merchant.name ?? 'Merchant store'}>
                    <span className="block">{task.merchant.address}</span>
                    {task.merchant.phone && (
                        <span className="mt-1 flex items-center gap-1.5">
                            <Phone className="h-3.5 w-3.5" />
                            {task.merchant.phone}
                        </span>
                    )}
                </DetailBlock>
                <DetailBlock
                    icon={Building2}
                    label="Hand off to"
                    title={task.originHub.name ?? 'Origin Bayan Hub'}
                >
                    {task.originHub.code ?? 'Facility code unavailable'}
                </DetailBlock>
            </div>
            <div className="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <Link
                    href={route('courier.messages', { delivery: task.id })}
                    className="inline-flex items-center justify-center gap-2 rounded-sm border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    <MessageSquare className="h-4 w-4" />
                    Message merchant
                </Link>
                {awaitingHub ? (
                    <div className="flex items-center gap-2 rounded-sm border border-indigo-300 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-900">
                        <Clock3 className="h-4 w-4" />
                        Awaiting Origin Hub intake scan
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={loading}
                        className="rounded-sm bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#C50B39] disabled:opacity-50"
                    >
                        {loading ? 'Saving...' : 'Confirm merchant pickup'}
                    </button>
                )}
            </div>
        </article>
    );
}

function FinalMileCard({
    task,
    codLabel,
    loading,
    onStart,
    onComplete,
}: {
    task: FinalMileTask;
    codLabel: string;
    loading: boolean;
    onStart: () => void;
    onComplete: () => void;
}) {
    const outForDelivery = task.nextAction === 'complete_delivery';

    return (
        <article className="rounded-lg border border-slate-300 p-4">
            <TaskHeader task={task} badge={outForDelivery ? 'OUT FOR DELIVERY' : 'ASSIGNED'} />
            <div className="mt-4 grid gap-3 lg:grid-cols-3">
                <DetailBlock icon={MapPin} label="Buyer delivery" title={task.recipient.name}>
                    <span className="block">{task.recipient.address}</span>
                    <span className="mt-1 flex items-center gap-1.5">
                        <Phone className="h-3.5 w-3.5" />
                        {task.recipient.phone}
                    </span>
                </DetailBlock>
                <DetailBlock
                    icon={Building2}
                    label="Dispatch facility"
                    title={task.destinationHub.name ?? 'Destination Bayan Hub'}
                >
                    {task.destinationHub.code ?? 'Facility code unavailable'}
                </DetailBlock>
                <DetailBlock icon={ClipboardCheck} label="Payment" title={task.payment.method}>
                    {task.payment.method === 'COD'
                        ? `Exact amount due: ${codLabel}`
                        : 'No cash collection required'}
                </DetailBlock>
            </div>
            <div className="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <Link
                    href={route('courier.messages', { delivery: task.id })}
                    className="inline-flex items-center justify-center gap-2 rounded-sm border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                >
                    <MessageSquare className="h-4 w-4" />
                    Message buyer
                </Link>
                <button
                    type="button"
                    onClick={outForDelivery ? onComplete : onStart}
                    disabled={loading}
                    className="rounded-sm bg-[#E00D42] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#C50B39] disabled:opacity-50"
                >
                    {loading
                        ? 'Saving...'
                        : outForDelivery
                          ? 'Record successful delivery'
                          : 'Start final-mile delivery'}
                </button>
            </div>
        </article>
    );
}

function TaskHeader({
    task,
    badge,
}: {
    task: Pick<PickupTask, 'trackingNumber' | 'orderNumber'>;
    badge: string;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p className="text-sm font-bold text-slate-950">{task.trackingNumber}</p>
                <p className="mt-1 text-xs text-slate-500">Order {task.orderNumber}</p>
            </div>
            <span className="w-fit rounded-sm border border-slate-300 bg-slate-50 px-2 py-1 text-[11px] font-bold text-slate-700">
                {badge}
            </span>
        </div>
    );
}

function DetailBlock({
    icon: Icon,
    label,
    title,
    children,
}: {
    icon: React.ElementType;
    label: string;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-md border border-slate-300 bg-slate-50 p-3">
            <div className="flex items-center gap-2 text-xs font-medium text-slate-500">
                <Icon className="h-4 w-4" />
                {label}
            </div>
            <p className="mt-2 text-sm font-semibold text-slate-900">{title}</p>
            <div className="mt-1 text-xs leading-5 text-slate-600">{children}</div>
        </div>
    );
}

function InstructionBox({ text }: { text: string }) {
    return (
        <div className="rounded-md border border-blue-300 bg-blue-50 p-3 text-sm leading-6 text-blue-950">
            {text}
        </div>
    );
}
