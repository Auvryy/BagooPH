import { useEffect, useMemo, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker } from 'leaflet';
import { MapPinned } from 'lucide-react';
import type { HubPlace } from './Workspace';
import 'leaflet/dist/leaflet.css';

export { hubPoint } from '@/utils/hubWorkspace';
import { hubPoint } from '@/utils/hubWorkspace';

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
            for (const [from, to] of connections) if (points.has(from) && points.has(to)) L.polyline([points.get(from)!, points.get(to)!], { color: '#E00D42', weight: 2.5, opacity: .65, dashArray: '6 7' }).addTo(instance);
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

    return <figure className={`hub-network-map relative isolate flex min-h-64 flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-100 ${className}`}>
        {valid.length ? <div ref={element} aria-label="Company hubs on OpenStreetMap; choose a hub marker to inspect it" className="min-h-0 w-full flex-1" /> : <div className="flex min-h-0 flex-1 flex-col items-center justify-center px-6 py-6 text-center"><MapPinned className="h-8 w-8 text-slate-400" /><p className="mt-3 text-sm font-semibold text-slate-700">No map pins saved yet</p><p className="mt-2 max-w-sm text-xs leading-5 text-slate-500">Use the hub names and addresses below. A location appears here when its coordinates are recorded.</p></div>}
        <figcaption className="relative shrink-0 space-y-1 border-t border-slate-200 bg-white px-3 py-2 text-[11px] leading-5 text-slate-500">
            {!!valid.length && <p>Dashed links show hub connections, not driving directions.{selected && <span className="ml-1 font-semibold text-slate-700">Selected: {selected.code ?? selected.name}.</span>}</p>}
            {tileError && <p>Street tiles are unavailable. Saved hub pins and the hub list still work.</p>}
            {missing > 0 && <p>{missing} {missing === 1 ? 'hub has' : 'hubs have'} no saved map pin; use the address list.</p>}
        </figcaption>
    </figure>;
}
