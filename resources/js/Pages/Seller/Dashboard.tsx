import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
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
    Users,
    Printer,
    Sparkles,
    Flame,
    Zap,
    Gauge,
    ShieldCheck,
    Check,
    BarChart3
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
    const [hoveredDay, setHoveredDay] = useState<number | null>(null);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const maxDailyRevenue = Math.max(...dailySales.map(d => d.revenue), 2000);
    const dailyTarget = 30000;
    const todayRevenue = dailySales[dailySales.length - 1]?.revenue || stats.totalRevenue * 0.15;
    const targetProgress = Math.min(100, Math.round((todayRevenue / dailyTarget) * 100));

    return (
        <DashboardLayout
            title="Merchant Command Cockpit"
            subtitle={`Operational workstation & live telemetry for ${shop.name}`}
            actions={
                <div className="flex items-center gap-2">
                    <Link
                        href={route('seller.orders.index')}
                        className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 bg-white hover:bg-slate-100 text-slate-700 text-xs font-bold font-mono rounded-xl border border-slate-200 shadow-2xs transition"
                    >
                        <Printer className="w-3.5 h-3.5 text-[#E00D42]" />
                        <span>Fulfillment Queue</span>
                    </Link>
                    <Link
                        href={route('seller.products.index')}
                        className="flex items-center gap-1.5 px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold font-mono rounded-xl shadow-xs transition uppercase"
                    >
                        <Plus className="w-3.5 h-3.5" />
                        <span>New Listing</span>
                    </Link>
                </div>
            }
        >
            <Head title="Merchant Cockpit — BagooPH" />

            <div className="space-y-6 font-sans">
                
                {/* 1. MERCHANT COMMAND STRIP (LIVE NETWORK PULSE) */}
                <div className="bg-slate-900 text-white rounded-2xl p-4 sm:p-5 shadow-md flex flex-col md:flex-row items-start md:items-center justify-between gap-4 border border-slate-800">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-xl bg-[#E00D42] flex items-center justify-center font-bold text-white shrink-0 shadow-xs">
                            <Gauge className="w-5 h-5" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                <span className="text-[11px] font-mono font-bold text-emerald-400 uppercase tracking-wider">Store Status: Online & Accepting Orders</span>
                            </div>
                            <p className="text-xs text-slate-300 font-sans mt-0.5">
                                Instant courier dispatch active • <strong>{stats.pendingPackCount + stats.readyPickupCount}</strong> parcels require action today
                            </p>
                        </div>
                    </div>

                    {/* Quick Telemetry Pills */}
                    <div className="flex flex-wrap items-center gap-2 font-mono text-xs">
                        <div className="px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 flex items-center gap-2">
                            <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                            <span className="text-white font-bold">{Number(shop.rating || 4.95).toFixed(2)} ★</span>
                            <span className="text-[10px] text-slate-400">Score</span>
                        </div>
                        <div className="px-3 py-1.5 rounded-xl bg-slate-800 border border-slate-700 flex items-center gap-2">
                            <Package className="w-3.5 h-3.5 text-blue-400" />
                            <span className="text-white font-bold">{stats.totalProducts}</span>
                            <span className="text-[10px] text-slate-400">Catalog</span>
                        </div>
                        <Link
                            href={route('seller.reports')}
                            className="px-3 py-1.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold transition flex items-center gap-1 uppercase"
                        >
                            <span>Ledger</span>
                            <ArrowUpRight className="w-3.5 h-3.5" />
                        </Link>
                    </div>
                </div>

                {/* 2. BENTO MATRIX: REVENUE ENGINE + FULFILLMENT PIPELINE */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* LEFT (8 Cols): Bespoke Revenue Velocity Engine */}
                    <div className="lg:col-span-8 bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-6 flex flex-col justify-between">
                        
                        {/* Revenue Top Banner with Goal Progress */}
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4 pb-5 border-b border-slate-100">
                            <div>
                                <div className="flex items-center gap-2 text-slate-500 font-mono text-xs font-bold uppercase">
                                    <DollarSign className="w-4 h-4 text-[#E00D42]" />
                                    <span>Gross Sales Velocity</span>
                                </div>
                                <div className="flex items-baseline gap-3 mt-1">
                                    <h2 className="text-3xl sm:text-4xl font-black text-slate-900 tracking-tight tabular-nums font-mono">
                                        {formatPrice(stats.totalRevenue)}
                                    </h2>
                                    <span className="inline-flex items-center gap-0.5 text-emerald-600 text-xs font-bold font-mono bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-200">
                                        <TrendingUp className="w-3 h-3" /> +16.4%
                                    </span>
                                </div>
                            </div>

                            {/* Daily Target Meter */}
                            <div className="sm:text-right font-mono text-xs space-y-1">
                                <div className="flex items-center sm:justify-end gap-2 text-slate-600">
                                    <span className="text-[11px]">Today's Goal:</span>
                                    <strong className="text-slate-900">{formatPrice(todayRevenue)} / {formatPrice(dailyTarget)}</strong>
                                </div>
                                <div className="w-44 bg-slate-100 h-2 rounded-full overflow-hidden sm:ml-auto">
                                    <div className="bg-gradient-to-r from-amber-500 to-[#E00D42] h-full rounded-full transition-all duration-500" style={{ width: `${targetProgress}%` }}></div>
                                </div>
                                <span className="text-[10px] text-slate-400 block">{targetProgress}% of daily quota reached</span>
                            </div>
                        </div>

                        {/* Interactive 7-Day Velocity Bar Matrix */}
                        <div className="space-y-2 pt-2">
                            <div className="flex items-center justify-between text-xs font-mono text-slate-500">
                                <span>7-Day Transaction Matrix</span>
                                <span className="text-[11px] text-slate-400">Hover bars for details</span>
                            </div>

                            <div className="grid grid-cols-7 gap-2.5 sm:gap-4 items-end h-40 pt-4 px-1">
                                {dailySales.map((day, idx) => {
                                    const heightPct = Math.max(15, Math.round((day.revenue / maxDailyRevenue) * 100));
                                    const isHovered = hoveredDay === idx;
                                    return (
                                        <div
                                            key={idx}
                                            className="relative flex flex-col items-center h-full justify-end group cursor-pointer"
                                            onMouseEnter={() => setHoveredDay(idx)}
                                            onMouseLeave={() => setHoveredDay(null)}
                                        >
                                            {/* Hover Tooltip Popup */}
                                            {isHovered && (
                                                <div className="absolute -top-12 z-20 bg-slate-900 text-white text-[10px] font-mono px-2.5 py-1.5 rounded-lg shadow-lg whitespace-nowrap pointer-events-none transform -translate-y-1 transition">
                                                    <p className="font-bold">{formatPrice(day.revenue)}</p>
                                                    <p className="text-slate-400">{day.units} units sold</p>
                                                </div>
                                            )}

                                            {/* Bar Container */}
                                            <div className="w-full bg-slate-100 rounded-xl h-full flex items-end p-1 overflow-hidden transition-all duration-200 group-hover:bg-slate-200">
                                                <div
                                                    className={`w-full rounded-lg transition-all duration-300 ${
                                                        isHovered 
                                                            ? 'bg-[#E00D42] shadow-sm' 
                                                            : 'bg-slate-800 group-hover:bg-[#E00D42]'
                                                    }`}
                                                    style={{ height: `${heightPct}%` }}
                                                ></div>
                                            </div>

                                            {/* Date Label */}
                                            <span className="text-[10px] font-mono font-bold text-slate-500 mt-2 uppercase">
                                                {day.date.slice(0, 3)}
                                            </span>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        {/* Secondary Metrics Strip */}
                        <div className="grid grid-cols-3 gap-3 pt-4 border-t border-slate-100 font-mono text-xs">
                            <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
                                <span className="text-[10px] text-slate-500 uppercase block">Units Sold</span>
                                <p className="text-lg font-black text-slate-900 font-sans mt-0.5">{stats.totalSales} items</p>
                            </div>
                            <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
                                <span className="text-[10px] text-slate-500 uppercase block">Average Basket</span>
                                <p className="text-lg font-black text-slate-900 font-sans mt-0.5">{formatPrice(stats.totalSales > 0 ? stats.totalRevenue / stats.totalSales : 0)}</p>
                            </div>
                            <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/80">
                                <span className="text-[10px] text-slate-500 uppercase block">Net Payout (90%)</span>
                                <p className="text-lg font-black text-emerald-600 font-sans mt-0.5">{formatPrice(stats.totalRevenue * 0.9)}</p>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT (4 Cols): Industrial Fulfillment Assembly Track */}
                    <div className="lg:col-span-4 bg-white rounded-2xl p-6 border border-slate-200 shadow-2xs space-y-5 flex flex-col justify-between">
                        
                        <div>
                            <div className="flex items-center justify-between pb-3 border-b border-slate-100 font-mono text-xs">
                                <div className="flex items-center gap-2">
                                    <Box className="w-4 h-4 text-[#E00D42]" />
                                    <span className="font-bold text-slate-900 uppercase">Warehouse Assembly Line</span>
                                </div>
                                <Link href={route('seller.orders.index')} className="text-[#E00D42] hover:underline font-bold uppercase text-[11px]">
                                    Queue ➔
                                </Link>
                            </div>

                            {/* Connected Pipeline Steps */}
                            <div className="space-y-3 pt-3 font-sans">
                                
                                <Link
                                    href={route('seller.orders.index', { status: 'to_pack' })}
                                    className="p-3.5 rounded-xl bg-slate-50 hover:bg-amber-50/50 border border-slate-200/90 hover:border-amber-400 transition flex items-center justify-between group"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center font-bold text-xs font-mono">
                                            01
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-slate-900 group-hover:text-amber-800">To Pack & Stage</p>
                                            <p className="text-[10px] text-slate-500 font-mono">Assemble parcel contents</p>
                                        </div>
                                    </div>
                                    <span className="px-2.5 py-1 rounded-lg bg-amber-500 text-white font-mono text-xs font-black shadow-2xs">
                                        {stats.pendingPackCount}
                                    </span>
                                </Link>

                                <Link
                                    href={route('seller.orders.index', { status: 'to_pickup' })}
                                    className="p-3.5 rounded-xl bg-slate-50 hover:bg-indigo-50/50 border border-slate-200/90 hover:border-indigo-400 transition flex items-center justify-between group"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-indigo-100 text-indigo-800 flex items-center justify-center font-bold text-xs font-mono">
                                            02
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-slate-900 group-hover:text-indigo-800">Thermal Label Attached</p>
                                            <p className="text-[10px] text-slate-500 font-mono">Awaiting courier handover</p>
                                        </div>
                                    </div>
                                    <span className="px-2.5 py-1 rounded-lg bg-indigo-600 text-white font-mono text-xs font-black shadow-2xs">
                                        {stats.readyPickupCount}
                                    </span>
                                </Link>

                                <Link
                                    href={route('seller.orders.index', { status: 'in_transit' })}
                                    className="p-3.5 rounded-xl bg-slate-50 hover:bg-blue-50/50 border border-slate-200/90 hover:border-blue-400 transition flex items-center justify-between group"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-blue-100 text-blue-800 flex items-center justify-center font-bold text-xs font-mono">
                                            03
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-slate-900 group-hover:text-blue-800">In Transit with Fleet</p>
                                            <p className="text-[10px] text-slate-500 font-mono">En route to customer</p>
                                        </div>
                                    </div>
                                    <span className="px-2.5 py-1 rounded-lg bg-blue-600 text-white font-mono text-xs font-black shadow-2xs">
                                        {stats.shippedCount}
                                    </span>
                                </Link>

                                <Link
                                    href={route('seller.orders.index', { status: 'delivered' })}
                                    className="p-3.5 rounded-xl bg-slate-50 hover:bg-emerald-50/50 border border-slate-200/90 hover:border-emerald-400 transition flex items-center justify-between group"
                                >
                                    <div className="flex items-center gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-800 flex items-center justify-center font-bold text-xs font-mono">
                                            04
                                        </div>
                                        <div>
                                            <p className="text-xs font-bold text-slate-900 group-hover:text-emerald-800">Delivered & Confirmed</p>
                                            <p className="text-[10px] text-slate-500 font-mono">Payout unlocked</p>
                                        </div>
                                    </div>
                                    <span className="px-2.5 py-1 rounded-lg bg-emerald-600 text-white font-mono text-xs font-black shadow-2xs">
                                        {stats.completedCount}
                                    </span>
                                </Link>
                            </div>
                        </div>

                        {/* Dispatch Telemetry Footer */}
                        <div className="p-3 rounded-xl bg-slate-50 border border-slate-200/80 font-mono text-xs space-y-1.5">
                            <div className="flex justify-between text-slate-600">
                                <span>Fast Dispatch Compliance</span>
                                <strong className="text-emerald-600">98.4%</strong>
                            </div>
                            <div className="w-full bg-slate-200 h-1.5 rounded-full overflow-hidden">
                                <div className="bg-emerald-500 h-full rounded-full w-[98.4%]"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 3. RECENT INCOMING PURCHASES RADAR & CATALOG MATRIX */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
                    
                    {/* Left (7 Cols): Real-Time Order Triage Table */}
                    <div className="lg:col-span-7 bg-white rounded-2xl border border-slate-200 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Incoming Customer Orders</h3>
                                <p className="text-xs text-slate-400 font-mono">Awaiting packaging and courier handover</p>
                            </div>
                            <Link href={route('seller.orders.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase">
                                <span>Manage All</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {recentOrders.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-mono">No incoming orders at the moment.</p>
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

                                        <div className="text-right shrink-0 space-y-1">
                                            <span className="font-black text-slate-900 font-sans text-sm block">{formatPrice(item.subtotal)}</span>
                                            <span className="inline-block px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px] uppercase font-bold">
                                                {item.order?.status || 'Processing'}
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Right (5 Cols): Top Velocity Products */}
                    <div className="lg:col-span-5 bg-white rounded-2xl border border-slate-200 p-6 space-y-4 shadow-2xs">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div>
                                <h3 className="font-bold text-sm text-slate-900">Inventory Velocity Leaders</h3>
                                <p className="text-xs text-slate-400 font-mono">High conversion SKU listings</p>
                            </div>
                            <Link href={route('seller.products.index')} className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase">
                                <span>Catalog</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {topProducts.length === 0 ? (
                            <p className="text-xs text-slate-400 py-8 text-center font-mono">No products published in catalog yet.</p>
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
