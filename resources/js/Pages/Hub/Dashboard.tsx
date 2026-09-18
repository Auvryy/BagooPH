import React, { useState, useId, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { 
    LayoutDashboard,
    ScanLine,
    Building2,
    Truck,
    Package,
    Store,
    TrendingUp,
    CheckCircle2,
    Clock,
    Box,
    ExternalLink,
    ChevronRight,
    ArrowUpRight,
    MapPin,
    Layers,
    ShieldCheck,
    BarChart3,
    ArrowRight
} from 'lucide-react';

interface Props {
    activeHub: {
        id: number;
        name: string;
        code: string;
        tier: string;
        province: string;
        city_municipality: string;
        barangay: string;
        address: string;
        capacity: number;
        allows_self_pickup: boolean;
        company?: {
            name: string;
            code: string;
        };
    } | null;
    hubs: Array<{
        id: number;
        name: string;
        code: string;
        tier: string;
        city_municipality: string;
        capacity: number;
    }>;
    stats: {
        parcels_in_hub: number;
        dispatched_today: number;
        ready_pickup: number;
        active_fleet: number;
        capacity: number;
        utilization_rate: number;
    };
    dailyDispatch: Array<{
        date: string;
        full_date: string;
        dispatches: number;
        inflow: number;
    }>;
    recentCheckpoints: Array<{
        id: number;
        tracking_number: string;
        checkpoint_type: string;
        location_name: string;
        notes?: string;
        scanned_by: string;
        created_at: string;
        timestamp: string;
        status: string;
        delivery_type: string;
        buyer_name: string;
        destination_bin: string;
    }>;
    hubFleet: Array<{
        id: number;
        plate_number: string;
        vehicle_type: string;
        model: string;
        capacity_kg: number;
        status: string;
        driver_name: string;
    }>;
    sampleTrackingNumbers: string[];
}

export default function HubDashboard({
    activeHub,
    hubs,
    stats,
    dailyDispatch,
    recentCheckpoints,
    hubFleet,
    sampleTrackingNumbers,
}: Props) {
    const [timeframe, setTimeframe] = useState<'7d' | '30d' | 'all'>('7d');
    const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);
    const [isHoveringChart, setIsHoveringChart] = useState(false);
    const chartClipId = useId();

    // Chart series data computation
    const chartSeries = useMemo(() => {
        return dailyDispatch.map((d) => ({
            date: d.date,
            dispatches: d.dispatches,
            inflow: d.inflow,
        }));
    }, [dailyDispatch]);

    // SVG Line Graph Geometry Constants matching Seller Dashboard
    const svgWidth = 680;
    const svgHeight = 150;
    const padding = { top: 18, right: 20, bottom: 28, left: 38 };
    const plotWidth = svgWidth - padding.left - padding.right;
    const plotHeight = svgHeight - padding.top - padding.bottom;

    const maxVolume = Math.max(...chartSeries.map((d) => Math.max(d.dispatches, d.inflow)), 50);

    // Calculate (x, y) plot coordinates
    const points = useMemo(() => {
        return chartSeries.map((item, idx) => {
            const x = padding.left + (idx / Math.max(1, chartSeries.length - 1)) * plotWidth;
            const y = padding.top + (1 - item.dispatches / maxVolume) * plotHeight;
            return { x, y, ...item };
        });
    }, [chartSeries, maxVolume, plotWidth, plotHeight]);

    // Catmull-Rom to Cubic Bezier Smooth Spline
    const splinePath = useMemo(() => {
        if (points.length === 0) return '';
        if (points.length === 1) return `M ${points[0].x} ${points[0].y}`;
        let d = `M ${points[0].x.toFixed(1)} ${points[0].y.toFixed(1)}`;
        for (let i = 0; i < points.length - 1; i++) {
            const p0 = points[i === 0 ? 0 : i - 1];
            const p1 = points[i];
            const p2 = points[i + 1];
            const p3 = points[i + 2 < points.length ? i + 2 : i + 1];
            const cp1x = p1.x + (p2.x - p0.x) / 6;
            const cp1y = p1.y + (p2.y - p0.y) / 6;
            const cp2x = p2.x - (p3.x - p1.x) / 6;
            const cp2y = p2.y - (p3.y - p1.y) / 6;
            d += ` C ${cp1x.toFixed(1)} ${cp1y.toFixed(1)}, ${cp2x.toFixed(1)} ${cp2y.toFixed(1)}, ${p2.x.toFixed(1)} ${p2.y.toFixed(1)}`;
        }
        return d;
    }, [points]);

    // Closed Area Path for Gradient Fill
    const areaPath = useMemo(() => {
        if (points.length < 2) return '';
        const lastX = points[points.length - 1].x.toFixed(1);
        const firstX = points[0].x.toFixed(1);
        const groundY = (svgHeight - padding.bottom).toFixed(1);
        return `${splinePath} L ${lastX} ${groundY} L ${firstX} ${groundY} Z`;
    }, [splinePath, points, svgHeight, padding.bottom]);

    const activePointIdx = hoveredIdx !== null ? hoveredIdx : points.length - 1;
    const activePoint = points[activePointIdx] || points[points.length - 1];

    const handleChartMouseMove = (e: React.MouseEvent<SVGSVGElement>) => {
        const rect = e.currentTarget.getBoundingClientRect();
        const clientX = e.clientX - rect.left;
        const scale = svgWidth / rect.width;
        const curSvgX = clientX * scale;
        const clampedX = Math.max(padding.left, Math.min(svgWidth - padding.right, curSvgX));
        const pct = (clampedX - padding.left) / plotWidth;
        const nearestIdx = Math.min(points.length - 1, Math.max(0, Math.round(pct * (points.length - 1))));
        setHoveredIdx(nearestIdx);
        setIsHoveringChart(true);
    };

    const handleChartMouseLeave = () => {
        setIsHoveringChart(false);
        setHoveredIdx(null);
    };

    return (
        <DashboardLayout
            title="Logistics Operations"
            subtitle={
                activeHub ? (
                    <span className="flex items-center gap-1.5">
                        <span className="font-bold text-slate-800">{activeHub.name}</span>
                        <span className="text-slate-400">•</span>
                        <span className="font-mono text-blue-600">{activeHub.code}</span>
                        <span className="text-slate-400">•</span>
                        <span className="capitalize">{activeHub.tier.replace(/_/g, ' ')}</span>
                    </span>
                ) : undefined
            }
            actions={
                <div className="flex items-center gap-2">
                    <Link
                        href={route('hub.counter')}
                        className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-bold font-mono rounded-lg border border-slate-200 transition"
                    >
                        <Store className="w-3.5 h-3.5 text-blue-600" />
                        <span>Counter Pickup</span>
                    </Link>
                    <Link
                        href={route('hub.scan.station')}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold font-mono rounded-lg shadow-xs transition uppercase tracking-wider"
                    >
                        <ScanLine className="w-3.5 h-3.5" />
                        <span>Barcode Scanner</span>
                    </Link>
                </div>
            }
        >
            <Head title="Logistics Operations — BagooPH" />

            <div className="space-y-6 font-sans">

                {/* 1. TOP BUSINESS KPI TILES (MATCHING SELLER DASHBOARD CLEAN AESTHETIC) */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    
                    {/* Parcels in Hub */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Parcels in Hub</span>
                                <span className="inline-flex items-center gap-0.5 text-blue-700 text-[10px] font-bold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200 font-mono">
                                    <Layers className="w-3 h-3" /> Live
                                </span>
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.parcels_in_hub}{' '}
                                    <span className="text-sm font-bold text-slate-400">parcels</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Capacity Load:</span>
                            <span className="font-bold text-slate-800">
                                {stats.utilization_rate}% ({stats.capacity} max)
                            </span>
                        </div>
                    </div>

                    {/* Dispatches Today */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Dispatched Today</span>
                                <Truck className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.dispatched_today}{' '}
                                    <span className="text-sm font-bold text-slate-400">outbound</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Dispatch Target:</span>
                            <span className="font-bold text-emerald-600">On Track</span>
                        </div>
                    </div>

                    {/* Counter Self-Pickup */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Counter Staged</span>
                                <Store className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.ready_pickup}{' '}
                                    <span className="text-sm font-bold text-slate-400">awaiting</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Handover Service:</span>
                            <span className="font-bold text-blue-600">Free Self-Pickup</span>
                        </div>
                    </div>

                    {/* Active Fleet */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-mono text-xs">
                                <span className="font-bold uppercase">Active Fleet</span>
                                <div className="flex items-center gap-1 text-emerald-600">
                                    <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    <span className="font-bold font-mono text-[10px] uppercase">Online</span>
                                </div>
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-mono tracking-tight">
                                    {stats.active_fleet}{' '}
                                    <span className="text-sm font-bold text-slate-400">units</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-mono">
                            <span className="text-slate-400">Fleet Readiness:</span>
                            <span className="font-bold text-slate-700">100% Operational</span>
                        </div>
                    </div>
                </div>

                {/* 2. BENTO SECTION: INTERACTIVE SPLINE GRAPH + FACILITY HEALTH CARD */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">

                    {/* LEFT (8 COLS): INTERACTIVE SPLINE LINE GRAPH */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <span className="w-2.5 h-2.5 rounded-full bg-blue-600"></span>
                                        <h3 className="text-sm font-black text-slate-900 font-mono uppercase tracking-wider">
                                            Throughput Velocity
                                        </h3>
                                    </div>
                                    <p className="text-xs text-slate-500 mt-0.5">
                                        Outbound dispatches and intake sorting rate over time
                                    </p>
                                </div>

                                {/* Timeframe Filter Buttons */}
                                <div className="flex items-center gap-1.5 p-1 bg-slate-100 rounded-lg font-mono text-xs">
                                    {(['7d', '30d', 'all'] as const).map((tf) => (
                                        <button
                                            key={tf}
                                            type="button"
                                            onClick={() => setTimeframe(tf)}
                                            className={`px-3 py-1 rounded-md text-[11px] font-bold transition cursor-pointer ${
                                                timeframe === tf
                                                    ? 'bg-white text-slate-900 shadow-2xs'
                                                    : 'text-slate-500 hover:text-slate-800'
                                            }`}
                                        >
                                            {tf.toUpperCase()}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Chart Active Point Readout */}
                            <div className="flex items-center gap-6 py-4">
                                <div>
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono block">
                                        Dispatched ({activePoint?.date || 'Today'})
                                    </span>
                                    <span className="text-xl font-black text-slate-900 font-mono">
                                        {activePoint?.dispatches ?? stats.dispatched_today}{' '}
                                        <span className="text-xs font-normal text-slate-500">units</span>
                                    </span>
                                </div>
                                <div className="border-l border-slate-200 pl-6">
                                    <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono block">
                                        Intake Volume
                                    </span>
                                    <span className="text-xl font-black text-blue-600 font-mono">
                                        {activePoint?.inflow ?? Math.round(stats.dispatched_today * 1.15)}{' '}
                                        <span className="text-xs font-normal text-slate-500">received</span>
                                    </span>
                                </div>
                            </div>

                            {/* SVG Chart Container */}
                            <div className="relative w-full h-44 sm:h-52">
                                <svg
                                    viewBox={`0 0 ${svgWidth} ${svgHeight}`}
                                    className="w-full h-full overflow-visible"
                                    preserveAspectRatio="none"
                                    onMouseMove={handleChartMouseMove}
                                    onMouseLeave={handleChartMouseLeave}
                                >
                                    <defs>
                                        <linearGradient id={`${chartClipId}-gradient`} x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor="#2563EB" stopOpacity="0.18" />
                                            <stop offset="100%" stopColor="#2563EB" stopOpacity="0.0" />
                                        </linearGradient>
                                    </defs>

                                    {/* Horizontal Guidelines */}
                                    {[0.25, 0.5, 0.75, 1.0].map((frac, idx) => {
                                        const yVal = padding.top + (1 - frac) * plotHeight;
                                        return (
                                            <g key={idx}>
                                                <line
                                                    x1={padding.left}
                                                    y1={yVal}
                                                    x2={svgWidth - padding.right}
                                                    y2={yVal}
                                                    stroke="#F1F5F9"
                                                    strokeWidth="1"
                                                    strokeDasharray="4 4"
                                                />
                                            </g>
                                        );
                                    })}

                                    {/* Area Fill */}
                                    {areaPath && (
                                        <path d={areaPath} fill={`url(#${chartClipId}-gradient)`} />
                                    )}

                                    {/* Spline Path */}
                                    {splinePath && (
                                        <path
                                            d={splinePath}
                                            fill="none"
                                            stroke="#2563EB"
                                            strokeWidth="2.5"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        />
                                    )}

                                    {/* Scrub Indicator Line & Point */}
                                    {isHoveringChart && activePoint && (
                                        <g>
                                            <line
                                                x1={activePoint.x}
                                                y1={padding.top}
                                                x2={activePoint.x}
                                                y2={svgHeight - padding.bottom}
                                                stroke="#94A3B8"
                                                strokeWidth="1"
                                                strokeDasharray="3 3"
                                            />
                                            <circle
                                                cx={activePoint.x}
                                                cy={activePoint.y}
                                                r="5"
                                                fill="#2563EB"
                                                stroke="#FFFFFF"
                                                strokeWidth="2"
                                            />
                                        </g>
                                    )}

                                    {/* X-Axis Date Labels */}
                                    {points.map((p, idx) => (
                                        <text
                                            key={idx}
                                            x={p.x}
                                            y={svgHeight - 8}
                                            textAnchor="middle"
                                            fontSize="9"
                                            fontWeight="600"
                                            fontFamily="monospace"
                                            fill={idx === activePointIdx ? '#1E293B' : '#94A3B8'}
                                        >
                                            {p.date}
                                        </text>
                                    ))}
                                </svg>
                            </div>
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono text-slate-500">
                            <span>Peak throughput: {maxVolume} parcels/day</span>
                            <Link
                                href={route('hub.deliveries')}
                                className="inline-flex items-center gap-1 text-blue-600 font-bold hover:underline"
                            >
                                <span>Parcels Registry</span>
                                <ArrowRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>
                    </div>

                    {/* RIGHT (4 COLS): FACILITY HEALTH & OPERATION CARD */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-2">
                                    <Building2 className="w-4 h-4 text-blue-600" />
                                    <h3 className="text-xs font-black text-slate-900 font-mono uppercase tracking-wider">
                                        Operating Facility
                                    </h3>
                                </div>
                                <span className="text-[10px] font-bold px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 font-mono uppercase">
                                    Active
                                </span>
                            </div>

                            {/* Hub Overview Info */}
                            <div className="mt-4 p-3.5 rounded-xl bg-slate-50 border border-slate-200/70">
                                <p className="text-xs font-black text-slate-900 truncate">
                                    {activeHub?.name || 'Logistics Sorting Center'}
                                </p>
                                <p className="text-[11px] text-slate-500 font-mono mt-0.5">
                                    Code: {activeHub?.code || 'STATION-01'} • {activeHub?.province || 'Laguna'}
                                </p>
                                <div className="flex items-center gap-1.5 text-[10px] text-slate-500 mt-2 font-mono">
                                    <MapPin className="w-3 h-3 text-slate-400 shrink-0" />
                                    <span className="truncate">{activeHub?.address || 'Provincial Freight Terminal'}</span>
                                </div>
                            </div>

                            {/* Capacity Meter */}
                            <div className="mt-4 space-y-2">
                                <div className="flex items-center justify-between text-xs font-mono">
                                    <span className="text-slate-500 font-bold uppercase text-[10px]">Sorting Bay Capacity</span>
                                    <span className="font-bold text-slate-800">{stats.utilization_rate}%</span>
                                </div>
                                <div className="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden border border-slate-200">
                                    <div
                                        className={`h-full rounded-full transition-all duration-500 ${
                                            stats.utilization_rate > 85
                                                ? 'bg-rose-500'
                                                : stats.utilization_rate > 60
                                                ? 'bg-amber-500'
                                                : 'bg-blue-600'
                                        }`}
                                        style={{ width: `${Math.min(100, Math.max(5, stats.utilization_rate))}%` }}
                                    />
                                </div>
                                <p className="text-[10px] text-slate-400 font-mono">
                                    {stats.parcels_in_hub} parcels held / {stats.capacity} maximum sorting volume
                                </p>
                            </div>

                            {/* Quick Action Station Tiles */}
                            <div className="mt-5 space-y-2">
                                <Link
                                    href={route('hub.scan.station')}
                                    className="w-full flex items-center justify-between p-3 rounded-xl bg-blue-50/70 hover:bg-blue-100/70 border border-blue-200 text-blue-900 transition group"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <ScanLine className="w-4 h-4 text-blue-600 group-hover:scale-110 transition-transform" />
                                        <div className="text-left">
                                            <p className="text-xs font-bold">Floor Scanner Terminal</p>
                                            <p className="text-[10px] text-blue-600 font-mono">Camera & USB Barcode Scan</p>
                                        </div>
                                    </div>
                                    <ChevronRight className="w-4 h-4 text-blue-400 group-hover:translate-x-0.5 transition-transform" />
                                </Link>

                                <Link
                                    href={route('hub.counter')}
                                    className="w-full flex items-center justify-between p-3 rounded-xl bg-slate-50 hover:bg-slate-100 border border-slate-200 text-slate-800 transition group"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Store className="w-4 h-4 text-emerald-600 group-hover:scale-110 transition-transform" />
                                        <div className="text-left">
                                            <p className="text-xs font-bold">Counter Self-Pickup</p>
                                            <p className="text-[10px] text-slate-500 font-mono">Customer ID Verification & Release</p>
                                        </div>
                                    </div>
                                    <ChevronRight className="w-4 h-4 text-slate-400 group-hover:translate-x-0.5 transition-transform" />
                                </Link>
                            </div>
                        </div>

                        <div className="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between text-xs font-mono">
                            <span className="text-slate-400">Network Map:</span>
                            <Link
                                href={route('hub.network')}
                                className="font-bold text-blue-600 hover:underline flex items-center gap-1"
                            >
                                <span>View Facilities</span>
                                <ArrowRight className="w-3 h-3" />
                            </Link>
                        </div>
                    </div>
                </div>

                {/* 3. LOWER SPLIT SECTION: RECENT SCAN CHECKPOINTS STREAM + FLEET STATUS */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* LEFT (8 COLS): RECENT ACTIVITY STREAM */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <ScanLine className="w-4 h-4 text-blue-600" />
                                <h3 className="text-xs font-black text-slate-900 font-mono uppercase tracking-wider">
                                    Recent Checkpoint Scans
                                </h3>
                            </div>
                            <Link
                                href={route('hub.scan.station')}
                                className="text-xs font-mono text-blue-600 font-bold hover:underline"
                            >
                                Open Scanner
                            </Link>
                        </div>

                        {recentCheckpoints.length === 0 ? (
                            <div className="py-12 text-center">
                                <Package className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                                <p className="text-xs font-bold text-slate-600">No checkpoints recorded yet</p>
                                <p className="text-[11px] text-slate-400 font-mono mt-0.5">
                                    Scan waybills at the Floor Scanner Station to track movement
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {recentCheckpoints.map((cp) => (
                                    <div key={cp.id} className="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2 text-xs">
                                        <div className="min-w-0 flex items-start gap-3">
                                            <div className="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center font-mono shrink-0 mt-0.5">
                                                <Package className="w-4 h-4" />
                                            </div>
                                            <div className="min-w-0">
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono font-bold text-slate-900">
                                                        {cp.tracking_number}
                                                    </span>
                                                    <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 uppercase font-mono">
                                                        {cp.delivery_type === 'hub_self_pickup' ? 'Counter' : 'Doorstep'}
                                                    </span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">
                                                    {cp.location_name} • Scanned by {cp.scanned_by}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-3 sm:text-right shrink-0">
                                            <div>
                                                <span className="inline-block px-2 py-0.5 text-[10px] font-bold rounded-md uppercase font-mono bg-slate-100 text-slate-700">
                                                    {cp.status.replace(/_/g, ' ')}
                                                </span>
                                                <p className="text-[10px] text-slate-400 font-mono mt-0.5">
                                                    {cp.created_at}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* RIGHT (4 COLS): ACTIVE FLEET QUICK OVERVIEW */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <Truck className="w-4 h-4 text-blue-600" />
                                <h3 className="text-xs font-black text-slate-900 font-mono uppercase tracking-wider">
                                    Station Fleet Units
                                </h3>
                            </div>
                            <Link
                                href={route('hub.fleet')}
                                className="text-xs font-mono text-blue-600 font-bold hover:underline"
                            >
                                All Fleet
                            </Link>
                        </div>

                        {hubFleet.length === 0 ? (
                            <div className="py-12 text-center">
                                <Truck className="w-8 h-8 text-slate-300 mx-auto mb-2" />
                                <p className="text-xs font-bold text-slate-600">No fleet vehicles assigned</p>
                                <p className="text-[11px] text-slate-400 font-mono mt-0.5">
                                    Add riders and transport vans in Fleet Management
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y divide-slate-100">
                                {hubFleet.map((vehicle) => (
                                    <div key={vehicle.id} className="py-3 flex items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-xs font-bold text-slate-900 font-mono">
                                                {vehicle.plate_number}
                                            </p>
                                            <p className="text-[11px] text-slate-500 truncate mt-0.5">
                                                {vehicle.model}
                                            </p>
                                            <p className="text-[10px] text-slate-400 font-mono">
                                                Driver: {vehicle.driver_name}
                                            </p>
                                        </div>
                                        <span className={`px-2 py-0.5 text-[9px] font-bold rounded uppercase font-mono ${
                                            vehicle.status === 'active'
                                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                : 'bg-slate-100 text-slate-600'
                                        }`}>
                                            {vehicle.status}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                </div>

            </div>
        </DashboardLayout>
    );
}
