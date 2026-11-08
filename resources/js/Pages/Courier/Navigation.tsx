import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import type { Map as LeafletMap, LayerGroup as LeafletLayerGroup } from 'leaflet';
import {
    ArrowLeft,
    Compass,
    ExternalLink,
    Maximize2,
    Minimize2,
    Navigation as NavigationIcon,
    Package,
    Phone,
    RefreshCw,
    Store,
    Truck,
} from 'lucide-react';
import CourierLayout from '@/Layouts/CourierLayout';
import {
    useDynamicCourierNavigation,
    type DynamicNavStop,
} from '@/hooks/useDynamicCourierNavigation';
import { courierButton } from '@/Components/CourierUI';
import { courierPath, type CourierScope } from '@/utils/courier';
import type { RoadMode } from '@/utils/courierRoadRoute';

interface DeliveryItem {
    id: number | string;
    trackingNumber: string;
    orderNumber?: string | null;
    status: string;
    merchant?: {
        name: string;
        address: string;
        latitude?: number | null;
        longitude?: number | null;
        phone?: string | null;
    } | null;
    originHub?: {
        name: string;
        address?: string;
        latitude?: number | null;
        longitude?: number | null;
    } | null;
    recipient?: {
        name: string;
        address: string;
        latitude?: number | null;
        longitude?: number | null;
        phone?: string | null;
    } | null;
    payment?: {
        method?: string;
        codAmount?: number | null;
    } | null;
}

interface NavigationProps {
    scope?: CourierScope;
    isOnline?: boolean;
    queues: {
        pickupTasks: DeliveryItem[];
        finalMileTasks: DeliveryItem[];
    };
    cashSummary?: {
        held_cents: number;
        max_float_cents: number;
        is_locked: boolean;
        is_warning: boolean;
    };
}

export default function CourierNavigation({
    scope,
    isOnline = false,
    queues,
}: NavigationProps) {
    const [isFullscreen, setIsFullscreen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<LeafletMap | null>(null);
    const routeLayerRef = useRef<LeafletLayerGroup | null>(null);
    const mapContainerRef = useRef<HTMLDivElement>(null);

    // Flatten pickup tasks and final mile tasks into normalized stops
    const stops: DynamicNavStop[] = useMemo(() => {
        const list: DynamicNavStop[] = [];

        for (const item of queues?.pickupTasks || []) {
            if (item.merchant?.latitude && item.merchant?.longitude) {
                list.push({
                    id: `pickup-${item.id}`,
                    trackingNumber: item.trackingNumber,
                    orderNumber: item.orderNumber,
                    phase: 'pickup',
                    phaseLabel: 'Merchant Pickup',
                    targetName: item.merchant.name || 'Merchant Store',
                    targetAddress: item.merchant.address || 'Address not provided',
                    targetPhone: item.merchant.phone,
                    latitude: Number(item.merchant.latitude),
                    longitude: Number(item.merchant.longitude),
                });
            } else if (item.originHub?.latitude && item.originHub?.longitude) {
                list.push({
                    id: `hub-${item.id}`,
                    trackingNumber: item.trackingNumber,
                    orderNumber: item.orderNumber,
                    phase: 'hub',
                    phaseLabel: 'Bayan Hub Dropoff',
                    targetName: item.originHub.name || 'Bayan Logistics Hub',
                    targetAddress: item.originHub.address || 'Hub destination',
                    latitude: Number(item.originHub.latitude),
                    longitude: Number(item.originHub.longitude),
                });
            }
        }

        for (const item of queues?.finalMileTasks || []) {
            if (item.recipient?.latitude && item.recipient?.longitude) {
                list.push({
                    id: `delivery-${item.id}`,
                    trackingNumber: item.trackingNumber,
                    orderNumber: item.orderNumber,
                    phase: 'delivery',
                    phaseLabel: 'Doorstep Delivery',
                    targetName: item.recipient.name || 'Customer Recipient',
                    targetAddress: item.recipient.address || 'Doorstep destination',
                    targetPhone: item.recipient.phone,
                    latitude: Number(item.recipient.latitude),
                    longitude: Number(item.recipient.longitude),
                    codAmount: item.payment?.codAmount,
                });
            }
        }

        // Default demo / fallback stop if rider has no active tasks with coordinates
        if (list.length === 0) {
            list.push({
                id: 'demo-laguna-hub',
                trackingNumber: 'BGO-WAYBILL-DEMO',
                orderNumber: 'BGO-ORD-101',
                phase: 'delivery',
                phaseLabel: 'Sample Destination',
                targetName: 'Bayan Logistics Laguna Hub',
                targetAddress: 'National Highway, Calamba, Laguna',
                latitude: 14.2084,
                longitude: 121.1574,
            });
        }

        return list;
    }, [queues]);

    const {
        activeStop,
        selectedId,
        selectStop,
        currentPosition,
        heading,
        mode,
        setMode,
        routeDetails,
        routeLoading,
        statusMessage,
        recenterRoute,
    } = useDynamicCourierNavigation({
        stops,
    });

    // Toggle Fullscreen API
    const toggleFullscreen = async () => {
        try {
            if (!document.fullscreenElement) {
                if (containerRef.current) {
                    await containerRef.current.requestFullscreen();
                } else {
                    await document.documentElement.requestFullscreen();
                }
                setIsFullscreen(true);
            } else {
                await document.exitFullscreen();
                setIsFullscreen(false);
            }
        } catch {
            // Fullscreen fallback
        }
    };

    useEffect(() => {
        const handleFullscreenChange = () => {
            setIsFullscreen(Boolean(document.fullscreenElement));
            setTimeout(() => {
                mapRef.current?.invalidateSize({ animate: false });
            }, 200);
        };
        document.addEventListener('fullscreenchange', handleFullscreenChange);
        return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
    }, []);

    // Initialize Leaflet Map
    useEffect(() => {
        if (!mapContainerRef.current) return;
        let cancelled = false;

        void import('leaflet').then((L) => {
            if (cancelled || !mapContainerRef.current) return;

            const map = L.map(mapContainerRef.current, {
                preferCanvas: true,
                zoomControl: false,
                scrollWheelZoom: true,
                keyboard: true,
            });
            mapRef.current = map;
            map.attributionControl.setPrefix(false);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                keepBuffer: 2,
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a>',
            }).addTo(map);

            const initialCenter: [number, number] = activeStop
                ? [activeStop.latitude, activeStop.longitude]
                : [14.2084, 121.1574];
            map.setView(initialCenter, 14);

            routeLayerRef.current = L.layerGroup().addTo(map);
        });

        return () => {
            cancelled = true;
            mapRef.current?.remove();
            mapRef.current = null;
            routeLayerRef.current = null;
        };
    }, []);

    // Redraw Road Route & Markers
    useEffect(() => {
        const map = mapRef.current;
        const layer = routeLayerRef.current;
        if (!map || !layer) return;

        let cancelled = false;

        void import('leaflet').then((L) => {
            if (cancelled || !map || !routeLayerRef.current) return;
            layer.clearLayers();

            const allPoints: [number, number][] = [];

            // Draw Road Polylines
            if (routeDetails?.lines && routeDetails.lines.length > 0) {
                for (const line of routeDetails.lines) {
                    allPoints.push(...line);
                    // Outer white casing
                    L.polyline(line, {
                        color: '#FFFFFF',
                        weight: 8,
                        lineCap: 'round',
                        lineJoin: 'round',
                        interactive: false,
                    }).addTo(layer);
                    // Inner Bagoo Crimson
                    L.polyline(line, {
                        color: '#E00D42',
                        weight: 5,
                        lineCap: 'round',
                        lineJoin: 'round',
                        interactive: false,
                    }).addTo(layer);
                }
            }

            // Rider GPS Marker
            const riderPos: [number, number] = currentPosition || [14.1675, 121.2434];
            allPoints.push(riderPos);

            const riderHtml = `
                <div class="relative flex items-center justify-center">
                    <div style="background-color: #18181B; transform: rotate(${heading || 0}deg);" class="h-7 w-7 rounded-full border-2 border-white shadow-xl flex items-center justify-center transition-transform duration-300">
                        <svg class="h-4 w-4 text-[#E00D42]" viewBox="0 0 24 24" fill="currentColor">
                            <polygon points="12,2 22,22 12,17 2,22"/>
                        </svg>
                    </div>
                    <div class="absolute -inset-1.5 rounded-full bg-rose-500/25 animate-ping pointer-events-none"></div>
                </div>
            `;
            const riderMarker = L.marker(riderPos, {
                icon: L.divIcon({
                    className: 'rider-gps-icon',
                    html: riderHtml,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                }),
                zIndexOffset: 1000,
            }).addTo(layer);
            riderMarker.bindTooltip('Your Live Location (Rider)');

            // Destination Marker
            if (activeStop) {
                const destPos: [number, number] = [activeStop.latitude, activeStop.longitude];
                allPoints.push(destPos);

                const destHtml = `
                    <div class="flex items-center justify-center">
                        <div class="h-8 w-8 rounded-full bg-[#E00D42] border-2 border-white shadow-xl flex items-center justify-center text-white font-extrabold text-xs">
                            📍
                        </div>
                    </div>
                `;
                const destMarker = L.marker(destPos, {
                    icon: L.divIcon({
                        className: 'dest-stop-icon',
                        html: destHtml,
                        iconSize: [32, 32],
                        iconAnchor: [16, 16],
                    }),
                    zIndexOffset: 900,
                }).addTo(layer);
                destMarker.bindTooltip(activeStop.targetName);
            }

            // Fit bounds
            if (allPoints.length >= 2) {
                map.fitBounds(L.latLngBounds(allPoints), {
                    padding: [60, 60],
                    maxZoom: 16,
                    animate: true,
                });
            } else if (allPoints.length === 1) {
                map.setView(allPoints[0], 15, { animate: true });
            }
        });

        return () => {
            cancelled = true;
        };
    }, [routeDetails, currentPosition, activeStop, heading]);

    // External Google Maps directions URL
    const externalDirectionsUrl = activeStop
        ? `https://www.google.com/maps/dir/?api=1&destination=${activeStop.latitude},${activeStop.longitude}&travelmode=driving`
        : null;

    return (
        <CourierLayout
            title="Live Road Navigation"
            isOnline={isOnline}
            scope={scope}
            hidePageHeading={true}
        >
            <Head title="Live Road Navigation - Rider Workspace" />

            <div
                ref={containerRef}
                className={`relative flex flex-col bg-[#F5F5F7] ${
                    isFullscreen
                        ? 'fixed inset-0 z-[99999] h-screen w-screen overflow-hidden'
                        : 'h-[calc(100vh-140px)] min-h-[600px] w-full rounded-2xl border border-slate-200 overflow-hidden shadow-xs'
                }`}
            >
                {/* Minimal Slim Floating Top Bar (Clean & Non-Intrusive) */}
                <div className="absolute inset-x-0 top-0 z-[1000] p-2.5 sm:p-3 pointer-events-none">
                    <div className="mx-auto flex max-w-4xl items-center justify-between gap-2 pointer-events-auto">
                        <div className="flex items-center gap-2">
                            <Link
                                href={courierPath('/deliveries')}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg border border-slate-200/90 bg-white/95 px-3 text-xs font-bold text-slate-800 shadow-sm backdrop-blur-md hover:bg-slate-50 transition-colors"
                            >
                                <ArrowLeft className="h-4 w-4" />
                                <span>Exit</span>
                            </Link>

                            {activeStop && (
                                <div className="hidden sm:inline-flex items-center gap-2 rounded-lg border border-slate-200/90 bg-white/95 px-3 py-1.5 shadow-sm backdrop-blur-md">
                                    <span className="inline-flex items-center gap-1 rounded bg-[#E00D42] px-1.5 py-0.5 text-[10px] font-extrabold uppercase text-white">
                                        {activeStop.phase === 'pickup' ? (
                                            <Store className="h-3 w-3" />
                                        ) : activeStop.phase === 'hub' ? (
                                            <Truck className="h-3 w-3" />
                                        ) : (
                                            <Package className="h-3 w-3" />
                                        )}
                                        {activeStop.phaseLabel}
                                    </span>
                                    <span className="text-xs font-extrabold text-slate-900 truncate max-w-[200px]">
                                        {activeStop.targetName}
                                    </span>
                                </div>
                            )}
                        </div>

                        <div className="flex items-center gap-1.5">
                            <span className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50/90 px-2.5 py-1.5 text-[11px] font-extrabold text-emerald-800 shadow-sm backdrop-blur-md">
                                <span className="h-2 w-2 rounded-full bg-emerald-500 animate-pulse" />
                                <span className="hidden md:inline">Highways Prioritized</span>
                                <span className="md:hidden">Highway</span>
                            </span>

                            <button
                                type="button"
                                onClick={recenterRoute}
                                title="Recenter Route"
                                className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200/90 bg-white/95 text-slate-700 shadow-sm backdrop-blur-md hover:bg-slate-50 transition-colors"
                            >
                                <RefreshCw className="h-4 w-4 text-[#E00D42]" />
                            </button>

                            <button
                                type="button"
                                onClick={toggleFullscreen}
                                title={isFullscreen ? 'Exit Fullscreen' : 'Fullscreen Mount View'}
                                className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[#18181B] px-3 text-xs font-bold text-white shadow-sm hover:bg-zinc-800 transition-colors"
                            >
                                {isFullscreen ? (
                                    <>
                                        <Minimize2 className="h-3.5 w-3.5" />
                                        <span className="hidden sm:inline">Exit</span>
                                    </>
                                ) : (
                                    <>
                                        <Maximize2 className="h-3.5 w-3.5" />
                                        <span className="hidden sm:inline">Fullscreen</span>
                                    </>
                                )}
                            </button>
                        </div>
                    </div>
                </div>

                {/* Full Viewport Leaflet Map Surface */}
                <div
                    ref={mapContainerRef}
                    role="region"
                    aria-label="Full-screen dynamic road navigation map"
                    className="h-full w-full z-0 cursor-grab active:cursor-grabbing"
                />

                {/* Consolidated Bottom Ergonomic Cockpit & Navigation Control Dock */}
                <div className="absolute inset-x-0 bottom-0 z-[1000] p-2.5 sm:p-4 pointer-events-none">
                    <div className="mx-auto max-w-3xl pointer-events-auto rounded-2xl border border-slate-200/90 bg-white/95 p-3 sm:p-4 shadow-2xl backdrop-blur-md space-y-3">
                        {/* Target Stop Header & Distance/ETA Row */}
                        {activeStop ? (
                            <div>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="inline-flex items-center gap-1 rounded bg-[#E00D42] px-2 py-0.5 text-[10px] font-extrabold uppercase text-white tracking-wider">
                                                {activeStop.phase === 'pickup' ? (
                                                    <Store className="h-3 w-3" />
                                                ) : activeStop.phase === 'hub' ? (
                                                    <Truck className="h-3 w-3" />
                                                ) : (
                                                    <Package className="h-3 w-3" />
                                                )}
                                                {activeStop.phaseLabel}
                                            </span>
                                            <span className="font-extrabold text-xs text-[#C20836]">
                                                {activeStop.trackingNumber}
                                            </span>
                                        </div>
                                        <h2 className="mt-1 text-base sm:text-lg font-black text-slate-900 tracking-tight truncate">
                                            {activeStop.targetName}
                                        </h2>
                                        <p className="mt-0.5 text-xs text-slate-600 line-clamp-1">
                                            {activeStop.targetAddress}
                                        </p>
                                    </div>

                                    {/* Distance & ETA Metrics */}
                                    <div className="flex items-center gap-3 rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 shrink-0 shadow-inner">
                                        <div className="text-right">
                                            <span className="block text-[10px] font-bold text-slate-400 uppercase">
                                                Distance
                                            </span>
                                            <span className="text-sm sm:text-base font-black text-slate-900">
                                                {routeDetails?.distanceMeters !== undefined
                                                    ? `${(routeDetails.distanceMeters / 1000).toFixed(1)} km`
                                                    : 'Ready'}
                                            </span>
                                        </div>
                                        <div className="h-6 w-px bg-slate-200" />
                                        <div className="text-left">
                                            <span className="block text-[10px] font-bold text-slate-400 uppercase">
                                                Est. Drive
                                            </span>
                                            <span className="text-sm sm:text-base font-black text-[#E00D42]">
                                                {routeDetails?.durationMinutes !== undefined
                                                    ? `~${routeDetails.durationMinutes} min`
                                                    : 'Ready'}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                {/* Primary Action Row: Call, External Directions, Arrived & Scanner */}
                                <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5">
                                    <div className="flex items-center gap-2">
                                        {activeStop.targetPhone && (
                                            <a
                                                href={`tel:${activeStop.targetPhone}`}
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                            >
                                                <Phone className="h-3.5 w-3.5 text-emerald-600" />
                                                <span>Call Stop</span>
                                            </a>
                                        )}
                                        {externalDirectionsUrl && (
                                            <a
                                                href={externalDirectionsUrl}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 shadow-xs"
                                            >
                                                <ExternalLink className="h-3.5 w-3.5 text-sky-600" />
                                                <span>Google Maps</span>
                                            </a>
                                        )}
                                    </div>

                                    <Link
                                        href={courierPath('/deliveries')}
                                        className="inline-flex items-center gap-2 rounded-xl bg-[#E00D42] px-4 py-2.5 text-xs sm:text-sm font-black text-white hover:bg-[#C20836] shadow-md transition-colors"
                                    >
                                        <NavigationIcon className="h-4 w-4" />
                                        <span>Arrived at Stop • Open Scanner</span>
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <div className="text-center py-2 text-xs font-semibold text-slate-500">
                                No active stops assigned. Return to your Bayan hub to collect parcels.
                            </div>
                        )}

                        {/* Route Stops Selector & Vehicle Type */}
                        {stops.length > 1 && (
                            <div className="border-t border-slate-100 pt-2">
                                <div className="flex items-center justify-between gap-2 pb-1.5">
                                    <span className="text-[11px] font-extrabold text-slate-700 uppercase tracking-wide flex items-center gap-1">
                                        <Compass className="h-3.5 w-3.5 text-[#E00D42]" />
                                        Route Stops ({stops.length})
                                    </span>
                                    <div className="flex items-center gap-1.5">
                                        <span className="text-[10px] font-bold text-slate-500">Vehicle:</span>
                                        <select
                                            aria-label="Vehicle type"
                                            value={mode}
                                            onChange={(e) => setMode(e.target.value as RoadMode)}
                                            className="rounded-md border-slate-200 bg-slate-50 py-0.5 px-2 text-xs font-bold text-slate-800 focus:ring-[#E00D42]"
                                        >
                                            <option value="motorcycle">Motorcycle</option>
                                            <option value="scooter">Scooter</option>
                                            <option value="drive">Van / Car</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="flex gap-2 overflow-x-auto pb-1">
                                    {stops.map((stop) => {
                                        const isSelected = String(stop.id) === String(selectedId);
                                        return (
                                            <button
                                                key={stop.id}
                                                type="button"
                                                onClick={() => selectStop(stop.id)}
                                                className={`flex flex-col text-left shrink-0 w-56 rounded-xl border p-2 transition-all ${
                                                    isSelected
                                                        ? 'border-[#E00D42] bg-rose-50/70 shadow-xs ring-1 ring-[#E00D42]'
                                                        : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between gap-1 w-full">
                                                    <span
                                                        className={`text-[9px] font-extrabold uppercase px-1.5 py-0.5 rounded ${
                                                            isSelected
                                                                ? 'bg-[#E00D42] text-white'
                                                                : 'bg-slate-100 text-slate-700'
                                                        }`}
                                                    >
                                                        {stop.phaseLabel}
                                                    </span>
                                                    <span className="text-[10px] font-bold text-slate-500 tabular-nums truncate max-w-[90px]">
                                                        {stop.trackingNumber}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs font-bold text-slate-900 truncate w-full">
                                                    {stop.targetName}
                                                </p>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </CourierLayout>
    );
}
