import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import WaybillCamera from '@/Components/WaybillCamera';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubCategoryTabs, HubNextStep, HubPageHeader, hubInput } from '@/Components/Logistics/Workspace';
import { HubParcelState, HubParcelNextStep, ParcelGuidance } from '@/Components/HubParcelGuidance';
import { 
    Store, 
    Search, 
    CheckCircle2, 
    Clock, 
    Package, 
    User, 
    Phone, 
    ShieldCheck, 
    ScanLine,
    ArrowRight,
    Tag,
    AlertCircle,
    X
} from 'lucide-react';

interface CounterParcel {
    id: number;
    tracking_number: string;
    order_number: string;
    buyer_name: string;
    buyer_id: number;
    claim: { reference: string; status: string; expires_at: string; locked_until: string | null } | null;
    buyer_phone: string;
    status: string;
    destination_bin: string | null;
    total_amount: number;
    payment_method: string;
    item_count: number;
    items: Array<{
        name: string;
        quantity: number;
        price: number;
    }>;
    arrived_at: string | null;
    guidance: ParcelGuidance;
}

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
        city_municipality: string;
        operating_hours: string | null;
        allows_self_pickup?: boolean;
    } | null;
    counterParcels: {
        data: CounterParcel[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_page_url: string | null;
        next_page_url: string | null;
        first_page_url: string;
    };
    recentlyCollected: Array<{
        id: number;
        tracking_number: string;
        buyer_name: string;
        collected_at: string;
    }>;
    search: string;
    requestToken: string;
    canConfigureHours: boolean;
    basePath: string;
    canScan: boolean;
    counterCounts: { shown: number; ready: number; blocked: number };
}

export default function HubCounterPickup({ activeHub, counterParcels, recentlyCollected, search, requestToken, canConfigureHours, basePath, canScan, counterCounts }: Props) {
    const pageParcels = counterParcels.data;
    const isCounterHub = activeHub?.tier === 'local_bayan_hub' && activeHub.allows_self_pickup !== false;
    const [searchTerm, setSearchTerm] = useState(search || '');
    const [selectedParcel, setSelectedParcel] = useState<CounterParcel | null>(null);
    const [recipientName, setRecipientName] = useState('');
    const [claimCode, setClaimCode] = useState('');
    const [notes, setNotes] = useState('');
    const [barcode, setBarcode] = useState('');
    const [identityConfirmed, setIdentityConfirmed] = useState(false);
    const [cashConfirmed, setCashConfirmed] = useState(false);
    const [cashReceived, setCashReceived] = useState('');
    const [changeGiven, setChangeGiven] = useState('0');
    const [errors, setErrors] = useState<Record<string,string>>({});
    const pending = useRef(false);
    const [hours, setHours] = useState(activeHub?.operating_hours ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [categoryFilter, setCategoryFilter] = useState<'all' | 'ready' | 'attention'>('all');
    const [currentTime, setCurrentTime] = useState(Date.now);
    useEffect(() => {
        setHours(activeHub?.operating_hours ?? '');
    }, [activeHub?.id, activeHub?.operating_hours]);
    useEffect(() => {
        setSelectedParcel(null);
        setErrors({});
    }, [activeHub?.id]);
    useEffect(() => {
        const now = Date.now();
        const boundaries = pageParcels.flatMap(parcel => [parcel.claim?.expires_at, parcel.claim?.locked_until])
            .filter((value): value is string => Boolean(value)).map(value => new Date(value).getTime()).filter(value => value > now);
        if (!boundaries.length) return;
        const timer = window.setTimeout(() => setCurrentTime(Date.now()), Math.min(Math.min(...boundaries) - now + 1, 2_147_483_647));
        return () => window.clearTimeout(timer);
    }, [pageParcels, currentTime]);
    const hasCurrentClaimWindow = (parcel: CounterParcel) => parcel.claim?.status === 'ready'
        && new Date(parcel.claim.expires_at).getTime() > Math.max(currentTime, Date.now())
        && (!parcel.claim.locked_until || new Date(parcel.claim.locked_until).getTime() <= Math.max(currentTime, Date.now()));
    const canRelease = (parcel: CounterParcel) => parcel.guidance.can_act && parcel.guidance.action === 'counter' && hasCurrentClaimWindow(parcel);
    const readyParcels = pageParcels.filter(parcel => canRelease(parcel));
    const attentionParcels = pageParcels.filter(parcel => !canRelease(parcel));
    const visibleParcels = categoryFilter === 'ready' ? readyParcels : categoryFilter === 'attention' ? attentionParcels : pageParcels;
    const elapsedReady = pageParcels.filter(parcel => parcel.guidance.ready_for_collection && !hasCurrentClaimWindow(parcel)).length;
    const readyNow = Math.max(0, counterCounts.ready - elapsedReady);
    const blockedNow = Math.min(counterCounts.shown, counterCounts.blocked + elapsedReady);
    const currentSelectedParcel = pageParcels.find(parcel => parcel.id === selectedParcel?.id);

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            `${basePath}/counter`,
            { search: searchTerm, hub_id: activeHub?.id },
            { preserveState: true, preserveScroll: true }
        );
    };

    const openReleaseModal = (parcel: CounterParcel) => {
        if (!canRelease(parcel)) return;
        setSelectedParcel(parcel);
        setRecipientName(parcel.buyer_name);
        setClaimCode('');
        setNotes(''); setBarcode(''); setIdentityConfirmed(false); setCashConfirmed(false); setCashReceived(''); setChangeGiven('0'); setErrors({});
    };

    const handleReleaseSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!selectedParcel || !currentSelectedParcel || !canRelease(currentSelectedParcel) || pending.current) return;
        pending.current = true; setErrors({});
        setSubmitting(true);

        router.post(
            `${basePath}/release`,
            {
                barcode,
                claim_code: claimCode,
                buyer_id: selectedParcel.buyer_id, identity_confirmed: identityConfirmed, request_token: requestToken,
                cash_received: selectedParcel.payment_method === 'cod' ? cashReceived : '0', change_given: selectedParcel.payment_method === 'cod' ? changeGiven : '0', cash_confirmed: selectedParcel.payment_method === 'cod' ? cashConfirmed : false,
                recipient_name: recipientName.trim() || selectedParcel.buyer_name,
                hub_id: activeHub?.id,
                notes: notes.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedParcel(null);
                    setSubmitting(false);
                },
                onError: value => { setErrors(value); setSubmitting(false); },
                onFinish: () => { pending.current = false; setSubmitting(false); },
            }
        );
    };

    return (
        <DashboardLayout
            title="Counter pickup"
            subtitle="Customer handover verification & hub pickup desk"
            actions={canScan &&
                <Link
                    href={`${basePath}/scan${activeHub ? `?hub_id=${activeHub.id}` : ''}`}
                    className="inline-flex items-center gap-2 px-4 py-2 bg-brand-500 hover:bg-brand-600 active:scale-[0.99] text-white text-xs font-bold font-sans rounded-xl shadow-sm transition"
                >
                    <ScanLine className="w-4 h-4" />
                    <span>Scan station</span>
                </Link>
            }
        >
            <Head title="Counter Self-Pickup — BagooPH" />

            <div className="space-y-5 font-sans">
                <HubPageHeader
                    title="Counter pickup"
                    roleLabel={!isCounterHub ? 'Not a pickup counter' : activeHub?.operating_hours ? `Open · ${activeHub.operating_hours}` : 'Counter hours not set'}
                    icon={Store}
                    actions={isCounterHub && canScan ? (
                        <Link
                            href={`${basePath}/scan${activeHub ? `?hub_id=${activeHub.id}` : ''}`}
                            className="inline-flex items-center gap-2 rounded border border-zinc-400 bg-white px-3.5 py-2 text-xs font-semibold text-ink-900 shadow-2xs transition hover:bg-zinc-50 hover:border-zinc-600"
                        >
                            <ScanLine className="h-4 w-4 text-zinc-600" />
                            Open parcel check-in
                        </Link>
                    ) : undefined}
                />
                {!isCounterHub && <HubNextStep title={activeHub?.tier === 'local_bayan_hub' ? 'Counter pickup is disabled at this hub' : 'Counter pickup is handled by child hubs'} icon={Store}><p>{canConfigureHours ? 'Choose an eligible child hub in the facility switcher to manage its pickup counter. Changing the view does not grant physical handler access.' : 'Use your assigned facility for its recorded parcel work. The company manager and assigned child-hub handler manage counter pickup.'}</p></HubNextStep>}
                {isCounterHub && canConfigureHours && activeHub && <form onSubmit={event => { event.preventDefault(); router.post(`${basePath}/counter/hours`,{hub_id:activeHub.id,operating_hours:hours},{preserveScroll:true,onError:setErrors}); }} className="rounded-xl border border-zinc-200/90 bg-white p-5 sm:p-6 shadow-xs"><label className="text-sm font-bold text-[#1C1A17]">Actual pickup operating hours<input required maxLength={255} value={hours} onChange={event => setHours(event.target.value)} className="mt-2 w-full rounded-xl border border-zinc-200/90 bg-zinc-50/50 hover:bg-white focus:bg-white px-3.5 py-2.5 text-xs text-[#1C1A17] font-medium shadow-xs focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/15 transition-colors" placeholder="Enter the hours your counter actually opens" /></label><button className="mt-3.5 rounded-xl bg-brand-500 hover:bg-brand-600 px-4 py-2 text-xs font-bold text-white shadow-xs transition active:scale-[0.99]">Save pickup hours</button>{errors.operating_hours && <p role="alert" className="mt-2 text-xs text-red-700">{errors.operating_hours}</p>}</form>}
                {isCounterHub && !activeHub?.operating_hours && <p className="rounded-xl border border-amber-200 bg-amber-50/80 p-4 text-xs font-medium text-amber-900">The company administrator must record actual counter hours before parcels can be staged for pickup.</p>}

                <div className="grid grid-cols-1 overflow-hidden rounded-card border border-line-200 bg-white sm:grid-cols-3">
                    <button type="button" onClick={() => setCategoryFilter('ready')} aria-pressed={categoryFilter === 'ready'}
                        className={`border-b border-line-200 p-4 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:border-b-0 sm:border-r sm:p-5 ${categoryFilter === 'ready' ? 'bg-brand-50/60' : 'hover:bg-surface-50'}`}>
                        <span className="block text-[13px] text-ink-600">Ready to release on this page</span>
                        <p className="mt-1 text-[28px] font-bold leading-9 tabular-nums text-success">{readyNow}</p>
                    </button>
                    <button type="button" onClick={() => setCategoryFilter('attention')} aria-pressed={categoryFilter === 'attention'}
                        className={`border-b border-line-200 p-4 text-left transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-500 sm:border-b-0 sm:border-r sm:p-5 ${categoryFilter === 'attention' ? 'bg-brand-50/60' : 'hover:bg-surface-50'}`}>
                        <span className="block text-[13px] text-ink-600">Needs attention on this page</span>
                        <p className={`mt-1 text-[28px] font-bold leading-9 tabular-nums ${blockedNow > 0 ? 'text-warning' : 'text-ink-900'}`}>{blockedNow}</p>
                    </button>
                    <div className="p-4 sm:p-5">
                        <span className="block text-[13px] text-ink-600">Released recently</span>
                        <p className="mt-1 text-[28px] font-bold leading-9 tabular-nums text-ink-900">{recentlyCollected.length}</p>
                    </div>
                </div>

                {/* 2. MAIN CONTENT: UNIFIED NOTION DATABASE VIEW + RECENTLY COLLECTED */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

                    {/* LEFT (8 COLS): STAGED PARCELS FOR HANDOVER */}
                    <div className="lg:col-span-8 bg-white rounded-xl border border-zinc-200/90 shadow-xs overflow-hidden">
                        {/* Cohesive Notion Database Header + Search + Verification Strip */}
                        <div className="space-y-3 px-5 pt-5">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 className="text-base font-semibold text-ink-900">Counter queue</h3>
                                <span className="text-[13px] tabular-nums text-ink-600">{counterParcels.total} matching parcels</span>
                            </div>

                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-80">
                                    <Search className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        type="text"
                                        aria-label="Search counter parcels"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                        placeholder="Search code, order, buyer or phone"
                                        className={`${hubInput} pl-9`}
                                    />
                                </form>
                            </div>

                            <HubCategoryTabs
                                label="Filter counter parcels"
                                activeId={categoryFilter}
                                onChange={(id) => setCategoryFilter(id as 'all' | 'ready' | 'attention')}
                                tabs={[
                                    { id: 'all', label: 'All on page', count: pageParcels.length },
                                    { id: 'ready', label: 'Ready to release', count: readyParcels.length },
                                    { id: 'attention', label: 'Needs attention', count: attentionParcels.length },
                                ]}
                            />
                        </div>

                        <div className="p-5 sm:p-6">

                        {pageParcels.length === 0 ? (
                            <div className="py-14 text-center font-sans">
                                <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-surface-100 text-ink-500">
                                    <Store className="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p className="text-sm font-semibold text-ink-900">{counterParcels.total > 0 ? 'No parcels on this page' : 'No matching parcels at this counter'}</p>
                            </div>
                        ) : visibleParcels.length === 0 ? (
                            <div className="py-10 text-center font-sans">
                                <p className="text-sm font-semibold text-ink-900">No parcels match this category on the current page</p>
                                <p className="mt-1 text-xs text-zinc-500">Switch back to All on page or check another page.</p>
                                <button
                                    type="button"
                                    onClick={() => setCategoryFilter('all')}
                                    className="mt-3 rounded-lg border border-zinc-300 bg-white px-3.5 py-1.5 text-xs font-semibold text-ink-900 shadow-2xs hover:bg-zinc-50 cursor-pointer"
                                >
                                    Show all on page
                                </button>
                            </div>
                        ) : (
                            <div className="divide-y divide-zinc-100 mt-2">
                                {visibleParcels.map((parcel) => (
                                    <div key={parcel.id} className="py-4 sm:py-5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap font-sans">
                                                <span className="inline-flex items-center rounded-lg border border-zinc-200 bg-zinc-100 px-2.5 py-1 text-xs font-bold tabular-nums tracking-wide text-ink-900 shadow-2xs">
                                                    <span className="text-[10px] uppercase font-sans font-extrabold mr-1 text-zinc-500">WAYBILL:</span>{parcel.tracking_number}
                                                </span>
                                                <span className="text-xs font-semibold px-2 py-0.5 rounded-lg bg-zinc-100 text-zinc-700 border border-zinc-200">
                                                    {parcel.destination_bin?.trim() ? parcel.destination_bin : 'Shelf not recorded'}
                                                </span>
                                                <span className="text-xs font-semibold uppercase px-2 py-0.5 rounded-lg bg-zinc-50 text-zinc-600 border border-zinc-200">
                                                    {parcel.payment_method}
                                                </span>
                                            </div>

                                            <div className="mt-2 flex items-center gap-3.5 text-xs text-zinc-600">
                                                <span className="font-bold text-[#1C1A17] flex items-center gap-1.5 font-sans">
                                                    <User className="w-3.5 h-3.5 text-zinc-400" />
                                                    {parcel.buyer_name}
                                                </span>
                                                {parcel.buyer_phone !== 'N/A' && (
                                                    <span className="text-zinc-500 font-sans flex items-center gap-1.5 text-xs">
                                                        <Phone className="w-3.5 h-3.5 text-zinc-400" />
                                                        {parcel.buyer_phone}
                                                    </span>
                                                )}
                                                <span className="text-zinc-400 font-sans text-xs">
                                                    {parcel.arrived_at ? `Staged: ${parcel.arrived_at} Asia/Manila` : 'Awaiting staging'}
                                                </span>
                                            </div>

                                            <div className="mt-3.5 space-y-3"><HubParcelState guidance={parcel.guidance} /><HubParcelNextStep guidance={parcel.guidance.ready_for_collection && !hasCurrentClaimWindow(parcel)
                                                ? { ...parcel.guidance, can_act: false, href: null, next_step: 'Review the current collection window', blocker: 'The collection window changed. Refresh the current checks before release.' }
                                                : parcel.guidance} hideLink={parcel.guidance.action === 'counter'} /></div>

                                            {/* Item breakdown preview */}
                                            {parcel.items && parcel.items.length > 0 && (
                                                <div className="mt-2 text-xs text-zinc-500 font-sans">
                                                    {parcel.items.map((it, idx) => (
                                                        <span key={idx} className="mr-3">
                                                            {it.quantity}x {it.name}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-3.5 sm:text-right shrink-0 font-sans">
                                            <div>
                                                <span className="text-sm font-bold text-[#1C1A17] block">
                                                    ₱{parcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                                <span className="text-[11px] text-zinc-400 block uppercase font-medium">
                                                    {parcel.payment_method === 'cod' ? 'Collect COD' : 'Prepaid'}
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => openReleaseModal(parcel)}
                                                disabled={!canRelease(parcel)}
                                                className="px-4 py-2 bg-brand-500 hover:bg-brand-600 text-white rounded-xl text-xs font-bold font-sans shadow-sm transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed active:scale-[0.99]"
                                            >
                                                <CheckCircle2 className="w-4 h-4" />
                                                <span>Release</span>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="mt-5 pt-4 border-t border-zinc-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                            <p className="text-zinc-500">Showing {counterParcels.from ?? 0} to {counterParcels.to ?? 0} of {counterParcels.total} matching parcels. Page {counterParcels.current_page} of {counterParcels.last_page}.</p>
                            <nav aria-label="Counter queue pages" className="flex items-center gap-2">
                                {counterParcels.prev_page_url ? <Link href={counterParcels.prev_page_url} preserveScroll className="rounded-xl border border-zinc-200/90 bg-white px-3.5 py-2 font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 hover:text-zinc-900 transition-colors">Previous</Link>
                                    : <button type="button" disabled className="rounded-xl border border-zinc-200/90 px-3.5 py-2 text-zinc-400 cursor-not-allowed">Previous</button>}
                                {counterParcels.next_page_url ? <Link href={counterParcels.next_page_url} preserveScroll className="rounded-xl border border-zinc-200/90 bg-white px-3.5 py-2 font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 hover:text-zinc-900 transition-colors">Next</Link>
                                    : <button type="button" disabled className="rounded-xl border border-zinc-200/90 px-3.5 py-2 text-zinc-400 cursor-not-allowed">Next</button>}
                                {counterParcels.current_page > counterParcels.last_page && <Link href={counterParcels.first_page_url} className="rounded-xl border border-zinc-300 bg-zinc-100 px-3.5 py-2 font-bold text-ink-900 shadow-xs">First page</Link>}
                            </nav>
                        </div>
                        </div>
                    </div>

                    {/* RIGHT (4 COLS): RECENTLY COLLECTED AUDIT LOG */}
                    <div className="lg:col-span-4 bg-white rounded-xl p-5 sm:p-6 border border-zinc-200/90 shadow-sm flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between pb-4 border-b border-zinc-100">
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                                    <h3 className="text-xs font-bold text-[#1C1A17] font-sans uppercase tracking-wider">
                                        Recent Handover Log
                                    </h3>
                                </div>
                                <span className="text-[10px] font-sans font-bold text-zinc-400 uppercase">
                                    Verified
                                </span>
                            </div>

                            {recentlyCollected.length === 0 ? (
                                <div className="py-12 text-center text-zinc-400 text-xs font-sans">
                                    No claims released recently.
                                </div>
                            ) : (
                                <div className="divide-y divide-zinc-100 mt-2 font-sans">
                                    {recentlyCollected.map((item) => (
                                        <div key={item.id} className="py-3">
                                            <div className="flex items-center justify-between">
                                                <span className="tabular-nums font-bold text-xs text-[#1C1A17]">
                                                    {item.tracking_number}
                                                </span>
                                                <span className="text-[11px] text-zinc-400 tabular-nums">
                                                    {item.collected_at}
                                                </span>
                                            </div>
                                            <p className="text-xs text-zinc-600 mt-1 font-sans">
                                                Handed over to: <span className="font-semibold text-[#1C1A17]">{item.buyer_name}</span>
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                </div>

            </div>

            {/* RELEASE VERIFICATION MODAL */}
            {selectedParcel && (
                <Dialog open onClose={() => { if (!submitting) setSelectedParcel(null); }} className="relative z-50 font-sans">
                    <DialogBackdrop className="fixed inset-0 bg-brand-900/45 backdrop-blur-xs transition-opacity" />
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                    <DialogPanel className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col overflow-hidden rounded-2xl border border-zinc-200/90 bg-white shadow-2xl">
                        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-100 px-6 py-5">
                            <div>
                                <DialogTitle className="text-base font-bold text-[#1C1A17]">
                                    Verify counter handover
                                </DialogTitle>
                                <p className="text-xs text-ink-900 tabular-nums font-bold mt-1">
                                    {selectedParcel.tracking_number}
                                </p>
                                {selectedParcel.claim && <p className="mt-1 text-xs text-zinc-500">Holding deadline: {new Date(selectedParcel.claim.expires_at).toLocaleString('en-PH', { timeZone: 'Asia/Manila' })} PHT · {selectedParcel.claim.status}</p>}
                            </div>
                            <button
                                type="button"
                                aria-label="Close handover verification"
                                disabled={submitting}
                                onClick={() => setSelectedParcel(null)}
                                className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 transition-colors"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleReleaseSubmit} aria-busy={submitting || undefined} className="flex min-h-0 flex-1 flex-col">
                            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-6 py-5">
                            <label className="block text-xs font-bold text-[#1C1A17]">Actual parcel waybill<input required disabled={submitting} value={barcode} onChange={event => setBarcode(event.target.value)} className={`${hubInput} mt-1.5`} placeholder="Scan or type waybill number" /></label>
                            <WaybillCamera onScan={setBarcode} disabled={submitting || !currentSelectedParcel || !canRelease(currentSelectedParcel)} instruction="Scan the parcel waybill, then check the buyer’s ID, private claim code and payment before confirming handover." />
                            <label className="flex items-start gap-2.5 text-xs text-zinc-700 leading-relaxed font-medium"><input type="checkbox" required disabled={submitting} checked={identityConfirmed} onChange={event => setIdentityConfirmed(event.target.checked)} className="mt-0.5 rounded border-zinc-300 text-ink-900 focus:ring-[#121316]" />I checked a valid photo ID matching buyer account #{selectedParcel.buyer_id} and the name below.</label>
                            <div>
                                <label htmlFor="counter-recipient-name" className="mb-1.5 block text-xs font-bold text-[#1C1A17]">
                                    Recipient name on the ID
                                </label>
                                <input
                                    id="counter-recipient-name"
                                    name="recipient_name"
                                    type="text"
                                    autoComplete="off"
                                    disabled={submitting}
                                    required
                                    value={recipientName}
                                    onChange={(e) => setRecipientName(e.target.value)}
                                    className={`${hubInput}`}
                                />
                            </div>

                            <div>
                                <label htmlFor="counter-claim-code" className="mb-1.5 block text-xs font-bold text-[#1C1A17]">
                                    One-time claim code
                                </label>
                                <input
                                    id="counter-claim-code"
                                    name="claim_code"
                                    type="text"
                                    spellCheck={false}
                                    disabled={submitting}
                                    value={claimCode}
                                    onChange={(e) => setClaimCode(e.target.value)}
                                    required autoComplete="off" placeholder="Enter the buyer’s private claim code"
                                    className={`${hubInput} tabular-nums`}
                                />
                            </div>

                            <div>
                                <label htmlFor="counter-handover-note" className="mb-1.5 block text-xs font-bold text-[#1C1A17]">
                                    Handover note
                                </label>
                                <input
                                    id="counter-handover-note"
                                    name="notes"
                                    type="text"
                                    disabled={submitting}
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                    placeholder="e.g. Valid Driver's License inspected"
                                    className={`${hubInput}`}
                                />
                            </div>

                            {selectedParcel.payment_method === 'cod' && (
                                <div className="p-4 rounded-xl bg-amber-50/80 border border-amber-200 text-amber-900 text-xs space-y-3">
                                    <p className="font-bold text-[10px] uppercase tracking-wider text-amber-800">Collect Cash on Delivery</p>
                                    <label className="block text-xs font-semibold">Actual cash tender<input required disabled={submitting} inputMode="decimal" value={cashReceived} onChange={event => setCashReceived(event.target.value)} className={`${hubInput} mt-1`} /></label>
                                    <label className="block text-xs font-semibold">Change returned<input required disabled={submitting} inputMode="decimal" value={changeGiven} onChange={event => setChangeGiven(event.target.value)} className={`${hubInput} mt-1`} /></label>
                                    <label className="flex items-start gap-2.5 text-xs font-medium"><input required type="checkbox" checked={cashConfirmed} onChange={event => setCashConfirmed(event.target.checked)} className="mt-0.5 rounded border-zinc-300 text-ink-900 focus:ring-[#121316]" />I received the cash and returned any correct change.</label>
                                    <p className="text-base font-extrabold mt-1 text-[#1C1A17]">
                                        ₱{selectedParcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                            )}

                            {(!currentSelectedParcel || !canRelease(currentSelectedParcel)) && <p role="alert" className="text-xs font-medium text-amber-800 rounded-lg bg-amber-50 p-2.5 border border-amber-200">This parcel is no longer ready for release. Close this form and review its current checks.</p>}
                            {Object.entries(errors).map(([key,value]) => <p role="alert" key={key} className="text-xs font-medium text-red-700">{value}</p>)}
                            </div>
                            <div className="flex shrink-0 items-center justify-end gap-2.5 border-t border-zinc-100 bg-zinc-50/50 px-6 py-4 font-sans">
                                <button
                                    type="button"
                                    disabled={submitting}
                                    onClick={() => setSelectedParcel(null)}
                                    className="rounded-xl border border-zinc-200/90 bg-white px-4 py-2.5 text-xs font-semibold text-zinc-700 shadow-xs hover:bg-zinc-100 hover:text-zinc-900 transition-colors"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting || !currentSelectedParcel || !canRelease(currentSelectedParcel)}
                                    className="rounded-xl bg-brand-500 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-brand-600 disabled:opacity-50 active:scale-[0.99]"
                                >
                                    {submitting ? 'Releasing...' : 'Confirm Handover'}
                                </button>
                            </div>
                        </form>
                    </DialogPanel>
                </div>
                </Dialog>
            )}
        </DashboardLayout>
    );
}
