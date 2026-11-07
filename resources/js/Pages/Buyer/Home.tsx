import React, { useEffect, useState, useMemo } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import BuyerAdvertisements, { Advertisement } from '@/Components/BuyerAdvertisements';
import ProductCard from '@/Components/ProductCard';
import FlashDealCard from '@/Components/FlashDealCard';
import CatalogueErrors from '@/Components/CatalogueErrors';
import BagHeartButton from '@/Components/BagHeartButton';
import CataloguePagination from '@/Components/CataloguePagination';
import { catalogueFilters } from '@/utils/catalogueNavigation';
import { Category, PaginatedData, Product } from '@/types';
import { 
    Truck, 
    Star, 
    ChevronRight, 
    ShoppingBag, 
    ShoppingCart, 
    Check, 
    Clock, 
    ArrowRight,
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
    HeartPulse,
    Filter,
    SlidersHorizontal,
    Search,
    RotateCcw,
    X,
    ChevronDown,
    Zap,
    Ticket,
    ShieldCheck,
    Flame
} from 'lucide-react';

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
    banners: Advertisement[];
    categories: (Category & { products_count?: number })[];
    feedProducts: PaginatedData<Product>;
    flashSaleProducts?: Product[];
    topPersonalizedProducts?: Array<Product & { personalized_ribbon?: string; collection_tag?: string; collection_title?: string }>;
    relatedProducts?: Product[];
    activeShipment?: ActiveShipment | null;
    filters: {
        search?: string;
        category?: string;
        sort?: string;
        min_price?: string;
        max_price?: string;
        in_stock?: boolean | string;
        rating?: string;
        tab?: string;
    };
}

export default function BuyerHome({
    banners,
    categories,
    feedProducts,
    flashSaleProducts: initialFlashSales,
    topPersonalizedProducts: initialTopProducts,
    relatedProducts = [],
    activeShipment,
    filters,
}: Props) {
    const [addingProductId, setAddingProductId] = useState<number | null>(null);
    const [addedSuccessId, setAddedSuccessId] = useState<number | null>(null);
    const [activeTab, setActiveTab] = useState(filters.tab || 'all');
    const [minPrice, setMinPrice] = useState(filters.min_price || '');
    const [maxPrice, setMaxPrice] = useState(filters.max_price || '');
    const [showFiltersPanel, setShowFiltersPanel] = useState(false);

    // Live ticking countdown for Flash Deals
    const [timeLeft, setTimeLeft] = useState({ hours: 3, minutes: 27, seconds: 48 });
    useEffect(() => {
        const timer = setInterval(() => {
            setTimeLeft((prev) => {
                if (prev.seconds > 0) {
                    return { ...prev, seconds: prev.seconds - 1 };
                } else if (prev.minutes > 0) {
                    return { ...prev, minutes: 59, seconds: 59 };
                } else if (prev.hours > 0) {
                    return { hours: prev.hours - 1, minutes: 59, seconds: 59 };
                }
                return { hours: 4, minutes: 0, seconds: 0 };
            });
        }, 1000);
        return () => clearInterval(timer);
    }, []);

    useEffect(() => {
        setActiveTab(filters.tab || 'all');
        setMinPrice(filters.min_price || '');
        setMaxPrice(filters.max_price || '');
    }, [filters.tab, filters.min_price, filters.max_price]);

    const applyFilterChange = (updates: Record<string, any>) => {
        const nextFilters = catalogueFilters(filters, updates);

        router.get(route('buyer.index'), nextFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handlePriceSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilterChange({
            min_price: minPrice.trim() || undefined,
            max_price: maxPrice.trim() || undefined,
        });
    };

    const clearAllFilters = () => {
        setMinPrice('');
        setMaxPrice('');
        router.get(route('buyer.index'), {}, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleTabChange = (tab: string) => {
        setActiveTab(tab);
        applyFilterChange({
            tab: tab !== 'all' ? tab : undefined,
            sort: tab !== 'all' ? tab : undefined,
        });
    };

    // Real dynamic or fallback flash sales
    const liveFlashProducts = useMemo(() => {
        if (initialFlashSales && initialFlashSales.length > 0) {
            return initialFlashSales.slice(0, 6);
        }
        return feedProducts.data.filter((p) => {
            const hasCompare = p.compare_at_price && Number(p.compare_at_price) > Number(p.price);
            const hasOffer = p.sale_offer?.active;
            return hasCompare || hasOffer;
        }).slice(0, 6);
    }, [initialFlashSales, feedProducts.data]);

    // Algorithmic top personalized products
    const topProductsList = useMemo(() => {
        if (initialTopProducts && initialTopProducts.length > 0) {
            return initialTopProducts.slice(0, 4);
        }
        return feedProducts.data.slice(0, 4);
    }, [initialTopProducts, feedProducts.data]);

    // Row 2: Official Shopee-style Feature Action Shortcuts
    const featureShortcuts = [
        { label: 'Siguradong Mura', badge: 'Best Price', icon: ShieldCheck, color: 'bg-rose-50 text-[#E00D42]', action: () => handleTabChange('top_sales') },
        { label: 'Fulfilled by Bagoo', badge: 'Express COD', icon: Truck, color: 'bg-emerald-50 text-emerald-600', action: () => applyFilterChange({ in_stock: true }) },
        { label: 'Flash Deals', badge: 'Up to 70%', icon: Zap, color: 'bg-amber-50 text-amber-600', action: () => {
            const el = document.getElementById('flash-deals');
            if (el) el.scrollIntoView({ behavior: 'smooth' });
        } },
        { label: 'Free Shipping', badge: '₱0 Min Spend', icon: Ticket, color: 'bg-sky-50 text-sky-600', action: () => router.get('/vouchers') },
        { label: 'Bagoo Mall', badge: '100% Authentic', icon: Store, color: 'bg-indigo-50 text-indigo-600', action: () => applyFilterChange({ rating: '4' }) },
        { label: 'Partner Promos', badge: 'Extra 15%', icon: Sparkles, color: 'bg-orange-50 text-orange-600', action: () => router.get('/vouchers') },
        { label: 'Loyalty Rewards', badge: 'Earn Coins', icon: Activity, color: 'bg-purple-50 text-purple-600', action: () => router.get(route('buyer.profile', { tab: 'account' })) },
        { label: 'Cash on Delivery', badge: 'Doorstep Escrow', icon: Check, color: 'bg-teal-50 text-teal-600', action: () => applyFilterChange({ category: undefined }) },
    ];

    // Row 3: Categories with REAL Product Photography
    const categoryCatalog = [
        { name: "Men's Apparel", slug: 'mens-apparel', image: '/images/products/boxy_tee_front.jpg', count: '1.2k+ items' },
        { name: 'Bags & EDC', slug: 'backpacks-and-bags', image: '/images/products/stealth_sling_front.jpg', count: '890+ items' },
        { name: 'Electronics & Tech', slug: 'electronics-and-gadgets', image: '/images/products/nomad_pouch_front.jpg', count: '650+ items' },
        { name: 'Watches & Gear', slug: 'jewelry-and-watches', image: '/images/products/field_watch_front.jpg', count: '420+ items' },
        { name: 'Sports & Outdoors', slug: 'sports-and-outdoors', image: '/images/products/tactical_gloves_front.jpg', count: '540+ items' },
        { name: 'Eyewear & Style', slug: 'bagoo-demo-accessories-5', image: '/images/products/aviator_glasses_front.jpg', count: '310+ items' },
        { name: 'Home & Studio', slug: 'home-and-garden', image: '/images/carousel/botanical-candle.jpg', count: '480+ items' },
        { name: 'Leathercraft & Wallets', slug: 'backpacks-and-bags', image: '/images/products/leather_wallet_front.jpg', count: '290+ items' },
        { name: 'Tactical & Survival', slug: 'sports-and-outdoors', image: '/images/products/paracord_bracelet_front.jpg', count: '380+ items' },
        { name: 'Caps & Headwear', slug: 'mens-apparel', image: '/images/products/camp_cap_front.jpg', count: '240+ items' },
        { name: 'Essential Basics', slug: 'mens-apparel', image: '/images/products/transit_brief_front.jpg', count: '510+ items' },
        { name: 'Hardware & EDC Tools', slug: 'backpacks-and-bags', image: '/images/products/edc_carabiner_front.jpg', count: '190+ items' },
    ];

    return (
        <BuyerLayout categories={categories}>
            <CatalogueErrors />
            <Head>
                <title>Official Marketplace — BagooPH | Philippine E-Commerce & On-Demand Delivery</title>
                <meta name="description" content="Shop verified merchants on BagooPH with nationwide Cash on Delivery (COD). Explore electronics, fashion, lifestyle, and local goods with real-time parcel tracking." />
                <meta property="og:title" content="BagooPH — Official Philippine E-Commerce Marketplace" />
                <meta property="og:description" content="Shop verified merchants on BagooPH with nationwide Cash on Delivery (COD) and fast, reliable delivery." />
                <meta property="og:image" content="/bagoo.svg" />
                <meta property="og:type" content="website" />
                <meta name="twitter:card" content="summary_large_image" />
                <meta name="twitter:title" content="BagooPH — Official Philippine E-Commerce Marketplace" />
                <meta name="twitter:description" content="Shop verified merchants on BagooPH with nationwide Cash on Delivery (COD)." />
            </Head>

            <div className="space-y-6">

                {/* 1. ACTIVE ORDER TELEMETRY STRIP (IF BUYER HAS IN-TRANSIT PACKAGE) */}
                {activeShipment && (
                    <div className="p-4 rounded-2xl bg-[#E00D42] text-white flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-[0_4px_16px_rgba(224,13,66,0.25)] border border-[#C20836] font-sans text-xs">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-white text-[#E00D42] flex items-center justify-center shrink-0 shadow-sm">
                                <Truck className="w-5 h-5 animate-bounce" />
                            </div>
                            <div>
                                <div className="flex items-center gap-2 text-[10px] text-amber-200 font-bold uppercase tracking-wider">
                                    <span>● LIVE DISPATCH TELEMETRY</span>
                                    <span>•</span>
                                    <span>{activeShipment.status.replace('_', ' ').toUpperCase()}</span>
                                </div>
                                <p className="font-bold text-white text-xs mt-0.5">
                                    Order #{activeShipment.order_number} ({activeShipment.item_name}) — Est. Arrival: <strong className="text-white underline">{activeShipment.estimated_delivery}</strong>
                                </p>
                            </div>
                        </div>

                        <Link
                            href={route('buyer.orders.show', activeShipment.order_id)}
                            className="px-4 py-2 bg-white hover:bg-amber-50 text-[#E00D42] rounded-xl font-bold text-[11px] uppercase transition-colors shrink-0 flex items-center gap-1.5 shadow-sm active:scale-95"
                        >
                            <span>Track Package</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                        </Link>
                    </div>
                )}

                {/* 1. HERO PROMOTIONAL BANNER SECTION */}
                <BuyerAdvertisements banners={banners} />

                {/* 2. OFFICIAL FEATURE ACTION SHORTCUTS (ROW 2) */}
                <section aria-label="Feature shortcuts" className="pt-1">
                    <div className="grid grid-cols-4 sm:grid-cols-8 gap-2 sm:gap-3">
                        {featureShortcuts.map((item, idx) => {
                            const IconComponent = item.icon;
                            return (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={item.action}
                                    className="p-2 sm:p-2.5 rounded-2xl border border-slate-200/90 bg-white hover:border-red-200 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex flex-col items-center justify-center gap-1.5 cursor-pointer group text-center"
                                >
                                    <div className={`w-10 h-10 sm:w-11 sm:h-11 rounded-full flex items-center justify-center transition-transform group-hover:scale-110 shadow-xs ${item.color}`}>
                                        <IconComponent className="w-5 h-5" />
                                    </div>
                                    <span className="text-[10px] sm:text-[11px] font-bold text-slate-800 group-hover:text-[#E00D42] truncate max-w-full font-sans leading-tight">
                                        {item.label}
                                    </span>
                                    <span className="text-[9px] font-bold text-slate-400 group-hover:text-[#E00D42] -mt-1 hidden sm:inline">
                                        {item.badge}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </section>

                {/* 3. CATEGORIES WITH REAL PRODUCT PHOTOGRAPHY (ROW 3) */}
                <section aria-label="Product Categories" className="space-y-3 pt-1">
                    <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-[0_4px_16px_rgba(15,23,42,0.05)] space-y-3">
                        <div className="flex items-center justify-between pb-2 border-b border-slate-100">
                            <div className="flex items-center gap-2">
                                <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42]"></span>
                                <h3 className="text-sm sm:text-base font-black text-slate-900 tracking-tight font-sans uppercase">
                                    Categories
                                </h3>
                            </div>
                            <span className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">
                                Real Local Curations
                            </span>
                        </div>

                        <div className="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2 sm:gap-3">
                            {categoryCatalog.map((cat, idx) => {
                                const isSelected = filters.category === cat.slug;
                                return (
                                    <button
                                        key={idx}
                                        type="button"
                                        onClick={() => applyFilterChange({ category: isSelected ? undefined : cat.slug, search: undefined })}
                                        className={`group p-2.5 rounded-2xl border transition-all duration-200 flex flex-col items-center justify-between gap-2 cursor-pointer text-center ${
                                            isSelected
                                                ? 'bg-rose-50 border-[#E00D42] shadow-sm ring-1 ring-[#E00D42]'
                                                : 'bg-slate-50/60 hover:bg-white border-slate-200/80 hover:border-red-200 hover:shadow-md hover:-translate-y-1'
                                        }`}
                                    >
                                        <div className="w-16 h-16 sm:w-20 sm:h-20 rounded-xl overflow-hidden bg-white border border-slate-100 shadow-2xs shrink-0">
                                            <img
                                                src={cat.image}
                                                alt={cat.name}
                                                loading="lazy"
                                                className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300"
                                            />
                                        </div>
                                        <div className="min-w-0 w-full">
                                            <span className={`block text-[11px] font-bold truncate ${isSelected ? 'text-[#E00D42]' : 'text-slate-800 group-hover:text-[#E00D42]'}`}>
                                                {cat.name}
                                            </span>
                                            <span className="block text-[9px] text-slate-400 font-medium">
                                                {cat.count}
                                            </span>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* 4. FLASH DEALS WITH COUNTDOWN TIMER (ROW 4) */}
                {liveFlashProducts.length > 0 && !filters.search && (
                    <section id="flash-deals" aria-label="Flash Deals" className="space-y-4 pt-1">
                        <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-[0_4px_16px_rgba(15,23,42,0.05)] space-y-4">
                            {/* Flash Sale Header Bar */}
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-3">
                                    <div className="bg-[#E00D42] text-white p-2 rounded-xl flex items-center justify-center shadow-xs">
                                        <Zap className="w-5 h-5 fill-white" />
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-lg sm:text-xl font-black text-slate-900 tracking-tight font-sans">
                                                FLASH DEALS
                                            </h3>
                                            <span className="bg-[#E00D42] text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-md tracking-wider">
                                                LIMITED TIME
                                            </span>
                                        </div>
                                        <p className="text-xs text-slate-500 font-sans mt-0.5">
                                            Hot promotional pricing with limited warehouse allocation
                                        </p>
                                    </div>
                                </div>

                                {/* Live Countdown Timer Box (Signature Red & Warm Orange - Zero Black) */}
                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-2">
                                        <span className="text-xs font-bold text-slate-500 font-sans uppercase tracking-wider">
                                            Ends in:
                                        </span>
                                        <div className="flex items-center gap-1 font-mono text-xs font-black">
                                            <span className="bg-[#E00D42] text-white px-2 py-1 rounded-md shadow-xs">
                                                {String(timeLeft.hours).padStart(2, '0')}
                                            </span>
                                            <span className="text-[#E00D42] font-black">:</span>
                                            <span className="bg-[#E00D42] text-white px-2 py-1 rounded-md shadow-xs">
                                                {String(timeLeft.minutes).padStart(2, '0')}
                                            </span>
                                            <span className="text-[#E00D42] font-black">:</span>
                                            <span className="bg-[#F97316] text-white px-2 py-1 rounded-md shadow-xs animate-pulse">
                                                {String(timeLeft.seconds).padStart(2, '0')}
                                            </span>
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() => handleTabChange('top_sales')}
                                        className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-0.5 cursor-pointer ml-2"
                                    >
                                        <span>See All</span>
                                        <ChevronRight className="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>

                            {/* Flash Sale Product Row (Only Image, Price & Selling Fast Bar) */}
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3 sm:gap-4">
                                {liveFlashProducts.map((product) => (
                                    <FlashDealCard
                                        key={product.id}
                                        product={product}
                                        heartControl={<BagHeartButton product={product} />}
                                        href={route('buyer.products.show', product.slug)}
                                    />
                                ))}
                            </div>
                        </div>
                    </section>
                )}

                {/* 5. TOP PRODUCTS // ALGORITHMIC CURATED PICKS (ROW 5) */}
                {topProductsList.length > 0 && !filters.search && (
                    <section id="top-products" aria-label="Top Products" className="space-y-4 pt-1">
                        <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200/90 shadow-[0_4px_16px_rgba(15,23,42,0.05)] space-y-4">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                                <div className="flex items-center gap-3">
                                    <div className="bg-[#E00D42] text-white p-2 rounded-xl flex items-center justify-center shadow-xs">
                                        <Sparkles className="w-5 h-5 fill-white" />
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-lg sm:text-xl font-black text-slate-900 tracking-tight font-sans uppercase">
                                                Top Products · Curated For You
                                            </h3>
                                            <span className="bg-[#F97316] text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-md tracking-wider">
                                                ALGORITHMIC PICKS
                                            </span>
                                        </div>
                                        <p className="text-xs text-slate-500 font-sans mt-0.5">
                                            Curated based on your browsing preferences and community sales velocity
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Top Products Cards with Dynamic Ribbons */}
                            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-4 gap-4 sm:gap-6">
                                {topProductsList.map((product, idx) => {
                                    const ribbonText = (product as any).personalized_ribbon || (idx === 0 ? '#1 BESTSELLER' : idx === 1 ? 'TRENDING · 3.4K SOLD' : idx === 2 ? '5.0★ TOP RATED' : 'HOT DEAL -29%');
                                    const nicheTag = (product as any).collection_tag || (product.category?.slug ?? 'backpacks-and-bags');
                                    const nicheTitle = (product as any).collection_title || `${product.category?.name ?? 'Curated Gear'} Collection`;

                                    return (
                                        <div key={product.id} className="relative flex flex-col group/top">
                                            {/* Dynamic Algorithmic Ribbon Header */}
                                            <div className="mb-2 flex items-center justify-between">
                                                <span className={`inline-flex items-center gap-1 text-[10px] font-black uppercase px-2.5 py-1 rounded-lg text-white shadow-xs ${
                                                    idx === 0 ? 'bg-amber-500' : idx === 1 ? 'bg-[#E00D42]' : idx === 2 ? 'bg-emerald-600' : 'bg-[#F97316]'
                                                }`}>
                                                    <Flame className="w-3 h-3 fill-white" />
                                                    <span>{ribbonText}</span>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() => applyFilterChange({ category: nicheTag, search: undefined })}
                                                    className="text-[10px] font-bold text-slate-500 hover:text-[#E00D42] flex items-center gap-0.5 transition cursor-pointer"
                                                    title={`View matching ${nicheTitle}`}
                                                >
                                                    <span>Discover</span>
                                                    <ChevronRight className="w-3 h-3" />
                                                </button>
                                            </div>

                                            {/* Product Card */}
                                            <ProductCard
                                                product={product}
                                                heartControl={<BagHeartButton product={product} />}
                                                href={route('buyer.products.show', product.slug)}
                                                className="border-2 border-slate-200 group-hover/top:border-red-300"
                                            />

                                            {/* Curated Sub-niche Link Pill */}
                                            <button
                                                type="button"
                                                onClick={() => applyFilterChange({ category: nicheTag, search: undefined })}
                                                className="mt-2 w-full text-center py-2 px-2.5 bg-slate-50 hover:bg-rose-50 text-slate-700 hover:text-[#E00D42] border border-slate-200/80 hover:border-red-200 rounded-xl text-[10px] font-bold transition flex items-center justify-center gap-1 cursor-pointer"
                                            >
                                                <span>Explore {nicheTitle}</span>
                                                <ArrowRight className="w-3 h-3 text-[#E00D42]" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </section>
                )}

                {/* 5. "DAILY DISCOVER" & SEARCH PRODUCT FEED */}
                <div id="product-feed" className="space-y-6 pt-2">
                    
                    {/* Search & Active Filters Header Banner (Triggered when searching or filtering) */}
                    {(filters.search || (filters.category && filters.category !== 'all') || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                        <div className="bg-white rounded-2xl p-4 sm:p-5 shadow-[0_4px_16px_rgba(15,23,42,0.05)] border border-slate-200/90 space-y-3">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Search className="w-4 h-4 text-[#E00D42]" />
                                        <h3 className="font-black text-slate-900 text-sm sm:text-base font-sans">
                                            {filters.search ? `SEARCH RESULTS FOR "${filters.search.toUpperCase()}"` : 'FILTERED PRODUCTS'}
                                        </h3>
                                    </div>
                                    <p className="text-xs text-slate-500 font-sans mt-0.5">
                                        Found <span className="font-bold text-[#E00D42]">{feedProducts.total}</span> products matching your criteria
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={clearAllFilters}
                                    className="text-xs font-sans font-bold text-slate-600 hover:text-[#E00D42] flex items-center gap-1.5 px-3.5 py-2 rounded-xl border border-slate-200 hover:border-[#E00D42]/40 transition w-fit cursor-pointer shadow-2xs"
                                >
                                    <RotateCcw className="w-3.5 h-3.5" />
                                    <span>Reset All Filters</span>
                                </button>
                            </div>

                            {/* Active Filter Chips */}
                            <div className="flex items-center gap-2 flex-wrap font-sans text-[11px]">
                                {filters.search && (
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Keyword: "{filters.search}"</span>
                                        <button onClick={() => applyFilterChange({ search: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.category && filters.category !== 'all' && (
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Dept: {categories.find(c => c.slug === filters.category)?.name || filters.category}</span>
                                        <button onClick={() => applyFilterChange({ category: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {(filters.min_price || filters.max_price) && (
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Price: ₱{filters.min_price || '0'} – ₱{filters.max_price || '∞'}</span>
                                        <button onClick={() => { setMinPrice(''); setMaxPrice(''); applyFilterChange({ min_price: undefined, max_price: undefined }); }} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.in_stock && (
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 font-medium">
                                        <span>In Stock Only</span>
                                        <button onClick={() => applyFilterChange({ in_stock: undefined })} className="hover:text-emerald-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.rating && (
                                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 font-medium">
                                        <span>Rating: {filters.rating}★ & up</span>
                                        <button onClick={() => applyFilterChange({ rating: undefined })} className="hover:text-amber-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}
                            </div>
                        </div>
                    )}

                    {/* Section Header when browsing naturally */}
                    {!(filters.search || (filters.category && filters.category !== 'all') || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-3 pb-2 border-b border-slate-200/90 font-sans">
                            <div>
                                <div className="flex items-center gap-2 mb-1">
                                    <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42] animate-pulse"></span>
                                    <span className="text-[11px] font-bold uppercase tracking-widest text-slate-500">
                                        VERIFIED MARKETPLACE // DAILY DISCOVER
                                    </span>
                                </div>
                                <h2 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight font-sans">
                                    RECOMMENDED FOR YOU
                                </h2>
                            </div>
                            <div className="text-xs text-slate-500 font-medium">
                                Curated from <strong className="text-slate-900 font-bold">{feedProducts.total}</strong> active listings
                            </div>
                        </div>
                    )}

                    {/* Filter & Sort Controls Toolbar */}
                    <div className="bg-white rounded-2xl p-3 sm:p-3.5 shadow-[0_2px_12px_rgba(15,23,42,0.04)] border border-slate-200/90 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 font-sans text-xs">
                        
                        {/* Left: Feed Sort Tabs */}
                        <div className="flex items-center gap-2 overflow-x-auto scrollbar-none pb-0.5">
                            <button
                                onClick={() => handleTabChange('all')}
                                className={`px-4 py-2 rounded-xl font-bold uppercase transition-all duration-200 shrink-0 cursor-pointer ${
                                    activeTab === 'all' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Daily Discover
                            </button>
                            <button
                                onClick={() => handleTabChange('top_sales')}
                                className={`px-4 py-2 rounded-xl font-bold uppercase transition-all duration-200 shrink-0 cursor-pointer ${
                                    activeTab === 'top_sales' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Top Sales
                            </button>
                            <button
                                onClick={() => handleTabChange('top_rated')}
                                className={`px-4 py-2 rounded-xl font-bold uppercase transition-all duration-200 shrink-0 cursor-pointer ${
                                    activeTab === 'top_rated' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Top Rated
                            </button>
                            <button
                                onClick={() => handleTabChange('new_arrivals')}
                                className={`px-4 py-2 rounded-xl font-bold uppercase transition-all duration-200 shrink-0 cursor-pointer ${
                                    activeTab === 'new_arrivals' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                New Arrivals
                            </button>
                        </div>

                        {/* Right: Quick Sort & Filters Toggle */}
                        <div className="flex items-center gap-2.5 justify-between md:justify-end">
                            {/* Sort Selector Dropdown */}
                            <div className="flex items-center gap-1.5">
                                <span className="text-slate-400 font-medium hidden sm:inline">Sort:</span>
                                <select
                                    value={filters.sort || 'relevance'}
                                    onChange={(e) => applyFilterChange({ sort: e.target.value })}
                                    className="bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-xl text-xs font-sans font-bold text-slate-800 py-2 pl-3 pr-8 focus:ring-[#E00D42] focus:border-[#E00D42] transition-colors cursor-pointer"
                                >
                                    <option value="relevance">Relevance</option>
                                    <option value="top_sales">Best Sellers</option>
                                    <option value="price_asc">Price: Low to High</option>
                                    <option value="price_desc">Price: High to Low</option>
                                    <option value="top_rated">Top Customer Rated</option>
                                    <option value="newest">Newest Arrivals</option>
                                </select>
                            </div>

                            {/* Filter Drawer / Panel Toggle Button */}
                            <button
                                type="button"
                                onClick={() => setShowFiltersPanel(!showFiltersPanel)}
                                className={`px-3.5 py-2 rounded-xl border font-bold flex items-center gap-1.5 transition-all duration-200 cursor-pointer ${
                                    showFiltersPanel || filters.min_price || filters.max_price || filters.in_stock
                                        ? 'bg-slate-900 text-white border-slate-900 shadow-xs'
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border-slate-200'
                                }`}
                            >
                                <SlidersHorizontal className="w-3.5 h-3.5" />
                                <span>Filters</span>
                            </button>
                        </div>
                    </div>

                    {/* Expandable Advanced Filters Box */}
                    {showFiltersPanel && (
                        <div className="bg-white rounded-2xl p-4 sm:p-5 shadow-[0_4px_16px_rgba(15,23,42,0.06)] border border-slate-200 space-y-4 font-sans text-xs animate-scale-in">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                                
                                {/* 1. Category Dropdown */}
                                <div>
                                    <label className="block text-slate-500 font-bold text-[10px] uppercase mb-1.5">Department</label>
                                    <select
                                        value={filters.category || 'all'}
                                        onChange={(e) => applyFilterChange({ category: e.target.value !== 'all' ? e.target.value : undefined })}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs py-2 px-3 font-medium focus:ring-[#E00D42] focus:border-[#E00D42]"
                                    >
                                        <option value="all">All Departments ({feedProducts.total})</option>
                                        {categories.map((c) => (
                                            <option key={c.id} value={c.slug}>
                                                {c.name} ({c.products_count ?? 0})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                {/* 2. Price Range Form */}
                                <div>
                                    <label className="block text-slate-500 font-bold text-[10px] uppercase mb-1.5">Price Range (₱ PHP)</label>
                                    <form onSubmit={handlePriceSubmit} className="flex items-center gap-1.5">
                                        <input
                                            type="number"
                                            placeholder="Min"
                                            value={minPrice}
                                            onChange={(e) => setMinPrice(e.target.value)}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                        <span className="text-slate-400 font-bold">-</span>
                                        <input
                                            type="number"
                                            placeholder="Max"
                                            value={maxPrice}
                                            onChange={(e) => setMaxPrice(e.target.value)}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-xl text-xs py-2 px-3 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                        <button
                                            type="submit"
                                            className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-xl font-bold uppercase transition-colors shrink-0 shadow-xs cursor-pointer active:scale-95"
                                        >
                                            Apply
                                        </button>
                                    </form>
                                </div>

                                {/* 3. Stock & Rating Filters */}
                                <div className="flex items-center gap-4">
                                    <label className="flex items-center gap-2 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(filters.in_stock)}
                                            onChange={(e) => applyFilterChange({ in_stock: e.target.checked ? true : undefined })}
                                            className="rounded-md border-slate-300 text-[#E00D42] focus:ring-[#E00D42] w-4 h-4 cursor-pointer"
                                        />
                                        <span className="text-slate-800 font-bold">In Stock Only</span>
                                    </label>

                                    <select
                                        value={filters.rating || ''}
                                        onChange={(e) => applyFilterChange({ rating: e.target.value || undefined })}
                                        className="bg-slate-50 border border-slate-200 rounded-xl text-xs py-2 px-3 font-medium focus:ring-[#E00D42] focus:border-[#E00D42]"
                                    >
                                        <option value="">Any Rating</option>
                                        <option value="4">4★ & above</option>
                                        <option value="5">5★</option>
                                    </select>
                                </div>

                            </div>
                        </div>
                    )}

                    <section id="catalogue-results" tabIndex={-1} aria-label="Product results" className="space-y-6 scroll-mt-28 outline-none">
                    {/* Feed Product Grid */}
                    {feedProducts.data.length === 0 ? (
                        <div className="space-y-6">
                            <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-200/90 shadow-[0_2px_8px_rgba(15,23,42,0.04)]">
                                <ShoppingBag className="w-14 h-14 text-slate-300 mx-auto" />
                                <h3 className="text-lg font-bold text-slate-900">No products found matching your search</h3>
                                <p className="text-xs text-slate-500 font-sans max-w-sm mx-auto">
                                    Try adjusting your search query, checking for typos, or clearing active filters to browse the complete catalog.
                                </p>
                                <button
                                    onClick={clearAllFilters}
                                    className="mt-3 px-5 py-2.5 bg-[#E00D42] text-white rounded-xl text-xs font-sans font-bold uppercase hover:bg-[#C20836] transition shadow-xs cursor-pointer active:scale-95"
                                >
                                    Clear All Filters & Browse All
                                </button>
                            </div>

                            {/* Related / Recommended Fallback Products */}
                            {relatedProducts && relatedProducts.length > 0 && (
                                <div className="space-y-4 pt-4">
                                    <div className="flex items-center gap-2 font-sans">
                                        <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                        <h4 className="font-bold text-slate-900 text-sm uppercase">Recommended Products You Might Like</h4>
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 gap-4 sm:gap-6">
                                        {relatedProducts.map((product) => (
                                            <ProductCard
                                                key={product.id}
                                                product={product}
                                                heartControl={<BagHeartButton product={product} />}
                                                href={route('buyer.products.show', product.slug)}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 gap-4 sm:gap-6">
                            {feedProducts.data.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                    heartControl={<BagHeartButton product={product} />}
                                    href={route('buyer.products.show', product.slug)}
                                />
                            ))}
                        </div>
                    )}

                    {/* Related Products Discovery Feed (when search query produced matches) */}
                    {filters.search && feedProducts.data.length > 0 && relatedProducts && relatedProducts.length > 0 && (
                        <div className="bg-white rounded-2xl p-5 shadow-[0_4px_16px_rgba(15,23,42,0.05)] border border-slate-200/90 space-y-4 mt-8 font-sans">
                            <div className="flex items-center gap-2 pb-2 border-b border-slate-100">
                                <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                <h4 className="font-bold text-slate-900 text-xs uppercase tracking-wider">Related Recommendations</h4>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-4 gap-4 sm:gap-6">
                                {relatedProducts.map((p) => (
                                    <ProductCard
                                        key={p.id}
                                        product={p}
                                        heartControl={<BagHeartButton product={p} />}
                                        href={route('buyer.products.show', p.slug)}
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    <CataloguePagination page={feedProducts} />
                    </section>
                </div>

            </div>
        </BuyerLayout>
    );
}
