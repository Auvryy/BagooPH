import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { Category, PaginatedData, Product } from '@/types';
import { 
    Search as SearchIcon, 
    SlidersHorizontal, 
    X, 
    RotateCcw, 
    Star, 
    Truck, 
    ChevronRight, 
    ShoppingBag, 
    Sparkles, 
    ChevronDown,
    Filter,
    ArrowUpDown,
    Check
} from 'lucide-react';

interface Props {
    products: PaginatedData<Product>;
    categories: (Category & { products_count?: number })[];
    relatedProducts?: Product[];
    filters: {
        search?: string;
        category?: string;
        sort?: string;
        min_price?: string;
        max_price?: string;
        in_stock?: boolean | string;
        rating?: string;
    };
}

export default function SearchPage({
    products,
    categories,
    relatedProducts = [],
    filters,
}: Props) {
    const [minPrice, setMinPrice] = useState(filters.min_price || '');
    const [maxPrice, setMaxPrice] = useState(filters.max_price || '');
    const [mobileFilterOpen, setMobileFilterOpen] = useState(false);

    const formatPrice = (amount?: number | string | null) => {
        const numeric = Number(amount || 0);
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            minimumFractionDigits: 2,
        }).format(numeric);
    };

    const applyFilter = (updates: Record<string, any>) => {
        const nextFilters: Record<string, any> = {
            ...filters,
            ...updates,
        };

        // Strip empty or default values
        Object.keys(nextFilters).forEach((key) => {
            if (
                nextFilters[key] === '' ||
                nextFilters[key] === undefined ||
                nextFilters[key] === null ||
                nextFilters[key] === 'all' ||
                nextFilters[key] === false
            ) {
                delete nextFilters[key];
            }
        });

        router.get(route('buyer.search'), nextFilters, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handlePriceSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilter({
            min_price: minPrice.trim() || undefined,
            max_price: maxPrice.trim() || undefined,
        });
    };

    const handlePricePreset = (min?: number, max?: number) => {
        setMinPrice(min ? min.toString() : '');
        setMaxPrice(max ? max.toString() : '');
        applyFilter({
            min_price: min ? min.toString() : undefined,
            max_price: max ? max.toString() : undefined,
        });
    };

    const clearAllFilters = () => {
        setMinPrice('');
        setMaxPrice('');
        router.get(route('buyer.search'), filters.search ? { search: filters.search } : {});
    };

    const activeCategory = categories.find((c) => c.slug === filters.category);

    return (
        <BuyerLayout categories={categories}>
            <Head title={filters.search ? `Search: "${filters.search}" — BagooPH` : 'Product Catalog & Search — BagooPH'} />

            <div className="space-y-6">
                
                {/* 1. BREADCRUMBS & TOP TITLE */}
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 font-mono text-xs pb-2 border-b border-slate-200">
                    <div className="flex items-center gap-2 text-slate-500">
                        <Link href={route('buyer.index')} className="hover:text-[#E00D42] transition">Marketplace</Link>
                        <span>/</span>
                        <Link href={route('buyer.search')} className="hover:text-[#E00D42] transition">Search Catalog</Link>
                        {filters.search && (
                            <>
                                <span>/</span>
                                <span className="font-bold text-slate-900 truncate max-w-[200px]">"{filters.search}"</span>
                            </>
                        )}
                        {activeCategory && (
                            <>
                                <span>/</span>
                                <span className="font-bold text-[#E00D42]">{activeCategory.name}</span>
                            </>
                        )}
                    </div>

                    <div className="flex items-center gap-3 text-slate-500">
                        <span>Showing <strong className="text-slate-900">{products.total}</strong> products found</span>
                    </div>
                </div>

                {/* 2. MAIN TWO-COLUMN SEARCH & FILTER LAYOUT */}
                <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                    
                    {/* LEFT SIDEBAR FILTERS (DESKTOP) */}
                    <aside className="hidden lg:block lg:col-span-3 bg-white rounded-2xl p-5 border border-slate-200 shadow-xs space-y-6 sticky top-24 font-mono text-xs">
                        
                        {/* Filter Header */}
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100">
                            <div className="flex items-center gap-2 font-bold text-slate-900 text-sm uppercase">
                                <Filter className="w-4 h-4 text-[#E00D42]" />
                                <span>Filter By</span>
                            </div>
                            <button
                                type="button"
                                onClick={clearAllFilters}
                                className="text-[11px] font-bold text-slate-500 hover:text-[#E00D42] flex items-center gap-1 transition"
                            >
                                <RotateCcw className="w-3 h-3" />
                                <span>Reset</span>
                            </button>
                        </div>

                        {/* 1. All 14 Departments */}
                        <div className="space-y-2.5">
                            <h4 className="font-bold text-slate-900 uppercase text-[11px] tracking-wider">Department</h4>
                            <div className="space-y-1 max-h-56 overflow-y-auto pr-1 scrollbar-thin">
                                <button
                                    type="button"
                                    onClick={() => applyFilter({ category: undefined })}
                                    className={`w-full text-left px-2.5 py-1.5 rounded-lg font-sans transition flex items-center justify-between text-xs ${
                                        !filters.category || filters.category === 'all'
                                            ? 'bg-[#E00D42] text-white font-bold'
                                            : 'text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    <span>All Departments</span>
                                    <span className="text-[10px] opacity-80">{categories.reduce((acc, c) => acc + (c.products_count || 0), 0)}</span>
                                </button>
                                {categories.map((cat) => (
                                    <button
                                        key={cat.id}
                                        type="button"
                                        onClick={() => applyFilter({ category: cat.slug })}
                                        className={`w-full text-left px-2.5 py-1.5 rounded-lg font-sans transition flex items-center justify-between text-xs ${
                                            filters.category === cat.slug
                                                ? 'bg-[#E00D42] text-white font-bold'
                                                : 'text-slate-700 hover:bg-slate-50'
                                        }`}
                                    >
                                        <span className="truncate">{cat.name}</span>
                                        <span className="text-[10px] opacity-75 font-mono">({cat.products_count ?? 0})</span>
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* 2. Price Range */}
                        <div className="space-y-2.5 pt-4 border-t border-slate-100">
                            <h4 className="font-bold text-slate-900 uppercase text-[11px] tracking-wider">Price Range (₱)</h4>
                            
                            {/* Price Presets */}
                            <div className="grid grid-cols-2 gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => handlePricePreset(undefined, 500)}
                                    className="p-1.5 rounded-md bg-slate-50 hover:bg-slate-100 border border-slate-200 text-center text-[10px] text-slate-700 transition"
                                >
                                    Under ₱500
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handlePricePreset(500, 1500)}
                                    className="p-1.5 rounded-md bg-slate-50 hover:bg-slate-100 border border-slate-200 text-center text-[10px] text-slate-700 transition"
                                >
                                    ₱500 - ₱1.5k
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handlePricePreset(1500, 5000)}
                                    className="p-1.5 rounded-md bg-slate-50 hover:bg-slate-100 border border-slate-200 text-center text-[10px] text-slate-700 transition"
                                >
                                    ₱1.5k - ₱5k
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handlePricePreset(5000, undefined)}
                                    className="p-1.5 rounded-md bg-slate-50 hover:bg-slate-100 border border-slate-200 text-center text-[10px] text-slate-700 transition"
                                >
                                    ₱5k & above
                                </button>
                            </div>

                            {/* Custom Price Form */}
                            <form onSubmit={handlePriceSubmit} className="space-y-2 pt-1">
                                <div className="flex items-center gap-1.5">
                                    <input
                                        type="number"
                                        placeholder="Min ₱"
                                        value={minPrice}
                                        onChange={(e) => setMinPrice(e.target.value)}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2"
                                    />
                                    <span className="text-slate-400">-</span>
                                    <input
                                        type="number"
                                        placeholder="Max ₱"
                                        value={maxPrice}
                                        onChange={(e) => setMaxPrice(e.target.value)}
                                        className="w-full bg-slate-50 border border-slate-200 rounded-lg text-xs py-1.5 px-2"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="w-full py-1.5 bg-slate-900 hover:bg-black text-white rounded-lg font-bold uppercase transition text-[11px]"
                                >
                                    Apply Price
                                </button>
                            </form>
                        </div>

                        {/* 3. Availability & Rating */}
                        <div className="space-y-3 pt-4 border-t border-slate-100">
                            <h4 className="font-bold text-slate-900 uppercase text-[11px] tracking-wider">Availability & Rating</h4>
                            
                            {/* In Stock Checkbox */}
                            <label className="flex items-center gap-2.5 cursor-pointer select-none">
                                <input
                                    type="checkbox"
                                    checked={Boolean(filters.in_stock)}
                                    onChange={(e) => applyFilter({ in_stock: e.target.checked ? true : undefined })}
                                    className="rounded border-slate-300 text-[#E00D42] focus:ring-[#E00D42]"
                                />
                                <span className="text-slate-700 font-sans text-xs">In Stock Only</span>
                            </label>

                            {/* Rating Radio Buttons */}
                            <div className="space-y-1 pt-1 font-sans text-xs">
                                <button
                                    type="button"
                                    onClick={() => applyFilter({ rating: undefined })}
                                    className={`w-full text-left px-2 py-1 rounded flex items-center gap-1.5 ${
                                        !filters.rating ? 'bg-slate-100 font-bold text-slate-900' : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    <span>All Ratings</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => applyFilter({ rating: '4' })}
                                    className={`w-full text-left px-2 py-1 rounded flex items-center gap-1.5 ${
                                        filters.rating === '4' ? 'bg-slate-100 font-bold text-slate-900' : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="flex text-amber-400">
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                    </div>
                                    <span className="font-mono text-[11px]">& up</span>
                                </button>
                                <button
                                    type="button"
                                    onClick={() => applyFilter({ rating: '4.5' })}
                                    className={`w-full text-left px-2 py-1 rounded flex items-center gap-1.5 ${
                                        filters.rating === '4.5' ? 'bg-slate-100 font-bold text-slate-900' : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="flex text-amber-400">
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                        <Star className="w-3.5 h-3.5 fill-amber-400" />
                                    </div>
                                    <span className="font-mono text-[11px]">4.5+ Stars</span>
                                </button>
                            </div>
                        </div>

                    </aside>

                    {/* RIGHT MAIN SEARCH RESULTS & FEED */}
                    <div className="lg:col-span-9 space-y-4">
                        
                        {/* SEARCH CONTROLS HEADER BAR */}
                        <div className="bg-white rounded-2xl p-4 shadow-xs border border-slate-200 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 font-mono text-xs">
                            
                            {/* Search Keyword Headline */}
                            <div className="flex items-center gap-2">
                                <SearchIcon className="w-4 h-4 text-[#E00D42]" />
                                <span className="font-black text-slate-900 text-sm truncate">
                                    {filters.search ? `"${filters.search}"` : activeCategory ? activeCategory.name : 'ALL PRODUCTS'}
                                </span>
                                <span className="px-2 py-0.5 rounded bg-slate-100 text-slate-600 text-[10px] font-bold">
                                    {products.total} Results
                                </span>
                            </div>

                            {/* Controls: Sort Dropdown & Mobile Filter Button */}
                            <div className="flex items-center gap-2 justify-between sm:justify-end">
                                
                                {/* Mobile Filter Toggle Button */}
                                <button
                                    type="button"
                                    onClick={() => setMobileFilterOpen(!mobileFilterOpen)}
                                    className="lg:hidden px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold flex items-center gap-1.5 transition"
                                >
                                    <SlidersHorizontal className="w-3.5 h-3.5" />
                                    <span>Filters</span>
                                </button>

                                {/* Sort By Selector */}
                                <div className="flex items-center gap-1.5">
                                    <span className="text-slate-400 hidden sm:inline">Sort:</span>
                                    <select
                                        value={filters.sort || 'relevance'}
                                        onChange={(e) => applyFilter({ sort: e.target.value })}
                                        className="bg-slate-50 border border-slate-200 rounded-lg text-xs font-mono font-bold text-slate-800 py-1.5 pl-2.5 pr-8 focus:ring-[#E00D42] focus:border-[#E00D42]"
                                    >
                                        <option value="relevance">Relevance</option>
                                        <option value="top_sales">Best Sellers</option>
                                        <option value="price_asc">Price: Low to High</option>
                                        <option value="price_desc">Price: High to Low</option>
                                        <option value="top_rated">Top Customer Rated</option>
                                        <option value="newest">Newest Arrivals</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* ACTIVE FILTER PILLS (Removable) */}
                        {(filters.search || filters.category || filters.min_price || filters.max_price || filters.in_stock || filters.rating) && (
                            <div className="flex items-center gap-2 flex-wrap font-mono text-[11px] bg-slate-50 p-2.5 rounded-xl border border-slate-200/80">
                                <span className="font-bold text-slate-400 text-[10px] uppercase">Active:</span>

                                {filters.search && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-800 shadow-2xs">
                                        <span>"{filters.search}"</span>
                                        <button onClick={() => applyFilter({ search: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {activeCategory && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-800 shadow-2xs">
                                        <span>Dept: {activeCategory.name}</span>
                                        <button onClick={() => applyFilter({ category: undefined })} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {(filters.min_price || filters.max_price) && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-white border border-slate-200 text-slate-800 shadow-2xs">
                                        <span>₱{filters.min_price || '0'} – ₱{filters.max_price || '∞'}</span>
                                        <button onClick={() => { setMinPrice(''); setMaxPrice(''); applyFilter({ min_price: undefined, max_price: undefined }); }} className="hover:text-[#E00D42]">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.in_stock && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 shadow-2xs">
                                        <span>In Stock</span>
                                        <button onClick={() => applyFilter({ in_stock: undefined })} className="hover:text-emerald-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                {filters.rating && (
                                    <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-md bg-amber-50 border border-amber-200 text-amber-800 shadow-2xs">
                                        <span>{filters.rating}★+</span>
                                        <button onClick={() => applyFilter({ rating: undefined })} className="hover:text-amber-950">
                                            <X className="w-3 h-3" />
                                        </button>
                                    </span>
                                )}

                                <button
                                    onClick={clearAllFilters}
                                    className="text-[10px] font-bold text-[#E00D42] hover:underline ml-auto"
                                >
                                    Clear All
                                </button>
                            </div>
                        )}

                        {/* PRODUCT RESULTS GRID */}
                        {products.data.length === 0 ? (
                            <div className="space-y-6">
                                <div className="bg-white rounded-2xl p-12 text-center space-y-3 border border-slate-200 shadow-xs">
                                    <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                                    <h3 className="text-base font-bold text-slate-800 font-sans">No products matched your search</h3>
                                    <p className="text-xs text-slate-500 font-mono max-w-md mx-auto">
                                        We couldn't find exact matches for your filters. Try checking spelling, using more general keywords, or resetting your filters.
                                    </p>
                                    <button
                                        onClick={clearAllFilters}
                                        className="mt-2 px-5 py-2.5 bg-[#E00D42] text-white rounded-lg text-xs font-mono font-bold uppercase hover:bg-[#C20836] transition shadow-xs"
                                    >
                                        Clear All Filters
                                    </button>
                                </div>

                                {/* Smart Fallback Recommendations */}
                                {relatedProducts && relatedProducts.length > 0 && (
                                    <div className="bg-white rounded-2xl p-6 border border-slate-200 shadow-xs space-y-4">
                                        <div className="flex items-center gap-2 font-mono pb-2 border-b border-slate-100">
                                            <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                            <h4 className="font-bold text-slate-900 text-xs uppercase">Trending Products You Might Like</h4>
                                        </div>
                                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                            {relatedProducts.map((product) => (
                                                <Link
                                                    key={product.id}
                                                    href={route('buyer.products.show', product.slug)}
                                                    className="group rounded-xl border border-slate-200 overflow-hidden p-2.5 hover:border-[#E00D42] hover:shadow-md transition flex flex-col justify-between"
                                                >
                                                    <div className="aspect-square rounded-lg overflow-hidden bg-slate-100 mb-2">
                                                        <img src={product.featured_image || ''} alt={product.name} className="w-full h-full object-cover group-hover:scale-105 transition" />
                                                    </div>
                                                    <div>
                                                        <h5 className="font-sans font-bold text-xs text-slate-800 line-clamp-2 group-hover:text-[#E00D42] transition">{product.name}</h5>
                                                        <span className="font-mono font-black text-sm text-[#E00D42] mt-1 block">{formatPrice(product.price)}</span>
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                {products.data.map((product) => {
                                    const priceNum = Number(product.price);
                                    const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
                                    const discountPct = compareNum && compareNum > priceNum 
                                        ? Math.round(((compareNum - priceNum) / compareNum) * 100)
                                        : null;

                                    return (
                                        <Link
                                            key={product.id}
                                            href={route('buyer.products.show', product.slug)}
                                            className="group bg-white rounded-xl border border-slate-200 overflow-hidden hover:shadow-xl hover:border-[#E00D42]/60 transition duration-300 flex flex-col justify-between shadow-2xs"
                                        >
                                            {/* Product Image & Badges */}
                                            <div className="block relative aspect-square bg-slate-100 overflow-hidden">
                                                <img
                                                    src={product.featured_image || ''}
                                                    alt={product.name}
                                                    className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                                />

                                                {/* MALL Badge */}
                                                <div className="absolute top-2 left-2 flex flex-col gap-1">
                                                    <span className="px-1.5 py-0.5 rounded bg-[#E00D42] text-white font-mono text-[9px] font-black tracking-wider shadow-2xs">
                                                        MALL
                                                    </span>
                                                </div>

                                                {/* Discount Percent */}
                                                {discountPct && (
                                                    <span className="absolute top-2 right-2 px-1.5 py-0.5 rounded bg-amber-400 text-slate-950 font-mono text-[9px] font-black shadow-2xs">
                                                        -{discountPct}%
                                                    </span>
                                                )}

                                                {/* Free Delivery Tag */}
                                                <div className="absolute bottom-2 left-2">
                                                    <span className="px-1.5 py-0.5 rounded bg-emerald-600/90 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase flex items-center gap-0.5 shadow-2xs">
                                                        <Truck className="w-2.5 h-2.5" /> FREE DELIVERY
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Product Content Details */}
                                            <div className="p-3 space-y-2 font-sans flex-1 flex flex-col justify-between">
                                                <div className="space-y-1">
                                                    <div className="text-[10px] font-mono text-slate-400 uppercase truncate">
                                                        {product.category?.name || 'General'}
                                                    </div>
                                                    <h4 className="font-bold text-xs text-slate-800 group-hover:text-[#E00D42] transition line-clamp-2 leading-tight">
                                                        {product.name}
                                                    </h4>

                                                    {/* Pricing */}
                                                    <div className="flex items-baseline gap-1.5 pt-0.5 font-mono">
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
                                                        <span>{product.sales_count ?? 85} sold</span>
                                                    </div>
                                                </div>

                                                {/* Card Footer Action */}
                                                <div className="pt-2 border-t border-slate-100 flex items-center justify-between font-mono text-[10px] text-slate-400 group-hover:text-[#E00D42] transition">
                                                    <span className="uppercase font-bold">View Product</span>
                                                    <ChevronRight className="w-3 h-3 group-hover:translate-x-0.5 transition-transform" />
                                                </div>
                                            </div>
                                        </Link>
                                    );
                                })}
                            </div>
                        )}

                        {/* RELATED PRODUCTS RECOMMENDATION FEED */}
                        {products.data.length > 0 && relatedProducts && relatedProducts.length > 0 && (
                            <div className="bg-white rounded-2xl p-5 shadow-xs border border-slate-200 space-y-3 mt-8 font-mono">
                                <div className="flex items-center gap-2 pb-2 border-b border-slate-100">
                                    <Sparkles className="w-4 h-4 text-[#E00D42]" />
                                    <h4 className="font-bold text-slate-900 text-xs uppercase">Related Recommendations</h4>
                                </div>
                                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                                    {relatedProducts.map((p) => (
                                        <Link
                                            key={p.id}
                                            href={route('buyer.products.show', p.slug)}
                                            className="group rounded-lg border border-slate-200 p-2 hover:border-[#E00D42] transition flex flex-col justify-between"
                                        >
                                            <img src={p.featured_image || ''} alt={p.name} className="w-full aspect-square object-cover rounded-md mb-1.5 group-hover:scale-105 transition" />
                                            <h5 className="font-sans font-bold text-[11px] text-slate-800 line-clamp-1 group-hover:text-[#E00D42]">{p.name}</h5>
                                            <span className="font-mono font-black text-xs text-[#E00D42] mt-1">{formatPrice(p.price)}</span>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* PAGINATION */}
                        {products.links && products.links.length > 3 && (
                            <div className="mt-8 flex items-center justify-center gap-1 font-mono text-xs">
                                {products.links.map((link, idx) => (
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

            </div>
        </BuyerLayout>
    );
}
