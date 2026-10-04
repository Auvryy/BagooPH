import { useEffect, useRef, useState } from 'react';
import { MapPin, Minus, Navigation, Plus, RotateCcw } from 'lucide-react';
import type { Map as LeafletMap } from 'leaflet';
import { CourierBadge, CourierPanel, courierButton, courierClasses } from '@/Components/CourierUI';
import { courierMapCoordinates, directionsUrl, type CourierMapJob } from '@/utils/courier';

export default function CourierJobMap({ job, embedded = false }: { job: CourierMapJob; embedded?: boolean }) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<LeafletMap | null>(null);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [attempt, setAttempt] = useState(0);
    const [mapReady, setMapReady] = useState(false);
    const coordinates = courierMapCoordinates(job.place);
    const latitude = coordinates?.[0];
    const longitude = coordinates?.[1];
    const directions = directionsUrl(job.place);

    useEffect(() => {
        if (latitude === undefined || longitude === undefined || !container.current) return;
        let cancelled = false;
        let observer: ResizeObserver | undefined;
        let loadTimeout: ReturnType<typeof setTimeout> | undefined;
        setStatus('loading');
        setMapReady(false);
        const initialize = async () => {
            try {
                const leaflet = await import('leaflet');
                if (cancelled || !container.current) return;
                const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                const instance = leaflet.map(container.current, {
                    zoomControl: false, scrollWheelZoom: false, keyboard: true,
                    zoomAnimation: !reduceMotion, fadeAnimation: !reduceMotion,
                    markerZoomAnimation: !reduceMotion, inertia: !reduceMotion,
                }).setView([latitude, longitude], 16, { animate: false });
                map.current = instance;
                setMapReady(true);
                instance.attributionControl.setPrefix(false);
                const tiles = leaflet.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
                });
                let tileFailed = false;
                tiles.on('tileerror', () => { tileFailed = true; clearTimeout(loadTimeout); if (!cancelled) setStatus('error'); });
                tiles.on('load', () => { clearTimeout(loadTimeout); if (!cancelled && !tileFailed) setStatus('ready'); });
                loadTimeout = setTimeout(() => { if (!cancelled) setStatus('error'); }, 15000);
                tiles.addTo(instance);
                const label = document.createElement('span');
                label.textContent = job.stopLabel;
                leaflet.marker([latitude, longitude], {
                    title: job.stopLabel,
                    icon: leaflet.divIcon({
                        className: 'courier-stop-pin', iconSize: [36, 44], iconAnchor: [18, 42],
                        html: '<span aria-hidden="true"><svg width="36" height="44" viewBox="0 0 36 44" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M18 42S2 25 2 18a16 16 0 1 1 32 0c0 7-16 24-16 24Z" fill="#E00D42" stroke="white" stroke-width="2"/><circle cx="18" cy="17" r="6" stroke="white" stroke-width="2"/><circle cx="18" cy="17" r="2" fill="white"/></svg></span>',
                    }),
                }).addTo(instance).bindTooltip(label, { direction: 'top', offset: [0, -40] });
                if (typeof ResizeObserver !== 'undefined') {
                    observer = new ResizeObserver(() => instance.invalidateSize({ animate: false }));
                    observer.observe(container.current);
                }
            } catch {
                if (!cancelled) setStatus('error');
            }
        };
        void initialize();
        return () => {
            cancelled = true;
            clearTimeout(loadTimeout);
            observer?.disconnect();
            map.current?.remove();
            map.current = null;
        };
    }, [job.key, job.stopLabel, latitude, longitude, attempt]);

    const content = <>
            <div className={embedded ? 'sr-only' : 'space-y-3 p-4 sm:p-5'}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="rider-map-title" tabIndex={-1} className="text-xl font-extrabold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">{job.preview ? 'Pickup preview' : 'Your selected stop'}</h2>
                    <span className="flex h-12 w-12 items-center justify-center rounded-full border border-rose-300 bg-[#FDF2F4] text-[#C20836]"><Navigation className="h-6 w-6" aria-hidden="true" /></span>
                </div>
                <CourierBadge tone={job.preview ? 'neutral' : 'brand'}>{job.stage}</CourierBadge>
                <p className="break-all text-sm font-semibold text-slate-700">{job.trackingNumber}</p>
                <p className="text-sm font-semibold text-slate-600">{job.stopLabel}</p>
                <h3 className="break-words text-lg font-bold">{job.place.name || 'Name not provided'}</h3>
                <p className="whitespace-pre-line break-words text-base leading-relaxed text-slate-700">{job.place.address || 'Address not provided'}</p>
            </div>
            {coordinates ? <>
                <div className={courierClasses('courier-map-surface relative isolate overflow-hidden rounded-[16px] border border-slate-300', !embedded && 'mx-4 sm:mx-5')}>
                    {embedded && <div className="pointer-events-none absolute left-3 right-3 top-3 z-[700] flex items-start"><span className="inline-flex max-w-full items-center gap-2 rounded-[12px] border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700"><MapPin className="h-4 w-4 shrink-0 text-[#C20836]" aria-hidden="true" /><span className="break-words">{job.place.name || job.stopLabel}</span></span></div>}
                    <div ref={container} role="region" aria-label={`Map of ${job.stopLabel}`} aria-describedby="rider-map-help" className={embedded ? 'h-60 w-full min-[860px]:aspect-[4/3] min-[860px]:h-auto min-[860px]:max-h-80' : 'h-60 w-full sm:h-72'} />
                </div>
                <div className={courierClasses('space-y-3 pt-3', !embedded && 'px-4 sm:px-5')}>
                    <div role="group" aria-label="Map controls" className="flex flex-wrap gap-2">
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomIn(1, { animate: false })} aria-label="Zoom in" className={`${courierButton} h-12 w-12 p-0`}><Plus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomOut(1, { animate: false })} aria-label="Zoom out" className={`${courierButton} h-12 w-12 p-0`}><Minus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => { if (coordinates) map.current?.setView(coordinates, 16, { animate: false }); }} className={courierButton}><MapPin className="h-4 w-4" aria-hidden="true" />Show stop</button>
                    </div>
                    <p id="rider-map-help" className="text-xs leading-relaxed text-slate-600">Saved stop · use arrow keys and +/− to explore. Live tracking is unavailable.</p>
                    <p role="status" className={courierClasses('text-sm text-slate-600', embedded && status !== 'error' && 'sr-only')}>{status === 'loading' ? 'Loading the street map…' : status === 'error' ? 'Street tiles could not load. The saved stop and address are still available.' : 'Street map loaded.'}</p>
                    {status === 'error' && <button type="button" onClick={() => setAttempt((value) => value + 1)} className={courierButton}><RotateCcw className="h-4 w-4" aria-hidden="true" />Retry map</button>}
                </div>
            </> : <div className={courierClasses('flex min-h-60 flex-col items-center justify-center gap-4 rounded-[16px] border border-slate-300 bg-[#FFF2F4] p-5 text-center', !embedded && 'mx-4 sm:mx-5')}><span className="flex h-14 w-14 items-center justify-center rounded-full border border-rose-300 bg-white text-[#C20836]"><MapPin className="h-6 w-6" aria-hidden="true" /></span><p className="max-w-xs text-base font-semibold text-slate-800">No usable saved pin for this stop.</p><p className="max-w-xs text-sm leading-relaxed text-slate-600">{directions ? 'Use address-based directions for this stop.' : 'Ask your logistics team to provide the destination.'}</p></div>}
            <div className={embedded ? 'sr-only' : 'space-y-3 p-4 sm:p-5'}>
                <p className="text-base leading-relaxed text-slate-700">{job.instruction}</p>
                {!embedded && directions && <a href={directions} target="_blank" rel="noopener noreferrer" className={`${courierButton} w-full`}><Navigation className="h-4 w-4" aria-hidden="true" />Directions</a>}
            </div>
        </>;

    return embedded
        ? <section className="courier-job-map min-w-0" aria-label="Selected stop map">{content}</section>
        : <CourierPanel className="courier-job-map min-w-0 overflow-hidden">{content}</CourierPanel>;
}
