import { PointerEvent, useEffect, useRef, useState } from 'react';
import { Button } from '@/Components/ui';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';

/** Banner artwork rules (must match AdvertisementAssetService). */
export const BANNER = { ratio: 47 / 22, minWidth: 940, minHeight: 440, outWidth: 1880, maxEdge: 4096, maxPixels: 16_000_000, maxBytes: 5_242_880 };

interface Crop { x: number; y: number; w: number; h: number }

export async function imageSize(file: File): Promise<{ width: number; height: number }> {
    const url = URL.createObjectURL(file);
    try {
        const img = new Image();
        img.src = url;
        await img.decode();
        return { width: img.naturalWidth, height: img.naturalHeight };
    } finally { URL.revokeObjectURL(url); }
}

/** True when the file can be uploaded as it is. */
export function bannerFits(size: { width: number; height: number }, bytes: number): boolean {
    return size.width >= BANNER.minWidth && size.height >= BANNER.minHeight
        && size.width <= BANNER.maxEdge && size.height <= BANNER.maxEdge && size.width * size.height <= BANNER.maxPixels
        && Math.abs(size.width / size.height / BANNER.ratio - 1) <= 0.01 && bytes <= BANNER.maxBytes;
}

/** Can a 47:22 area of at least the minimum size be cut from this image? */
export function bannerCroppable(size: { width: number; height: number }): boolean {
    const w = Math.min(size.width, size.height * BANNER.ratio);
    return w >= BANNER.minWidth && w / BANNER.ratio >= BANNER.minHeight - 0.5;
}

interface Props { file: File; size: { width: number; height: number }; onCancel: () => void; onCropped: (file: File) => void }

/**
 * Lets the admin drag and zoom a 47:22 frame over the chosen image, then saves the
 * framed area as a 1880 × 880 (or smaller, never below 940 × 440) banner.
 */
export default function BannerCropper({ file, size, onCancel, onCropped }: Props) {
    const [url, setUrl] = useState('');
    const maxW = Math.min(size.width, size.height * BANNER.ratio);
    const minW = Math.min(maxW, Math.max(BANNER.minWidth, maxW * 0.3));
    const [zoom, setZoom] = useState(1); // 1 = largest frame
    const [crop, setCrop] = useState<Crop>(() => ({ x: (size.width - maxW) / 2, y: (size.height - maxW / BANNER.ratio) / 2, w: maxW, h: maxW / BANNER.ratio }));
    const [busy, setBusy] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    const drag = useRef<{ px: number; py: number; x: number; y: number } | null>(null);
    const [scale, setScale] = useState(1);
    useBodyScrollLock(true);

    useEffect(() => { const next = URL.createObjectURL(file); setUrl(next); return () => URL.revokeObjectURL(next); }, [file]);
    useEffect(() => {
        const update = () => setScale((box.current?.clientWidth ?? size.width) / size.width);
        update();
        window.addEventListener('resize', update);
        return () => window.removeEventListener('resize', update);
    }, [size.width]);

    const clamp = (next: Crop): Crop => ({ ...next, x: Math.min(Math.max(0, next.x), size.width - next.w), y: Math.min(Math.max(0, next.y), size.height - next.h) });
    const setZoomLevel = (value: number) => {
        setZoom(value);
        const w = minW + (maxW - minW) * value;
        setCrop(current => clamp({ x: current.x + (current.w - w) / 2, y: current.y + (current.h - w / BANNER.ratio) / 2, w, h: w / BANNER.ratio }));
    };
    const down = (event: PointerEvent<HTMLDivElement>) => { event.currentTarget.setPointerCapture(event.pointerId); drag.current = { px: event.clientX, py: event.clientY, x: crop.x, y: crop.y }; };
    const move = (event: PointerEvent<HTMLDivElement>) => {
        if (!drag.current) return;
        const start = drag.current;
        setCrop(current => clamp({ ...current, x: start.x + (event.clientX - start.px) / scale, y: start.y + (event.clientY - start.py) / scale }));
    };
    const up = () => { drag.current = null; };

    const save = async () => {
        setBusy(true);
        try {
            const img = new Image(); img.src = url; await img.decode();
            const outW = Math.round(Math.max(BANNER.minWidth, Math.min(BANNER.outWidth, crop.w)));
            const outH = Math.round(outW / BANNER.ratio);
            const canvas = document.createElement('canvas');
            canvas.width = outW; canvas.height = outH;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(img, crop.x, crop.y, crop.w, crop.h, 0, 0, outW, outH);
            const type = file.type === 'image/png' ? 'image/png' : file.type === 'image/webp' ? 'image/webp' : 'image/jpeg';
            let blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, type, 0.9));
            if (blob && blob.size > BANNER.maxBytes) blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob) return;
            const ext = blob.type === 'image/png' ? 'png' : blob.type === 'image/webp' ? 'webp' : 'jpg';
            onCropped(new File([blob], `${file.name.replace(/\.[^.]+$/, '')}-banner.${ext}`, { type: blob.type }));
        } finally { setBusy(false); }
    };

    return <div role="dialog" aria-modal="true" aria-labelledby="crop-title" className="fixed inset-0 z-50 flex items-center justify-center bg-brand-900/45 p-4">
        <div className="w-full max-w-3xl rounded-card bg-white p-5 shadow-xl">
            <h2 id="crop-title" className="text-lg font-semibold text-ink-900">Crop the banner</h2>
            <p className="mt-1 text-sm text-ink-600">This image is {size.width} × {size.height}. Banners must be the 47:22 shape (like 1880 × 880). Drag the frame to choose the part to show.</p>
            <div ref={box} className="relative mt-4 w-full touch-none select-none overflow-hidden rounded-control bg-surface-100" style={{ aspectRatio: `${size.width} / ${size.height}` }}>
                {url && <img src={url} alt="" className="absolute inset-0 h-full w-full" draggable={false} />}
                <div onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerCancel={up}
                    className="absolute cursor-move border-2 border-white"
                    style={{ left: crop.x * scale, top: crop.y * scale, width: crop.w * scale, height: crop.h * scale, boxShadow: '0 0 0 9999px rgba(28,25,23,0.55)' }}
                    aria-label="Banner frame. Drag to move." />
            </div>
            <label className="mt-4 block text-[13px] font-medium text-ink-700">Zoom
                <input type="range" min={0} max={1} step={0.01} value={zoom} onChange={event => setZoomLevel(Number(event.target.value))} className="mt-1 block w-full accent-brand-500" disabled={maxW <= minW} />
            </label>
            <p className="mt-1 text-xs text-ink-600">Saved as {Math.round(Math.max(BANNER.minWidth, Math.min(BANNER.outWidth, crop.w)))} × {Math.round(Math.max(BANNER.minWidth, Math.min(BANNER.outWidth, crop.w)) / BANNER.ratio)} pixels.</p>
            <div className="mt-5 flex justify-end gap-2">
                <Button onClick={onCancel} disabled={busy}>Cancel</Button>
                <Button variant="primary" onClick={save} disabled={busy}>{busy ? 'Cropping…' : 'Use this crop'}</Button>
            </div>
        </div>
    </div>;
}
