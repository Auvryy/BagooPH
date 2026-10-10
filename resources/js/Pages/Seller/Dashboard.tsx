import React, { useState, useId, useMemo } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Order, Product, Shop } from '@/types';
import { 
    Package, 
    ShoppingCart, 
    ArrowRight, 
    Plus, 
    CheckCircle2,
    Truck,
    Clock,
    Box,
    Layers,
    ArrowUpRight,
    ChevronRight,
    ExternalLink,
    RotateCcw
} from 'lucide-react';

interface Props {
    shop: Shop;
    stats: {
        totalProducts: number;
        lowStockCount: number;
        outOfStockCount: number;
        completedGrossSales: number;
        completedUnits: number;
        completedOrderCount: number;
        averageCompletedOrderValue: number;
        estimatedSellerShare: number;
        openOrderValue: number;
        openUnits: number;
        openOrderCount: number;
        pendingPackCount: number;
        readyPickupCount: number;
        shippedCount: number;
        completedCount: number;
        returnCount: number;
        deliveredCount: number;
        deliveryIssueCount: number;
        cancelledCount: number;
        returnedCount: number;
    };
    dailySales: Array<{
        date: string;
        revenue: number;
        units: number;
    }>;
    recentOrders: Order[];
    topProducts: Product[];
}

export default function SellerDashboard({ shop, stats, dailySales, recentOrders, topProducts }: Props) {
    const [hoveredIdx, setHoveredIdx] = useState<number | null>(null);
    const [isHoveringChart, setIsHoveringChart] = useState(false);
    const chartClipId = useId();

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    // Total pending action items requiring merchant intervention
    const pendingActions = stats.pendingPackCount + stats.readyPickupCount;

    const chartSeries = dailySales;
    const hasCompletedSales = chartSeries.some((day) => day.revenue > 0 || day.units > 0);

    // SVG Line Graph Geometry Constants
    const svgWidth = 680;
    const svgHeight = 150;
    const padding = { top: 18, right: 20, bottom: 28, left: 38 };
    const plotWidth = svgWidth - padding.left - padding.right;
    const plotHeight = svgHeight - padding.top - padding.bottom;

    const maxRevenue = Math.max(...chartSeries.map(d => d.revenue), 1);

    // Calculate (x, y) plot coordinates
    const points = useMemo(() => {
        return chartSeries.map((item, idx) => {
            const x = padding.left + (idx / Math.max(1, chartSeries.length - 1)) * plotWidth;
            const y = padding.top + (1 - item.revenue / maxRevenue) * plotHeight;
            return { x, y, ...item };
        });
    }, [chartSeries, maxRevenue, plotWidth, plotHeight]);

    // Build Catmull-Rom to Cubic Bezier Smooth Spline
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

    // Closed Area Path for Subtle Gradient Fill
    const areaPath = useMemo(() => {
        if (points.length < 2) return '';
        const lastX = points[points.length - 1].x.toFixed(1);
        const firstX = points[0].x.toFixed(1);
        const groundY = (svgHeight - padding.bottom).toFixed(1);
        return `${splinePath} L ${lastX} ${groundY} L ${firstX} ${groundY} Z`;
    }, [splinePath, points, svgHeight, padding.bottom]);

    // Handle interactive scrub across chart
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
            title="Dashboard"
            actions={
                <div className="flex items-center gap-2">
                    <Link
                        href={route('preview')}
                        target="_blank"
                        className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-50 hover:bg-slate-100 text-slate-700 text-xs font-bold font-sans rounded-lg border border-slate-200 transition"
                    >
                        <ExternalLink className="w-3.5 h-3.5 text-slate-500" />
                        <span>Storefront Preview</span>
                    </Link>
                    <Link
                        href={route('seller.products.index')}
                        className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-sans rounded-lg shadow-xs transition uppercase tracking-wider"
                    >
                        <Plus className="w-3.5 h-3.5" />
                        <span>New Listing</span>
                    </Link>
                </div>
            }
        >
            <Head title="Dashboard — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP BUSINESS KPI TILES (CLEAN MINIMALIST CARDS) */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Completed sales</span>
                                <CheckCircle2 className="w-4 h-4 text-emerald-600" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {formatPrice(stats.completedGrossSales)}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Estimated seller share</span>
                            <span className="font-bold text-slate-900">{formatPrice(stats.estimatedSellerShare)}</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Completed items</span>
                                <Package className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {stats.completedUnits} <span className="text-sm font-bold text-slate-500">items</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Average completed order</span>
                            <span className="font-bold text-slate-800">
                                {formatPrice(stats.averageCompletedOrderValue)}
                            </span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Open order value</span>
                                <ShoppingCart className="w-4 h-4 text-[#E00D42]" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {formatPrice(stats.openOrderValue)}
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-[11px] font-sans">
                            <span className="text-slate-500">Stock reserved at checkout</span>
                            <span className="font-bold text-slate-900">{stats.openOrderCount} orders · {stats.openUnits} units</span>
                        </div>
                    </div>

                    <div className="bg-white rounded-lg p-5 border border-slate-300 shadow-2xs flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between text-slate-500 font-sans text-xs">
                                <span className="font-semibold">Catalog</span>
                                <Box className="w-4 h-4 text-slate-400" />
                            </div>
                            <div className="mt-2">
                                <p className="text-2xl sm:text-3xl font-black text-slate-900 font-sans tracking-tight">
                                    {stats.totalProducts} <span className="text-sm font-bold text-slate-500">SKUs</span>
                                </p>
                            </div>
                        </div>
                        <div className="mt-3 pt-3 border-t border-slate-100 space-y-2 text-[11px] font-sans">
                            <span className="text-slate-500">Active listing stock</span>
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                <Link href={route('seller.products.index', { status: 'active', stock: 'low_stock' })} className="font-bold text-amber-700 hover:underline focus-visible:outline-[#E00D42]">{stats.lowStockCount} low stock (1–5)</Link>
                                <Link href={route('seller.products.index', { status: 'active', stock: 'out_of_stock' })} className="font-bold text-rose-700 hover:underline focus-visible:outline-[#E00D42]">{stats.outOfStockCount ?? 0} out of stock</Link>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. BENTO SECTION: INTERACTIVE MINIMALIST SPLINE GRAPH + VERTICAL FULFILLMENT CARDS */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* LEFT (8 COLS): INTERACTIVE SPLINE LINE GRAPH */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        
                        {/* Chart Header */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100">
                            <div>
                                <div className="flex items-center gap-2">
                                    <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42]"></span>
                                    <h3 className="text-sm font-bold text-slate-900 font-sans">
                                        Completed sales
                                    </h3>
                                </div>
                                <p className="mt-1 text-xs text-slate-500">Buyer-confirmed product sales for the last seven days</p>
                            </div>
                            <span className="text-xs font-semibold text-slate-500">Last 7 days</span>
                        </div>

                        {/* Interactive Line Chart Canvas - Sleek, Lower Height */}
                        <div className="relative pt-3 pb-1">
                            {!hasCompletedSales ? (
                                <div className="flex h-32 flex-col items-center justify-center px-6 text-center">
                                    <CheckCircle2 className="mb-2 h-5 w-5 text-slate-400" />
                                    <p className="text-sm font-semibold text-slate-700">No completed sales in this period</p>
                                    <p className="mt-1 text-xs text-slate-500">Open orders remain in the fulfillment queue until the buyer confirms receipt.</p>
                                </div>
                            ) : (
                                <>
                            
                            {/* Live Hover Tooltip */}
                            {isHoveringChart && activePoint && (
                                <div
                                    className="absolute pointer-events-none z-20 bg-slate-950 text-white p-3 rounded-xl shadow-xl border border-slate-800 font-sans text-xs transition-all duration-75"
                                    style={{
                                        left: `${(activePoint.x / svgWidth) * 100}%`,
                                        top: `${(activePoint.y / svgHeight) * 100}%`,
                                        transform: 'translate(-50%, -125%)',
                                    }}
                                >
                                    <div className="flex items-center justify-between gap-4 pb-1.5 border-b border-slate-800 text-[10px] text-slate-400">
                                        <span>{activePoint.date}</span>
                                        <span className="text-slate-300 font-bold">{activePoint.units} completed</span>
                                    </div>
                                    <div className="mt-1.5 space-y-0.5">
                                        <p className="text-base font-black text-white font-sans">
                                            {formatPrice(activePoint.revenue)}
                                        </p>
                                        <p className="text-[10px] text-slate-300">
                                            Estimated seller share: {formatPrice(activePoint.revenue * 0.9)}
                                        </p>
                                    </div>
                                    <div className="w-2 h-2 bg-slate-950 rotate-45 border-r border-b border-slate-800 absolute -bottom-1 left-1/2 -translate-x-1/2"></div>
                                </div>
                            )}

                            {/* SVG Spline Container */}
                            <svg
                                viewBox={`0 0 ${svgWidth} ${svgHeight}`}
                                className="w-full h-32 select-none cursor-crosshair overflow-visible"
                                onMouseMove={handleChartMouseMove}
                                onMouseLeave={handleChartMouseLeave}
                            >
                                <defs>
                                    {/* Gradient fill under spline curve */}
                                    <linearGradient id="curveGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stopColor="#E00D42" stopOpacity="0.16" />
                                        <stop offset="60%" stopColor="#E00D42" stopOpacity="0.04" />
                                        <stop offset="100%" stopColor="#E00D42" stopOpacity="0.0" />
                                    </linearGradient>

                                    {/* Clip path for colorized progress reveal */}
                                    <clipPath id={chartClipId}>
                                        <rect
                                            x={0}
                                            y={0}
                                            width={activePoint ? activePoint.x : svgWidth}
                                            height={svgHeight}
                                        />
                                    </clipPath>
                                </defs>

                                {/* Horizontal Grid Lines */}
                                {[0, 0.5, 1].map((ratio, idx) => {
                                    const y = padding.top + ratio * plotHeight;
                                    return (
                                        <g key={idx}>
                                            <line
                                                x1={padding.left}
                                                y1={y}
                                                x2={svgWidth - padding.right}
                                                y2={y}
                                                stroke="#f1f5f9"
                                                strokeWidth="1"
                                            />
                                            <text
                                                x={padding.left - 6}
                                                y={y + 3}
                                                fill="#94a3b8"
                                                fontSize="8.5"
                                                fontFamily="Plus Jakarta Sans, sans-serif"
                                                textAnchor="end"
                                            >
                                                {formatPrice(maxRevenue * (1 - ratio)).slice(0, -3)}
                                            </text>
                                        </g>
                                    );
                                })}

                                {/* Area Fill Under the Spline */}
                                {areaPath && (
                                    <path
                                        d={areaPath}
                                        fill="url(#curveGradient)"
                                    />
                                )}

                                {/* Inactive Line Path (Soft Gray) */}
                                <path
                                    d={splinePath}
                                    fill="none"
                                    stroke="#e2e8f0"
                                    strokeWidth="2.5"
                                    strokeLinecap="round"
                                />

                                {/* Active Highlighted Spline Curve (Crimson Red) */}
                                <g clipPath={`url(#${chartClipId})`}>
                                    <path
                                        d={splinePath}
                                        fill="none"
                                        stroke="#E00D42"
                                        strokeWidth="3"
                                        strokeLinecap="round"
                                    />
                                </g>

                                {/* Vertical Scrubber Guideline */}
                                {activePoint && (
                                    <line
                                        x1={activePoint.x}
                                        y1={padding.top}
                                        x2={activePoint.x}
                                        y2={svgHeight - padding.bottom}
                                        stroke="#cbd5e1"
                                        strokeDasharray="3 3"
                                        strokeWidth="1.5"
                                    />
                                )}

                                {/* Interactive Indicator Points on Spline */}
                                {points.map((pt, idx) => {
                                    const isActive = idx === activePointIdx;
                                    return (
                                        <g key={idx}>
                                            {/* Outer highlight pulse */}
                                            {isActive && (
                                                <circle
                                                    cx={pt.x}
                                                    cy={pt.y}
                                                    r="8"
                                                    fill="#E00D42"
                                                    fillOpacity="0.2"
                                                />
                                            )}
                                            {/* Primary Anchor Dot */}
                                            <circle
                                                cx={pt.x}
                                                cy={pt.y}
                                                r={isActive ? "4.5" : "3"}
                                                fill={isActive ? "#E00D42" : "#94a3b8"}
                                                stroke="#ffffff"
                                                strokeWidth="2"
                                            />
                                            {/* X-axis date text */}
                                            <text
                                                x={pt.x}
                                                y={svgHeight - padding.bottom + 16}
                                                fill={isActive ? "#E00D42" : "#64748b"}
                                                fontWeight={isActive ? "bold" : "normal"}
                                                fontSize="9.5"
                                                fontFamily="Plus Jakarta Sans, sans-serif"
                                                textAnchor="middle"
                                                className="uppercase tracking-wider"
                                            >
                                                {pt.date}
                                            </text>
                                        </g>
                                    );
                                })}
                            </svg>
                                </>
                            )}
                        </div>

                        {/* Bottom Telemetry Strip: Clean Single-Row Bar (Minimalist, Zero Bulk) */}
                        {hasCompletedSales && <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pt-2 mt-1.5 border-t border-slate-100 font-sans text-xs">
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] text-slate-500">Selected date completed sales</span>
                                <span className="text-sm font-black text-slate-900 font-sans">
                                    {formatPrice(activePoint?.revenue || 0)}
                                </span>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-[10px] text-slate-500">Estimated seller share</span>
                                <span className="text-sm font-black text-slate-900 font-sans">
                                    {formatPrice((activePoint?.revenue || 0) * 0.9)}
                                </span>
                            </div>
                        </div>}
                    </div>

                    {/* RIGHT (4 COLS): VERTICAL FULFILLMENT CARDS WITH 2 PRIMARY ACTIONS + PIPELINE SUMMARY */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs flex flex-col justify-between">
                        {/* Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 shrink-0">
                            <div className="flex items-center gap-2">
                                <Box className="w-4 h-4 text-[#E00D42]" />
                                <h3 className="text-xs font-black text-slate-900 font-sans uppercase tracking-wider">
                                    Fulfillment Actions
                                </h3>
                            </div>
                            <Link 
                                href={route('seller.orders.index')} 
                                className="text-[11px] font-bold font-sans text-[#E00D42] hover:underline uppercase inline-flex items-center gap-1"
                            >
                                View Orders <ArrowRight className="w-3 h-3" />
                            </Link>
                        </div>

                        {/* Action Cards & Pipeline Box - Sleek, thin, and compact */}
                        <div className="space-y-2.5 pt-3 font-sans">
                            
                            {/* 1. TO PACK */}
                            <Link
                                href={route('seller.orders.index', { status: 'to_pack' })}
                                className={`p-2.5 sm:p-3 rounded-xl transition flex items-center justify-between gap-3 group ${
                                    stats.pendingPackCount > 0
                                        ? 'bg-amber-50/70 border border-amber-300 shadow-2xs hover:bg-amber-100/60'
                                        : 'bg-slate-50 border border-slate-200/90 hover:border-amber-400'
                                }`}
                            >
                                <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                    <div className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                        stats.pendingPackCount > 0
                                            ? 'bg-amber-500 text-white shadow-xs'
                                            : 'bg-amber-100 text-amber-700'
                                    }`}>
                                        <Package className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-xs font-bold text-slate-900 uppercase font-sans tracking-tight group-hover:text-amber-900 block leading-tight">
                                            To Pack
                                        </span>
                                        <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                            Awaiting packaging
                                        </p>
                                    </div>
                                </div>
                                <span className={`px-2.5 py-1 min-w-[28px] text-center rounded-lg font-sans text-sm font-black shrink-0 ${
                                    stats.pendingPackCount > 0
                                        ? 'bg-amber-500 text-white shadow-xs'
                                        : 'bg-slate-200 text-slate-700'
                                }`}>
                                    {stats.pendingPackCount}
                                </span>
                            </Link>

                            {/* 2. RETURN CUSTODY */}
                            <Link
                                href={route('seller.orders.index', { status: 'return_custody' })}
                                className={`p-2.5 sm:p-3 rounded-xl transition flex items-center justify-between gap-3 group ${
                                    (stats.returnCount || 0) > 0
                                        ? 'bg-rose-50/70 border border-rose-300 shadow-2xs hover:bg-rose-100/60'
                                        : 'bg-slate-50 hover:bg-rose-50/30 border border-slate-200/90 hover:border-rose-400'
                                }`}
                            >
                                <div className="flex items-center gap-2.5 min-w-0 flex-1">
                                    <div className={`w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ${
                                        (stats.returnCount || 0) > 0
                                            ? 'bg-rose-500 text-white shadow-xs'
                                            : 'bg-rose-100 text-rose-700'
                                    }`}>
                                        <RotateCcw className="w-4 h-4" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <span className="text-xs font-bold text-slate-900 uppercase font-sans tracking-tight group-hover:text-rose-900 block leading-tight" title="Return parcels">
                                            Return parcels
                                        </span>
                                        <p className="text-[10px] text-slate-500 font-sans truncate mt-0.5">
                                            {(stats.returnCount || 0) > 0 ? 'Review reverse parcel custody' : 'No recorded return parcels'}
                                        </p>
                                    </div>
                                </div>
                                <span className={`px-2.5 py-1 min-w-[28px] text-center rounded-lg font-sans text-sm font-black shrink-0 ${
                                    (stats.returnCount || 0) > 0
                                        ? 'bg-rose-500 text-white shadow-xs'
                                        : 'bg-slate-200 text-slate-700'
                                }`}>
                                    {stats.returnCount || 0}
                                </span>
                            </Link>

                            <div className="space-y-2 text-xs">
                                {[{ status: 'delivery_failed', label: 'Delivery issues', count: stats.deliveryIssueCount }, { status: 'returned', label: 'Returned orders', count: stats.returnedCount }, { status: 'cancelled', label: 'Cancelled orders', count: stats.cancelledCount }].map(stage => <Link key={stage.status} href={route('seller.orders.index', { status: stage.status })} className="flex items-center justify-between rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-slate-700 hover:border-[#E00D42]"><span>{stage.label}</span><strong>{stage.count ?? 0}</strong></Link>)}
                                <p className="text-[10px] text-slate-500">Post-delivery disputes remain unavailable.</p>
                            </div>

                            {/* 3. LOGISTICS PIPELINE SUMMARY BOX */}
                            <div className="p-2.5 sm:p-3 rounded-xl bg-slate-50 border border-slate-200/80 font-sans shrink-0">
                                <div className="flex items-center justify-between pb-1.5 mb-2 border-b border-slate-200/60 text-[9px] uppercase font-bold text-slate-400 tracking-wider">
                                    <span>Logistics Pipeline</span>
                                    <span className="text-slate-500">Live Status</span>
                                </div>
                                <div className="grid grid-cols-4 gap-1 divide-x divide-slate-200/80 text-center">
                                    <Link
                                        href={route('seller.orders.index', { status: 'to_pickup' })}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block"
                                        title="Orders ready for courier pickup"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            Ready
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {stats.readyPickupCount}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            pickup
                                        </span>
                                    </Link>

                                    <Link
                                        href={route('seller.orders.index', { status: 'in_transit' })}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block"
                                        title="Orders currently in transit with courier"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            Transit
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {stats.shippedCount}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            on way
                                        </span>
                                    </Link>

                                    <Link
                                        href={route('seller.orders.index', { status: 'delivered' })}
                                        className="px-1 hover:bg-slate-100/80 rounded-lg transition group block"
                                        title="Delivered orders"
                                    >
                                        <span className="text-[9px] text-slate-500 font-bold uppercase tracking-wider block group-hover:text-slate-900">
                                            Delivered
                                        </span>
                                        <span className="text-sm font-black text-slate-900 font-sans block mt-0.5 group-hover:text-[#E00D42]">
                                            {stats.deliveredCount ?? 0}
                                        </span>
                                        <span className="text-[8px] text-slate-400 block -mt-0.5">
                                            received
                                        </span>
                                    </Link>
                                    <Link href={route('seller.orders.index', { status: 'completed' })} className="block rounded-lg px-1 text-center hover:bg-slate-100/80" title="Buyer-confirmed completed orders">
                                        <span className="block text-[9px] font-bold uppercase text-slate-500">Completed</span>
                                        <span className="mt-0.5 block text-sm font-black text-slate-900">{stats.completedCount ?? 0}</span>
                                        <span className="block text-[8px] text-slate-400">confirmed</span>
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 3. OPERATIONAL DETAIL MATRICES (INCOMING PURCHASES & BEST SELLERS) */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left (7 Cols): Real-Time Order Triage Table */}
                    <div className="lg:col-span-7 bg-white rounded-2xl border border-slate-200/90 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3.5 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Incoming Customer Orders</h3>
                                <p className="text-xs text-slate-400 font-sans">Awaiting packaging and courier handover</p>
                            </div>
                            <Link href={route('seller.orders.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-sans uppercase">
                                <span>Manage All</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {recentOrders.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-sans">No incoming orders at the moment.</p>
                        ) : (
                            <div className="divide-y divide-slate-100 space-y-3">
                                {recentOrders.map((item) => (
                                    <div key={item.id} className="pt-3 first:pt-0 flex items-center justify-between gap-4 font-sans text-xs">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={item.items?.[0]?.product?.featured_image || ''}
                                                alt=""
                                                className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate font-sans text-xs">{item.items?.[0]?.product?.name || 'Purchased items'}{(item.items?.length ?? 0) > 1 ? ` + ${(item.items?.length ?? 0) - 1} more` : ''}</p>
                                                <p className="text-slate-400 text-[11px]">
                                                    Order #{item.order_number} · Units: {(item.items ?? []).reduce((sum, line) => sum + line.quantity, 0)}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-3 shrink-0">
                                            <div className="text-right space-y-0.5">
                                                <span className="font-black text-slate-900 font-sans text-sm block">{formatPrice((item.items ?? []).reduce((sum, line) => sum + Number(line.subtotal), 0))}</span>
                                                <span className="inline-block px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px] uppercase font-bold">
                                                    {item.status || 'Unknown'}
                                                </span>
                                            </div>
                                            <Link
                                                href={route('seller.orders.index')}
                                                className="px-2.5 py-1.5 rounded-lg bg-[#E00D42] hover:bg-[#C20836] text-white text-[11px] font-bold font-sans transition shadow-2xs cursor-pointer"
                                                title="Open in Orders Fulfillment"
                                            >
                                                Manage
                                            </Link>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right (5 Cols): Top completed products */}
                    <div className="lg:col-span-5 bg-white rounded-2xl border border-slate-200/90 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3.5 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Top completed products</h3>
                                <p className="text-xs text-slate-400 font-sans">Ranked by buyer-confirmed units</p>
                            </div>
                            <Link href={route('seller.products.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-sans uppercase">
                                <span>Catalog</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {topProducts.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-sans">No products published in catalog yet.</p>
                        ) : (
                            <div className="divide-y divide-slate-100 space-y-3">
                                {topProducts.map((prod) => (
                                    <div key={prod.id} className="pt-3 first:pt-0 flex items-center justify-between gap-4 font-sans text-xs">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={prod.featured_image || ''}
                                                alt=""
                                                className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate font-sans text-xs">{prod.name}</p>
                                                <p className="text-slate-400 text-[10px]">
                                                    {prod.stock} available · {Number(prod.open_order_units || 0)} reserved · {Number(prod.completed_units || 0)} completed
                                                </p>
                                            </div>
                                        </div>

                                        <span className="font-black text-[#E00D42] font-sans text-sm shrink-0">
                                            {formatPrice(prod.price)}
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
