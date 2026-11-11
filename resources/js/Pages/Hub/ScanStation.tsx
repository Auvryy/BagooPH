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
    QrCode,
    FileText,
    Check,
    Compass,
} from 'lucide-react';

export interface CorridorStagingItem {
    corridor_id: string;
    source_hub_id: number;
    destination_hub: {
        id: number;
        name: string;
        code: string;
        tier: string;
        city_municipality: string;
        province: string;
    };
    leg_type: 'feeder' | 'line_haul';
    sealed_bags_count: number;
    open_bags_count: number;
    loose_cargo_count: number;
    total_containers: number;
    total_parcels: number;
    total_weight_kg: number;
    total_cbm: number;
    sizing: {
        required_type: string;
        vehicle_name: string;
        max_payload_kg: number;
        max_cbm: number;
        payload_utilization_pct: number;
        volume_utilization_pct: number;
        is_upgrade: boolean;
        recommendation_reason: string;
    };
    matched_vehicle: {
        id: number;
        plate_number: string;
        vehicle_type: string;
        model: string;
        capacity_kg: number;
        driver_id: number;
        driver_name: string;
    } | null;
    can_dispatch: boolean;
}

export interface DigitalGatePass {
    gate_pass_code: string;
    issued_at: string;
    departure_gate: string;
    manifest_id: number;
    manifest_reference: string;
    source_hub: { id: number; name: string; code: string; tier: string };
    destination_hub: { id: number; name: string; code: string; tier: string };
    driver_name: string;
    driver_phone: string;
    vehicle_plate: string;
    vehicle_type: string;
    total_mother_bags: number;
    total_loose_cargo: number;
    total_parcels_enclosed: number;
    total_weight_kg: number;
    vehicle_capacity_kg: number;
    utilization_pct: number;
    seals: string[];
    verification_hash: string;
}

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
    corridorStaging?: CorridorStagingItem[];
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
    assigned_rider?: {
        id: number;
        name: string;
    } | null;
    can_auto_progress?: boolean;
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
    dws?: {
        length_cm: number;
        width_cm: number;
        height_cm: number;
        actual_weight_kg: number;
        volumetric_weight_kg: number;
        chargeable_weight_kg: number;
        volume_cbm: number;
        is_oversized: boolean;
        is_baggable: boolean;
        chute: string;
        next_facility_name: string;
        next_facility_code: string;
    } | null;
    mother_bag_slotting?: {
        bagged: boolean;
        classification?: string;
        reason?: string;
        mother_bag_id?: number | null;
        reference?: string;
        seal_number?: string | null;
        status?: string;
        parcel_count?: number;
        max_parcels?: number;
        capacity_pct?: number;
        estimated_weight_kg?: number;
        auto_seal_triggered?: boolean;
    } | null;
}

interface ScanEvidence {
    route_hub_count: number;
    submitted_waybill_records: number;
    inherited_departure_records: number;
    derived_local_records: number;
    latest_record: { reference: string; type: string; provenance: string; actor: string | null; actor_role: string | null; recorded_at: string | null } | null;
}

interface MasterBagScanResult {
    reference: string;
    seal_number: string;
    source_hub: string;
    destination_hub: string;
    parcel_count: number;
    status: string;
    receiving_url?: string;
    manifest_url?: string;
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
    corridorStaging = [],
}: ScanStationProps) {
    const [activeTab, setActiveTab] = useState<'scan' | 'counter' | 'logs'>('scan');
    const [barcodeInput, setBarcodeInput] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [audioEnabled, setAudioEnabled] = useState(true);
    const [activeGatePass, setActiveGatePass] = useState<DigitalGatePass | null>(null);
    // Dynamic prompt state
    const [lastResult, setLastResult] = useState<{
        prompt: DynamicPrompt;
        delivery: ScannedDeliveryResult;
        confirmed: boolean;
        alreadyReceived?: boolean;
        scanEvidence?: ScanEvidence;
        scannedBarcode: string;
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
    useOperationalRefresh(isSubmitting, ['activeHub', 'hubs', 'basePath', 'recentScans', 'pendingOriginIntake', 'counterPickups', 'counterTotal', 'stats', 'corridorStaging']);
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
                scan_evidence?: ScanEvidence;
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
                setLastResult({
                    prompt: data.prompt,
                    delivery: data.delivery,
                    confirmed: Boolean(data.confirmed),
                    alreadyReceived: data.already_received,
                    scanEvidence: data.scan_evidence,
                    scannedBarcode: String(body.barcode ?? ''),
                });
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

    const handleBarcodeProcess = (barcode: string) => {
        const code = waybillCode(barcode, window.location.origin) ?? barcode;
        return runScan({ barcode: code, mode: 'inspect' });
    };

    const confirmScanAction = () => {
        if (!lastResult?.scannedBarcode || !lastResult.prompt.requires_confirmation || !lastResult.delivery.guidance.can_act || lastResult.delivery.guidance.action !== 'scan') return;
        return runScan({
            barcode: lastResult.scannedBarcode, mode: 'confirm',
            action: lastResult.prompt.action, expected_status: lastResult.prompt.expected_status,
            attempt_reference: lastResult.prompt.attempt_reference ?? undefined,
            route_reference: lastResult.prompt.route_reference ?? undefined,
            claim_reference: lastResult.prompt.claim_reference ?? undefined,
        }, true);
    };

    const openBagReceiving = () => {
        if (!masterBagResult || isSubmitting) return;
        router.get(masterBagResult.receiving_url || `${basePath}/mother-bags?hub_id=${activeHub?.id}`);
    };

    const triggerFastDispatch = (destinationHubId?: number, _isCorridor = false) => {
        if (!activeHub || isSubmitting) return;
        const destination = destinationHubId || lastResult?.delivery?.routing_target?.id;
        const query = new URLSearchParams({ hub_id: String(activeHub.id) });
        if (destination) query.set('destination_hub_id', String(destination));
        router.get(`${basePath}/manifests?${query}`);
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
                    openBagReceiving();
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

            {/* Top Unified 3-Column Notion Metric Strip */}
            <section aria-label="Station counts and expected arrivals" className="mb-5 space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-zinc-200/80 rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden">
                    <div className="p-4 sm:p-5 flex items-center justify-between gap-4">
                        <div>
                            <p className="text-[11px] font-bold text-zinc-500 tracking-wider uppercase font-sans">
                                Parcels in Station
                            </p>
                            <div className="mt-1 flex items-baseline gap-2">
                                <p className="text-2xl font-bold font-sans text-zinc-900 tabular-nums">
                                    {stats.parcels_in_hub}
                                </p>
                                <span className="text-xs text-zinc-500 font-medium">in custody</span>
                            </div>
                        </div>
                        <div className="w-9 h-9 rounded-lg bg-zinc-50 border border-zinc-200/90 flex items-center justify-center text-zinc-700 shrink-0">
                            <Package className="w-4 h-4" />
                        </div>
                    </div>

                    <div className="p-4 sm:p-5 flex items-center justify-between gap-4">
                        <div>
                            <p className="text-[11px] font-bold text-zinc-500 tracking-wider uppercase font-sans">
                                Self-Pickup Staged
                            </p>
                            <div className="mt-1 flex items-baseline gap-2">
                                <p className="text-2xl font-bold font-sans text-zinc-900 tabular-nums">
                                    {stats.ready_pickup}
                                </p>
                                <span className="text-xs text-zinc-500 font-medium">counter ready</span>
                            </div>
                        </div>
                        <div className="w-9 h-9 rounded-lg bg-zinc-50 border border-zinc-200/90 flex items-center justify-center text-zinc-700 shrink-0">
                            <Boxes className="w-4 h-4" />
                        </div>
                    </div>

                    <div className="p-4 sm:p-5 flex items-center justify-between gap-4">
                        <div>
                            <p className="text-[11px] font-bold text-zinc-500 tracking-wider uppercase font-sans">
                                Dispatched Today
                            </p>
                            <div className="mt-1 flex items-baseline gap-2">
                                <p className="text-2xl font-bold font-sans text-[#E00D42] tabular-nums">
                                    {stats.dispatched_today}
                                </p>
                                <span className="text-xs text-zinc-500 font-medium">outbound</span>
                            </div>
                        </div>
                        <div className="w-9 h-9 rounded-lg bg-rose-50 border border-rose-200 flex items-center justify-center text-[#E00D42] shrink-0">
                            <ScanLine className="w-4 h-4" />
                        </div>
                    </div>
                </div>
            </section>

            {/* Scanner views - Notion Segmented Pill Bar */}
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div role="group" aria-label="Scanner views" className="inline-flex p-1 bg-rose-50/60 border border-rose-200/80 rounded-xl font-sans text-xs max-w-full overflow-x-auto gap-1">
                    <button
                        type="button"
                        onClick={() => setActiveTab('scan')}
                        aria-pressed={activeTab === 'scan'}
                        className={`flex items-center gap-2 px-4 py-2 rounded-lg font-semibold transition cursor-pointer shrink-0 ${
                            activeTab === 'scan'
                                ? 'bg-[#E00D42] text-white shadow-xs'
                                : 'bg-white/80 text-zinc-700 hover:text-[#E00D42] hover:bg-white'
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
                                    ? 'bg-[#E00D42] text-white shadow-xs'
                                    : 'bg-white/80 text-zinc-700 hover:text-[#E00D42] hover:bg-white'
                            }`}
                        >
                            <Boxes className="w-4 h-4" />
                            <span>Counter queue</span>
                            {counterTotal > 0 && (
                                <span className={`px-2 py-0.5 text-xs rounded-md border font-bold tabular-nums ${
                                    activeTab === 'counter'
                                        ? 'bg-white/20 text-white border-white/30'
                                        : 'bg-rose-50 text-[#E00D42] border-rose-200'
                                }`}>
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
                                ? 'bg-[#E00D42] text-white shadow-xs'
                                : 'bg-white/80 text-zinc-700 hover:text-[#E00D42] hover:bg-white'
                        }`}
                    >
                        <Clock className="w-4 h-4" />
                        <span>Scan history</span>
                    </button>
                </div>

                {activeHub && (
                    <div className="inline-flex items-center gap-2 rounded-lg border border-rose-200/80 bg-white px-3 py-1.5 text-xs font-sans shadow-2xs">
                        <Building2 className="h-3.5 w-3.5 text-[#E00D42]" />
                        <span className="font-semibold text-zinc-800">{activeHub.name}</span>
                        <span className="rounded bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-[#C20836] border border-rose-200">
                            {activeHub.code}
                        </span>
                    </div>
                )}
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
                            {/* Unified Hardware Gun, Keyboard & Optical Camera Console Card */}
                            <div className="bg-white rounded-xl border border-zinc-200/90 shadow-xs overflow-hidden">
                                <div className="p-6">
                                    <div className="flex items-center justify-between mb-3">
                                        <label htmlFor="barcode-field" className="block text-xs font-bold text-zinc-800 font-sans uppercase tracking-wider">
                                            Scan or enter a waybill
                                        </label>

                                        <div className="flex items-center gap-2">
                                            {/* Audio Feedback Toggle */}
                                            <button
                                                type="button"
                                                onClick={() => setAudioEnabled(!audioEnabled)}
                                                className={`px-3 py-1.5 rounded-lg border transition shadow-2xs flex items-center gap-1.5 text-xs font-semibold font-sans cursor-pointer ${
                                                    audioEnabled
                                                        ? 'bg-rose-50/80 border-rose-200 text-[#C20836] hover:bg-[#E00D42] hover:text-white'
                                                        : 'bg-zinc-50 border-zinc-200 text-zinc-500 hover:bg-zinc-100'
                                                }`}
                                                title={audioEnabled ? 'Sound Enabled' : 'Sound Muted'}
                                            >
                                                {audioEnabled ? <Volume2 className="w-3.5 h-3.5" /> : <VolumeX className="w-3.5 h-3.5" />}
                                                <span>{audioEnabled ? 'Audio ON' : 'Muted'}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <form onSubmit={handleInputSubmit} className="space-y-3">
                                        <div className="flex gap-2.5">
                                            <div className="relative flex-1">
                                                <ScanLine className="w-4 h-4 text-zinc-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
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
                                                    className="min-h-12 w-full bg-zinc-50/40 hover:bg-white focus:bg-white border border-zinc-300 text-zinc-900 placeholder-zinc-400 rounded-xl pl-10 pr-3.5 py-2.5 text-sm font-sans focus:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]/15 shadow-2xs outline-hidden transition"
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

                                    <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500 border-t border-zinc-100 pt-3.5">
                                        <span className="flex items-center gap-1.5 text-zinc-800 font-semibold">
                                            <Zap className="h-3.5 w-3.5 text-[#E00D42]" />
                                            Hardware Laser Wedge Active
                                        </span>
                                        <span className="text-zinc-500 flex items-center gap-1.5 flex-wrap">
                                            Commands: <span className="text-zinc-800 font-bold">[CMD-CONFIRM]</span> · <span className="text-zinc-800 font-bold">[CMD-DISPATCH]</span> · <span className="text-zinc-800 font-bold">[CMD-CLEAR]</span>
                                            <a
                                                href={`${basePath}/command-sheet`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-rose-50/80 px-2 py-0.5 text-xs text-[#C20836] hover:bg-[#E00D42] hover:text-white font-semibold ml-1 transition"
                                            >
                                                <Printer className="h-3.5 w-3.5" />
                                                Print Sheet
                                            </a>
                                        </span>
                                    </div>
                                </div>

                                {/* Integrated Optical Camera Footer Section */}
                                <div className="border-t border-zinc-200/80 bg-zinc-50/60 px-6 py-4">
                                    <WaybillCamera
                                        key={activeHub?.id}
                                        onScan={handleBarcodeProcess}
                                        disabled={isSubmitting}
                                        instruction="Scan the tracking barcode or parcel QR to check the next permitted step."
                                    />
                                </div>
                            </div>
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
                                        <span className={`text-xs px-2.5 py-1 rounded-full font-bold uppercase tabular-nums ${
                                            masterBagResult.status === 'received'
                                                ? 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                                                : 'bg-amber-100 text-amber-800 border border-amber-300'
                                        }`}>
                                            {masterBagResult.status}
                                        </span>
                                    </div>

                                    <div className="rounded-xl border border-rose-200 bg-rose-50/70 p-5 text-center shadow-xs">
                                        <p className="text-xs tracking-wider text-[#C20836] uppercase font-bold">Master Bag Tag</p>
                                        <p className="text-2xl font-black tracking-tight text-[#E00D42] mt-1">{masterBagResult.reference}</p>
                                        <p className="text-xs text-zinc-600 mt-1.5">
                                            Numbered Tamper Seal: <span className="text-zinc-900 font-bold">{masterBagResult.seal_number || 'N/A'}</span>
                                        </p>
                                    </div>

                                    <div className="space-y-3 rounded-xl border border-rose-200 bg-rose-50 p-4">
                                        <p className="text-sm text-slate-700">Bag identified. Check the actual seal, then scan each parcel at receiving.</p>
                                        <button type="button" disabled={isSubmitting} onClick={openBagReceiving} className="w-full rounded-lg bg-[#E00D42] px-4 py-3 text-sm font-bold text-white hover:bg-[#C20836] disabled:opacity-50">Open bag receiving</button>
                                    </div>

                                    {masterBagResult.parcels && masterBagResult.parcels.length > 0 && (
                                        <div className="border-t border-zinc-100 pt-3 space-y-1.5">
                                            <p className="text-xs font-bold text-zinc-900">Contained Parcels ({masterBagResult.parcels.length})</p>
                                            <div className="max-h-36 overflow-y-auto divide-y divide-zinc-100 text-xs tabular-nums">
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
                                        <div className="rounded-xl border border-rose-200 bg-rose-50/70 p-5 shadow-xs flex items-center justify-between">
                                            <div>
                                                <span className="text-xs font-bold tracking-wider uppercase text-[#C20836]">
                                                    Route Target Facility
                                                </span>
                                                <div className="text-xl sm:text-2xl font-black tracking-tight text-zinc-900 mt-1">
                                                    {lastResult.delivery.routing_target.name}
                                                </div>
                                                <div className="text-xs text-zinc-600 font-medium mt-0.5">
                                                    CODE: {lastResult.delivery.routing_target.code} · TIER: {lastResult.delivery.routing_target.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <span className="inline-flex items-center justify-center rounded-xl bg-[#E00D42] px-3.5 py-1.5 text-xs font-bold text-white shadow-xs">
                                                    {lastResult.delivery.destination_bin ? `BIN: ${lastResult.delivery.destination_bin}` : 'SORT QUEUE'}
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    {/* AUTOMATED DWS INDUCTION PROFILE & CHUTE DISPLAY */}
                                    {lastResult.delivery.dws && (
                                        <div className="rounded-xl border border-zinc-200 bg-white p-4.5 shadow-xs space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Scale className="h-4 w-4 text-[#E00D42]" />
                                                    <span className="text-xs font-bold text-zinc-900 uppercase tracking-wider font-sans">
                                                        Handling estimate
                                                    </span>
                                                </div>
                                                <span className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-100 border border-zinc-200/80 px-2.5 py-1 text-xs font-bold text-zinc-800">
                                                    <Compass className="h-3.5 w-3.5 text-[#E00D42]" />
                                                    <span>{lastResult.delivery.dws.chute}</span>
                                                </span>
                                            </div>
                                            <div className="grid grid-cols-2 sm:grid-cols-4 gap-2.5 pt-1 text-xs">
                                                <div className="rounded-lg bg-zinc-50 p-2.5 border border-zinc-100">
                                                    <span className="text-[10px] uppercase font-bold text-zinc-400 block">Est. dimensions</span>
                                                    <span className="font-bold text-zinc-800 tabular-nums">
                                                        {lastResult.delivery.dws.length_cm}×{lastResult.delivery.dws.width_cm}×{lastResult.delivery.dws.height_cm} cm
                                                    </span>
                                                </div>
                                                <div className="rounded-lg bg-zinc-50 p-2.5 border border-zinc-100">
                                                    <span className="text-[10px] uppercase font-bold text-zinc-400 block">Est. weight</span>
                                                    <span className="font-bold text-zinc-800 tabular-nums">
                                                        {lastResult.delivery.dws.actual_weight_kg.toFixed(2)} kg
                                                    </span>
                                                </div>
                                                <div className="rounded-lg bg-zinc-50 p-2.5 border border-zinc-100">
                                                    <span className="text-[10px] uppercase font-bold text-zinc-400 block">Volumetric</span>
                                                    <span className="font-bold text-zinc-800 tabular-nums">
                                                        {lastResult.delivery.dws.volumetric_weight_kg.toFixed(2)} kg
                                                    </span>
                                                </div>
                                                <div className="rounded-lg bg-rose-50/70 p-2.5 border border-rose-200">
                                                    <span className="text-[10px] uppercase font-bold text-[#C20836] block">Chargeable</span>
                                                    <span className="font-extrabold text-[#E00D42] tabular-nums">
                                                        {lastResult.delivery.dws.chargeable_weight_kg.toFixed(2)} kg
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between pt-1 text-[11px] text-zinc-500 border-t border-zinc-100">
                                                <span>Volume: <strong className="text-zinc-700">{lastResult.delivery.dws.volume_cbm.toFixed(4)} CBM</strong></span>
                                                <span className={`px-2 py-0.5 rounded font-bold uppercase text-[10px] ${
                                                    lastResult.delivery.dws.is_oversized
                                                        ? 'bg-amber-100 text-amber-800 border border-amber-300'
                                                        : 'bg-emerald-100 text-emerald-800 border border-emerald-300'
                                                }`}>
                                                    {lastResult.delivery.dws.is_oversized ? 'Loose Freight (Oversized)' : 'Standard Mother Bag Pouch'}
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    {/* MOTHER BAG AUTO-SLOTTING PROGRESS CARD */}
                                    {lastResult.delivery.mother_bag_slotting && (
                                        <div className="rounded-xl border border-rose-200/90 bg-rose-50/40 p-4.5 shadow-xs space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Boxes className="h-4 w-4 text-[#E00D42]" />
                                                    <span className="text-xs font-bold text-zinc-900 uppercase tracking-wider font-sans">
                                                        Mother Bag Allocation
                                                    </span>
                                                </div>
                                                <span className="text-xs font-bold text-[#E00D42] bg-white border border-rose-200 px-2.5 py-0.5 rounded-lg tabular-nums">
                                                    {lastResult.delivery.mother_bag_slotting.reference || 'PREVIEW SACK'}
                                                </span>
                                            </div>
                                            <div>
                                                <div className="flex items-center justify-between text-xs mb-1.5">
                                                    <span className="text-zinc-600 font-medium">
                                                        Sack Capacity ({lastResult.delivery.mother_bag_slotting.parcel_count || 1} / {lastResult.delivery.mother_bag_slotting.max_parcels || 50} pouches)
                                                    </span>
                                                    <span className="font-bold text-zinc-900 tabular-nums">
                                                        {lastResult.delivery.mother_bag_slotting.capacity_pct || 2}%
                                                    </span>
                                                </div>
                                                <div className="w-full bg-zinc-200/80 rounded-full h-2 overflow-hidden">
                                                    <div
                                                        className="bg-[#E00D42] h-2 rounded-full transition-all duration-300"
                                                        style={{ width: `${Math.min(100, Math.max(5, lastResult.delivery.mother_bag_slotting.capacity_pct || 2))}%` }}
                                                    />
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between text-[11px] text-zinc-600 pt-1">
                                                <span>Est. Sack Weight: <strong>{lastResult.delivery.mother_bag_slotting.estimated_weight_kg || 0.6} kg</strong> / 25.0 kg</span>
                                                <span className="font-medium text-zinc-500">
                                                    {lastResult.delivery.mother_bag_slotting.seal_number
                                                        ? `Seal: ${lastResult.delivery.mother_bag_slotting.seal_number}`
                                                        : 'Numbered seal required before departure'}
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

                                    {/* Assigned Final-Mile Rider Badge */}
                                    {lastResult.delivery.assigned_rider && (
                                        <div className="flex items-center justify-between rounded-xl bg-indigo-50/80 border border-indigo-200 px-4 py-2.5 text-xs">
                                            <span className="font-semibold text-indigo-900 flex items-center gap-2">
                                                <Truck className="h-4 w-4 text-indigo-600" />
                                                Assigned Final-Mile Rider:
                                            </span>
                                            <span className="font-bold text-indigo-950 bg-white px-2.5 py-1 rounded-md border border-indigo-200 shadow-2xs">
                                                {lastResult.delivery.assigned_rider.name}
                                            </span>
                                        </div>
                                    )}

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
                                                        disabled={isSubmitting}
                                                        onClick={() => triggerFastDispatch(lastResult.delivery.routing_target?.id)}
                                                        className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-5 py-3 text-sm font-bold text-white shadow-xs hover:bg-[#C20836] transition cursor-pointer active:scale-98 disabled:opacity-50"
                                                    >
                                                        <Zap className="h-4 w-4 fill-current text-white" />
                                                        <span>{`Prepare manifest to ${lastResult.delivery.routing_target?.code || 'next hub'}`}</span>
                                                    </button>
                                                    <span className="text-xs text-zinc-500 tabular-nums">or scan [CMD-DISPATCH]</span>
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
                                            className="rounded-xl border border-rose-200 bg-rose-50/80 px-3.5 py-2 text-xs font-semibold text-[#C20836] disabled:opacity-50 shadow-xs hover:bg-[#E00D42] hover:text-white transition cursor-pointer">Check for updates</button>
                                        {lastResult.delivery.registry_url && !lastResult.delivery.guidance.can_act && <Link href={lastResult.delivery.registry_url}
                                            className="rounded-xl border border-rose-200 bg-rose-50/80 px-3.5 py-2 text-xs font-semibold text-[#C20836] shadow-xs hover:bg-[#E00D42] hover:text-white transition">View parcel records</Link>}
                                        <button type="button" disabled={isSubmitting} onClick={() => {
                                            setLastResult(null); setScanMessage(null); setBarcodeInput('');
                                             setDwsLength(''); setDwsWidth(''); setDwsHeight(''); setDwsWeightGrams('');
                                            barcodeInputRef.current?.focus();
                                        }} className="rounded-xl border border-rose-200 bg-rose-50/80 px-3.5 py-2 text-xs font-semibold text-[#C20836] disabled:opacity-50 shadow-xs hover:bg-[#E00D42] hover:text-white transition cursor-pointer">Scan next parcel</button>
                                    </div>

                                    {/* DWS Volumetric Weight Sizing Tool */}
                                    <div className="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs space-y-4">
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2 text-xs font-bold text-zinc-900">
                                                <Scale className="h-4 w-4 text-[#E00D42]" />
                                                <span>Weight and size calculator</span>
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
                                    <div className="flex items-center justify-between p-4 bg-zinc-50 rounded-xl border border-zinc-200 shadow-xs">
                                        <div className="flex items-center gap-2.5">
                                            <Layers className="w-4 h-4 text-[#E00D42]" />
                                            <div>
                                                <div className="text-xs uppercase text-zinc-500 font-sans font-bold tracking-wider">Destination bin / shelf</div>
                                                <div className="text-sm font-bold text-zinc-900 font-sans mt-0.5">
                                                    {lastResult.delivery.destination_bin?.trim() ? lastResult.delivery.destination_bin : lastResult.delivery.delivery_type === 'hub_self_pickup' ? 'Shelf not recorded' : 'Bin not recorded'}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <div className="text-xs uppercase text-rose-500 font-sans font-bold tracking-wider">Waybill Code</div>
                                            <div className="text-xs font-bold text-[#E00D42] bg-rose-50 px-2.5 py-1 rounded-lg border border-rose-200 inline-block mt-0.5">
                                                {lastResult.delivery.tracking_number}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Recipient & Order Details */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        <div className="bg-zinc-50 p-4 rounded-xl border border-zinc-200 shadow-xs space-y-1">
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

                                        <div className="bg-zinc-50 p-4 rounded-xl border border-zinc-200 shadow-xs space-y-1">
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

                                    {lastResult.scanEvidence && <details className="rounded-lg border border-rose-200 bg-rose-50/60 p-3 text-xs text-slate-700">
                                        <summary className="cursor-pointer font-semibold text-[#9F1239]">Scan evidence · {lastResult.scanEvidence.route_hub_count} route hub(s)</summary>
                                        <div className="mt-3 space-y-2">
                                            {lastResult.scanEvidence.latest_record && <><p className="break-all font-semibold">{lastResult.scanEvidence.latest_record.reference}</p><p>{lastResult.scanEvidence.latest_record.actor || 'No named actor recorded'} · {lastResult.scanEvidence.latest_record.type.replaceAll('_', ' ')} · {lastResult.scanEvidence.latest_record.recorded_at ? new Date(lastResult.scanEvidence.latest_record.recorded_at).toLocaleString('en-PH') : 'Time not recorded'}</p></>}
                                            <p>{lastResult.scanEvidence.submitted_waybill_records} submitted waybill record(s) · {lastResult.scanEvidence.inherited_departure_records} departure record(s) using retained load evidence · {lastResult.scanEvidence.derived_local_records} local route record(s)</p>
                                            <p>Aliases and prepared labels are excluded. These are recorded events, not a count of physical touches.</p>
                                        </div>
                                    </details>}

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
                                <div className="bg-white rounded-xl border border-zinc-200/90 shadow-xs overflow-hidden font-sans">
                                    {/* Command Terminal Header */}
                                    <div className="flex items-center justify-between border-b border-zinc-200/80 bg-zinc-50/70 px-5 py-3">
                                        <div className="flex items-center gap-2">
                                            <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-white border border-zinc-200 text-zinc-700 shadow-2xs">
                                                <ScanLine className="h-3.5 w-3.5 text-[#E00D42]" />
                                            </span>
                                            <span className="text-xs font-bold uppercase tracking-wider text-zinc-800">
                                                Routing Terminal
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2 tabular-nums text-xs">
                                            <span className="rounded-md bg-white border border-zinc-200/90 px-2 py-0.5 font-bold text-zinc-700">
                                                {activeHub?.code || 'STATION'}
                                            </span>
                                            <span className="rounded-md bg-rose-50 border border-rose-200 px-2 py-0.5 font-bold text-[#E00D42]">
                                                WEDGE READY
                                            </span>
                                        </div>
                                    </div>

                                    {/* Idle Body */}
                                    <div className="p-6 sm:p-7">
                                        <div className="flex items-start gap-4">
                                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-zinc-200/90 bg-zinc-50 text-zinc-700">
                                                <ScanLine className="w-5 h-5" />
                                            </div>
                                            <div>
                                                <h4 className="font-bold text-sm text-zinc-900 font-sans">
                                                    Ready for the next parcel
                                                </h4>
                                                <p className="text-xs text-zinc-500 mt-1 leading-relaxed">
                                                    Scan a parcel waybill or enter its tracking code to see the next permitted action and receiving hub.
                                                </p>
                                            </div>
                                        </div>

                                        {/* Hardware & Keyboard Command Matrix */}
                                        <div className="mt-6 rounded-xl border border-zinc-200/80 bg-zinc-50/50 divide-y divide-zinc-200/70">
                                            <div className="px-3.5 py-2 flex items-center justify-between text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                                                <span>Hardware Command Shortcuts</span>
                                                <span className="tabular-nums text-zinc-400">2D / 1D WEDGE</span>
                                            </div>
                                            <div className="px-3.5 py-2.5 flex items-center justify-between gap-2 text-xs">
                                                <span className="text-zinc-600 font-medium">Confirm physical intake or bag seal</span>
                                                <kbd className="rounded-md border border-zinc-300 bg-white px-2 py-0.5 tabular-nums text-[11px] font-bold text-zinc-800 shadow-2xs">
                                                    CMD-CONFIRM
                                                </kbd>
                                            </div>
                                            <div className="px-3.5 py-2.5 flex items-center justify-between gap-2 text-xs">
                                                <span className="text-zinc-600 font-medium">1-click fast dispatch to route target</span>
                                                <kbd className="rounded-md border border-zinc-300 bg-white px-2 py-0.5 tabular-nums text-[11px] font-bold text-zinc-800 shadow-2xs">
                                                    CMD-DISPATCH
                                                </kbd>
                                            </div>
                                            <div className="px-3.5 py-2.5 flex items-center justify-between gap-2 text-xs">
                                                <span className="text-zinc-600 font-medium">Reset terminal and focus waybill input</span>
                                                <kbd className="rounded-md border border-zinc-300 bg-white px-2 py-0.5 tabular-nums text-[11px] font-bold text-zinc-800 shadow-2xs">
                                                    CMD-CLEAR
                                                </kbd>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* AUTOMATED FLEET SIZING & CORRIDOR DISPATCH (TMS) */}
                    {corridorStaging && corridorStaging.length > 0 && (
                        <div className="bg-white rounded-xl border border-zinc-200/90 shadow-xs overflow-hidden mt-2 font-sans">
                            <div className="flex flex-wrap items-center justify-between border-b border-zinc-200/80 bg-zinc-50/70 px-5 py-3.5 gap-2">
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 border border-rose-200 text-[#E00D42]">
                                        <Truck className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-900">
                                            Automated Corridor Staging & Vehicle Sizing (TMS)
                                        </h3>
                                        <p className="text-[11px] text-zinc-500 font-medium">
                                            Algorithmic payload bin-packing & scheduled linehaul vehicle assignment
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="rounded-lg bg-rose-50 border border-rose-200 px-2.5 py-1 text-[11px] font-bold text-[#E00D42] tabular-nums">
                                        {corridorStaging.length} Outbound Corridor{corridorStaging.length > 1 ? 's' : ''}
                                    </span>
                                </div>
                            </div>

                            <div className="divide-y divide-zinc-100">
                                {corridorStaging.map((corridor) => (
                                    <div key={corridor.corridor_id} className="p-5 sm:p-6 transition hover:bg-zinc-50/50">
                                        <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                                            <div className="space-y-1.5 flex-1 min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="text-sm sm:text-base font-bold text-zinc-900">
                                                        {activeHub?.code} ➔ {corridor.destination_hub.name}
                                                    </span>
                                                    <span className={`px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase tracking-wide border ${
                                                        corridor.leg_type === 'line_haul'
                                                            ? 'bg-purple-50 text-purple-700 border-purple-200'
                                                            : 'bg-blue-50 text-blue-700 border-blue-200'
                                                    }`}>
                                                        {corridor.leg_type === 'line_haul' ? 'Line-Haul Trunk' : 'Municipal Feeder'}
                                                    </span>
                                                    {corridor.sizing.is_upgrade && (
                                                        <span className="px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase bg-amber-50 text-amber-800 border border-amber-300">
                                                            Suggested capacity upgrade
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-600">
                                                    <span>Mother Bags: <strong className="text-zinc-900 font-semibold">{corridor.sealed_bags_count} sealed</strong> ({corridor.open_bags_count} packing)</span>
                                                    <span>Loose Cargo: <strong className="text-zinc-900 font-semibold">{corridor.loose_cargo_count}</strong></span>
                                                    <span>Enclosed Parcels: <strong className="text-zinc-900 font-semibold">{corridor.total_parcels}</strong></span>
                                                    <span>Payload: <strong className="text-zinc-900 font-semibold">{corridor.total_weight_kg.toFixed(1)} kg</strong> ({corridor.total_cbm.toFixed(2)} CBM)</span>
                                                </div>
                                            </div>

                                            {/* Vehicle Sizing & Match Badge */}
                                            <div className="flex flex-col sm:flex-row sm:items-center gap-3 shrink-0">
                                                <div className="rounded-xl border border-zinc-200/90 bg-zinc-50 p-3 min-w-[210px] space-y-1.5">
                                                    <div className="flex items-center justify-between text-xs">
                                                        <span className="text-zinc-500 font-medium">Assigned Class:</span>
                                                        <span className="font-bold text-zinc-900">{corridor.sizing.vehicle_name}</span>
                                                    </div>
                                                    <div>
                                                        <div className="flex items-center justify-between text-[11px] text-zinc-500 mb-1">
                                                            <span>Capacity Utilization</span>
                                                            <span className="font-bold text-zinc-800 tabular-nums">{corridor.sizing.payload_utilization_pct}%</span>
                                                        </div>
                                                        <div className="w-full bg-zinc-200 rounded-full h-1.5 overflow-hidden">
                                                            <div
                                                                className={`h-1.5 rounded-full ${
                                                                    corridor.sizing.payload_utilization_pct > 90
                                                                        ? 'bg-amber-500'
                                                                        : 'bg-[#E00D42]'
                                                                }`}
                                                                style={{ width: `${Math.min(100, Math.max(8, corridor.sizing.payload_utilization_pct))}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="text-[11px] text-zinc-600 flex items-center justify-between pt-0.5 border-t border-zinc-200/60">
                                                        <span>Vehicle: <strong className="text-zinc-800">{corridor.matched_vehicle?.plate_number || 'Choose a vehicle'}</strong></span>
                                                        <span className="truncate max-w-[100px] text-zinc-500">{corridor.matched_vehicle?.driver_name || 'Choose a driver'}</span>
                                                    </div>
                                                </div>

                                                <button
                                                    type="button"
                                                    disabled={isSubmitting || !corridor.can_dispatch}
                                                    onClick={() => triggerFastDispatch(corridor.destination_hub.id, true)}
                                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#E00D42] px-5 py-3 text-xs font-bold text-white shadow-xs hover:bg-[#C20836] active:scale-98 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                                                >
                                                    <Zap className="h-4 w-4 fill-current text-white" />
                                                    <span>Prepare manifest</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
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
                                            <span className="font-bold text-zinc-900 tabular-nums tracking-wide">
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

            {pendingOriginIntake.length > 0 && (
                <section className="mt-6" aria-label="Expected origin arrivals">
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
                                        <div className="text-xs text-zinc-500 whitespace-nowrap tabular-nums">{parcel.updated_at}</div>
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* DIGITAL GATE PASS & e-BOL MODAL */}
            {activeGatePass && (
                <div
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="gate-pass-title"
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 overflow-y-auto"
                >
                    <div className="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-zinc-200 overflow-hidden font-sans my-8">
                        {/* Modal Header */}
                        <div className="bg-[#E00D42] text-white p-6 relative">
                            <div className="flex items-center justify-between">
                                <span className="inline-flex items-center gap-1.5 rounded-md bg-white/20 px-2.5 py-1 text-[11px] font-extrabold uppercase tracking-wider text-white">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    Official Vehicle Gate Pass
                                </span>
                                <span className="text-xs font-mono font-bold bg-black/20 px-2.5 py-1 rounded text-white">
                                    {activeGatePass.departure_gate}
                                </span>
                            </div>
                            <h2 id="gate-pass-title" className="text-xl sm:text-2xl font-black tracking-tight mt-2 text-white">
                                {activeGatePass.gate_pass_code}
                            </h2>
                            <p className="text-xs text-rose-100 mt-1">
                                Manifest Reference: <strong className="text-white">{activeGatePass.manifest_reference}</strong>
                            </p>
                        </div>

                        {/* Modal Body */}
                        <div className="p-6 space-y-4 text-xs">
                            {/* Route & Transport */}
                            <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4 space-y-2">
                                <div className="flex items-center justify-between text-zinc-500 uppercase font-bold text-[10px] tracking-wider">
                                    <span>Source Hub</span>
                                    <span>Destination Hub</span>
                                </div>
                                <div className="flex items-center justify-between font-bold text-sm text-zinc-900">
                                    <span>{activeGatePass.source_hub.name} ({activeGatePass.source_hub.code})</span>
                                    <ArrowRight className="h-4 w-4 text-zinc-400 mx-2" />
                                    <span>{activeGatePass.destination_hub.name} ({activeGatePass.destination_hub.code})</span>
                                </div>
                            </div>

                            {/* Driver & Vehicle */}
                            <div className="grid grid-cols-2 gap-3">
                                <div className="rounded-xl border border-zinc-200 p-3.5 space-y-1">
                                    <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Assigned Driver</span>
                                    <div className="font-bold text-zinc-900 text-xs sm:text-sm">{activeGatePass.driver_name}</div>
                                    <div className="text-zinc-500 text-[11px]">{activeGatePass.driver_phone}</div>
                                </div>
                                <div className="rounded-xl border border-zinc-200 p-3.5 space-y-1">
                                    <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Vehicle Plate & Class</span>
                                    <div className="font-bold text-zinc-900 text-xs sm:text-sm">{activeGatePass.vehicle_plate}</div>
                                    <div className="text-[#E00D42] font-semibold text-[11px] capitalize">{activeGatePass.vehicle_type.replace(/_/g, ' ')}</div>
                                </div>
                            </div>

                            {/* Payload & Sizing Gauge */}
                            <div className="rounded-xl border border-zinc-200 p-4 space-y-2">
                                <div className="flex items-center justify-between">
                                    <span className="font-bold text-zinc-700">Payload Weight Utilization</span>
                                    <span className="font-extrabold text-[#E00D42] tabular-nums">
                                        {activeGatePass.total_weight_kg.toFixed(1)} kg / {activeGatePass.vehicle_capacity_kg} kg ({activeGatePass.utilization_pct}%)
                                    </span>
                                </div>
                                <div className="w-full bg-zinc-100 rounded-full h-2 overflow-hidden">
                                    <div
                                        className="bg-[#E00D42] h-2 rounded-full"
                                        style={{ width: `${Math.min(100, Math.max(8, activeGatePass.utilization_pct))}%` }}
                                    />
                                </div>
                                <div className="flex items-center justify-between text-[11px] text-zinc-500 pt-1">
                                    <span>Mother Bags: <strong>{activeGatePass.total_mother_bags}</strong></span>
                                    <span>Loose Cartons: <strong>{activeGatePass.total_loose_cargo}</strong></span>
                                    <span>Total Parcels: <strong>{activeGatePass.total_parcels_enclosed}</strong></span>
                                </div>
                            </div>

                            {/* Tamper Seals */}
                            {activeGatePass.seals && activeGatePass.seals.length > 0 && (
                                <div className="rounded-xl border border-rose-200 bg-rose-50/50 p-3.5 space-y-1.5">
                                    <span className="text-[10px] font-bold text-[#C20836] uppercase tracking-wider block">
                                        Tamper Security Seals ({activeGatePass.seals.length})
                                    </span>
                                    <div className="flex flex-wrap gap-1.5">
                                        {activeGatePass.seals.map((seal, idx) => (
                                            <span key={idx} className="rounded-md border border-rose-300 bg-white px-2 py-0.5 font-bold text-xs text-[#E00D42] tabular-nums">
                                                {seal}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Security QR / Hash */}
                            <div className="flex items-center justify-between p-3 bg-zinc-50 rounded-xl border border-zinc-200">
                                <div className="flex items-center gap-2">
                                    <QrCode className="h-5 w-5 text-[#E00D42]" />
                                    <div>
                                        <span className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Guardhouse Hash</span>
                                        <span className="font-mono text-[10px] text-zinc-600 truncate max-w-[220px] block">
                                            {activeGatePass.verification_hash}
                                        </span>
                                    </div>
                                </div>
                                <span className="rounded bg-emerald-100 border border-emerald-300 px-2 py-0.5 text-[10px] font-extrabold text-emerald-800 uppercase">
                                    CLEARED
                                </span>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div className="bg-zinc-50 px-6 py-4 border-t border-zinc-200 flex items-center justify-between gap-3">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-xs font-bold text-zinc-800 shadow-xs hover:bg-zinc-100 transition cursor-pointer"
                            >
                                <Printer className="h-4 w-4 text-zinc-600" />
                                <span>Print Gate Pass & e-BOL</span>
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveGatePass(null)}
                                className="rounded-xl bg-[#E00D42] px-6 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-[#C20836] transition cursor-pointer"
                            >
                                Done / Return to Scanner
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
}
