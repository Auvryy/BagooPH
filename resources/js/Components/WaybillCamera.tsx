import { useCallback, useEffect, useRef, useState } from 'react';

interface Detector { detect(video: HTMLVideoElement): Promise<Array<{ rawValue: string }>> }
type DetectorConstructor = new (options: { formats: string[] }) => Detector;

export default function WaybillCamera({ onScan, disabled = false }: { onScan: (value: string) => void; disabled?: boolean }) {
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const detector = useRef<Detector | null>(null);
    const generation = useRef(0);
    const [active, setActive] = useState(false);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const release = useCallback(() => {
        stream.current?.getTracks().forEach(track => track.stop());
        stream.current = null;
        if (video.current) video.current.srcObject = null;
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
        try {
            const Constructor = (window as Window & { BarcodeDetector?: DetectorConstructor }).BarcodeDetector;
            if (!Constructor || !navigator.mediaDevices?.getUserMedia) throw new Error('unavailable');
            detector.current = new Constructor({ formats: ['code_128', 'code_39', 'qr_code'] });
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
        } catch {
            if (attempt === generation.current) {
                release();
                setError('Camera scanning is unavailable. Enter the waybill or use a barcode scanner.');
            }
        } finally {
            if (attempt === generation.current) setStarting(false);
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
                const value = values.find(item => typeof item.rawValue === 'string' && item.rawValue.length > 0)?.rawValue;
                if (value) { stop(); onScan(value); return; }
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
    }, [active, disabled, onScan, stop]);

    return <div className="rounded-lg border border-slate-300 bg-slate-50 p-3">
        <button type="button" disabled={!active && (disabled || starting)} onClick={active ? stop : start}
            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 disabled:opacity-50">
            {active ? 'Stop camera' : starting ? 'Starting camera...' : 'Scan actual waybill with camera'}
        </button>
        <video ref={video} muted playsInline className={active ? 'mt-3 max-h-64 w-full rounded-lg bg-slate-900 object-contain' : 'hidden'} aria-label="Actual waybill camera" />
        <p className="mt-2 text-xs text-slate-600">A camera scan fills the waybill field. Review it and submit the appropriate manifest action.</p>
        {error && <p role="alert" className="mt-2 text-sm text-red-700">{error}</p>}
    </div>;
}
