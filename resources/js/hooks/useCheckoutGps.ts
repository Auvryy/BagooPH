import { useEffect, useRef, useState } from 'react';

type Point = { latitude: number; longitude: number };

export default function useCheckoutGps(onPin: (point: Point) => void) {
    const [locating, setLocating] = useState(false);
    const [status, setStatus] = useState<string | null>(null);
    const generation = useRef(0);

    useEffect(() => () => { generation.current++; }, []);

    const cancel = () => {
        generation.current++;
        setLocating(false);
        setStatus(null);
    };
    const capture = () => {
        if (!navigator.geolocation) { setStatus('Geolocation is not supported by your browser.'); return; }
        const request = ++generation.current;
        setLocating(true);
        setStatus('Acquiring a location…');
        try {
            navigator.geolocation.getCurrentPosition(position => {
                if (request !== generation.current) return;
                setLocating(false);
                const { latitude, longitude, accuracy } = position.coords;
                if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || Math.abs(latitude) > 90 || Math.abs(longitude) > 180
                    || !Number.isFinite(accuracy) || accuracy < 0 || accuracy > 100 || !Number.isFinite(position.timestamp)
                    || Date.now() - position.timestamp > 30000 || position.timestamp > Date.now() + 1000) {
                    setStatus('A fresh, accurate location was not available. Place the pin on the map instead.'); return;
                }
                onPin({ latitude: Number(latitude.toFixed(6)), longitude: Number(longitude.toFixed(6)) });
                setStatus(`GPS location selected (accuracy ±${Math.round(accuracy)} m). Check the pin on the map.`);
            }, () => {
                if (request !== generation.current) return;
                setLocating(false);
                setStatus('Could not get your location. Place the pin on the map instead.');
            }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
        } catch (_) {
            if (request === generation.current) { setLocating(false); setStatus('Could not get your location. Place the pin on the map instead.'); }
        }
    };

    return { locating, status, capture, cancel };
}
