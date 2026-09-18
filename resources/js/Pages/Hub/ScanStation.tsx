import React, { useState, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import {
    ScanLine,
    Camera,
    CameraOff,
    Truck,
    Package,
    CheckCircle2,
    AlertCircle,
    Building2,
    Volume2,
    VolumeX,
    ArrowRight,
    Search,
    MapPin,
    User,
    Phone,
    CornerDownRight,
    RefreshCw,
    Clock,
    Hash,
    Layers,
    ChevronRight,
    Sparkles,
    ShieldCheck,
    Navigation,
    Boxes
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
    destination_bin: string;
    buyer_name: string;
}

interface CounterPickupParcel {
    id: number;
    tracking_number: string;
    order_number?: string;
    buyer_name: string;
    buyer_phone: string;
    status: string;
    destination_bin: string;
    total_amount: number;
    payment_method: string;
    item_count: number;
}

interface ScanStationProps {
    activeHub: LogisticsHub | null;
    hubs: LogisticsHub[];
    recentScans: RecentScan[];
    counterPickups: CounterPickupParcel[];
    stats: {
        parcels_in_hub: number;
        ready_pickup: number;
        dispatched_today: number;
    };
    sampleTrackingNumbers: string[];
}

interface DynamicPrompt {
    action: string;
    prompt: string;
    next_status: string;
    color: string;
}

interface ScannedDeliveryResult {
    id: number;
    tracking_number: string;
    status: string;
    delivery_type: string;
    destination_bin: string;
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
    counterPickups,
    stats,
    sampleTrackingNumbers,
}: ScanStationProps) {
    const [activeTab, setActiveTab] = useState<'scan' | 'counter' | 'logs'>('scan');
    const [barcodeInput, setBarcodeInput] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [audioEnabled, setAudioEnabled] = useState(true);

    // Dynamic prompt state
    const [lastResult, setLastResult] = useState<{
        prompt: DynamicPrompt;
        delivery: ScannedDeliveryResult;
    } | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);

    // Counter pickup claim form state
    const [pickupSearch, setPickupSearch] = useState('');
    const [claimRecipientName, setClaimRecipientName] = useState('');
    const [claimNotes, setClaimNotes] = useState('');
    const [releasingId, setReleasingId] = useState<number | null>(null);
    const [releaseSuccess, setReleaseSuccess] = useState<string | null>(null);

    // Camera scanner state
    const [cameraActive, setCameraActive] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const barcodeInputRef = useRef<HTMLInputElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const detectorRef = useRef<any>(null);

    // Audio synthesizer
    const playSound = (type: 'success' | 'error') => {
        if (!audioEnabled) return;
        try {
            const AudioContext = window.AudioContext || (window as any).webkitAudioContext;
            if (!AudioContext) return;
            const ctx = new AudioContext();

            if (type === 'success') {
                const osc1 = ctx.createOscillator();
                const osc2 = ctx.createOscillator();
                const gain = ctx.createGain();

                osc1.type = 'sine';
                osc2.type = 'sine';
                osc1.frequency.setValueAtTime(880, ctx.currentTime);
                osc2.frequency.setValueAtTime(1320, ctx.currentTime + 0.08);

                gain.gain.setValueAtTime(0.2, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.25);

                osc1.connect(gain);
                osc2.connect(gain);
                gain.connect(ctx.destination);

                osc1.start(ctx.currentTime);
                osc1.stop(ctx.currentTime + 0.08);
                osc2.start(ctx.currentTime + 0.08);
                osc2.stop(ctx.currentTime + 0.25);
            } else {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sawtooth';
                osc.frequency.setValueAtTime(220, ctx.currentTime);
                gain.gain.setValueAtTime(0.25, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.35);
            }
        } catch {
            // Audio context not allowed or unsupported
        }
    };

    // Initialize BarcodeDetector API if supported
    useEffect(() => {
        if ('BarcodeDetector' in window) {
            try {
                detectorRef.current = new (window as any).BarcodeDetector({
                    formats: ['code_128', 'code_39', 'qr_code', 'ean_13', 'upc_a'],
                });
            } catch {
                detectorRef.current = null;
            }
        }
    }, []);

    // Camera start/stop
    const startCamera = async () => {
        setCameraError(null);
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
            });
            streamRef.current = stream;
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
                setCameraActive(true);
            }
        } catch (err: any) {
            setCameraError('Camera access denied or unavailable: ' + (err.message || 'Unknown error'));
            setCameraActive(false);
        }
    };

    const stopCamera = () => {
        if (streamRef.current) {
            streamRef.current.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        }
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setCameraActive(false);
    };

    useEffect(() => {
        return () => {
            stopCamera();
        };
    }, []);

    // Continuous frame scanning loop when camera is active
    useEffect(() => {
        let frameId: number;
        const scanFrame = async () => {
            if (cameraActive && videoRef.current && detectorRef.current && !isSubmitting) {
                try {
                    const barcodes = await detectorRef.current.detect(videoRef.current);
                    if (barcodes && barcodes.length > 0) {
                        const rawValue = barcodes[0].rawValue;
                        if (rawValue && rawValue.trim()) {
                            stopCamera();
                            handleBarcodeProcess(rawValue.trim());
                            return;
                        }
                    }
                } catch {
                    // Frame detection skip
                }
            }
            if (cameraActive) {
                frameId = requestAnimationFrame(scanFrame);
            }
        };

        if (cameraActive) {
            frameId = requestAnimationFrame(scanFrame);
        }

        return () => {
            cancelAnimationFrame(frameId);
        };
    }, [cameraActive, isSubmitting]);

    // Handle barcode intake API submission
    const handleBarcodeProcess = async (barcodeToScan: string) => {
        const code = barcodeToScan.trim();
        if (!code || isSubmitting) return;

        setIsSubmitting(true);
        setScanError(null);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const response = await fetch(route('hub.scan'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    barcode: code,
                    hub_id: activeHub?.id,
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                playSound('success');
                setLastResult({
                    prompt: data.prompt,
                    delivery: data.delivery,
                });
                setBarcodeInput('');
                // Refresh recent scans in background
                router.reload({ only: ['recentScans', 'stats', 'counterPickups'] });
            } else {
                playSound('error');
                setScanError(data.error || 'Failed to process barcode. Parcel not found.');
            }
        } catch (err: any) {
            playSound('error');
            setScanError(err.message || 'Network error while contacting routing engine.');
        } finally {
            setIsSubmitting(false);
            setTimeout(() => {
                barcodeInputRef.current?.focus();
            }, 100);
        }
    };

    // Handle Manual Input Submit
    const handleInputSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (barcodeInput.trim()) {
            handleBarcodeProcess(barcodeInput.trim());
        }
    };

    // Hub change handler
    const handleHubChange = (hubId: string) => {
        router.get(
            route('hub.scan.station'),
            { hub_id: hubId },
            { preserveState: false }
        );
    };

    // Bayan Hub Counter Release handover
    const handleCounterRelease = async (trackingNumber: string) => {
        if (!trackingNumber) return;
        setIsSubmitting(true);
        setReleaseSuccess(null);
        setScanError(null);

        try {
            const csrfToken = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content;
            const response = await fetch(route('hub.release'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    barcode: trackingNumber,
                    recipient_name: claimRecipientName || undefined,
                    notes: claimNotes || undefined,
                    hub_id: activeHub?.id,
                }),
            });

            const data = await response.json();
            if (response.ok && data.success) {
                playSound('success');
                setReleaseSuccess(data.message || 'Parcel successfully claimed!');
                setClaimRecipientName('');
                setClaimNotes('');
                setReleasingId(null);
                router.reload({ only: ['counterPickups', 'recentScans', 'stats'] });
            } else {
                playSound('error');
                setScanError(data.error || 'Release failed.');
            }
        } catch (err: any) {
            playSound('error');
            setScanError(err.message || 'Network error during parcel release.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const getActionBadgeStyle = (color: string) => {
        switch (color) {
            case 'blue':
                return 'bg-blue-600 text-white border-blue-500 shadow-blue-500/20';
            case 'indigo':
                return 'bg-indigo-600 text-white border-indigo-500 shadow-indigo-500/20';
            case 'green':
            case 'emerald':
                return 'bg-emerald-600 text-white border-emerald-500 shadow-emerald-500/20';
            default:
                return 'bg-gray-800 text-gray-100 border-gray-700';
        }
    };

    const filteredCounterPickups = counterPickups.filter((item) => {
        if (!pickupSearch.trim()) return true;
        const q = pickupSearch.toLowerCase();
        return (
            item.tracking_number.toLowerCase().includes(q) ||
            item.buyer_name.toLowerCase().includes(q) ||
            (item.order_number && item.order_number.toLowerCase().includes(q)) ||
            item.buyer_phone.toLowerCase().includes(q)
        );
    });

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 font-sans pb-16">
            <Head title="Mobile Scan Station - BagooPH Logistics" />

            {/* Top Navigation Header */}
            <header className="sticky top-0 z-30 bg-slate-900/90 backdrop-blur-md border-b border-slate-800 px-4 py-3">
                <div className="max-w-4xl mx-auto flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <div className="p-2 bg-emerald-500/10 border border-emerald-500/20 rounded-lg text-emerald-400">
                            <ScanLine className="w-5 h-5 animate-pulse" />
                        </div>
                        <div>
                            <div className="flex items-center gap-1.5">
                                <span className="font-black text-sm tracking-tight text-white uppercase">BagooPH</span>
                                <span className="text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-300 font-semibold border border-amber-500/30">
                                    WAREHOUSE PWA
                                </span>
                            </div>
                            <p className="text-[11px] text-slate-400 font-mono">Floor Barcode Terminal</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* Audio Toggle */}
                        <button
                            type="button"
                            onClick={() => setAudioEnabled(!audioEnabled)}
                            className={`p-2 rounded-lg border transition-colors ${
                                audioEnabled
                                    ? 'bg-slate-800 border-slate-700 text-emerald-400 hover:bg-slate-700'
                                    : 'bg-slate-800 border-slate-700 text-slate-500 hover:bg-slate-700'
                            }`}
                            title={audioEnabled ? 'Sound Enabled' : 'Sound Muted'}
                        >
                            {audioEnabled ? <Volume2 className="w-4 h-4" /> : <VolumeX className="w-4 h-4" />}
                        </button>

                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="text-xs px-2.5 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-lg border border-slate-700 transition"
                        >
                            Sign Out
                        </Link>
                    </div>
                </div>
            </header>

            {/* Active Facility & Hub Context Selector */}
            <section className="bg-slate-900 border-b border-slate-800 px-4 py-3">
                <div className="max-w-4xl mx-auto">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div className="flex items-start gap-2.5">
                            <Building2 className="w-5 h-5 text-emerald-400 mt-0.5 flex-shrink-0" />
                            <div>
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-bold text-sm text-slate-100">
                                        {activeHub ? activeHub.name : 'No Active Hub Assigned'}
                                    </span>
                                    {activeHub && (
                                        <span
                                            className={`text-[10px] uppercase font-mono px-2 py-0.5 rounded-full font-bold ${
                                                activeHub.tier === 'regional_mother_hub'
                                                    ? 'bg-indigo-500/20 text-indigo-300 border border-indigo-500/30'
                                                    : 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                                            }`}
                                        >
                                            {activeHub.tier === 'regional_mother_hub' ? 'Regional Mother Hub' : 'Local Bayan Hub'}
                                        </span>
                                    )}
                                </div>
                                <p className="text-xs text-slate-400 mt-0.5">
                                    {activeHub ? `${activeHub.city_municipality}, ${activeHub.province} (${activeHub.code})` : 'Select a hub'}
                                </p>
                            </div>
                        </div>

                        {/* Hub Selector Dropdown */}
                        <div className="flex items-center gap-2">
                            <label htmlFor="hub-select" className="text-xs text-slate-400 font-medium whitespace-nowrap">
                                Switch Hub:
                            </label>
                            <select
                                id="hub-select"
                                value={activeHub?.id || ''}
                                onChange={(e) => handleHubChange(e.target.value)}
                                className="bg-slate-950 border border-slate-700 text-slate-200 text-xs rounded-lg px-2.5 py-1.5 focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                            >
                                {hubs.map((h) => (
                                    <option key={h.id} value={h.id}>
                                        {h.name} ({h.tier === 'regional_mother_hub' ? 'Mother' : 'Bayan'})
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {/* Quick Hub Stats Bar */}
                    <div className="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-slate-800 text-center">
                        <div className="bg-slate-950/60 rounded-lg p-2 border border-slate-800">
                            <div className="text-base font-extrabold text-slate-100">{stats.parcels_in_hub}</div>
                            <div className="text-[10px] uppercase text-slate-400 tracking-wider">Parcels in Hub</div>
                        </div>
                        <div className="bg-slate-950/60 rounded-lg p-2 border border-slate-800">
                            <div className="text-base font-extrabold text-amber-400">{stats.ready_pickup}</div>
                            <div className="text-[10px] uppercase text-slate-400 tracking-wider">Self-Pickup Ready</div>
                        </div>
                        <div className="bg-slate-950/60 rounded-lg p-2 border border-slate-800">
                            <div className="text-base font-extrabold text-emerald-400">{stats.dispatched_today}</div>
                            <div className="text-[10px] uppercase text-slate-400 tracking-wider">Scans Today</div>
                        </div>
                    </div>
                </div>
            </section>

            {/* Navigation Tabs */}
            <div className="max-w-4xl mx-auto px-4 mt-4">
                <div className="flex border-b border-slate-800 gap-2">
                    <button
                        type="button"
                        onClick={() => setActiveTab('scan')}
                        className={`flex items-center gap-2 px-4 py-2.5 text-xs font-semibold rounded-t-lg transition-colors border-b-2 ${
                            activeTab === 'scan'
                                ? 'border-emerald-500 text-emerald-400 bg-slate-900/60'
                                : 'border-transparent text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        <ScanLine className="w-4 h-4" />
                        <span>Floor Intake & Sort</span>
                    </button>

                    {activeHub?.allows_self_pickup && (
                        <button
                            type="button"
                            onClick={() => setActiveTab('counter')}
                            className={`flex items-center gap-2 px-4 py-2.5 text-xs font-semibold rounded-t-lg transition-colors border-b-2 ${
                                activeTab === 'counter'
                                    ? 'border-emerald-500 text-emerald-400 bg-slate-900/60'
                                    : 'border-transparent text-slate-400 hover:text-slate-200'
                            }`}
                        >
                            <Boxes className="w-4 h-4" />
                            <span>Bayan Counter Release</span>
                            {counterPickups.length > 0 && (
                                <span className="px-1.5 py-0.2 bg-amber-500/20 text-amber-300 text-[10px] rounded-full border border-amber-500/30">
                                    {counterPickups.length}
                                </span>
                            )}
                        </button>
                    )}

                    <button
                        type="button"
                        onClick={() => setActiveTab('logs')}
                        className={`flex items-center gap-2 px-4 py-2.5 text-xs font-semibold rounded-t-lg transition-colors border-b-2 ${
                            activeTab === 'logs'
                                ? 'border-emerald-500 text-emerald-400 bg-slate-900/60'
                                : 'border-transparent text-slate-400 hover:text-slate-200'
                        }`}
                    >
                        <Clock className="w-4 h-4" />
                        <span>Station Log</span>
                    </button>
                </div>
            </div>

            {/* TAB 1: Fast Scan & Camera Viewfinder */}
            {activeTab === 'scan' && (
                <main className="max-w-4xl mx-auto px-4 mt-4 space-y-4">
                    {/* Error Banner */}
                    {scanError && (
                        <div className="bg-red-950/60 border border-red-800/80 text-red-200 rounded-xl p-3 flex items-start gap-3 animate-shake">
                            <AlertCircle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                            <div className="text-xs">
                                <span className="font-bold">Scan Routing Error:</span> {scanError}
                            </div>
                        </div>
                    )}

                    {/* DYNAMIC SCAN PROMPT HUD CARD */}
                    {lastResult && (
                        <div className="bg-gradient-to-b from-slate-900 to-slate-950 rounded-2xl border border-slate-700 shadow-2xl p-4 sm:p-6 relative overflow-hidden">
                            <div className="flex items-center justify-between gap-2 mb-3">
                                <span className="text-[11px] font-mono uppercase text-slate-400 tracking-wider flex items-center gap-1.5">
                                    <Sparkles className="w-3.5 h-3.5 text-amber-400" />
                                    Active Facility Routing Action
                                </span>
                                <span className="text-xs font-mono bg-slate-800 text-slate-300 px-2.5 py-0.5 rounded-md border border-slate-700">
                                    Leg: {lastResult.delivery.status.replace(/_/g, ' ')}
                                </span>
                            </div>

                            {/* Prominent Action Banner Prompt */}
                            <div
                                className={`rounded-xl p-4 text-center border shadow-lg transition-transform ${getActionBadgeStyle(
                                    lastResult.prompt.color
                                )}`}
                            >
                                <div className="text-xs uppercase font-extrabold tracking-widest opacity-80 mb-1">
                                    {lastResult.prompt.action.replace(/_/g, ' ')}
                                </div>
                                <div className="text-lg sm:text-2xl font-black tracking-tight uppercase">
                                    {lastResult.prompt.prompt}
                                </div>
                            </div>

                            {/* Destination Bin Code */}
                            <div className="mt-4 flex flex-col sm:flex-row items-center justify-between gap-3 bg-slate-950/80 rounded-xl p-3 border border-slate-800">
                                <div className="flex items-center gap-2">
                                    <Layers className="w-5 h-5 text-emerald-400" />
                                    <div>
                                        <div className="text-[10px] uppercase text-slate-400 font-mono">Assigned Bayan Sort Bin</div>
                                        <div className="text-sm font-extrabold text-emerald-300 font-mono">
                                            {lastResult.delivery.destination_bin || 'BIN: GENERAL'}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <span className="text-xs font-mono text-slate-400">Waybill:</span>
                                    <span className="text-xs font-mono font-bold text-white bg-slate-800 px-2 py-1 rounded border border-slate-700">
                                        {lastResult.delivery.tracking_number}
                                    </span>
                                </div>
                            </div>

                            {/* Delivery & Buyer Info */}
                            <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div className="bg-slate-900/80 p-3 rounded-lg border border-slate-800">
                                    <div className="text-slate-400 text-[10px] uppercase font-mono mb-1">Customer & Destination</div>
                                    <div className="font-bold text-slate-200">{lastResult.delivery.buyer.name}</div>
                                    <div className="text-slate-400 mt-0.5">
                                        {lastResult.delivery.buyer.barangay}, {lastResult.delivery.buyer.city}
                                    </div>
                                    {lastResult.delivery.buyer.landmark && (
                                        <div className="text-amber-400/80 text-[11px] mt-1">
                                            Landmark: {lastResult.delivery.buyer.landmark}
                                        </div>
                                    )}
                                    <div className="text-slate-400 font-mono text-[11px] mt-1">
                                        Phone: {lastResult.delivery.buyer.phone}
                                    </div>
                                </div>

                                <div className="bg-slate-900/80 p-3 rounded-lg border border-slate-800">
                                    <div className="text-slate-400 text-[10px] uppercase font-mono mb-1">Order Details</div>
                                    <div className="font-bold text-slate-200">
                                        ₱{lastResult.delivery.order.total_amount.toFixed(2)}
                                        <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-amber-300 uppercase font-mono">
                                            {lastResult.delivery.order.payment_method}
                                        </span>
                                    </div>
                                    <div className="text-slate-400 mt-1">
                                        {lastResult.delivery.order.items.length} item(s):{' '}
                                        {lastResult.delivery.order.items.map((i) => `${i.name} (x${i.quantity})`).join(', ')}
                                    </div>
                                    <div className="mt-1 flex items-center gap-1.5 text-emerald-400 text-[11px]">
                                        <ShieldCheck className="w-3.5 h-3.5" />
                                        <span>Audit checkpoint recorded</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Camera Viewfinder Box */}
                    <div className="bg-slate-900 rounded-2xl border border-slate-800 overflow-hidden relative">
                        <div className="p-3 border-b border-slate-800 flex items-center justify-between">
                            <span className="text-xs font-bold text-slate-300 flex items-center gap-2">
                                <Camera className="w-4 h-4 text-emerald-400" />
                                Camera Scanner Viewfinder
                            </span>

                            <button
                                type="button"
                                onClick={cameraActive ? stopCamera : startCamera}
                                className={`text-xs px-3 py-1.5 rounded-lg font-semibold flex items-center gap-1.5 transition ${
                                    cameraActive
                                        ? 'bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30'
                                        : 'bg-emerald-600 text-white hover:bg-emerald-500 shadow-md shadow-emerald-600/20'
                                }`}
                            >
                                {cameraActive ? (
                                    <>
                                        <CameraOff className="w-3.5 h-3.5" />
                                        Stop Camera
                                    </>
                                ) : (
                                    <>
                                        <Camera className="w-3.5 h-3.5" />
                                        Start Camera
                                    </>
                                )}
                            </button>
                        </div>

                        {cameraError && (
                            <div className="p-3 bg-amber-950/40 text-amber-300 text-xs border-b border-amber-900/50">
                                {cameraError}
                            </div>
                        )}

                        <div className="relative bg-black min-h-[220px] max-h-[360px] flex items-center justify-center overflow-hidden">
                            <video
                                ref={videoRef}
                                className={`w-full h-full object-cover ${cameraActive ? 'block' : 'hidden'}`}
                                playsInline
                                muted
                            />

                            {/* Viewfinder Overlay Reticle */}
                            {cameraActive ? (
                                <div className="absolute inset-0 pointer-events-none flex items-center justify-center">
                                    <div className="relative w-64 h-48 border-2 border-dashed border-emerald-400/80 rounded-2xl shadow-inner">
                                        <div className="absolute inset-x-0 h-0.5 bg-red-500 animate-pulse top-1/2 -translate-y-1/2 shadow-red-500/80 shadow-md" />
                                        <div className="absolute top-2 left-2 text-[9px] font-mono text-emerald-400 uppercase bg-black/60 px-1 rounded">
                                            Align Barcode
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-center p-6 text-slate-500">
                                    <Camera className="w-12 h-12 mx-auto mb-2 opacity-30" />
                                    <p className="text-xs text-slate-400">Camera is idle.</p>
                                    <p className="text-[11px] text-slate-500 mt-0.5">
                                        Tap "Start Camera" above or scan with your handheld Bluetooth laser gun below.
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Laser Gun / Keyboard Barcode Form */}
                    <div className="bg-slate-900 rounded-2xl border border-slate-800 p-4">
                        <form onSubmit={handleInputSubmit} className="space-y-3">
                            <label htmlFor="barcode-field" className="block text-xs font-semibold text-slate-300">
                                Barcode / Waybill Input (Hardware Gun or Manual)
                            </label>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <ScanLine className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" />
                                    <input
                                        id="barcode-field"
                                        ref={barcodeInputRef}
                                        type="text"
                                        value={barcodeInput}
                                        onChange={(e) => setBarcodeInput(e.target.value)}
                                        placeholder="Scan or enter tracking e.g. BG-..."
                                        disabled={isSubmitting}
                                        autoFocus
                                        className="w-full bg-slate-950 border border-slate-700 text-slate-100 rounded-xl pl-9 pr-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    disabled={isSubmitting || !barcodeInput.trim()}
                                    className="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-800 disabled:text-slate-600 text-white font-bold text-xs rounded-xl shadow-lg shadow-emerald-600/20 transition flex items-center gap-1.5"
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

                        {/* Quick Test Barcode Pills */}
                        {sampleTrackingNumbers && sampleTrackingNumbers.length > 0 && (
                            <div className="mt-4 pt-3 border-t border-slate-800">
                                <div className="text-[11px] text-slate-400 font-mono mb-2 flex items-center gap-1.5">
                                    <Hash className="w-3.5 h-3.5 text-slate-500" />
                                    Sample Waybills (Click to simulate instant scan):
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {sampleTrackingNumbers.map((track) => (
                                        <button
                                            key={track}
                                            type="button"
                                            onClick={() => {
                                                setBarcodeInput(track);
                                                handleBarcodeProcess(track);
                                            }}
                                            className="text-[11px] font-mono bg-slate-950 hover:bg-slate-800 border border-slate-800 hover:border-slate-700 text-slate-300 px-2 py-1 rounded transition"
                                        >
                                            {track}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </main>
            )}

            {/* TAB 2: Bayan Counter Release */}
            {activeTab === 'counter' && (
                <main className="max-w-4xl mx-auto px-4 mt-4 space-y-4">
                    {releaseSuccess && (
                        <div className="bg-emerald-950/60 border border-emerald-800/80 text-emerald-200 rounded-xl p-3 flex items-start gap-3">
                            <CheckCircle2 className="w-5 h-5 text-emerald-400 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-medium">{releaseSuccess}</div>
                        </div>
                    )}

                    {scanError && (
                        <div className="bg-red-950/60 border border-red-800/80 text-red-200 rounded-xl p-3 flex items-start gap-3">
                            <AlertCircle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                            <div className="text-xs">{scanError}</div>
                        </div>
                    )}

                    {/* Counter Release Search and Summary */}
                    <div className="bg-slate-900 rounded-2xl border border-slate-800 p-4">
                        <div className="flex items-center justify-between gap-2 mb-3">
                            <div>
                                <h3 className="font-bold text-sm text-slate-100 flex items-center gap-2">
                                    <Boxes className="w-4 h-4 text-emerald-400" />
                                    Free Bayan Hub Counter Handover Station
                                </h3>
                                <p className="text-xs text-slate-400 mt-0.5">
                                    Hand over staged self-pickup packages to buyers at {activeHub?.name}.
                                </p>
                            </div>
                        </div>

                        <div className="relative mt-2">
                            <Search className="w-4 h-4 text-slate-500 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                type="text"
                                value={pickupSearch}
                                onChange={(e) => setPickupSearch(e.target.value)}
                                placeholder="Search by customer name, phone, order #, or tracking code..."
                                className="w-full bg-slate-950 border border-slate-700 text-slate-100 rounded-xl pl-9 pr-3 py-2 text-xs focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                            />
                        </div>
                    </div>

                    {/* List of Staged Self-Pickup Packages */}
                    <div className="space-y-3">
                        {filteredCounterPickups.length === 0 ? (
                            <div className="bg-slate-900/60 rounded-2xl border border-slate-800/80 p-8 text-center">
                                <Package className="w-10 h-10 text-slate-600 mx-auto mb-2" />
                                <p className="text-sm font-medium text-slate-400">No staged self-pickup packages found.</p>
                                <p className="text-xs text-slate-500 mt-1">
                                    Packages routed for customer pickup at this Bayan Hub will show here once intake is completed.
                                </p>
                            </div>
                        ) : (
                            filteredCounterPickups.map((parcel) => (
                                <div
                                    key={parcel.id}
                                    className="bg-slate-900 rounded-2xl border border-slate-800 p-4 hover:border-slate-700 transition"
                                >
                                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-mono font-bold text-sm text-white">
                                                    {parcel.tracking_number}
                                                </span>
                                                <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                                    {parcel.destination_bin}
                                                </span>
                                                <span className="text-[10px] uppercase font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-300">
                                                    {parcel.status.replace(/_/g, ' ')}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-3 mt-1 text-xs text-slate-400">
                                                <span className="text-slate-200 font-semibold">{parcel.buyer_name}</span>
                                                <span>•</span>
                                                <span>{parcel.buyer_phone}</span>
                                                <span>•</span>
                                                <span>₱{parcel.total_amount.toFixed(2)} ({parcel.payment_method.toUpperCase()})</span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => setReleasingId(releasingId === parcel.id ? null : parcel.id)}
                                            className="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-xl shadow-sm transition"
                                        >
                                            {releasingId === parcel.id ? 'Cancel' : 'Release to Buyer'}
                                        </button>
                                    </div>

                                    {/* Inline Claim Verification Box */}
                                    {releasingId === parcel.id && (
                                        <div className="mt-3 pt-3 border-t border-slate-800 bg-slate-950/60 rounded-xl p-3 space-y-2.5">
                                            <div className="text-xs font-semibold text-slate-300">
                                                Confirm Customer Claim
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                <input
                                                    type="text"
                                                    value={claimRecipientName}
                                                    onChange={(e) => setClaimRecipientName(e.target.value)}
                                                    placeholder={`Claimant name (defaults to ${parcel.buyer_name})`}
                                                    className="bg-slate-900 border border-slate-700 text-slate-200 rounded-lg px-2.5 py-1.5 text-xs"
                                                />
                                                <input
                                                    type="text"
                                                    value={claimNotes}
                                                    onChange={(e) => setClaimNotes(e.target.value)}
                                                    placeholder="Verification notes e.g. Valid ID Presented"
                                                    className="bg-slate-900 border border-slate-700 text-slate-200 rounded-lg px-2.5 py-1.5 text-xs"
                                                />
                                            </div>
                                            <div className="flex justify-end gap-2 pt-1">
                                                <button
                                                    type="button"
                                                    onClick={() => setReleasingId(null)}
                                                    className="px-3 py-1 bg-slate-800 text-slate-300 hover:bg-slate-700 text-xs rounded-lg"
                                                >
                                                    Cancel
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={isSubmitting}
                                                    onClick={() => handleCounterRelease(parcel.tracking_number)}
                                                    className="px-4 py-1 bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-xs rounded-lg transition"
                                                >
                                                    {isSubmitting ? 'Recording Handover...' : 'Confirm Handover'}
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </main>
            )}

            {/* TAB 3: Recent Activity Scans Log */}
            {activeTab === 'logs' && (
                <main className="max-w-4xl mx-auto px-4 mt-4 space-y-3">
                    <div className="bg-slate-900 rounded-2xl border border-slate-800 p-4">
                        <h3 className="font-bold text-sm text-slate-100 flex items-center gap-2">
                            <Clock className="w-4 h-4 text-emerald-400" />
                            Recent Station Scans & Routing Audits
                        </h3>
                        <p className="text-xs text-slate-400 mt-0.5">
                            Last 15 scans executed at this logistics terminal.
                        </p>
                    </div>

                    <div className="space-y-2">
                        {recentScans.length === 0 ? (
                            <div className="p-8 text-center text-slate-500 bg-slate-900/60 rounded-2xl border border-slate-800">
                                No recent scans recorded at this station yet.
                            </div>
                        ) : (
                            recentScans.map((scan) => (
                                <div
                                    key={scan.id}
                                    className="bg-slate-900/80 rounded-xl border border-slate-800/80 p-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs"
                                >
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-mono font-bold text-slate-200">
                                                {scan.tracking_number}
                                            </span>
                                            <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-300">
                                                {scan.checkpoint_type}
                                            </span>
                                            <span className="text-[10px] font-mono text-emerald-400">
                                                {scan.destination_bin}
                                            </span>
                                        </div>
                                        <div className="text-slate-400 text-[11px] mt-1">{scan.notes}</div>
                                    </div>
                                    <div className="text-right text-[11px] text-slate-500 font-mono">
                                        <div>{new Date(scan.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</div>
                                        <div className="text-slate-400">{scan.scanned_by}</div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                </main>
            )}
        </div>
    );
}
