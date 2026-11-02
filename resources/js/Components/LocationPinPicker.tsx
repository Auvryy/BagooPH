import { useEffect, useId, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker } from 'leaflet';

type Point = { latitude: number; longitude: number };

export default function LocationPinPicker({ value, onChange, label = 'Delivery location' }: {
    value: Point | null;
    onChange: (point: Point) => void;
    label?: string;
}) {
    const container = useRef<HTMLDivElement>(null);
    const instructionsId = useId();
    const map = useRef<LeafletMap | null>(null);
    const marker = useRef<Marker | null>(null);
    const setPoint = useRef<((point: Point | null) => void) | null>(null);
    const current = useRef(value);
    const notify = useRef(onChange);
    const [failed, setFailed] = useState(false);
    const [loading, setLoading] = useState(true);
    const [attempt, setAttempt] = useState(0);
    current.current = value;
    notify.current = onChange;

    useEffect(() => {
        let cancelled = false;
        let observer: ResizeObserver | undefined;
        let tileTimeout: ReturnType<typeof setTimeout> | undefined;
        setFailed(false);
        setLoading(true);
        void Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([L]) => {
            if (cancelled || !container.current) return;
            const instance = L.map(container.current, { scrollWheelZoom: false });
            map.current = instance;
            instance.setView(current.current ? [current.current.latitude, current.current.longitude] : [12.8797, 121.774], current.current ? 16 : 5);
            const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
            });
            const failTiles = () => {
                if (tileTimeout) clearTimeout(tileTimeout);
                if (!cancelled) { setFailed(true); setLoading(false); }
            };
            tiles.on('tileerror', failTiles);
            tiles.on('load', () => {
                if (tileTimeout) clearTimeout(tileTimeout);
                if (!cancelled) setLoading(false);
            });
            tileTimeout = setTimeout(failTiles, 15000);
            tiles.addTo(instance);
            const icon = L.divIcon({ className: 'location-pin-marker', html: '<span aria-hidden="true" style="display:block;width:30px;height:30px;border:4px solid white;border-radius:50%;background:#E00D42;box-shadow:0 1px 5px #0008"></span>', iconSize: [44, 44], iconAnchor: [22, 22] });
            setPoint.current = point => {
                if (!point) { marker.current?.remove(); marker.current = null; return; }
                if (!marker.current) {
                    marker.current = L.marker([point.latitude, point.longitude], { draggable: true, icon, title: label, keyboard: true }).addTo(instance);
                    marker.current.on('dragend', () => {
                        const position = marker.current?.getLatLng();
                        if (position) notify.current({ latitude: Number(position.lat.toFixed(7)), longitude: Number(position.lng.toFixed(7)) });
                    });
                } else marker.current.setLatLng([point.latitude, point.longitude]);
            };
            setPoint.current(current.current);
            instance.on('click', event => notify.current({ latitude: Number(event.latlng.lat.toFixed(7)), longitude: Number(event.latlng.lng.toFixed(7)) }));
            instance.on('keypress', event => {
                const keyboardEvent = event.originalEvent;
                if (keyboardEvent.key !== 'Enter' || keyboardEvent.target !== container.current) return;
                const center = instance.getCenter();
                notify.current({ latitude: Number(center.lat.toFixed(7)), longitude: Number(center.lng.toFixed(7)) });
            });
            if (typeof ResizeObserver !== 'undefined') {
                observer = new ResizeObserver(() => instance.invalidateSize({ animate: false }));
                observer.observe(container.current);
            }
        }).catch(() => { if (!cancelled) { setFailed(true); setLoading(false); } });
        return () => { cancelled = true; if (tileTimeout) clearTimeout(tileTimeout); observer?.disconnect(); setPoint.current = null; marker.current = null; map.current?.remove(); map.current = null; };
    }, [label, attempt]);

    useEffect(() => {
        setPoint.current?.(value);
        if (value && map.current) map.current.setView([value.latitude, value.longitude], Math.max(map.current.getZoom(), 15), { animate: false });
    }, [value?.latitude, value?.longitude]);

    const pinStatus = value ? 'Location pin selected.' : 'No location pin selected yet.';
    const tileFailureStatus = value
        ? 'Map tiles could not load. Your pin is still selected; retry to view the location.'
        : 'Map tiles could not load. Check your connection and try again.';
    const loadingStatus = value ? 'Loading map… Your pin is still selected.' : 'Loading map…';

    return <div className="location-pin-picker space-y-2">
        <p id={instructionsId} className="text-xs text-slate-700">Click or tap the map to place a pin, then drag it to the exact location. Keyboard: focus the map and use arrow keys to pan; press Enter to pin its center. Press Tab to reach the zoom controls.</p>
        <div ref={container} role="region" aria-label={`${label} map`} aria-describedby={instructionsId} className="h-64 w-full rounded-lg border border-slate-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#E00D42]" />
        <p className="text-xs text-slate-600" role="status">{failed ? tileFailureStatus : loading ? loadingStatus : pinStatus}</p>
        {failed && <button type="button" onClick={() => setAttempt(previous => previous + 1)} className="min-h-[44px] rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Retry map</button>}
    </div>;
}
