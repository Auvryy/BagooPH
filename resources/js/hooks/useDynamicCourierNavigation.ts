import { useCallback, useEffect, useRef, useState } from 'react';
import {
    fetchDetailedRoadRoute,
    roadPointDistance,
    validRoadPoint,
    type DetailedRoadRoute,
    type RoadMode,
    type RoadPoint,
} from '@/utils/courierRoadRoute';

export interface DynamicNavStop {
    id: number | string;
    trackingNumber: string;
    orderNumber?: string | null;
    phase: 'pickup' | 'hub' | 'delivery';
    phaseLabel: string;
    targetName: string;
    targetAddress: string;
    targetPhone?: string | null;
    latitude: number;
    longitude: number;
    codAmount?: number | null;
}

export interface UseDynamicCourierNavigationOptions {
    stops: DynamicNavStop[];
    selectedStopId?: string | number | null;
    fallbackOrigin?: RoadPoint | null;
    apiKey?: string;
    defaultMode?: RoadMode;
}

export function useDynamicCourierNavigation({
    stops,
    selectedStopId: initialStopId,
    fallbackOrigin = null,
    apiKey = import.meta.env?.VITE_GEOAPIFY_API_KEY ?? '',
    defaultMode = 'motorcycle',
}: UseDynamicCourierNavigationOptions) {
    const [selectedId, setSelectedId] = useState<string | number | null>(() => {
        if (initialStopId !== undefined && initialStopId !== null) return initialStopId;
        return stops[0]?.id ?? null;
    });

    const [mode, setMode] = useState<RoadMode>(defaultMode);
    const [currentPosition, setCurrentPosition] = useState<RoadPoint | null>(null);
    const [heading, setHeading] = useState<number | null>(null);
    const [speed, setSpeed] = useState<number | null>(null);
    const [accuracy, setAccuracy] = useState<number | null>(null);
    const [statusMessage, setStatusMessage] = useState<string>('Acquiring rider GPS location…');
    const [isNavigating, setIsNavigating] = useState(false);
    const [routeDetails, setRouteDetails] = useState<DetailedRoadRoute | null>(null);
    const [routeLoading, setRouteLoading] = useState(false);

    const activeStop = stops.find((s) => String(s.id) === String(selectedId)) || stops[0] || null;
    const destination: RoadPoint | null = activeStop ? [activeStop.latitude, activeStop.longitude] : null;

    const lastRoutedPosition = useRef<RoadPoint | null>(null);
    const routeAbortController = useRef<AbortController | null>(null);
    const watchIdRef = useRef<number | null>(null);

    // Dynamic Route Calculation
    const calculateRoute = useCallback(
        async (origin: RoadPoint, dest: RoadPoint) => {
            if (!validRoadPoint(origin) || !validRoadPoint(dest)) return;
            routeAbortController.current?.abort();
            routeAbortController.current = new AbortController();
            const signal = routeAbortController.current.signal;

            setRouteLoading(true);
            setStatusMessage('Calculating highway-optimized route…');

            try {
                if (apiKey.trim()) {
                    const result = await fetchDetailedRoadRoute(origin, dest, mode, apiKey, signal);
                    if (!signal.aborted) {
                        setRouteDetails(result);
                        lastRoutedPosition.current = origin;
                        setStatusMessage('Live highway navigation active.');
                    }
                } else {
                    // Fallback straight / bezier approximation when API key is unconfigured
                    const straightDistance = Math.round(roadPointDistance(origin, dest));
                    // 35 km/h estimate
                    const estMinutes = Math.max(1, Math.round((straightDistance / 1000) / 35 * 60));
                    setRouteDetails({
                        lines: [[origin, dest]],
                        distanceMeters: straightDistance,
                        durationMinutes: estMinutes,
                        highwayPrioritized: false,
                    });
                    lastRoutedPosition.current = origin;
                    setStatusMessage('Direct route line (Configure VITE_GEOAPIFY_API_KEY for turn-by-turn road geometry).');
                }
            } catch (err: unknown) {
                if (!signal.aborted) {
                    const straightDistance = Math.round(roadPointDistance(origin, dest));
                    const estMinutes = Math.max(1, Math.round((straightDistance / 1000) / 35 * 60));
                    setRouteDetails({
                        lines: [[origin, dest]],
                        distanceMeters: straightDistance,
                        durationMinutes: estMinutes,
                        highwayPrioritized: false,
                    });
                    setStatusMessage('Offline direct route active.');
                }
            } finally {
                setRouteLoading(false);
            }
        },
        [apiKey, mode]
    );

    const validFallbackOrigin: RoadPoint | null =
        fallbackOrigin && validRoadPoint(fallbackOrigin) ? fallbackOrigin : null;

    // Route from assigned hub fallback until live GPS arrives
    useEffect(() => {
        if (!currentPosition && destination && validFallbackOrigin) {
            void calculateRoute(validFallbackOrigin, destination);
        }
    }, [currentPosition, destination?.[0], destination?.[1], validFallbackOrigin?.[0], validFallbackOrigin?.[1], calculateRoute]);

    // Watch Geolocation
    useEffect(() => {
        if (!navigator.geolocation) {
            setStatusMessage(
                validFallbackOrigin
                    ? 'Browser GPS unavailable; routing from assigned hub.'
                    : 'Geolocation unavailable in this browser.'
            );
            return;
        }

        const handleSuccess = (pos: GeolocationPosition) => {
            const point: RoadPoint = [pos.coords.latitude, pos.coords.longitude];
            if (!validRoadPoint(point)) return;

            setCurrentPosition(point);
            setHeading(pos.coords.heading ?? null);
            setSpeed(pos.coords.speed ?? null);
            setAccuracy(pos.coords.accuracy ?? null);

            // If we have an active destination, check if we need to route or reroute
            if (destination) {
                const prev = lastRoutedPosition.current;
                if (!prev || roadPointDistance(prev, point) >= 60) {
                    void calculateRoute(point, destination);
                }
            }
        };

        const handleError = () => {
            setStatusMessage(
                validFallbackOrigin
                    ? 'GPS unavailable; showing route from your assigned hub.'
                    : 'Waiting for GPS permission or high-accuracy signal…'
            );
        };

        watchIdRef.current = navigator.geolocation.watchPosition(handleSuccess, handleError, {
            enableHighAccuracy: true,
            maximumAge: 1000,
            timeout: 10000,
        });

        return () => {
            if (watchIdRef.current !== null) {
                navigator.geolocation.clearWatch(watchIdRef.current);
                watchIdRef.current = null;
            }
            routeAbortController.current?.abort();
        };
    }, [destination, calculateRoute, validFallbackOrigin]);

    // Recalculate when selected stop changes
    const selectStop = (id: string | number) => {
        setSelectedId(id);
        const target = stops.find((s) => String(s.id) === String(id));
        const originPoint = currentPosition || validFallbackOrigin;
        if (target && originPoint) {
            void calculateRoute(originPoint, [target.latitude, target.longitude]);
        }
    };

    const recenterRoute = () => {
        const originPoint = currentPosition || validFallbackOrigin;
        if (originPoint && destination) {
            void calculateRoute(originPoint, destination);
        }
    };

    return {
        stops,
        activeStop,
        selectedId: activeStop?.id ?? null,
        selectStop,
        currentPosition,
        heading,
        speed,
        accuracy,
        mode,
        setMode,
        routeDetails,
        routeLoading,
        statusMessage,
        recenterRoute,
        isNavigating,
        setIsNavigating,
    };
}
