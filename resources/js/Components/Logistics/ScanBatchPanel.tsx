import React from 'react';
import {
    AlertCircle,
    ArrowRight,
    CheckCircle2,
    Layers,
    Printer,
    QrCode,
    RefreshCw,
    ShieldCheck,
    Trash2,
    UploadCloud,
    WifiOff,
    Zap,
} from 'lucide-react';
import Modal from '@/Components/Modal';
import { hubButton, hubInput, hubPrimary } from '@/Components/Logistics/Workspace';
import { waybillCsrfHeaders, WaybillRequestError } from '@/utils/waybillScan';

export type ScanOperationMode = 'verify' | 'direct';

export interface QueuedScanItem {
    request_token: string;
    barcode: string;
    location_barcode?: string;
    expected_action?: string;
    notes?: string;
    client_scanned_at: string;
}

export interface BatchSyncItemResult {
    item_index: number;
    request_token: string;
    barcode: string;
    tracking_number: string | null;
    delivery_id: number | null;
    outcome: 'confirmed' | 'duplicate_replay' | 'rejected';
    action_applied: string | null;
    status_before: string | null;
    status_after: string | null;
    checkpoint_reference: string | null;
    latency_ms: number;
    error: string | null;
}

export interface BatchSyncSummary {
    submitted: number;
    confirmed: number;
    duplicate_replay: number;
    rejected: number;
    elapsed_ms: number;
    scans_per_sec: number;
}

export interface BatchSyncResponse<TScanResult = unknown> {
    success: boolean;
    batch_reference: string;
    hub?: {
        id: number;
        name: string;
        code: string;
        tier: string;
    };
    direct_mode: boolean;
    pinned_action: string | null;
    summary: BatchSyncSummary;
    items: BatchSyncItemResult[];
    scan_result?: TScanResult;
}

export interface GatePassData {
    gate_pass_code: string;
    manifest_reference: string;
    departure_gate: string;
    source_hub: { name: string; code: string };
    destination_hub: { name: string; code: string };
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

export const PINNED_SCAN_ACTIONS = [
    { value: 'ANY_PERMITTED_PARCEL_STEP', label: 'Any permitted parcel step (route auto-check)' },
    { value: 'RECEIVE_FROM_PICKUP_RIDER', label: 'Receive from pickup rider' },
    { value: 'SORT_TO_LINE_HAUL', label: 'Sort at Mother Hub for line-haul' },
    { value: 'STAGE_FOR_PICKUP', label: 'Stage for counter pickup' },
    { value: 'RECEIVE_FAILED_DELIVERY', label: 'Receive failed-delivery return from rider' },
    { value: 'RELEASE_APPROVED_RETRY', label: 'Release approved retry for sorting' },
    { value: 'STAGE_SELLER_RETURN', label: 'Stage seller return' },
] as const;

export function queueStorageKey(hubId: number): string {
    return `bagoo_scan_batch_queue_v1:${hubId}`;
}

export function loadQueuedScans(hubId: number | null | undefined): QueuedScanItem[] {
    if (!hubId || typeof window === 'undefined' || !window.localStorage || typeof window.localStorage.getItem !== 'function') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(queueStorageKey(hubId));
        if (!raw) {
            return [];
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }
        return parsed.filter(
            (entry): entry is QueuedScanItem =>
                Boolean(entry) &&
                typeof entry === 'object' &&
                typeof entry.request_token === 'string' &&
                typeof entry.barcode === 'string' &&
                entry.barcode.trim().length > 0 &&
                typeof entry.client_scanned_at === 'string',
        );
    } catch {
        return [];
    }
}

export function saveQueuedScans(hubId: number | null | undefined, items: QueuedScanItem[]): void {
    if (!hubId || typeof window === 'undefined' || !window.localStorage || typeof window.localStorage.setItem !== 'function') {
        return;
    }

    try {
        window.localStorage.setItem(queueStorageKey(hubId), JSON.stringify(items));
    } catch {
        // Ignore storage quota / private mode failures gracefully.
    }
}

export function createClientRequestToken(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    const template = '10000000-1000-4000-8000-100000000000';
    return template.replace(/[018]/g, (digit) => {
        const num = Number(digit);
        return (num ^ ((Math.random() * 16) >> (num / 4))).toString(16);
    });
}

export function enqueueScanItem(
    current: QueuedScanItem[],
    rawBarcode: string,
    options?: {
        locationBarcode?: string;
        expectedAction?: string;
        notes?: string;
        token?: string;
        clientScannedAt?: string;
    },
): { next: QueuedScanItem[]; added: boolean; duplicateBarcode: boolean } {
    const normalizedBarcode = rawBarcode.trim().toUpperCase();
    if (!normalizedBarcode) {
        return { next: current, added: false, duplicateBarcode: false };
    }

    if (current.some((item) => item.barcode.trim().toUpperCase() === normalizedBarcode)) {
        return { next: current, added: false, duplicateBarcode: true };
    }

    const expectedAction =
        options?.expectedAction && options.expectedAction !== 'ANY_PERMITTED_PARCEL_STEP'
            ? options.expectedAction
            : undefined;

    const item: QueuedScanItem = {
        request_token: options?.token ?? createClientRequestToken(),
        barcode: normalizedBarcode,
        location_barcode: options?.locationBarcode?.trim() || undefined,
        expected_action: expectedAction,
        notes: options?.notes?.trim() || undefined,
        client_scanned_at: options?.clientScannedAt ?? new Date().toISOString(),
    };

    return {
        next: [...current, item],
        added: true,
        duplicateBarcode: false,
    };
}

async function postJsonAction<TResponse>(
    path: string,
    body: Record<string, unknown>,
    signal: AbortSignal,
    fallbackError: string,
): Promise<TResponse> {
    if (!path.startsWith('/') || path.startsWith('//')) {
        throw new WaybillRequestError('Open the scanner in the current portal.');
    }

    let response: Response;
    try {
        response = await fetch(path, {
            method: 'POST',
            credentials: 'same-origin',
            mode: 'same-origin',
            cache: 'no-store',
            signal,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...waybillCsrfHeaders(
                    document.cookie,
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                ),
            },
            body: JSON.stringify(body),
        });
    } catch (error) {
        if (signal.aborted) {
            throw error;
        }
        throw new WaybillRequestError('Connection lost before the station responded. Your queue stays saved locally.');
    }

    if (response.status === 419) {
        throw new WaybillRequestError('Your session expired. Reload this scanner and sign in again if asked.', 419);
    }
    if (response.status === 401 || response.redirected) {
        throw new WaybillRequestError('Sign in again to continue scanning.', 401);
    }

    let data: Record<string, unknown>;
    try {
        data = await response.json();
    } catch {
        throw new WaybillRequestError(fallbackError, response.status);
    }

    if (!response.ok) {
        const errors = (data.errors ?? {}) as Record<string, unknown>;
        const fieldError = Object.values(errors).flat().find((value) => typeof value === 'string');
        const message =
            response.status >= 500
                ? 'The scanner is temporarily unavailable. Try again shortly.'
                : (fieldError ?? data.error ?? data.message ?? fallbackError);
        const recoveryUrl =
            typeof data.recovery_url === 'string' &&
            /^(?:\/(?:hub\/)?parcel-recovery\/[1-9][0-9]{0,17}|\/loss-found\/[1-9][0-9]{0,17}\?hub_id=[1-9][0-9]{0,17})$/.test(data.recovery_url)
                ? data.recovery_url
                : null;
        throw new WaybillRequestError(String(message), response.status, recoveryUrl);
    }

    return data as unknown as TResponse;
}

export async function requestBatchScan<TScanResult = unknown>(
    path: string,
    body: {
        hub_id: number;
        direct_mode: boolean;
        pinned_action?: string;
        items: QueuedScanItem[];
    },
    signal: AbortSignal,
): Promise<BatchSyncResponse<TScanResult>> {
    const data = await postJsonAction<BatchSyncResponse<TScanResult>>(
        path,
        body,
        signal,
        'The batch scan response could not be read. Try syncing again.',
    );

    if (data.success !== true || !Array.isArray(data.items) || !data.summary) {
        throw new WaybillRequestError('The batch scan summary was incomplete. Try syncing again.');
    }

    return data;
}

export async function packOpenMotherBag(
    path: string,
    body: {
        source_hub_id: number;
        barcode: string;
        request_token: string;
    },
    signal: AbortSignal,
): Promise<{
    bag: {
        id?: string;
        reference: string;
        status?: string;
        physical_tag?: string | null;
    };
    capacity?: {
        parcel_count?: number;
        max_parcels?: number;
        capacity_pct?: number;
        weight_kg?: number | null;
    } | null;
}> {
    return postJsonAction(
        path,
        body,
        signal,
        'The parcel could not be placed into an open Mother Bag.',
    );
}

export function ScanModeControls({
    scanMode,
    onModeChange,
    pinnedAction,
    onPinnedActionChange,
    locationBarcode,
    onLocationBarcodeChange,
    queuedCount,
    isOnline,
    onOpenBatchQueue,
}: {
    scanMode: ScanOperationMode;
    onModeChange: (mode: ScanOperationMode) => void;
    pinnedAction: string;
    onPinnedActionChange: (action: string) => void;
    locationBarcode: string;
    onLocationBarcodeChange: (code: string) => void;
    queuedCount: number;
    isOnline: boolean;
    onOpenBatchQueue?: () => void;
}) {
    const modes: Array<{ id: ScanOperationMode; label: string }> = [
        {
            id: 'verify',
            label: '2-Step Verify',
        },
        {
            id: 'direct',
            label: '1-Scan Direct',
        },
    ];

    return (
        <section
            aria-label="Scanner speed and safety mode"
            className="rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-xs space-y-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-3">
                    <h2 className="text-[11px] font-bold uppercase tracking-wider text-zinc-500">
                        Scan mode
                    </h2>
                    <div
                        role="radiogroup"
                        aria-label="Scanning workflow mode"
                        className="inline-flex items-center rounded-lg border border-zinc-200 bg-slate-100/80 p-0.5 gap-0.5"
                    >
                        {modes.map((option) => {
                            const active = scanMode === option.id;
                            return (
                                <button
                                    key={option.id}
                                    type="button"
                                    role="radio"
                                    aria-checked={active}
                                    onClick={() => onModeChange(option.id)}
                                    className={`rounded-md px-3 py-1 text-xs font-semibold transition cursor-pointer ${
 active
 ? option.id === 'direct'
 ? 'border border-brand-600 bg-brand-500 text-white shadow-2xs'
 : 'border border-zinc-200 bg-white text-ink-900 shadow-2xs'
 : 'border border-transparent text-zinc-600 hover:text-ink-900'
 }`}
                                >
                                    {option.label}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {(!isOnline || queuedCount > 0) && (
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        {!isOnline && (
                            <span className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-900">
                                <WifiOff className="h-3.5 w-3.5" />
                                Offline · Auto-queuing scans
                            </span>
                        )}
                        {isOnline && queuedCount > 0 && (
                            <button
                                type="button"
                                onClick={onOpenBatchQueue}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-brand-600 hover:bg-rose-100 transition cursor-pointer"
                            >
                                <Layers className="h-3.5 w-3.5" />
                                <span>{queuedCount} queued offline · Sync</span>
                            </button>
                        )}
                    </div>
                )}
            </div>

            {scanMode === 'direct' && (
                <div className="grid gap-3 border-t border-zinc-200 pt-3 sm:grid-cols-2">
                    <label className="block text-xs font-semibold text-zinc-700">
                        Lane Action Lock (optional guard)
                        <select
                            value={pinnedAction}
                            onChange={(event) => onPinnedActionChange(event.target.value)}
                            className={`${hubInput} mt-1.5`}
                        >
                            {PINNED_SCAN_ACTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block text-xs font-semibold text-zinc-700">
                        Station Bin / Shelf Barcode (optional)
                        <input
                            type="text"
                            maxLength={100}
                            value={locationBarcode}
                            onChange={(event) => onLocationBarcodeChange(event.target.value.toUpperCase())}
                            placeholder="e.g. LOC-BIN-01 or BAY-A2"
                            className={`${hubInput} mt-1.5`}
                        />
                    </label>
                </div>
            )}
        </section>
    );
}

export function BatchQueueWorkbench({
    queue,
    isSyncing,
    isOnline,
    lastSync,
    onRemoveItem,
    onClearQueue,
    onSyncQueue,
}: {
    queue: QueuedScanItem[];
    isSyncing: boolean;
    isOnline: boolean;
    lastSync: BatchSyncResponse | null;
    onRemoveItem: (token: string) => void;
    onClearQueue: () => void;
    onSyncQueue: () => void;
}) {
    return (
        <section
            aria-label="Offline and batch scan queue"
            className="rounded-xl border border-zinc-200/90 bg-white shadow-xs overflow-hidden space-y-4 p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 pb-3.5">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-ink-900">
                        <Layers className="h-4 w-4" />
                    </span>
                    <div>
                        <h3 className="text-sm font-semibold text-ink-900">
                            Batch Queue ({queue.length} pending)
                        </h3>
                        <p className="text-xs text-zinc-500">
                            Stored locally with idempotent tokens. Mother Bags and buyer returns must still be inspected separately.
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {queue.length > 0 && (
                        <button
                            type="button"
                            disabled={isSyncing}
                            onClick={onClearQueue}
                            className={hubButton}
                        >
                            Clear queue
                        </button>
                    )}
                    <button
                        type="button"
                        disabled={isSyncing || queue.length === 0 || !isOnline}
                        onClick={onSyncQueue}
                        className={hubPrimary}
                    >
                        {isSyncing ? <RefreshCw className="h-3.5 w-3.5 animate-spin" /> : <UploadCloud className="h-3.5 w-3.5" />}
                        <span>
                            {!isOnline
                                ? 'Waiting for connection'
                                : isSyncing
                                ? 'Syncing batch...'
                                : `Sync ${queue.length} queued ${queue.length === 1 ? 'scan' : 'scans'}`}
                        </span>
                    </button>
                </div>
            </div>

            {queue.length === 0 ? (
                <div className="rounded border border-dashed border-zinc-300 bg-zinc-50/60 px-4 py-6 text-center text-xs text-zinc-500">
                    No parcels queued right now. If internet connection drops during scanning, parcel scans are automatically buffered here and ready to sync when online.
                </div>
            ) : (
                <ul className="divide-y divide-zinc-100 rounded-xl border border-zinc-200/80 bg-zinc-50/40 max-h-60 overflow-y-auto">
                    {queue.map((item, idx) => (
                        <li key={item.request_token} className="flex items-center justify-between gap-3 px-4 py-2.5 text-xs">
                            <div className="min-w-0">
                                <span className="font-semibold text-ink-900 tabular-nums">
                                    #{idx + 1} · {item.barcode}
                                </span>
                                <p className="mt-0.5 text-xs text-zinc-500">
                                    {item.expected_action ? `Action lock: ${item.expected_action}` : 'Auto-check permitted step'}
                                    {item.location_barcode ? ` · Bin ${item.location_barcode}` : ''}
                                </p>
                            </div>
                            <button
                                type="button"
                                disabled={isSyncing}
                                onClick={() => onRemoveItem(item.request_token)}
                                aria-label={`Remove queued scan ${item.barcode}`}
                                className="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 cursor-pointer"
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                                <span>Remove</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {lastSync && (
                <div className="rounded-xl border border-zinc-200/90 bg-zinc-50/60 p-4 space-y-3" aria-label="Latest batch replay report">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2 text-xs font-semibold text-ink-900">
                            <Zap className="h-4 w-4 text-brand-500" />
                            <span>Last Batch Replay Results</span>
                        </div>
                        <span className="text-xs font-medium text-zinc-600 tabular-nums">
                            {lastSync.summary.elapsed_ms} ms total · {lastSync.summary.scans_per_sec} scans/sec measured
                        </span>
                    </div>

                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 text-xs">
                        <div className="rounded-lg border border-zinc-200 bg-white px-3 py-2">
                            <span className="block text-zinc-500">Submitted</span>
                            <strong className="text-sm font-bold text-ink-900 tabular-nums">{lastSync.summary.submitted}</strong>
                        </div>
                        <div className="rounded-lg border border-zinc-200 bg-white px-3 py-2">
                            <span className="block text-zinc-600">Confirmed</span>
                            <strong className="text-sm font-bold text-ink-900 tabular-nums">{lastSync.summary.confirmed}</strong>
                        </div>
                        <div className="rounded-lg border border-zinc-200 bg-white px-3 py-2">
                            <span className="block text-zinc-600">Idempotent replay</span>
                            <strong className="text-sm font-bold text-zinc-800 tabular-nums">{lastSync.summary.duplicate_replay}</strong>
                        </div>
                        <div className={`rounded-lg border px-3 py-2 ${lastSync.summary.rejected > 0 ? 'border-red-200 bg-red-50/70' : 'border-zinc-200 bg-white'}`}>
                            <span className={lastSync.summary.rejected > 0 ? 'block text-red-700' : 'block text-zinc-500'}>Rejected</span>
                            <strong className={`text-sm font-bold tabular-nums ${lastSync.summary.rejected > 0 ? 'text-red-800' : 'text-zinc-800'}`}>
                                {lastSync.summary.rejected}
                            </strong>
                        </div>
                    </div>

                    <ul className="divide-y divide-zinc-200/80 rounded-lg border border-zinc-200 bg-white max-h-56 overflow-y-auto">
                        {lastSync.items.map((item) => (
                            <li key={`${item.item_index}-${item.request_token}`} className="flex flex-wrap items-center justify-between gap-2 px-3.5 py-2.5 text-xs">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        {item.outcome === 'rejected' ? (
                                            <AlertCircle className="h-3.5 w-3.5 shrink-0 text-red-600" />
                                        ) : (
                                            <CheckCircle2 className="h-3.5 w-3.5 shrink-0 text-brand-500" />
                                        )}
                                        <span className="font-semibold text-ink-900 tabular-nums">{item.barcode}</span>
                                        <span
                                            className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${
 item.outcome === 'confirmed'
 ? 'border-zinc-300 bg-zinc-100 text-ink-900'
 : item.outcome === 'duplicate_replay'
 ? 'border-zinc-200 bg-zinc-100 text-zinc-700'
 : 'border-red-200 bg-red-50 text-red-800'
 }`}
                                        >
                                            {item.outcome === 'duplicate_replay' ? 'Replayed' : item.outcome}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-zinc-600">
                                        {item.error
                                            ? item.error
                                            : `${item.action_applied ?? 'Verified'} (${item.status_before ?? '—'} -> ${item.status_after ?? '—'})`}
                                    </p>
                                </div>
                                <span className="text-xs text-zinc-400 tabular-nums">{item.latency_ms} ms</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

export function GatePassDialog({
    gatePass,
    onClose,
}: {
    gatePass: GatePassData | null;
    onClose: () => void;
}) {
    return (
        <Modal show={Boolean(gatePass)} onClose={onClose} maxWidth="lg">
            {gatePass && (
                <div className="overflow-hidden bg-white font-sans">
                    <div className="bg-brand-500 p-6 text-white">
                        <div className="flex items-center justify-between">
                            <span className="inline-flex items-center gap-1.5 rounded-md bg-white/15 px-2.5 py-1 text-xs font-semibold text-white">
                                <ShieldCheck className="h-3.5 w-3.5" />
                                Official Vehicle Gate Pass
                            </span>
                            <span className="rounded bg-white/15 px-2.5 py-1 text-xs font-semibold text-white tabular-nums">
                                {gatePass.departure_gate}
                            </span>
                        </div>
                        <h2 id="gate-pass-title" className="mt-2 text-xl font-bold tracking-tight text-white">
                            {gatePass.gate_pass_code}
                        </h2>
                        <p className="mt-1 text-xs text-rose-100">
                            Manifest Reference: <strong className="text-white">{gatePass.manifest_reference}</strong>
                        </p>
                    </div>

                    <div className="space-y-4 p-6 text-xs">
                        <div className="space-y-2 rounded-xl border border-zinc-200 bg-zinc-50 p-4">
                            <div className="flex items-center justify-between text-xs font-semibold text-zinc-500">
                                <span>Source Hub</span>
                                <span>Destination Hub</span>
                            </div>
                            <div className="flex items-center justify-between text-sm font-bold text-ink-900">
                                <span>{gatePass.source_hub.name} ({gatePass.source_hub.code})</span>
                                <ArrowRight className="mx-2 h-4 w-4 text-zinc-400" />
                                <span>{gatePass.destination_hub.name} ({gatePass.destination_hub.code})</span>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1 rounded-xl border border-zinc-200 p-3.5">
                                <span className="block text-xs font-medium text-zinc-500">Assigned Driver</span>
                                <div className="text-sm font-bold text-ink-900">{gatePass.driver_name}</div>
                                <div className="text-xs text-zinc-500">{gatePass.driver_phone}</div>
                            </div>
                            <div className="space-y-1 rounded-xl border border-zinc-200 p-3.5">
                                <span className="block text-xs font-medium text-zinc-500">Vehicle Plate &amp; Class</span>
                                <div className="text-sm font-bold text-ink-900">{gatePass.vehicle_plate}</div>
                                <div className="text-xs font-semibold capitalize text-zinc-600">{gatePass.vehicle_type.replace(/_/g, ' ')}</div>
                            </div>
                        </div>

                        <div className="space-y-2 rounded-xl border border-zinc-200 p-4">
                            <div className="flex items-center justify-between">
                                <span className="font-semibold text-zinc-700">Payload Weight Utilization</span>
                                <span className="font-bold text-ink-900 tabular-nums">
                                    {gatePass.total_weight_kg.toFixed(1)} kg / {gatePass.vehicle_capacity_kg} kg ({gatePass.utilization_pct}%)
                                </span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-zinc-100">
                                <div
                                    className="h-2 rounded-full bg-brand-500"
                                    style={{ width: `${Math.min(100, Math.max(8, gatePass.utilization_pct))}%` }}
                                />
                            </div>
                            <div className="flex items-center justify-between pt-1 text-xs text-zinc-500">
                                <span>Mother Bags: <strong>{gatePass.total_mother_bags}</strong></span>
                                <span>Loose Cartons: <strong>{gatePass.total_loose_cargo}</strong></span>
                                <span>Total Parcels: <strong>{gatePass.total_parcels_enclosed}</strong></span>
                            </div>
                        </div>

                        {gatePass.seals && gatePass.seals.length > 0 && (
                            <div className="space-y-1.5 rounded-xl border border-zinc-200 bg-zinc-50/70 p-3.5">
                                <span className="block text-xs font-semibold text-zinc-700">
                                    Tamper Security Seals ({gatePass.seals.length})
                                </span>
                                <div className="flex flex-wrap gap-1.5">
                                    {gatePass.seals.map((seal, idx) => (
                                        <span key={idx} className="rounded-md border border-zinc-300 bg-white px-2 py-0.5 text-xs font-semibold text-ink-900 tabular-nums">
                                            {seal}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="flex items-center justify-between rounded-xl border border-zinc-200 bg-zinc-50 p-3">
                            <div className="flex items-center gap-2">
                                <QrCode className="h-5 w-5 text-zinc-700" />
                                <div>
                                    <span className="block text-xs font-medium text-zinc-500">Guardhouse Verification</span>
                                    <span className="block max-w-[220px] truncate text-xs text-zinc-700 tabular-nums">
                                        {gatePass.verification_hash}
                                    </span>
                                </div>
                            </div>
                            <span className="rounded border border-zinc-300 bg-zinc-100 px-2 py-0.5 text-xs font-bold uppercase text-ink-900">
                                CLEARED
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-zinc-200 bg-zinc-50 px-6 py-4">
                        <button type="button" onClick={() => window.print()} className={hubButton}>
                            <Printer className="h-4 w-4" />
                            <span>Print Gate Pass &amp; e-BOL</span>
                        </button>
                        <button type="button" onClick={onClose} className={hubPrimary}>
                            Done / Return to Scanner
                        </button>
                    </div>
                </div>
            )}
        </Modal>
    );
}
