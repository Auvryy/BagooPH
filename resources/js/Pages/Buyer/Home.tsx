import React, { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Category, PaginatedData, Product } from '@/types';
import { 
    Zap, 
    Truck, 
    ShieldCheck, 
    Tag, 
    TrendingUp, 
    Globe, 
    Coins, 
    Crown, 
    Star, 
    ChevronLeft, 
    ChevronRight, 
    ShoppingBag, 
    ShoppingCart, 
    Check, 
    Clock, 
    ArrowRight,
    Flame,
    Store,
    Sparkles,
    Laptop,
    Shirt,
    Compass,
    Footprints,
    Home as HomeIcon,
    Smartphone,
    Headphones,
    Activity,
    Watch,
    Car,
    PenTool,
    HeartPulse
} from 'lucide-react';

interface Banner {
    id: number;
    title: string;
    subtitle: string;
    tag: string;
    code: string;
    image: string;
    cta: string;
    badge: string;
    bgGradient: string;
}

interface QuickService {
    id: string;
    name: string;
    icon: string;
    color: string;
    tag: string;
}

interface FlashDeal {
    id: number;
    name: string;
    slug: string;
    price: number;
    compare_at_price: number;
    discount_pct: number;
    claimed_percent: number;
    stock: number;
    featured_image: string;
    category_name: string;
}

interface Voucher {
    code: string;
    discount: string;
    min_spend: number;
    description: string;
    expires: string;
}

interface ActiveShipment {
    order_id: number;
    order_number: string;
    status: string;
    tracking_number: string;
    courier_name: string;
    item_name: string;
    item_count: number;
    estimated_delivery: string;
}

interface Props {
    banners: Banner[];
    quickServices: QuickService[];
    flashDeals: FlashDeal[];
    categories: (Category & { products_count?: number })[];
    feedProducts: PaginatedData<Product>;
    vouchers: Voucher[];
    activeShipment?: ActiveShipment | null;
    filters: {
        search?: string;
        category?: string;
        tab?: string;
    };
}

export default function BuyerHome({
    banners,
    quickServices,
    flashDeals,
    categories,
    feedProducts,
    vouchers,
    activeShipment,
    filters,
}: Props) {
    const [currentBannerIndex, setCurrentBannerIndex] = useState(0);
    const [addingProductId, setAddingProductId] = useState<number | null>(null);
    const [addedSuccessId, setAddedSuccessId] = useState<number | null>(null);
    const [countdown, setCountdown] = useState({ hours: '02', minutes: '45', seconds: '30' });
    const [activeTab, setActiveTab] = useState(filters.tab || 'all');
    const [copiedVoucher, setCopiedVoucher] = useState<string | null>(null);

    // Auto-advance hero carousel
    useEffect(() => {
        const interval = setInterval(() => {
            setCurrentBannerIndex((prev) => (prev + 1) % banners.length);
        }, 5000);
        return () => clearInterval(interval);
    }, [banners.length]);

    // Flash sale countdown timer
    useEffect(() => {
        const timer = setInterval(() => {
            const now = new Date();
            const endOfDay = new Date();
            endOfDay.setHours(23, 59, 59);
            const diff = Math.max(0, endOfDay.getTime() - now.getTime());

            const hours = Math.floor((diff / (1000 * 60 * 60)) % 24).toString().padStart(2, '0');
            const minutes = Math.floor((diff / (1000 * 60)) % 60).toString().padStart(2, '0');
            const seconds = Math.floor((diff / 1000) % 60).toString().padStart(2, '0');

            setCountdown({ hours, minutes, seconds });
        }, 1000);

        return () => clearInterval(timer);
    }, []);

    const handleAddToCart = (e: React.MouseEvent, productId: number) => {
        e.preventDefault();
        e.stopPropagation();
        setAddingProductId(productId);

        router.post(route('cart.store'), {
            product_id: productId,
            quantity: 1,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setAddingProductId(null);
                setAddedSuccessId(productId);
                setTimeout(() => setAddedSuccessId(null), 2000);
            },
            onError: () => {
                setAddingProductId(null);
            },
        });
    };

    const handleTabChange = (tab: string) => {
        setActiveTab(tab);
        router.get(route('buyer.index'), {
            ...filters,
            tab: tab !== 'all' ? tab : undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const copyVoucherCode = (code: string) => {
        navigator.clipboard.writeText(code);
        setCopiedVoucher(code);
        setTimeout(() => setCopiedVoucher(null), 2000);
    };

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    const getServiceIcon = (iconName: string) => {
        switch (iconName) {
            case 'Truck': return Truck;
            case 'Zap': return Zap;
            case 'ShieldCheck': return ShieldCheck;
            case 'Tag': return Tag;
            case 'TrendingUp': return TrendingUp;
            case 'Globe': return Globe;
            case 'Coins': return Coins;
            case 'Crown': return Crown;
            default: return Sparkles;
        }
    };

    const getCategoryIcon = (iconName?: string | null) => {
        switch (iconName?.toLowerCase()) {
            case 'laptop':
            case 'consumer-electronics': return Laptop;
            case 'shirt':
            case 'apparel-and-footwear': return Shirt;
            case 'compass':
            case 'travel-and-accessories': return Compass;
            case 'footprints':
            case 'footwear-and-sneakers': return Footprints;
            case 'home':
            case 'smart-home-and-living': return HomeIcon;
            case 'sparkles':
            case 'beauty-and-grooming': return Sparkles;
            case 'smartphone':
            case 'mobile-and-gadgets': return Smartphone;
            case 'headphones':
            case 'audio-and-spatial-sound': return Headphones;
            case 'activity':
            case 'sports-and-outdoor': return Activity;
            case 'watch':
            case 'watches-and-edc': return Watch;
            case 'car':
            case 'automotive-essentials': return Car;
            case 'pentool':
            case 'desk-studio-and-stationery': return PenTool;
            case 'heartpulse':
            case 'health-and-wellness': return HeartPulse;
            default: return ShoppingBag;
        }
    };

    return (
        <BuyerLayout categories={categories}>
            <Head title="Shopee/SHEIN-Style Buyer Marketplace — BagooPH" />

            <div className="space-y-6">

                {/* 1. ACTIVE ORDER TELEMETRY STRIP (IF BUYER HAS IN-TRANSIT PACKAGE) */}
                {activeShipment && (
                    <div className="p-4 rounded-xl bg-slate-900 text-white flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-md border border-slate-800 font-mono text-xs">
                        <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-lg bg-[#E00D42] text-white flex items-center justify-center shrink-0">
                                <Truck className="w-5 h-5 animate-bounce" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2 text-[10px] text-emerald-400 font-bold uppercase">
                                    <span>● LIVE DISPATCH TELEMETRY</span>
                                    <span>•</span>
                                    <span>{activeShipment.status.replace('_', ' ').toUpperCase()}</span>
                                </div>
                                <p className="font-bold text-white text-xs">
                                    Order #{activeShipment.order_number} ({activeShipment.item_name}) — Est. Arrival: <strong className="text-white">{activeShipment.estimated_delivery}</strong>
                                </p>
                            </div>
                        </div>

                        <Link
                            href={route('buyer.orders.show', activeShipment.order_id)}
                            className="px-3.5 py-1.5 bg-white hover:bg-[#E00D42] text-slate-900 hover:text-white rounded-lg font-bold text-[11px] uppercase transition shrink-0 flex items-center gap-1.5"
                        >
                            <span>Track Package</span>
                            <ArrowRight className="w-3 h-3" />
                        </Link>
                    </div>
                )}

                {/* 2. HERO PROMOTIONAL BANNER CAROUSEL (SHOPEE / SHEIN STAGE) */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
                    
                    {/* Main Sliding Carousel Banner */}
                    <div className="lg:col-span-8 relative rounded-2xl overflow-hidden shadow-lg h-[260px] sm:h-[340px] group bg-black">
                        {banners.map((banner, index) => (
                            <div
                                key={banner.id}
                                className={`absolute inset-0 transition-opacity duration-700 ease-in-out ${
                                    index === currentBannerIndex ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'
                                }`}
                            >
                                <img
                                    src={banner.image}
                                    alt={banner.title}
                                    className="w-full h-full object-cover opacity-60"
                                />
                                <div className={`absolute inset-0 bg-gradient-to-r ${banner.bgGradient} opacity-80 mix-blend-multiply`}></div>

                                {/* Banner Text Content */}
                                <div className="absolute inset-0 p-6 sm:p-10 flex flex-col justify-between text-white z-20">
                                    <div>
                                        <span className="inline-block px-2.5 py-1 rounded bg-[#E00D42] text-white font-mono text-[10px] font-bold uppercase tracking-wider mb-2 shadow-xs">
                                            {banner.badge}
                                        </span>
                                        <h2 className="text-2xl sm:text-4xl font-black tracking-tight leading-tight max-w-xl">
                                            {banner.title}
                                        </h2>
                                        <p className="text-xs sm:text-sm text-white/80 font-mono mt-1 uppercase">
                                            {banner.subtitle}
                                        </p>
                                    </div>

                                    <div className="flex items-center gap-3 pt-4">
                                        <button
                                            type="button"
                                            onClick={() => copyVoucherCode(banner.code)}
                                            className="px-5 py-2.5 bg-white hover:bg-[#E00D42] text-slate-900 hover:text-white rounded-lg font-mono text-xs font-black uppercase tracking-wider transition shadow-md flex items-center gap-2"
                                        >
                                            {copiedVoucher === banner.code ? (
                                                <>
                                                    <Check className="w-4 h-4 text-emerald-600" />
                                                    <span>VOUCHER COPIED!</span>
                                                </>
                                            ) : (
                                                <>
                                                    <Tag className="w-4 h-4 text-[#E00D42]" />
                                                    <span>USE CODE: {banner.code}</span>
                                                </>
                                            )}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}

                        {/* Carousel Arrows */}
                        <button
                            onClick={() => setCurrentBannerIndex((prev) => (prev - 1 + banners.length) % banners.length)}
                            className="absolute left-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/40 hover:bg-black/80 text-white flex items-center justify-center z-30 transition opacity-0 group-hover:opacity-100"
                        >
                            <ChevronLeft className="w-5 h-5" />
                        </button>
                        <button
                            onClick={() => setCurrentBannerIndex((prev) => (prev + 1) % banners.length)}
                            className="absolute right-3 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full bg-black/40 hover:bg-black/80 text-white flex items-center justify-center z-30 transition opacity-0 group-hover:opacity-100"
                        >
                            <ChevronRight className="w-5 h-5" />
                        </button>

                        {/* Carousel Dots */}
                        <div className="absolute bottom-3 left-1/2 -translate-x-1/2 flex items-center gap-1.5 z-30">
                            {banners.map((_, i) => (
                                <button
                                    key={i}
                                    onClick={() => setCurrentBannerIndex(i)}
                                    className={`h-2 rounded-full transition-all ${
                                        i === currentBannerIndex ? 'w-6 bg-[#E00D42]' : 'w-2 bg-white/50'
                                    }`}
                                />
                            ))}
                        </div>
                    </div>

                    {/* Right Secondary Promo Cards */}
                    <div className="lg:col-span-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-1 gap-3">
                        <div className="p-4 rounded-2xl bg-gradient-to-br from-rose-600 to-rose-900 text-white flex flex-col justify-between shadow-md relative overflow-hidden h-[120px] sm:h-[165px]">
                            <div>
                                <span className="text-[10px] font-mono uppercase tracking-wider font-bold bg-black/30 px-2 py-0.5 rounded text-white/90">
                                    FLASH SALE HOTLINE
                                </span>
                                <h3 className="text-base font-black tracking-tight mt-1">
                                    Up to 70% Slashed Prices
                                </h3>
                                <p className="text-[11px] text-white/80 font-mono">Starts every 3 hours with limited slots.</p>
                            </div>
                            <span className="text-xs font-bold text-white/90 flex items-center gap-1">
                                Explore Flash Deals <ChevronRight className="w-3.5 h-3.5" />
                            </span>
                        </div>

                        <div className="p-4 rounded-2xl bg-gradient-to-br from-indigo-800 to-slate-950 text-white flex flex-col justify-between shadow-md relative overflow-hidden h-[120px] sm:h-[165px]">
                            <div>
                                <span className="text-[10px] font-mono uppercase tracking-wider font-bold bg-white/20 px-2 py-0.5 rounded text-white/90">
                                    NEW BUYER PRIVILEGE
                                </span>
                                <h3 className="text-base font-black tracking-tight mt-1">
                                    Free Shipping ₱0 Min Spend
                                </h3>
                                <p className="text-[11px] text-white/80 font-mono">Valid on your first 3 platform checkouts.</p>
                            </div>
                            <span className="text-xs font-bold text-white/90 flex items-center gap-1">
                                Claim Shipping Pass <ChevronRight className="w-3.5 h-3.5" />
                            </span>
                        </div>
                    </div>
                </div>

                {/* 3. 8 QUICK-SERVICE ICON ACTIONS (SHOPEE STYLE) */}
                <div className="bg-white rounded-2xl p-4 sm:p-6 shadow-xs border border-slate-100">
                    <div className="grid grid-cols-4 sm:grid-cols-8 gap-4 text-center">
                        {quickServices.map((service) => {
                            const IconComponent = getServiceIcon(service.icon);
                            return (
                                <button
                                    key={service.id}
                                    onClick={() => handleTabChange(service.id === 'flash' ? 'flash' : 'all')}
                                    className="flex flex-col items-center group focus:outline-hidden"
                                >
                                    <div className={`w-12 h-12 rounded-2xl ${service.color} text-white flex items-center justify-center shadow-md group-hover:scale-110 transition-transform duration-300 relative`}>
                                        <IconComponent className="w-6 h-6" />
                                    </div>
                                    <span className="text-xs font-bold text-slate-800 mt-2 truncate w-full group-hover:text-[#E00D42] transition">
                                        {service.name}
                                    </span>
                                    <span className="text-[10px] text-slate-400 font-mono">{service.tag}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* 4. ⚡ FLASH DEALS SECTION (WITH LIVE COUNTDOWN TIMER & PROGRESS) */}
                <div className="bg-white rounded-2xl p-6 shadow-xs border border-slate-100 space-y-4">
                    {/* Flash Sale Header */}
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-1 text-[#E00D42] font-black text-lg tracking-tight font-mono">
                                <Zap className="w-5 h-5 fill-[#E00D42]" />
                                <span>FLASH DEALS</span>
                            </div>

                            {/* Countdown Clock */}
                            <div className="flex items-center gap-1 font-mono text-xs font-black">
                                <span className="px-2 py-1 rounded bg-black text-white">{countdown.hours}</span>
                                <span>:</span>
                                <span className="px-2 py-1 rounded bg-black text-white">{countdown.minutes}</span>
                                <span>:</span>
                                <span className="px-2 py-1 rounded bg-[#E00D42] text-white">{countdown.seconds}</span>
                            </div>
                        </div>

                        <Link
                            href={route('products.index')}
                            className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-1 font-mono uppercase"
                        >
                            <span>See All Deals</span>
                            <ChevronRight className="w-4 h-4" />
                        </Link>
                    </div>

                    {/* Flash Sale Horizontal Products Carousel */}
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3.5">
                        {flashDeals.map((deal) => (
                            <Link
                                key={deal.id}
                                href={route('buyer.products.show', deal.slug)}
                                className="group bg-slate-50 hover:bg-white rounded-xl p-2.5 border border-slate-100 hover:border-slate-300 hover:shadow-md transition flex flex-col justify-between relative"
                            >
                                <div className="space-y-2">
                                    {/* Thumbnail */}
                                    <div className="aspect-square rounded-lg bg-white overflow-hidden relative">
                                        <img
                                            src={deal.featured_image}
                                            alt={deal.name}
                                            className="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                        />
                                        <span className="absolute top-1.5 right-1.5 px-1.5 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[9px] font-black">
                                            -{deal.discount_pct}%
                                        </span>
                                    </div>

                                    {/* Product Title */}
                                    <h4 className="text-xs font-bold text-slate-800 line-clamp-1 group-hover:text-[#E00D42] transition">
                                        {deal.name}
                                    </h4>

                                    {/* Slashed Price */}
                                    <div>
                                        <span className="text-sm font-black text-[#E00D42]">
                                            {formatPrice(deal.price)}
                                        </span>
                                        <span className="text-[10px] text-slate-400 line-through block">
                                            {formatPrice(deal.compare_at_price)}
                                        </span>
                                    </div>
                                </div>

                                {/* Claimed Progress Bar */}
                                <div className="mt-2 space-y-1">
                                    <div className="w-full bg-slate-200 h-2 rounded-full overflow-hidden">
                                        <div 
                                            className="bg-gradient-to-r from-amber-500 to-[#E00D42] h-full rounded-full"
                                            style={{ width: `${deal.claimed_percent}%` }}
                                        ></div>
                                    </div>
                                    <div className="flex items-center justify-between text-[9px] font-mono text-slate-500 font-bold uppercase">
                                        <span className="flex items-center gap-0.5 text-[#E00D42]">
                                            <Flame className="w-3 h-3 fill-[#E00D42]" /> {deal.claimed_percent}% SOLD
                                        </span>
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>

                {/* 5. 14 MASTER CATEGORIES VISUAL GRID */}
                <div className="bg-white rounded-2xl p-6 shadow-xs border border-slate-100 space-y-4">
                    <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                        <div>
                            <h3 className="font-bold text-slate-900 text-base">Categories Directory</h3>
                            <p className="text-xs text-slate-500 font-mono">14 Verified Product Departments</p>
                        </div>

                        <Link href={route('products.index')} className="text-xs font-bold text-[#E00D42] hover:underline font-mono uppercase">
                            View All ➔
                        </Link>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-3 font-sans">
                        {categories.map((cat) => {
                            const IconComponent = getCategoryIcon(cat.slug);
                            return (
                                <Link
                                    key={cat.id}
                                    href={route('buyer.index', { category: cat.slug })}
                                    className="p-3 rounded-xl bg-slate-50 hover:bg-white border border-slate-100 hover:border-[#E00D42]/40 hover:shadow-md transition text-center group flex flex-col items-center justify-between"
                                >
                                    <div className="w-10 h-10 rounded-xl bg-white text-slate-700 group-hover:text-[#E00D42] group-hover:scale-110 transition flex items-center justify-center shadow-2xs">
                                        <IconComponent className="w-5 h-5" />
                                    </div>
                                    <span className="text-xs font-bold text-slate-800 mt-2 truncate w-full group-hover:text-[#E00D42] transition">
                                        {cat.name}
                                    </span>
                                    <span className="text-[10px] text-slate-400 font-mono mt-0.5">
                                        {cat.products_count ?? 0} items
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                </div>

                {/* 6. "DAILY DISCOVER" / FOR YOU E-COMMERCE FEED */}
                <div className="space-y-4">
                    {/* Feed Tabs */}
                    <div className="bg-white rounded-xl p-2 shadow-xs border border-slate-100 flex items-center gap-2 overflow-x-auto scrollbar-none font-mono text-xs">
                        <button
                            onClick={() => handleTabChange('all')}
                            className={`px-4 py-2 rounded-lg font-bold uppercase transition ${
                                activeTab === 'all' 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            Daily Discover
                        </button>
                        <button
                            onClick={() => handleTabChange('top_sales')}
                            className={`px-4 py-2 rounded-lg font-bold uppercase transition ${
                                activeTab === 'top_sales' 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            Top Sales
                        </button>
                        <button
                            onClick={() => handleTabChange('top_rated')}
                            className={`px-4 py-2 rounded-lg font-bold uppercase transition ${
                                activeTab === 'top_rated' 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            Top Rated
                        </button>
                        <button
                            onClick={() => handleTabChange('new_arrivals')}
                            className={`px-4 py-2 rounded-lg font-bold uppercase transition ${
                                activeTab === 'new_arrivals' 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-700 hover:bg-slate-100'
                            }`}
                        >
                            New Arrivals
                        </button>
                    </div>

                    {/* Feed Product Grid */}
                    {feedProducts.data.length === 0 ? (
                        <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-100">
                            <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                            <h3 className="text-base font-bold text-slate-800">No products found</h3>
                            <p className="text-xs text-slate-500">Try clearing filters or search query.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                            {feedProducts.data.map((product) => {
                                const isAdding = addingProductId === product.id;
                                const isSuccess = addedSuccessId === product.id;
                                const priceNum = Number(product.price);
                                const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
                                const discountPct = compareNum && compareNum > priceNum 
                                    ? Math.round(((compareNum - priceNum) / compareNum) * 100)
                                    : null;

                                return (
                                    <div
                                        key={product.id}
                                        className="group bg-white rounded-xl border border-slate-200 overflow-hidden hover:shadow-xl hover:border-[#E00D42]/50 transition duration-300 flex flex-col justify-between"
                                    >
                                        <Link href={route('buyer.products.show', product.slug)} className="block relative aspect-square bg-slate-100 overflow-hidden">
                                            <img
                                                src={product.featured_image || ''}
                                                alt={product.name}
                                                className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                            />

                                            {/* Mall & Discount Badges */}
                                            <div className="absolute top-2 left-2 flex flex-col gap-1">
                                                <span className="px-1.5 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[9px] font-black tracking-wider shadow-2xs">
                                                    MALL
                                                </span>
                                            </div>

                                            {discountPct && (
                                                <span className="absolute top-2 right-2 px-1.5 py-0.5 rounded bg-amber-400 text-slate-950 font-mono text-[9px] font-black shadow-2xs">
                                                    -{discountPct}%
                                                </span>
                                            )}

                                            {/* Free Shipping Tag */}
                                            <div className="absolute bottom-2 left-2">
                                                <span className="px-1.5 py-0.5 rounded bg-emerald-600/90 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase flex items-center gap-0.5 shadow-2xs">
                                                    <Truck className="w-2.5 h-2.5" /> FREE SHIPPING
                                                </span>
                                            </div>
                                        </Link>

                                        {/* Product Info */}
                                        <div className="p-3 space-y-2 font-sans flex-1 flex flex-col justify-between">
                                            <div className="space-y-1">
                                                <Link
                                                    href={route('buyer.products.show', product.slug)}
                                                    className="font-bold text-xs text-slate-800 group-hover:text-[#E00D42] transition line-clamp-2 leading-tight"
                                                >
                                                    {product.name}
                                                </Link>

                                                {/* Price */}
                                                <div className="flex items-baseline gap-1.5 pt-0.5">
                                                    <span className="text-sm font-black text-[#E00D42]">
                                                        {formatPrice(product.price)}
                                                    </span>
                                                    {compareNum && compareNum > priceNum && (
                                                        <span className="text-[10px] text-slate-400 line-through">
                                                            {formatPrice(product.compare_at_price)}
                                                        </span>
                                                    )}
                                                </div>

                                                {/* Rating & Sold count */}
                                                <div className="flex items-center justify-between text-[10px] text-slate-500 font-mono pt-1">
                                                    <div className="flex items-center gap-0.5 text-amber-500 font-bold">
                                                        <Star className="w-3 h-3 fill-amber-400" />
                                                        <span>{Number(product.rating || 5.0).toFixed(1)}</span>
                                                    </div>
                                                    <span>{product.sales_count ?? 120} sold</span>
                                                </div>
                                            </div>

                                            {/* Quick Add Button */}
                                            <button
                                                type="button"
                                                onClick={(e) => handleAddToCart(e, product.id)}
                                                disabled={isAdding || product.stock <= 0}
                                                className={`w-full py-1.5 rounded-lg font-mono text-[11px] font-bold uppercase tracking-wider transition flex items-center justify-center gap-1 ${
                                                    isSuccess
                                                        ? 'bg-emerald-600 text-white'
                                                        : product.stock <= 0
                                                            ? 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                                            : 'bg-slate-900 hover:bg-[#E00D42] text-white active:scale-[0.98]'
                                                }`}
                                            >
                                                {isSuccess ? (
                                                    <>
                                                        <Check className="w-3 h-3" />
                                                        <span>Added</span>
                                                    </>
                                                ) : isAdding ? (
                                                    <span>...</span>
                                                ) : (
                                                    <>
                                                        <ShoppingCart className="w-3 h-3" />
                                                        <span>Add to Bag</span>
                                                    </>
                                                )}
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    {/* Pagination */}
                    {feedProducts.links && feedProducts.links.length > 3 && (
                        <div className="mt-8 flex items-center justify-center gap-1 font-mono text-xs">
                            {feedProducts.links.map((link, idx) => (
                                <Link
                                    key={idx}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`px-3.5 py-2 rounded-lg border font-bold uppercase transition ${
                                        link.active
                                            ? 'bg-[#E00D42] text-white border-[#E00D42]'
                                            : link.url
                                                ? 'bg-white text-slate-800 border-slate-200 hover:border-slate-400'
                                                : 'bg-slate-100 text-slate-400 border-slate-200 cursor-not-allowed'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>

            </div>
        </BuyerLayout>
    );
}
