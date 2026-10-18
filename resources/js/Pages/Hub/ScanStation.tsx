import { refreshPage } from '@/utils/accountRequests';
import React, { useState, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import HubCounterPreview, { CounterPreviewParcel } from '@/Components/HubCounterPreview';
import { HubParcelNextStep, HubParcelState, ParcelGuidance } from '@/Components/HubParcelGuidance';
import WaybillCamera from '@/Components/WaybillCamera';
import { requestWaybill, waybillCode, WaybillRequestError } from '@/utils/waybillScan';
import useOperationalRefresh from '@/hooks/useOperationalRefresh';
import {
    ScanLine,
    Package,
    CheckCircle2,
    AlertCircle,
    Building2,
    Volume2,
    VolumeX,
    ArrowRight,
    RefreshCw,
    Clock,
    Layers,
    ShieldCheck,
    Boxes,
    CornerDownRight
} from 'lucide-react';

interface LogisticsHub {
    id: number;
    name: string;
    code: string;
    tier: 'regional_mother_hub' | 'local_bayan_hub';
    province: string;
    city_municipality: string;
    barangay: string;
    allows_self_pickup: boolean;
    company?: {
        id: number;
        name: string;
        code: string;
    };
}

interface RecentScan {
    id: number;
    tracking_number: string;
    checkpoint_type: string;
    location_name: string;
    notes: string;
    scanned_by: string;
    created_at: string;
    status: string;
    delivery_type: string;
    destination_bin: string | null;
    buyer_name: string;
}

interface PendingOriginIntake {
    id: number;
    tracking_number: string;
    order_number?: string;
    buyer_name: string;
    rider_name: string;
    item_names: string[];
    updated_at: string;
}

interface ScanStationProps {
    activeHub: LogisticsHub | null;
    hubs: LogisticsHub[];
    recentScans: RecentScan[];
    pendingOriginIntake: PendingOriginIntake[];
    counterPickups: CounterPreviewParcel[];
    counterTotal: number;
    basePath: string;
    stats: {
        parcels_in_hub: number;
        ready_pickup: number;
        dispatched_today: number;
    };
}

interface DynamicPrompt {
    action: string;
    prompt: string;
    next_status: string;
    color: string;
    expected_status: string;
    attempt_reference?: string | null;
    route_reference?: string | null;
    claim_reference?: string | null;
    requires_confirmation: boolean;
    manifest_url: string | null;
}

interface ScannedDeliveryResult {
    guidance: ParcelGuidance;
    registry_url?: string | null;
    id: number;
    tracking_number: string;
    status: string;
    delivery_type: string;
    destination_bin: string | null;
    current_hub: {
        id: number;
        name: string;
        code: string;
        tier: string;
    } | null;
    buyer: {
        name: string;
        phone: string;
        barangay: string;
        city: string;
        landmark?: string;
    };
    order: {
        order_number: string;
        total_amount: number;
        payment_method: string;
        items: Array<{
            name: string;
            quantity: number;
            price: number;
        }>;
    };
}

export default function ScanStation({
    activeHub,
    hubs,
    recentScans,
    pendingOriginIntake,
    counterPickups,
    counterTotal,
    basePath,
    stats,
}: ScanStationProps) {
    const [activeTab, setActiveTab] = useState<'scan' | 'counter' | 'logs'>('scan');
    const [barcodeInput, setBarcodeInput] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [audioEnabled, setAudioEnabled] = useState(true);

    // Dynamic prompt state
    const [lastResult, setLastResult] = useState<{
        prompt: DynamicPrompt;
        delivery: ScannedDeliveryResult;
        confirmed: boolean;
    } | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [scanMessage, setScanMessage] = useState<string | null>(null);
    const [sessionExpired, setSessionExpired] = useState(false);

    const barcodeInputRef = useRef<HTMLInputElement | null>(null);
    const pendingRequest = useRef<AbortController | null>(null);
    useOperationalRefresh(isSubmitting, ['activeHub', 'hubs', 'basePath', 'recentScans', 'pendingOriginIntake', 'counterPickups', 'counterTotal', 'stats']);
    useEffect(() => {
        setLastResult(null);
        setScanError(null);
        setScanMessage(null);
        setIsSubmitting(false);
        return () => { pendingRequest.current?.abort(); pendingRequest.current = null; };
    }, [activeHub?.id]);
    useEffect(() => {
        if (activeTab === 'scan') barcodeInputRef.current?.focus();
    }, [activeTab, activeHub?.id]);

    // Sound effect synthesizer via Web Audio API
    const playSound = (type: 'success' | 'error' | 'beep') => {
        if (!audioEnabled) return;
        try {
            const AudioCtx = window.AudioContext || (window as any).webkitAudioContext;
            if (!AudioCtx) return;
            const ctx = new AudioCtx();

            if (type === 'beep' || type === 'success') {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, ctx.currentTime);
                gain.gain.setValueAtTime(0.12, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.15);
            } else if (type === 'error') {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.35);
            }
        } catch {
            // Audio context not allowed or unsupported
        }
    };

    const runScan = async (body: Record<string, unknown>, confirming = false) => {
        if (pendingRequest.current || !activeHub) return;
        const request = new AbortController();
        pendingRequest.current = request;
        setIsSubmitting(true);
        setScanError(null);
        setScanMessage(null);
        setSessionExpired(false);
        if (!confirming) setLastResult(null);
        try {
            const data = await requestWaybill<{ prompt: DynamicPrompt; delivery: ScannedDeliveryResult; confirmed: boolean; message: string }>(
                `${basePath}/scan`, { ...body, hub_id: activeHub.id }, request.signal,
            );
            if (pendingRequest.current !== request || request.signal.aborted) return;
            playSound(data.confirmed ? 'success' : 'beep');
            setLastResult({ prompt: data.prompt, delivery: data.delivery, confirmed: Boolean(data.confirmed) });
            setScanMessage(data.message);
            setBarcodeInput('');
            if (confirming) {
                try { await refreshPage(); }
                catch { if (!request.signal.aborted) setScanError('The custody action was saved, but the queue could not refresh. Reload this scanner to see the latest records.'); }
            }
        } catch (error) {
            if (pendingRequest.current !== request || request.signal.aborted) return;
            playSound('error');
            setLastResult(null);
            setBarcodeInput(String(body.barcode ?? ''));
            setScanError(error instanceof Error ? error.message : 'The waybill could not be checked. Try again.');
            setSessionExpired(error instanceof WaybillRequestError && [401, 419].includes(error.status));
        } finally {
            if (pendingRequest.current === request) {
                pendingRequest.current = null;
                setIsSubmitting(false);
            }
        }
    };

    const handleBarcodeProcess = (barcode: string) => runScan({ barcode: waybillCode(barcode, window.location.origin) ?? barcode, mode: 'inspect' });
    const confirmScanAction = () => {
        if (!lastResult?.prompt.requires_confirmation || !lastResult.delivery.guidance.can_act || lastResult.delivery.guidance.action !== 'scan') return;
        return runScan({
            barcode: lastResult.delivery.tracking_number, mode: 'confirm',
            action: lastResult.prompt.action, expected_status: lastResult.prompt.expected_status,
            attempt_reference: lastResult.prompt.attempt_reference ?? undefined,
            route_reference: lastResult.prompt.route_reference ?? undefined,
            claim_reference: lastResult.prompt.claim_reference ?? undefined,
        }, true);
    };

    // Handle Manual Input Submit
    const handleInputSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (barcodeInput.trim()) {
            handleBarcodeProcess(barcodeInput);
        }
    };

    // Hub change handler
    const handleHubChange = (hubId: string) => {
        router.get(
            `${basePath}/scan`,
            { hub_id: hubId },
            { preserveState: false }
        );
    };

    return (
        <DashboardLayout
            title="Scan & Intake Workstation"
            subtitle="Real-time parcel intake scanning, destination route sorting, and customer claim handover"
        >
            <Head title="Scan Station — Logistics Hub — BagooPH" />

            {/* Quick KPI Overview Bar (Minimalist Architectural Stats) */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex items-center justify-between">
                    <div>
                        <div className="text-[10px] uppercase font-sans font-bold text-slate-500 tracking-wider">
                            Parcels in Station
                        </div>
                        <div className="text-xl font-bold font-sans text-slate-900 mt-0.5">
                            {stats.parcels_in_hub}
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-xs bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-700">
                        <Package className="w-4 h-4" />
                    </div>
                </div>

                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex items-center justify-between">
                    <div>
                        <div className="text-[10px] uppercase font-sans font-bold text-slate-500 tracking-wider">
                            Self-Pickup Staged
                        </div>
                        <div className="text-xl font-bold font-sans text-slate-900 mt-0.5">
                            {stats.ready_pickup}
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-xs bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-700">
                        <Boxes className="w-4 h-4" />
                    </div>
                </div>

                <div className="bg-white rounded-xs p-3.5 border border-slate-300 shadow-xs flex items-center justify-between">
                    <div>
                        <div className="text-[10px] uppercase font-sans font-bold text-slate-500 tracking-wider">
                            Dispatched Today
                        </div>
                        <div className="text-xl font-bold font-sans text-[#E00D42] mt-0.5">
                            {stats.dispatched_today}
                        </div>
                    </div>
                    <div className="w-8 h-8 rounded-xs bg-[#FDF2F4] border border-[#FCE7EA] flex items-center justify-center text-[#E00D42]">
                        <ScanLine className="w-4 h-4" />
                    </div>
                </div>
            </div>

            {pendingOriginIntake.length > 0 && (
                <div className="bg-amber-50 border border-amber-300 rounded-xs p-4 mb-5 shadow-xs">
                    <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-3">
                        <div>
                            <h3 className="text-sm font-bold text-amber-950 font-sans flex items-center gap-2">
                                <Clock className="w-4 h-4" />
                                Awaiting origin hub intake
                            </h3>
                            <p className="text-xs text-amber-900/80 mt-0.5">
                                Pickup riders have collected these parcels. Scan each waybill when it physically reaches {activeHub?.name}.
                            </p>
                        </div>
                        <span className="text-xs font-bold text-amber-950 whitespace-nowrap">
                            {pendingOriginIntake.length} waiting
                        </span>
                    </div>
                    <div className="space-y-2">
                        {pendingOriginIntake.map((parcel) => (
                            <button
                                type="button"
                                key={parcel.id}
                                onClick={() => {
                                    setBarcodeInput(parcel.tracking_number);
                                    barcodeInputRef.current?.focus();
                                }}
                                className="w-full text-left bg-white border border-amber-200 rounded-xs px-3 py-2 hover:border-[#E00D42] transition"
                            >
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5">
                                    <div>
                                        <div className="text-xs font-bold text-slate-900 font-sans">{parcel.tracking_number}</div>
                                        <div className="text-[11px] text-slate-600 mt-0.5">
                                            {parcel.item_names.join(', ') || 'Parcel contents not listed'} · Pickup rider: {parcel.rider_name}
                                        </div>
                                    </div>
                                    <div className="text-[10px] text-slate-500 whitespace-nowrap">{parcel.updated_at}</div>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* Navigation Tabs (Style Guide Standard 2px Rectangular) */}
            <div className="flex border-b border-slate-300 mb-5 gap-1 font-sans text-xs">
                <button
                    type="button"
                    onClick={() => setActiveTab('scan')}
                    className={`flex items-center gap-2 px-4 py-2.5 font-bold transition border-b-2 -mb-px ${
                        activeTab === 'scan'
                            ? 'border-[#E00D42] text-[#E00D42] bg-white'
                            : 'border-transparent text-slate-600 hover:text-slate-900'
                    }`}
                >
                    <ScanLine className="w-4 h-4" />
                    <span>Intake & Sort Station</span>
                </button>

                {activeHub && (activeHub.allows_self_pickup || counterTotal > 0) && (
                    <button
                        type="button"
                        onClick={() => setActiveTab('counter')}
                        className={`flex items-center gap-2 px-4 py-2.5 font-bold transition border-b-2 -mb-px ${
                            activeTab === 'counter'
                                ? 'border-[#E00D42] text-[#E00D42] bg-white'
                                : 'border-transparent text-slate-600 hover:text-slate-900'
                        }`}
                    >
                        <Boxes className="w-4 h-4" />
                        <span>Counter Release Desk</span>
                        {counterTotal > 0 && (
                            <span className="px-1.5 py-0.2 bg-slate-100 text-slate-800 text-[10px] rounded-xs border border-slate-300 font-sans font-bold">
                                {counterTotal}
                            </span>
                        )}
                    </button>
                )}

                <button
                    type="button"
                    onClick={() => setActiveTab('logs')}
                    className={`flex items-center gap-2 px-4 py-2.5 font-bold transition border-b-2 -mb-px ${
                        activeTab === 'logs'
                            ? 'border-[#E00D42] text-[#E00D42] bg-white'
                            : 'border-transparent text-slate-600 hover:text-slate-900'
                    }`}
                >
                    <Clock className="w-4 h-4" />
                    <span>Station Audit Log</span>
                </button>
            </div>

            {/* TAB 1: Scan Workstation (2-Column Bento Layout) */}
            {activeTab === 'scan' && (
                <div className="space-y-4">
                    {/* Error Banner */}
                    {scanError && (
                        <div role="alert" className="bg-red-50 border border-red-300 text-red-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
                            <AlertCircle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans">
                                <span className="font-bold">Scan Routing Error:</span> {scanError}
                                {sessionExpired && <button type="button" onClick={() => window.location.reload()}
                                    className="mt-2 block rounded-xs border border-red-300 bg-white px-3 py-2 font-semibold">Reload scanner</button>}
                            </div>
                        </div>
                    )}

                    {scanMessage && (
                        <div role="status" className="bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
                            <CheckCircle2 className="w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans">{scanMessage}</div>
                        </div>
                    )}

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        {/* LEFT COLUMN: Physical & Optical Barcode Scanner (col-span-6) */}
                        <div className="lg:col-span-6 space-y-4">
                            {/* Hardware Gun & Keyboard Input Card */}
                            <div className="bg-white rounded-xs border border-slate-300 p-4 sm:p-5 shadow-xs">
                                <div className="flex items-center justify-between mb-3">
                                    <label htmlFor="barcode-field" className="block text-xs font-bold text-slate-900 font-sans uppercase tracking-wider">
                                        Waybill Barcode Input
                                    </label>
                                    
                                    {/* Audio Feedback Toggle */}
                                    <button
                                        type="button"
                                        onClick={() => setAudioEnabled(!audioEnabled)}
                                        className={`p-1.5 rounded-xs border transition shadow-xs flex items-center gap-1 text-[11px] font-sans ${
                                            audioEnabled
                                                ? 'bg-slate-100 border-slate-300 text-slate-800'
                                                : 'bg-white border-slate-200 text-slate-400'
                                        }`}
                                        title={audioEnabled ? 'Sound Enabled' : 'Sound Muted'}
                                    >
                                        {audioEnabled ? <Volume2 className="w-3.5 h-3.5 text-slate-700" /> : <VolumeX className="w-3.5 h-3.5 text-slate-400" />}
                                        <span>{audioEnabled ? 'Audio ON' : 'Muted'}</span>
                                    </button>
                                </div>

                                <form onSubmit={handleInputSubmit} className="space-y-3">
                                    <div className="flex gap-2">
                                        <div className="relative flex-1">
                                            <ScanLine className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                            <input
                                                id="barcode-field"
                                                ref={barcodeInputRef}
                                                type="text"
                                                maxLength={255}
                                                autoComplete="off"
                                                spellCheck={false}
                                                value={barcodeInput}
                                                onChange={(e) => setBarcodeInput(e.target.value)}
                                                placeholder="Scan with laser gun or enter tracking..."
                                                disabled={isSubmitting}
                                                autoFocus
                                                className="w-full bg-white border border-slate-300 text-slate-900 placeholder-slate-400 rounded-xs pl-9 pr-3 py-2.5 text-xs font-sans focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] shadow-xs outline-hidden transition"
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={isSubmitting || !barcodeInput.trim()}
                                            className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:bg-slate-200 disabled:text-slate-400 text-white font-bold text-xs rounded-xs shadow-xs transition flex items-center gap-1.5 uppercase font-sans tracking-wider cursor-pointer"
                                        >
                                            {isSubmitting ? (
                                                <RefreshCw className="w-4 h-4 animate-spin" />
                                            ) : (
                                                <>
                                                    <span>Scan</span>
                                                    <ArrowRight className="w-4 h-4" />
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </form>

                            </div>

                            <WaybillCamera key={activeHub?.id} onScan={handleBarcodeProcess} disabled={isSubmitting}
                                instruction="Scan the tracking barcode or parcel QR to check the next permitted step." />
                        </div>

                        {/* RIGHT COLUMN: Live Routing Action & Intelligence HUD (col-span-6) */}
                        <div className="lg:col-span-6">
                            {lastResult ? (
                                <div className="bg-white rounded-xs border border-slate-300 shadow-xs p-4 sm:p-5 space-y-4">
                                    <div className="flex items-center justify-between border-b border-slate-200 pb-3">
                                        <span className="text-[11px] font-sans uppercase text-slate-500 tracking-wider font-bold">
                                            Routing Directive
                                        </span>
                                        <span className="text-xs font-sans bg-slate-100 text-slate-800 px-2 py-0.5 rounded-xs border border-slate-300 font-bold">
                                            Status: {lastResult.delivery.status.replace(/_/g, ' ')}
                                        </span>
                                    </div>

                                    {/* Prominent Action Directive Banner */}
                                    <div className="rounded-xs p-4 text-center bg-slate-900 text-white border border-slate-800 shadow-xs">
                                        <div className="text-[10px] uppercase font-sans font-bold tracking-widest text-slate-400 mb-1">
                                            {lastResult.confirmed ? 'Custody action saved' : 'Waybill checked'}
                                        </div>
                                        <div className="text-lg sm:text-xl font-black font-sans uppercase tracking-tight">
                                            {lastResult.delivery.guidance.next_step}
                                        </div>
                                    </div>

                                    <div className="space-y-3"><HubParcelState guidance={lastResult.delivery.guidance} /><HubParcelNextStep guidance={lastResult.delivery.guidance} hideLink={lastResult.delivery.guidance.action === 'scan'} /></div>

                                    {lastResult.prompt.manifest_url && !lastResult.delivery.guidance.href && lastResult.delivery.guidance.can_act && <div className="rounded-xs border border-slate-300 bg-white p-3.5"><p className="mb-3 text-xs text-slate-700">Use the recorded manifest to load, dispatch or receive this parcel. Its actual list and scans establish transport custody.</p><Link className="text-sm font-semibold text-[#E00D42]" href={lastResult.prompt.manifest_url}>Open manifests</Link></div>}

                                    {lastResult.prompt.requires_confirmation && lastResult.delivery.guidance.can_act && lastResult.delivery.guidance.action === 'scan' && (
                                        <div className="rounded-xs border border-amber-300 bg-amber-50 p-3.5">
                                            <p className="text-xs text-amber-900 font-sans mb-3">
                                                Verify the parcel and physical handoff before recording this custody action.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={confirmScanAction}
                                                disabled={isSubmitting}
                                                className="w-full rounded-xs bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836] disabled:bg-slate-300"
                                            >
                                                {isSubmitting
                                                    ? 'Recording action...'
                                                    : lastResult.confirmed
                                                        ? 'Confirm next facility action'
                                                        : 'Confirm custody action'}
                                            </button>
                                        </div>
                                    )}

                                    <div className="flex flex-wrap gap-2">
                                        <button type="button" disabled={isSubmitting} onClick={() => handleBarcodeProcess(lastResult.delivery.tracking_number)}
                                            className="rounded-xs border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 disabled:opacity-50">Check for updates</button>
                                        {lastResult.delivery.registry_url && !lastResult.delivery.guidance.can_act && <Link href={lastResult.delivery.registry_url}
                                            className="rounded-xs border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-[#E00D42]">View parcel records</Link>}
                                        <button type="button" disabled={isSubmitting} onClick={() => {
                                            setLastResult(null); setScanMessage(null); setBarcodeInput('');
                                            barcodeInputRef.current?.focus();
                                        }} className="rounded-xs border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 disabled:opacity-50">Scan next parcel</button>
                                    </div>

                                    {/* Assigned Sort Bin */}
                                    <div className="flex items-center justify-between p-3.5 bg-slate-50 rounded-xs border border-slate-300">
                                        <div className="flex items-center gap-2.5">
                                            <Layers className="w-4 h-4 text-slate-700" />
                                            <div>
                                                <div className="text-[10px] uppercase text-slate-500 font-sans font-bold">Destination Bin</div>
                                                <div className="text-sm font-bold text-slate-900 font-sans">
                                                    {lastResult.delivery.destination_bin?.trim() ? lastResult.delivery.destination_bin : lastResult.delivery.delivery_type === 'hub_self_pickup' ? 'Shelf not recorded' : 'Bin not recorded'}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div className="text-[10px] uppercase text-slate-500 font-sans font-bold">Waybill Code</div>
                                            <div className="text-xs font-bold font-sans text-slate-900">
                                                {lastResult.delivery.tracking_number}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Recipient & Order Details */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        <div className="bg-slate-50 p-3 rounded-xs border border-slate-200 space-y-1">
                                            <div className="text-slate-500 text-[10px] uppercase font-sans font-bold">Customer</div>
                                            <div className="font-bold text-slate-900">{lastResult.delivery.buyer.name}</div>
                                            <div className="text-slate-600 text-[11px]">
                                                {lastResult.delivery.buyer.barangay}, {lastResult.delivery.buyer.city}
                                            </div>
                                            {lastResult.delivery.buyer.landmark && (
                                                <div className="text-slate-700 text-[10px] font-sans">
                                                    Landmark: {lastResult.delivery.buyer.landmark}
                                                </div>
                                            )}
                                        </div>

                                        <div className="bg-slate-50 p-3 rounded-xs border border-slate-200 space-y-1">
                                            <div className="text-slate-500 text-[10px] uppercase font-sans font-bold">Order Summary</div>
                                            <div className="font-bold text-slate-900">
                                                ₱{lastResult.delivery.order.total_amount.toFixed(2)}{' '}
                                                <span className="text-[10px] font-sans uppercase text-slate-600">
                                                    ({lastResult.delivery.order.payment_method})
                                                </span>
                                            </div>
                                            <div className="text-slate-600 text-[11px]">
                                                {lastResult.delivery.order.items.length} item(s):{' '}
                                                {lastResult.delivery.order.items.map((i) => `${i.name} (x${i.quantity})`).join(', ')}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="pt-1 flex items-center gap-1.5 text-slate-600 text-[11px] font-sans">
                                        <ShieldCheck className="w-3.5 h-3.5 text-slate-700" />
                                        <span>
                                            {lastResult.confirmed
                                                ? 'Recorded custody action retained'
                                                : lastResult.prompt.requires_confirmation && lastResult.delivery.guidance.can_act
                                                    ? 'Waybill verified; confirm the action to update custody'
                                                    : 'Waybill verified; follow the guidance above'}
                                        </span>
                                    </div>
                                </div>
                            ) : (
                                <div className="bg-white rounded-xs border border-slate-300 p-8 text-center shadow-xs">
                                    <ScanLine className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                                    <h4 className="font-bold text-sm text-slate-900 font-sans">Awaiting Next Barcode Scan</h4>
                                    <p className="text-xs text-slate-500 mt-1 max-w-sm mx-auto">
                                        Scan a parcel waybill label or enter a tracking code on the left to trigger instant road routing & destination sort binning.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* TAB 2: Secure counter preview */}
            {activeTab === 'counter' && activeHub && <HubCounterPreview parcels={counterPickups} total={counterTotal} hubId={activeHub.id} basePath={basePath} />}

            {/* TAB 3: Station Audit Log */}
            {activeTab === 'logs' && (
                <div className="space-y-3">
                    <div className="bg-white rounded-xs border border-slate-300 p-4 shadow-xs">
                        <h3 className="font-bold text-sm text-slate-900 flex items-center gap-2 font-sans">
                            <Clock className="w-4 h-4 text-slate-700" />
                            Recent Station Scans & Routing Audits
                        </h3>
                        <p className="text-xs text-slate-500 mt-0.5">
                            Last 15 scans executed at this logistics facility workstation.
                        </p>
                    </div>

                    <div className="space-y-2">
                        {recentScans.length === 0 ? (
                            <div className="p-8 text-center text-slate-500 bg-white rounded-xs border border-slate-300 shadow-xs font-sans text-xs">
                                No recent scans recorded at this station yet.
                            </div>
                        ) : (
                            recentScans.map((scan) => (
                                <div
                                    key={scan.id}
                                    className="bg-white rounded-xs border border-slate-300 p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs shadow-xs hover:border-slate-400 transition"
                                >
                                    <div>
                                        <div className="flex items-center gap-2 font-sans">
                                            <span className="font-bold text-slate-900">
                                                {scan.tracking_number}
                                            </span>
                                            <span className="text-[10px] px-1.5 py-0.5 rounded-xs bg-slate-100 text-slate-800 font-bold border border-slate-300">
                                                {scan.checkpoint_type}
                                            </span>
                                            <span className="text-[10px] text-slate-700 font-bold bg-slate-50 px-1.5 py-0.5 rounded-xs border border-slate-200">
                                                {scan.destination_bin?.trim() ? scan.destination_bin : scan.delivery_type === 'hub_self_pickup' ? 'Shelf not recorded' : 'Bin not recorded'}
                                            </span>
                                        </div>
                                        <div className="text-slate-600 text-[11px] mt-1">{scan.notes}</div>
                                    </div>
                                    <div className="text-right text-[11px] text-slate-500 font-sans">
                                        <div className="font-bold text-slate-800">{new Date(scan.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                                        <div className="text-slate-400">{scan.scanned_by}</div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
