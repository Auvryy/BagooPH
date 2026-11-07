import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { 
    Clock, 
    Ticket, 
    Sparkles, 
    Truck, 
    ShieldCheck, 
    Flame, 
    Star, 
    Check, 
    ArrowRight, 
    Store, 
    Tag, 
    Gift, 
    ShoppingBag,
    Layers,
    ChevronRight,
    MapPin
} from 'lucide-react';
import axios from 'axios';

interface VoucherItem {
    id: string;
    code: string;
    title: string;
    subtitle: string;
    discount: string;
    badge: string;
    claimed: number;
    revision_id?: number | null;
    voucher_id?: number | null;
    is_saved?: boolean;
}

interface CampaignProduct {
    id: number;
    name: string;
    price: number;
    original_price: number;
    discount_pct: number;
    cover_image: string | null;
    slug: string;
    shop: {
        id: number;
        name: string;
        city: string;
        pickup_province?: string;
        rating: number;
    } | null;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    sub_category: {
        id: number;
        name: string;
    } | null;
    review_summary?: {
        average_rating: number;
        total_reviews: number;
    } | null;
    event_badge: string;
    claimed_pct: number;
}

interface CampaignData {
    slug: string;
    title: string;
    tagline: string;
    description: string;
    theme: {
        bg_gradient: string;
        accent_color: string;
        primary_btn: string;
        badge_bg: string;
        badge_text: string;
        border_accent: string;
    };
    banner_img: string;
    badge: string;
    ends_at: string;
    vouchers: VoucherItem[];
}

interface Props {
    campaign: CampaignData;
    products: CampaignProduct[];
    categories: Array<{ id: number; name: string; slug: string }>;
    event: string;
    allCampaigns: Array<{ slug: string; title: string; badge: string }>;
}

export default function CampaignPage({ campaign, products, categories, event, allCampaigns }: Props) {
    const { auth } = usePage().props as any;
    const [claimedVouchers, setClaimedVouchers] = useState<Record<string, boolean>>(() => {
        const initial: Record<string, boolean> = {};
        campaign.vouchers.forEach((v) => {
            if (v.is_saved) {
                initial[v.code] = true;
            }
        });
        return initial;
    });
    const [claiming, setClaiming] = useState<Record<string, boolean>>({});
    const [toastMessage, setToastMessage] = useState<string | null>(null);
    const [activeFilter, setActiveFilter] = useState<string>('all');
    const [selectedCategory, setSelectedCategory] = useState<string>('all');

    // Countdown Timer logic
    const [timeLeft, setTimeLeft] = useState<{ hours: number; minutes: number; seconds: number }>({
        hours: 14,
        minutes: 32,
        seconds: 45,
    });

    useEffect(() => {
        const target = new Date(campaign.ends_at).getTime();
        const updateTimer = () => {
            const now = new Date().getTime();
            const diff = Math.max(0, target - now);
            const totalSec = Math.floor(diff / 1000);
            const hours = Math.floor(totalSec / 3600);
            const minutes = Math.floor((totalSec % 3600) / 60);
            const seconds = totalSec % 60;
            setTimeLeft({ hours, minutes, seconds });
        };
        updateTimer();
        const interval = setInterval(updateTimer, 1000);
        return () => clearInterval(interval);
    }, [campaign.ends_at]);

    const showToast = (msg: string) => {
        setToastMessage(msg);
        setTimeout(() => setToastMessage(null), 4000);
    };

    const handleClaimVoucher = async (voucher: VoucherItem) => {
        if (claimedVouchers[voucher.code]) return;

        setClaiming((prev) => ({ ...prev, [voucher.code]: true }));

        try {
            if (voucher.revision_id && auth?.user) {
                await axios.post(`/buyer/saved-vouchers/${voucher.revision_id}`);
            }
            // Mark as claimed
            setClaimedVouchers((prev) => ({ ...prev, [voucher.code]: true }));
            showToast(`🎉 Voucher ${voucher.code} saved to your wallet! Use it during checkout.`);
        } catch {
            // Still mark as claimed in local UI state for guest / session convenience
            setClaimedVouchers((prev) => ({ ...prev, [voucher.code]: true }));
            showToast(`🎉 Voucher ${voucher.code} collected for this session!`);
        } finally {
            setClaiming((prev) => ({ ...prev, [voucher.code]: false }));
        }
    };

    const handleClaimAll = async () => {
        for (const voucher of campaign.vouchers) {
            if (!claimedVouchers[voucher.code]) {
                await handleClaimVoucher(voucher);
            }
        }
        showToast('🔥 All event vouchers collected successfully!');
    };

    // Filter products
    const filteredProducts = products.filter((p) => {
        if (selectedCategory !== 'all' && p.category?.slug !== selectedCategory) {
            return false;
        }
        if (activeFilter === 'under_500') {
            return p.price <= 500;
        }
        if (activeFilter === 'deep_discount') {
            return p.discount_pct >= 20;
        }
        return true;
    });

    const formatPrice = (val: number) => {
        return '₱' + Number(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100 -mt-3 sm:-mt-4 md:-mt-5.5 -mx-2 sm:-mx-3 md:-mx-4 pb-20">
            <Head title={`${campaign.title} | BagooPH Official Event`} />

            {/* FLOATING CELEBRATION TOAST */}
            {toastMessage && (
                <div className="fixed bottom-6 right-6 z-50 animate-bounce bg-emerald-500 text-slate-950 font-black px-5 py-3 rounded-xl shadow-2xl flex items-center gap-3 border-2 border-emerald-300 text-sm">
                    <Sparkles className="w-5 h-5 text-slate-950 animate-spin" />
                    <span>{toastMessage}</span>
                </div>
            )}

            {/* 1. HERO EVENT HEADER */}
            <section className={`relative overflow-hidden bg-gradient-to-b ${campaign.theme.bg_gradient} border-b border-white/10 px-4 sm:px-8 py-10 md:py-16 shadow-2xl`}>
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.08),transparent_50%)] pointer-events-none" />

                <div className="max-w-7xl mx-auto relative z-10">
                    {/* Breadcrumbs & Event Badge */}
                    <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-white/70">
                            <Link href="/" className="hover:text-white transition">Bagoo</Link>
                            <ChevronRight className="w-3.5 h-3.5 text-white/40" />
                            <Link href="/#flash-deals" className="hover:text-white transition">Promotions</Link>
                            <ChevronRight className="w-3.5 h-3.5 text-white/40" />
                            <span className="text-amber-300 font-bold">{campaign.badge}</span>
                        </div>

                        {/* LIVE COUNTDOWN TIMER */}
                        <div className="flex items-center gap-2.5 bg-black/60 backdrop-blur-md px-4 py-2 rounded-xl border border-white/15 shadow-inner">
                            <Clock className="w-4 h-4 text-rose-400 animate-pulse" />
                            <span className="text-xs font-extrabold tracking-wider text-rose-400 uppercase">DEAL ENDS IN:</span>
                            <div className="flex items-center gap-1 font-mono font-black text-sm text-white">
                                <span className="bg-white/10 px-2 py-0.5 rounded-md">{String(timeLeft.hours).padStart(2, '0')}h</span>
                                <span className="text-white/40">:</span>
                                <span className="bg-white/10 px-2 py-0.5 rounded-md">{String(timeLeft.minutes).padStart(2, '0')}m</span>
                                <span className="text-white/40">:</span>
                                <span className="bg-rose-500/30 text-rose-300 px-2 py-0.5 rounded-md">{String(timeLeft.seconds).padStart(2, '0')}s</span>
                            </div>
                        </div>
                    </div>

                    {/* Headline and artwork */}
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
                        <div className="lg:col-span-7 space-y-4">
                            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/10 border border-white/20 text-xs font-black tracking-widest text-amber-300 uppercase">
                                <Sparkles className="w-3.5 h-3.5" />
                                <span>{campaign.tagline}</span>
                            </div>

                            <h1 className="text-3xl sm:text-4xl md:text-5xl font-black tracking-tight text-white leading-tight">
                                {campaign.title}
                            </h1>

                            <p className="text-sm sm:text-base text-white/80 max-w-2xl leading-relaxed">
                                {campaign.description}
                            </p>

                            {/* Trust badges */}
                            <div className="pt-2 flex flex-wrap items-center gap-4 text-xs font-medium text-white/90">
                                <div className="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-lg border border-white/10">
                                    <Truck className="w-4 h-4 text-emerald-400" />
                                    <span>₱0 Min Spend Nationwide</span>
                                </div>
                                <div className="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-lg border border-white/10">
                                    <ShieldCheck className="w-4 h-4 text-sky-400" />
                                    <span>100% Authentic Merchants</span>
                                </div>
                                <div className="flex items-center gap-1.5 bg-white/10 px-3 py-1.5 rounded-lg border border-white/10">
                                    <Flame className="w-4 h-4 text-amber-400" />
                                    <span>Hourly Flash Drops</span>
                                </div>
                            </div>
                        </div>

                        {/* Event Artwork Card */}
                        <div className="lg:col-span-5">
                            <div className="relative rounded-2xl overflow-hidden shadow-2xl border-2 border-white/20 group">
                                <img 
                                    src={campaign.banner_img} 
                                    alt={campaign.title}
                                    className="w-full h-auto object-cover transform group-hover:scale-105 transition-transform duration-700" 
                                />
                                <div className="absolute bottom-3 left-3 right-3 bg-black/70 backdrop-blur-md rounded-xl p-3 border border-white/10 flex items-center justify-between">
                                    <div>
                                        <p className="text-[10px] uppercase font-bold text-amber-300">Official Platform Campaign</p>
                                        <p className="text-xs font-extrabold text-white">{campaign.badge}</p>
                                    </div>
                                    <button 
                                        onClick={handleClaimAll}
                                        className="px-3.5 py-1.5 rounded-lg bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold transition shadow-lg flex items-center gap-1.5"
                                    >
                                        <Gift className="w-3.5 h-3.5" />
                                        <span>Claim All</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* CAMPAIGN SWITCHER PILLS */}
                    <div className="mt-10 pt-6 border-t border-white/10 flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
                        <span className="text-xs font-bold text-white/50 uppercase tracking-wider shrink-0 mr-2">Other Events:</span>
                        {allCampaigns.map((camp) => (
                            <Link
                                key={camp.slug}
                                href={`/campaign/${camp.slug}`}
                                className={`px-3.5 py-1.5 rounded-full text-xs font-extrabold transition shrink-0 whitespace-nowrap flex items-center gap-1.5 ${
                                    camp.slug === event
                                        ? 'bg-white text-slate-900 shadow-md scale-105'
                                        : 'bg-white/10 text-white hover:bg-white/20 border border-white/15'
                                }`}
                            >
                                <span>{camp.badge}</span>
                            </Link>
                        ))}
                    </div>
                </div>
            </section>

            {/* 2. VOUCHER CLAIM CENTER (1-Click Claiming Hub) */}
            <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 -mt-6 relative z-20">
                <div className="bg-slate-900/90 backdrop-blur-md rounded-2xl border border-white/10 shadow-2xl p-6 sm:p-8">
                    <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center border border-amber-500/30">
                                <Ticket className="w-5 h-5" />
                            </div>
                            <div>
                                <h2 className="text-lg sm:text-xl font-black text-white flex items-center gap-2">
                                    <span>Event Voucher Hub</span>
                                    <span className="text-[10px] font-extrabold bg-[#E00D42] text-white px-2 py-0.5 rounded-full uppercase">Instant Claim</span>
                                </h2>
                                <p className="text-xs text-slate-400">Collect platform-sponsored discounts before shopping — auto-applied at checkout</p>
                            </div>
                        </div>

                        <button 
                            type="button"
                            onClick={handleClaimAll}
                            className="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-slate-950 font-black text-xs uppercase tracking-wider shadow-lg transition flex items-center gap-2 cursor-pointer"
                        >
                            <Sparkles className="w-4 h-4 text-slate-950" />
                            <span>Claim All Event Vouchers</span>
                        </button>
                    </div>

                    {/* Vouchers Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        {campaign.vouchers.map((voucher) => {
                            const isClaimed = claimedVouchers[voucher.code];
                            const isProcessing = claiming[voucher.code];

                            return (
                                <div 
                                    key={voucher.id}
                                    className={`relative rounded-xl border p-4 transition-all duration-300 flex flex-col justify-between ${
                                        isClaimed 
                                            ? 'bg-slate-800/40 border-emerald-500/40' 
                                            : 'bg-slate-800/80 hover:bg-slate-800 border-white/10 hover:border-amber-400/40'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3 mb-3">
                                        <div>
                                            <span className="text-[10px] font-extrabold px-2 py-0.5 rounded-sm bg-white/10 text-amber-300 tracking-wider uppercase">
                                                {voucher.badge}
                                            </span>
                                            <h3 className="text-base font-black text-white mt-1.5">{voucher.title}</h3>
                                            <p className="text-xs text-slate-300 mt-0.5">{voucher.subtitle}</p>
                                        </div>

                                        <div className="text-right shrink-0">
                                            <span className="text-lg font-black text-amber-400 block leading-none">{voucher.discount}</span>
                                            <span className="text-[10px] font-mono text-slate-400 mt-1 block uppercase">CODE: {voucher.code}</span>
                                        </div>
                                    </div>

                                    {/* Claim Progress & Action */}
                                    <div className="pt-3 border-t border-white/10 flex items-center justify-between gap-3">
                                        <div className="flex-1 min-w-0">
                                            <div className="flex items-center justify-between text-[10px] text-slate-400 mb-1">
                                                <span>Quota:</span>
                                                <span className="font-bold text-amber-400">{voucher.claimed}% Claimed</span>
                                            </div>
                                            <div className="w-full h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                                <div 
                                                    className="h-full bg-gradient-to-r from-amber-500 to-rose-500 rounded-full" 
                                                    style={{ width: `${voucher.claimed}%` }} 
                                                />
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            disabled={isClaimed || isProcessing}
                                            onClick={() => handleClaimVoucher(voucher)}
                                            className={`px-4 py-1.5 rounded-lg text-xs font-black uppercase tracking-wider transition shrink-0 ${
                                                isClaimed
                                                    ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 cursor-default flex items-center gap-1'
                                                    : isProcessing
                                                        ? 'bg-slate-700 text-slate-400 cursor-wait'
                                                        : 'bg-[#E00D42] hover:bg-[#C20836] text-white shadow-md cursor-pointer'
                                            }`}
                                        >
                                            {isClaimed ? (
                                                <>
                                                    <Check className="w-3.5 h-3.5" />
                                                    <span>Claimed</span>
                                                </>
                                            ) : isProcessing ? (
                                                'Saving…'
                                            ) : (
                                                'Claim'
                                            )}
                                        </button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* 3. PARTICIPATING PRODUCTS & EVENT DEALS */}
            <section className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-12">
                <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                    <div>
                        <div className="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-wider text-rose-400 mb-1">
                            <Flame className="w-4 h-4" />
                            <span>Participating Verified Merchants</span>
                        </div>
                        <h2 className="text-2xl sm:text-3xl font-black text-white">Event Showcase Deals</h2>
                    </div>

                    {/* Filter Pills */}
                    <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
                        <button
                            type="button"
                            onClick={() => setActiveFilter('all')}
                            className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                activeFilter === 'all'
                                    ? 'bg-white text-slate-900 shadow-sm'
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            All Deals ({products.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveFilter('deep_discount')}
                            className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                activeFilter === 'deep_discount'
                                    ? 'bg-white text-slate-900 shadow-sm'
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            🔥 20%+ Off Markdowns
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveFilter('under_500')}
                            className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                activeFilter === 'under_500'
                                    ? 'bg-white text-slate-900 shadow-sm'
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                            }`}
                        >
                            ₱ Under ₱500 Steals
                        </button>
                    </div>
                </div>

                {/* Categories Tab Selector */}
                <div className="mb-8 flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none border-b border-white/10">
                    <button
                        type="button"
                        onClick={() => setSelectedCategory('all')}
                        className={`px-3 py-1.5 rounded-md text-xs font-extrabold whitespace-nowrap transition ${
                            selectedCategory === 'all'
                                ? 'border-b-2 border-[#E00D42] text-white font-black'
                                : 'text-slate-400 hover:text-white'
                        }`}
                    >
                        All Categories
                    </button>
                    {categories.map((cat) => (
                        <button
                            key={cat.id}
                            type="button"
                            onClick={() => setSelectedCategory(cat.slug)}
                            className={`px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition ${
                                selectedCategory === cat.slug
                                    ? 'border-b-2 border-[#E00D42] text-white font-black'
                                    : 'text-slate-400 hover:text-white'
                            }`}
                        >
                            {cat.name}
                        </button>
                    ))}
                </div>

                {/* Products Grid */}
                {filteredProducts.length === 0 ? (
                    <div className="text-center py-16 bg-slate-900 rounded-2xl border border-white/10">
                        <ShoppingBag className="w-12 h-12 text-slate-600 mx-auto mb-3" />
                        <p className="text-base font-bold text-white">No products found for this filter.</p>
                        <button
                            onClick={() => { setActiveFilter('all'); setSelectedCategory('all'); }}
                            className="mt-3 text-xs font-bold text-[#E00D42] underline"
                        >
                            Reset filters
                        </button>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-5">
                        {filteredProducts.map((product) => (
                            <Link
                                key={product.id}
                                href={`/products/${product.id}`}
                                className="group bg-slate-900 rounded-xl overflow-hidden border border-white/10 hover:border-white/30 transition-all duration-300 flex flex-col justify-between hover:shadow-xl hover:-translate-y-1"
                            >
                                <div className="relative aspect-square w-full bg-slate-800 overflow-hidden">
                                    <img
                                        src={product.cover_image || '/images/placeholder.webp'}
                                        alt={product.name}
                                        loading="lazy"
                                        className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                                    />

                                    {/* Campaign Ribbon Badge */}
                                    <div className="absolute top-2 left-2 bg-[#E00D42] text-white font-black text-[9px] uppercase tracking-wider px-2 py-0.5 rounded-sm shadow-md flex items-center gap-1">
                                        <Tag className="w-2.5 h-2.5" />
                                        <span>{product.event_badge}</span>
                                    </div>

                                    {/* Discount Percent Tag */}
                                    <div className="absolute top-2 right-2 bg-amber-400 text-slate-950 font-black text-[10px] px-1.5 py-0.5 rounded-sm shadow-md">
                                        -{product.discount_pct}%
                                    </div>

                                    {/* Stock Sold Meter */}
                                    <div className="absolute bottom-2 left-2 right-2 bg-black/75 backdrop-blur-xs rounded-sm px-2 py-1 text-[10px] text-white flex items-center justify-between">
                                        <span className="font-bold text-amber-300">🔥 {product.claimed_pct}% Claimed</span>
                                        <span className="text-white/60">Fast Moving</span>
                                    </div>
                                </div>

                                <div className="p-3 sm:p-4 flex-1 flex flex-col justify-between space-y-3">
                                    <div>
                                        {/* Merchant Name & City */}
                                        {product.shop && (
                                            <div className="flex items-center gap-1 text-[11px] text-slate-400 mb-1 truncate">
                                                <Store className="w-3 h-3 text-[#E00D42] shrink-0" />
                                                <span className="truncate font-semibold">{product.shop.name}</span>
                                                <span className="text-slate-600">•</span>
                                                <span className="text-slate-500 text-[10px] flex items-center gap-0.5 shrink-0">
                                                    <MapPin className="w-2.5 h-2.5" />
                                                    {product.shop.city}
                                                </span>
                                            </div>
                                        )}

                                        <h3 className="text-xs sm:text-sm font-bold text-white line-clamp-2 leading-snug group-hover:text-amber-300 transition-colors">
                                            {product.name}
                                        </h3>
                                    </div>

                                    <div>
                                        {/* Price Section */}
                                        <div className="flex items-baseline gap-2">
                                            <span className="text-base sm:text-lg font-black text-[#E00D42]">
                                                {formatPrice(product.price)}
                                            </span>
                                            <span className="text-xs text-slate-500 line-through">
                                                {formatPrice(product.original_price)}
                                            </span>
                                        </div>

                                        {/* Action Button */}
                                        <div className="mt-3 w-full py-1.5 rounded-lg bg-white/10 group-hover:bg-[#E00D42] text-white text-xs font-bold transition text-center flex items-center justify-center gap-1.5">
                                            <ShoppingBag className="w-3.5 h-3.5" />
                                            <span>Claim Deal</span>
                                        </div>
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}

CampaignPage.layout = (page: React.ReactNode) => <BuyerLayout>{page}</BuyerLayout>;
