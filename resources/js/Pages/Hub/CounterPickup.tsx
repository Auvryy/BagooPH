import React, { useEffect, useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Dialog, DialogBackdrop, DialogPanel, DialogTitle } from '@headlessui/react';
import WaybillCamera from '@/Components/WaybillCamera';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubNextStep, HubPageHeader, HubStepRail, hubInput } from '@/Components/Logistics/Workspace';
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
    const [currentTime, setCurrentTime] = useState(Date.now);
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
            subtitle="Customer handover verification & zero-fee claim desk"
            actions={canScan &&
                <Link
                    href={`${basePath}/scan${activeHub ? `?hub_id=${activeHub.id}` : ''}`}
                    className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold font-sans rounded-lg shadow-sm transition"
                >
                    <ScanLine className="w-3.5 h-3.5" />
                    <span>Scan station</span>
                </Link>
            }
        >
            <Head title="Counter Self-Pickup — BagooPH" />

            <div className="space-y-5 font-sans">
                <HubPageHeader title="Counter pickup" icon={Store} description="Stage the parcel, then verify the buyer and their private claim code before handing it over." />
                {isCounterHub && <HubStepRail label="Counter release checks" steps={[{ title: 'Stage parcel', detail: 'Recorded hub arrival', state: 'waiting' }, { title: 'Verify buyer', detail: 'Photo ID and claim code', state: 'waiting' }, { title: 'Check payment', detail: 'Collect cash only for COD', state: 'waiting' }, { title: 'Record handover', detail: 'Release the actual parcel', state: 'waiting' }]} />}
                {!isCounterHub && <HubNextStep title={activeHub?.tier === 'local_bayan_hub' ? 'Counter pickup is disabled at this hub' : 'Counter pickup is handled by child hubs'} icon={Store}><p>{canConfigureHours ? 'Choose an eligible child hub in the facility switcher to manage its pickup counter. Changing the view does not grant physical handler access.' : 'Use your assigned facility for its recorded parcel work. The company manager and assigned child-hub handler manage counter pickup.'}</p></HubNextStep>}
                <p className="text-sm text-slate-600">Readiness and attention counts apply to this page. The queue total includes all matching parcels. Check the buyer's photo ID, private claim code and original payment before release.</p>
                {isCounterHub && canConfigureHours && activeHub && <form onSubmit={event => { event.preventDefault(); router.post(`${basePath}/counter/hours`,{hub_id:activeHub.id,operating_hours:hours},{preserveScroll:true,onError:setErrors}); }} className="rounded-lg border border-slate-300 bg-white p-4"><label className="text-sm font-semibold">Actual pickup operating hours<input required maxLength={255} value={hours} onChange={event => setHours(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" placeholder="Enter the hours your counter actually opens" /></label><button className="mt-3 rounded-lg bg-[#E00D42] px-4 py-2 text-sm font-semibold text-white">Save pickup hours</button>{errors.operating_hours && <p role="alert" className="mt-2 text-sm text-red-700">{errors.operating_hours}</p>}</form>}
                {isCounterHub && !activeHub?.operating_hours && <p className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">The company administrator must record actual counter hours before parcels can be staged for pickup.</p>}

                {/* 1. TOP STATS TILES */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
                    <div className="bg-white rounded-lg p-4 border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Ready for Buyer Verification (this page)</span>
                                <Store className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {readyNow}{' '}
                                    <span className="text-xs font-bold text-slate-400">ready</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-300 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Requirements:</span>
                            <span className="font-bold text-slate-800">Staging and valid claim</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-4 border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Needs Attention (this page)</span>
                                <AlertCircle className="w-3.5 h-3.5 text-amber-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-amber-800 font-sans tracking-tight">
                                    {blockedNow}{' '}
                                    <span className="text-xs font-bold text-amber-800">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-300 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Next step:</span>
                            <span className="font-bold text-amber-800">Review the blocker</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-4 border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Recent Verified Collections</span>
                                <CheckCircle2 className="w-3.5 h-3.5 text-slate-700" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {recentlyCollected.length}{' '}
                                    <span className="text-xs font-bold text-slate-400">released</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-300 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">History:</span>
                            <span className="font-bold text-slate-800">Up to five shown</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-4 border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-bold uppercase tracking-wider text-[10px]">Station Location</span>
                                <span className="text-[9px] font-bold px-1.5 py-0.5 rounded-lg bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] font-sans">
                                    {activeHub?.code || 'STATION'}
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-base font-black text-slate-900 truncate">
                                    {activeHub?.name || 'Local Bayan Hub'}
                                </p>
                                <p className="text-xs text-slate-500 font-sans mt-0.5">
                                    {activeHub?.city_municipality || 'Laguna'}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-2.5 border-t border-slate-300 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Self-Pickup Desk:</span>
                            <span className={`font-bold ${!isCounterHub ? 'text-slate-500' : activeHub?.operating_hours ? 'text-emerald-700' : 'text-amber-700'}`}>{!isCounterHub ? 'Not a pickup counter' : activeHub?.operating_hours ? 'Hours recorded' : 'Setup required'}</span>
                        </div>
                    </div>
                </div>

                {/* 2. SEARCH BAR & ACTION HEADER */}
                <div className="bg-white rounded-lg p-3.5 border border-slate-300 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-3">
                    <form onSubmit={handleSearchSubmit} className="relative w-full sm:w-96">
                        <Search className="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                        <input
                            type="text"
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            placeholder="Tracking code, order number, buyer name or phone..."
                            className="w-full pl-8 pr-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-sans focus:bg-white focus:outline-hidden focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] transition"
                        />
                    </form>

                    <div className="text-xs font-sans text-slate-500 flex items-center gap-2">
                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                        <span>Verify photo ID, the one-time claim code and payment before release. Collect cash only for COD.</span>
                    </div>
                </div>

                {/* 3. MAIN CONTENT: STAGED PARCELS QUEUE + RECENTLY COLLECTED */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-4">

                    {/* LEFT (8 COLS): STAGED PARCELS FOR HANDOVER */}
                    <div className="lg:col-span-8 bg-white rounded-lg p-5 border border-slate-300 shadow-sm">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-300">
                            <div className="flex items-center gap-2">
                                <Store className="w-4 h-4 text-slate-700" />
                                <h3 className="text-xs font-black text-slate-900 font-sans uppercase tracking-wider">
                                    Counter Parcel Queue
                                </h3>
                            </div>
                            <span className="text-xs font-sans text-slate-500 font-bold">
                                {counterParcels.total} matching parcels
                            </span>
                        </div>

                        {pageParcels.length === 0 ? (
                            <div className="py-12 text-center font-sans">
                                <Store className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                                <p className="text-xs font-bold text-slate-700">{counterParcels.total > 0 ? 'No parcels on this page' : 'No matching parcels at this counter'}</p>
                                <p className="text-[11px] text-slate-400 mt-1">
                                    Arrived parcels appear here before staging. Search or change pages to find a parcel.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-200 mt-1">
                                {pageParcels.map((parcel) => (
                                    <div key={parcel.id} className="py-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap font-sans">
                                                <span className="font-bold text-slate-900 text-xs">
                                                    {parcel.tracking_number}
                                                </span>
                                                <span className="text-[10px] px-1.5 py-0.5 rounded-lg bg-[#FDF2F4] text-[#E00D42] border border-[#FCE7EA] font-bold">
                                                    {parcel.destination_bin?.trim() ? parcel.destination_bin : 'Shelf not recorded'}
                                                </span>
                                                <span className="text-[10px] uppercase px-1.5 py-0.5 rounded-lg bg-slate-100 text-slate-600 border border-slate-300">
                                                    {parcel.payment_method}
                                                </span>
                                            </div>

                                            <div className="mt-1 flex items-center gap-3 text-xs text-slate-600">
                                                <span className="font-bold text-slate-900 flex items-center gap-1 font-sans">
                                                    <User className="w-3 h-3 text-slate-400" />
                                                    {parcel.buyer_name}
                                                </span>
                                                {parcel.buyer_phone !== 'N/A' && (
                                                    <span className="text-slate-500 font-sans flex items-center gap-1 text-[11px]">
                                                        <Phone className="w-3 h-3 text-slate-400" />
                                                        {parcel.buyer_phone}
                                                    </span>
                                                )}
                                                <span className="text-slate-400 font-sans text-[10px]">
                                                    {parcel.arrived_at ? `Staged: ${parcel.arrived_at} Asia/Manila` : 'Awaiting staging'}
                                                </span>
                                            </div>

                                            <div className="mt-3 space-y-3"><HubParcelState guidance={parcel.guidance} /><HubParcelNextStep guidance={parcel.guidance.ready_for_collection && !hasCurrentClaimWindow(parcel)
                                                ? { ...parcel.guidance, can_act: false, href: null, next_step: 'Review the current collection window', blocker: 'The collection window changed. Refresh the current checks before release.' }
                                                : parcel.guidance} hideLink={parcel.guidance.action === 'counter'} /></div>

                                            {/* Item breakdown preview */}
                                            {parcel.items && parcel.items.length > 0 && (
                                                <div className="mt-1.5 text-[11px] text-slate-500 font-sans">
                                                    {parcel.items.map((it, idx) => (
                                                        <span key={idx} className="mr-3">
                                                            {it.quantity}x {it.name}
                                                        </span>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="flex items-center gap-3 sm:text-right shrink-0 font-sans">
                                            <div>
                                                <span className="text-xs font-bold text-slate-900 block">
                                                    ₱{parcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                                </span>
                                                <span className="text-[10px] text-slate-400 block uppercase">
                                                    {parcel.payment_method === 'cod' ? 'Collect COD' : 'Prepaid'}
                                                </span>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => openReleaseModal(parcel)}
                                                disabled={!canRelease(parcel)}
                                                className="px-3 py-1.5 bg-slate-900 hover:bg-slate-800 text-white rounded-lg text-xs font-bold font-sans shadow-sm transition flex items-center gap-1.5 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <CheckCircle2 className="w-3.5 h-3.5" />
                                                <span>Release</span>
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        <div className="mt-4 pt-3 border-t border-slate-300 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                            <p className="text-slate-600">Showing {counterParcels.from ?? 0} to {counterParcels.to ?? 0} of {counterParcels.total} matching parcels. Page {counterParcels.current_page} of {counterParcels.last_page}.</p>
                            <nav aria-label="Counter queue pages" className="flex items-center gap-2">
                                {counterParcels.prev_page_url ? <Link href={counterParcels.prev_page_url} preserveScroll className="rounded-lg border border-slate-300 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Previous</Link>
                                    : <button type="button" disabled className="rounded-lg border border-slate-300 px-3 py-2 text-slate-400">Previous</button>}
                                {counterParcels.next_page_url ? <Link href={counterParcels.next_page_url} preserveScroll className="rounded-lg border border-slate-300 px-3 py-2 font-semibold text-slate-700 hover:bg-slate-50">Next</Link>
                                    : <button type="button" disabled className="rounded-lg border border-slate-300 px-3 py-2 text-slate-400">Next</button>}
                                {counterParcels.current_page > counterParcels.last_page && <Link href={counterParcels.first_page_url} className="rounded-lg border border-slate-300 px-3 py-2 font-semibold text-[#E00D42]">First page</Link>}
                            </nav>
                        </div>
                    </div>

                    {/* RIGHT (4 COLS): RECENTLY COLLECTED AUDIT LOG */}
                    <div className="lg:col-span-4 bg-white rounded-lg p-5 border border-slate-300 shadow-sm">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-300">
                            <div className="flex items-center gap-2">
                                <CheckCircle2 className="w-4 h-4 text-emerald-700" />
                                <h3 className="text-xs font-black text-slate-900 font-sans uppercase tracking-wider">
                                    Recent Handover Log
                                </h3>
                            </div>
                            <span className="text-[10px] font-sans font-bold text-slate-400 uppercase">
                                Verified
                            </span>
                        </div>

                        {recentlyCollected.length === 0 ? (
                            <div className="py-10 text-center text-slate-400 text-xs font-sans">
                                No claims released recently.
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-200 mt-1 font-sans">
                                {recentlyCollected.map((item) => (
                                    <div key={item.id} className="py-2.5">
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-xs text-slate-900">
                                                {item.tracking_number}
                                            </span>
                                            <span className="text-[10px] text-slate-400">
                                                {item.collected_at}
                                            </span>
                                        </div>
                                        <p className="text-xs text-slate-600 mt-0.5 font-sans">
                                            Handed over to: <span className="font-bold text-slate-800">{item.buyer_name}</span>
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="mt-3.5 p-2.5 rounded-lg bg-slate-50 border border-slate-300 text-xs text-slate-800">
                            <p className="font-bold flex items-center gap-1.5 font-sans text-[11px]">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-700" />
                                Verified Station Handover
                            </p>
                            <p className="text-[10px] text-slate-500 font-sans mt-0.5">
                                Counter handovers automatically resolve status to "Customer Collected" with audit timestamps.
                            </p>
                        </div>
                    </div>

                </div>

            </div>

            {/* RELEASE VERIFICATION MODAL */}
            {selectedParcel && (
                <Dialog open onClose={() => { if (!submitting) setSelectedParcel(null); }} className="relative z-50 font-sans">
                    <DialogBackdrop className="fixed inset-0 bg-black/60 backdrop-blur-xs" />
                    <div className="fixed inset-0 flex items-center justify-center p-4">
                    <DialogPanel className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col overflow-hidden rounded-lg border border-slate-300 bg-white shadow-2xl">
                        <div className="flex shrink-0 items-center justify-between gap-3 border-b border-slate-300 px-5 py-4">
                            <div>
                                <DialogTitle className="text-sm font-bold text-slate-900">
                                    Verify counter handover
                                </DialogTitle>
                                <p className="text-xs text-[#E00D42] font-bold mt-0.5">
                                    {selectedParcel.tracking_number}
                                </p>
                                {selectedParcel.claim && <p className="mt-1 text-xs text-slate-700">Holding deadline: {new Date(selectedParcel.claim.expires_at).toLocaleString('en-PH')} · {selectedParcel.claim.status}</p>}
                            </div>
                            <button
                                type="button"
                                aria-label="Close handover verification"
                                disabled={submitting}
                                onClick={() => setSelectedParcel(null)}
                                className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-50 hover:text-slate-700"
                            >
                                <X className="w-4 h-4" />
                            </button>
                        </div>

                        <form onSubmit={handleReleaseSubmit} aria-busy={submitting || undefined} className="flex min-h-0 flex-1 flex-col">
                            <div className="min-h-0 flex-1 space-y-3.5 overflow-y-auto overscroll-contain px-5 py-4">
                            <label className="block text-sm font-semibold">Actual parcel waybill<input required disabled={submitting} value={barcode} onChange={event => setBarcode(event.target.value)} className={`${hubInput} mt-1 min-h-11`} /></label>
                            <WaybillCamera onScan={setBarcode} disabled={submitting || !currentSelectedParcel || !canRelease(currentSelectedParcel)} instruction="Scan the parcel waybill, then check the buyer’s ID, private claim code and payment before confirming handover." />
                            <label className="flex items-start gap-2 text-sm"><input type="checkbox" required disabled={submitting} checked={identityConfirmed} onChange={event => setIdentityConfirmed(event.target.checked)} className="mt-1 border-slate-300" />I checked a valid photo ID matching buyer account #{selectedParcel.buyer_id} and the name below.</label>
                            <div>
                                <label htmlFor="counter-recipient-name" className="mb-1 block text-sm font-semibold text-slate-700">
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
                                    className={`${hubInput} min-h-11`}
                                />
                            </div>

                            <div>
                                <label htmlFor="counter-claim-code" className="mb-1 block text-sm font-semibold text-slate-700">
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
                                    className={`${hubInput} min-h-11`}
                                />
                            </div>

                            <div>
                                <label htmlFor="counter-handover-note" className="mb-1 block text-sm font-semibold text-slate-700">
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
                                    className={`${hubInput} min-h-11`}
                                />
                            </div>

                            {selectedParcel.payment_method === 'cod' && (
                                <div className="p-2.5 rounded-lg bg-amber-50 border border-amber-300 text-amber-900 text-xs">
                                    <p className="font-bold text-[10px] uppercase">Collect Cash on Delivery</p>
                                    <label className="mt-2 block">Actual cash tender<input required disabled={submitting} inputMode="decimal" value={cashReceived} onChange={event => setCashReceived(event.target.value)} className={`${hubInput} mt-1 min-h-11`} /></label>
                                    <label className="mt-2 block">Change returned<input required disabled={submitting} inputMode="decimal" value={changeGiven} onChange={event => setChangeGiven(event.target.value)} className={`${hubInput} mt-1 min-h-11`} /></label>
                                    <label className="mt-3 flex items-start gap-2"><input required type="checkbox" checked={cashConfirmed} onChange={event => setCashConfirmed(event.target.checked)} />I received the cash and returned any correct change.</label>
                                    <p className="text-sm font-black mt-0.5">
                                        ₱{selectedParcel.total_amount.toLocaleString(undefined, { minimumFractionDigits: 2 })}
                                    </p>
                                </div>
                            )}

                            {(!currentSelectedParcel || !canRelease(currentSelectedParcel)) && <p role="alert" className="text-sm text-amber-800">This parcel is no longer ready for release. Close this form and review its current checks.</p>}
                            {Object.entries(errors).map(([key,value]) => <p role="alert" key={key} className="text-sm text-red-700">{value}</p>)}
                            </div>
                            <div className="flex shrink-0 items-center justify-end gap-2 border-t border-slate-300 bg-white px-5 py-4 font-sans">
                                <button
                                    type="button"
                                    disabled={submitting}
                                    onClick={() => setSelectedParcel(null)}
                                    className="min-h-11 rounded-lg border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={submitting || !currentSelectedParcel || !canRelease(currentSelectedParcel)}
                                    className="min-h-11 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-50"
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
