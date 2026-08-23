import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { OrderItem, Product, Shop } from '@/types';
import { 
    DollarSign, 
    Package, 
    ShoppingCart, 
    Store, 
    ArrowRight, 
    Plus, 
    TrendingUp,
    TrendingDown,
    Star,
    CheckCircle2,
    Truck,
    Clock,
    AlertTriangle,
    FileText,
    ExternalLink,
    Box,
    Layers,
    ArrowUpRight,
    Calendar,
    ChevronRight,
    Users
} from 'lucide-react';

interface Props {
    shop: Shop;
    stats: {
        totalProducts: number;
        lowStockCount: number;
        totalSales: number;
        totalRevenue: number;
        pendingPackCount: number;
        readyPickupCount: number;
        shippedCount: number;
        completedCount: number;
    };
    dailySales: Array<{
        date: string;
        revenue: number;
        units: number;
    }>;
    recentOrders: OrderItem[];
    topProducts: Product[];
}

export default function SellerDashboard({ shop, stats, dailySales, recentOrders, topProducts }: Props) {
    const [timeRange, setTimeRange] = useState<'7d' | '30d'>('7d');
    const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const maxDailyRevenue = Math.max(...dailySales.map(d => d.revenue), 1500);

    // SVG Area Chart points calculation
    const chartHeight = 140;
    const chartWidth = 600;
    const points = dailySales.map((d, idx) => {
        const x = (idx / (dailySales.length - 1)) * chartWidth;
        const y = chartHeight - (d.revenue / maxDailyRevenue) * (chartHeight - 20) - 10;
        return { x, y, data: d };
    });

    const pathD = points.reduce((acc, pt, idx, arr) => {
        if (idx === 0) return `M ${pt.x} ${pt.y}`;
        const prev = arr[idx - 1];
        const cx1 = prev.x + (pt.x - prev.x) / 2;
        const cy1 = prev.y;
        const cx2 = prev.x + (pt.x - prev.x) / 2;
        const cy2 = pt.y;
        return `${acc} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${pt.x} ${pt.y}`;
    }, '');

    const areaD = `${pathD} L ${chartWidth} ${chartHeight} L 0 ${chartHeight} Z`;

    return (
        <DashboardLayout
            title="Seller Overview & Analytics"
            subtitle={`Live telemetry for ${shop.name}`}
            actions={
                <Link
                    href={route('seller.products.index')}
                    className="flex items-center gap-1.5 px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-xl shadow-xs transition uppercase"
                >
                    <Plus className="w-3.5 h-3.5" />
                    <span>List New Product</span>
                </Link>
            }
        >
            <Head title="Seller Overview — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. TOP EXECUTIVE STATS GRID */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    
                    {/* Gross Sales */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold text-slate-500 font-mono uppercase">Total Gross Sales</span>
                            <div className="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                                <DollarSign className="w-4 h-4" />
                            </div>
                        </div>
                        <div>
                            <h3 className="text-2xl font-black text-slate-900 tracking-tight">{formatPrice(stats.totalRevenue)}</h3>
                            <div className="flex items-center gap-1.5 mt-1">
                                <span className="inline-flex items-center gap-0.5 text-emerald-600 text-xs font-bold font-mono">
                                    <TrendingUp className="w-3 h-3" /> +14.8%
                                </span>
                                <span className="text-[11px] text-slate-400 font-mono">vs previous 7 days</span>
                            </div>
                        </div>
                    </div>

                    {/* Total Orders Fulfilled */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold text-slate-500 font-mono uppercase">Units Fulfilled</span>
                            <div className="w-8 h-8 rounded-xl bg-rose-50 text-[#E00D42] flex items-center justify-center">
                                <ShoppingCart className="w-4 h-4" />
                            </div>
                        </div>
                        <div>
                            <h3 className="text-2xl font-black text-slate-900 tracking-tight">{stats.totalSales} items</h3>
                            <div className="flex items-center gap-1.5 mt-1">
                                <span className="inline-flex items-center gap-0.5 text-emerald-600 text-xs font-bold font-mono">
                                    <TrendingUp className="w-3 h-3" /> +8.2%
                                </span>
                                <span className="text-[11px] text-slate-400 font-mono">sales volume</span>
                            </div>
                        </div>
                    </div>

                    {/* Live Catalog Listings */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold text-slate-500 font-mono uppercase">Catalog Listings</span>
                            <div className="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                                <Package className="w-4 h-4" />
                            </div>
                        </div>
                        <div>
                            <h3 className="text-2xl font-black text-slate-900 tracking-tight">{stats.totalProducts} items</h3>
                            <div className="flex items-center gap-1.5 mt-1">
                                <span className="text-slate-500 text-xs font-mono font-bold">14 Master Departments</span>
                            </div>
                        </div>
                    </div>

                    {/* Store Rating & Performance */}
                    <div className="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-2xs space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold text-slate-500 font-mono uppercase">Merchant Rating</span>
                            <div className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                                <Star className="w-4 h-4 fill-amber-400 text-amber-400" />
                            </div>
                        </div>
                        <div>
                            <h3 className="text-2xl font-black text-slate-900 tracking-tight">{Number(shop.rating || 4.95).toFixed(2)} ★</h3>
                            <div className="flex items-center gap-1.5 mt-1">
                                <span className="text-emerald-600 text-xs font-bold font-mono">99% Chat Response Rate</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. ORDER FULFILLMENT ACTION CENTRE (TO-DO PIPELINE) */}
                <div className="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/90 shadow-2xs space-y-4">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono text-xs">
                        <div className="flex items-center gap-2">
                            <Box className="w-4 h-4 text-[#E00D42]" />
                            <span className="font-bold text-slate-900 uppercase">Fulfillment Action Pipeline</span>
                        </div>
                        <Link href={route('seller.orders.index')} className="text-xs font-bold text-[#E00D42] hover:underline uppercase">
                            View All Orders ➔
                        </Link>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 font-sans">
                        <Link
                            href={route('seller.orders.index', { status: 'to_pack' })}
                            className="p-4 rounded-xl bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-amber-400 hover:shadow-sm transition group flex flex-col justify-between"
                        >
                            <div className="flex items-center justify-between text-xs font-mono">
                                <span className="text-slate-500 font-bold uppercase">To Pack</span>
                                <span className="w-2 h-2 rounded-full bg-amber-400"></span>
                            </div>
                            <div className="mt-3">
                                <p className="text-2xl font-black text-slate-900 group-hover:text-amber-600 transition font-sans">{stats.pendingPackCount}</p>
                                <p className="text-[11px] text-slate-500 font-mono mt-0.5">Orders awaiting packing</p>
                            </div>
                        </Link>

                        <Link
                            href={route('seller.orders.index', { status: 'to_pickup' })}
                            className="p-4 rounded-xl bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-indigo-400 hover:shadow-sm transition group flex flex-col justify-between"
                        >
                            <div className="flex items-center justify-between text-xs font-mono">
                                <span className="text-slate-500 font-bold uppercase">Ready Pickup</span>
                                <span className="w-2 h-2 rounded-full bg-indigo-500"></span>
                            </div>
                            <div className="mt-3">
                                <p className="text-2xl font-black text-slate-900 group-hover:text-indigo-600 transition font-sans">{stats.readyPickupCount}</p>
                                <p className="text-[11px] text-slate-500 font-mono mt-0.5">Courier collection pending</p>
                            </div>
                        </Link>

                        <Link
                            href={route('seller.orders.index', { status: 'in_transit' })}
                            className="p-4 rounded-xl bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-blue-400 hover:shadow-sm transition group flex flex-col justify-between"
                        >
                            <div className="flex items-center justify-between text-xs font-mono">
                                <span className="text-slate-500 font-bold uppercase">In Transit</span>
                                <span className="w-2 h-2 rounded-full bg-blue-500"></span>
                            </div>
                            <div className="mt-3">
                                <p className="text-2xl font-black text-slate-900 group-hover:text-blue-600 transition font-sans">{stats.shippedCount}</p>
                                <p className="text-[11px] text-slate-500 font-mono mt-0.5">Out with courier fleet</p>
                            </div>
                        </Link>

                        <Link
                            href={route('seller.orders.index', { status: 'delivered' })}
                            className="p-4 rounded-xl bg-slate-50 hover:bg-white border border-slate-200/80 hover:border-emerald-400 hover:shadow-sm transition group flex flex-col justify-between"
                        >
                            <div className="flex items-center justify-between text-xs font-mono">
                                <span className="text-slate-500 font-bold uppercase">Completed</span>
                                <span className="w-2 h-2 rounded-full bg-emerald-500"></span>
                            </div>
                            <div className="mt-3">
                                <p className="text-2xl font-black text-slate-900 group-hover:text-emerald-600 transition font-sans">{stats.completedCount}</p>
                                <p className="text-[11px] text-slate-500 font-mono mt-0.5">Delivered & confirmed</p>
                            </div>
                        </Link>
                    </div>
                </div>

                {/* 3. MODERN SALES ANALYTICS CHART & STORE PERFORMANCE */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Interactive Sales Velocity Chart */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs space-y-4">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-slate-900 text-sm">Revenue Velocity & Daily Trend</h3>
                                <p className="text-xs text-slate-500 font-mono">Daily transaction volume breakdown in Philippine Pesos (₱)</p>
                            </div>

                            <div className="flex items-center gap-2 font-mono text-xs">
                                <button
                                    type="button"
                                    onClick={() => setTimeRange('7d')}
                                    className={`px-3 py-1 rounded-lg font-bold uppercase transition ${
                                        timeRange === '7d' ? 'bg-[#E00D42] text-white shadow-xs' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                    }`}
                                >
                                    Last 7 Days
                                </button>
                                <Link
                                    href={route('seller.reports')}
                                    className="px-3 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold uppercase transition"
                                >
                                    Full Statement
                                </Link>
                            </div>
                        </div>

                        {/* Modern SVG Area Chart */}
                        <div className="pt-4">
                            <div className="relative w-full h-44">
                                <svg
                                    viewBox={`0 0 ${chartWidth} ${chartHeight}`}
                                    className="w-full h-full overflow-visible"
                                    preserveAspectRatio="none"
                                >
                                    <defs>
                                        <linearGradient id="revenueGradient" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor="#E00D42" stopOpacity="0.25" />
                                            <stop offset="100%" stopColor="#E00D42" stopOpacity="0.0" />
                                        </linearGradient>
                                    </defs>

                                    {/* Grid Lines */}
                                    <line x1="0" y1={chartHeight * 0.25} x2={chartWidth} y2={chartHeight * 0.25} stroke="#E2E8F0" strokeDasharray="4" />
                                    <line x1="0" y1={chartHeight * 0.50} x2={chartWidth} y2={chartHeight * 0.50} stroke="#E2E8F0" strokeDasharray="4" />
                                    <line x1="0" y1={chartHeight * 0.75} x2={chartWidth} y2={chartHeight * 0.75} stroke="#E2E8F0" strokeDasharray="4" />

                                    {/* Filled Area */}
                                    <path d={areaD} fill="url(#revenueGradient)" />

                                    {/* Smooth Curve Line */}
                                    <path d={pathD} fill="none" stroke="#E00D42" strokeWidth="2.5" strokeLinecap="round" />

                                    {/* Data Points */}
                                    {points.map((pt, idx) => (
                                        <g key={idx}>
                                            <circle
                                                cx={pt.x}
                                                cy={pt.y}
                                                r={hoveredIndex === idx ? 6 : 4}
                                                fill="#FFFFFF"
                                                stroke="#E00D42"
                                                strokeWidth="2.5"
                                                className="cursor-pointer transition-all duration-200"
                                                onMouseEnter={() => setHoveredIndex(idx)}
                                                onMouseLeave={() => setHoveredIndex(null)}
                                            />
                                        </g>
                                    ))}
                                </svg>

                                {/* Tooltip for hovered data point */}
                                {hoveredIndex !== null && points[hoveredIndex] && (
                                    <div 
                                        className="absolute bg-slate-900 text-white px-2.5 py-1.5 rounded-lg shadow-lg text-[11px] font-mono pointer-events-none transform -translate-x-1/2 -translate-y-12 transition-all duration-150 z-10"
                                        style={{ 
                                            left: `${(points[hoveredIndex].x / chartWidth) * 100}%`,
                                            top: `${(points[hoveredIndex].y / chartHeight) * 100}%` 
                                        }}
                                    >
                                        <p className="font-bold">{formatPrice(points[hoveredIndex].data.revenue)}</p>
                                        <p className="text-[9px] text-slate-400">{points[hoveredIndex].data.units} units sold</p>
                                    </div>
                                )}
                            </div>

                            {/* X-Axis Date Labels */}
                            <div className="flex justify-between items-center pt-2 font-mono text-[10px] text-slate-400 font-bold uppercase">
                                {dailySales.map((d, idx) => (
                                    <span key={idx}>{d.date}</span>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Store Health & Logistics Telemetry */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-6 border border-slate-200/90 shadow-2xs space-y-5 flex flex-col justify-between">
                        <div>
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                                <h3 className="font-bold text-slate-900 text-sm">Store Logistics Health</h3>
                                <span className="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 font-mono text-[10px] font-bold">OPTIMAL</span>
                            </div>

                            <div className="space-y-4 pt-4 font-mono text-xs">
                                <div>
                                    <div className="flex justify-between text-slate-600 mb-1">
                                        <span>Fast Dispatch Rate</span>
                                        <span className="font-bold text-slate-900">98.4%</span>
                                    </div>
                                    <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div className="bg-emerald-500 h-full rounded-full w-[98.4%]"></div>
                                    </div>
                                </div>

                                <div>
                                    <div className="flex justify-between text-slate-600 mb-1">
                                        <span>On-Time Courier Handover</span>
                                        <span className="font-bold text-slate-900">99.1%</span>
                                    </div>
                                    <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div className="bg-indigo-500 h-full rounded-full w-[99.1%]"></div>
                                    </div>
                                </div>

                                <div>
                                    <div className="flex justify-between text-slate-600 mb-1">
                                        <span>Customer Satisfaction</span>
                                        <span className="font-bold text-slate-900">99.5%</span>
                                    </div>
                                    <div className="w-full bg-slate-100 h-2 rounded-full overflow-hidden">
                                        <div className="bg-[#E00D42] h-full rounded-full w-[99.5%]"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Store Settings Shortcut */}
                        <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-200/80 flex items-center justify-between">
                            <div className="text-xs">
                                <p className="font-bold text-slate-900">Warehouse Hub</p>
                                <p className="text-[11px] text-slate-500 font-mono truncate max-w-[180px]">{shop.address || 'Metro Manila Dispatch'}</p>
                            </div>
                            <Link
                                href={route('seller.settings')}
                                className="px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-700 border border-slate-200 rounded-lg text-xs font-bold font-mono uppercase transition shadow-2xs"
                            >
                                Edit
                            </Link>
                        </div>
                    </div>
                </div>

                {/* 4. RECENT CUSTOMER PURCHASES & TOP PRODUCTS */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left: Recent Customer Orders Table */}
                    <div className="lg:col-span-7 bg-white rounded-2xl border border-slate-200/90 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Recent Customer Purchases</h3>
                                <p className="text-xs text-slate-400 font-mono">Real-time orders awaiting dispatch</p>
                            </div>
                            <Link href={route('seller.orders.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase">
                                <span>Manage All</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {recentOrders.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-mono">No customer orders received yet.</p>
                        ) : (
                            <div className="divide-y divide-slate-100 space-y-3">
                                {recentOrders.map((item) => (
                                    <div key={item.id} className="pt-3 first:pt-0 flex items-center justify-between gap-4 font-mono text-xs">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={item.product?.featured_image || ''}
                                                alt=""
                                                className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate font-sans text-xs">{item.product?.name}</p>
                                                <p className="text-slate-400 text-[11px]">
                                                    Order #{item.order?.order_number} • Qty: {item.quantity}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="text-right shrink-0">
                                            <span className="font-black text-slate-900 font-sans text-sm">{formatPrice(item.subtotal)}</span>
                                            <span className="block text-[10px] text-slate-500 uppercase font-bold">
                                                {item.order?.status || 'Processing'}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right: Top Performing Products */}
                    <div className="lg:col-span-5 bg-white rounded-2xl border border-slate-200/90 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Top Performing Listings</h3>
                                <p className="text-xs text-slate-400 font-mono">Highest revenue generators</p>
                            </div>
                            <Link href={route('seller.products.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase">
                                <span>Catalog</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {topProducts.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-mono">No products published yet.</p>
                        ) : (
                            <div className="divide-y divide-slate-100 space-y-3">
                                {topProducts.map((prod) => (
                                    <div key={prod.id} className="pt-3 first:pt-0 flex items-center justify-between gap-4 font-mono text-xs">
                                        <div className="flex items-center gap-3 min-w-0">
                                            <img
                                                src={prod.featured_image || ''}
                                                alt=""
                                                className="w-12 h-12 rounded-xl object-cover bg-slate-100 border border-slate-200 shrink-0"
                                            />
                                            <div className="truncate space-y-0.5">
                                                <p className="font-bold text-slate-900 truncate font-sans text-xs">{prod.name}</p>
                                                <p className="text-slate-400 text-[10px]">
                                                    Stock: {prod.stock} units • {prod.sales_count ?? 0} Sold
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
