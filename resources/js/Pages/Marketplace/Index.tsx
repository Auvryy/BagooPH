import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Category, PaginatedData, Product, Shop } from '@/types';
import { 
    Star, 
    ShoppingCart, 
    Sparkles, 
    ArrowRight, 
    Store, 
    ShieldCheck, 
    Truck, 
    RefreshCw,
    SlidersHorizontal,
    ShoppingBag
} from 'lucide-react';

interface Props {
    products: PaginatedData<Product>;
    categories: Category[];
    featuredShops: Shop[];
    filters: {
        search?: string;
        category?: string;
        sort?: string;
    };
}

export default function MarketplaceIndex({ products, categories, featuredShops, filters }: Props) {
    const handleCategoryClick = (slug?: string) => {
        router.get(
            route('marketplace'), 
            { ...filters, category: slug || undefined }, 
            { preserveState: true }
        );
    };

    const handleSortChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        router.get(
            route('marketplace'), 
            { ...filters, sort: e.target.value || undefined }, 
            { preserveState: true }
        );
    };

    const addToCart = (productId: number) => {
        router.post(route('cart.store'), {
            product_id: productId,
            quantity: 1,
        }, {
            preserveScroll: true,
        });
    };

    return (
        <MarketplaceLayout>
            <Head title="Bagoo — Modern Multi-Role E-Commerce Marketplace" />

            {/* Hero Section */}
            <div className="relative overflow-hidden bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 text-white py-16 sm:py-24 px-4 sm:px-6 lg:px-8">
                <div className="absolute inset-0 opacity-10 bg-[radial-gradient(#818cf8_1px,transparent_1px)] [background-size:16px_16px]"></div>
                <div className="max-w-7xl mx-auto relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                    <div className="lg:col-span-7 space-y-6">
                        <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/20 border border-indigo-400/30 text-indigo-300 text-xs font-semibold backdrop-blur-md">
                            <Sparkles className="w-3.5 h-3.5 text-indigo-400" />
                            <span>Next-Gen Multi-Role Commerce Ecosystem</span>
                        </div>
                        <h1 className="text-4xl sm:text-6xl font-black tracking-tight leading-tight">
                            Smart Shopping. <br />
                            <span className="bg-gradient-to-r from-indigo-400 via-violet-300 to-emerald-400 bg-clip-text text-transparent">
                                Seamless Logistics.
                            </span>
                        </h1>
                        <p className="text-sm sm:text-base text-slate-300 max-w-xl leading-relaxed">
                            Discover premium backpacks, modern tech gear, travel accessories, and streetwear from verified merchants with real-time courier tracking.
                        </p>
                        <div className="flex flex-wrap items-center gap-4 pt-2">
                            <a
                                href="#products"
                                className="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm rounded-2xl shadow-lg shadow-indigo-600/30 hover:scale-105 active:scale-95 transition flex items-center gap-2"
                            >
                                <span>Explore Products</span>
                                <ArrowRight className="w-4 h-4" />
                            </a>
                            <Link
                                href={route('register')}
                                className="px-6 py-3.5 bg-white/10 hover:bg-white/20 text-white font-semibold text-sm rounded-2xl backdrop-blur-md border border-white/20 transition"
                            >
                                Open a Store / Become a Courier
                            </Link>
                        </div>
                    </div>

                    {/* Hero Highlight Cards */}
                    <div className="lg:col-span-5 grid grid-cols-2 gap-4">
                        <div className="p-5 bg-white/10 backdrop-blur-md rounded-3xl border border-white/10 space-y-2">
                            <div className="w-10 h-10 rounded-2xl bg-indigo-500/30 flex items-center justify-center text-indigo-300">
                                <Truck className="w-5 h-5" />
                            </div>
                            <h3 className="font-bold text-sm text-white">Live Courier Dispatch</h3>
                            <p className="text-xs text-slate-300">Integrated tracking from seller pickup to doorstep.</p>
                        </div>
                        <div className="p-5 bg-white/10 backdrop-blur-md rounded-3xl border border-white/10 space-y-2">
                            <div className="w-10 h-10 rounded-2xl bg-emerald-500/30 flex items-center justify-center text-emerald-300">
                                <Store className="w-5 h-5" />
                            </div>
                            <h3 className="font-bold text-sm text-white">Verified Merchants</h3>
                            <p className="text-xs text-slate-300">Direct storefront management with live inventory.</p>
                        </div>
                        <div className="p-5 bg-white/10 backdrop-blur-md rounded-3xl border border-white/10 space-y-2">
                            <div className="w-10 h-10 rounded-2xl bg-rose-500/30 flex items-center justify-center text-rose-300">
                                <ShieldCheck className="w-5 h-5" />
                            </div>
                            <h3 className="font-bold text-sm text-white">Admin Governance</h3>
                            <p className="text-xs text-slate-300">Multi-tier role access control and safety.</p>
                        </div>
                        <div className="p-5 bg-white/10 backdrop-blur-md rounded-3xl border border-white/10 space-y-2">
                            <div className="w-10 h-10 rounded-2xl bg-amber-500/30 flex items-center justify-center text-amber-300">
                                <ShoppingBag className="w-5 h-5" />
                            </div>
                            <h3 className="font-bold text-sm text-white">Logistics Ready</h3>
                            <p className="text-xs text-slate-300">Extensible architecture for fleet & hub partners.</p>
                        </div>
                    </div>
                </div>
            </div>

            {/* Categories Carousel / Badges */}
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 relative z-20">
                <div className="bg-white rounded-3xl p-4 shadow-xl shadow-slate-200/50 border border-slate-100 flex items-center gap-3 overflow-x-auto no-scrollbar">
                    <button
                        onClick={() => handleCategoryClick()}
                        className={`px-5 py-2.5 rounded-2xl text-xs font-bold whitespace-nowrap transition ${
                            !filters.category
                                ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                        }`}
                    >
                        All Categories
                    </button>
                    {categories.map((cat) => (
                        <button
                            key={cat.id}
                            onClick={() => handleCategoryClick(cat.slug)}
                            className={`px-5 py-2.5 rounded-2xl text-xs font-bold whitespace-nowrap transition flex items-center gap-2 ${
                                filters.category === cat.slug
                                    ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/30'
                                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                            }`}
                        >
                            <span>{cat.name}</span>
                            <span className="text-[10px] px-1.5 py-0.5 rounded-full bg-black/10">
                                {cat.products_count ?? 0}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            {/* Main Products Grid */}
            <section id="products" className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                {/* Section Header with Controls */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-8">
                    <div>
                        <h2 className="text-2xl font-black text-slate-900 tracking-tight">
                            {filters.category
                                ? `Products in ${categories.find(c => c.slug === filters.category)?.name || filters.category}`
                                : 'Featured Catalog'}
                        </h2>
                        <p className="text-xs text-slate-500 mt-1">
                            Showing {products.data.length} of {products.total} products
                        </p>
                    </div>

                    <div className="flex items-center gap-3 w-full sm:w-auto">
                        <div className="flex items-center gap-2 bg-white px-3 py-2 rounded-2xl border border-slate-200 text-xs w-full sm:w-auto">
                            <SlidersHorizontal className="w-3.5 h-3.5 text-slate-400" />
                            <span className="text-slate-500 font-medium">Sort by:</span>
                            <select
                                value={filters.sort || ''}
                                onChange={handleSortChange}
                                className="bg-transparent border-none p-0 text-xs font-bold text-slate-800 focus:ring-0 cursor-pointer"
                            >
                                <option value="">Latest Arrival</option>
                                <option value="popular">Most Popular</option>
                                <option value="price_low">Price: Low to High</option>
                                <option value="price_high">Price: High to Low</option>
                                <option value="rating">Highest Rated</option>
                            </select>
                        </div>
                    </div>
                </div>

                {/* Product Grid */}
                {products.data.length === 0 ? (
                    <div className="text-center py-16 bg-white rounded-3xl border border-slate-200 p-8 space-y-4">
                        <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                        <h3 className="text-lg font-bold text-slate-700">No products found</h3>
                        <p className="text-xs text-slate-500">Try changing your search term or category filter.</p>
                        <button
                            onClick={() => router.get(route('marketplace'))}
                            className="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-semibold"
                        >
                            Reset Filters
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        {products.data.map((product) => (
                            <div
                                key={product.id}
                                className="group bg-white rounded-3xl border border-slate-200/80 hover:border-indigo-300 hover:shadow-xl hover:shadow-indigo-500/10 transition-all duration-300 flex flex-col overflow-hidden"
                            >
                                {/* Product Image */}
                                <Link
                                    href={route('products.show', product.slug)}
                                    className="relative aspect-square overflow-hidden bg-slate-100"
                                >
                                    <img
                                        src={product.featured_image || 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80'}
                                        alt={product.name}
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500"
                                    />
                                    {product.compare_at_price && Number(product.compare_at_price) > Number(product.price) && (
                                        <span className="absolute top-3 left-3 px-2.5 py-1 bg-rose-500 text-white text-[10px] font-black rounded-lg shadow-sm">
                                            SALE
                                        </span>
                                    )}
                                    <span className="absolute bottom-3 right-3 px-2 py-0.5 bg-slate-900/70 backdrop-blur-md text-white text-[10px] font-bold rounded-md">
                                        Stock: {product.stock}
                                    </span>
                                </Link>

                                {/* Card Details */}
                                <div className="p-5 flex-1 flex flex-col justify-between">
                                    <div>
                                        {/* Store & Rating */}
                                        <div className="flex items-center justify-between text-xs mb-2">
                                            <span className="text-slate-400 truncate max-w-[120px]">
                                                {product.shop?.name || 'Bagoo Partner'}
                                            </span>
                                            <div className="flex items-center gap-1 text-amber-500 font-bold text-[11px]">
                                                <Star className="w-3.5 h-3.5 fill-amber-400 text-amber-400" />
                                                <span>{product.rating}</span>
                                            </div>
                                        </div>

                                        <Link
                                            href={route('products.show', product.slug)}
                                            className="font-bold text-sm text-slate-900 hover:text-indigo-600 line-clamp-2 leading-snug transition mb-1"
                                        >
                                            {product.name}
                                        </Link>

                                        <p className="text-xs text-slate-500 line-clamp-2 leading-relaxed mb-3">
                                            {product.description}
                                        </p>
                                    </div>

                                    {/* Price & Action */}
                                    <div className="pt-3 border-t border-slate-100 flex items-center justify-between">
                                        <div>
                                            <div className="flex items-baseline gap-1.5">
                                                <span className="text-lg font-black text-slate-900">
                                                    ${Number(product.price).toFixed(2)}
                                                </span>
                                                {product.compare_at_price && (
                                                    <span className="text-xs text-slate-400 line-through">
                                                        ${Number(product.compare_at_price).toFixed(2)}
                                                    </span>
                                                )}
                                            </div>
                                            <span className="text-[10px] text-slate-400">
                                                {product.sales_count} sold
                                            </span>
                                        </div>

                                        <button
                                            onClick={() => addToCart(product.id)}
                                            disabled={product.stock <= 0}
                                            className={`p-3 rounded-2xl font-semibold transition flex items-center justify-center ${
                                                product.stock > 0
                                                    ? 'bg-indigo-600 hover:bg-indigo-700 text-white shadow-md shadow-indigo-600/20 active:scale-95'
                                                    : 'bg-slate-100 text-slate-400 cursor-not-allowed'
                                            }`}
                                            title="Add to Cart"
                                        >
                                            <ShoppingCart className="w-4 h-4" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                {/* Pagination */}
                {products.last_page > 1 && (
                    <div className="flex justify-center items-center gap-2 mt-12">
                        {products.prev_page_url && (
                            <Link
                                href={products.prev_page_url}
                                className="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Previous
                            </Link>
                        )}
                        <span className="text-xs text-slate-500 font-medium px-2">
                            Page {products.current_page} of {products.last_page}
                        </span>
                        {products.next_page_url && (
                            <Link
                                href={products.next_page_url}
                                className="px-4 py-2 bg-white border border-slate-200 rounded-xl text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Next
                            </Link>
                        )}
                    </div>
                )}
            </section>
        </MarketplaceLayout>
    );
}
