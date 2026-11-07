import React from 'react';
import { Head, Link, router } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import ProductCard from '@/Components/ProductCard';
import BagHeartButton from '@/Components/BagHeartButton';
import { Category, Product, CuratedCollection } from '@/types';
import { Sparkles, Flame, ChevronRight, Award, TrendingUp, CheckCircle2, ArrowLeft } from 'lucide-react';

interface Props {
    categories: Category[];
    collections: CuratedCollection[];
    activeCollection: CuratedCollection;
    products: Product[];
}

export default function TopProducts({
    categories = [],
    collections = [],
    activeCollection,
    products = [],
}: Props) {
    const handleCollectionChange = (targetUrl: string) => {
        router.visit(targetUrl, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <BuyerLayout categories={categories}>
            <Head title="Top Products & Official Best Sellers | BagooPH" />

            <div className="max-w-[1536px] mx-auto px-2 sm:px-3 md:px-4 py-3 space-y-3 sm:space-y-4">
                
                {/* 1. BREADCRUMBS & BACK LINK */}
                <div className="flex items-center justify-between text-xs font-sans text-slate-500">
                    <div className="flex items-center gap-1.5">
                        <Link href="/" className="hover:text-[#E00D42] transition flex items-center gap-1">
                            <ArrowLeft className="w-3.5 h-3.5" />
                            <span>Marketplace</span>
                        </Link>
                        <ChevronRight className="w-3 h-3 text-slate-400" />
                        <span className="font-bold text-slate-800">Top Products</span>
                        <ChevronRight className="w-3 h-3 text-slate-400" />
                        <span className="text-[#E00D42] font-semibold">{activeCollection?.title}</span>
                    </div>

                    <div className="hidden sm:flex items-center gap-2 text-[11px] font-medium text-slate-500">
                        <span className="flex items-center gap-1 text-emerald-600">
                            <CheckCircle2 className="w-3.5 h-3.5" />
                            <span>Verified Transaction Signals</span>
                        </span>
                        <span>•</span>
                        <span>Updated Hourly</span>
                    </div>
                </div>

                {/* 2. HERO HEADER BANNER */}
                <div className="bg-gradient-to-r from-slate-900 via-slate-800 to-[#9E0B30] text-white rounded-xl p-3 sm:p-5 shadow-sm border border-slate-800 relative overflow-hidden font-sans">
                    <div className="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-3">
                        <div className="space-y-1">
                            <div className="flex items-center gap-2">
                                <div className="bg-[#E00D42] p-1.5 rounded-lg flex items-center justify-center shadow-xs">
                                    <Sparkles className="w-4 h-4 text-white fill-white" />
                                </div>
                                <h1 className="text-xl sm:text-2xl font-black uppercase tracking-tight text-white">
                                    TOP PRODUCTS
                                </h1>
                                <span className="bg-[#E00D42] text-white text-[9px] font-black uppercase px-2 py-0.5 rounded-md tracking-wider">
                                    ALGORITHMIC PICKS
                                </span>
                            </div>
                            <p className="text-xs sm:text-sm text-slate-300 font-normal max-w-2xl">
                                Curated bestsellers ranked by sales velocity, buyer repeat orders, verified customer reviews, and fulfillment reliability.
                            </p>
                        </div>

                        {/* Velocity Metric Pill */}
                        <div className="flex items-center gap-3 bg-black/30 backdrop-blur-xs border border-white/10 rounded-xl px-3.5 py-2 shrink-0 self-start md:self-auto">
                            <TrendingUp className="w-5 h-5 text-amber-300" />
                            <div>
                                <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Selected Niche</p>
                                <p className="text-xs font-black text-amber-300">{activeCollection?.sales_badge}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* 3. HORIZONTAL NICHE SELECTION TABS */}
                <div className="bg-white rounded-xl border border-slate-200/80 shadow-2xs p-1 sm:p-1.5 font-sans">
                    <div className="flex items-center gap-1 overflow-x-auto scrollbar-none pb-0.5">
                        {collections.map((col) => {
                            const isActive = activeCollection?.sub_slug === col.sub_slug;
                            return (
                                <button
                                    key={col.id}
                                    type="button"
                                    onClick={() => handleCollectionChange(col.target_url)}
                                    className={`relative px-3.5 py-2 rounded-lg text-xs font-bold whitespace-nowrap transition-all duration-200 cursor-pointer shrink-0 flex items-center gap-1.5 ${
                                        isActive
                                            ? 'bg-[#E00D42] text-white shadow-xs'
                                            : 'text-slate-700 hover:text-slate-900 hover:bg-slate-100/80'
                                    }`}
                                >
                                    <Flame className={`w-3.5 h-3.5 ${isActive ? 'fill-white text-white' : 'text-[#E00D42]'}`} />
                                    <span>{col.title}</span>
                                    <span className={`text-[10px] font-mono font-normal ml-0.5 px-1.5 py-0.2 rounded ${
                                        isActive ? 'bg-black/25 text-white' : 'bg-slate-100 text-slate-600'
                                    }`}>
                                        {col.sales_badge}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* 4. ACTIVE NICHE HEADER INFO */}
                <div className="flex items-center justify-between pb-1.5 border-b border-slate-200/80 font-sans">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="text-[10px] font-black uppercase tracking-wider text-[#E00D42]">
                                {activeCollection?.category_name}
                            </span>
                            <span className="text-slate-300">•</span>
                            <h2 className="text-base sm:text-lg font-black text-slate-900 tracking-tight">
                                {activeCollection?.title}
                            </h2>
                        </div>
                        <p className="text-[11px] text-slate-500">
                            Showing top ranked products with guaranteed authentic warehouse stock
                        </p>
                    </div>

                    <span className="text-xs font-bold text-slate-600">
                        {products.length} Products Ranked
                    </span>
                </div>

                {/* 5. DENSE 6-COLUMN RANKED PRODUCT GRID */}
                {products.length === 0 ? (
                    <div className="bg-white rounded-xl p-8 text-center space-y-3 border border-slate-200/80 shadow-2xs font-sans">
                        <Award className="w-12 h-12 text-slate-300 mx-auto" />
                        <h3 className="text-base font-bold text-slate-900">No ranked items in this niche yet</h3>
                        <p className="text-xs text-slate-500 max-w-sm mx-auto">
                            Check back shortly as our demand algorithm aggregates sales and review momentum.
                        </p>
                        <Link
                            href="/"
                            className="inline-block mt-2 px-4 py-2 bg-[#E00D42] text-white rounded-lg text-xs font-bold uppercase hover:bg-[#C20836] transition shadow-xs"
                        >
                            Browse All Marketplace Products
                        </Link>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-6 gap-1.5 sm:gap-2 md:gap-2.5">
                        {products.map((product, index) => {
                            const rank = index + 1;
                            return (
                                <div key={product.id} className="relative group/ranked flex flex-col">
                                    {/* Prominent Ranking Ribbon Badge */}
                                    <div className="absolute top-2 left-2 z-20 pointer-events-none">
                                        <span className={`inline-flex items-center justify-center font-black rounded-md px-2 py-0.5 shadow-md text-[10px] tracking-wider ${
                                            rank === 1 ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-white border border-amber-300/50 ring-1 ring-amber-400/30' :
                                            rank === 2 ? 'bg-gradient-to-r from-slate-400 to-slate-500 text-white border border-slate-300/50' :
                                            rank === 3 ? 'bg-gradient-to-r from-amber-700 to-amber-800 text-white border border-amber-600/50' :
                                            'bg-slate-900/85 backdrop-blur-xs text-white border border-white/20'
                                        }`}>
                                            #{rank}
                                        </span>
                                    </div>

                                    {/* Compact Product Card */}
                                    <ProductCard
                                        product={product}
                                        heartControl={<BagHeartButton product={product} />}
                                        href={route('buyer.products.show', product.slug)}
                                    />
                                </div>
                            );
                        })}
                    </div>
                )}

            </div>
        </BuyerLayout>
    );
}
