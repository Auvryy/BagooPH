import { useEffect, useRef, useState } from 'react';
import { MapPin, Minus, Navigation, Plus, RotateCcw } from 'lucide-react';
import type { Map as LeafletMap, LayerGroup } from 'leaflet';
import { requestRoadPreview, type RoadPreview } from '@/utils/courierRoadRoute';
import { CourierBadge, CourierPanel, courierButton, courierClasses } from '@/Components/CourierUI';
import { courierMapCoordinates, directionsUrl, type CourierMapJob } from '@/utils/courier';

export default function CourierJobMap({ job, embedded = false }: { job: CourierMapJob; embedded?: boolean }) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<LeafletMap | null>(null);
    const routeLayer = useRef<LayerGroup | null>(null);
    const routeRequest = useRef<AbortController | null>(null);
    const routeGeneration = useRef(0);
    const [road, setRoad] = useState<RoadPreview | null>(null);
    const [routing, setRouting] = useState(false);
    const [routeError, setRouteError] = useState('');
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [attempt, setAttempt] = useState(0);
    const [mapReady, setMapReady] = useState(false);
    const coordinates = courierMapCoordinates(job.place);
    const latitude = coordinates?.[0];
    const longitude = coordinates?.[1];
    const directions = directionsUrl(job.place);

    useEffect(() => {
        const clear = () => { routeGeneration.current++; routeRequest.current?.abort(); routeLayer.current?.clearLayers(); setRoad(null); setRouting(false); setRouteError(''); };
        clear();
        const hide = () => { if (document.visibilityState === 'hidden') clear(); };
        document.addEventListener('visibilitychange', hide);
        return () => { document.removeEventListener('visibilitychange', hide); routeGeneration.current++; routeRequest.current?.abort(); };
    }, [job.key, job.stage, job.status, latitude, longitude]);

    const previewRoad = () => {
        if ((routeRequest.current && !routeRequest.current.signal.aborted) || routing) return;
        if (job.preview || !job.status || !navigator.geolocation) { setRouteError('Location access is unavailable. Use the stop address or external directions.'); return; }
        const generation = ++routeGeneration.current;
        routeRequest.current?.abort();
        const controller = new AbortController(); routeRequest.current = controller;
        routeLayer.current?.clearLayers(); setRoad(null); setRouteError(''); setRouting(true);
        try { navigator.geolocation.getCurrentPosition(async fix => {
            if (controller.signal.aborted || generation !== routeGeneration.current) return;
            try {
                const value = await requestRoadPreview(job.key, job.trackingNumber, job.status!, fix, controller.signal);
                if (!controller.signal.aborted && generation === routeGeneration.current) setRoad(value);
            } catch (error) {
                if (!controller.signal.aborted && generation === routeGeneration.current) setRouteError(error instanceof Error ? error.message : 'The road route is unavailable. Use the stop address.');
            } finally { if (!controller.signal.aborted && generation === routeGeneration.current) { setRouting(false); if (routeRequest.current === controller) routeRequest.current = null; } }
        }, () => { if (!controller.signal.aborted && generation === routeGeneration.current) { setRouteError('Your location could not be read. Use the stop address or external directions.'); setRouting(false); routeRequest.current = null; } }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        } catch { controller.abort(); routeRequest.current = null; setRouting(false); setRouteError('Your browser did not allow location access. Use external directions.'); }
    };

    useEffect(() => {
        if (!road || !map.current || !mapReady) return;
        let cancelled = false;
        void import('leaflet').then(leaflet => {
            if (cancelled || !map.current) return;
            routeLayer.current?.clearLayers();
            const layer = leaflet.layerGroup().addTo(map.current); routeLayer.current = layer;
            for (const line of road.lines) {
                leaflet.polyline(line, { color: '#FFFFFF', weight: 7, opacity: 0.9, interactive: false }).addTo(layer);
                leaflet.polyline(line, { color: '#E00D42', weight: 4, opacity: 1, interactive: false }).addTo(layer);
            }
            const first = road.lines[0][0];
            leaflet.circleMarker(first, { radius: 6, color: '#FFFFFF', weight: 2, fillColor: '#18181B', fillOpacity: 1 }).addTo(layer);
            map.current.fitBounds(leaflet.latLngBounds(road.lines.flat()), { padding: [30, 30], maxZoom: 17, animate: false });
        });
        return () => { cancelled = true; routeLayer.current?.remove(); routeLayer.current = null; };
    }, [road, mapReady]);

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
                    preferCanvas: true, zoomControl: false, scrollWheelZoom: false, keyboard: true,
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
            routeLayer.current?.remove(); routeLayer.current = null;
            map.current?.remove();
            map.current = null;
        };
    }, [job.key, job.stopLabel, latitude, longitude, attempt]);

    const content = <>
            <div className={embedded ? 'sr-only' : 'space-y-3 p-4 sm:p-5'}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="rider-map-title" tabIndex={-1} className="text-xl font-extrabold focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#E00D42]">{job.preview ? 'Pickup preview' : 'Your selected stop'}</h2>
                    <span className="flex h-12 w-12 items-center justify-center rounded-[8px] border border-rose-300 bg-[#FDF2F4] text-[#C20836]"><Navigation className="h-6 w-6" aria-hidden="true" /></span>
                </div>
                <CourierBadge tone={job.preview ? 'neutral' : 'brand'}>{job.stage}</CourierBadge>
                <p className="break-all text-sm font-semibold text-slate-700">{job.trackingNumber}</p>
                <p className="text-sm font-semibold text-slate-600">{job.stopLabel}</p>
                <h3 className="break-words text-lg font-bold">{job.place.name || 'Name not provided'}</h3>
                <p className="whitespace-pre-line break-words text-base leading-relaxed text-slate-700">{job.place.address || 'Address not provided'}</p>
            </div>
            {coordinates ? <>
                <div className={courierClasses('courier-map-surface relative isolate overflow-hidden rounded-[8px] border border-slate-300', !embedded && 'mx-4 sm:mx-5')}>
                    {embedded && <div className="pointer-events-none absolute left-3 right-3 top-3 z-[700] flex items-start"><span className="courier-map-label inline-flex max-w-full items-center gap-2 rounded-[8px] border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700"><MapPin className="h-4 w-4 shrink-0 text-[#C20836]" aria-hidden="true" /><span className="break-words">{job.place.name || job.stopLabel}</span></span></div>}
                    <div ref={container} role="region" aria-label={`Map of ${job.stopLabel}`} aria-describedby="rider-map-help" className={embedded ? 'h-60 w-full min-[860px]:aspect-[4/3] min-[860px]:h-auto min-[860px]:max-h-80' : 'h-60 w-full sm:h-72'} />
                </div>
                <div className={courierClasses('space-y-3 pt-3', !embedded && 'px-4 sm:px-5')}>
                    <div role="group" aria-label="Map controls" className="flex flex-wrap gap-2">
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomIn(1, { animate: false })} aria-label="Zoom in" className={`${courierButton} h-12 w-12 p-0`}><Plus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomOut(1, { animate: false })} aria-label="Zoom out" className={`${courierButton} h-12 w-12 p-0`}><Minus className="h-5 w-5" aria-hidden="true" /></button>
                        <button type="button" disabled={!mapReady} onClick={() => { if (coordinates) map.current?.setView(coordinates, 16, { animate: false }); }} className={courierButton}><MapPin className="h-4 w-4" aria-hidden="true" />Show stop</button>
                        {road && <button type="button" onClick={() => map.current?.fitBounds(road.lines.flat(), { padding: [30, 30], maxZoom: 17, animate: false })} className={courierButton}>Show route</button>}
                    </div>
                    {!job.preview && job.status && <button type="button" onClick={previewRoad} disabled={!mapReady || routing} className={courierButton}><Navigation className="h-4 w-4" aria-hidden="true" />{routing ? 'Calculating road route…' : 'Preview road route'}</button>}
                    {routing && <button type="button" className={courierButton} onClick={() => { routeGeneration.current++; routeRequest.current?.abort(); routeRequest.current = null; setRouting(false); }}>Cancel route</button>}
                    {road && <p role="status" className="text-xs text-slate-600">{road.mode === 'drive' ? 'Driving' : road.mode === 'scooter' ? 'Scooter' : 'Motorcycle'} road preview · from a location snapshot, without live tracking. <button type="button" className="font-medium text-[#C20836] underline" onClick={() => { setRoad(null); routeLayer.current?.clearLayers(); }}>Clear route</button></p>}
                    {routeError && <p role="status" className="text-sm text-amber-800">{routeError}</p>}
                    <p id="rider-map-help" className="text-xs leading-relaxed text-slate-600">Saved stop · use arrow keys and +/− to explore. Live tracking is unavailable.</p>
                    <p role="status" className={courierClasses('text-sm text-slate-600', embedded && status !== 'error' && 'sr-only')}>{status === 'loading' ? 'Loading the street map…' : status === 'error' ? 'Street tiles could not load. The saved stop and address are still available.' : 'Street map loaded.'}</p>
                    {status === 'error' && <button type="button" onClick={() => setAttempt((value) => value + 1)} className={courierButton}><RotateCcw className="h-4 w-4" aria-hidden="true" />Retry map</button>}
                </div>
            </> : <div className={courierClasses('courier-inset flex min-h-60 flex-col items-center justify-center gap-4 rounded-[8px] border border-slate-300 bg-[#FFF2F4] p-5 text-center', !embedded && 'mx-4 sm:mx-5')}><span className="flex h-14 w-14 items-center justify-center rounded-[8px] border border-rose-300 bg-white text-[#C20836]"><MapPin className="h-6 w-6" aria-hidden="true" /></span><p className="max-w-xs text-base font-semibold text-slate-800">No usable saved pin for this stop.</p><p className="max-w-xs text-sm leading-relaxed text-slate-600">{directions ? 'Use address-based directions for this stop.' : 'Ask your logistics team to provide the destination.'}</p></div>}
            <div className={embedded ? 'sr-only' : 'space-y-3 p-4 sm:p-5'}>
                <p className="text-base leading-relaxed text-slate-700">{job.instruction}</p>
                {!embedded && directions && <a href={directions} target="_blank" rel="noopener noreferrer" className={`${courierButton} w-full`}><Navigation className="h-4 w-4" aria-hidden="true" />Directions</a>}
            </div>
        </>;

    return embedded
        ? <section className="courier-job-map min-w-0" aria-label="Selected stop map">{content}</section>
        : <CourierPanel className="courier-job-map min-w-0 overflow-hidden">{content}</CourierPanel>;
}
