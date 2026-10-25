import { useEffect, useRef, useState } from 'react';
import { MapPin, Minus, Plus, RotateCcw, Route } from 'lucide-react';
import type { Map as LeafletMap } from 'leaflet';
import { CourierPanel, courierButton, courierClasses } from '@/Components/CourierUI';
import { courierMapCoordinates, directionsUrl, type CourierMapJob } from '@/utils/courier';
import sample from '@/data/riderDeliverySample.json';

export default function CourierJobMap({ job, embedded = false }: { job?: CourierMapJob; embedded?: boolean }) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<LeafletMap | null>(null);
    const [sampleMode, setSampleMode] = useState(!job);
    const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
    const [attempt, setAttempt] = useState(0);
    const [mapReady, setMapReady] = useState(false);
    const coordinates = job ? courierMapCoordinates(job.place) : null;
    const latitude = coordinates?.[0];
    const longitude = coordinates?.[1];
    const directions = job ? directionsUrl(job.place) : null;
    const title = sampleMode ? sample.label : job?.stopLabel ?? 'Delivery map';

    useEffect(() => { setSampleMode(!job); }, [job?.key, job?.status, latitude, longitude]);

    useEffect(() => {
        if (!sampleMode && (latitude === undefined || longitude === undefined)) return;
        if (!container.current) return;
        let cancelled = false;
        let observer: ResizeObserver | undefined;
        let timeout: ReturnType<typeof setTimeout> | undefined;
        setStatus('loading');
        setMapReady(false);
        void import('leaflet').then(leaflet => {
            if (cancelled || !container.current) return;
            const instance = leaflet.map(container.current, {
                preferCanvas: true, zoomControl: false, scrollWheelZoom: false, keyboard: true,
                zoomAnimation: false, fadeAnimation: false, markerZoomAnimation: false, inertia: false,
            });
            map.current = instance;
            instance.attributionControl.setPrefix(false);
            const tiles = leaflet.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, keepBuffer: 1,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>',
            });
            let failed = false;
            tiles.on('tileerror', () => { failed = true; clearTimeout(timeout); if (!cancelled) setStatus('error'); });
            tiles.on('load', () => { clearTimeout(timeout); if (!cancelled && !failed) setStatus('ready'); });
            timeout = setTimeout(() => { if (!cancelled) setStatus('error'); }, 15000);
            tiles.addTo(instance);
            if (sampleMode) {
                const points = sample.coordinates.map(p => [p[0], p[1]] as [number, number]);
                const roadStyle = { smoothFactor: 0, lineCap: 'round', lineJoin: 'round', interactive: false } as const;
                leaflet.polyline(points, { ...roadStyle, color: '#FFFFFF', weight: 8 }).addTo(instance);
                leaflet.polyline(points, { ...roadStyle, color: '#E00D42', weight: 5 }).addTo(instance);
                for (const [point, label, color] of [[points[0], sample.riderLabel, '#18181B'], [points[points.length - 1], sample.deliveryLabel, '#E00D42']] as const) {
                    leaflet.circleMarker(point, { radius: 9, color: '#FFFFFF', weight: 3, fillColor: color, fillOpacity: 1 }).addTo(instance).bindTooltip(label);
                }
                instance.fitBounds(leaflet.latLngBounds(points), { padding: [48, 64], maxZoom: 16, animate: false });
            } else {
                instance.setView([latitude!, longitude!], 16, { animate: false });
                const label = document.createElement('span');
                label.textContent = job?.place.name || title;
                leaflet.circleMarker([latitude!, longitude!], { radius: 9, color: '#FFFFFF', weight: 3, fillColor: '#E00D42', fillOpacity: 1 }).addTo(instance).bindTooltip(label);
            }
            if (typeof ResizeObserver !== 'undefined') {
                observer = new ResizeObserver(() => instance.invalidateSize({ animate: false }));
                observer.observe(container.current);
            }
            setMapReady(true);
        }).catch(() => { if (!cancelled) setStatus('error'); });
        return () => {
            cancelled = true;
            clearTimeout(timeout);
            observer?.disconnect();
            map.current?.remove();
            map.current = null;
        };
    }, [job?.key, job?.status, job?.place.name, title, latitude, longitude, sampleMode, attempt]);

    const fit = () => {
        if (sampleMode) map.current?.fitBounds(sample.coordinates.map(p => [p[0], p[1]]), { padding: [48, 64], maxZoom: 16, animate: false });
        else if (coordinates) map.current?.setView(coordinates, 16, { animate: false });
    };
    const content = <>
        {!embedded && <header className="flex flex-wrap items-center justify-between gap-2 p-4 sm:px-5"><h2 className="text-base font-semibold">Delivery map</h2><span className="text-xs text-slate-500">{sampleMode ? 'Sample preview' : 'Saved stop'}</span></header>}
        {sampleMode || coordinates ? <div className={courierClasses('courier-map-surface relative isolate overflow-hidden rounded-[8px] border border-slate-300', !embedded && 'mx-4 sm:mx-5')}>
            <div ref={container} role="region" aria-label={`Map of ${title}`} className="h-80 w-full sm:h-96" />
            <div className="pointer-events-none absolute inset-x-3 top-3 z-[700] flex"><span className="courier-map-label inline-flex max-w-full items-center gap-2 rounded-[8px] border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-800"><MapPin className="h-4 w-4 shrink-0 text-[#E00D42]" aria-hidden="true" /><span className="break-words">{sampleMode ? sample.label : job?.place.name || title}</span></span></div>
            <div role="group" aria-label="Map controls" className="absolute bottom-14 right-3 z-[700] flex flex-col overflow-hidden rounded-[8px] border border-slate-300 bg-white shadow-sm">
                <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomIn(1, { animate: false })} aria-label="Zoom in" className={`${courierButton} h-12 w-12 rounded-none border-0 p-0`}><Plus className="h-5 w-5" aria-hidden="true" /></button>
                <button type="button" disabled={!mapReady} onClick={() => map.current?.zoomOut(1, { animate: false })} aria-label="Zoom out" className={`${courierButton} h-12 w-12 rounded-none border-0 border-t border-slate-200 p-0`}><Minus className="h-5 w-5" aria-hidden="true" /></button>
                <button type="button" disabled={!mapReady} onClick={fit} aria-label={sampleMode ? 'Show sample route' : 'Show stop'} className={`${courierButton} h-12 w-12 rounded-none border-0 border-t border-slate-200 p-0`}><Route className="h-5 w-5" aria-hidden="true" /></button>
            </div>
        </div> : <div className="mx-4 rounded-[8px] border border-slate-300 bg-slate-50 p-5 text-sm text-slate-600">No usable saved pin for this stop. {directions ? 'Use address-based directions for this stop.' : 'Ask your logistics team for the destination.'}</div>}
        <div className={courierClasses('space-y-3 pt-3', !embedded && 'px-4 pb-4 sm:px-5')}>
            {sampleMode ? <div className="space-y-2"><p className="text-xs leading-relaxed text-slate-600">{sample.notice}</p><div className="flex flex-wrap gap-4 text-xs text-slate-700"><span className="inline-flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-[#18181B]" />{sample.riderLabel}</span><span className="inline-flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full bg-[#E00D42]" />{sample.deliveryLabel}</span><span>{sample.distanceMeters.toLocaleString()} m along mapped streets</span></div></div> : job && <p className="text-sm leading-relaxed text-slate-600">{job.place.address || 'Address not provided'}</p>}
            {job && <button type="button" className={courierButton} onClick={() => setSampleMode(v => !v)}>{sampleMode ? 'Show saved stop' : 'Try sample route'}</button>}
            <p role="status" className={status === 'error' ? 'text-sm text-amber-800' : 'sr-only'}>{status === 'loading' ? 'Loading the street map…' : status === 'error' ? 'Street tiles could not load. The route illustration and saved addresses remain available.' : 'Street map loaded.'}</p>
            {status === 'error' && <button type="button" onClick={() => setAttempt(v => v + 1)} className={courierButton}><RotateCcw className="h-4 w-4" aria-hidden="true" />Retry map</button>}
            {!embedded && !sampleMode && directions && <a href={directions} target="_blank" rel="noopener noreferrer" className={courierButton}>External directions</a>}
        </div>
    </>;
    return embedded ? <section className="courier-job-map min-w-0" aria-label="Delivery map">{content}</section> : <CourierPanel className="courier-job-map min-w-0 overflow-hidden">{content}</CourierPanel>;
}
