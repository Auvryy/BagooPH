import { useEffect, useRef, useState } from 'react';
import { courierPath, type CourierMapJob } from '@/utils/courier';
import { fetchRoadRoute, roadPointDistance, validRoadPoint, type RoadMode, type RoadPoint } from '@/utils/courierRoadRoute';

export default function useCourierRoadRoute(job: CourierMapJob | undefined, destination: RoadPoint | null, apiKey: string, sampleMode: boolean) {
    const key = job && !job.preview && job.stopKind && !sampleMode && destination && validRoadPoint(destination)
        ? `${job.key}|${job.status ?? ''}|${job.stopKind}|${destination[0]}|${destination[1]}` : null;
    const [activeKey, setActiveKey] = useState<string | null>(null);
    const [mode, setMode] = useState<RoadMode | ''>('');
    const [message, setMessage] = useState('');
    const [result, setResult] = useState<{ key: string; origin: RoadPoint; lines: RoadPoint[][] } | null>(null);
    const stopTracking = useRef<(() => void) | null>(null);
    const active = key !== null && activeKey === key;
    const start = () => {
        if (!key) { setMessage('Select an assigned stop with a saved map pin.'); return; }
        if (!apiKey.trim()) { setMessage('In-app road routing is not configured. Use external directions for this stop.'); return; }
        if (!mode) { setMessage('Choose the vehicle you are using.'); return; }
        if (document.visibilityState !== 'visible') { setMessage('Open this page before starting navigation.'); return; }
        if (!navigator.geolocation || typeof navigator.geolocation.watchPosition !== 'function') { setMessage('Location is unavailable in this browser.'); return; }
        setResult(null); setMessage('Checking your current assignment…'); setActiveKey(key);
    };
    const stop = () => { stopTracking.current?.(); setActiveKey(null); setResult(null); setMessage('Navigation stopped.'); };

    useEffect(() => { setActiveKey(null); setResult(null); setMessage(''); }, [key]);

    useEffect(() => {
        if (!active || !job || !destination || !key || !mode) return;
        let cancelled = false, watch: number | null = null, lastFix = 0, lastRoute = 0, lastAttempt = 0, routing = false;
        let previous: RoadPoint | null = null, latestPoint: RoadPoint | null = null, routeAbort: AbortController | null = null, authAbort: AbortController | null = null;
        let checking: Promise<void> | null = null;
        let authTimer: ReturnType<typeof setInterval> | null = null, fixTimer: ReturnType<typeof setInterval> | null = null;
        const cleanup = () => {
            if (cancelled) return;
            cancelled = true;
            if (authTimer !== null) clearInterval(authTimer);
            if (fixTimer !== null) clearInterval(fixTimer);
            if (watch !== null) { navigator.geolocation.clearWatch(watch); watch = null; }
            authAbort?.abort(); routeAbort?.abort();
            document.removeEventListener('visibilitychange', hidden); window.removeEventListener('offline', offline);
        };
        const fail = (text: string) => {
            if (cancelled) return;
            cleanup();
            setResult(null); setMessage(text); setActiveKey(null);
        };
        const verify = () => {
            if (checking) return checking;
            authAbort = new AbortController();
            const authSignal = authAbort.signal;
            const timer = setTimeout(() => authAbort?.abort(), 10000);
            checking = (async () => {
                try {
                    const response = await fetch(courierPath(`/navigation/${encodeURIComponent(job.key)}`), { signal: authSignal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                    if (!response.ok || response.redirected) throw new Error('Assignment unavailable.');
                    const payload = await response.json();
                    const stop = payload?.data;
                    if (stop?.task_id !== job.key || stop.kind !== job.stopKind || stop.latitude !== destination[0] || stop.longitude !== destination[1]) throw new Error('Stop changed.');
                } finally { clearTimeout(timer); checking = null; }
            })();
            return checking;
        };
        const refresh = async (position: GeolocationPosition) => {
            if (cancelled) return;
            const now = Date.now(), point: RoadPoint = [position.coords.latitude, position.coords.longitude];
            if (!validRoadPoint(point) || !Number.isFinite(position.coords.accuracy) || position.coords.accuracy > 50 || position.coords.accuracy < 0
                || !Number.isFinite(position.timestamp) || now - position.timestamp > 30000 || position.timestamp > now + 1000) {
                routeAbort?.abort(); previous = null; latestPoint = null; lastRoute = 0;
                setResult(null); setMessage('Waiting for a fresh, accurate location before calculating a route.'); return;
            }
            lastFix = now; latestPoint = point;
            if (previous && roadPointDistance(previous, point) >= 50) {
                previous = null; lastRoute = 0; setResult(null); setMessage('Location changed. Updating the road route…');
            }
            if (routing || now - lastAttempt < 30000 || (previous && roadPointDistance(previous, point) < 50 && now - lastRoute < 120000)) return;
            routing = true; lastAttempt = now; setResult(null); setMessage('Calculating the road route…');
            try {
                try { await verify(); } catch (_) { fail('Your assignment or stop changed. Refresh before navigating.'); return; }
                if (cancelled) return;
                if (!latestPoint || roadPointDistance(point, latestPoint) >= 50) { setMessage('Location changed. Waiting to update the road route…'); return; }
                routeAbort?.abort(); routeAbort = new AbortController();
                const controller = routeAbort;
                const timer = setTimeout(() => {
                    controller.abort();
                    if (!cancelled) { setResult(null); setMessage('Road route timed out. Check your connection or use external directions.'); }
                }, 15000);
                try {
                    const lines = await fetchRoadRoute(point, destination, mode, apiKey, controller.signal);
                    if (!cancelled && !controller.signal.aborted && latestPoint && roadPointDistance(point, latestPoint) < 50) { previous = point; lastRoute = Date.now(); setResult({ key, origin: point, lines }); setMessage('Road route ready.'); }
                    else if (!cancelled && !controller.signal.aborted) setMessage('Location changed. Waiting to update the road route…');
                } finally { clearTimeout(timer); }
            } catch (_) {
                if (!cancelled && !routeAbort?.signal.aborted) { setResult(null); setMessage('Road route unavailable. Check your connection or use external directions.'); }
            } finally { routing = false; }
        };
        const hidden = () => { if (document.visibilityState !== 'visible') fail('Navigation paused while this page is hidden.'); };
        const offline = () => fail('Navigation paused because the connection was lost.');
        document.addEventListener('visibilitychange', hidden);
        window.addEventListener('offline', offline);
        authTimer = setInterval(() => { void verify().catch(() => fail('Your assignment or stop changed. Refresh before navigating.')); }, 25000);
        fixTimer = setInterval(() => { if (lastFix && Date.now() - lastFix > 30000) { routeAbort?.abort(); previous = null; latestPoint = null; lastRoute = 0; setResult(null); setMessage('Waiting for a fresh, accurate location before calculating a route.'); } }, 5000);
        stopTracking.current = cleanup;
        void verify().then(() => {
            if (cancelled) return;
            setMessage('Waiting for your current location…');
            watch = navigator.geolocation.watchPosition(position => { void refresh(position); }, () => fail('Location is unavailable or permission was denied.'), { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 });
        }).catch(() => fail('Your assignment or stop is unavailable. Refresh before navigating.'));
        return () => { cleanup(); if (stopTracking.current === cleanup) stopTracking.current = null; };
    }, [active, key, job?.key, destination?.[0], destination?.[1], apiKey, mode]);

    return { active, mode, setMode, message, lines: active && result?.key === key ? result.lines : null, origin: active && result?.key === key ? result.origin : null, start, stop, configured: Boolean(apiKey.trim()) };
}
