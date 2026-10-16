import React, { useEffect, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import BuyerAdvertisements, { Advertisement } from '@/Components/BuyerAdvertisements';
import ProductCard from '@/Components/ProductCard';
import CatalogueErrors from '@/Components/CatalogueErrors';
import SavedProductButton from '@/Components/SavedProductButton';
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
    ChevronDown
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
    useEffect(() => {
        setActiveTab(filters.tab || 'all');
        setMinPrice(filters.min_price || '');
        setMaxPrice(filters.max_price || '');
    }, [filters.tab, filters.min_price, filters.max_price]);

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

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
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
                    <div className="p-4 rounded-xl bg-slate-900 text-white flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-md border border-slate-800 font-sans text-xs">
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

                <BuyerAdvertisements banners={banners} />

                {/* 5. "DAILY DISCOVER" & SEARCH PRODUCT FEED */}
                <div id="product-feed" className="space-y-6 pt-2">
                    
                    {/* Search & Active Filters Header Banner (Triggered when searching or filtering) */}
                    {(filters.search || (filters.category && filters.category !== 'all') || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                        <div className="bg-white rounded-2xl p-4 sm:p-5 shadow-xs border border-slate-200 space-y-3">
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
                                    className="text-xs font-sans font-bold text-slate-600 hover:text-[#E00D42] flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-200 hover:border-[#E00D42]/40 transition w-fit"
                                >
                                    <RotateCcw className="w-3.5 h-3.5" />
                                    <span>Reset All Filters</span>
                                </button>
                            </div>

                            {/* Active Filter Chips */}
                            <div className="flex items-center gap-2 flex-wrap font-sans text-[11px]">
                                {filters.search && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-800">
                                        <span>Keyword: "{filters.search}"</span>
                                        <button onClick={() => applyFilterChange({ search: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.category && filters.category !== 'all' && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-800">
                                        <span>Dept: {categories.find(c => c.slug === filters.category)?.name || filters.category}</span>
                                        <button onClick={() => applyFilterChange({ category: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {(filters.min_price || filters.max_price) && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-100 border border-slate-200 text-slate-800">
                                        <span>Price: ₱{filters.min_price || '0'} – ₱{filters.max_price || '∞'}</span>
                                        <button onClick={() => { setMinPrice(''); setMaxPrice(''); applyFilterChange({ min_price: undefined, max_price: undefined }); }} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.in_stock && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800">
                                        <span>In Stock Only</span>
                                        <button onClick={() => applyFilterChange({ in_stock: undefined })} className="hover:text-emerald-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.rating && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-amber-50 border border-amber-200 text-amber-800">
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
                                    <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                                    <span className="text-[10px] font-bold uppercase tracking-widest text-slate-500">
                                        VERIFIED MARKETPLACE // DAILY DISCOVER
                                    </span>
                                </div>
                                <h2 className="text-xl sm:text-2xl font-black text-slate-900 tracking-tight font-sans">
                                    RECOMMENDED FOR YOU
                                </h2>
                            </div>
                            <div className="text-xs text-slate-500">
                                Curated from <strong className="text-slate-900 font-bold">{feedProducts.total}</strong> active listings
                            </div>
                        </div>
                    )}

                    {/* Filter & Sort Controls Toolbar */}
                    <div className="bg-white rounded-xl p-3 shadow-xs border border-slate-200/90 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 font-sans text-xs">
                        
                        {/* Left: Feed Sort Tabs */}
                        <div className="flex items-center gap-1.5 overflow-x-auto scrollbar-none">
                            <button
                                onClick={() => handleTabChange('all')}
                                className={`px-3.5 py-1.5 rounded-lg font-bold uppercase transition shrink-0 ${
                                    activeTab === 'all' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                Daily Discover
                            </button>
                            <button
                                onClick={() => handleTabChange('top_sales')}
                                className={`px-3.5 py-1.5 rounded-lg font-bold uppercase transition shrink-0 ${
                                    activeTab === 'top_sales' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                Top Sales
                            </button>
                            <button
                                onClick={() => handleTabChange('top_rated')}
                                className={`px-3.5 py-1.5 rounded-lg font-bold uppercase transition shrink-0 ${
                                    activeTab === 'top_rated' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                Top Rated
                            </button>
                            <button
                                onClick={() => handleTabChange('new_arrivals')}
                                className={`px-3.5 py-1.5 rounded-lg font-bold uppercase transition shrink-0 ${
                                    activeTab === 'new_arrivals' 
                                        ? 'bg-[#E00D42] text-white shadow-xs' 
                                        : 'text-slate-700 hover:bg-slate-100'
                                }`}
                            >
                                New Arrivals
                            </button>
                        </div>

                        {/* Right: Quick Sort & Filters Toggle */}
                        <div className="flex items-center gap-2 justify-between md:justify-end">
                            {/* Sort Selector Dropdown */}
                            <div className="flex items-center gap-1.5">
                                <span className="text-slate-400 hidden sm:inline">Sort:</span>
                                <select
                                    value={filters.sort || 'relevance'}
                                    onChange={(e) => applyFilterChange({ sort: e.target.value })}
                                    className="bg-slate-50 border border-slate-200 rounded-lg text-xs font-sans font-bold text-slate-800 py-1.5 pl-2.5 pr-8 focus:ring-[#E00D42] focus:border-[#E00D42]"
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
                                className={`px-3 py-1.5 rounded-lg border font-bold flex items-center gap-1.5 transition ${
                                    showFiltersPanel || filters.min_price || filters.max_price || filters.in_stock
                                        ? 'bg-slate-900 text-white border-slate-900'
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
                        <div className="bg-white rounded-xl p-4 shadow-xs border border-slate-200 space-y-4 font-sans text-xs animate-scale-in">
                            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                                
                                {/* 1. Category Dropdown */}
                                <div>
                                    <label className="block text-slate-500 font-bold text-[10px] uppercase mb-1">Department</label>
                                    <select
                                        value={filters.category || 'all'}
                                        onChange={(e) => applyFilterChange({ category: e.target.value !== 'all' ? e.target.value : undefined })}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2.5"
                                    >
                                        <option value="all">All 14 Departments</option>
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
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2"
                                        />
                                        <span className="text-slate-400">-</span>
                                        <input
                                            type="number"
                                            placeholder="Max"
                                            value={maxPrice}
                                            onChange={(e) => setMaxPrice(e.target.value)}
                                            className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2"
                                        />
                                        <button
                                            type="submit"
                                            className="px-3 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white rounded-lg font-bold uppercase transition shrink-0"
                                        >
                                            Apply
                                        </button>
                                    </form>
                                </div>

                                {/* 3. Stock & Rating Filters */}
                                <div className="flex items-center gap-3">
                                    <label className="flex items-center gap-2 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(filters.in_stock)}
                                            onChange={(e) => applyFilterChange({ in_stock: e.target.checked ? true : undefined })}
                                            className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                        />
                                        <span className="text-slate-700 font-bold">In Stock Only</span>
                                    </label>

                                    <select
                                        value={filters.rating || ''}
                                        onChange={(e) => applyFilterChange({ rating: e.target.value || undefined })}
                                        className="bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2"
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
                            <div className="bg-white rounded-2xl p-10 text-center space-y-3 border border-slate-100">
                                <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                                <h3 className="text-base font-bold text-slate-800">No products found matching your search</h3>
                                <p className="text-xs text-slate-500 font-sans">
                                    Try adjusting your search query, checking for typos, or clearing active filters.
                                </p>
                                <button
                                    onClick={clearAllFilters}
                                    className="mt-2 px-4 py-2 bg-[#E00D42] text-white rounded-lg text-xs font-sans font-bold uppercase hover:bg-[#C20836] transition"
                                >
                                    Clear All Filters & Browse All
                                </button>
                            </div>

                            {/* Related / Recommended Fallback Products */}
                            {relatedProducts && relatedProducts.length > 0 && (
                                <div className="space-y-3 pt-4">
                                    <div className="flex items-center gap-2 font-sans">
                                        <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                        <h4 className="font-bold text-slate-900 text-sm uppercase">Recommended Products You Might Like</h4>
                                    </div>
                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5 sm:gap-4">
                                        {relatedProducts.map((product) => (
                                            <ProductCard
                                                key={product.id}
                                                product={product} saveControl={<SavedProductButton productId={product.id} />}
                                                href={route('buyer.products.show', product.slug)}
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5 sm:gap-4">
                            {feedProducts.data.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product} saveControl={<SavedProductButton productId={product.id} />}
                                    href={route('buyer.products.show', product.slug)}
                                />
                            ))}
                        </div>
                    )}

                    {/* Related Products Discovery Feed (when search query produced matches) */}
                    {filters.search && feedProducts.data.length > 0 && relatedProducts && relatedProducts.length > 0 && (
                        <div className="bg-white rounded-2xl p-5 shadow-xs border border-slate-200 space-y-4 mt-8 font-sans">
                            <div className="flex items-center gap-2 pb-2 border-b border-slate-100">
                                <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                <h4 className="font-bold text-slate-900 text-xs uppercase tracking-wider">Related Recommendations</h4>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5 sm:gap-4">
                                {relatedProducts.map((p) => (
                                    <ProductCard
                                        key={p.id}
                                        product={p} saveControl={<SavedProductButton productId={p.id} />}
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
