import { useEffect, useRef, useState } from 'react';
import type { Map as LeafletMap, Marker } from 'leaflet';

type Point = { latitude: number; longitude: number };

export default function LocationPinPicker({ value, onChange, label = 'Delivery location' }: {
    value: Point | null;
    onChange: (point: Point) => void;
    label?: string;
}) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<LeafletMap | null>(null);
    const marker = useRef<Marker | null>(null);
    const setPoint = useRef<((point: Point | null) => void) | null>(null);
    const current = useRef(value);
    const notify = useRef(onChange);
    const [failed, setFailed] = useState(false);
    const [attempt, setAttempt] = useState(0);
    current.current = value;
    notify.current = onChange;

    useEffect(() => {
        let cancelled = false;
        let observer: ResizeObserver | undefined;
        setFailed(false);
        void Promise.all([import('leaflet'), import('leaflet/dist/leaflet.css')]).then(([L]) => {
            if (cancelled || !container.current) return;
            const instance = L.map(container.current, { scrollWheelZoom: false });
            map.current = instance;
            instance.setView(current.current ? [current.current.latitude, current.current.longitude] : [12.8797, 121.774], current.current ? 16 : 5);
            const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>',
            });
            tiles.on('tileerror', () => { if (!cancelled) setFailed(true); });
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
            if (typeof ResizeObserver !== 'undefined') {
                observer = new ResizeObserver(() => instance.invalidateSize({ animate: false }));
                observer.observe(container.current);
            }
        }).catch(() => { if (!cancelled) setFailed(true); });
        return () => { cancelled = true; observer?.disconnect(); setPoint.current = null; marker.current = null; map.current?.remove(); map.current = null; };
    }, [label, attempt]);

    useEffect(() => {
        setPoint.current?.(value);
        if (value && map.current) map.current.setView([value.latitude, value.longitude], Math.max(map.current.getZoom(), 15), { animate: false });
    }, [value?.latitude, value?.longitude]);

    return <div className="location-pin-picker space-y-2">
        <p className="text-xs text-slate-700">Tap the map to place a pin, then drag it to the exact location.</p>
        <div ref={container} role="region" aria-label={`${label} map`} className="h-64 w-full rounded-lg border border-slate-300" />
        <p className="text-xs text-slate-600" role="status">{failed ? 'Map tiles could not load. Check your connection and try again.' : value ? 'Location pin selected.' : 'No location pin selected yet.'}</p>
        {failed && <button type="button" onClick={() => setAttempt(previous => previous + 1)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">Retry map</button>}
    </div>;
}
