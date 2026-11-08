import { useCallback, useEffect, useRef, useState } from 'react';
import { BrowserMultiFormatReader } from '@zxing/library';
import { Camera, Image as ImageIcon } from 'lucide-react';
import { selectWaybill } from '@/utils/waybillScan';

interface Detector { detect(video: HTMLVideoElement): Promise<Array<{ rawValue: string }>> }
type DetectorConstructor = new (options: { formats: string[] }) => Detector;

export default function WaybillCamera({
    onScan,
    disabled = false,
    autoStart = false,
    instruction = 'Scan the parcel waybill to fill this field. Check the scanned code and complete the required details below.',
}: {
    onScan: (value: string) => void;
    disabled?: boolean;
    autoStart?: boolean;
    instruction?: string;
}) {
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const detector = useRef<Detector | null>(null);
    const zxingReader = useRef<BrowserMultiFormatReader | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const generation = useRef(0);
    const autoStarted = useRef(false);
    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [streamBlocked, setStreamBlocked] = useState(false);
    const recognized = useRef(onScan);
    recognized.current = onScan;

    const release = useCallback(() => {
        stream.current?.getTracks().forEach(track => track.stop());
        stream.current = null;
        if (video.current) video.current.srcObject = null;
        if (zxingReader.current) {
            try { zxingReader.current.reset(); } catch {}
            zxingReader.current = null;
        }
    }, []);

    const stop = useCallback(() => {
        generation.current++;
        release();
        setActive(false);
        setStarting(false);
    }, [release]);

    useEffect(() => () => { generation.current++; release(); }, [release]);
    useEffect(() => { if (disabled) stop(); }, [disabled, stop]);

    const start = async () => {
        if (starting || active || disabled) return;
        const attempt = ++generation.current;
        setStarting(true);
        setError(null);
        setStreamBlocked(false);

        try {
            const hasMediaDevices = typeof navigator !== 'undefined' && Boolean(navigator.mediaDevices?.getUserMedia);
            if (!hasMediaDevices) {
                setStreamBlocked(true);
                throw new Error('media-devices-unavailable');
            }

            const Constructor = (typeof window !== 'undefined' ? (window as Window & { BarcodeDetector?: DetectorConstructor }).BarcodeDetector : undefined);
            if (Constructor) {
                detector.current = new Constructor({ formats: ['code_128', 'code_39', 'qr_code'] });
            } else {
                const zxing = new BrowserMultiFormatReader();
                zxingReader.current = zxing;
                detector.current = {
                    async detect(v: HTMLVideoElement) {
                        try {
                            const res = await zxing.decodeFromVideoElement(v);
                            return res ? [{ rawValue: res.getText() }] : [];
                        } catch {
                            return [];
                        }
                    },
                };
            }

            const next = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            if (attempt !== generation.current) { next.getTracks().forEach(track => track.stop()); return; }
            stream.current = next;
            if (!video.current) { release(); return; }
            video.current.srcObject = next;
            await video.current.play();
            if (attempt !== generation.current) {
                next.getTracks().forEach(track => track.stop());
                if (stream.current === next) release();
                return;
            }
            setActive(true);
        } catch (err: unknown) {
            if (attempt === generation.current) {
                release();
                const isStreamErr = err instanceof Error && err.message === 'media-devices-unavailable';
                if (isStreamErr || (typeof window !== 'undefined' && window.location.protocol === 'http:' && window.location.hostname !== 'localhost' && window.location.hostname !== '127.0.0.1')) {
                    setStreamBlocked(true);
                    setError('Live camera stream is disabled by your browser over local HTTP. Tap "Take photo with camera" below to snap and scan, or use USB port forwarding on localhost:8000.');
                } else {
                    setError('Camera scanning is unavailable. Enter the waybill or use a barcode scanner.');
                }
            }
        } finally {
            if (attempt === generation.current) setStarting(false);
        }
    };

    useEffect(() => {
        if (autoStart && !disabled && !active && !starting && !autoStarted.current) {
            autoStarted.current = true;
            void start();
        }
    }, [autoStart, disabled, active, starting]);

    const handlePhotoCapture = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setStarting(true);
        setError(null);
        try {
            const url = URL.createObjectURL(file);
            const zxing = new BrowserMultiFormatReader();
            const res = await zxing.decodeFromImageUrl(url);
            URL.revokeObjectURL(url);
            if (res) {
                const result = selectWaybill([{ rawValue: res.getText() }], window.location.origin);
                if (result.code) {
                    recognized.current(result.code);
                    setError(null);
                    return;
                } else if (result.error) {
                    setError(result.error);
                    return;
                }
            }
            setError('No readable barcode or QR code found in the photo. Please point directly at the waybill code and try again.');
        } catch {
            setError('Could not read barcode from photo. Please enter tracking code manually or try another angle.');
        } finally {
            setStarting(false);
            e.target.value = '';
        }
    };

    useEffect(() => {
        if (!active || disabled) return;
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const detect = async () => {
            try {
                const values = video.current && video.current.readyState >= 2 && detector.current
                    ? await detector.current.detect(video.current) : [];
                if (cancelled) return;
                const result = selectWaybill(values, window.location.origin);
                if (result.error) setError(result.error);
                if (result.code) { stop(); setError(null); recognized.current(result.code); return; }
            } catch {
                if (cancelled) return;
                stop();
                setError('The camera could not read the waybill. Enter it or use a barcode scanner.');
                return;
            }
            if (!cancelled) timer = setTimeout(detect, 250);
        };
        void detect();
        return () => { cancelled = true; if (timer) clearTimeout(timer); };
    }, [active, disabled, stop]);

    return (
        <div className="rounded-xl border border-zinc-200/90 bg-zinc-50/70 p-3.5 space-y-2.5">
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    disabled={!active && (disabled || starting)}
                    onClick={active ? stop : start}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50/85 px-3 py-2 text-xs font-semibold text-[#C20836] shadow-2xs hover:bg-[#E00D42] hover:text-white hover:border-[#E00D42] transition disabled:opacity-50 cursor-pointer"
                >
                    <Camera className="h-4 w-4" />
                    <span>{active ? 'Stop camera' : starting ? 'Starting camera...' : 'Scan actual waybill with camera'}</span>
                </button>

                {/* Direct photo capture fallback for mobile browsers on HTTP */}
                <label className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200/90 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 shadow-2xs hover:bg-zinc-50 hover:text-zinc-900 transition cursor-pointer">
                    <ImageIcon className="h-4 w-4 text-[#E00D42]" />
                    <span>Take photo with camera</span>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="sr-only"
                        disabled={disabled || starting}
                        onChange={handlePhotoCapture}
                    />
                </label>
            </div>

            <video
                ref={video}
                muted
                playsInline
                className={active ? 'max-h-64 w-full rounded-xl bg-black object-contain border border-zinc-300' : 'hidden'}
                aria-label="Actual waybill camera"
            />

            <p className="text-xs text-zinc-500 leading-relaxed">
                {instruction} Point camera directly at the waybill code.
            </p>

            {error && (
                <p role="alert" className="rounded-lg border border-rose-200 bg-rose-50/80 p-2.5 text-xs text-[#C20836] leading-relaxed font-medium">
                    {error}
                </p>
            )}
        </div>
    );
}
