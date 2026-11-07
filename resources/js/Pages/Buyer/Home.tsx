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
import { Category, PaginatedData, Product, SubCategory } from '@/types';
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

export interface CuratedCollection {
    id: number;
    title: string;
    ribbon: string;
    ribbon_color: string;
    sales_badge: string;
    category_slug: string;
    category_name: string;
    sub_slug: string;
    image: string;
    item_count_label?: string;
    target_url: string;
}

interface Props {
    banners: Advertisement[];
    categories: (Category & { products_count?: number })[];
    subcategories?: SubCategory[];
    feedProducts: PaginatedData<Product>;
    flashSaleProducts?: Product[];
    topPersonalizedProducts?: Array<Product & { personalized_ribbon?: string; collection_tag?: string; collection_sub?: string; collection_title?: string; sales_badge?: string }>;
    topCuratedCollections?: CuratedCollection[];
    relatedProducts?: Product[];
    activeShipment?: ActiveShipment | null;
    filters: {
        search?: string;
        category?: string;
        sub?: string;
        sub_category?: string;
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
    subcategories = [],
    feedProducts,
    flashSaleProducts: initialFlashSales,
    topPersonalizedProducts: initialTopProducts,
    topCuratedCollections = [],
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

    // Row 3: Official 14 Master Categories with Custom Transparent Icons
    const categoryCatalog = [
        { name: "Men's Apparel", slug: 'mens-apparel', image: '/images/categories/icons/cat-mens-apparel.png', count: '1.2k+ items' },
        { name: 'Bags & EDC', slug: 'backpacks-and-bags', image: '/images/categories/icons/cat-backpacks-and-bags.png', count: '890+ items' },
        { name: 'Electronics & Gadgets', slug: 'electronics-and-gadgets', image: '/images/categories/icons/cat-electronics-and-gadgets.png', count: '650+ items' },
        { name: 'Jewelry & Watches', slug: 'jewelry-and-watches', image: '/images/categories/icons/cat-jewelry-and-watches.png', count: '420+ items' },
        { name: 'Sports & Outdoors', slug: 'sports-and-outdoors', image: '/images/categories/icons/cat-sports-and-outdoors.png', count: '540+ items' },
        { name: 'Eyewear & Accessories', slug: 'bagoo-demo-accessories-5', image: '/images/categories/icons/cat-eyewear-and-accessories.png', count: '310+ items' },
        { name: 'Home & Living', slug: 'home-and-garden', image: '/images/categories/icons/cat-home-and-garden.png', count: '480+ items' },
        { name: "Women's Apparel", slug: 'womens-apparel', image: '/images/categories/icons/cat-womens-apparel.png', count: '1.1k+ items' },
        { name: 'Health & Beauty', slug: 'health-and-beauty', image: '/images/categories/icons/cat-health-and-beauty.png', count: '590+ items' },
        { name: 'Automotive & Motorcycle', slug: 'automotive-motorcycle-group-9', image: '/images/categories/icons/cat-automotive-and-motorcycle.png', count: '340+ items' },
        { name: 'Food & Gourmet', slug: 'food-and-gourmet', image: '/images/categories/icons/cat-food-and-gourmet.png', count: '460+ items' },
        { name: 'Office & School', slug: 'office-and-school-supplies-group-12', image: '/images/categories/icons/cat-office-and-school.png', count: '290+ items' },
        { name: 'Kids & Baby', slug: 'kids-and-baby', image: '/images/categories/icons/cat-kids-and-baby.png', count: '380+ items' },
        { name: 'Pet Supplies', slug: 'pet-supplies', image: '/images/categories/icons/cat-pet-supplies.png', count: '270+ items' },
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

            <div className="space-y-3 sm:space-y-4">

                {/* 1. ACTIVE ORDER TELEMETRY STRIP (IF BUYER HAS IN-TRANSIT PACKAGE) */}
                {activeShipment && (
                    <div className="p-3 sm:p-3.5 rounded-xl bg-[#E00D42] text-white flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5 shadow-[0_4px_16px_rgba(224,13,66,0.2)] border border-[#C20836] font-sans text-xs">
                        <div className="flex items-center gap-2.5">
                            <div className="w-9 h-9 rounded-lg bg-white text-[#E00D42] flex items-center justify-center shrink-0 shadow-sm">
                                <Truck className="w-4 h-4 animate-bounce" />
                            </div>
                            <div>
                                <div className="flex items-center gap-1.5 text-[10px] text-amber-200 font-bold uppercase tracking-wider">
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
                            className="px-3.5 py-1.5 bg-white hover:bg-amber-50 text-[#E00D42] rounded-lg font-bold text-[10px] sm:text-[11px] uppercase transition-colors shrink-0 flex items-center gap-1 shadow-sm active:scale-95"
                        >
                            <span>Track Package</span>
                            <ArrowRight className="w-3 h-3" />
                        </Link>
                    </div>
                )}

                {/* 1. HERO PROMOTIONAL BANNER SECTION */}
                <BuyerAdvertisements banners={banners} />

                {/* 2. OFFICIAL FEATURE ACTION SHORTCUTS (ROW 2) */}
                <section aria-label="Feature shortcuts" className="pt-0.5">
                    <div className="grid grid-cols-4 sm:grid-cols-8 gap-1.5 sm:gap-2">
                        {featureShortcuts.map((item, idx) => {
                            const IconComponent = item.icon;
                            return (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={item.action}
                                    className="p-1.5 sm:p-2 rounded-xl border border-slate-200/80 bg-white hover:border-red-200 hover:shadow-xs transition-all duration-200 flex flex-col items-center justify-center gap-1 cursor-pointer group text-center"
                                >
                                    <div className={`w-8 h-8 sm:w-9 sm:h-9 rounded-full flex items-center justify-center transition-transform group-hover:scale-105 shadow-2xs ${item.color}`}>
                                        <IconComponent className="w-4 h-4" />
                                    </div>
                                    <span className="text-[10px] sm:text-[11px] font-bold text-slate-800 group-hover:text-[#E00D42] truncate max-w-full font-sans leading-tight">
                                        {item.label}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </section>

                {/* 3. CATEGORIES (ROW 3) - CLEAN SURFACE */}
                <section aria-label="Product Categories" className="space-y-2 pt-0.5">
                    <div className="flex items-center justify-between pb-1.5 border-b border-slate-200/80">
                        <div className="flex items-center gap-2">
                            <span className="w-2 h-2 rounded-full bg-[#E00D42]" />
                            <h3 className="text-xs sm:text-sm font-black text-slate-900 tracking-tight font-sans uppercase">
                                Categories
                            </h3>
                        </div>
                        <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                            14 Official Departments
                        </span>
                    </div>

                    <div className="grid grid-cols-4 sm:grid-cols-7 md:grid-cols-7 gap-1 sm:gap-2">
                        {categoryCatalog.map((cat, idx) => {
                            const isSelected = filters.category === cat.slug;
                            return (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={() => applyFilterChange({ category: isSelected ? undefined : cat.slug, sub: undefined, search: undefined })}
                                    className={`group py-1 px-1 flex flex-col items-center justify-start gap-1 cursor-pointer text-center transition-all duration-200 hover:-translate-y-0.5 focus:outline-hidden ${
                                        isSelected
                                            ? 'text-[#E00D42]'
                                            : 'text-slate-800'
                                    }`}
                                >
                                    {/* Icon Container: Pure image, no box */}
                                    <div className="w-12 h-12 sm:w-14 sm:h-14 flex items-center justify-center shrink-0">
                                        <img
                                            src={cat.image}
                                            alt={cat.name}
                                            loading="lazy"
                                            className={`w-full h-full object-contain transition-transform duration-300 ${
                                                isSelected ? 'scale-110 drop-shadow-md' : 'group-hover:scale-110 drop-shadow-xs'
                                            }`}
                                        />
                                    </div>
                                    <div className="min-w-0 w-full flex flex-col items-center">
                                        <span className={`block text-[10px] sm:text-[11px] font-bold leading-tight line-clamp-2 transition-colors ${
                                            isSelected ? 'text-[#E00D42] font-black' : 'text-slate-800 group-hover:text-[#E00D42]'
                                        }`}>
                                            {cat.name}
                                        </span>
                                        {isSelected && (
                                            <span className="inline-block w-4 h-0.5 bg-[#E00D42] rounded-full mt-0.5" />
                                        )}
                                    </div>
                                </button>
                            );
                        })}
                    </div>

                    {/* Level-2 Subcategory Filter Rail */}
                    {filters.category && filters.category !== 'all' && subcategories && subcategories.length > 0 && (
                        <div className="pt-2 border-t border-slate-200/80 space-y-1.5 animate-fade-in">
                            <div className="flex items-center justify-between text-xs font-sans">
                                <div className="flex items-center gap-1.5">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42]" />
                                    <span className="font-bold text-slate-800 uppercase tracking-wide text-[10px] sm:text-[11px]">
                                        {categories.find(c => c.slug === filters.category)?.name || 'Department'} Subcategories
                                    </span>
                                </div>
                                {filters.sub && (
                                    <button
                                        type="button"
                                        onClick={() => applyFilterChange({ sub: undefined })}
                                        className="text-[10px] font-bold text-slate-500 hover:text-[#E00D42] transition cursor-pointer flex items-center gap-1"
                                    >
                                        <RotateCcw className="w-3 h-3" />
                                        <span>Show All</span>
                                    </button>
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-1.5 py-1">
                                <button
                                    type="button"
                                    onClick={() => applyFilterChange({ sub: undefined })}
                                    className={`px-3 py-1 rounded-lg text-[11px] font-bold font-sans transition-all duration-200 cursor-pointer ${
                                        !filters.sub
                                            ? 'bg-[#E00D42] text-white shadow-xs'
                                            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200/80'
                                    }`}
                                >
                                    ALL {categories.find(c => c.slug === filters.category)?.name?.toUpperCase() || ''}
                                </button>
                                {subcategories.map((sub) => {
                                    const isSubSelected = filters.sub === sub.slug;
                                    return (
                                        <button
                                            key={sub.id}
                                            type="button"
                                            onClick={() => applyFilterChange({ sub: isSubSelected ? undefined : sub.slug })}
                                            className={`px-2.5 py-1 rounded-lg text-[11px] font-bold font-sans transition-all duration-200 cursor-pointer flex items-center gap-1 ${
                                                isSubSelected
                                                    ? 'bg-[#E00D42] text-white shadow-xs ring-1 ring-red-300'
                                                    : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200/80'
                                            }`}
                                        >
                                            <span>{sub.name}</span>
                                            {typeof sub.products_count === 'number' && sub.products_count > 0 && (
                                                <span className={`text-[9px] px-1 py-0.2 rounded-full font-mono ${
                                                    isSubSelected ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-600'
                                                }`}>
                                                    {sub.products_count}
                                                </span>
                                            )}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </section>

                {/* 4. FLASH DEALS WITH COUNTDOWN TIMER (ROW 4) */}
                {liveFlashProducts.length > 0 && !filters.search && (
                    <section id="flash-deals" aria-label="Flash Deals" className="space-y-2">
                        {/* Flash Sale Header Bar */}
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1.5 border-b border-slate-200/80">
                            <div className="flex items-center gap-2.5">
                                <div className="bg-[#E00D42] text-white p-1.5 rounded-lg flex items-center justify-center shadow-xs">
                                    <Zap className="w-4 h-4 fill-white" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-base sm:text-lg font-black text-slate-900 tracking-tight font-sans">
                                            FLASH DEALS
                                        </h3>
                                        <span className="bg-[#E00D42] text-white text-[8px] sm:text-[9px] font-black uppercase px-1.5 py-0.5 rounded-md tracking-wider">
                                            LIMITED TIME
                                        </span>
                                    </div>
                                    <p className="text-[11px] text-slate-500 font-sans">
                                        Hot promotional pricing with limited warehouse allocation
                                    </p>
                                </div>
                            </div>

                            {/* Live Countdown Timer Box (Signature Red & Warm Orange - Zero Black) */}
                            <div className="flex items-center gap-2.5">
                                <div className="flex items-center gap-1.5">
                                    <span className="text-[11px] font-bold text-slate-500 font-sans uppercase tracking-wider">
                                        Ends in:
                                    </span>
                                    <div className="flex items-center gap-1 font-mono text-xs font-black">
                                        <span className="bg-[#E00D42] text-white px-1.5 py-0.5 rounded shadow-xs">
                                            {String(timeLeft.hours).padStart(2, '0')}
                                        </span>
                                        <span className="text-[#E00D42] font-black">:</span>
                                        <span className="bg-[#E00D42] text-white px-1.5 py-0.5 rounded shadow-xs">
                                            {String(timeLeft.minutes).padStart(2, '0')}
                                        </span>
                                        <span className="text-[#E00D42] font-black">:</span>
                                        <span className="bg-[#F97316] text-white px-1.5 py-0.5 rounded shadow-xs animate-pulse">
                                            {String(timeLeft.seconds).padStart(2, '0')}
                                        </span>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => handleTabChange('top_sales')}
                                    className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-0.5 cursor-pointer ml-1"
                                >
                                    <span>See All</span>
                                    <ChevronRight className="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </div>

                        {/* Flash Sale Product Row (Compact dense 6-column grid) */}
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-1.5 sm:gap-2 md:gap-2.5">
                            {liveFlashProducts.map((product) => (
                                <FlashDealCard
                                    key={product.id}
                                    product={product}
                                    heartControl={<BagHeartButton product={product} />}
                                    href={route('buyer.products.show', product.slug)}
                                />
                            ))}
                        </div>
                    </section>
                )}

                {/* 5. TOP PRODUCTS // ALGORITHMIC CURATED PICKS (ROW 5) */}
                {((topCuratedCollections && topCuratedCollections.length > 0) || topProductsList.length > 0) && !filters.search && (
                    <section id="top-products" aria-label="Top Products" className="space-y-2">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-1.5 border-b border-slate-200/80">
                            <div className="flex items-center gap-2.5">
                                <div className="bg-[#E00D42] text-white p-1.5 rounded-lg flex items-center justify-center shadow-xs">
                                    <Sparkles className="w-4 h-4 fill-white" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <h3 className="text-base sm:text-lg font-black text-slate-900 tracking-tight font-sans uppercase">
                                            Top Products
                                        </h3>
                                        <span className="bg-[#E00D42] text-white text-[8px] sm:text-[9px] font-black uppercase px-1.5 py-0.5 rounded-md tracking-wider">
                                            ALGORITHMIC PICKS
                                        </span>
                                    </div>
                                    <p className="text-[11px] text-slate-500 font-sans">
                                        High-velocity bestselling niches ranked by community sales and verified repeat orders
                                    </p>
                                </div>
                            </div>

                            <Link
                                href="/top-products"
                                className="text-xs font-bold text-[#E00D42] hover:underline flex items-center gap-0.5 cursor-pointer ml-auto sm:ml-0"
                            >
                                <span>See All</span>
                                <ChevronRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>

                        {/* Curated Sub-niche Collection Cards (Bagoo Unique In-Image Scrim Card Design) */}
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 sm:gap-2.5">
                            {(topCuratedCollections && topCuratedCollections.length > 0 ? topCuratedCollections : [
                                {
                                    id: 1,
                                    title: 'Tactical Crossbody Slings',
                                    ribbon: '#1 BESTSELLER',
                                    ribbon_color: 'amber',
                                    sales_badge: '3.4K+ SOLD/MO',
                                    category_slug: 'backpacks-and-bags',
                                    category_name: 'Bags & EDC',
                                    sub_slug: 'crossbody-slings',
                                    image: '/images/products/stealth_sling_front.jpg',
                                    target_url: '/top-products?collection=crossbody-slings',
                                },
                                {
                                    id: 2,
                                    title: 'Oversized Streetwear Tees',
                                    ribbon: 'TRENDING',
                                    ribbon_color: 'crimson',
                                    sales_badge: '2.8K+ SOLD/MO',
                                    category_slug: 'mens-apparel',
                                    category_name: "Men's Apparel",
                                    sub_slug: 'tees-and-tops',
                                    image: '/images/products/boxy_tee_front.jpg',
                                    target_url: '/top-products?collection=tees-and-tops',
                                },
                                {
                                    id: 3,
                                    title: 'Mil-Spec Field Watches',
                                    ribbon: 'TOP RATED 5.0★',
                                    ribbon_color: 'emerald',
                                    sales_badge: '1.9K+ SOLD/MO',
                                    category_slug: 'jewelry-and-watches',
                                    category_name: 'Jewelry & Watches',
                                    sub_slug: 'tactical-field-watches',
                                    image: '/images/categories/jewelry_watches.jpg',
                                    target_url: '/top-products?collection=tactical-field-watches',
                                },
                                {
                                    id: 4,
                                    title: 'Modular Tech Organizers',
                                    ribbon: 'HOT DEMAND',
                                    ribbon_color: 'orange',
                                    sales_badge: '1.5K+ SOLD/MO',
                                    category_slug: 'backpacks-and-bags',
                                    category_name: 'Bags & EDC',
                                    sub_slug: 'tech-pouches-and-organizers',
                                    image: '/images/products/nomad_pouch_front.jpg',
                                    target_url: '/top-products?collection=tech-pouches-and-organizers',
                                },
                                {
                                    id: 5,
                                    title: 'Commuter Backpacks & Roll-Tops',
                                    ribbon: 'RISING STAR',
                                    ribbon_color: 'amber',
                                    sales_badge: '1.2K+ SOLD/MO',
                                    category_slug: 'backpacks-and-bags',
                                    category_name: 'Bags & EDC',
                                    sub_slug: 'commuter-backpacks',
                                    image: '/images/products/aero_rolltop_front.jpg',
                                    target_url: '/top-products?collection=commuter-backpacks',
                                },
                                {
                                    id: 6,
                                    title: 'EDC Hardware & Carabiners',
                                    ribbon: 'COMMUNITY CHOICE',
                                    ribbon_color: 'emerald',
                                    sales_badge: '950+ SOLD/MO',
                                    category_slug: 'backpacks-and-bags',
                                    category_name: 'Bags & EDC',
                                    sub_slug: 'edc-hardware',
                                    image: '/images/products/edc_carabiner_front.jpg',
                                    target_url: '/top-products?collection=edc-hardware',
                                },
                            ]).map((collection) => (
                                <Link
                                    key={collection.id}
                                    href={collection.target_url || `/top-products?collection=${collection.sub_slug}`}
                                    className="group/top relative block rounded-xl overflow-hidden aspect-4/3 sm:aspect-square bg-slate-900 border border-slate-200/80 hover:border-red-400 hover:shadow-lg transition-all duration-300 cursor-pointer"
                                >
                                    {/* Full Card Background Image */}
                                    <img
                                        src={collection.image}
                                        alt={collection.title}
                                        loading="lazy"
                                        className="absolute inset-0 w-full h-full object-cover group-hover/top:scale-108 transition-transform duration-500"
                                    />

                                    {/* Elegant Dark Gradient Scrim */}
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/35 to-transparent transition-opacity group-hover/top:opacity-95" />

                                    {/* Top-Left Ribbon Badge */}
                                    <div className="absolute top-2 left-2 z-10 flex items-center gap-1">
                                        <span className={`inline-flex items-center gap-1 text-[9px] font-black uppercase px-2 py-0.5 rounded-md text-white shadow-md ${
                                            collection.ribbon_color === 'amber' ? 'bg-amber-500' :
                                            collection.ribbon_color === 'crimson' ? 'bg-[#E00D42]' :
                                            collection.ribbon_color === 'emerald' ? 'bg-emerald-600' : 'bg-[#F97316]'
                                        }`}>
                                            <Flame className="w-2.5 h-2.5 fill-white" />
                                            <span>{collection.ribbon}</span>
                                        </span>
                                    </div>

                                    {/* Inside Bottom Info Scrim (Title + Department) */}
                                    <div className="absolute inset-x-0 bottom-0 p-2 sm:p-2.5 z-10 flex flex-col justify-end text-white font-sans">
                                        <span className="text-[9px] font-bold text-rose-200 uppercase tracking-wider truncate mb-0.5">
                                            {collection.category_name}
                                        </span>
                                        <h4 className="font-bold text-white text-xs sm:text-[13px] leading-tight line-clamp-1 group-hover/top:text-amber-200 transition-colors">
                                            {collection.title}
                                        </h4>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                )}

                {/* 5. "DAILY DISCOVER" & SEARCH PRODUCT FEED */}
                <div id="product-feed" className="space-y-3 pt-1">
                    
                    {/* Search & Active Filters Header Banner (Triggered when searching or filtering) */}
                    {(filters.search || (filters.category && filters.category !== 'all') || filters.sub || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                        <div className="bg-white rounded-xl p-3 sm:p-3.5 shadow-2xs border border-slate-200/80 space-y-2">
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-2 border-b border-slate-100">
                                <div>
                                    <div className="flex items-center gap-1.5">
                                        <Search className="w-4 h-4 text-[#E00D42]" />
                                        <h3 className="font-black text-slate-900 text-xs sm:text-sm font-sans uppercase">
                                            {filters.search ? `SEARCH RESULTS FOR "${filters.search.toUpperCase()}"` : 'FILTERED PRODUCTS'}
                                        </h3>
                                    </div>
                                    <p className="text-[11px] text-slate-500 font-sans mt-0.5">
                                        Found <span className="font-bold text-[#E00D42]">{feedProducts.total}</span> products matching your criteria
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={clearAllFilters}
                                    className="text-xs font-sans font-bold text-slate-600 hover:text-[#E00D42] flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 hover:border-[#E00D42]/40 transition w-fit cursor-pointer shadow-2xs"
                                >
                                    <RotateCcw className="w-3.5 h-3.5" />
                                    <span>Reset All Filters</span>
                                </button>
                            </div>

                            {/* Active Filter Chips */}
                            <div className="flex items-center gap-1.5 flex-wrap font-sans text-[11px]">
                                {filters.search && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Keyword: "{filters.search}"</span>
                                        <button onClick={() => applyFilterChange({ search: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.category && filters.category !== 'all' && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Dept: {categories.find(c => c.slug === filters.category)?.name || filters.category}</span>
                                        <button onClick={() => applyFilterChange({ category: undefined, sub: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.sub && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-rose-50 border border-rose-200 text-[#E00D42] font-semibold">
                                        <span>Subcategory: {subcategories?.find(s => s.slug === filters.sub)?.name || filters.sub}</span>
                                        <button onClick={() => applyFilterChange({ sub: undefined })} className="hover:text-red-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {(filters.min_price || filters.max_price) && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-slate-100 border border-slate-200 text-slate-800 font-medium">
                                        <span>Price: ₱{filters.min_price || '0'} – ₱{filters.max_price || '∞'}</span>
                                        <button onClick={() => { setMinPrice(''); setMaxPrice(''); applyFilterChange({ min_price: undefined, max_price: undefined }); }} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.in_stock && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 font-medium">
                                        <span>In Stock Only</span>
                                        <button onClick={() => applyFilterChange({ in_stock: undefined })} className="hover:text-emerald-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.rating && (
                                    <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-800 font-medium">
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
                    {!(filters.search || (filters.category && filters.category !== 'all') || filters.sub || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                        <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-2 pb-1.5 border-b border-slate-200/80 font-sans">
                            <div>
                                <div className="flex items-center gap-1.5 mb-0.5">
                                    <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                                    <span className="text-[10px] font-bold uppercase tracking-widest text-slate-500">
                                        VERIFIED MARKETPLACE // DAILY DISCOVER
                                    </span>
                                </div>
                                <h2 className="text-lg sm:text-xl font-black text-slate-900 tracking-tight font-sans">
                                    RECOMMENDED FOR YOU
                                </h2>
                            </div>
                            <div className="text-[11px] text-slate-500 font-medium">
                                Curated from <strong className="text-slate-900 font-bold">{feedProducts.total}</strong> active listings
                            </div>
                        </div>
                    )}

                    {/* Filter & Sort Controls Toolbar */}
                    <div className="bg-white rounded-xl p-1.5 sm:p-2 shadow-2xs border border-slate-200/80 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-2 font-sans text-xs">
                        
                        {/* Left: Feed Sort Tabs (Flex Wrap - No Horizontal Scrolling) */}
                        <div className="flex flex-wrap items-center gap-1.5 py-0.5">
                            <button
                                onClick={() => handleTabChange('all')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold uppercase transition-all duration-200 cursor-pointer ${
                                    activeTab === 'all' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Daily Discover
                            </button>
                            <button
                                onClick={() => handleTabChange('top_sales')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold uppercase transition-all duration-200 cursor-pointer ${
                                    activeTab === 'top_sales' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Top Sales
                            </button>
                            <button
                                onClick={() => handleTabChange('top_rated')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold uppercase transition-all duration-200 cursor-pointer ${
                                    activeTab === 'top_rated' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                Top Rated
                            </button>
                            <button
                                onClick={() => handleTabChange('new_arrivals')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold uppercase transition-all duration-200 cursor-pointer ${
                                    activeTab === 'new_arrivals' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'bg-slate-50 hover:bg-slate-100 text-slate-700 border border-slate-200/70'
                                }`}
                            >
                                New Arrivals
                            </button>
                        </div>

                        {/* Right: Quick Sort & Filters Toggle */}
                        <div className="flex items-center gap-2 justify-between md:justify-end">
                            {/* Sort Selector Dropdown */}
                            <div className="flex items-center gap-1.5">
                                <span className="text-slate-400 font-medium hidden sm:inline text-xs">Sort:</span>
                                <select
                                    value={filters.sort || 'relevance'}
                                    onChange={(e) => applyFilterChange({ sort: e.target.value })}
                                    className="bg-slate-50 hover:bg-slate-100 border border-slate-200 rounded-lg text-xs font-sans font-bold text-slate-800 py-1.5 pl-2.5 pr-7 focus:ring-[#E00D42] focus:border-[#E00D42] transition-colors cursor-pointer"
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
                                className={`px-3 py-1.5 rounded-lg border text-xs font-bold flex items-center gap-1.5 transition-all duration-200 cursor-pointer ${
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
                        <div className="bg-white rounded-xl p-3 sm:p-3.5 shadow-2xs border border-slate-200/80 space-y-3 font-sans text-xs animate-scale-in">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
                                
                                {/* 1. Category Dropdown */}
                                <div>
                                    <label className="block text-slate-500 font-bold text-[10px] uppercase mb-1">Department</label>
                                    <select
                                        value={filters.category || 'all'}
                                        onChange={(e) => applyFilterChange({ category: e.target.value !== 'all' ? e.target.value : undefined })}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2.5 font-medium focus:ring-[#E00D42] focus:border-[#E00D42]"
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
                                    <label className="block text-slate-500 font-bold text-[10px] uppercase mb-1">Price Range (₱ PHP)</label>
                                    <form onSubmit={handlePriceSubmit} className="flex items-center gap-1.5">
                                        <input
                                            type="number"
                                            placeholder="Min"
                                            value={minPrice}
                                            onChange={(e) => setMinPrice(e.target.value)}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2.5 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                        <span className="text-slate-400 font-bold">-</span>
                                        <input
                                            type="number"
                                            placeholder="Max"
                                            value={maxPrice}
                                            onChange={(e) => setMaxPrice(e.target.value)}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2.5 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                        />
                                        <button
                                            type="submit"
                                            className="px-3 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-bold uppercase transition-colors shrink-0 shadow-xs cursor-pointer active:scale-95 text-xs"
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
                                            className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42] w-4 h-4 cursor-pointer"
                                        />
                                        <span className="text-slate-800 font-bold text-xs">In Stock Only</span>
                                    </label>

                                    <select
                                        value={filters.rating || ''}
                                        onChange={(e) => applyFilterChange({ rating: e.target.value || undefined })}
                                        className="bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2.5 font-medium focus:ring-[#E00D42] focus:border-[#E00D42]"
                                    >
                                        <option value="">Any Rating</option>
                                        <option value="4">4★ & above</option>
                                        <option value="5">5★</option>
                                    </select>
                                </div>

                            </div>
                        </div>
                    )}

                    <section id="catalogue-results" tabIndex={-1} aria-label="Product results" className="space-y-4 scroll-mt-28 outline-none">
                    {/* Feed Product Grid */}
                    {feedProducts.data.length === 0 ? (
                        <div className="space-y-4">
                            <div className="bg-white rounded-xl p-8 text-center space-y-3 border border-slate-200/80 shadow-2xs">
                                <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                                <h3 className="text-base font-bold text-slate-900">No products found matching your search</h3>
                                <p className="text-xs text-slate-500 font-sans max-w-sm mx-auto">
                                    Try adjusting your search query, checking for typos, or clearing active filters to browse the complete catalog.
                                </p>
                                <button
                                    onClick={clearAllFilters}
                                    className="mt-2 px-4 py-2 bg-[#E00D42] text-white rounded-lg text-xs font-sans font-bold uppercase hover:bg-[#C20836] transition shadow-xs cursor-pointer active:scale-95"
                                >
                                    Clear All Filters & Browse All
                                </button>
                            </div>

                            {/* Related / Recommended Fallback Products */}
                            {relatedProducts && relatedProducts.length > 0 && (
                                <div className="space-y-3 pt-2">
                                    <div className="flex items-center gap-1.5 font-sans">
                                        <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                        <h4 className="font-bold text-slate-900 text-xs sm:text-sm uppercase">Recommended Products You Might Like</h4>
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-6 gap-1.5 sm:gap-2 md:gap-2.5">
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
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-6 gap-1.5 sm:gap-2 md:gap-2.5">
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
                        <div className="bg-white rounded-xl p-3 sm:p-4 shadow-2xs border border-slate-200/80 space-y-3 mt-4 font-sans">
                            <div className="flex items-center gap-1.5 pb-1.5 border-b border-slate-100">
                                <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                <h4 className="font-bold text-slate-900 text-xs uppercase tracking-wider">Related Recommendations</h4>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-6 gap-1.5 sm:gap-2 md:gap-2.5">
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
