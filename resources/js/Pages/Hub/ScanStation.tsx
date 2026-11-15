import { refreshPage } from '@/utils/accountRequests';
import React, { useState, useEffect, useRef } from 'react';
import { Head, router, Link, usePage } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
    HubNextStep,
    HubRoute,
    hubButton,
} from '@/Components/Logistics/Workspace';
import {
    BatchQueueWorkbench,
    BatchSyncResponse,
    GatePassDialog,
    QueuedScanItem,
    ScanModeControls,
    ScanOperationMode,
    createClientRequestToken,
    enqueueScanItem,
    loadQueuedScans,
    packOpenMotherBag,
    requestBatchScan,
    saveQueuedScans,
} from '@/Components/Logistics/ScanBatchPanel';
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
    total_weight_kg: number | null;
    total_cbm: number | null;
    unpacked_count: number;
    unknown_readings_count: number;
    blocker: string | null;
    sizing: {
        required_type: string;
        vehicle_name: string;
        max_payload_kg: number | null;
        max_cbm: number | null;
        payload_utilization_pct: number | null;
        volume_utilization_pct: number | null;
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
    next_hop?: { hub_id: number; recommended_bin: string } | null;
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
        estimated_weight_kg?: number | null;
        weight_kg?: number | null;
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

interface ScanApiPayload {
    prompt?: DynamicPrompt;
    delivery?: ScannedDeliveryResult;
    confirmed: boolean;
    message: string;
    already_received?: boolean;
    scan_evidence?: ScanEvidence;
    is_master_bag?: boolean;
    bag?: MasterBagScanResult;
    return_case?: { reference: string; barcode: string; href: string; status: string };
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
    const page = usePage();
    const canManageHubResources = Boolean((page?.props?.auth?.user as { canManageResources?: boolean } | undefined)?.canManageResources);
    const [activeTab, setActiveTab] = useState<'scan' | 'counter' | 'logs' | 'batch'>('scan');
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

    // Always default to 2-step inspect mode so saved storage preferences never auto-confirm custody.
    const [scanMode, setScanMode] = useState<ScanOperationMode>('verify');
    const [pinnedAction, setPinnedAction] = useState<string>('ANY_PERMITTED_PARCEL_STEP');
    const [locationBarcode, setLocationBarcode] = useState<string>('');
    const [queuedScans, setQueuedScans] = useState<QueuedScanItem[]>([]);
    const [lastBatchSync, setLastBatchSync] = useState<BatchSyncResponse<ScanApiPayload> | null>(null);
    const readNavigatorOnline = (): boolean => {
        if (typeof navigator !== 'undefined' && typeof navigator.onLine === 'boolean') {
            return navigator.onLine;
        }
        if (typeof window !== 'undefined' && typeof window.navigator?.onLine === 'boolean') {
            return window.navigator.onLine;
        }
        return true;
    };

    const [isOnline, setIsOnline] = useState<boolean>(() => readNavigatorOnline());
    const [slottingBusy, setSlottingBusy] = useState<boolean>(false);
    const [slotFeedback, setSlotFeedback] = useState<string | null>(null);

    const dwsVolumetricKg = (Number(dwsLength) > 0 && Number(dwsWidth) > 0 && Number(dwsHeight) > 0)
        ? (Number(dwsLength) * Number(dwsWidth) * Number(dwsHeight)) / 3500
        : 0;
    const dwsActualKg = Number(dwsWeightGrams) > 0 ? Number(dwsWeightGrams) / 1000 : 0;
    const dwsBillableKg = Math.max(dwsActualKg, dwsVolumetricKg);

    const barcodeInputRef = useRef<HTMLInputElement | null>(null);
    const pendingRequest = useRef<AbortController | null>(null);
    useOperationalRefresh(isSubmitting, ['activeHub', 'hubs', 'basePath', 'recentScans', 'pendingOriginIntake', 'counterPickups', 'counterTotal', 'stats', 'corridorStaging']);
    useEffect(() => {
        if (typeof window === 'undefined') return;
        const handleOnline = () => setIsOnline(readNavigatorOnline());
        const handleOffline = () => setIsOnline(false);
        handleOnline();
        window.addEventListener('online', handleOnline);
        window.addEventListener('offline', handleOffline);
        return () => {
            window.removeEventListener('online', handleOnline);
            window.removeEventListener('offline', handleOffline);
        };
    }, []);
    useEffect(() => {
        setLastResult(null);
        setMasterBagResult(null);
        setReturnResult(null);
        setScanError(null);
        setRecoveryUrl(null);
        setScanMessage(null);
        setSlotFeedback(null);
        setLastBatchSync(null);
        setDwsLength('');
        setDwsWidth('');
        setDwsHeight('');
        setDwsWeightGrams('');
        setQueuedScans(loadQueuedScans(activeHub?.id));
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

    const updateQueue = (next: QueuedScanItem[]) => {
        setQueuedScans(next);
        saveQueuedScans(activeHub?.id, next);
    };

    const applyScanPayload = (data: ScanApiPayload, submittedBarcode: string) => {
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
                scannedBarcode: submittedBarcode,
            });
            playSound(data.confirmed ? 'success' : 'beep');
        }
        setScanMessage(data.message);
        setSlotFeedback(null);
    };

    const runScan = async (body: Record<string, unknown>, confirming = false) => {
        if (pendingRequest.current || !activeHub) return;
        const request = new AbortController();
        pendingRequest.current = request;
        setIsSubmitting(true);
        setScanError(null);
        setRecoveryUrl(null);
        setScanMessage(null);
        setSlotFeedback(null);
        setSessionExpired(false);
        if (!confirming) { setLastResult(null); setReturnResult(null); setMasterBagResult(null); }
        try {
            const data = await requestWaybill<ScanApiPayload>(
                `${basePath}/scan`, { ...body, hub_id: activeHub.id }, request.signal,
            );
            if (pendingRequest.current !== request || request.signal.aborted) return;

            applyScanPayload(data, String(body.barcode ?? ''));
            setBarcodeInput('');
            if (confirming) {
                try { await refreshPage(); }
                catch { if (!request.signal.aborted) setScanError('The custody action was saved, but the queue could not refresh. Reload this scanner to see the latest records.'); }
            }
        } catch (error) {
            if (pendingRequest.current !== request || request.signal.aborted) return;
            const rawCode = String(body.barcode ?? '').trim();
            const upperRaw = rawCode.toUpperCase();
            const isSpecialTag = upperRaw.startsWith('BAG-') || upperRaw.startsWith('RMB-') || upperRaw.startsWith('BRT-');
            if (!(error instanceof WaybillRequestError) && !confirming && rawCode && !isSpecialTag) {
                enqueueScanForBatch(rawCode, `Connection interrupted — buffered ${rawCode} offline for sync.`);
                return;
            }
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

    const enqueueScanForBatch = (code: string, customMessage?: string) => {
        const { next, added, duplicateBarcode } = enqueueScanItem(queuedScans, code, {
            locationBarcode,
            expectedAction: pinnedAction,
        });
        setScanError(null);
        setRecoveryUrl(null);
        setSlotFeedback(null);
        setReturnResult(null);
        setMasterBagResult(null);

        if (added) {
            updateQueue(next);
            playSound('beep');
            setScanMessage(customMessage ?? `Queued ${code} offline (${next.length} pending in batch queue).`);
            setBarcodeInput('');
        } else if (duplicateBarcode) {
            playSound('beep');
            setScanMessage(`${code} is already waiting in the batch queue.`);
            setBarcodeInput('');
        }
    };

    const runDirectConfirmScan = async (code: string) => {
        if (pendingRequest.current || !activeHub) return;
        const request = new AbortController();
        pendingRequest.current = request;
        setIsSubmitting(true);
        setScanError(null);
        setRecoveryUrl(null);
        setScanMessage(null);
        setSlotFeedback(null);
        setSessionExpired(false);
        setLastResult(null);
        setReturnResult(null);
        setMasterBagResult(null);

        try {
            const response = await requestBatchScan<ScanApiPayload>(
                `${basePath}/scan/batch`,
                {
                    hub_id: activeHub.id,
                    direct_mode: true,
                    pinned_action: pinnedAction !== 'ANY_PERMITTED_PARCEL_STEP' ? pinnedAction : undefined,
                    items: [
                        {
                            barcode: code,
                            request_token: createClientRequestToken(),
                            location_barcode: locationBarcode.trim() || undefined,
                            client_scanned_at: new Date().toISOString(),
                        },
                    ],
                },
                request.signal,
            );
            if (pendingRequest.current !== request || request.signal.aborted) return;

            setLastBatchSync(response);
            const item = response.items[0];
            if (!item || item.outcome === 'rejected') {
                playSound('error');
                setBarcodeInput(code);
                setScanError(item?.error || `Direct scan could not confirm ${code}.`);
                return;
            }

            setBarcodeInput('');
            if (response.scan_result?.delivery && response.scan_result?.prompt) {
                applyScanPayload(response.scan_result, code);
            } else {
                playSound('success');
                setScanMessage(
                    item.outcome === 'duplicate_replay'
                        ? `Replay verified for ${item.tracking_number ?? code}.`
                        : `Confirmed ${item.action_applied ?? 'custody step'} for ${item.tracking_number ?? code} in ${item.latency_ms} ms.`,
                );
            }

            try {
                await refreshPage();
            } catch {
                if (!request.signal.aborted) {
                    setScanError('The direct scan was saved, but station counters could not refresh.');
                }
            }
        } catch (error) {
            if (pendingRequest.current !== request || request.signal.aborted) return;
            const upperCode = code.toUpperCase();
            const isSpecialTag = upperCode.startsWith('BAG-') || upperCode.startsWith('RMB-') || upperCode.startsWith('BRT-');
            if (!(error instanceof WaybillRequestError) && !isSpecialTag) {
                enqueueScanForBatch(code, `Connection interrupted — buffered ${code} offline for sync.`);
                return;
            }
            playSound('error');
            setBarcodeInput(code);
            setScanError(error instanceof Error ? error.message : 'Direct confirmation failed. Try again.');
            setRecoveryUrl(error instanceof WaybillRequestError ? error.recoveryUrl : null);
            setSessionExpired(error instanceof WaybillRequestError && [401, 419].includes(error.status));
        } finally {
            if (pendingRequest.current === request) {
                pendingRequest.current = null;
                setIsSubmitting(false);
            }
        }
    };

    const syncBatchQueue = async () => {
        if (pendingRequest.current || !activeHub || queuedScans.length === 0) return;
        const request = new AbortController();
        pendingRequest.current = request;
        setIsSubmitting(true);
        setScanError(null);
        setRecoveryUrl(null);
        setScanMessage(null);
        setSessionExpired(false);

        try {
            const response = await requestBatchScan<ScanApiPayload>(
                `${basePath}/scan/batch`,
                {
                    hub_id: activeHub.id,
                    direct_mode: false,
                    pinned_action: pinnedAction !== 'ANY_PERMITTED_PARCEL_STEP' ? pinnedAction : undefined,
                    items: queuedScans,
                },
                request.signal,
            );
            if (pendingRequest.current !== request || request.signal.aborted) return;

            setLastBatchSync(response);
            const rejectedTokens = new Set(
                response.items.filter((entry) => entry.outcome === 'rejected').map((entry) => entry.request_token),
            );
            const remaining = queuedScans.filter((entry) => rejectedTokens.has(entry.request_token));
            updateQueue(remaining);

            if (response.summary.confirmed > 0 || response.summary.duplicate_replay > 0) {
                playSound('success');
            } else {
                playSound('error');
            }

            setScanMessage(
                `Batch synced (${response.summary.elapsed_ms} ms · ${response.summary.scans_per_sec} scans/sec): ` +
                    `${response.summary.confirmed} confirmed, ${response.summary.duplicate_replay} replayed, ${response.summary.rejected} rejected.`,
            );

            if (response.summary.confirmed > 0) {
                try {
                    await refreshPage();
                } catch {
                    if (!request.signal.aborted) {
                        setScanError('Batch scans saved, but the station summary could not refresh.');
                    }
                }
            }
        } catch (error) {
            if (pendingRequest.current !== request || request.signal.aborted) return;
            playSound('error');
            setScanError(error instanceof Error ? error.message : 'Batch sync failed. Your queue remains saved.');
            setSessionExpired(error instanceof WaybillRequestError && [401, 419].includes(error.status));
        } finally {
            if (pendingRequest.current === request) {
                pendingRequest.current = null;
                setIsSubmitting(false);
            }
        }
    };

    const packCurrentParcelIntoOpenBag = async () => {
        if (!activeHub || !lastResult?.delivery?.tracking_number || slottingBusy) return;
        const controller = new AbortController();
        setSlottingBusy(true);
        setScanError(null);
        setSlotFeedback(null);

        try {
            const packed = await packOpenMotherBag(
                `${basePath}/mother-bags/pack`,
                {
                    source_hub_id: activeHub.id,
                    barcode: lastResult.delivery.tracking_number,
                    request_token: createClientRequestToken(),
                },
                controller.signal,
            );
            playSound('seal');
            setLastResult((previous) => {
                if (!previous) return previous;
                const prevSlot = previous.delivery.mother_bag_slotting ?? { bagged: false };
                return {
                    ...previous,
                    delivery: {
                        ...previous.delivery,
                        mother_bag_slotting: {
                            ...prevSlot,
                            bagged: true,
                            reference: packed.bag.reference,
                            status: packed.bag.status ?? 'open',
                            parcel_count: packed.capacity?.parcel_count ?? ((prevSlot.parcel_count ?? 0) + 1),
                            max_parcels: packed.capacity?.max_parcels ?? prevSlot.max_parcels ?? 50,
                            capacity_pct: packed.capacity?.capacity_pct ?? prevSlot.capacity_pct ?? 2,
                            weight_kg: packed.capacity?.weight_kg ?? prevSlot.weight_kg,
                        },
                    },
                };
            });
            setSlotFeedback(`Packed ${lastResult.delivery.tracking_number} into open Mother Bag ${packed.bag.reference}.`);
            await refreshPage().catch(() => {});
        } catch (error) {
            playSound('error');
            setScanError(error instanceof Error ? error.message : 'Could not add parcel to an open Mother Bag.');
        } finally {
            setSlottingBusy(false);
        }
    };

    const handleBarcodeProcess = (barcode: string) => {
        const extracted = waybillCode(barcode, window.location.origin) ?? barcode;
        const code = extracted.trim();
        if (!code) return;
        const upperCode = code.toUpperCase();
        const isNonDirectDocument =
            upperCode.startsWith('BAG-') || upperCode.startsWith('RMB-') || upperCode.startsWith('BRT-');

        const effectivelyOffline = !isOnline || !readNavigatorOnline();
        if (effectivelyOffline) {
            if (isNonDirectDocument) {
                playSound('error');
                setScanError('Mother Bags and buyer return tags require an active connection to inspect before confirming.');
                return;
            }
            enqueueScanForBatch(code);
            return;
        }

        if (scanMode === 'verify' || isNonDirectDocument) {
            return runScan({
                barcode: extracted,
                mode: 'inspect',
                ...(locationBarcode.trim() ? { location_barcode: locationBarcode.trim() } : {}),
            });
        }

        return runDirectConfirmScan(code);
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
        >
            <Head title="Scan Station — Logistics Hub — BagooPH" />

            {/* Compact Workstation Header */}
            <div className="mb-4 flex flex-wrap items-end justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-[28px] font-bold leading-[34px] tracking-tight text-ink-900">Scan station</h1>
                    {activeHub && (
                        <p className="mt-1 flex items-center gap-1.5 text-sm text-ink-600">
                            <Building2 className="h-4 w-4 text-ink-500" aria-hidden="true" />
                            <span>{activeHub.name} · {activeHub.code}</span>
                        </p>
                    )}
                </div>

                <section aria-label="Station counts and expected arrivals" className="flex flex-wrap items-end gap-6">
                    <div>
                        <p className="text-xl font-bold leading-7 text-ink-900 tabular-nums">{stats.parcels_in_hub}</p>
                        <p className="text-xs text-ink-600">In custody</p>
                    </div>
                    <div>
                        <p className="text-xl font-bold leading-7 text-ink-900 tabular-nums">{stats.ready_pickup}</p>
                        <p className="text-xs text-ink-600">Counter ready</p>
                    </div>
                    <div>
                        <p className="text-xl font-bold leading-7 text-ink-900 tabular-nums">{stats.dispatched_today}</p>
                        <p className="text-xs text-ink-600">Dispatched today</p>
                    </div>
                </section>
            </div>

            {/* Underline Navigation Tabs + Live Wedge Status */}
            <div className="mb-5 flex flex-wrap items-center justify-between gap-3 border-b border-line-200">
                <div role="group" aria-label="Scanner views" className="-mb-px flex max-w-full items-center gap-6 overflow-x-auto text-sm font-sans">
                    <button
                        type="button"
                        onClick={() => setActiveTab('scan')}
                        aria-pressed={activeTab === 'scan'}
                        className={`inline-flex items-center gap-2 border-b-2 py-2.5 font-semibold transition cursor-pointer shrink-0 ${
 activeTab === 'scan'
 ? 'border-brand-500 text-brand-500'
 : 'border-transparent text-zinc-600 hover:text-ink-900'
 }`}
                    >
                        <ScanLine className="w-3.5 h-3.5" />
                        <span>Scan parcels</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => setActiveTab('batch')}
                        aria-pressed={activeTab === 'batch'}
                        className={`inline-flex items-center gap-2 border-b-2 py-2.5 font-semibold transition cursor-pointer shrink-0 ${
 activeTab === 'batch'
 ? 'border-brand-500 text-brand-500'
 : 'border-transparent text-zinc-600 hover:text-ink-900'
 }`}
                    >
                        <Layers className="w-3.5 h-3.5" />
                        <span>Batch queue</span>
                        {queuedScans.length > 0 && (
                            <span className="rounded-chip bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-600 tabular-nums">
                                {queuedScans.length}
                            </span>
                        )}
                    </button>

                    {activeHub && (activeHub.allows_self_pickup || counterTotal > 0) && (
                        <button
                            type="button"
                            onClick={() => setActiveTab('counter')}
                            aria-pressed={activeTab === 'counter'}
                            className={`inline-flex items-center gap-2 border-b-2 py-2.5 font-semibold transition cursor-pointer shrink-0 ${
 activeTab === 'counter'
 ? 'border-brand-500 text-brand-500'
 : 'border-transparent text-zinc-600 hover:text-ink-900'
 }`}
                        >
                            <Boxes className="w-3.5 h-3.5" />
                            <span>Counter queue</span>
                            {counterTotal > 0 && (
                                <span className="rounded-chip bg-surface-100 px-1.5 py-0.5 text-xs font-medium text-ink-700 tabular-nums">
                                    {counterTotal}
                                </span>
                            )}
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => setActiveTab('logs')}
                        aria-pressed={activeTab === 'logs'}
                        className={`inline-flex items-center gap-2 border-b-2 py-2.5 font-semibold transition cursor-pointer shrink-0 ${
 activeTab === 'logs'
 ? 'border-brand-500 text-brand-500'
 : 'border-transparent text-zinc-600 hover:text-ink-900'
 }`}
                    >
                        <Clock className="w-3.5 h-3.5" />
                        <span>Scan history</span>
                    </button>
                </div>

                <div className="pb-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-chip px-2 py-1 text-xs font-medium ${
 isOnline ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'
 }`}>
                        <span aria-hidden="true" className={`h-1.5 w-1.5 rounded-full ${isOnline ? 'bg-success' : 'bg-warning'}`} />
                        {isOnline ? 'Scanner ready' : 'Offline · scans will queue'}
                    </span>
                </div>
            </div>

            {/* TAB 1: Scan Workstation */}
            {activeTab === 'scan' && (
                <div className="space-y-4">
                    {/* Error Banner */}
                    {scanError && (
                        <div role="alert" className="bg-red-50 border border-red-300 text-red-900 rounded-xl p-4 flex items-start gap-3 shadow-xs">
                            <AlertCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans leading-relaxed">
                                <span className="font-bold">Scan Routing Error:</span> {scanError}
                                {recoveryUrl && <Link href={recoveryUrl} className="mt-2 block font-semibold underline underline-offset-4 text-red-700 hover:text-red-900">Open parcel review</Link>}
                                {sessionExpired && <button type="button" onClick={() => window.location.reload()}
                                    className="mt-2 block rounded-lg border border-red-300 bg-white px-3 py-1.5 font-semibold text-red-800 shadow-xs hover:bg-red-50 transition cursor-pointer">Reload scanner</button>}
                            </div>
                        </div>
                    )}

                    {scanMessage && (
                        <div role="status" className={`${lastResult?.alreadyReceived ? 'bg-amber-50 border-amber-300 text-amber-900' : 'bg-white border-zinc-200 text-ink-900'} border rounded-xl p-4 flex items-start gap-3 shadow-xs`}>
                            <CheckCircle2 className={`w-5 h-5 ${lastResult?.alreadyReceived ? 'text-amber-700' : 'text-brand-500'} flex-shrink-0 mt-0.5`} />
                            <div className="text-xs font-medium font-sans leading-relaxed">{scanMessage}</div>
                        </div>
                    )}

                    {returnResult && <section className="mb-4 rounded-xl border border-zinc-200 bg-white p-5 shadow-xs"><h2 className="font-bold text-zinc-900">Buyer return {returnResult.reference}</h2><p className="mt-2 break-all text-xs text-zinc-600">{returnResult.barcode}</p><p className="mt-2 text-xs text-zinc-600">This scan identifies the return. Record its actual intake or manifest receipt from the case.</p><Link href={returnResult.href} className="mt-4 inline-flex rounded-lg bg-brand-500 px-4 py-2.5 text-xs font-bold text-white shadow-xs hover:bg-brand-600 transition cursor-pointer">Open buyer return</Link></section>}

                    <ScanModeControls
                        scanMode={scanMode}
                        onModeChange={setScanMode}
                        pinnedAction={pinnedAction}
                        onPinnedActionChange={setPinnedAction}
                        locationBarcode={locationBarcode}
                        onLocationBarcodeChange={setLocationBarcode}
                        queuedCount={queuedScans.length}
                        isOnline={isOnline}
                        onOpenBatchQueue={() => setActiveTab('batch')}
                    />

                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">
                        {/* LEFT COLUMN: Physical & Optical Barcode Scanner (col-span-6) */}
                        <div className="lg:col-span-6 space-y-4">
                            <div className="bg-white rounded-xl border border-zinc-200 shadow-xs overflow-hidden">
                                <div className="p-5 space-y-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <label htmlFor="barcode-field" className="text-sm font-bold text-ink-900 font-sans">
                                            Scan or enter waybill
                                        </label>

                                        <button
                                            type="button"
                                            onClick={() => setAudioEnabled(!audioEnabled)}
                                            aria-pressed={audioEnabled}
                                            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-line-300 bg-white text-ink-700 transition-colors hover:border-ink-400 hover:bg-surface-100 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 cursor-pointer"
                                            title={audioEnabled ? 'Sound on' : 'Sound off'}
                                        >
                                            {audioEnabled ? <Volume2 className="h-4 w-4" aria-hidden="true" /> : <VolumeX className="h-4 w-4 text-ink-400" aria-hidden="true" />}
                                            <span className="sr-only">{audioEnabled ? 'Audio ON' : 'Muted'}</span>
                                        </button>
                                    </div>

                                    <form onSubmit={handleInputSubmit} className="space-y-2.5">
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
                                                    placeholder="Ready for scanner — or type tracking code..."
                                                    disabled={isSubmitting}
                                                    autoFocus
                                                    className="min-h-11 w-full bg-white border border-zinc-300 text-zinc-900 placeholder-zinc-400 rounded-xl pl-10 pr-3.5 py-2 text-sm font-sans focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 shadow-2xs outline-hidden transition"
                                                />
                                            </div>
                                            <button
                                                type="submit"
                                                disabled={isSubmitting || !barcodeInput.trim()}
                                                className="px-5 py-2 bg-brand-500 hover:bg-brand-600 border border-brand-600 disabled:opacity-50 text-white font-bold text-xs rounded-xl shadow-xs transition flex items-center gap-1.5 font-sans cursor-pointer shrink-0 disabled:cursor-not-allowed"
                                            >
                                                {isSubmitting ? (
                                                    <RefreshCw className="w-4 h-4 animate-spin" />
                                                ) : (
                                                    <>
                                                        <span>Scan</span>
                                                        <ArrowRight className="w-3.5 h-3.5" />
                                                    </>
                                                )}
                                            </button>
                                        </div>
                                    </form>
                                </div>

                                {/* Integrated Optical Camera Footer Section */}
                                <div className="border-t border-zinc-100 px-5 py-4 bg-white">
                                    <WaybillCamera
                                        key={activeHub?.id}
                                        onScan={handleBarcodeProcess}
                                        disabled={isSubmitting}
                                        instruction=""
                                        layout="split"
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
                                        <span className={`text-xs px-2.5 py-1 rounded-sm font-bold uppercase tabular-nums ${
 masterBagResult.status === 'received'
 ? 'bg-zinc-100 text-ink-900 border border-zinc-300'
 : 'bg-amber-100 text-amber-800 border border-amber-300'
 }`}>
                                            {masterBagResult.status}
                                        </span>
                                    </div>

                                    <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-5 text-center shadow-2xs">
                                        <p className="text-xs tracking-wider text-zinc-500 uppercase font-bold">Master Bag Tag</p>
                                        <p className="text-2xl font-black tracking-tight text-ink-900 mt-1">{masterBagResult.reference}</p>
                                        <p className="text-xs text-zinc-600 mt-1.5">
                                            Numbered Tamper Seal: <span className="text-zinc-900 font-bold">{masterBagResult.seal_number || 'N/A'}</span>
                                        </p>
                                    </div>

                                    <div className="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4">
                                        <p className="text-sm text-zinc-700">Bag identified. Check the actual seal, then scan each parcel at receiving.</p>
                                        <button type="button" disabled={isSubmitting} onClick={openBagReceiving} className="w-full rounded-lg bg-brand-500 px-4 py-3 text-sm font-bold text-white hover:bg-brand-600 disabled:opacity-50 cursor-pointer">Open bag receiving</button>
                                    </div>

                                    {masterBagResult.parcels && masterBagResult.parcels.length > 0 && (
                                        <div className="border-t border-zinc-100 pt-3 space-y-1.5">
                                            <p className="text-xs font-bold text-zinc-900">Contained Parcels ({masterBagResult.parcels.length})</p>
                                            <div className="max-h-36 overflow-y-auto divide-y divide-zinc-100 text-xs tabular-nums">
                                                {masterBagResult.parcels.map((p, idx) => (
                                                    <div key={idx} className="py-1.5 flex items-center justify-between">
                                                        <span className="text-ink-900 font-semibold">{p.tracking_number}</span>
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
                                    {/* Route Target Badge */}
                                    {lastResult.delivery.routing_target && (
                                        <div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-5 shadow-2xs flex items-center justify-between gap-4">
                                            <div>
                                                <span className="text-xs font-bold tracking-wider uppercase text-zinc-500">
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
                                                <span className="inline-flex items-center justify-center rounded-chip bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-600">
                                                    {lastResult.delivery.destination_bin ? `BIN: ${lastResult.delivery.destination_bin}` : 'SORT QUEUE'}
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    {lastResult.delivery.next_hop && lastResult.delivery.next_hop.hub_id === activeHub?.id && <div className="rounded-lg border border-zinc-200 bg-slate-50/70 p-4">
                                        <p className="text-xs font-semibold uppercase text-zinc-700">Local delivery staging</p>
                                        <p className="mt-1 font-semibold text-slate-900">{lastResult.delivery.next_hop.recommended_bin}</p>
                                    </div>}

                                    {/* AUTOMATED DWS INDUCTION PROFILE & CHUTE DISPLAY */}
                                    {lastResult.delivery.dws && (
                                        <div className="rounded-xl border border-zinc-200 bg-white p-4.5 shadow-xs space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Scale className="h-4 w-4 text-zinc-700" />
                                                    <span className="text-xs font-bold text-zinc-900 uppercase tracking-wider font-sans">
                                                        Handling estimate
                                                    </span>
                                                </div>
                                                <span className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-100 border border-zinc-200/80 px-2.5 py-1 text-xs font-bold text-zinc-800">
                                                    <Compass className="h-3.5 w-3.5 text-zinc-700" />
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
                                                <div className="rounded-lg bg-zinc-100 p-2.5 border border-zinc-200">
                                                    <span className="text-[10px] uppercase font-bold text-zinc-500 block">Chargeable</span>
                                                    <span className="font-extrabold text-ink-900 tabular-nums">
                                                        {lastResult.delivery.dws.chargeable_weight_kg.toFixed(2)} kg
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between pt-1 text-[11px] text-zinc-500 border-t border-zinc-100">
                                                <span>Volume: <strong className="text-zinc-700">{lastResult.delivery.dws.volume_cbm.toFixed(4)} CBM</strong></span>
                                                <span className={`px-2 py-0.5 rounded font-bold uppercase text-[10px] ${
 lastResult.delivery.dws.is_oversized
 ? 'bg-amber-100 text-amber-800 border border-amber-300'
 : 'bg-zinc-100 text-ink-900 border border-zinc-300'
 }`}>
                                                    {lastResult.delivery.dws.is_oversized ? 'Loose Freight (Oversized)' : 'Standard Mother Bag Pouch'}
                                                </span>
                                            </div>
                                        </div>
                                    )}

                                    {/* MOTHER BAG AUTO-SLOTTING PROGRESS CARD */}
                                    {lastResult.delivery.mother_bag_slotting?.classification !== 'mother_bag' && lastResult.delivery.mother_bag_slotting?.reason && <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{lastResult.delivery.mother_bag_slotting.reason}</p>}
                                    {lastResult.delivery.mother_bag_slotting?.classification === 'mother_bag' && (
                                        <div className="rounded-xl border border-zinc-200 bg-zinc-50/70 p-4.5 shadow-2xs space-y-3">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <Boxes className="h-4 w-4 text-zinc-700" />
                                                    <span className="text-xs font-bold text-zinc-900 uppercase tracking-wider font-sans">
                                                        {lastResult.delivery.mother_bag_slotting.bagged ? 'Recorded packing' : 'Suggested packing'}
                                                    </span>
                                                </div>
                                                <span className="text-xs font-bold text-ink-900 bg-white border border-zinc-300 px-2.5 py-0.5 rounded-lg tabular-nums">
                                                    {lastResult.delivery.mother_bag_slotting.reference || 'New bag required'}
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
                                                        className="bg-brand-500 h-2 rounded-full transition-all duration-300"
                                                        style={{ width: `${Math.min(100, Math.max(5, lastResult.delivery.mother_bag_slotting.capacity_pct || 2))}%` }}
                                                    />
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between text-[11px] text-zinc-600 pt-1">
                                                <span>Retained package weight: <strong>{lastResult.delivery.mother_bag_slotting.weight_kg == null ? 'Needs readings' : `${lastResult.delivery.mother_bag_slotting.weight_kg.toFixed(3)} kg`}</strong> / 25.0 kg</span>
                                                <span className="font-medium text-zinc-500">
                                                    {lastResult.delivery.mother_bag_slotting.seal_number
                                                        ? `Seal: ${lastResult.delivery.mother_bag_slotting.seal_number}`
                                                        : 'Numbered seal required before departure'}
                                                </span>
                                            </div>
                                            {!lastResult.delivery.mother_bag_slotting.bagged &&
                                                activeHub &&
                                                lastResult.delivery.dws?.is_baggable !== false && (
                                                    <div className="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/80 pt-2.5">
                                                        <span className="text-xs text-zinc-600">
                                                            Slot this pouch straight into the open Mother Bag from here.
                                                        </span>
                                                        <button
                                                            type="button"
                                                            disabled={slottingBusy || isSubmitting}
                                                            onClick={packCurrentParcelIntoOpenBag}
                                                            className={hubButton}
                                                        >
                                                            {slottingBusy ? 'Packing...' : 'Add to open Mother Bag'}
                                                        </button>
                                                    </div>
                                                )}
                                            {slotFeedback && (
                                                <p className="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-medium text-ink-900">
                                                    {slotFeedback}
                                                </p>
                                            )}
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

                                    {/* Next Action Card for Manifest Transfer */}
                                    {(lastResult.delivery.guidance.action === 'manifest' || lastResult.prompt.manifest_url) && lastResult.delivery.guidance.can_act && (
                                        <div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-6 shadow-xs space-y-4">
                                            <div className="flex items-start gap-3.5">
                                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-500">
                                                    <Truck className="h-5 w-5" />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2">
                                                        <span className="text-xs font-bold uppercase tracking-wider text-zinc-600">
                                                            Next Operational Step
                                                        </span>
                                                        {lastResult.alreadyReceived && (
                                                            <span className="rounded-lg border border-zinc-200 bg-zinc-100 px-2 py-0.5 text-xs font-bold text-ink-900">
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
                                                        className="inline-flex items-center gap-2 rounded-xl bg-brand-500 px-5 py-3 text-sm font-bold text-white shadow-xs hover:bg-brand-600 transition cursor-pointer active:scale-98 disabled:opacity-50"
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
                                                className="w-full rounded-xl bg-brand-500 px-4 py-2.5 text-xs font-bold text-white hover:bg-brand-600 disabled:bg-zinc-300 shadow-xs cursor-pointer transition"
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
                                            className="rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 disabled:opacity-50 shadow-2xs hover:bg-zinc-50 hover:text-ink-900 transition cursor-pointer">Check for updates</button>
                                        {lastResult.delivery.registry_url && !lastResult.delivery.guidance.can_act && <Link href={lastResult.delivery.registry_url}
                                            className="rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50 hover:text-ink-900 transition">View parcel records</Link>}
                                        <button type="button" disabled={isSubmitting} onClick={() => {
                                            setLastResult(null); setScanMessage(null); setSlotFeedback(null); setBarcodeInput('');
                                             setDwsLength(''); setDwsWidth(''); setDwsHeight(''); setDwsWeightGrams('');
                                            barcodeInputRef.current?.focus();
                                        }} className="rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-xs font-semibold text-zinc-700 disabled:opacity-50 shadow-2xs hover:bg-zinc-50 hover:text-ink-900 transition cursor-pointer">Scan next parcel</button>
                                    </div>

                                    {/* Collapsible DWS Volumetric Weight Sizing Tool */}
                                    <details className="rounded-xl border border-zinc-200 bg-white p-4 shadow-2xs">
                                        <summary className="flex cursor-pointer list-none items-center justify-between text-xs font-bold text-zinc-900">
                                            <span className="flex items-center gap-2">
                                                <Scale className="h-4 w-4 text-zinc-700" />
                                                <span>Weight and size calculator</span>
                                            </span>
                                            <span className="text-xs font-medium text-zinc-500">
                                                Dimension Sizing · Optional check
                                            </span>
                                        </summary>

                                        <div className="mt-4 space-y-4">
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
                                                        className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-2xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 transition"
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
                                                        className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-2xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 transition"
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
                                                        className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-2xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 transition"
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
                                                        className="w-full rounded-xl border border-zinc-300 bg-white px-3.5 py-2.5 text-sm text-zinc-900 shadow-2xs focus:border-brand-500 focus:ring-2 focus:ring-brand-500/15 transition"
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
                                                        <span className="font-bold text-ink-900 bg-zinc-100 border border-zinc-300 px-2.5 py-1 rounded-lg tabular-nums">
                                                            {dwsBillableKg.toFixed(2)} kg
                                                        </span>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </details>

                                    {/* Assigned Sort Bin */}
                                    <div className="flex items-center justify-between p-4 bg-zinc-50 rounded-xl border border-zinc-200 shadow-2xs">
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
                                            <div className="text-xs uppercase text-zinc-500 font-sans font-bold tracking-wider">Waybill Code</div>
                                            <div className="text-xs font-bold text-ink-900 bg-white px-2.5 py-1 rounded-lg border border-zinc-200 inline-block mt-0.5 tabular-nums">
                                                {lastResult.delivery.tracking_number}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Recipient & Order Details */}
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                        <div className="bg-zinc-50 p-4 rounded-xl border border-zinc-200 shadow-2xs space-y-1">
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

                                        <div className="bg-zinc-50 p-4 rounded-xl border border-zinc-200 shadow-2xs space-y-1">
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

                                    {lastResult.scanEvidence && <details className="rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 text-xs text-zinc-700">
                                        <summary className="cursor-pointer font-semibold text-ink-900">Scan evidence · {lastResult.scanEvidence.route_hub_count} route hub(s)</summary>
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
                                <div className="bg-white rounded-xl border border-zinc-200 shadow-xs overflow-hidden font-sans">
                                    {/* Command Terminal Header */}
                                    <div className="flex items-center justify-between border-b border-zinc-100 px-5 py-3">
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-base font-semibold text-ink-900">Routing</h3>
                                        </div>
                                        <a
                                            href={`${basePath}/command-sheet`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="inline-flex items-center gap-1.5 text-xs text-zinc-600 hover:text-brand-500 font-semibold transition"
                                        >
                                            <Printer className="h-3.5 w-3.5" />
                                            <span>Print commands</span>
                                        </a>
                                    </div>

                                    {/* Centered Idle Body */}
                                    <div className="px-5 pt-7 pb-5">
                                        <div className="flex flex-col items-center text-center py-4">
                                            <div className="flex h-11 w-11 items-center justify-center rounded-full bg-surface-100 text-ink-500">
                                                <QrCode className="w-5 h-5" aria-hidden="true" />
                                            </div>
                                            <h4 className="mt-3 text-base font-semibold text-ink-900 font-sans">
                                                Ready for the next parcel
                                            </h4>
                                        </div>

                                        {/* Hardware & Keyboard Command Matrix */}
                                        <details className="group mt-6 border-t border-line-200 pt-3">
                                            <summary className="flex cursor-pointer list-none items-center gap-1.5 rounded-control py-1 text-[13px] font-medium text-ink-700 hover:text-ink-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand-500 [&::-webkit-details-marker]:hidden">
                                                <Zap className="h-3.5 w-3.5 text-ink-500" aria-hidden="true" />
                                                <span>Scanner commands</span>
                                            </summary>
                                            <div className="mt-2 space-y-2 text-[13px]">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-zinc-600">Confirm intake or bag seal</span>
                                                    <kbd className="rounded-chip bg-surface-100 px-2 py-0.5 tabular-nums text-xs font-medium text-ink-700">
                                                        CMD-CONFIRM
                                                    </kbd>
                                                </div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-zinc-600">Fast dispatch to route target</span>
                                                    <kbd className="rounded-chip bg-surface-100 px-2 py-0.5 tabular-nums text-xs font-medium text-ink-700">
                                                        CMD-DISPATCH
                                                    </kbd>
                                                </div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-zinc-600">Reset terminal &amp; focus input</span>
                                                    <kbd className="rounded-chip bg-surface-100 px-2 py-0.5 tabular-nums text-xs font-medium text-ink-700">
                                                        CMD-CLEAR
                                                    </kbd>
                                                </div>
                                            </div>
                                        </details>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>

                    {(queuedScans.length > 0 || lastBatchSync !== null || !isOnline) && (
                        <BatchQueueWorkbench
                            queue={queuedScans}
                            isSyncing={isSubmitting}
                            isOnline={isOnline}
                            lastSync={lastBatchSync}
                            onRemoveItem={(token) => updateQueue(queuedScans.filter((item) => item.request_token !== token))}
                            onClearQueue={() => updateQueue([])}
                            onSyncQueue={syncBatchQueue}
                        />
                    )}

                    {/* AUTOMATED FLEET SIZING & CORRIDOR DISPATCH (TMS) */}
                    {corridorStaging && corridorStaging.length > 0 && (
                        <div className="bg-white rounded-xl border border-zinc-200/90 shadow-xs overflow-hidden mt-2 font-sans">
                            <div className="flex flex-wrap items-center justify-between border-b border-zinc-200/80 bg-zinc-50/70 px-5 py-3.5 gap-2">
                                <div className="flex items-center gap-2.5">
                                    <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-white border border-zinc-200 text-zinc-700">
                                        <Truck className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <h3 className="text-xs font-bold uppercase tracking-wider text-zinc-900">
                                            Outbound staging
                                        </h3>
                                        <p className="text-[11px] text-zinc-500 font-medium">
                                            Purchased next hubs and retained package readings
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="rounded-lg bg-zinc-100 border border-zinc-200 px-2.5 py-1 text-[11px] font-bold text-zinc-800 tabular-nums">
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
                                                        {activeHub?.code} {'->'} {corridor.destination_hub.name}
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
                                                    <span>Loose cargo: <strong className="text-zinc-900 font-semibold">{corridor.loose_cargo_count}</strong></span>
                                                    <span>Parcels: <strong className="text-zinc-900 font-semibold">{corridor.total_parcels}</strong></span>
                                                    <span>Package weight: <strong className="text-zinc-900 font-semibold">{corridor.total_weight_kg == null ? 'Needs readings' : `${corridor.total_weight_kg.toFixed(3)} kg`}</strong>{corridor.total_cbm != null && ` (${corridor.total_cbm.toFixed(3)} m³)`}</span>
                                                    <span>Unpacked: <strong>{corridor.unpacked_count}</strong></span>
                                                </div>
                                            </div>

                                            {/* Vehicle Sizing & Match Badge */}
                                            <div className="flex flex-col sm:flex-row sm:items-center gap-3 shrink-0">
                                                <div className="rounded-xl border border-zinc-200/90 bg-zinc-50 p-3 min-w-[210px] space-y-1.5">
                                                    <div className="flex items-center justify-between text-xs">
                                                        <span className="text-zinc-500 font-medium">Required vehicle:</span>
                                                        <span className="font-bold text-zinc-900">{corridor.sizing.vehicle_name}</span>
                                                    </div>
                                                    <div>
                                                        <div className="flex items-center justify-between text-[11px] text-zinc-500 mb-1">
                                                            <span>Configured kg capacity</span>
                                                            <span className="font-bold text-zinc-800 tabular-nums">{corridor.sizing.payload_utilization_pct == null ? 'No eligible match' : `${corridor.sizing.payload_utilization_pct}%`}</span>
                                                        </div>
                                                        <div className="w-full bg-zinc-200 rounded-full h-1.5 overflow-hidden">
                                                            <div
                                                                className={`h-1.5 rounded-full ${
 (corridor.sizing.payload_utilization_pct ?? 0) > 90
 ? 'bg-amber-500'
 : 'bg-brand-500'
 }`}
                                                                style={{ width: `${Math.min(100, Math.max(0, corridor.sizing.payload_utilization_pct ?? 0))}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                    <div className="text-[11px] text-zinc-600 flex items-center justify-between pt-0.5 border-t border-zinc-200/60">
                                                        <span>Vehicle: <strong className="text-zinc-800">{corridor.matched_vehicle?.plate_number || 'No eligible match'}</strong></span>
                                                        <span className="truncate max-w-[100px] text-zinc-500">{corridor.matched_vehicle?.driver_name || ''}</span>
                                                    </div>
                                                </div>

                                                {corridor.blocker && <div className="max-w-xs text-xs text-amber-800">
                                                    <p>{corridor.blocker}</p>
                                                    <Link className="mt-2 inline-flex rounded-lg border border-zinc-300 bg-white px-3 py-2 font-semibold text-zinc-800 hover:bg-zinc-100" href={`${basePath}/mother-bags?hub_id=${activeHub?.id}`}>Open packing</Link>
                                                </div>}
                                                <button
                                                    type="button"
                                                    disabled={isSubmitting || !corridor.can_dispatch}
                                                    onClick={() => triggerFastDispatch(corridor.destination_hub.id, true)}
                                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-500 px-5 py-3 text-xs font-bold text-white shadow-xs hover:bg-brand-600 active:scale-98 transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
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

            {/* TAB 4: Offline & Batch Queue Workbench */}
            {activeTab === 'batch' && (
                <div className="space-y-4">
                    <BatchQueueWorkbench
                        queue={queuedScans}
                        isSyncing={isSubmitting}
                        isOnline={isOnline}
                        lastSync={lastBatchSync}
                        onRemoveItem={(token) => updateQueue(queuedScans.filter((item) => item.request_token !== token))}
                        onClearQueue={() => updateQueue([])}
                        onSyncQueue={syncBatchQueue}
                    />
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
                                    className="w-full text-left bg-white border border-amber-200/80 rounded-xl px-4 py-3 hover:border-zinc-400 shadow-xs hover:shadow-sm transition cursor-pointer"
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

            <GatePassDialog gatePass={activeGatePass} onClose={() => setActiveGatePass(null)} />
        </DashboardLayout>
    );
}
