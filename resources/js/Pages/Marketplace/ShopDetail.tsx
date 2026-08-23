import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { PaginatedData, Product, Shop } from '@/types';
import { 
    Store, 
    Star, 
    MapPin, 
    Phone, 
    ShoppingBag, 
    ArrowLeft, 
    ShieldCheck, 
    CheckCircle2, 
    MessageSquare, 
    Tag, 
    Clock, 
    Sparkles, 
    Truck,
    Search,
    ChevronRight,
    Heart,
    Share2,
    Check
} from 'lucide-react';

interface Props {
    shop: Shop & {
        response_rate?: string;
        response_time?: string;
        rating?: number | string;
        products_count?: number;
    };
    products: PaginatedData<Product>;
}

export default function ShopDetail({ shop, products }: Props) {
    const [searchInShop, setSearchInShop] = useState('');
    const [selectedCategoryTab, setSelectedCategoryTab] = useState('all');
    const [isFollowed, setIsFollowed] = useState(false);
    const [copiedVoucher, setCopiedVoucher] = useState<string | null>(null);

    const formatPrice = (val: string | number | undefined | null) => {
        const num = Number(val || 0);
        return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(num);
    };

    const copyVoucher = (code: string) => {
        navigator.clipboard.writeText(code);
        setCopiedVoucher(code);
        setTimeout(() => setCopiedVoucher(null), 3000);
    };

    const filteredProducts = products.data.filter(product => {
        const matchesSearch = !searchInShop.trim() || 
            product.name.toLowerCase().includes(searchInShop.toLowerCase()) ||
            product.description.toLowerCase().includes(searchInShop.toLowerCase());
        return matchesSearch;
    });

    return (
        <BuyerLayout>
            <Head title={`${shop.name} — Verified Official Storefront`} />

            <div className="space-y-6 max-w-7xl mx-auto font-sans">
                
                {/* Back Link */}
                <div className="flex items-center justify-between pb-2">
                    <Link
                        href={route('buyer.index')}
                        className="text-xs font-mono text-slate-500 hover:text-[#E00D42] flex items-center gap-1 uppercase font-bold transition"
                    >
                        <ArrowLeft className="w-3.5 h-3.5" />
                        <span>Return to Marketplace</span>
                    </Link>
                </div>

                {/* 1. MASTER FLAGSHIP STOREFRONT PROFILE CARD */}
                <div className="bg-white rounded-3xl border border-slate-200/80 shadow-md overflow-hidden relative">
                    
                    {/* Top Banner Image with Gradient */}
                    <div className="h-44 sm:h-56 relative bg-slate-950 overflow-hidden">
                        <img 
                            src={shop.banner || 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1400&q=80'} 
                            alt="" 
                            className="w-full h-full object-cover opacity-60" 
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/30 to-transparent"></div>
                        <div className="absolute top-4 right-4 flex items-center gap-2 font-mono text-xs text-white">
                            <span className="px-2.5 py-1 rounded-full bg-black/60 backdrop-blur-md border border-white/20 flex items-center gap-1">
                                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                                <span>100% Authentic Products</span>
                            </span>
                        </div>
                    </div>

                    {/* Storefront Info & Telemetry Bar */}
                    <div className="p-6 sm:p-8 pt-0 relative">
                        <div className="flex flex-col md:flex-row items-start md:items-end justify-between gap-6 -mt-16 sm:-mt-20">
                            
                            {/* Logo & Store Names */}
                            <div className="flex items-end gap-4 sm:gap-6">
                                <div className="w-24 h-24 sm:w-28 sm:h-28 rounded-3xl bg-white p-2 shadow-2xl border-2 border-white overflow-hidden shrink-0">
                                    <img 
                                        src={shop.logo || 'https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=300&q=80'} 
                                        alt={shop.name} 
                                        className="w-full h-full object-cover rounded-2xl" 
                                    />
                                </div>

                                <div className="space-y-1 pb-1">
                                    <div className="flex items-center gap-2">
                                        <h1 className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">
                                            {shop.name}
                                        </h1>
                                        <span className="px-2 py-0.5 rounded bg-emerald-50 text-emerald-700 border border-emerald-200 text-[10px] font-bold font-mono uppercase">
                                            MALL PREFERRED
                                        </span>
                                    </div>
                                    <p className="text-xs text-slate-500 max-w-xl line-clamp-2">
                                        {shop.description || 'Official flagship store for curated lifestyle essentials on BagooPH.'}
                                    </p>
                                    <p className="text-[11px] text-slate-400 font-mono flex items-center gap-1.5 pt-0.5">
                                        <MapPin className="w-3 h-3 text-[#E00D42]" />
                                        <span>{shop.address || 'Artisan District'}, {shop.city || 'Metro Manila'}</span>
                                    </p>
                                </div>
                            </div>

                            {/* Action Buttons */}
                            <div className="flex items-center gap-3 w-full md:w-auto font-mono text-xs">
                                <button
                                    type="button"
                                    onClick={() => setIsFollowed(!isFollowed)}
                                    className={`flex-1 md:flex-initial px-5 py-2.5 rounded-xl font-bold uppercase transition flex items-center justify-center gap-1.5 ${
                                        isFollowed 
                                            ? 'bg-slate-100 text-slate-700 border border-slate-300' 
                                            : 'bg-slate-900 hover:bg-black text-white shadow-xs'
                                    }`}
                                >
                                    <Heart className={`w-3.5 h-3.5 ${isFollowed ? 'fill-[#E00D42] text-[#E00D42]' : ''}`} />
                                    <span>{isFollowed ? 'Following (14.2k)' : '+ Follow Shop'}</span>
                                </button>

                                <button
                                    type="button"
                                    className="flex-1 md:flex-initial px-5 py-2.5 rounded-xl bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition shadow-xs flex items-center justify-center gap-1.5"
                                >
                                    <MessageSquare className="w-3.5 h-3.5" />
                                    <span>Live Chat</span>
                                </button>
                            </div>
                        </div>

                        {/* Store Statistics Grid */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-slate-100 font-mono text-xs">
                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase font-bold block">Rating & Feedback</span>
                                <div className="flex items-center gap-1.5">
                                    <span className="text-base font-black text-[#E00D42]">{Number(shop.rating || 4.95).toFixed(2)}</span>
                                    <div className="flex text-amber-400">
                                        {[...Array(5)].map((_, i) => (
                                            <Star key={i} className="w-3 h-3 fill-amber-400" />
                                        ))}
                                    </div>
                                </div>
                            </div>

                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase font-bold block">Total Products</span>
                                <p className="text-base font-black text-slate-900">{products.total ?? products.data.length} Listings</p>
                            </div>

                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase font-bold block">Chat Response</span>
                                <p className="text-base font-black text-emerald-600">99% (Within 5 mins)</p>
                            </div>

                            <div className="p-3.5 rounded-2xl bg-slate-50 border border-slate-100 space-y-1">
                                <span className="text-slate-400 text-[10px] uppercase font-bold block">Dispatch Performance</span>
                                <p className="text-base font-black text-indigo-600">98% Fast Dispatch</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 2. STORE EXCLUSIVE VOUCHER STRIP */}
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 font-mono text-xs">
                    <div className="p-4 rounded-2xl bg-gradient-to-r from-rose-50 to-rose-100/60 border border-rose-200 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white text-[9px] font-bold uppercase">STORE EXCLUSIVE</span>
                            <h4 className="font-black text-sm text-slate-900">₱150 OFF DISCOUNT</h4>
                            <p className="text-[10px] text-slate-500">Min. Spend ₱1,500 • Code: PRIME150</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('PRIME150')}
                            className="px-3.5 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-xl text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'PRIME150' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>

                    <div className="p-4 rounded-2xl bg-gradient-to-r from-emerald-50 to-emerald-100/60 border border-emerald-200 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded bg-emerald-600 text-white text-[9px] font-bold uppercase">SHIPPING PASS</span>
                            <h4 className="font-black text-sm text-slate-900">FREE DOORSTEP DELIVERY</h4>
                            <p className="text-[10px] text-slate-500">Min. Spend ₱0 • Code: PRIMESHIP</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('PRIMESHIP')}
                            className="px-3.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'PRIMESHIP' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>

                    <div className="p-4 rounded-2xl bg-gradient-to-r from-amber-50 to-amber-100/60 border border-amber-200 flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="px-2 py-0.5 rounded bg-amber-500 text-white text-[9px] font-bold uppercase">FLASH COUPON</span>
                            <h4 className="font-black text-sm text-slate-900">10% CASHBACK COINS</h4>
                            <p className="text-[10px] text-slate-500">All Categories • Code: COIN10</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => copyVoucher('COIN10')}
                            className="px-3.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white font-bold rounded-xl text-[11px] uppercase transition shadow-2xs"
                        >
                            {copiedVoucher === 'COIN10' ? 'Claimed ✓' : 'Claim'}
                        </button>
                    </div>
                </div>

                {/* 3. STORE PRODUCTS CATALOG WITH IN-STORE SEARCH */}
                <div className="space-y-4">
                    {/* Catalog Header & Search Filter */}
                    <div className="bg-white rounded-2xl p-4 sm:p-5 border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div className="flex items-center gap-2">
                            <ShoppingBag className="w-5 h-5 text-[#E00D42]" />
                            <h3 className="font-black text-slate-900 text-base">Store Catalog & All Products</h3>
                            <span className="text-xs font-mono text-slate-400">({filteredProducts.length} listings)</span>
                        </div>

                        {/* Search Inside Shop */}
                        <div className="w-full sm:w-80">
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInShop}
                                    onChange={(e) => setSearchInShop(e.target.value)}
                                    placeholder="Search in this shop..."
                                    className="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs focus:ring-1 focus:ring-[#E00D42]"
                                />
                                <Search className="w-4 h-4 text-slate-400 absolute left-3 top-2.5" />
                            </div>
                        </div>
                    </div>

                    {/* Products Grid */}
                    {filteredProducts.length === 0 ? (
                        <div className="bg-white rounded-3xl p-12 text-center border border-slate-200 space-y-3">
                            <ShoppingBag className="w-12 h-12 text-slate-300 mx-auto" />
                            <h4 className="font-bold text-slate-800 text-base">No products match your search in this store</h4>
                            <p className="text-xs text-slate-500">Try searching for other keywords or clear your search.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3.5">
                            {filteredProducts.map((product) => {
                                const priceNum = Number(product.price);
                                const compareNum = product.compare_at_price ? Number(product.compare_at_price) : null;
                                const discountPct = compareNum && compareNum > priceNum 
                                    ? Math.round(((compareNum - priceNum) / compareNum) * 100)
                                    : null;

                                return (
                                    <Link
                                        key={product.id}
                                        href={route('buyer.products.show', product.slug)}
                                        className="group bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-xl hover:border-[#E00D42]/50 transition duration-300 flex flex-col justify-between"
                                    >
                                        <div className="block relative aspect-square bg-slate-100 overflow-hidden">
                                            <img
                                                src={product.featured_image || ''}
                                                alt={product.name}
                                                className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                            />

                                            {/* Mall Badge */}
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

                                            <div className="absolute bottom-2 left-2">
                                                <span className="px-1.5 py-0.5 rounded bg-emerald-600/90 backdrop-blur-xs text-white font-mono text-[8px] font-bold uppercase flex items-center gap-0.5 shadow-2xs">
                                                    <Truck className="w-2.5 h-2.5" /> FREE DELIVERY
                                                </span>
                                            </div>
                                        </div>

                                        {/* Product Info */}
                                        <div className="p-3 space-y-2 font-sans flex-1 flex flex-col justify-between">
                                            <div className="space-y-1">
                                                <h4 className="font-bold text-xs text-slate-800 group-hover:text-[#E00D42] transition line-clamp-2 leading-tight">
                                                    {product.name}
                                                </h4>

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

                                            <div className="pt-2 border-t border-slate-100 flex items-center justify-between font-mono text-[10px] text-slate-400 group-hover:text-[#E00D42] transition">
                                                <span className="uppercase font-bold">Explore Specs</span>
                                                <ChevronRight className="w-3 h-3 group-hover:translate-x-0.5 transition-transform" />
                                            </div>
                                        </div>
                                    </Link>
                                );
                            })}
                        </div>
                    )}
                </div>

            </div>
        </BuyerLayout>
    );
}
