import React, { useState, useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import GrainOverlay from '@/Components/GrainOverlay';
import { Category, PaginatedData, Product, Shop } from '@/types';
import { 
    Search, 
    SlidersHorizontal, 
    ShoppingBag, 
    Truck, 
    Star, 
    ArrowRight, 
    Check, 
    Plus, 
    Filter, 
    Sparkles, 
    Store, 
    Clock, 
    X,
    Laptop,
    Shirt,
    Compass,
    Footprints,
    Home,
    Smartphone,
    Headphones,
    Activity,
    Watch,
    Car,
    PenTool,
    HeartPulse,
    ShieldCheck
} from 'lucide-react';

interface ActiveShipment {
    order_id: number;
    order_number: string;
    status: string;
    tracking_number: string;
    courier_name: string;
    estimated_delivery: string;
    item_name: string;
    item_count: number;
}

interface Props {
    products: PaginatedData<Product>;
    categories: (Category & { products_count?: number })[];
    featuredShops: Shop[];
    activeShipment?: ActiveShipment | null;
    filters: {
        search?: string;
        category?: string;
        sort?: string;
        in_stock?: boolean | string;
        min_price?: string;
        max_price?: string;
    };
}

export default function Catalog({ products, categories, activeShipment, filters }: Props) {
    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [selectedCategory, setSelectedCategory] = useState(filters.category || 'all');
    const [selectedSort, setSelectedSort] = useState(filters.sort || 'latest');
    const [inStockOnly, setInStockOnly] = useState(filters.in_stock === true || filters.in_stock === '1');
    const [addingProductId, setAddingProductId] = useState<number | null>(null);
    const [addedSuccessId, setAddedSuccessId] = useState<number | null>(null);

    // Dynamic icon resolver for the 14 verified departments
    const getCategoryIcon = (iconName: string) => {
        switch (iconName?.toLowerCase()) {
            case 'laptop':
            case 'consumer-electronics':
                return Laptop;
            case 'shirt':
            case 'apparel-and-footwear':
                return Shirt;
            case 'compass':
            case 'travel-and-accessories':
                return Compass;
            case 'footprints':
            case 'footwear-and-sneakers':
                return Footprints;
            case 'home':
            case 'smart-home-and-living':
                return Home;
            case 'sparkles':
            case 'beauty-and-grooming':
                return Sparkles;
            case 'smartphone':
            case 'mobile-and-gadgets':
                return Smartphone;
            case 'headphones':
            case 'audio-and-spatial-sound':
                return Headphones;
            case 'activity':
            case 'sports-and-outdoor':
                return Activity;
            case 'watch':
            case 'watches-and-edc':
                return Watch;
            case 'car':
            case 'automotive-essentials':
                return Car;
            case 'pentool':
            case 'desk-studio-and-stationery':
                return PenTool;
            case 'heartpulse':
            case 'health-and-wellness':
                return HeartPulse;
            default:
                return ShoppingBag;
        }
    };

    const applyFilters = (newParams: Partial<typeof filters>) => {
        const query: Record<string, any> = {
            search: searchQuery || undefined,
            category: selectedCategory !== 'all' ? selectedCategory : undefined,
            sort: selectedSort !== 'latest' ? selectedSort : undefined,
            in_stock: inStockOnly ? '1' : undefined,
            ...newParams,
        };

        // Clean up undefined
        Object.keys(query).forEach(k => query[k] === undefined && delete query[k]);

        router.get(route('products.index'), query, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilters({ search: searchQuery });
    };

    const handleCategoryClick = (slug: string) => {
        setSelectedCategory(slug);
        applyFilters({ category: slug !== 'all' ? slug : undefined });
    };

    const handleSortChange = (sort: string) => {
        setSelectedSort(sort);
        applyFilters({ sort });
    };

    const handleInStockToggle = () => {
        const next = !inStockOnly;
        setInStockOnly(next);
        applyFilters({ in_stock: next ? '1' : undefined });
    };

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

    const clearAllFilters = () => {
        setSearchQuery('');
        setSelectedCategory('all');
        setSelectedSort('latest');
        setInStockOnly(false);
        router.get(route('products.index'), {}, { preserveScroll: true });
    };

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    return (
        <MarketplaceLayout headerTheme="light">
            <Head title="Buyer Marketplace & Catalog — BagooPH" />

            <div className="relative bg-[#ECEAE5] min-h-screen text-[#111111] selection:bg-[#E00D42] selection:text-white pb-24">
                <GrainOverlay />

                {/* 1. TOP BUYER TELEMETRY & BANNER */}
                <div className="border-b border-black/15 bg-[#F3F0EA] relative z-20">
                    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
                        <div className="flex flex-col md:flex-row items-center justify-between gap-3 text-[11px] font-mono">
                            {/* Left: Active Voucher Incentive */}
                            <div className="flex items-center gap-2 text-black/80">
                                <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                                <span className="font-bold uppercase tracking-wider text-black">BUYER PRIVILEGE:</span>
                                <span>USE CODE <strong className="text-[#E00D42] bg-[#E00D42]/10 px-1.5 py-0.5 rounded border border-[#E00D42]/20">BAGOO10</strong> FOR 10% OFF FIRST CHECKOUT</span>
                            </div>

                            {/* Right: Security & Dispatch Assurance */}
                            <div className="flex items-center gap-4 text-black/60 uppercase">
                                <span className="flex items-center gap-1"><ShieldCheck className="w-3.5 h-3.5 text-[#E00D42]" /> 100% KYC Verified Stores</span>
                                <span className="text-black/20">•</span>
                                <span className="flex items-center gap-1"><Truck className="w-3.5 h-3.5 text-black" /> Direct Courier Fleet</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-12 relative z-20">
                    
                    {/* 2. ACTIVE ORDER DISPATCH TELEMETRY (If Authenticated Buyer has In-Transit Package) */}
                    {activeShipment && (
                        <div className="mb-8 p-4 sm:p-5 rounded-xl bg-black text-white border border-black/20 shadow-lg relative overflow-hidden flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                            <div className="flex items-center gap-3.5">
                                <div className="w-10 h-10 rounded-lg bg-[#E00D42] flex items-center justify-center text-white shrink-0">
                                    <Truck className="w-5 h-5 animate-bounce" />
                                </div>
                                <div className="space-y-0.5">
                                    <div className="flex items-center gap-2 font-mono text-[10px] uppercase text-white/60">
                                        <span>LIVE SHIPMENT TELEMETRY</span>
                                        <span>•</span>
                                        <span className="text-emerald-400 font-bold">STATUS: {activeShipment.status.replace('_', ' ').toUpperCase()}</span>
                                    </div>
                                    <h3 className="text-sm font-bold text-white flex items-center gap-2">
                                        <span>Order #{activeShipment.order_number}</span>
                                        <span className="text-white/40 font-normal">— {activeShipment.item_name} {activeShipment.item_count > 1 ? `(+${activeShipment.item_count - 1} more)` : ''}</span>
                                    </h3>
                                    <p className="font-mono text-[11px] text-white/70">
                                        Tracking: <span className="text-[#E00D42] font-bold">{activeShipment.tracking_number}</span> ({activeShipment.courier_name}) • Est. Delivery: <strong className="text-white">{activeShipment.estimated_delivery}</strong>
                                    </p>
                                </div>
                            </div>

                            <Link
                                href={route('orders.show', activeShipment.order_id)}
                                className="px-4 py-2 bg-white hover:bg-[#E00D42] text-black hover:text-white rounded-lg font-mono text-xs font-bold uppercase tracking-wider transition shrink-0 flex items-center gap-2"
                            >
                                <span>Track Delivery</span>
                                <ArrowRight className="w-3.5 h-3.5" />
                            </Link>
                        </div>
                    )}

                    {/* 3. HERO HEADER SECTION */}
                    <div className="mb-8 border-b border-black/15 pb-6">
                        <div className="flex flex-col md:flex-row md:items-end justify-between gap-4 font-mono">
                            <div>
                                <span className="inline-block px-2.5 py-0.5 mb-2 rounded bg-black text-white text-[10px] font-bold uppercase tracking-widest">
                                    BUYER PORTAL // VERIFIED DIRECTORY
                                </span>
                                <h1 className="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight text-black font-sans">
                                    PRODUCT CATALOG
                                </h1>
                                <p className="text-xs text-black/60 font-mono mt-1 uppercase max-w-2xl">
                                    14 OFFICIAL DEPARTMENTS • 100% REGULATED INVENTORY • LIVE DOORSTEP TELEMETRY
                                </p>
                            </div>

                            <div className="text-right hidden md:block">
                                <span className="block text-2xl font-black font-sans text-black">{products.total}</span>
                                <span className="block text-[10px] text-black/50 uppercase tracking-widest font-bold">AVAILABLE PRODUCTS</span>
                            </div>
                        </div>
                    </div>

                    {/* 4. 14 VERIFIED PRODUCT DEPARTMENTS SELECTOR */}
                    <div className="mb-8">
                        <div className="flex items-center justify-between mb-3 font-mono text-xs font-bold uppercase text-black/70">
                            <span>Browse by Department</span>
                            <span className="text-[10px] text-black/40">14 Verified Categories</span>
                        </div>

                        <div className="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none font-mono text-xs">
                            {/* All Departments Button */}
                            <button
                                type="button"
                                onClick={() => handleCategoryClick('all')}
                                className={`px-3.5 py-2 rounded-lg border font-bold uppercase text-[11px] whitespace-nowrap transition flex items-center gap-2 ${
                                    selectedCategory === 'all'
                                        ? 'bg-[#E00D42] text-white border-[#E00D42] shadow-xs'
                                        : 'bg-white text-black/80 border-black/15 hover:border-black'
                                }`}
                            >
                                <ShoppingBag className="w-3.5 h-3.5" />
                                <span>All Departments</span>
                                <span className={`px-1.5 py-0.2 rounded text-[9px] ${
                                    selectedCategory === 'all' ? 'bg-white/20 text-white' : 'bg-black/5 text-black/60'
                                }`}>
                                    {categories.reduce((acc, c) => acc + (c.products_count || 0), 0)}
                                </span>
                            </button>

                            {/* 14 Departments */}
                            {categories.map((cat) => {
                                const IconComponent = getCategoryIcon(cat.slug);
                                const isSelected = selectedCategory === cat.slug;

                                return (
                                    <button
                                        key={cat.id}
                                        type="button"
                                        onClick={() => handleCategoryClick(cat.slug)}
                                        className={`px-3 py-2 rounded-lg border font-bold uppercase text-[11px] whitespace-nowrap transition flex items-center gap-1.5 ${
                                            isSelected
                                                ? 'bg-[#E00D42] text-white border-[#E00D42] shadow-xs'
                                                : 'bg-white text-black/80 border-black/15 hover:border-black'
                                        }`}
                                    >
                                        <IconComponent className="w-3.5 h-3.5" />
                                        <span>{cat.name}</span>
                                        {cat.products_count !== undefined && (
                                            <span className={`px-1.5 py-0.2 rounded text-[9px] ${
                                                isSelected ? 'bg-white/20 text-white' : 'bg-black/5 text-black/60'
                                            }`}>
                                                {cat.products_count}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {/* 5. SEARCH & CONTROL TOOLBAR */}
                    <div className="mb-8 bg-white p-3.5 rounded-xl border border-black/15 shadow-sm font-mono text-xs">
                        <div className="flex flex-col lg:flex-row items-center justify-between gap-3">
                            
                            {/* Search Input */}
                            <form onSubmit={handleSearchSubmit} className="relative w-full lg:w-96">
                                <Search className="w-4 h-4 text-black/40 absolute left-3 top-1/2 -translate-y-1/2" />
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search products, keywords, or SKU..."
                                    className="w-full pl-9 pr-8 py-2 text-xs bg-[#F4F2EC] border border-black/15 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden transition"
                                />
                                {searchQuery && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearchQuery('');
                                            applyFilters({ search: undefined });
                                        }}
                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-black/40 hover:text-black"
                                    >
                                        <X className="w-3.5 h-3.5" />
                                    </button>
                                )}
                            </form>

                            {/* Sort & In-Stock Controls */}
                            <div className="flex items-center justify-between lg:justify-end w-full lg:w-auto gap-3 text-xs">
                                
                                {/* In Stock Toggle */}
                                <label className="flex items-center gap-2 cursor-pointer select-none px-3 py-1.5 rounded-lg border border-black/15 hover:bg-black/5 transition">
                                    <input
                                        type="checkbox"
                                        checked={inStockOnly}
                                        onChange={handleInStockToggle}
                                        className="rounded border-black/30 text-[#E00D42] focus:ring-[#E00D42]"
                                    />
                                    <span className="text-[11px] font-bold uppercase text-black/80">In Stock Only</span>
                                </label>

                                {/* Sort Dropdown */}
                                <div className="flex items-center gap-2">
                                    <span className="text-[11px] text-black/50 uppercase font-bold hidden sm:inline">Sort:</span>
                                    <select
                                        value={selectedSort}
                                        onChange={(e) => handleSortChange(e.target.value)}
                                        className="py-1.5 pl-3 pr-8 text-xs bg-[#F4F2EC] border border-black/15 rounded-lg focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] outline-hidden uppercase font-bold"
                                    >
                                        <option value="latest">Latest Arrivals</option>
                                        <option value="popular">Most Popular</option>
                                        <option value="rating">Top Customer Rated</option>
                                        <option value="price_asc">Price: Low to High</option>
                                        <option value="price_desc">Price: High to Low</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Active Filter Pills Bar */}
                        {(selectedCategory !== 'all' || searchQuery || inStockOnly || selectedSort !== 'latest') && (
                            <div className="mt-3 pt-3 border-t border-black/10 flex items-center justify-between text-[11px]">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="text-black/50 font-bold uppercase">Active Filters:</span>
                                    {selectedCategory !== 'all' && (
                                        <span className="px-2 py-0.5 bg-black text-white rounded text-[10px] font-bold uppercase flex items-center gap-1">
                                            Dept: {categories.find(c => c.slug === selectedCategory)?.name || selectedCategory}
                                            <button onClick={() => handleCategoryClick('all')}><X className="w-3 h-3" /></button>
                                        </span>
                                    )}
                                    {searchQuery && (
                                        <span className="px-2 py-0.5 bg-black text-white rounded text-[10px] font-bold uppercase flex items-center gap-1">
                                            Query: "{searchQuery}"
                                            <button onClick={() => { setSearchQuery(''); applyFilters({ search: undefined }); }}><X className="w-3 h-3" /></button>
                                        </span>
                                    )}
                                    {inStockOnly && (
                                        <span className="px-2 py-0.5 bg-emerald-700 text-white rounded text-[10px] font-bold uppercase flex items-center gap-1">
                                            In Stock
                                            <button onClick={handleInStockToggle}><X className="w-3 h-3" /></button>
                                        </span>
                                    )}
                                </div>

                                <button
                                    type="button"
                                    onClick={clearAllFilters}
                                    className="text-[#E00D42] hover:underline font-bold uppercase text-[10px]"
                                >
                                    Reset All
                                </button>
                            </div>
                        )}
                    </div>

                    {/* 6. PRODUCT GRID */}
                    {products.data.length === 0 ? (
                        <div className="bg-white rounded-2xl border border-black/15 p-12 text-center space-y-4 max-w-lg mx-auto font-mono">
                            <ShoppingBag className="w-12 h-12 text-black/20 mx-auto" />
                            <h3 className="text-lg font-bold text-black font-sans">No matching products found</h3>
                            <p className="text-xs text-black/60 uppercase">Try adjusting your keyword search, selecting a different department, or resetting active filters.</p>
                            <button
                                type="button"
                                onClick={clearAllFilters}
                                className="px-4 py-2 bg-black text-white font-bold text-xs rounded-lg uppercase tracking-wider hover:bg-[#E00D42] transition"
                            >
                                Clear All Filters
                            </button>
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                            {products.data.map((product) => {
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
                                        className="group bg-white rounded-xl border border-black/15 overflow-hidden flex flex-col justify-between hover:shadow-xl hover:border-black/30 transition duration-300 relative"
                                    >
                                        {/* Precision Crosshair Corner Accents */}
                                        <span className="absolute top-2 left-2 text-black/20 font-mono text-[9px] select-none pointer-events-none">+</span>
                                        <span className="absolute top-2 right-2 text-black/20 font-mono text-[9px] select-none pointer-events-none">+</span>

                                        <div>
                                            {/* Product Thumbnail Container */}
                                            <Link href={route('products.show', product.slug)} className="block relative aspect-square bg-[#F4F2EC] overflow-hidden">
                                                <img
                                                    src={product.featured_image || ''}
                                                    alt={product.name}
                                                    className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                                />

                                                {/* Top Badges */}
                                                <div className="absolute top-3 left-3 right-3 flex items-center justify-between gap-1 pointer-events-none">
                                                    {product.category && (
                                                        <span className="px-2 py-0.5 rounded bg-black/80 backdrop-blur-xs text-white font-mono text-[9px] font-bold uppercase tracking-wider">
                                                            {product.category.name}
                                                        </span>
                                                    )}

                                                    {discountPct && (
                                                        <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[10px] font-black tracking-wider shadow-xs">
                                                            -{discountPct}%
                                                        </span>
                                                    )}
                                                </div>

                                                {/* Stock Status Badge */}
                                                <div className="absolute bottom-3 left-3 pointer-events-none">
                                                    {product.stock > 0 ? (
                                                        <span className="px-2 py-0.5 rounded-md bg-white/90 backdrop-blur-xs text-black font-mono text-[9px] font-bold uppercase flex items-center gap-1 shadow-2xs">
                                                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                                            <span>{product.stock <= 20 ? `LOW: ${product.stock} UNITS` : `${product.stock} IN STOCK`}</span>
                                                        </span>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded-md bg-rose-600 text-white font-mono text-[9px] font-bold uppercase">
                                                            OUT OF STOCK
                                                        </span>
                                                    )}
                                                </div>
                                            </Link>

                                            {/* Product Details Section */}
                                            <div className="p-4 space-y-2 font-mono">
                                                
                                                {/* Shop & Rating Info */}
                                                <div className="flex items-center justify-between text-[10px] text-black/60 uppercase">
                                                    {product.shop ? (
                                                        <Link 
                                                            href={route('shop.show', product.shop.slug)}
                                                            className="flex items-center gap-1 font-bold text-black hover:text-[#E00D42] transition truncate max-w-[140px]"
                                                        >
                                                            <Store className="w-3 h-3 text-[#E00D42]" />
                                                            <span className="truncate">{product.shop.name}</span>
                                                        </Link>
                                                    ) : (
                                                        <span>Official Store</span>
                                                    )}

                                                    <div className="flex items-center gap-1 text-black font-bold">
                                                        <Star className="w-3 h-3 fill-amber-400 text-amber-400" />
                                                        <span>{Number(product.rating || 5.0).toFixed(1)}</span>
                                                    </div>
                                                </div>

                                                {/* Product Title */}
                                                <Link 
                                                    href={route('products.show', product.slug)}
                                                    className="block font-sans font-bold text-sm text-black group-hover:text-[#E00D42] transition line-clamp-2 leading-snug"
                                                >
                                                    {product.name}
                                                </Link>

                                                {/* Price Section */}
                                                <div className="pt-1 flex items-baseline gap-2">
                                                    <span className="text-base font-black font-sans text-black">
                                                        {formatPrice(product.price)}
                                                    </span>
                                                    {product.compare_at_price && product.compare_at_price > product.price && (
                                                        <span className="text-xs text-black/40 line-through">
                                                            {formatPrice(product.compare_at_price)}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Bottom Action Footer */}
                                        <div className="p-4 pt-0">
                                            <button
                                                type="button"
                                                onClick={(e) => handleAddToCart(e, product.id)}
                                                disabled={isAdding || product.stock <= 0}
                                                className={`w-full py-2.5 rounded-lg font-mono text-xs font-bold uppercase tracking-wider transition flex items-center justify-center gap-1.5 shadow-2xs ${
                                                    isSuccess
                                                        ? 'bg-emerald-600 text-white'
                                                        : product.stock <= 0
                                                            ? 'bg-black/10 text-black/40 cursor-not-allowed'
                                                            : 'bg-black hover:bg-[#E00D42] text-white active:scale-[0.98]'
                                                }`}
                                            >
                                                {isSuccess ? (
                                                    <>
                                                        <Check className="w-3.5 h-3.5" />
                                                        <span>Added to Bag</span>
                                                    </>
                                                ) : isAdding ? (
                                                    <span>Adding...</span>
                                                ) : product.stock <= 0 ? (
                                                    <span>Unavailable</span>
                                                ) : (
                                                    <>
                                                        <ShoppingBag className="w-3.5 h-3.5" />
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

                    {/* 7. PAGINATION LINKS */}
                    {products.links && products.links.length > 3 && (
                        <div className="mt-12 flex items-center justify-center gap-1 font-mono text-xs">
                            {products.links.map((link, idx) => (
                                <Link
                                    key={idx}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`px-3.5 py-2 rounded-lg border font-bold uppercase transition ${
                                        link.active
                                            ? 'bg-[#E00D42] text-white border-[#E00D42]'
                                            : link.url
                                                ? 'bg-white text-black border-black/15 hover:border-black'
                                                : 'bg-black/5 text-black/30 border-black/10 cursor-not-allowed'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </MarketplaceLayout>
    );
}
