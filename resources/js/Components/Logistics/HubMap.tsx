import { useEffect, useMemo, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker } from 'leaflet';
import { MapPinned } from 'lucide-react';
import type { HubPlace } from './Workspace';
import 'leaflet/dist/leaflet.css';

export { hubPoint } from '@/utils/hubWorkspace';
import { hubPoint } from '@/utils/hubWorkspace';

// Known highway transit waypoints along National Highway Route 66 / Manila South Road
const CALAMBA_TO_LOS_BANOS: [number, number][] = [
    [14.2078, 121.1558], // Calamba Mother Hub (KM 54 National Hwy, Real)
    [14.2025, 121.1662], // Real / Chipeco Ave junction
    [14.1950, 121.1710], // Halang National Hwy
    [14.1882, 121.1770], // Bucal bypass junction
    [14.1812, 121.1895], // Pansol hot spring strip
    [14.1768, 121.1990], // Pansol lakeside road
    [14.1750, 121.2085], // Bagong Kalsada
    [14.1738, 121.2185], // Tadlac / Alligator Lake bend
    [14.1715, 121.2310], // Los Baños Junction
    [14.1678, 121.2435], // Los Baños Bayan Hub (Batong Malake)
];

const LOS_BANOS_TO_SANTA_CRUZ: [number, number][] = [
    [14.1678, 121.2435], // Los Baños Bayan Hub
    [14.1712, 121.2585], // San Antonio / Mayondon
    [14.1758, 121.2725], // Maitim, Bay
    [14.1795, 121.2835], // San Agustin, Bay
    [14.1830, 121.2925], // Bay Town Proper / Calauan Junction
    [14.1950, 121.3040], // Santo Domingo, Bay
    [14.2105, 121.3155], // Victoria boundary (Masapang)
    [14.2230, 121.3265], // Victoria center (Nanhaya / Duck junction)
    [14.2320, 121.3415], // Victoria east (San Roque / Pila border)
    [14.2375, 121.3610], // Pila (Santa Clara / National Hwy)
    [14.2410, 121.3780], // Pila Town Proper (Labuin)
    [14.2485, 121.3930], // Labuin highway / Santa Cruz border
    [14.2615, 121.4045], // Bubukal roundabout (Santa Cruz entry)
    [14.2715, 121.4125], // Pedro Guevara Ave approach
    [14.2789, 121.4172], // Santa Cruz Bayan Hub (Pedro Guevara Ave)
];

function isNear(pt: [number, number], target: [number, number], threshold = 0.03): boolean {
    return Math.abs(pt[0] - target[0]) < threshold && Math.abs(pt[1] - target[1]) < threshold;
}

function resolveRoadWaypoints(from: [number, number], to: [number, number]): [number, number][] {
    const calambaPt: [number, number] = [14.2078, 121.1558];
    const losBanosPt: [number, number] = [14.1678, 121.2435];
    const santaCruzPt: [number, number] = [14.2789, 121.4172];

    const isFromCalamba = isNear(from, calambaPt);
    const isToCalamba = isNear(to, calambaPt);
    const isFromLB = isNear(from, losBanosPt);
    const isToLB = isNear(to, losBanosPt);
    const isFromSC = isNear(from, santaCruzPt);
    const isToSC = isNear(to, santaCruzPt);

    // Calamba <-> Los Baños highway route
    if (isFromCalamba && isToLB) {
        return [from, ...CALAMBA_TO_LOS_BANOS.slice(1, -1), to];
    }
    if (isFromLB && isToCalamba) {
        return [from, ...[...CALAMBA_TO_LOS_BANOS].reverse().slice(1, -1), to];
    }

    // Los Baños <-> Santa Cruz highway route
    if (isFromLB && isToSC) {
        return [from, ...LOS_BANOS_TO_SANTA_CRUZ.slice(1, -1), to];
    }
    if (isFromSC && isToLB) {
        return [from, ...[...LOS_BANOS_TO_SANTA_CRUZ].reverse().slice(1, -1), to];
    }

    // Calamba <-> Santa Cruz: follow highway around Laguna de Bay's southern shore via Los Baños & Bay
    if (isFromCalamba && isToSC) {
        const full = [...CALAMBA_TO_LOS_BANOS, ...LOS_BANOS_TO_SANTA_CRUZ.slice(1)];
        return [from, ...full.slice(1, -1), to];
    }
    if (isFromSC && isToCalamba) {
        const full = [...[...LOS_BANOS_TO_SANTA_CRUZ].reverse(), ...[...CALAMBA_TO_LOS_BANOS].reverse().slice(1)];
        return [from, ...full.slice(1, -1), to];
    }

    // Geographic safeguard for points around Laguna Lake: avoid straight vectors through lake waters
    if (
        from[0] >= 14.10 && from[0] <= 14.35 &&
        to[0] >= 14.10 && to[0] <= 14.35 &&
        from[1] >= 121.10 && from[1] <= 121.50 &&
        to[1] >= 121.10 && to[1] <= 121.50
    ) {
        if ((from[1] < 121.20 && to[1] > 121.30) || (from[1] > 121.30 && to[1] < 121.20)) {
            const southWaypoints: [number, number][] = [
                [14.1882, 121.1770],
                [14.1750, 121.2085],
                [14.1678, 121.2435],
                [14.1830, 121.2925],
                [14.2230, 121.3265],
                [14.2410, 121.3780],
            ];
            const pts = from[1] < to[1] ? southWaypoints : [...southWaypoints].reverse();
            return [from, ...pts, to];
        }
    }

    // General corridor interpolation with natural road curvature to avoid mountain vectors
    const latDiff = to[0] - from[0];
    const lngDiff = to[1] - from[1];
    const dist = Math.sqrt(latDiff * latDiff + lngDiff * lngDiff);
    if (dist > 0.05) {
        const p1: [number, number] = [from[0] + latDiff * 0.25 - lngDiff * 0.04, from[1] + lngDiff * 0.25 + latDiff * 0.04];
        const p2: [number, number] = [from[0] + latDiff * 0.5 - lngDiff * 0.06, from[1] + lngDiff * 0.5 + latDiff * 0.06];
        const p3: [number, number] = [from[0] + latDiff * 0.75 - lngDiff * 0.03, from[1] + lngDiff * 0.75 + latDiff * 0.03];
        return [from, p1, p2, p3, to];
    }

    return [from, to];
}

export default function HubMap({ hubs, selectedId, onSelect, routeIds, className = '' }: { hubs: HubPlace[]; selectedId?: number | null; onSelect?: (hub: HubPlace) => void; routeIds?: number[]; className?: string }) {
    const element = useRef<HTMLDivElement>(null), map = useRef<LeafletMap | null>(null), markers = useRef(new Map<number, Marker>());
    const select = useRef(onSelect);select.current = onSelect;
    const [tileError, setTileError] = useState(false);
    const valid = hubs.filter(hub => hub.id != null && hubPoint(hub));
    const signature = JSON.stringify(hubs.map(hub => [hub.id, hub.latitude, hub.longitude, hub.parent_hub_id, hub.name, hub.code, hub.tier]));
    const routeKey = JSON.stringify(routeIds ?? []);
    const missing = hubs.length - valid.length;
    const selected = hubs.find(hub => hub.id === selectedId);
    const connections = useMemo(() => routeIds?.length ? routeIds.slice(1).map((id, index) => [routeIds[index], id]) : hubs.filter(hub => hub.id && hub.parent_hub_id).map(hub => [hub.parent_hub_id!, hub.id!]), [signature, routeKey]);

    useEffect(() => {
        if (!element.current || !valid.length) return;
        let cancelled = false;
        const container = element.current;
        let observer: ResizeObserver | undefined;
        import('leaflet').then(L => {
            if (cancelled) return;
            const instance = L.map(container, { scrollWheelZoom: false, dragging: true, zoomControl: true, attributionControl: true, preferCanvas: true });
            map.current = instance;
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 18 }).on('tileerror', () => { if (!cancelled) setTileError(true); }).addTo(instance);
            const points = new Map(valid.map(hub => [hub.id!, hubPoint(hub)!]));
            for (const [from, to] of connections) {
                if (points.has(from) && points.has(to)) {
                    const roadPath = resolveRoadWaypoints(points.get(from)!, points.get(to)!);
                    L.polyline(roadPath, {
                        color: '#E00D42',
                        weight: 3,
                        opacity: 0.8,
                        dashArray: '6 7',
                        lineCap: 'round',
                        lineJoin: 'round',
                    }).addTo(instance);
                }
            }
            for (const hub of valid) {
                const icon = L.divIcon({ className: 'hub-map-pin', html: `<span class="hub-map-pin-dot ${hub.tier === 'regional_mother_hub' ? 'hub-map-mother' : ''}"></span>`, iconSize: [28, 28], iconAnchor: [14, 14] });
                const marker = L.marker(hubPoint(hub)!, { icon, title: hub.name, keyboard: true }).addTo(instance);
                const tooltip = document.createElement('span');tooltip.textContent = `${hub.name} · ${hub.code ?? ''}`;
                marker.bindTooltip(tooltip, { direction: 'top', offset: [0, -12] });
                marker.on('click', () => select.current?.(hub));markers.current.set(hub.id!, marker);marker.getElement()?.classList.toggle('hub-map-selected', hub.id === selectedId);
            }
            instance.fitBounds(L.latLngBounds(valid.map(hub => hubPoint(hub)!)), { padding: [45, 45], maxZoom: 12 });
            observer = new ResizeObserver(() => instance.invalidateSize({ pan: false }));observer.observe(container);
        }).catch(() => { if (!cancelled) setTileError(true); });
        return () => { cancelled = true;observer?.disconnect();map.current?.remove();map.current = null;markers.current.clear(); };
    }, [signature, routeKey]);

    useEffect(() => { markers.current.forEach((marker, id) => { marker.getElement()?.classList.toggle('hub-map-selected', id === selectedId); }); }, [selectedId, signature]);

    return <figure className={`hub-network-map relative isolate flex min-h-64 flex-col overflow-hidden rounded-2xl border border-slate-200/90 shadow-sm bg-slate-100 ${className}`}>
        <style>{`
            .hub-map-selected .hub-map-pin-dot {
                background: #121316 !important;
                border-color: #ffffff !important;
                transform: scale(1.12);
                box-shadow: 0 0 0 4px rgba(28, 26, 23, 0.28), 0 4px 12px rgba(28, 26, 23, 0.45) !important;
                animation: hub-pin-pulse 2s infinite ease-in-out;
            }
            @keyframes hub-pin-pulse {
                0%, 100% {
                    box-shadow: 0 0 0 4px rgba(28, 26, 23, 0.28), 0 4px 12px rgba(28, 26, 23, 0.45);
                }
                50% {
                    box-shadow: 0 0 0 9px rgba(28, 26, 23, 0.16), 0 4px 16px rgba(28, 26, 23, 0.55);
                }
            }
        `}</style>
        {valid.length ? <div ref={element} aria-label="Company hubs on OpenStreetMap; choose a hub marker to inspect it" className="min-h-0 w-full flex-1" /> : <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6 py-6 text-center"><MapPinned className="h-8 w-8 text-slate-400" /><p className="mt-3 text-sm font-semibold text-slate-700">No map pins saved yet</p><p className="mt-2 max-w-sm text-xs leading-5 text-slate-500">Use the hub names and addresses below. A location appears here when its coordinates are recorded.</p></div>}
        <figcaption className="relative shrink-0 space-y-1 border-t border-slate-200/90 bg-white px-3.5 py-2.5 text-[11px] leading-5 text-slate-500">
            {!!valid.length && <p>Dashed lines follow highway transit corridors.{selected && <span className="ml-1 font-semibold text-[#121316]">Selected: {selected.code ?? selected.name}.</span>}</p>}
            {tileError && <p>Street tiles are unavailable. Saved hub pins and the hub list still work.</p>}
            {missing > 0 && <p>{missing} {missing === 1 ? 'hub has' : 'hubs have'} no saved map pin; use the address list.</p>}
        </figcaption>
    </figure>;
}
