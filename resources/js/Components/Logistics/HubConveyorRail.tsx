import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    Truck,
    Scale,
    Layers,
    Boxes,
    Send,
    Store,
    CheckCircle2,
    ArrowRight,
    ExternalLink,
    Info,
    Sparkles,
} from 'lucide-react';

export interface HubConveyorStation {
    id: 'inbound' | 'dws' | 'sorting' | 'bagging' | 'dispatch' | 'counter';
    number: number;
    title: string;
    role: string;
    sop: string;
    actionLabel?: string;
    actionHref?: string;
    icon: React.ElementType;
}

export const HUB_STATIONS: HubConveyorStation[] = [
    {
        id: 'inbound',
        number: 1,
        title: 'Inbound Dock',
        role: 'Unloader / Gatekeeper',
        sop: 'Cut vehicle door seals, verify manifest, and scan incoming mother bags or loose parcels into hub inventory.',
        actionLabel: 'View Inbound Queue',
        icon: Truck,
    },
    {
        id: 'dws',
        number: 2,
        title: 'DWS Station',
        role: 'Dimension & Weight Operator',
        sop: 'Audit physical weight and volumetric dimensions ((L×W×H)/3500) against seller declarations to prevent tariff fraud.',
        actionLabel: 'Use DWS Tool Below',
        icon: Scale,
    },
    {
        id: 'sorting',
        number: 3,
        title: 'Sorting Matrix',
        role: 'Zone / Barangay Sorter',
        sop: 'Route parcels into destination pigeonhole cubbies according to municipal barangay or child hub delivery zones.',
        icon: Layers,
    },
    {
        id: 'bagging',
        number: 4,
        title: 'Consolidation',
        role: 'Mother Bagger & Seal Operator',
        sop: 'Pack consolidated zone parcels into heavy-duty Mother Bags and lock with serialized tamper-evident pull-tight seals.',
        actionLabel: 'Manage Mother Bags',
        actionHref: '/hub/mother-bags',
        icon: Boxes,
    },
    {
        id: 'dispatch',
        number: 5,
        title: 'Outbound Bay',
        role: 'Linehaul & Courier Dispatch',
        sop: 'Generate electronic transport manifest, scan sealed bags onto feeder trucks or last-mile delivery riders.',
        actionLabel: 'Open Manifests',
        actionHref: '/hub/manifests',
        icon: Send,
    },
    {
        id: 'counter',
        number: 6,
        title: 'Front Desk / Handover',
        role: 'PUDO Cashier / Courier Drop',
        sop: 'Release parcels to walk-in buyers using 6-digit claim PINs or complete doorstep POD with GPS verification.',
        actionLabel: 'Counter Pickup Queue',
        actionHref: '/hub/counter-pickup',
        icon: Store,
    },
];

interface HubConveyorRailProps {
    currentStatus?: string;
    deliveryType?: string;
    basePath?: string;
    isScanning?: boolean;
    onStationClick?: (stationId: string) => void;
}

export default function HubConveyorRail({
    currentStatus = '',
    deliveryType = 'standard',
    basePath = '/hub',
    isScanning = false,
    onStationClick,
}: HubConveyorRailProps) {
    // Determine the active station index (0 to 5) based on parcel delivery status
    const getActiveStationIndex = (status: string, type: string): number => {
        const s = status.toLowerCase();
        if (s.includes('delivered') || s.includes('picked_up') || s.includes('completed')) {
            return 5; // Handover complete
        }
        if (s.includes('out_for_delivery') || s.includes('ready_for_pickup') || s.includes('dispatched')) {
            return type === 'counter_pickup' ? 5 : 4; // Outbound dispatch or counter
        }
        if (s.includes('in_transit') || s.includes('mother_bag') || s.includes('manifested')) {
            return 3; // Bagging / Linehaul
        }
        if (s.includes('sorting') || s.includes('sorted')) {
            return 2; // Sorting matrix
        }
        if (s.includes('weighed') || s.includes('dimension') || s.includes('dws')) {
            return 1; // DWS inspection
        }
        if (s.includes('received_at_hub') || s.includes('intake') || s.includes('picked_up_from_seller')) {
            return 0; // Inbound dock
        }
        return 0; // Default to inbound dock
    };

    const activeIndex = getActiveStationIndex(currentStatus, deliveryType);
    const [selectedStation, setSelectedStation] = useState<HubConveyorStation>(
        HUB_STATIONS[activeIndex] || HUB_STATIONS[0]
    );

    // Sync selected station with scanned parcel movement
    React.useEffect(() => {
        if (currentStatus) {
            setSelectedStation(HUB_STATIONS[activeIndex]);
        }
    }, [currentStatus, activeIndex]);

    return (
        <section
            aria-label="Interactive Hub Conveyor Rail"
            className="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:p-5 transition-all"
        >
            {/* Header & Status Indicator */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-50 text-[#E00D42] border border-rose-100">
                        <Sparkles className="h-4 w-4" />
                    </span>
                    <div>
                        <h2 className="text-xs font-bold uppercase tracking-wider text-slate-900">
                            Hub Conveyor Station Pipeline
                        </h2>
                        <p className="text-[11px] text-slate-500">
                            Interactive visual rail of the 6 specialized operational stations
                        </p>
                    </div>
                </div>

                {currentStatus && (
                    <div className="flex items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-[#E00D42]">
                        <span className="h-2 w-2 rounded-full bg-[#E00D42] animate-pulse" />
                        <span>Active at: {HUB_STATIONS[activeIndex]?.title}</span>
                    </div>
                )}
            </div>

            {/* Conveyor Rail Line with Moving Parcel */}
            <div className="relative mt-5 pt-2 pb-3">
                {/* Visual Steel Track / Conveyor Belt */}
                <div className="relative h-2.5 w-full rounded-full bg-slate-100 border border-slate-200 overflow-hidden">
                    {/* Conveyor Rollers pattern */}
                    <div className="absolute inset-0 bg-[linear-gradient(90deg,transparent_50%,rgba(203,213,225,0.4)_50%)] bg-[length:12px_100%] animate-[moveRight_1.5s_linear_infinite]" />
                    {/* Filled Progress Line */}
                    <div
                        className="h-full bg-[#E00D42] transition-all duration-700 ease-out"
                        style={{ width: `${(activeIndex / (HUB_STATIONS.length - 1)) * 100}%` }}
                    />
                </div>

                {/* 6 Interactive Stations along the Rail */}
                <div className="relative -mt-4.5 flex justify-between">
                    {HUB_STATIONS.map((station, idx) => {
                        const Icon = station.icon;
                        const isPast = idx < activeIndex;
                        const isCurrent = idx === activeIndex;
                        const isTargetSelected = selectedStation.id === station.id;

                        return (
                            <button
                                key={station.id}
                                type="button"
                                onClick={() => {
                                    setSelectedStation(station);
                                    if (onStationClick) onStationClick(station.id);
                                }}
                                className="group relative flex flex-col items-center focus:outline-none"
                            >
                                {/* Station Node Badge */}
                                <div
                                    className={`relative z-10 flex h-9 w-9 items-center justify-center rounded-xl border transition-all duration-300 shadow-xs cursor-pointer ${
                                        isCurrent
                                            ? 'border-[#E00D42] bg-[#E00D42] text-white ring-4 ring-rose-100 scale-110'
                                            : isPast
                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-700 hover:border-emerald-600'
                                            : isTargetSelected
                                            ? 'border-slate-400 bg-slate-100 text-slate-800 ring-2 ring-slate-200'
                                            : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300 hover:text-slate-800'
                                    }`}
                                >
                                    {isPast ? (
                                        <CheckCircle2 className="h-4 w-4 text-emerald-600" />
                                    ) : (
                                        <Icon className="h-4 w-4" />
                                    )}

                                    {/* Moving Parcel Indicator on Active Node */}
                                    {isCurrent && (
                                        <span className="absolute -top-2 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-white border border-[#E00D42] shadow-xs">
                                            <span className="h-2 w-2 rounded-full bg-[#E00D42] animate-ping" />
                                        </span>
                                    )}
                                </div>

                                {/* Station Label */}
                                <div className="mt-2 text-center">
                                    <span
                                        className={`block text-[11px] font-bold leading-tight transition-colors ${
                                            isCurrent
                                                ? 'text-[#E00D42]'
                                                : isTargetSelected
                                                ? 'text-slate-900'
                                                : 'text-slate-600'
                                        }`}
                                    >
                                        {station.title}
                                    </span>
                                    <span className="hidden sm:block text-[10px] text-slate-400">
                                        Station {station.number}
                                    </span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Selected Station Operational Card */}
            <div className="mt-4 rounded-lg border border-slate-200 bg-[#F8F9FA] p-3.5 sm:p-4 text-xs font-sans">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 pb-2.5">
                    <div className="flex items-center gap-2">
                        <span className="font-bold text-slate-900">
                            Station {selectedStation.number}: {selectedStation.title}
                        </span>
                        <span className="rounded-md border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">
                            Role: {selectedStation.role}
                        </span>
                    </div>

                    {selectedStation.actionHref && (
                        <Link
                            href={selectedStation.actionHref}
                            className="inline-flex items-center gap-1 font-semibold text-[#E00D42] hover:text-[#C20836]"
                        >
                            <span>{selectedStation.actionLabel || 'Go to station'}</span>
                            <ArrowRight className="h-3 w-3" />
                        </Link>
                    )}
                </div>

                <div className="mt-2 flex items-start gap-2 text-slate-600 leading-relaxed">
                    <Info className="h-3.5 w-3.5 text-slate-400 shrink-0 mt-0.5" />
                    <p className="text-[11px]">{selectedStation.sop}</p>
                </div>
            </div>
        </section>
    );
}
