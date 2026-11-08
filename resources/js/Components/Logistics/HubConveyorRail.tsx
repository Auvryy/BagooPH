import React from 'react';
import {
    Truck,
    Scale,
    Layers,
    Boxes,
    Send,
    Store,
    Package,
    CheckCircle2,
    ChevronRight,
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

    return (
        <section
            aria-label="Hub Processing Pipeline"
            className="rounded-xl border border-zinc-200/90 bg-white p-3.5 shadow-xs font-sans"
        >
            {/* Compact Notion Workflow Header */}
            <div className="flex flex-wrap items-center justify-between gap-2 pb-2.5 mb-2.5 border-b border-zinc-100">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-6 w-6 items-center justify-center rounded-md bg-rose-50 border border-rose-200/80 text-[#E00D42]">
                        <Package className="h-3.5 w-3.5" />
                    </span>
                    <h2 className="text-xs font-bold uppercase tracking-wider text-zinc-800">
                        Hub Pipeline
                    </h2>
                    {trackingNumber && (
                        <span className="text-xs font-bold text-[#E00D42] bg-rose-50 px-2 py-0.5 rounded-md border border-rose-200">
                            {trackingNumber}
                        </span>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {isScanning ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#C20836] bg-rose-50 px-2.5 py-0.5 rounded-md border border-rose-200">
                            Resolving stage…
                        </span>
                    ) : hasScannedParcel ? (
                        <span className="inline-flex items-center gap-1.5 text-xs font-bold text-[#C20836] bg-rose-50 px-2.5 py-0.5 rounded-md border border-rose-200">
                            <ChevronRight className="w-3.5 h-3.5 text-[#E00D42]" />
                            <span>Station {HUB_STATIONS[activeIndex].number}: {HUB_STATIONS[activeIndex].title}</span>
                        </span>
                    ) : (
                        <span className="text-xs font-medium text-zinc-400">
                            Ready for scan
                        </span>
                    )}
                </div>
            </div>

            {/* 6-Stage Horizontal Pill Blocks */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2">
                {HUB_STATIONS.map((station, idx) => {
                    const Icon = station.icon;
                    const isStationActive = hasScannedParcel && idx === activeIndex;
                    const isStationCompleted = hasScannedParcel && idx < activeIndex;

                    return (
                        <button
                            key={station.id}
                            type="button"
                            onClick={() => onStationClick && onStationClick(station.id)}
                            className={`group relative flex items-center gap-2.5 rounded-lg border px-3 py-2 text-left transition focus:outline-none cursor-pointer ${
                                isStationActive
                                    ? 'border-[#E00D42] bg-rose-50 text-zinc-900 shadow-xs'
                                    : isStationCompleted
                                        ? 'border-rose-200/80 bg-rose-50/40 text-zinc-800 hover:border-rose-300'
                                        : 'border-zinc-200/80 bg-white text-zinc-600 hover:border-rose-200 hover:bg-rose-50/30'
                            }`}
                        >
                            <div
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md border transition ${
                                    isStationActive
                                        ? 'border-[#E00D42] bg-[#E00D42] text-white shadow-2xs'
                                        : isStationCompleted
                                            ? 'border-[#E00D42] bg-[#E00D42] text-white'
                                            : 'border-rose-200/70 bg-rose-50/60 text-[#E00D42] group-hover:bg-[#E00D42] group-hover:text-white'
                                }`}
                            >
                                {isStationCompleted ? (
                                    <CheckCircle2 className="h-3.5 w-3.5 text-white" />
                                ) : (
                                    <Icon className="h-3.5 w-3.5" />
                                )}
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center justify-between gap-1">
                                    <span className="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                                        Station {station.number}
                                    </span>
                                    {isStationActive && (
                                        <span className="text-[10px] font-bold uppercase text-[#E00D42]">
                                            Active
                                        </span>
                                    )}
                                </div>
                                <span
                                    className={`block truncate text-xs font-bold leading-snug ${
                                        isStationActive || isStationCompleted ? 'text-[#C20836]' : 'text-zinc-800'
                                    }`}
                                >
                                    {station.title}
                                </span>
                            </div>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}
