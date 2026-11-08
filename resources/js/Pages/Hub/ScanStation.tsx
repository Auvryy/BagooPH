import { refreshPage } from '@/utils/accountRequests';
import React, { useState, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { HubNextStep, HubPageHeader, HubRoute } from '@/Components/Logistics/Workspace';
import HubConveyorRail from '@/Components/Logistics/HubConveyorRail';
import HubCounterPreview, { CounterPreviewParcel } from '@/Components/HubCounterPreview';
import { HubParcelAction, HubParcelState, ParcelGuidance } from '@/Components/HubParcelGuidance';
import WaybillCamera from '@/Components/WaybillCamera';
import { requestWaybill, waybillCode, WaybillRequestError } from '@/utils/waybillScan';
import useOperationalRefresh from '@/hooks/useOperationalRefresh';
import { useBarcodeWedge } from '@/hooks/useBarcodeWedge';
import { scannerAudio } from '@/utils/scannerAudio';
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
    Scale,
    ShieldCheck,
    Boxes,
    CornerDownRight,
    Truck,
    Send,
    Zap,
    Lock,
    Tag,
    XCircle,
    Printer,
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
    routing_target?: { id: number; name: string; code: string; tier: string } | null;
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

interface MasterBagScanResult {
    reference: string;
    seal_number: string;
    source_hub: string;
    destination_hub: string;
    parcel_count: number;
    status: string;
    parcels?: Array<{
        tracking_number: string;
        order_number?: string;
        status: string;
    }>;
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
    const [fastDispatching, setFastDispatching] = useState(false);
    const [audioEnabled, setAudioEnabled] = useState(true);

    // Dynamic prompt state
    const [lastResult, setLastResult] = useState<{
        prompt: DynamicPrompt;
        delivery: ScannedDeliveryResult;
        confirmed: boolean;
        alreadyReceived?: boolean;
    } | null>(null);
    const [masterBagResult, setMasterBagResult] = useState<MasterBagScanResult | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [recoveryUrl, setRecoveryUrl] = useState<string | null>(null);
    const [scanMessage, setScanMessage] = useState<string | null>(null);
    const [returnResult, setReturnResult] = useState<{ reference: string; barcode: string; href: string; status: string } | null>(null);
    const [sessionExpired, setSessionExpired] = useState(false);

    // DWS (Dimension, Weight, Scan) audit state
    const [dwsLength, setDwsLength] = useState<string>('');
    const [dwsWidth, setDwsWidth] = useState<string>('');
    const [dwsHeight, setDwsHeight] = useState<string>('');
    const [dwsWeightGrams, setDwsWeightGrams] = useState<string>('');

    const dwsVolumetricKg = (Number(dwsLength) > 0 && Number(dwsWidth) > 0 && Number(dwsHeight) > 0)
        ? (Number(dwsLength) * Number(dwsWidth) * Number(dwsHeight)) / 3500
        : 0;
    const dwsActualKg = Number(dwsWeightGrams) > 0 ? Number(dwsWeightGrams) / 1000 : 0;
    const dwsBillableKg = Math.max(dwsActualKg, dwsVolumetricKg);

    const barcodeInputRef = useRef<HTMLInputElement | null>(null);
    const pendingRequest = useRef<AbortController | null>(null);
    useOperationalRefresh(isSubmitting, ['activeHub', 'hubs', 'basePath', 'recentScans', 'pendingOriginIntake', 'counterPickups', 'counterTotal', 'stats']);
    useEffect(() => {
        setLastResult(null);
        setMasterBagResult(null);
        setReturnResult(null);
        setScanError(null);
        setRecoveryUrl(null);
        setScanMessage(null);
        setDwsLength('');
        setDwsWidth('');
        setDwsHeight('');
        setDwsWeightGrams('');
        setIsSubmitting(false);
        return () => { pendingRequest.current?.abort(); pendingRequest.current = null; };
    }, [activeHub?.id]);
    useEffect(() => {
        if (activeTab === 'scan') barcodeInputRef.current?.focus();
    }, [activeTab, activeHub?.id]);

    useEffect(() => {
        scannerAudio.setEnabled(audioEnabled);
    }, [audioEnabled]);

    // Sound effect synthesizer via Web Audio API
    const playSound = (type: 'success' | 'error' | 'beep' | 'seal' | 'dispatch') => {
        if (!audioEnabled) return;
        try {
            if (type === 'success') scannerAudio.playSuccess();
            else if (type === 'error') scannerAudio.playError();
            else if (type === 'beep') scannerAudio.playScanBeep();
            else if (type === 'seal') scannerAudio.playSealLock();
            else if (type === 'dispatch') scannerAudio.playDispatch();
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
        setRecoveryUrl(null);
        setScanMessage(null);
        setSessionExpired(false);
        if (!confirming) { setLastResult(null); setReturnResult(null); setMasterBagResult(null); }
        try {
            const data = await requestWaybill<{
                prompt?: DynamicPrompt;
                delivery?: ScannedDeliveryResult;
                confirmed: boolean;
                message: string;
                already_received?: boolean;
                is_master_bag?: boolean;
                bag?: MasterBagScanResult;
                return_case?: { reference: string; barcode: string; href: string; status: string };
            }>(
                `${basePath}/scan`, { ...body, hub_id: activeHub.id }, request.signal,
            );
            if (pendingRequest.current !== request || request.signal.aborted) return;

            if (data.is_master_bag && data.bag) {
                setMasterBagResult(data.bag);
                setLastResult(null);
                setReturnResult(null);
                playSound(data.confirmed ? 'success' : 'beep');
            } else if (data.return_case) {
                setReturnResult(data.return_case);
                setLastResult(null);
                setMasterBagResult(null);
                playSound(data.confirmed ? 'success' : 'beep');
            } else if (data.delivery && data.prompt) {
                setReturnResult(null);
                setMasterBagResult(null);
                setLastResult({ prompt: data.prompt, delivery: data.delivery, confirmed: Boolean(data.confirmed), alreadyReceived: data.already_received });
                playSound(data.confirmed ? 'success' : 'beep');
            }
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
            setMasterBagResult(null);
            setBarcodeInput(String(body.barcode ?? ''));
            setScanError(error instanceof Error ? error.message : 'The barcode could not be checked. Try again.');
            setRecoveryUrl(error instanceof WaybillRequestError ? error.recoveryUrl : null);
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

    const confirmMasterBagIngestion = () => {
        if (!masterBagResult) return;
        return runScan({
            barcode: masterBagResult.reference,
            mode: 'confirm',
            action: 'INGEST_MASTER_BAG',
            expected_status: 'in_transit',
        }, true);
    };

    const triggerFastDispatch = async (destinationHubId?: number) => {
        const destId = destinationHubId || lastResult?.delivery?.routing_target?.id;
        if (!destId || !activeHub || fastDispatching) return;

        setFastDispatching(true);
        setScanError(null);
        setScanMessage(null);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content
                || ((window as unknown as { csrfToken?: string }).csrfToken ?? '');

            const response = await fetch(`${basePath}/scan/fast-dispatch`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    destination_hub_id: destId,
                    delivery_ids: lastResult?.delivery?.id ? [lastResult.delivery.id] : undefined,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || data.message || 'Fast dispatch failed');
            }

            playSound('dispatch');
            setScanMessage(data.message);
            setLastResult(null);
            setMasterBagResult(null);
            await refreshPage();
        } catch (err) {
            playSound('error');
            setScanError(err instanceof Error ? err.message : 'Fast dispatch failed');
        } finally {
            setFastDispatching(false);
        }
    };

    // Industrial Hardware Wedge Scanner Hook
    useBarcodeWedge({
        enabled: activeTab === 'scan',
        onScan: (scannedCode) => {
            const code = scannedCode.trim();
            if (!code) return;

            // Action Command Barcodes
            if (code === 'CMD-CONFIRM') {
                scannerAudio.playCommand();
                if (masterBagResult && masterBagResult.status !== 'received') {
                    confirmMasterBagIngestion();
                } else if (lastResult?.prompt.requires_confirmation) {
                    confirmScanAction();
                }
                return;
            }

            if (code === 'CMD-DISPATCH') {
                scannerAudio.playCommand();
                triggerFastDispatch();
                return;
            }

            if (code === 'CMD-CLEAR') {
                scannerAudio.playCommand();
                setBarcodeInput('');
                setLastResult(null);
                setMasterBagResult(null);
                setScanError(null);
                setScanMessage(null);
                barcodeInputRef.current?.focus();
                return;
            }

            // Normal Barcode (Parcel Waybill or Master Bag BAG-)
            handleBarcodeProcess(code);
        },
    });

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
            title="Scan station"
            subtitle="Scan a waybill, check its next stop and confirm the physical handover"
        >
            <Head title="Scan Station — Logistics Hub — BagooPH" />
            <HubPageHeader title="Scan station" description="Scan the parcel in front of you. Check the current holder and next stop, then confirm only the handover you actually received." icon={ScanLine} />

            {/* Scanner views - Notion Segmented Pill Bar */}
            <div role="group" aria-label="Scanner views" className="mb-6 inline-flex p-1 bg-zinc-100 rounded-xl font-sans text-xs max-w-full overflow-x-auto">
                <button
                    type="button"
                    onClick={() => setActiveTab('scan')}
                    aria-pressed={activeTab === 'scan'}
                    className={`flex items-center gap-2 px-4 py-2 rounded-lg font-semibold transition cursor-pointer shrink-0 ${
                        activeTab === 'scan'
                            ? 'bg-white text-zinc-900 shadow-xs'
                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
                    }`}
                >
                    <ScanLine className="w-4 h-4" />
                    <span>Scan parcels</span>
                </button>

                {activeHub && (activeHub.allows_self_pickup || counterTotal > 0) && (
                    <button
                        type="button"
                        onClick={() => setActiveTab('counter')}
                        aria-pressed={activeTab === 'counter'}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg font-semibold transition cursor-pointer shrink-0 ${
                            activeTab === 'counter'
                                ? 'bg-white text-zinc-900 shadow-xs'
                                : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
                        }`}
                    >
                        <Boxes className="w-4 h-4" />
                        <span>Counter queue</span>
                        {counterTotal > 0 && (
                            <span className="px-2 py-0.5 bg-zinc-100 text-zinc-900 text-xs rounded-lg border border-zinc-300 font-sans font-bold">
                                {counterTotal}
                            </span>
                        )}
                    </button>
                )}

                <button
                    type="button"
                    onClick={() => setActiveTab('logs')}
                    aria-pressed={activeTab === 'logs'}
                    className={`flex items-center gap-2 px-4 py-2 rounded-lg font-semibold transition cursor-pointer shrink-0 ${
                        activeTab === 'logs'
                            ? 'bg-white text-zinc-900 shadow-xs'
                            : 'text-zinc-600 hover:text-zinc-900 hover:bg-zinc-200/50'
                    }`}
                >
                    <Clock className="w-4 h-4" />
                    <span>Scan history</span>
                </button>
            </div>

            {/* TAB 1: Scan Workstation (2-Column Bento Layout) */}
            {activeTab === 'scan' && (
                <div className="space-y-4">
                    {/* Error Banner */}
                    {scanError && (
                        <div role="alert" className="bg-red-50 border border-red-200 text-red-900 rounded-xl p-4 flex items-start gap-3 shadow-xs">
                            <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans leading-relaxed">
                                <span className="font-bold">Scan Routing Error:</span> {scanError}
                                {recoveryUrl && <Link href={recoveryUrl} className="mt-2 block font-semibold underline underline-offset-4 text-red-700 hover:text-red-900">Open Parcel recovery</Link>}
                                {sessionExpired && <button type="button" onClick={() => window.location.reload()}
                                    className="mt-2 block rounded-xl border border-red-300 bg-white px-3 py-1.5 font-semibold text-red-800 shadow-xs hover:bg-red-50 transition cursor-pointer">Reload scanner</button>}
                            </div>
                        </div>
                    )}

                    {scanMessage && (
                        <div role="status" className={`${lastResult?.alreadyReceived ? 'bg-amber-50 border-amber-200 text-amber-900' : 'bg-emerald-50 border-emerald-200 text-emerald-900'} border rounded-xl p-4 flex items-start gap-3 shadow-xs`}>
                            <CheckCircle2 className={`w-5 h-5 ${lastResult?.alreadyReceived ? 'text-amber-700' : 'text-emerald-600'} flex-shrink-0 mt-0.5`} />
                            <div className="text-xs font-medium font-sans leading-relaxed">{scanMessage}</div>
                        </div>
                    )}

                    {returnResult && <section className="mb-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-xs"><h2 className="font-bold text-zinc-900">Buyer return {returnResult.reference}</h2><p className="mt-2 break-all text-xs text-zinc-600">{returnResult.barcode}</p><p className="mt-2 text-xs text-zinc-600">This scan identifies the return. Record its actual intake or manifest receipt from the case.</p><Link href={returnResult.href} className="mt-4 inline-flex rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-[#C20836] transition cursor-pointer">Open buyer return</Link></section>}

                    {/* Interactive Animated Conveyor Rail (Hub 6 Stations) */}
                    <HubConveyorRail
                        currentStatus={lastResult?.delivery?.status || ''}
                        deliveryType={lastResult?.delivery?.delivery_type || 'standard'}
                        basePath={basePath}
                        isScanning={isSubmitting}
                        trackingNumber={lastResult?.delivery?.tracking_number}
                    />

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        {/* LEFT COLUMN: Physical & Optical Barcode Scanner (col-span-6) */}
                        <div className="lg:col-span-6 space-y-4">
                            {/* Hardware Gun & Keyboard Input Card */}
                            <div className="bg-white rounded-xl border border-zinc-200 p-6 shadow-xs">
                                <div className="flex items-center justify-between mb-3">
                                    <label htmlFor="barcode-field" className="block text-xs font-bold text-zinc-700 font-sans uppercase tracking-wider">
                                        Scan or enter a waybill
                                    </label>
                                    
                                    {/* Audio Feedback Toggle */}
                                    <button
                                        type="button"
                                        onClick={() => setAudioEnabled(!audioEnabled)}
                                        className={`px-3 py-1.5 rounded-xl border transition shadow-xs flex items-center gap-1.5 text-xs font-semibold font-sans cursor-pointer ${
                                            audioEnabled
                                                ? 'bg-zinc-50 border-zinc-200 text-zinc-800'
                                                : 'bg-white border-zinc-200 text-zinc-400'
                                        }`}
                                        title={audioEnabled ? 'Sound Enabled' : 'Sound Muted'}
                                    >
                                        {audioEnabled ? <Volume2 className="w-3.5 h-3.5 text-zinc-700" /> : <VolumeX className="w-3.5 h-3.5 text-zinc-400" />}
                                        <span>{audioEnabled ? 'Audio ON' : 'Muted'}</span>
                                    </button>
                                </div>

                                <form onSubmit={handleInputSubmit} className="space-y-3">
                                    <div className="flex gap-2.5">
                                        <div className="relative flex-1">
                                            <ScanLine className="w-4 h-4 text-zinc-400 absolute left-3 top-1/2 -translate-y-1/2" />
                                            <input
                                                id="barcode-field"
                                                ref={barcodeInputRef}
                                                type="text"
                                                maxLength={255}
                                                autoComplete="off"
                                                spellCheck={false}
                                                value={barcodeInput}
                                                onChange={(e) => setBarcodeInput(e.target.value)}
                                                placeholder="Ready for your scanner — or type the tracking code"
                                                disabled={isSubmitting}
                                                autoFocus
                                                className="min-h-12 w-full bg-white border border-zinc-300 text-zinc-900 placeholder-zinc-400 rounded-xl pl-9 pr-3 py-2.5 text-sm font-sans focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 shadow-xs outline-hidden transition"
                                            />
                                        </div>
                                        <button
                                            type="submit"
                                            disabled={isSubmitting || !barcodeInput.trim()}
                                            className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] disabled:bg-zinc-200 disabled:text-zinc-400 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center gap-1.5 uppercase font-sans tracking-wider cursor-pointer"
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

                                <div className="mt-4 flex flex-wrap items-center justify-between text-xs font-mono text-zinc-500 border-t border-zinc-100 pt-3">
                                    <span className="flex items-center gap-1 text-zinc-800 font-semibold">
                                        <Zap className="h-3.5 w-3.5 text-amber-500 fill-current" />
                                        Hardware Laser Wedge Active
                                    </span>
                                    <span className="text-zinc-500 flex items-center gap-1.5 flex-wrap">
                                        Commands: <span className="text-zinc-800 font-bold">[CMD-CONFIRM]</span> · <span className="text-zinc-800 font-bold">[CMD-DISPATCH]</span> · <span className="text-zinc-800 font-bold">[CMD-CLEAR]</span>
                                        <a
                                            href={`${basePath}/command-sheet`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1 text-xs text-[#E00D42] hover:underline font-semibold ml-1"
                                        >
                                            <Printer className="h-3 w-3" />
                                            Print Sheet
                                        </a>
                                    </span>
                                </div>
                            </div>

                            <WaybillCamera key={activeHub?.id} onScan={handleBarcodeProcess} disabled={isSubmitting}
                                instruction="Scan the tracking barcode or parcel QR to check the next permitted step." />
                        </div>

                        {/* RIGHT COLUMN: Live Routing Action & Intelligence HUD (col-span-6) */}
                        <div className="lg:col-span-6 space-y-4">
                            {/* MASTER BAG INGESTION CARD */}
                            {masterBagResult && (
                                <div className="bg-white rounded-xl border border-indigo-200 shadow-xs p-6 space-y-4">
                                    <div className="flex items-center justify-between border-b border-indigo-100 pb-3">
                                        <div className="flex items-center gap-2.5">
                                            <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold">
                                                <Boxes className="h-5 w-5" />
                                            </span>
                                            <div>
                                                <span className="text-xs font-bold uppercase tracking-wider text-[#1C1A17] block">
                                                    Master Consolidation Bag
                                                </span>
                                                <span className="text-xs text-zinc-500 font-medium flex items-center mt-0.5">
                                                    From: {masterBagResult.source_hub} <ArrowRight className="h-3 w-3 inline mx-1.5 text-zinc-400" /> {masterBagResult.destination_hub}
                                                </span>
                                            </div>
                                        </div>
                                        <span className={`text-xs px-2.5 py-1 rounded-full font-bold uppercase font-mono ${
                                            masterBagResult.status === 'received'
                                                ? 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                                                : 'bg-amber-100 text-amber-800 border border-amber-300'
                                        }`}>
                                            {masterBagResult.status}
                                        </span>
                                    </div>

                                    <div className="rounded-xl bg-[#1C1A17] text-white p-5 text-center shadow-xs">
                                        <p className="text-xs font-mono tracking-wider text-stone-400 uppercase font-semibold">Master Bag Tag</p>
                                        <p className="text-2xl font-black font-mono tracking-wider text-emerald-400 mt-1">{masterBagResult.reference}</p>
                                        <p className="text-xs font-mono text-stone-300 mt-1.5">
                                            Numbered Tamper Seal: <span className="text-amber-300 font-bold">{masterBagResult.seal_number || 'N/A'}</span>
                                        </p>
                                    </div>

                                    {masterBagResult.status !== 'received' ? (
                                        <div className="space-y-2">
                                            <button
                                                type="button"
                                                disabled={isSubmitting}
                                                onClick={confirmMasterBagIngestion}
                                                className="w-full rounded-xl bg-emerald-600 hover:bg-emerald-700 active:scale-98 text-white px-5 py-3 text-sm font-bold shadow-xs transition flex items-center justify-center gap-2 cursor-pointer"
                                            >
                                                <Zap className="h-4 w-4 fill-current text-white" />
                                                <span>{isSubmitting ? 'Ingesting Nested Parcels…' : `Ingest All ${masterBagResult.parcel_count} Nested Parcels`}</span>
                                            </button>
                                            <p className="text-center text-xs text-zinc-500 font-mono">or scan hardware barcode [CMD-CONFIRM]</p>
                                        </div>
                                    ) : (
                                        <div className="rounded-xl bg-emerald-50 border border-emerald-200 p-3.5 text-xs text-emerald-900 flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-emerald-600 shrink-0" />
                                            <span>All parcels received into hub custody and queued for local sorting.</span>
                                        </div>
                                    )}

                                    {masterBagResult.parcels && masterBagResult.parcels.length > 0 && (
                                        <div className="border-t border-zinc-100 pt-3 space-y-1.5">
                                            <p className="text-xs font-bold text-zinc-900">Contained Parcels ({masterBagResult.parcels.length})</p>
                                            <div className="max-h-36 overflow-y-auto divide-y divide-zinc-100 text-xs font-mono">
                                                {masterBagResult.parcels.map((p, idx) => (
                                                    <div key={idx} className="py-1.5 flex items-center justify-between">
                                                        <span className="text-[#E00D42] font-semibold">{p.tracking_number}</span>
                                                        <span className="text-zinc-500 text-xs">{p.order_number ? `#${p.order_number}` : ''}</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    <button
                                        type="button"
                                        disabled={isSubmitting}
                                        onClick={() => { setMasterBagResult(null); setBarcodeInput(''); barcodeInputRef.current?.focus(); }}
                                        className="w-full rounded-xl border border-zinc-300 bg-white py-2.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 transition shadow-xs cursor-pointer"
                                    >
                                        Scan Next Bag / Parcel
                                    </button>
                                </div>
                            )}

                            {lastResult ? (
                                <div className="bg-white rounded-xl border border-zinc-200 shadow-xs p-6 space-y-5">
                                    {/* Prominent High-Contrast Route Target Badge */}
                                    {lastResult.delivery.routing_target && (
                                        <div className="rounded-xl border border-stone-800 bg-[#1C1A17] text-white p-5 shadow-xs flex items-center justify-between">
                                            <div>
                                                <span className="text-xs font-mono font-bold tracking-wider uppercase text-amber-400">
                                                    Route Target Facility
                                                </span>
                                                <div className="text-xl sm:text-2xl font-black font-mono tracking-tight text-white mt-1">
                                                    {lastResult.delivery.routing_target.name}
                                                </div>
                                                <div className="text-xs text-stone-300 font-mono mt-0.5">
                                                    CODE: {lastResult.delivery.routing_target.code} · TIER: {lastResult.delivery.routing_target.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <span className="inline-flex items-center justify-center rounded-xl bg-emerald-500/20 px-3.5 py-1.5 font-mono text-xs font-bold text-emerald-300 border border-emerald-500/30">
                                                    {lastResult.delivery.destination_bin ? `BIN: ${lastResult.delivery.destination_bin}` : 'SORT QUEUE'}
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    <div className="flex items-center justify-between border-b border-zinc-200 pb-3">
                                        <span className="text-xs font-sans uppercase text-zinc-500 tracking-wider font-bold">
                                            Parcel result
                                        </span>
                                        <span className="text-xs font-sans bg-zinc-100 text-zinc-900 px-3 py-1 rounded-xl border border-zinc-200 font-bold">
                                            Status: {lastResult.delivery.status.replace(/_/g, ' ')}
                                        </span>
                                    </div>

                                    <HubNextStep title={lastResult.delivery.guidance.next_step} tone={lastResult.delivery.guidance.blocker ? 'warning' : 'brand'} icon={ArrowRight}><p><span className="font-medium text-[#1C1A17]">Next person:</span> {lastResult.delivery.guidance.responsible}</p>{lastResult.delivery.guidance.blocker && <p className="mt-1 text-amber-800">{lastResult.delivery.guidance.blocker}</p>}</HubNextStep>
                                    <HubParcelState guidance={lastResult.delivery.guidance} />
                                    {lastResult.delivery.routing_target && activeHub && <HubRoute stops={[activeHub,lastResult.delivery.routing_target]} label="Planned next hub" />}

                                    {/* Prominent Next Action Card for Manifest Transfer */}
                                    {(lastResult.delivery.guidance.action === 'manifest' || lastResult.prompt.manifest_url) && lastResult.delivery.guidance.can_act && (
                                        <div className="rounded-xl border border-rose-200 bg-rose-50/60 p-6 shadow-xs space-y-4">
                                            <div className="flex items-start gap-3.5">
                                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[#E00D42] text-white shadow-xs">
                                                    <Truck className="h-5 w-5" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-bold uppercase tracking-wider text-[#C20836]">
                                                             Next Operational Step
                                                        </span>
                                                        {lastResult.alreadyReceived && (
                                                            <span className="rounded-lg bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800">
                                                                Intake Verified
                                                            </span>
                                                        )}
                                                    </div>
                                                    <h3 className="mt-1 text-base font-bold text-zinc-900">
                                                        Dispatch on Vehicle Transfer Manifest
                                                    </h3>
                                                    <p className="mt-1 text-xs text-zinc-600 leading-relaxed font-medium">
                                                        Ready for outbound transfer to <strong>{lastResult.delivery.routing_target?.name || 'the next facility'}</strong>.
                                                    </p>
                                                </div>
                                            </div>
                                            <div className="pt-2 space-y-2.5">
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <button
                                                        type="button"
                                                        disabled={fastDispatching || isSubmitting}
                                                        onClick={() => triggerFastDispatch(lastResult.delivery.routing_target?.id)}
                                                        className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-5 py-3 text-sm font-bold text-white shadow-xs hover:bg-[#C20836] transition cursor-pointer active:scale-98 disabled:opacity-50"
                                                    >
                                                        <Zap className="h-4 w-4 fill-current text-white" />
                                                        <span>{fastDispatching ? 'Creating Bag & Manifest…' : `1-Click Fast Dispatch to ${lastResult.delivery.routing_target?.code || 'Next Hub'}`}</span>
                                                    </button>
                                                    <span className="text-xs text-zinc-500 font-mono">or scan [CMD-DISPATCH]</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Link
                                                        href={lastResult.prompt.manifest_url || lastResult.delivery.guidance.href || '/hub/manifests'}
                                                        className="inline-flex items-center gap-1.5 rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-800 hover:bg-zinc-50 transition shadow-xs"
                                                    >
                                                        <span>Advanced Manifest Workstation</span>
                                                        <ArrowRight className="h-3 w-3" />
                                                    </Link>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {lastResult.delivery.guidance.action !== 'scan' && lastResult.delivery.guidance.action !== 'manifest' && <HubParcelAction guidance={lastResult.delivery.guidance} />}

                                    {lastResult.prompt.requires_confirmation && lastResult.delivery.guidance.can_act && lastResult.delivery.guidance.action === 'scan' && (
                                        <div className="rounded-xl border border-amber-300 bg-amber-50/80 p-5 shadow-xs">
                                            <p className="text-xs text-amber-900 font-medium mb-3">
                                                Confirm physical parcel receipt at this station.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={confirmScanAction}
                                                disabled={isSubmitting}
                                                className="w-full rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs font-bold text-white hover:bg-[#C20836] disabled:bg-zinc-300 shadow-xs cursor-pointer transition"
                                            >
                                                {isSubmitting
                                                    ? 'Recording action...'
                                                    : lastResult.confirmed
                                                        ? 'Confirm next facility action'
                                                        : 'Confirm custody action'}
                                            </button>
                                        </div>
                                    )}

                                    <div className="flex flex-wrap gap-2.5">
                                        <button type="button" disabled={isSubmitting} onClick={() => handleBarcodeProcess(lastResult.delivery.tracking_number)}
                                            className="rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-800 disabled:opacity-50 shadow-xs hover:bg-zinc-50 transition cursor-pointer">Check for updates</button>
                                        {lastResult.delivery.registry_url && !lastResult.delivery.guidance.can_act && <Link href={lastResult.delivery.registry_url}
                                            className="rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-[#E00D42] shadow-xs hover:bg-rose-50 transition">View parcel records</Link>}
                                        <button type="button" disabled={isSubmitting} onClick={() => {
                                            setLastResult(null); setScanMessage(null); setBarcodeInput('');
                                             setDwsLength(''); setDwsWidth(''); setDwsHeight(''); setDwsWeightGrams('');
                                            barcodeInputRef.current?.focus();
                                        }} className="rounded-xl border border-zinc-300 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-800 disabled:opacity-50 shadow-xs hover:bg-zinc-50 transition cursor-pointer">Scan next parcel</button>
                                    </div>

                                    {/* DWS Volumetric Weight Sizing Tool */}
                                    <div className="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs space-y-4">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2 text-xs font-bold text-zinc-900">
                                                <Scale className="h-4 w-4 text-[#E00D42]" />
                                                <span>DWS Weight & Dimension Verification</span>
                                            </div>
                                            <span className="text-xs font-semibold text-zinc-500 uppercase tracking-wide">
                                                Dimension Sizing
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                            <div>
                                                <label className="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Length (cm)</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    placeholder="0.0"
                                                    value={dwsLength}
                                                    onChange={(e) => setDwsLength(e.target.value)}
                                                    className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Width (cm)</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    placeholder="0.0"
                                                    value={dwsWidth}
                                                    onChange={(e) => setDwsWidth(e.target.value)}
                                                    className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Height (cm)</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="0.1"
                                                    placeholder="0.0"
                                                    value={dwsHeight}
                                                    onChange={(e) => setDwsHeight(e.target.value)}
                                                    className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Weight (grams)</label>
                                                <input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    placeholder="0"
                                                    value={dwsWeightGrams}
                                                    onChange={(e) => setDwsWeightGrams(e.target.value)}
                                                    className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-xs focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 transition"
                                                />
                                            </div>
                                        </div>

                                        {(dwsActualKg > 0 || dwsVolumetricKg > 0) && (
                                            <div className="flex flex-wrap items-center justify-between gap-2 pt-2 text-xs border-t border-zinc-100">
                                                <div className="text-zinc-600">
                                                    <span>Actual: <strong className="text-zinc-900 font-semibold">{dwsActualKg.toFixed(2)} kg</strong></span>
                                                    <span className="mx-2 text-zinc-300">·</span>
                                                    <span>Volumetric: <strong className="text-zinc-900 font-semibold">{dwsVolumetricKg.toFixed(2)} kg</strong></span>
                                                </div>
                                                <div className="flex items-center gap-1.5">
                                                    <span className="text-zinc-500 font-medium">Billable:</span>
                                                    <span className="font-extrabold text-[#E00D42] bg-rose-50 border border-rose-200 px-2.5 py-1 rounded-lg tabular-nums">
                                                        {dwsBillableKg.toFixed(2)} kg
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    {/* Assigned Sort Bin */}
                                    <div className="flex items-center justify-between p-4.5 bg-zinc-50 rounded-xl border border-zinc-200 shadow-xs">
                                        <div className="flex items-center gap-2.5">
                                            <Layers className="w-4 h-4 text-zinc-700" />
                                            <div>
                                                <div className="text-xs uppercase text-zinc-500 font-sans font-bold tracking-wider">Destination bin / shelf</div>
                                                <div className="text-sm font-bold text-zinc-900 font-sans mt-0.5">
                                                    {lastResult.delivery.destination_bin?.trim() ? lastResult.delivery.destination_bin : lastResult.delivery.delivery_type === 'hub_self_pickup' ? 'Shelf not recorded' : 'Bin not recorded'}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div className="text-xs uppercase text-rose-500 font-sans font-bold tracking-wider">Waybill Code</div>
                                            <div className="text-xs font-mono font-black text-[#E00D42] bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-200 inline-block mt-0.5">
                                                {lastResult.delivery.tracking_number}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Recipient & Order Details */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        <div className="bg-zinc-50 p-4.5 rounded-xl border border-zinc-200 shadow-xs space-y-1">
                                            <div className="text-zinc-500 text-xs uppercase font-sans font-bold tracking-wider">Customer</div>
                                            <div className="font-bold text-zinc-900 text-sm">{lastResult.delivery.buyer.name}</div>
                                            <div className="text-zinc-600 text-xs">
                                                {lastResult.delivery.buyer.barangay}, {lastResult.delivery.buyer.city}
                                            </div>
                                            {lastResult.delivery.buyer.landmark && (
                                                <div className="text-zinc-700 text-xs font-medium">
                                                    Landmark: {lastResult.delivery.buyer.landmark}
                                                </div>
                                            )}
                                        </div>

                                        <div className="bg-zinc-50 p-4.5 rounded-xl border border-zinc-200 shadow-xs space-y-1">
                                            <div className="text-zinc-500 text-xs uppercase font-sans font-bold tracking-wider">Order Summary</div>
                                            <div className="font-bold text-zinc-900 text-sm">
                                                ₱{lastResult.delivery.order.total_amount.toFixed(2)}{' '}
                                                <span className="text-xs font-sans uppercase text-zinc-600 font-medium">
                                                    ({lastResult.delivery.order.payment_method})
                                                </span>
                                            </div>
                                            <div className="text-zinc-600 text-xs">
                                                {lastResult.delivery.order.items.length} item(s):{' '}
                                                {lastResult.delivery.order.items.map((i) => `${i.name} (x${i.quantity})`).join(', ')}
                                            </div>
                                        </div>
                                    </div>

                                    <div className="pt-1 flex items-center gap-1.5 text-zinc-600 text-xs font-sans">
                                        <ShieldCheck className="w-4 h-4 text-zinc-700" />
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
                                <div className="bg-white rounded-xl border border-zinc-200 p-8 text-center shadow-xs">
                                    <ScanLine className="w-10 h-10 text-zinc-300 mx-auto mb-2" />
                                    <h4 className="font-bold text-sm text-zinc-900 font-sans">Ready for the next parcel</h4>
                                    <p className="text-xs text-zinc-500 mt-1 max-w-sm mx-auto">
                                        Scan a parcel waybill or enter its tracking code to see the next permitted action and receiving hub.
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
                <div className="space-y-4">
                    <div className="bg-white rounded-xl border border-zinc-200 p-5 shadow-xs">
                        <h3 className="font-bold text-sm text-zinc-900 flex items-center gap-2 font-sans">
                            <Clock className="w-4 h-4 text-zinc-600" />
                            Recent Station Scans & Routing Audits
                        </h3>
                        <p className="text-xs text-zinc-500 mt-1">
                            Last 15 scans executed at this logistics facility workstation.
                        </p>
                    </div>

                    <div className="space-y-3">
                        {recentScans.length === 0 ? (
                            <div className="p-8 text-center text-zinc-500 bg-white rounded-xl border border-zinc-200 shadow-xs font-sans text-xs">
                                No recent scans recorded at this station yet.
                            </div>
                        ) : (
                            recentScans.map((scan) => (
                                <div
                                    key={scan.id}
                                    className="bg-white rounded-xl border border-zinc-200 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs shadow-xs hover:border-zinc-300 hover:shadow-sm transition"
                                >
                                    <div className="space-y-1">
                                        <div className="flex flex-wrap items-center gap-2 font-sans">
                                            <span className="font-bold text-zinc-900 font-mono tracking-wide">
                                                {scan.tracking_number}
                                            </span>
                                            <span className="text-xs px-2.5 py-0.5 rounded-lg bg-zinc-100 text-zinc-800 font-semibold border border-zinc-200">
                                                {scan.checkpoint_type}
                                            </span>
                                            <span className="text-xs text-zinc-600 font-medium bg-zinc-50 px-2 py-0.5 rounded-lg border border-zinc-200">
                                                {scan.destination_bin?.trim() ? scan.destination_bin : scan.delivery_type === 'hub_self_pickup' ? 'Shelf not recorded' : 'Bin not recorded'}
                                            </span>
                                        </div>
                                        {scan.notes && <div className="text-zinc-600 text-xs">{scan.notes}</div>}
                                    </div>
                                    <div className="text-left sm:text-right text-xs text-zinc-500 font-sans shrink-0">
                                        <div className="font-semibold text-zinc-900">{new Date(scan.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                                        <div className="text-zinc-500 mt-0.5">{scan.scanned_by}</div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
            <section className="mt-6" aria-label="Station counts and expected arrivals">
            {/* Quick KPI Overview Bar (Minimalist Architectural Stats) */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                <div className="bg-white rounded-xl p-5 border border-zinc-200/90 shadow-xs hover:border-zinc-300 transition-all flex items-center justify-between">
                    <div>
                        <p className="text-xs font-semibold text-zinc-500 tracking-wide uppercase font-sans">
                            Parcels in Station
                        </p>
                        <p className="text-2xl font-bold font-sans text-zinc-900 mt-1.5 tabular-nums">
                            {stats.parcels_in_hub}
                        </p>
                    </div>
                    <div className="w-11 h-11 rounded-xl bg-zinc-50 border border-zinc-200 flex items-center justify-center text-zinc-600 shrink-0">
                        <Package className="w-5 h-5" />
                    </div>
                </div>

                <div className="bg-white rounded-xl p-5 border border-zinc-200/90 shadow-xs hover:border-zinc-300 transition-all flex items-center justify-between">
                    <div>
                        <p className="text-xs font-semibold text-zinc-500 tracking-wide uppercase font-sans">
                            Self-Pickup Staged
                        </p>
                        <p className="text-2xl font-bold font-sans text-zinc-900 mt-1.5 tabular-nums">
                            {stats.ready_pickup}
                        </p>
                    </div>
                    <div className="w-11 h-11 rounded-xl bg-zinc-50 border border-zinc-200 flex items-center justify-center text-zinc-600 shrink-0">
                        <Boxes className="w-5 h-5" />
                    </div>
                </div>

                <div className="bg-white rounded-xl p-5 border border-zinc-200/90 shadow-xs hover:border-zinc-300 transition-all flex items-center justify-between">
                    <div>
                        <p className="text-xs font-semibold text-zinc-500 tracking-wide uppercase font-sans">
                            Dispatched Today
                        </p>
                        <p className="text-2xl font-bold font-sans text-[#E00D42] mt-1.5 tabular-nums">
                            {stats.dispatched_today}
                        </p>
                    </div>
                    <div className="w-11 h-11 rounded-xl bg-[#FDF2F4] border border-[#FCE7EA] flex items-center justify-center text-[#E00D42] shrink-0">
                        <ScanLine className="w-5 h-5" />
                    </div>
                </div>
            </div>

            {pendingOriginIntake.length > 0 && (
                <div className="bg-amber-50/70 border border-amber-200 rounded-xl p-6 mb-5 shadow-xs">
                    <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-4">
                        <div>
                            <h3 className="text-sm font-bold text-zinc-900 font-sans flex items-center gap-2">
                                <Clock className="w-4 h-4 text-amber-800" />
                                Awaiting origin hub intake
                            </h3>
                            <p className="text-xs text-amber-900/80 mt-1">
                                Pickup riders have collected these parcels. Scan each waybill when it physically reaches {activeHub?.name}.
                            </p>
                        </div>
                        <span className="text-xs font-bold text-zinc-900 whitespace-nowrap bg-amber-100/80 px-2.5 py-1 rounded-lg border border-amber-200">
                            {pendingOriginIntake.length} waiting
                        </span>
                    </div>
                    <div className="space-y-2.5">
                        {pendingOriginIntake.map((parcel) => (
                            <button
                                type="button"
                                key={parcel.id}
                                onClick={() => {
                                    setBarcodeInput(parcel.tracking_number);
                                    barcodeInputRef.current?.focus();
                                }}
                                className="w-full text-left bg-white border border-amber-200/80 rounded-xl px-4 py-3 hover:border-[#E00D42] shadow-xs hover:shadow-sm transition cursor-pointer"
                            >
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1.5">
                                    <div>
                                        <div className="text-xs font-bold text-zinc-900 font-sans">{parcel.tracking_number}</div>
                                        <div className="text-xs text-zinc-600 mt-0.5">
                                            {parcel.item_names.join(', ') || 'Parcel contents not listed'} · Pickup rider: {parcel.rider_name}
                                        </div>
                                    </div>
                                    <div className="text-xs text-zinc-500 whitespace-nowrap font-mono">{parcel.updated_at}</div>
                                </div>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            </section>
        </DashboardLayout>
    );
}
