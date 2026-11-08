import React, { useState, useEffect } from 'react';
import {
    Truck,
    Scale,
    Layers,
    Boxes,
    Send,
    Store,
    Package,
} from 'lucide-react';

export interface HubConveyorStation {
    id: 'inbound' | 'dws' | 'sorting' | 'bagging' | 'dispatch' | 'counter';
    number: number;
    title: string;
    icon: React.ElementType;
}

export const HUB_STATIONS: HubConveyorStation[] = [
    {
        id: 'inbound',
        number: 1,
        title: 'Inbound',
        icon: Truck,
    },
    {
        id: 'dws',
        number: 2,
        title: 'DWS Audit',
        icon: Scale,
    },
    {
        id: 'sorting',
        number: 3,
        title: 'Sorting',
        icon: Layers,
    },
    {
        id: 'bagging',
        number: 4,
        title: 'Consolidation',
        icon: Boxes,
    },
    {
        id: 'dispatch',
        number: 5,
        title: 'Outbound Bay',
        icon: Send,
    },
    {
        id: 'counter',
        number: 6,
        title: 'Handover / POD',
        icon: Store,
    },
];

interface HubConveyorRailProps {
    currentStatus?: string;
    deliveryType?: string;
    basePath?: string;
    isScanning?: boolean;
    trackingNumber?: string;
    onStationClick?: (stationId: string) => void;
}

export default function HubConveyorRail({
    currentStatus = '',
    deliveryType = 'standard',
    isScanning = false,
    trackingNumber,
    onStationClick,
}: HubConveyorRailProps) {
    // Determine active station index (0 to 5)
    const getActiveStationIndex = (status: string, type: string): number => {
        const s = status.toLowerCase();
        if (!s) return 0;

        if (
            s.includes('customer_collected') ||
            s.includes('delivered') ||
            s.includes('completed') ||
            (type === 'counter_pickup' && s.includes('ready_for_hub_pickup'))
        ) {
            return 5;
        }

        if (
            s.includes('out_for_delivery') ||
            s.includes('assigned_to_rider') ||
            s.includes('in_transit_to_dest') ||
            s.includes('arrived_at_destination_hub') ||
            s.includes('dispatched')
        ) {
            return 4;
        }

        if (
            s.includes('in_transit_to_mother_hub') ||
            s.includes('arrived_at_mother_hub') ||
            s.includes('mother_bag') ||
            s.includes('manifested')
        ) {
            return 3;
        }

        if (
            s.includes('sorted_to_line_haul') ||
            s.includes('sorted_to_barangay_bin') ||
            s.includes('sorting') ||
            s.includes('sorted')
        ) {
            return 2;
        }

        if (
            s.includes('arrived_at_origin_hub') ||
            s.includes('weighed') ||
            s.includes('dimension') ||
            s.includes('dws')
        ) {
            return 1;
        }

        return 0;
    };

    const hasScannedParcel = Boolean(currentStatus);
    const activeIndex = getActiveStationIndex(currentStatus, deliveryType);

    // Track position for low-FPS discrete motion
    const [boxIndex, setBoxIndex] = useState(activeIndex);

    useEffect(() => {
        if (hasScannedParcel) {
            setBoxIndex(activeIndex);
        }
    }, [hasScannedParcel, activeIndex]);

    return (
        <section
            aria-label="Hub Processing Pipeline"
            className="mb-5 rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-xs"
        >
            {/* Header: Clean & Essential Information Only */}
            <div className="flex items-center justify-between border-b border-slate-100 pb-3 mb-4">
                <div className="flex items-center gap-2">
                    <h2 className="text-xs font-bold uppercase tracking-wider text-slate-800 font-sans">
                        Hub Pipeline
                    </h2>
                    {trackingNumber && (
                        <span className="text-xs font-mono font-bold text-[#E00D42] bg-rose-50 px-2.5 py-0.5 rounded border border-rose-200">
                            {trackingNumber}
                        </span>
                    )}
                </div>

                <div>
                    {hasScannedParcel ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-700 bg-slate-100 px-2.5 py-1 rounded border border-slate-200">
                            <span className="w-2 h-2 rounded-full bg-[#E00D42]" />
                            Station {HUB_STATIONS[activeIndex].number}: {HUB_STATIONS[activeIndex].title}
                        </span>
                    ) : (
                        <span className="text-xs text-slate-400 font-medium">
                            Ready for scan
                        </span>
                    )}
                </div>
            </div>

            {/* Pipeline Stage Track: Flat Nodes with Dashed Broken Line */}
            <div className="relative pt-2 pb-1">
                {/* Dashed Broken Line connecting the exact centers of all 6 columns */}
                <div
                    className="absolute top-7 border-t-2 border-dashed border-slate-300 pointer-events-none z-0"
                    style={{
                        left: 'calc(100% / 12)',
                        right: 'calc(100% / 12)',
                    }}
                />

                {/* Light Low-FPS Box Icon stepping between nodes on scan */}
                {hasScannedParcel && (
                    <div
                        className="absolute top-3 -translate-x-1/2 z-20 pointer-events-none"
                        style={{
                            left: `${((boxIndex + 0.5) / 6) * 100}%`,
                            transition: 'left 0.45s steps(5, end)',
                        }}
                    >
                        <div className="flex items-center justify-center w-8 h-8 rounded-lg bg-white border-2 border-slate-800 text-slate-900 shadow-sm">
                            <Package className="w-4 h-4 text-[#E00D42]" />
                        </div>
                    </div>
                )}

                {/* 6 Flat Station Nodes */}
                <div className="grid grid-cols-6 relative z-10">
                    {HUB_STATIONS.map((station, idx) => {
                        const Icon = station.icon;
                        const isStationActive = hasScannedParcel && idx === activeIndex;

                        return (
                            <button
                                key={station.id}
                                type="button"
                                onClick={() => onStationClick && onStationClick(station.id)}
                                className="flex flex-col items-center text-center group focus:outline-none"
                            >
                                {/* Node Badge */}
                                <div
                                    className={`w-10 h-10 rounded-lg border flex items-center justify-center transition ${
                                        isStationActive
                                            ? 'border-[#E00D42] bg-[#E00D42] text-white shadow-xs'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400 hover:text-slate-900 shadow-2xs'
                                    }`}
                                >
                                    <Icon className="w-4 h-4" />
                                </div>

                                {/* Node Title */}
                                <span
                                    className={`mt-2 block text-xs font-bold leading-tight ${
                                        isStationActive ? 'text-[#E00D42]' : 'text-slate-700'
                                    }`}
                                >
                                    {station.title}
                                </span>
                                <span className="text-xs text-slate-400 font-medium">
                                    Station {station.number}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}
