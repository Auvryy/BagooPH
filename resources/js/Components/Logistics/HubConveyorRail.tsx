import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import {
    Truck,
    Scale,
    Layers,
    Boxes,
    Send,
    Store,
    ArrowRight,
    Info,
    Sparkles,
    Package,
    Activity,
    Check,
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
    trackingNumber?: string;
    onStationClick?: (stationId: string) => void;
}

export default function HubConveyorRail({
    currentStatus = '',
    deliveryType = 'standard',
    basePath = '/hub',
    isScanning = false,
    trackingNumber,
    onStationClick,
}: HubConveyorRailProps) {
    // Determine the active station index (0 to 5) based on parcel delivery status
    const getActiveStationIndex = (status: string, type: string): number => {
        const s = status.toLowerCase();
        if (!s) return 0;

        // 1. Station 6: Front Desk / Handover (completed, customer collected, or ready at counter)
        if (
            s.includes('customer_collected') ||
            s.includes('delivered') ||
            s.includes('completed') ||
            (type === 'counter_pickup' && s.includes('ready_for_hub_pickup'))
        ) {
            return 5;
        }

        // 2. Station 5: Outbound Bay (doorstep out for delivery, assigned to delivery rider, or linehaul dispatch)
        if (
            s.includes('out_for_delivery') ||
            s.includes('assigned_to_rider') ||
            s.includes('in_transit_to_dest') ||
            s.includes('arrived_at_destination_hub') ||
            s.includes('dispatched')
        ) {
            return 4;
        }

        // 3. Station 4: Consolidation (mother hub transfer / mother bagging)
        if (
            s.includes('in_transit_to_mother_hub') ||
            s.includes('arrived_at_mother_hub') ||
            s.includes('mother_bag') ||
            s.includes('manifested')
        ) {
            return 3;
        }

        // 4. Station 3: Sorting Matrix (sorting to line haul cages or barangay pigeonholes)
        if (
            s.includes('sorted_to_line_haul') ||
            s.includes('sorted_to_barangay_bin') ||
            s.includes('sorting') ||
            s.includes('sorted')
        ) {
            return 2;
        }

        // 5. Station 2: DWS Station (parcel received at origin hub, performing weight/dimension audit)
        if (
            s.includes('arrived_at_origin_hub') ||
            s.includes('weighed') ||
            s.includes('dimension') ||
            s.includes('dws')
        ) {
            return 1;
        }

        // 6. Station 1: Inbound Dock (first-mile rider collected from seller, awaiting physical intake at hub gate)
        if (
            s.includes('picked_up') ||
            s.includes('ready_for_pickup') ||
            s.includes('received_at_hub') ||
            s.includes('intake') ||
            s.includes('picked_up_from_seller')
        ) {
            return 0;
        }

        return 0; // Default to inbound dock
    };

    const hasScannedParcel = Boolean(currentStatus);
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

    // Multiple continuous parcels on the conveyor belt (continuous staggered flow)
    const conveyorParcels = [
        { id: 'p1', delay: '0s', label: 'Parcel #4829', style: 'bg-amber-100 border-amber-300 text-amber-900' },
        { id: 'p2', delay: '-2.8s', label: 'Express #1094', style: 'bg-white border-rose-200 text-[#E00D42]' },
        { id: 'p3', delay: '-5.6s', label: 'Tote #8210', style: 'bg-amber-50 border-amber-200 text-amber-800' },
        { id: 'p4', delay: '-8.4s', label: 'Carton #5521', style: 'bg-amber-200 border-amber-400 text-amber-950' },
        { id: 'p5', delay: '-11.2s', label: 'Bag #3301', style: 'bg-slate-100 border-slate-300 text-slate-800' },
    ];

    return (
        <section
            aria-label="Interactive Hub Conveyor Automation Line"
            className="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white p-4 shadow-xs sm:p-5 transition-all"
        >
            <style>{`
                @keyframes conveyorBeltRoll {
                    0% { background-position: 0 0; }
                    100% { background-position: 24px 0; }
                }
                @keyframes parcelConveyorTravel {
                    0% { left: -3%; opacity: 0; transform: translateY(-50%) scale(0.85); }
                    4% { opacity: 1; transform: translateY(-50%) scale(1); }
                    96% { opacity: 1; transform: translateY(-50%) scale(1); }
                    100% { left: 101%; opacity: 0; transform: translateY(-50%) scale(0.85); }
                }
                @keyframes scannerLaser {
                    0%, 100% { top: 0%; opacity: 0.8; }
                    50% { top: 100%; opacity: 0.2; }
                }
                @keyframes beaconPulse {
                    0%, 100% { transform: scale(1); opacity: 0.6; }
                    50% { transform: scale(1.3); opacity: 0; }
                }
            `}</style>

            {/* Header & Status Indicator */}
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div className="flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-50 text-[#E00D42] border border-rose-100">
                        <Sparkles className="h-4 w-4" />
                    </span>
                    <div>
                        <div className="flex items-center gap-2">
                            <h2 className="text-xs font-bold uppercase tracking-wider text-slate-900">
                                Hub Conveyor Automation Line
                            </h2>
                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse" />
                                6 Stations Online
                            </span>
                        </div>
                        <p className="text-[11px] text-slate-500">
                            Multi-parcel continuous conveyor system across all specialized hub stations
                        </p>
                    </div>
                </div>

                <div className="flex items-center gap-2">
                    {hasScannedParcel ? (
                        <div className="flex items-center gap-1.5 rounded-full border border-rose-200 bg-rose-50 px-3 py-1 text-[11px] font-semibold text-[#E00D42] shadow-2xs">
                            <span className="h-2 w-2 rounded-full bg-[#E00D42] animate-ping" />
                            <span>
                                Scanned Focus: Station {HUB_STATIONS[activeIndex]?.number} ({HUB_STATIONS[activeIndex]?.title})
                            </span>
                        </div>
                    ) : (
                        <div className="flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-semibold text-slate-600">
                            <Activity className="h-3 w-3 text-slate-400" />
                            <span>Continuous Throughput Active</span>
                        </div>
                    )}
                </div>
            </div>

            {/* Conveyor Belt System with Continuous Multi-Parcel Animation */}
            <div className="relative mt-5 mb-3 px-2 sm:px-6">
                {/* Physical Conveyor Track Chassis */}
                <div className="relative h-11 w-full rounded-xl bg-slate-900 border-2 border-slate-700 p-1 shadow-inner overflow-hidden">
                    {/* Metal Conveyor Guide Rails (Top & Bottom) */}
                    <div className="absolute inset-x-0 top-0 h-1 bg-slate-600 border-b border-slate-500 z-10" />
                    <div className="absolute inset-x-0 bottom-0 h-1 bg-slate-600 border-t border-slate-500 z-10" />

                    {/* Motorized Rollers with Continuous Motion Texture */}
                    <div
                        className="absolute inset-0 bg-[linear-gradient(90deg,#1e293b_0%,#334155_50%,#1e293b_100%)] bg-[length:24px_100%]"
                        style={{
                            animation: 'conveyorBeltRoll 1.2s linear infinite',
                        }}
                    />

                    {/* Live Moving Parcels Flowing Along the Belt */}
                    {conveyorParcels.map((parcel) => (
                        <div
                            key={parcel.id}
                            className="absolute top-1/2 z-20 pointer-events-none"
                            style={{
                                animation: 'parcelConveyorTravel 14s linear infinite',
                                animationDelay: parcel.delay,
                            }}
                        >
                            {/* Stylized Miniature Carton Box */}
                            <div
                                className={`flex items-center gap-1 px-1.5 py-0.5 rounded-md border text-[9px] font-bold shadow-xs ${parcel.style} select-none`}
                            >
                                <Package className="h-3 w-3 shrink-0" />
                                <span className="hidden sm:inline font-mono text-[8px] tracking-tight">{parcel.label}</span>
                            </div>
                        </div>
                    ))}

                    {/* Active Laser Scan Target at the Scanned Station Node */}
                    {hasScannedParcel && (
                        <div
                            className="absolute top-0 bottom-0 z-25 pointer-events-none transition-all duration-700 ease-out"
                            style={{
                                left: `calc(${(activeIndex / 5) * 100}% - 18px)`,
                                width: '36px',
                            }}
                        >
                            <div className="relative w-full h-full bg-rose-500/20 border-x border-[#E00D42]/60 backdrop-blur-2xs">
                                {/* Red Scanning Laser Beam */}
                                <div
                                    className="absolute inset-x-0 h-0.5 bg-[#E00D42] shadow-[0_0_8px_#E00D42]"
                                    style={{
                                        animation: 'scannerLaser 1.5s ease-in-out infinite',
                                    }}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* 6 Specialized Operational Station Nodes Along the Rail */}
                <div className="relative -mt-7 flex justify-between">
                    {HUB_STATIONS.map((station, idx) => {
                        const Icon = station.icon;
                        const isScannedActive = hasScannedParcel && idx === activeIndex;
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
                                {/* Station Node Workstation Badge */}
                                <div
                                    className={`relative z-30 flex h-11 w-11 items-center justify-center rounded-xl border-2 transition-all duration-300 shadow-md cursor-pointer ${
                                        isScannedActive
                                            ? 'border-[#E00D42] bg-[#E00D42] text-white ring-4 ring-rose-200 scale-110 shadow-rose-200'
                                            : isTargetSelected
                                            ? 'border-slate-800 bg-white text-slate-900 ring-2 ring-slate-300'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400 hover:text-slate-900'
                                    }`}
                                >
                                    <Icon className="h-5 w-5" />

                                    {/* Active Scanning Beacon Pulse on Scanned Node */}
                                    {isScannedActive && (
                                        <>
                                            <span
                                                className="absolute -inset-1 rounded-xl bg-rose-400 pointer-events-none"
                                                style={{ animation: 'beaconPulse 2s cubic-bezier(0, 0, 0.2, 1) infinite' }}
                                            />
                                            {/* Station Number Tag on Active Node */}
                                            <span className="absolute -top-2 -right-1 flex h-4 w-4 items-center justify-center rounded-full bg-white border border-[#E00D42] text-[9px] font-black text-[#E00D42] shadow-2xs">
                                                {station.number}
                                            </span>
                                        </>
                                    )}
                                </div>

                                {/* Active Scanned Waybill Floating Tag */}
                                {isScannedActive && trackingNumber && (
                                    <div className="absolute -top-7 z-40 whitespace-nowrap rounded-md bg-slate-900 px-2 py-0.5 text-[9px] font-bold font-mono text-white shadow-md border border-slate-700 animate-bounce">
                                        #{trackingNumber}
                                    </div>
                                )}

                                {/* Station Label */}
                                <div className="mt-2 text-center">
                                    <span
                                        className={`block text-[11px] font-bold leading-tight transition-colors ${
                                            isScannedActive
                                                ? 'text-[#E00D42]'
                                                : isTargetSelected
                                                ? 'text-slate-900'
                                                : 'text-slate-600'
                                        }`}
                                    >
                                        {station.title}
                                    </span>
                                    <span className="text-[10px] text-slate-400 font-medium">
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
                        {hasScannedParcel && selectedStation.number === HUB_STATIONS[activeIndex]?.number && (
                            <span className="rounded-md bg-rose-100 border border-rose-200 text-[#C20836] px-2 py-0.5 text-[10px] font-bold">
                                Current Scan Location
                            </span>
                        )}
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
