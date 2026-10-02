import React, { useMemo, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    Camera,
    CheckCircle2,
    ChevronRight,
    Clock3,
    History,
    MapPin,
    MessageSquare,
    Package,
    PackageCheck,
    Phone,
    Power,
    ShieldAlert,
    Store,
    Truck,
    X,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';

interface Scope {
    company?: string | null;
    hub?: string | null;
    hubCode?: string | null;
    hub_code?: string | null;
    barangay?: string | null;
    isAssigned?: boolean;
    isOperational?: boolean;
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
    scope?: Scope;
    isOnline?: boolean;
    stats?: {
        availablePickups?: number;
        activePickups?: number;
        activePickupLimit?: number;
        finalMileTasks?: number;
        completedToday?: number;
    };
    queues?: {
        availablePickups?: PickupTask[];
        pickupTasks?: PickupTask[];
        finalMileTasks?: FinalMileTask[];
        recentActivity?: Activity[];
    };
}

type Tab = 'pickup' | 'available' | 'final_mile' | 'activity';
type ActionTarget = {
    deliveryId: number;
    trackingNumber: string;
    status: 'picked_up' | 'out_for_delivery' | 'delivered';
} | null;

export default function CourierDeliveries({ scope, isOnline = false, stats, queues }: Props) {
    const availableJobs = queues?.availablePickups ?? [];
    const pickupTasks = queues?.pickupTasks ?? [];
    const finalMileTasks = queues?.finalMileTasks ?? [];
    const recentActivity = queues?.recentActivity ?? [];

    const availableCount = stats?.availablePickups ?? 0;
    const activePickupCount = stats?.activePickups ?? 0;
    const activePickupLimit = stats?.activePickupLimit ?? 5;
    const finalMileCount = stats?.finalMileTasks ?? 0;
    const completedTodayCount = stats?.completedToday ?? 0;

    const hubName = scope?.hub ?? 'Bayan Hub';
    const hubCode = scope?.hubCode ?? scope?.hub_code ?? 'BH-LBN';
    const companyName = scope?.company ?? 'Logistics';
    const isAssigned = scope?.isAssigned ?? false;
    const isOperational = scope?.isOperational ?? true;

    const initialTab = useMemo<Tab>(() => {
        if (finalMileTasks.length > 0) return 'final_mile';
        if (pickupTasks.length > 0) return 'pickup';
        return 'available';
    }, [finalMileTasks.length, pickupTasks.length]);

    const [activeTab, setActiveTab] = useState<Tab>(initialTab);
    const [actionTarget, setActionTarget] = useState<ActionTarget>(null);
    const [notes, setNotes] = useState('');
    const [proofFile, setProofFile] = useState<File | null>(null);
    const [proofPreview, setProofPreview] = useState<string | null>(null);
    const [loadingId, setLoadingId] = useState<number | null>(null);

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
            title="Dispatch Dashboard"
            subtitle={
                isAssigned
                    ? `${hubName} · ${companyName}`
                    : 'Waiting for a logistics company and hub assignment'
            }
            isOnline={isOnline}
            scope={scope}
        >
            <Head title="Dispatch Dashboard — BagooPH" />

            <div className="space-y-6 font-sans">
                {/* Status Advisories */}
                {!isAssigned && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50/90 p-4 text-xs font-medium text-amber-950 flex items-start gap-3 shadow-2xs">
                        <ShieldAlert className="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                        <div>
                            <p className="font-bold text-amber-900">Working Hub Not Assigned</p>
                            <p className="mt-0.5 text-amber-800">
                                Your courier account is approved, but has not yet been linked to an active logistics facility.
                                A logistics administrator must assign your station before available pickup orders appear.
                            </p>
                        </div>
                    </div>
                )}
                {isAssigned && !isOperational && (
                    <div className="rounded-xl border border-rose-300 bg-rose-50/90 p-4 text-xs font-medium text-rose-950 flex items-start gap-3 shadow-2xs">
                        <ShieldAlert className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
                        <div>
                            <p className="font-bold text-rose-900">Facility Operations Paused</p>
                            <p className="mt-0.5 text-rose-800">
                                Your assigned logistics company or working hub is currently marked inactive. New pickup dispatches
                                are suspended, but any parcels already in your custody remain active and must be processed.
                            </p>
                        </div>
                    </div>
                )}

                {/* 1. TOP BENTO KPI CARDS (MATCHING SELLER CENTRE BENTO) */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    {/* 1: Available Pickups */}
                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between hover:border-slate-400 transition">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Available pickups</span>
                                <Package className="w-4 h-4 text-[#E00D42]" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {availableCount}{' '}
                                    <span className="text-sm font-bold text-slate-500">jobs</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Ready at Bayan Hub</span>
                            <span className="font-bold text-slate-900">
                                {hubCode}
                            </span>
                        </div>
                    </div>

                    {/* 2: My Pickup Tasks */}
                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between hover:border-slate-400 transition">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">My pickup tasks</span>
                                <Store className="w-4 h-4 text-indigo-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {activePickupCount}{' '}
                                    <span className="text-sm font-bold text-slate-500">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Batch capacity</span>
                            <span className="font-bold text-slate-900">
                                {activePickupCount} of {activePickupLimit} limit
                            </span>
                        </div>
                    </div>

                    {/* 3: Final-Mile Tasks */}
                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between hover:border-slate-400 transition">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Final-mile tasks</span>
                                <Truck className="w-4 h-4 text-amber-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {finalMileCount}{' '}
                                    <span className="text-sm font-bold text-slate-500">deliveries</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Buyer destination</span>
                            <span className="font-bold text-slate-900">Direct delivery</span>
                        </div>
                    </div>

                    {/* 4: Delivered Today */}
                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between hover:border-slate-400 transition">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Delivered today</span>
                                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {completedTodayCount}{' '}
                                    <span className="text-sm font-bold text-slate-500">handoffs</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Verified trips</span>
                            <span className="font-bold text-emerald-600">100% completed</span>
                        </div>
                    </div>
                </div>

                {/* 2. MIDDLE BENTO ROW: 8-COL EXECUTION QUEUE + 4-COL CUSTODY PIPELINE */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    {/* LEFT (8 COLS): ACTIVE ROUTE & EXECUTION QUEUE */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between space-y-4">
                        {/* Section Header with Segmented Tabs */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-slate-100">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42]" />
                                    <h3 className="text-sm font-bold text-slate-900 font-sans">
                                        Route & Execution Queue
                                    </h3>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">
                                    {hubName} · Real-time pickup collection and final-mile buyer delivery tasks
                                </p>
                            </div>

                            {/* Queue Segmented Switcher */}
                            <div className="flex flex-wrap gap-1 bg-slate-100/90 p-1 rounded-lg border border-slate-200/80 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setActiveTab('pickup')}
                                    className={`px-3 py-1.5 rounded-md text-xs font-bold transition cursor-pointer flex items-center gap-1.5 ${
                                        activeTab === 'pickup'
                                            ? 'bg-white text-slate-900 shadow-2xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Store className="w-3.5 h-3.5 text-indigo-600" />
                                    <span>My Pickups</span>
                                    <span
                                        className={`ml-1 px-1.5 py-0.2 rounded text-[10px] font-bold ${
                                            activeTab === 'pickup'
                                                ? 'bg-indigo-50 text-indigo-700'
                                                : 'bg-slate-200 text-slate-700'
                                        }`}
                                    >
                                        {activePickupCount}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setActiveTab('final_mile')}
                                    className={`px-3 py-1.5 rounded-md text-xs font-bold transition cursor-pointer flex items-center gap-1.5 ${
                                        activeTab === 'final_mile'
                                            ? 'bg-white text-slate-900 shadow-2xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Truck className="w-3.5 h-3.5 text-amber-600" />
                                    <span>Final-Mile</span>
                                    <span
                                        className={`ml-1 px-1.5 py-0.2 rounded text-[10px] font-bold ${
                                            activeTab === 'final_mile'
                                                ? 'bg-amber-50 text-amber-800'
                                                : 'bg-slate-200 text-slate-700'
                                        }`}
                                    >
                                        {finalMileCount}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setActiveTab('available')}
                                    className={`px-3 py-1.5 rounded-md text-xs font-bold transition cursor-pointer flex items-center gap-1.5 ${
                                        activeTab === 'available'
                                            ? 'bg-white text-slate-900 shadow-2xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <Package className="w-3.5 h-3.5 text-[#E00D42]" />
                                    <span>Available</span>
                                    <span
                                        className={`ml-1 px-1.5 py-0.2 rounded text-[10px] font-bold ${
                                            activeTab === 'available'
                                                ? 'bg-rose-50 text-[#C20836]'
                                                : 'bg-slate-200 text-slate-700'
                                        }`}
                                    >
                                        {availableCount}
                                    </span>
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setActiveTab('activity')}
                                    className={`px-3 py-1.5 rounded-md text-xs font-bold transition cursor-pointer flex items-center gap-1.5 ${
                                        activeTab === 'activity'
                                            ? 'bg-white text-slate-900 shadow-2xs'
                                            : 'text-slate-600 hover:text-slate-900'
                                    }`}
                                >
                                    <History className="w-3.5 h-3.5 text-emerald-600" />
                                    <span>Trips</span>
                                    <span
                                        className={`ml-1 px-1.5 py-0.2 rounded text-[10px] font-bold ${
                                            activeTab === 'activity'
                                                ? 'bg-emerald-50 text-emerald-800'
                                                : 'bg-slate-200 text-slate-700'
                                        }`}
                                    >
                                        {recentActivity.length}
                                    </span>
                                </button>
                            </div>
                        </div>

                        {/* Duty Guidance Banner */}
                        {!isOnline && activeTab === 'available' && availableJobs.length > 0 && (
                            <div className="rounded-xl border border-amber-300 bg-amber-50/80 p-3.5 text-xs text-amber-950 flex items-center justify-between gap-3">
                                <div className="flex items-center gap-2.5 min-w-0">
                                    <Power className="w-4 h-4 text-amber-600 shrink-0" />
                                    <span className="truncate">
                                        You are currently <strong>Off Duty</strong>. Go on duty to claim new pickup jobs.
                                    </span>
                                </div>
                                <span className="text-[10px] font-bold uppercase tracking-wider text-amber-800 shrink-0">
                                    Toggle in Topbar
                                </span>
                            </div>
                        )}

                        {/* Content Area for Active Tab */}
                        <div className="space-y-3 min-h-[300px]">
                            {/* TAB 1: MY PICKUPS */}
                            {activeTab === 'pickup' && (
                                <>
                                    {pickupTasks.length === 0 ? (
                                        <EmptyState
                                            icon={Store}
                                            title="No Active Pickups In Progress"
                                            description="You have no claimed merchant pickups right now. Browse available jobs to start your batch route."
                                            action={
                                                availableCount > 0 ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => setActiveTab('available')}
                                                        className="mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#E00D42] text-white text-xs font-bold rounded-lg shadow-xs hover:bg-[#C20836] transition cursor-pointer"
                                                    >
                                                        <span>View Available Jobs ({availableCount})</span>
                                                        <ArrowRight className="w-3.5 h-3.5" />
                                                    </button>
                                                ) : undefined
                                            }
                                        />
                                    ) : (
                                        pickupTasks.map((task) => (
                                            <PickupRouteCard
                                                key={task.id}
                                                task={task}
                                                loading={loadingId === task.id}
                                                onConfirm={() =>
                                                    openAction(task.id, task.trackingNumber, 'picked_up')
                                                }
                                            />
                                        ))
                                    )}
                                </>
                            )}

                            {/* TAB 2: FINAL-MILE TASKS */}
                            {activeTab === 'final_mile' && (
                                <>
                                    {finalMileTasks.length === 0 ? (
                                        <EmptyState
                                            icon={Truck}
                                            title="No Final-Mile Dispatches"
                                            description="No buyer deliveries are assigned to you at this moment. Assignments are issued by your destination hub dispatcher."
                                        />
                                    ) : (
                                        finalMileTasks.map((task) => (
                                            <FinalMileRouteCard
                                                key={task.id}
                                                task={task}
                                                codLabel={formatCurrency(task.payment?.codAmount ?? null)}
                                                loading={loadingId === task.id}
                                                onStart={() =>
                                                    openAction(task.id, task.trackingNumber, 'out_for_delivery')
                                                }
                                                onComplete={() =>
                                                    openAction(task.id, task.trackingNumber, 'delivered')
                                                }
                                            />
                                        ))
                                    )}
                                </>
                            )}

                            {/* TAB 3: AVAILABLE PICKUPS */}
                            {activeTab === 'available' && (
                                <>
                                    {availableJobs.length === 0 ? (
                                        <EmptyState
                                            icon={PackageCheck}
                                            title="No Available Pickup Jobs"
                                            description="All ready orders at your assigned Bayan Hub are currently claimed or awaiting merchant packing."
                                        />
                                    ) : (
                                        availableJobs.map((task) => (
                                            <AvailablePickupRouteCard
                                                key={task.id}
                                                task={task}
                                                disabled={!isOnline || loadingId !== null || activePickupCount >= activePickupLimit}
                                                loading={loadingId === task.id}
                                                onClaim={() => claimPickup(task.id)}
                                            />
                                        ))
                                    )}
                                </>
                            )}

                            {/* TAB 4: RECENT ACTIVITY */}
                            {activeTab === 'activity' && (
                                <>
                                    {recentActivity.length === 0 ? (
                                        <EmptyState
                                            icon={History}
                                            title="No Delivery Trips Recorded"
                                            description="Your completed buyer handoffs for today will appear here once verified."
                                        />
                                    ) : (
                                        <div className="grid gap-2.5">
                                            {recentActivity.map((activity) => (
                                                <div
                                                    key={activity.id}
                                                    className="p-3.5 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-white hover:border-slate-300 transition shadow-2xs flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-sans"
                                                >
                                                    <div className="min-w-0">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-bold text-xs text-slate-900">
                                                                {activity.trackingNumber}
                                                            </span>
                                                            <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 uppercase">
                                                                Delivered
                                                            </span>
                                                        </div>
                                                        <p className="text-[11px] text-slate-500 mt-0.5 truncate">
                                                            Recipient: <span className="font-semibold text-slate-800">{activity.recipientName}</span> • {activity.deliveryAddress}
                                                        </p>
                                                    </div>

                                                    <div className="sm:text-right shrink-0 text-xs">
                                                        <p className="font-bold text-slate-800">
                                                            {formatDate(activity.deliveredAt)}
                                                        </p>
                                                        <p className="text-[10px] text-slate-400 mt-0.5">
                                                            Payment: {activity.paymentMethod}
                                                        </p>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* RIGHT (4 COLS): CUSTODY & DISPATCH PIPELINE (MATCHING SELLER'S FULFILLMENT ACTIONS) */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between space-y-4">
                        {/* Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 shrink-0">
                            <div className="flex items-center gap-2">
                                <Truck className="w-4 h-4 text-[#E00D42]" />
                                <h3 className="text-xs font-black text-slate-900 font-sans uppercase tracking-wider">
                                    Custody Pipeline
                                </h3>
                            </div>
                            <span className="text-[11px] font-bold font-sans text-slate-500 uppercase">
                                Live Status
                            </span>
                        </div>

                        {/* Interactive Pipeline Action Cards */}
                        <div className="space-y-2.5 font-sans">
                            {/* 1. TO PICK UP */}
                            <button
                                type="button"
                                onClick={() => setActiveTab('pickup')}
                                className={`w-full p-2.5 sm:p-3 rounded-xl transition flex items-center justify-between gap-3 text-left cursor-pointer group ${
                                    activePickupCount > 0
                                        ? 'bg-amber-50/70 border border-amber-300 shadow-2xs hover:bg-amber-100/60'
                                        : 'bg-slate-50 border border-slate-200/90 hover:border-amber-400'
                                }`}
                            >
                                <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                    <div
                                        className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                            activePickupCount > 0
                                                ? 'bg-amber-500 text-white shadow-xs'
                                                : 'bg-amber-100 text-amber-700'
                                        }`}
                                    >
                                        <Store className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-xs font-bold text-slate-900 uppercase font-sans tracking-tight group-hover:text-amber-900 block leading-tight">
                                            To Pick Up
                                        </span>
                                        <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                            Merchant store collection
                                        </p>
                                    </div>
                                </div>
                                <span
                                    className={`px-2.5 py-1 min-w-[28px] text-center rounded-lg font-sans text-sm font-black shrink-0 ${
                                        activePickupCount > 0
                                            ? 'bg-amber-500 text-white shadow-xs'
                                            : 'bg-slate-200 text-slate-700'
                                    }`}
                                >
                                    {activePickupCount}
                                </span>
                            </button>

                            {/* 2. FINAL-MILE DELIVERY */}
                            <button
                                type="button"
                                onClick={() => setActiveTab('final_mile')}
                                className={`w-full p-2.5 sm:p-3 rounded-xl transition flex items-center justify-between gap-3 text-left cursor-pointer group ${
                                    finalMileCount > 0
                                        ? 'bg-emerald-50/70 border border-emerald-300 shadow-2xs hover:bg-emerald-100/60'
                                        : 'bg-slate-50 border border-slate-200/90 hover:border-emerald-400'
                                }`}
                            >
                                <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                    <div
                                        className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                            finalMileCount > 0
                                                ? 'bg-emerald-600 text-white shadow-xs'
                                                : 'bg-emerald-100 text-emerald-800'
                                        }`}
                                    >
                                        <Truck className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-xs font-bold text-slate-900 uppercase font-sans tracking-tight group-hover:text-emerald-900 block leading-tight">
                                            Final-Mile
                                        </span>
                                        <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                            Buyer destination handoff
                                        </p>
                                    </div>
                                </div>
                                <span
                                    className={`px-2.5 py-1 min-w-[28px] text-center rounded-lg font-sans text-sm font-black shrink-0 ${
                                        finalMileCount > 0
                                            ? 'bg-emerald-600 text-white shadow-xs'
                                            : 'bg-slate-200 text-slate-700'
                                    }`}
                                >
                                    {finalMileCount}
                                </span>
                            </button>

                            {/* 3. AVAILABLE PICKUPS POOL */}
                            <button
                                type="button"
                                onClick={() => setActiveTab('available')}
                                className={`w-full p-2.5 sm:p-3 rounded-xl transition flex items-center justify-between gap-3 text-left cursor-pointer group ${
                                    availableCount > 0
                                        ? 'bg-rose-50/70 border border-rose-300 shadow-2xs hover:bg-rose-100/60'
                                        : 'bg-slate-50 border border-slate-200/90 hover:border-rose-400'
                                }`}
                            >
                                <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                    <div
                                        className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                            availableCount > 0
                                                ? 'bg-[#E00D42] text-white shadow-xs'
                                                : 'bg-rose-100 text-rose-700'
                                        }`}
                                    >
                                        <Package className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-xs font-bold text-slate-900 uppercase font-sans tracking-tight group-hover:text-rose-900 block leading-tight">
                                            Available Jobs
                                        </span>
                                        <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                            Ready at {hubCode}
                                        </p>
                                    </div>
                                </div>
                                <span
                                    className={`px-2.5 py-1 min-w-[28px] text-center rounded-lg font-sans text-sm font-black shrink-0 ${
                                        availableCount > 0
                                            ? 'bg-[#E00D42] text-white shadow-xs'
                                            : 'bg-slate-200 text-slate-700'
                                    }`}
                                >
                                    {availableCount}
                                </span>
                            </button>

                            {/* LOGISTICS PIPELINE SUMMARY BOX */}
                            <div className="p-2.5 sm:p-3 rounded-xl bg-slate-50 border border-slate-200/80 font-sans shrink-0">
                                <div className="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-200/60 text-[9px] uppercase font-bold text-slate-400 tracking-wider">
                                    <span>Route Custody</span>
                                    <span className="text-slate-500">Live Telemetry</span>
                                </div>
                                <div className="grid grid-cols-3 gap-1 divide-x divide-slate-200/80 text-center">
                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('available')}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block cursor-pointer"
                                        title="Available pickup jobs"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            Available
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {availableCount}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            ready
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('pickup')}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block cursor-pointer"
                                        title="Active route tasks in custody"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            In Custody
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {activePickupCount + finalMileCount}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            on route
                                        </span>
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => setActiveTab('activity')}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block cursor-pointer"
                                        title="Delivered parcels today"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            Delivered
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {completedTodayCount}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            verified
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* Operational Rules Card */}
                        <div className="pt-3 border-t border-slate-100 text-[11px] text-slate-500 space-y-1.5 font-sans">
                            <div className="flex items-center gap-1.5 font-bold text-slate-700">
                                <Building2 className="w-3.5 h-3.5 text-slate-400" />
                                <span>Custody Protocol</span>
                            </div>
                            <p className="leading-relaxed">
                                Pickups must be handed off only to the assigned <strong>{hubCode}</strong>.
                                Custody transfers when facility operators scan the parcel inbound.
                            </p>
                        </div>
                    </div>
                </div>

                {/* 3. BOTTOM ROW: AVAILABLE PICKUP OPPORTUNITIES (FAST DISPATCH QUEUE) */}
                {activeTab !== 'available' && availableJobs.length > 0 && (
                    <div className="bg-white rounded-2xl border border-slate-200/90 p-5 shadow-2xs font-sans">
                        <div className="flex items-center justify-between pb-3.5 border-b border-slate-100">
                            <div>
                                <div className="flex items-center gap-2">
                                    <Package className="w-4 h-4 text-[#E00D42]" />
                                    <h3 className="font-bold text-sm text-slate-900">
                                        Available Pickup Opportunities ({availableJobs.length})
                                    </h3>
                                </div>
                                <p className="text-xs text-slate-400 mt-0.5">
                                    Ready parcels at {hubName} eligible for batch claim
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setActiveTab('available')}
                                className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-sans uppercase cursor-pointer"
                            >
                                <span>Manage All</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </button>
                        </div>

                        <div className="mt-3 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                            {availableJobs.slice(0, 3).map((task) => (
                                <div
                                    key={task.id}
                                    className="p-3.5 rounded-xl border border-slate-200 bg-slate-50/50 hover:bg-white hover:border-slate-300 transition shadow-2xs flex flex-col justify-between"
                                >
                                    <div>
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-xs text-slate-900">
                                                {task.trackingNumber}
                                            </span>
                                            <span className="px-1.5 py-0.5 rounded text-[9px] font-bold bg-rose-50 text-[#C20836] border border-rose-200 uppercase">
                                                Ready
                                            </span>
                                        </div>
                                        <p className="text-xs font-bold text-slate-800 mt-1.5">
                                            {task.merchant?.name ?? 'Merchant Store'}
                                        </p>
                                        <p className="text-[11px] text-slate-500 line-clamp-1 mt-0.5">
                                            {task.merchant?.address}
                                        </p>
                                    </div>

                                    <div className="mt-3 pt-3 border-t border-slate-200/60 flex items-center justify-between">
                                        <span className="text-[11px] text-slate-500 font-semibold">
                                            {task.itemCount} item(s)
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => claimPickup(task.id)}
                                            disabled={!isOnline || loadingId !== null || activePickupCount >= activePickupLimit}
                                            className="px-3 py-1 bg-[#E00D42] text-white hover:bg-[#C20836] text-[11px] font-bold rounded-md shadow-2xs transition disabled:opacity-50 cursor-pointer"
                                        >
                                            {loadingId === task.id ? 'Claiming...' : 'Claim Job'}
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* ACTION MODAL (FOR PICKUP CONFIRMATION, FINAL-MILE START, AND DELIVERY PHOTO PROOF) */}
            {actionTarget && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/60 backdrop-blur-xs p-4 font-sans">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-300 bg-white shadow-2xl overflow-hidden animate-in fade-in zoom-in-95 duration-100">
                        <div className="flex items-start justify-between border-b border-slate-100 p-5 bg-slate-50/50">
                            <div>
                                <h2 className="text-base font-black text-slate-900 tracking-tight">
                                    {actionTarget.status === 'picked_up' && 'Confirm Merchant Pickup'}
                                    {actionTarget.status === 'out_for_delivery' && 'Start Final-Mile Delivery'}
                                    {actionTarget.status === 'delivered' && 'Record Successful Delivery'}
                                </h2>
                                <p className="mt-0.5 text-xs text-slate-500">
                                    Waybill tracking: <span className="font-bold text-slate-800">{actionTarget.trackingNumber}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close"
                                onClick={closeAction}
                                className="rounded-lg p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition cursor-pointer"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={submitAction} className="space-y-4 p-5">
                            {actionTarget.status === 'picked_up' && (
                                <InstructionBox text="Match the waybill barcode at the merchant premises before confirming pickup. Custody remains yours until the Origin Bayan Hub scans the parcel." />
                            )}
                            {actionTarget.status === 'out_for_delivery' && (
                                <InstructionBox text="Confirm you have received physical possession of this parcel from the Destination Bayan Hub staging area before departing for the buyer address." />
                            )}
                            {actionTarget.status === 'delivered' && (
                                <>
                                    <InstructionBox text="Successful handoff requires clear photographic proof of delivery at the recipient location." />
                                    <div>
                                        <label
                                            htmlFor="proof-image"
                                            className="mb-1.5 block text-xs font-bold text-slate-800 uppercase tracking-wider"
                                        >
                                            Handoff Proof Photo <span className="text-[#E00D42]">*</span>
                                        </label>
                                        <label
                                            htmlFor="proof-image"
                                            className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-slate-50/80 px-4 py-4 text-xs font-bold text-slate-700 hover:bg-slate-100 transition hover:border-slate-400"
                                        >
                                            <Camera className="w-4 h-4 text-[#E00D42]" />
                                            <span>{proofFile ? proofFile.name : 'Choose or capture handoff photo'}</span>
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
                                            <div className="mt-3 aspect-video overflow-hidden rounded-xl border border-slate-200 bg-slate-100 shadow-2xs">
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
                                    className="mb-1.5 block text-xs font-bold text-slate-800 uppercase tracking-wider"
                                >
                                    {actionTarget.status === 'picked_up'
                                        ? 'Pickup Note for Merchant'
                                        : 'Operational Delivery Notes'}{' '}
                                    <span className="font-normal text-slate-400 lowercase">(optional)</span>
                                </label>
                                <textarea
                                    id="courier-notes"
                                    rows={3}
                                    maxLength={500}
                                    value={notes}
                                    onChange={(event) => setNotes(event.target.value)}
                                    placeholder={
                                        actionTarget.status === 'picked_up'
                                            ? 'e.g. Received 2 bags from merchant counter, in good condition.'
                                            : 'e.g. Received by buyer at gate, verified signature.'
                                    }
                                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-xs focus:border-[#E00D42] focus:ring-[#E00D42] shadow-2xs font-sans"
                                />
                            </div>

                            <div className="flex flex-col-reverse sm:flex-row sm:items-center sm:justify-end gap-2 pt-3 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={closeAction}
                                    className="px-4 py-2 rounded-lg text-xs font-bold text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 transition cursor-pointer uppercase tracking-wider"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={
                                        loadingId !== null ||
                                        (actionTarget.status === 'delivered' && !proofFile)
                                    }
                                    className="px-4 py-2 rounded-lg text-xs font-bold text-white bg-[#E00D42] hover:bg-[#C20836] transition shadow-xs disabled:opacity-50 disabled:cursor-not-allowed uppercase tracking-wider cursor-pointer"
                                >
                                    {loadingId !== null ? 'Saving...' : 'Confirm Action'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CourierLayout>
    );
}

{/* SUBCOMPONENTS */}

function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: React.ElementType;
    title: string;
    description: string;
    action?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center p-8 rounded-xl border border-dashed border-slate-300 bg-slate-50/50 text-center font-sans">
            <div className="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-slate-400 mb-3 shadow-2xs">
                <Icon className="w-6 h-6" />
            </div>
            <h4 className="text-sm font-bold text-slate-800">{title}</h4>
            <p className="mt-1 text-xs text-slate-500 max-w-md">{description}</p>
            {action}
        </div>
    );
}

function PickupRouteCard({
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
        <article className="p-4 sm:p-5 rounded-xl border border-slate-200/90 bg-white hover:border-slate-300 transition shadow-2xs space-y-4 font-sans">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                <div className="flex items-center gap-2">
                    <span className="font-black text-sm text-slate-900 tracking-tight">
                        {task.trackingNumber}
                    </span>
                    {task.orderNumber && (
                        <span className="text-xs text-slate-500 font-medium">
                            · Order {task.orderNumber}
                        </span>
                    )}
                </div>
                <span
                    className={`w-fit px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${
                        awaitingHub
                            ? 'bg-amber-50 text-amber-800 border border-amber-200'
                            : 'bg-indigo-50 text-indigo-700 border border-indigo-200'
                    }`}
                >
                    {awaitingHub ? 'In Custody' : 'Assigned Pickup'}
                </span>
            </div>

            {/* Grid Route Details */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <Store className="w-3.5 h-3.5 text-indigo-600" />
                        <span>Merchant Store</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">
                        {task.merchant?.name ?? 'Merchant Store'}
                    </p>
                    <p className="text-xs text-slate-600 leading-relaxed">
                        {task.merchant?.address}
                    </p>
                    {task.merchant?.phone && (
                        <a
                            href={`tel:${task.merchant.phone}`}
                            className="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-600 hover:underline mt-0.5"
                        >
                            <Phone className="w-3 h-3" />
                            <span>{task.merchant.phone}</span>
                        </a>
                    )}
                </div>

                <div className="space-y-1">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <Building2 className="w-3.5 h-3.5 text-slate-500" />
                        <span>Origin Bayan Hub Handoff</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">
                        {task.originHub?.name ?? 'Origin Bayan Hub'}
                    </p>
                    <p className="text-xs text-slate-500">
                        Facility Station: <span className="font-semibold text-slate-700">{task.originHub?.code ?? 'BH-01'}</span>
                    </p>
                    <p className="text-[11px] text-slate-400 mt-1">
                        Parcel items: {task.itemCount} unit(s)
                    </p>
                </div>
            </div>

            {/* Action Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-slate-100">
                <Link
                    href={route('courier.messages', { delivery: task.id })}
                    className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition"
                >
                    <MessageSquare className="w-3.5 h-3.5 text-slate-400" />
                    <span>Message Merchant</span>
                </Link>

                {awaitingHub ? (
                    <div className="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-amber-50 text-amber-800 border border-amber-200 text-xs font-bold">
                        <Clock3 className="w-3.5 h-3.5" />
                        <span>Awaiting Origin Hub Intake Scan</span>
                    </div>
                ) : (
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={loading}
                        className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold rounded-lg shadow-xs transition uppercase tracking-wider cursor-pointer"
                    >
                        {loading ? 'Saving...' : 'Confirm Merchant Pickup'}
                    </button>
                )}
            </div>
        </article>
    );
}

function FinalMileRouteCard({
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
        <article className="p-4 sm:p-5 rounded-xl border border-slate-200/90 bg-white hover:border-slate-300 transition shadow-2xs space-y-4 font-sans">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                <div className="flex items-center gap-2">
                    <span className="font-black text-sm text-slate-900 tracking-tight">
                        {task.trackingNumber}
                    </span>
                    {task.orderNumber && (
                        <span className="text-xs text-slate-500 font-medium">
                            · Order {task.orderNumber}
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-2">
                    <span
                        className={`px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${
                            task.payment?.method === 'COD'
                                ? 'bg-amber-50 text-amber-800 border border-amber-200'
                                : 'bg-slate-100 text-slate-700 border border-slate-200'
                        }`}
                    >
                        {task.payment?.method ?? 'ONLINE'}
                    </span>
                    <span
                        className={`w-fit px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider ${
                            outForDelivery
                                ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                                : 'bg-blue-50 text-blue-700 border border-blue-200'
                        }`}
                    >
                        {outForDelivery ? 'Out for Delivery' : 'Assigned to Rider'}
                    </span>
                </div>
            </div>

            {/* Grid Route Details */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="space-y-1 sm:col-span-2">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <MapPin className="w-3.5 h-3.5 text-emerald-600" />
                        <span>Buyer Recipient</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">{task.recipient?.name}</p>
                    <p className="text-xs text-slate-600 leading-relaxed">
                        {task.recipient?.address}
                    </p>
                    {task.recipient?.phone && (
                        <a
                            href={`tel:${task.recipient.phone}`}
                            className="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 hover:underline mt-0.5"
                        >
                            <Phone className="w-3 h-3" />
                            <span>{task.recipient.phone}</span>
                        </a>
                    )}
                </div>

                <div className="space-y-1">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <Building2 className="w-3.5 h-3.5 text-slate-500" />
                        <span>Destination Hub</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">
                        {task.destinationHub?.name ?? 'Destination Bayan Hub'}
                    </p>
                    <p className="text-xs text-slate-500">
                        Code: {task.destinationHub?.code ?? 'BH-02'}
                    </p>
                    {task.payment?.method === 'COD' && (
                        <div className="mt-2 p-2 rounded-lg bg-amber-50 border border-amber-200">
                            <span className="text-[10px] font-bold text-amber-800 uppercase block">
                                Collect Exact COD
                            </span>
                            <span className="text-xs font-black text-amber-900 block">
                                {codLabel}
                            </span>
                        </div>
                    )}
                </div>
            </div>

            {/* Action Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-slate-100">
                <Link
                    href={route('courier.messages', { delivery: task.id })}
                    className="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 text-xs font-bold text-slate-700 hover:bg-slate-50 hover:border-slate-300 transition"
                >
                    <MessageSquare className="w-3.5 h-3.5 text-slate-400" />
                    <span>Message Buyer</span>
                </Link>

                <button
                    type="button"
                    onClick={outForDelivery ? onComplete : onStart}
                    disabled={loading}
                    className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold rounded-lg shadow-xs transition uppercase tracking-wider cursor-pointer"
                >
                    {loading
                        ? 'Saving...'
                        : outForDelivery
                          ? 'Record Successful Delivery'
                          : 'Start Final-Mile Delivery'}
                </button>
            </div>
        </article>
    );
}

function AvailablePickupRouteCard({
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
        <article className="p-4 sm:p-5 rounded-xl border border-slate-200/90 bg-white hover:border-slate-300 transition shadow-2xs space-y-4 font-sans">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                <div className="flex items-center gap-2">
                    <span className="font-black text-sm text-slate-900 tracking-tight">
                        {task.trackingNumber}
                    </span>
                    {task.orderNumber && (
                        <span className="text-xs text-slate-500 font-medium">
                            · Order {task.orderNumber}
                        </span>
                    )}
                </div>
                <span className="w-fit px-2 py-0.5 rounded text-[10px] font-black bg-rose-50 text-[#C20836] border border-rose-200 uppercase tracking-wider">
                    Ready for Pickup
                </span>
            </div>

            {/* Grid Route Details */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                        <span>Merchant Location</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">
                        {task.merchant?.name ?? 'Merchant Store'}
                    </p>
                    <p className="text-xs text-slate-600 leading-relaxed">
                        {task.merchant?.address}
                    </p>
                </div>

                <div className="space-y-1">
                    <div className="flex items-center gap-1.5 text-slate-400 text-xs font-semibold">
                        <Building2 className="w-3.5 h-3.5 text-slate-500" />
                        <span>Origin Bayan Hub Target</span>
                    </div>
                    <p className="text-xs font-bold text-slate-900">
                        {task.originHub?.name ?? 'Origin Bayan Hub'}
                    </p>
                    <p className="text-xs text-slate-500">
                        Facility Code: {task.originHub?.code ?? 'BH-01'}
                    </p>
                    <p className="text-[11px] text-slate-400 mt-1">
                        Total items: {task.itemCount} unit(s)
                    </p>
                </div>
            </div>

            {/* Action Bar */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-slate-100">
                <span className="text-xs text-slate-500 font-medium">
                    Batch claim limit applies (up to 5)
                </span>
                <button
                    type="button"
                    onClick={onClaim}
                    disabled={disabled}
                    className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold rounded-lg shadow-xs transition uppercase tracking-wider disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                >
                    {loading ? 'Claiming...' : 'Claim Pickup'}
                </button>
            </div>
        </article>
    );
}

function InstructionBox({ text }: { text: string }) {
    return (
        <div className="rounded-xl border border-blue-200 bg-blue-50/80 p-3.5 text-xs leading-relaxed text-blue-950 font-sans">
            {text}
        </div>
    );
}
