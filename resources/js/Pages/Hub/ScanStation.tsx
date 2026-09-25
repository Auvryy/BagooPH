import React, { useState, useEffect, useRef } from 'react';
import { Head, router, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import {
    ScanLine,
    Camera,
    CameraOff,
    Package,
    CheckCircle2,
    AlertCircle,
    Building2,
    Volume2,
    VolumeX,
    ArrowRight,
    Search,
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
    counterPickups: CounterPickupParcel[];
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
    requires_confirmation: boolean;
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
    pendingOriginIntake,
    counterPickups,
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
    } | null>(null);
    const [scanError, setScanError] = useState<string | null>(null);
    const [scanMessage, setScanMessage] = useState<string | null>(null);

    // Counter pickup claim form state
    const [pickupSearch, setPickupSearch] = useState('');
    const [claimRecipientName, setClaimRecipientName] = useState('');
    const [claimNotes, setClaimNotes] = useState('');
    const [releasingId, setReleasingId] = useState<number | null>(null);
    const [releaseSuccess, setReleaseSuccess] = useState<string | null>(null);

    // Camera & Web Barcode detection state
    const [cameraActive, setCameraActive] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const detectorRef = useRef<any>(null);
    const barcodeInputRef = useRef<HTMLInputElement | null>(null);

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
        setScanMessage(null);

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
                    mode: 'inspect',
                }),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                playSound('success');
                setLastResult({
                    prompt: data.prompt,
                    delivery: data.delivery,
                });
                setScanMessage(data.message);
                setBarcodeInput('');
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

    const confirmScanAction = async () => {
        if (!lastResult || !lastResult.prompt.requires_confirmation || isSubmitting) return;

        setIsSubmitting(true);
        setScanError(null);
        setScanMessage(null);

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
                    barcode: lastResult.delivery.tracking_number,
                    hub_id: activeHub?.id,
                    mode: 'confirm',
                    action: lastResult.prompt.action,
                    expected_status: lastResult.prompt.expected_status,
                }),
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'The custody action could not be recorded.');
            }

            playSound('success');
            setLastResult({ prompt: data.prompt, delivery: data.delivery });
            setScanMessage(data.message);
            router.reload({ only: ['recentScans', 'pendingOriginIntake', 'stats', 'counterPickups'] });
        } catch (err: any) {
            playSound('error');
            setScanError(err.message || 'Network error while confirming the custody action.');
        } finally {
            setIsSubmitting(false);
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
                router.reload({ only: ['counterPickups', 'recentScans', 'pendingOriginIntake', 'stats'] });
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

                {activeHub?.allows_self_pickup && (
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
                        {counterPickups.length > 0 && (
                            <span className="px-1.5 py-0.2 bg-slate-100 text-slate-800 text-[10px] rounded-xs border border-slate-300 font-sans font-bold">
                                {counterPickups.length}
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
                        <div className="bg-red-50 border border-red-300 text-red-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
                            <AlertCircle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans">
                                <span className="font-bold">Scan Routing Error:</span> {scanError}
                            </div>
                        </div>
                    )}

                    {scanMessage && (
                        <div className="bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
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

                            {/* Camera Viewfinder Card (Minimalist Clean Style) */}
                            <div className="bg-white rounded-xs border border-slate-300 overflow-hidden shadow-xs">
                                <div className="p-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                                    <span className="text-xs font-bold text-slate-900 flex items-center gap-2 font-sans uppercase tracking-wider">
                                        <Camera className="w-4 h-4 text-slate-700" />
                                        Camera Barcode Reader
                                    </span>

                                    <button
                                        type="button"
                                        onClick={cameraActive ? stopCamera : startCamera}
                                        className={`text-xs px-3 py-1 rounded-xs font-sans font-bold flex items-center gap-1.5 transition shadow-xs ${
                                            cameraActive
                                                ? 'bg-slate-800 text-white hover:bg-slate-900'
                                                : 'bg-white text-slate-800 border border-slate-300 hover:bg-slate-50'
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
                                    <div className="p-3 bg-red-50 text-red-800 text-xs border-b border-red-200 font-sans">
                                        {cameraError}
                                    </div>
                                )}

                                <div className="relative bg-slate-900 min-h-[190px] max-h-[260px] flex items-center justify-center overflow-hidden">
                                    <video
                                        ref={videoRef}
                                        className={`w-full h-full object-cover ${cameraActive ? 'block' : 'hidden'}`}
                                        playsInline
                                        muted
                                    />

                                    {/* Viewfinder Target Guide */}
                                    {cameraActive ? (
                                        <div className="absolute inset-0 pointer-events-none flex items-center justify-center">
                                            <div className="relative w-56 h-36 border-2 border-dashed border-white/80 rounded-xs">
                                                <div className="absolute inset-x-0 h-0.5 bg-[#E00D42] top-1/2 -translate-y-1/2 shadow-xs" />
                                                <div className="absolute top-1.5 left-1.5 text-[9px] font-sans text-white uppercase bg-black/70 px-1 py-0.5 rounded-xs font-bold">
                                                    Align Barcode
                                                </div>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="text-center p-6 text-slate-400">
                                            <Camera className="w-8 h-8 mx-auto mb-1.5 text-slate-500 opacity-40" />
                                            <p className="text-xs font-sans font-bold text-slate-300">Camera Inactive</p>
                                            <p className="text-[11px] text-slate-400 mt-0.5 max-w-xs mx-auto">
                                                Click "Start Camera" or scan via Bluetooth barcode laser scanner.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
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
                                            {lastResult.prompt.action.replace(/_/g, ' ')}
                                        </div>
                                        <div className="text-lg sm:text-xl font-black font-sans uppercase tracking-tight">
                                            {lastResult.prompt.prompt}
                                        </div>
                                    </div>

                                    {lastResult.prompt.requires_confirmation && (
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
                                                {isSubmitting ? 'Recording action...' : 'Confirm custody action'}
                                            </button>
                                        </div>
                                    )}

                                    {/* Assigned Sort Bin */}
                                    <div className="flex items-center justify-between p-3.5 bg-slate-50 rounded-xs border border-slate-300">
                                        <div className="flex items-center gap-2.5">
                                            <Layers className="w-4 h-4 text-slate-700" />
                                            <div>
                                                <div className="text-[10px] uppercase text-slate-500 font-sans font-bold">Destination Bin</div>
                                                <div className="text-sm font-bold text-slate-900 font-sans">
                                                    {lastResult.delivery.destination_bin || 'BIN: GENERAL'}
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
                                        <span>Audit checkpoint recorded in database</span>
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

            {/* TAB 2: Bayan Counter Release */}
            {activeTab === 'counter' && (
                <div className="space-y-4">
                    {releaseSuccess && (
                        <div className="bg-emerald-50 border border-emerald-300 text-emerald-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
                            <CheckCircle2 className="w-4 h-4 text-emerald-700 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans font-bold">{releaseSuccess}</div>
                        </div>
                    )}

                    {scanError && (
                        <div className="bg-red-50 border border-red-300 text-red-900 rounded-xs p-3.5 flex items-start gap-3 shadow-xs">
                            <AlertCircle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
                            <div className="text-xs font-sans">{scanError}</div>
                        </div>
                    )}

                    {/* Counter Release Header and Search */}
                    <div className="bg-white rounded-xs border border-slate-300 p-4 sm:p-5 shadow-xs">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-3">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900 flex items-center gap-2 font-sans">
                                    <Boxes className="w-4 h-4 text-slate-700" />
                                    Customer Self-Pickup Counter Desk
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Hand over staged ₱0.00 self-pickup packages to buyers at {activeHub?.name}.
                                </p>
                            </div>
                        </div>

                        <div className="relative">
                            <Search className="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" />
                            <input
                                type="text"
                                value={pickupSearch}
                                onChange={(e) => setPickupSearch(e.target.value)}
                                placeholder="Search by customer name, phone, order #, or tracking code..."
                                className="w-full bg-white border border-slate-300 text-slate-900 placeholder-slate-400 rounded-xs pl-9 pr-3 py-2 text-xs font-sans focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] shadow-xs outline-hidden"
                            />
                        </div>
                    </div>

                    {/* Staged Packages List */}
                    <div className="space-y-3">
                        {filteredCounterPickups.length === 0 ? (
                            <div className="bg-white rounded-xs border border-slate-300 p-8 text-center shadow-xs">
                                <Package className="w-10 h-10 text-slate-300 mx-auto mb-2" />
                                <p className="text-sm font-bold font-sans text-slate-800">No staged self-pickup packages found.</p>
                                <p className="text-xs text-slate-500 mt-1 max-w-md mx-auto">
                                    Packages routed for customer pickup at this Bayan Hub will appear here once intake scan is confirmed.
                                </p>
                            </div>
                        ) : (
                            filteredCounterPickups.map((parcel) => (
                                <div
                                    key={parcel.id}
                                    className="bg-white rounded-xs border border-slate-300 p-4 hover:border-slate-400 transition shadow-xs"
                                >
                                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2 flex-wrap font-sans">
                                                <span className="font-bold text-sm text-slate-900">
                                                    {parcel.tracking_number}
                                                </span>
                                                <span className="text-[10px] px-2 py-0.5 rounded-xs bg-slate-100 text-slate-800 border border-slate-300 font-bold">
                                                    {parcel.destination_bin}
                                                </span>
                                                <span className="text-[10px] uppercase px-2 py-0.5 rounded-xs bg-slate-100 text-slate-600 font-bold">
                                                    {parcel.status.replace(/_/g, ' ')}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-3 mt-1.5 text-xs text-slate-600">
                                                <span className="text-slate-900 font-bold">{parcel.buyer_name}</span>
                                                <span>•</span>
                                                <span>{parcel.buyer_phone}</span>
                                                <span>•</span>
                                                <span className="font-sans font-bold text-slate-900">₱{parcel.total_amount.toFixed(2)} ({parcel.payment_method.toUpperCase()})</span>
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={() => setReleasingId(releasingId === parcel.id ? null : parcel.id)}
                                            className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold text-xs rounded-xs shadow-xs transition font-sans uppercase tracking-wider cursor-pointer"
                                        >
                                            {releasingId === parcel.id ? 'Cancel' : 'Release to Buyer'}
                                        </button>
                                    </div>

                                    {/* Inline Claim Verification Box */}
                                    {releasingId === parcel.id && (
                                        <div className="mt-3 pt-3 border-t border-slate-200 bg-slate-50 rounded-xs p-3.5 space-y-2.5">
                                            <div className="text-xs font-bold text-slate-900 font-sans uppercase tracking-wider">
                                                Confirm Customer Claim Handover
                                            </div>
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                <input
                                                    type="text"
                                                    value={claimRecipientName}
                                                    onChange={(e) => setClaimRecipientName(e.target.value)}
                                                    placeholder={`Claimant name (defaults to ${parcel.buyer_name})`}
                                                    className="bg-white border border-slate-300 text-slate-900 rounded-xs px-2.5 py-1.5 text-xs font-sans shadow-2xs focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                                />
                                                <input
                                                    type="text"
                                                    value={claimNotes}
                                                    onChange={(e) => setClaimNotes(e.target.value)}
                                                    placeholder="Verification notes e.g. Valid ID Presented"
                                                    className="bg-white border border-slate-300 text-slate-900 rounded-xs px-2.5 py-1.5 text-xs font-sans shadow-2xs focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42]"
                                                />
                                            </div>
                                            <div className="flex justify-end gap-2 pt-1 font-sans">
                                                <button
                                                    type="button"
                                                    onClick={() => setReleasingId(null)}
                                                    className="px-3 py-1.5 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs rounded-xs font-medium cursor-pointer"
                                                >
                                                    Cancel
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={isSubmitting}
                                                    onClick={() => handleCounterRelease(parcel.tracking_number)}
                                                    className="px-4 py-1.5 bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs rounded-xs transition cursor-pointer shadow-xs"
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
                </div>
            )}

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
                                                {scan.destination_bin}
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
