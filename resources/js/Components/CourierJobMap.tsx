import { useEffect, useRef, useState } from 'react';
import { MapPin, Minus, Navigation, Plus, RotateCcw } from 'lucide-react';
import type { Map as LeafletMap } from 'leaflet';
import { CourierBadge, CourierPanel, courierButton } from '@/Components/CourierUI';
import { courierMapCoordinates, directionsUrl, type CourierMapJob } from '@/utils/courier';

export default function CourierJobMap({ job }: { job: CourierMapJob }) {
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
                leaflet.circleMarker([latitude, longitude], {
                    radius: 10, color: '#FFFFFF', weight: 3, fillColor: '#E00D42', fillOpacity: 1,
                }).addTo(instance).bindTooltip(label, { direction: 'top', offset: [0, -10] });
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

    return (
        <CourierPanel className="courier-job-map min-w-0 overflow-hidden">
            <div className="space-y-3 p-4 sm:p-5">
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
                <div className="relative isolate mx-4 overflow-hidden rounded-[18px] border border-slate-300 sm:mx-5">
                    <div ref={container} role="region" aria-label={`Map of ${job.stopLabel}`} aria-describedby="rider-map-help" className="h-60 w-full sm:h-72" />
                </div>
                <div className="space-y-3 px-4 pt-3 sm:px-5">
                    <div role="group" aria-label="Map controls" className="flex flex-wrap gap-2">
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomIn(1, { animate: false })} aria-label="Zoom in" className={`${courierButton} h-12 w-12 p-0`}><Plus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomOut(1, { animate: false })} aria-label="Zoom out" className={`${courierButton} h-12 w-12 p-0`}><Minus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => { if (coordinates) map.current?.setView(coordinates, 16, { animate: false }); }} className={courierButton}><MapPin className="h-4 w-4" aria-hidden="true" />Show stop</button>
                    </div>
                    <p id="rider-map-help" className="text-sm leading-relaxed text-slate-600">The red pin is the saved stop. Drag to explore, or focus the map and use arrow keys and +/−. Your live location and route are not tracked here.</p>
                    <p role="status" className="text-sm text-slate-600">{status === 'loading' ? 'Loading the street map…' : status === 'error' ? 'Street tiles could not load. The saved stop and address are still available.' : 'Street map loaded.'}</p>
                    {status === 'error' && <button type="button" onClick={() => setAttempt((value) => value + 1)} className={courierButton}><RotateCcw className="h-4 w-4" aria-hidden="true" />Retry map</button>}
                </div>
            </> : <div className="mx-4 flex items-start gap-3 rounded-[18px] border border-amber-300 bg-[#FFF4DF] p-4 text-[#92400E] sm:mx-5"><MapPin className="mt-1 h-6 w-6 shrink-0" aria-hidden="true" /><p className="text-base leading-relaxed">No usable saved pin for this stop. {directions ? 'Use address-based directions below.' : 'Ask your logistics team to provide the destination.'}</p></div>}
            <div className="space-y-3 p-4 sm:p-5">
                <p className="text-base leading-relaxed text-slate-700">{job.instruction}</p>
                {directions && <a href={directions} target="_blank" rel="noopener noreferrer" className={`${courierButton} w-full`}><Navigation className="h-4 w-4" aria-hidden="true" />Directions</a>}
            </div>
        </CourierPanel>
    );
}
