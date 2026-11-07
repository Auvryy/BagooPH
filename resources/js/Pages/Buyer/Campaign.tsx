import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import BuyerLayout from '@/Layouts/BuyerLayout';
import { 
    Clock, 
    Ticket, 
    Sparkles, 
    Truck, 
    Flame, 
    Check, 
    Store, 
    Tag, 
    ShoppingBag,
    MapPin,
    ChevronRight
} from 'lucide-react';
import axios from 'axios';

interface VoucherItem {
    id: string;
    code: string;
    title: string;
    min_spend: string;
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
    featured_image: string | null;
    cover_image: string | null;
    slug: string;
    rating: number;
    sales_count: number;
    shop: {
        id: number;
        name: string;
        city: string;
        pickup_province?: string;
    } | null;
    category: {
        id: number;
        name: string;
        slug: string;
    } | null;
    event_badge: string;
    claimed_pct: number;
}

interface CampaignData {
    slug: string;
    title: string;
    badge: string;
    banner_img: string;
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

    // Live Countdown Timer
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
        setTimeout(() => setToastMessage(null), 3500);
    };

    const handleClaimVoucher = async (voucher: VoucherItem) => {
        if (claimedVouchers[voucher.code]) return;
        setClaiming((prev) => ({ ...prev, [voucher.code]: true }));

        try {
            if (voucher.revision_id && auth?.user) {
                await axios.post(`/buyer/saved-vouchers/${voucher.revision_id}`);
            }
            setClaimedVouchers((prev) => ({ ...prev, [voucher.code]: true }));
            showToast(`✓ Voucher ${voucher.code} saved to your wallet!`);
        } catch {
            setClaimedVouchers((prev) => ({ ...prev, [voucher.code]: true }));
            showToast(`✓ Voucher ${voucher.code} collected!`);
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
        showToast('✓ All vouchers claimed!');
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
        <div className="min-h-screen bg-[#F5F5F7] text-slate-900 -mt-3 sm:-mt-4 md:-mt-5.5 -mx-2 sm:-mx-3 md:-mx-4 pb-16 font-sans">
            <Head title={`${campaign.title} | BagooPH Official Event`} />

            {/* FLOATING SUCCESS TOAST */}
            {toastMessage && (
                <div className="fixed bottom-6 right-6 z-50 bg-slate-900 text-white font-bold px-4 py-2.5 rounded-xl shadow-xl flex items-center gap-2.5 text-xs animate-in fade-in slide-in-from-bottom-2">
                    <Check className="w-4 h-4 text-emerald-400" />
                    <span>{toastMessage}</span>
                </div>
            )}

            {/* 1. HERO EVENT BANNER & DIRECT COUNTDOWN */}
            <section className="w-full bg-white border-b border-slate-200">
                {/* Visual Banner Image */}
                <div className="w-full max-w-[1536px] mx-auto overflow-hidden bg-slate-900 aspect-[1880/500] sm:aspect-[1880/420] max-h-[380px]">
                    <img 
                        src={campaign.banner_img} 
                        alt={campaign.title}
                        className="w-full h-full object-cover" 
                    />
                </div>

                {/* Direct Action & Countdown Bar */}
                <div className="max-w-7xl mx-auto px-3 sm:px-6 py-3 sm:py-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="px-2.5 py-1 bg-[#E00D42] text-white text-xs font-black rounded-md tracking-wider uppercase">
                            {campaign.badge}
                        </span>
                        <h1 className="text-lg sm:text-xl font-black text-slate-900 tracking-tight">
                            {campaign.title}
                        </h1>
                    </div>

                    {/* DIRECT COUNTDOWN TIMER */}
                    <div className="flex items-center gap-2 bg-rose-50 border border-rose-200/80 px-3 py-1.5 rounded-lg">
                        <Clock className="w-4 h-4 text-[#E00D42]" />
                        <span className="text-xs font-extrabold text-[#E00D42] uppercase tracking-wider">ENDS IN:</span>
                        <div className="flex items-center gap-1 font-mono font-black text-xs text-slate-900">
                            <span className="bg-white px-1.5 py-0.5 rounded shadow-2xs border border-slate-200">{String(timeLeft.hours).padStart(2, '0')}h</span>
                            <span>:</span>
                            <span className="bg-white px-1.5 py-0.5 rounded shadow-2xs border border-slate-200">{String(timeLeft.minutes).padStart(2, '0')}m</span>
                            <span>:</span>
                            <span className="bg-white px-1.5 py-0.5 rounded shadow-2xs border border-slate-200 text-[#E00D42]">{String(timeLeft.seconds).padStart(2, '0')}s</span>
                        </div>
                    </div>
                </div>

                {/* Quick Event Switcher Pills */}
                <div className="max-w-7xl mx-auto px-3 sm:px-6 pb-3 flex items-center gap-2 overflow-x-auto scrollbar-none">
                    <span className="text-xs font-bold text-slate-600 shrink-0">Events:</span>
                    {allCampaigns.map((camp) => (
                        <Link
                            key={camp.slug}
                            href={`/campaign/${camp.slug}`}
                            className={`px-3 py-1 rounded-full text-xs font-bold transition shrink-0 whitespace-nowrap ${
                                camp.slug === event
                                    ? 'bg-[#E00D42] text-white shadow-xs'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                            }`}
                        >
                            {camp.badge}
                        </Link>
                    ))}
                </div>
            </section>

            {/* 2. DIRECT VOUCHER CLAIM HUB */}
            <section className="max-w-7xl mx-auto px-3 sm:px-6 mt-5">
                <div className="bg-white rounded-xl border border-slate-200 p-4 sm:p-5 shadow-xs">
                    <div className="flex items-center justify-between gap-4 mb-4">
                        <div className="flex items-center gap-2">
                            <Ticket className="w-5 h-5 text-[#E00D42]" />
                            <h2 className="text-base font-black text-slate-900">Event Vouchers</h2>
                            <span className="text-xs text-slate-600">Auto-applies at checkout</span>
                        </div>

                        <button 
                            type="button"
                            onClick={handleClaimAll}
                            className="px-3.5 py-1.5 rounded-lg bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-2xs"
                        >
                            <Sparkles className="w-3.5 h-3.5" />
                            <span>Claim All Vouchers</span>
                        </button>
                    </div>

                    {/* Vouchers Grid */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        {campaign.vouchers.map((voucher) => {
                            const isClaimed = claimedVouchers[voucher.code];
                            const isProcessing = claiming[voucher.code];

                            return (
                                <div 
                                    key={voucher.id}
                                    className={`relative rounded-lg border p-3 flex items-center justify-between gap-3 transition ${
                                        isClaimed 
                                            ? 'bg-slate-50 border-slate-200' 
                                            : 'bg-white border-dashed border-rose-300 hover:border-[#E00D42] shadow-2xs'
                                    }`}
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2 mb-1">
                                            <span className="text-[10px] font-bold px-1.5 py-0.5 rounded bg-rose-50 text-[#E00D42]">
                                                {voucher.badge}
                                            </span>
                                            <span className="text-[11px] font-mono text-slate-600 font-semibold">{voucher.code}</span>
                                        </div>

                                        <div className="flex items-baseline gap-1.5">
                                            <span className="text-lg font-black text-[#E00D42] leading-none">{voucher.discount}</span>
                                            <span className="text-xs text-slate-700 font-medium">Min spend {voucher.min_spend}</span>
                                        </div>

                                        {/* Quota bar */}
                                        <div className="mt-2 w-32 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                                            <div 
                                                className="h-full bg-gradient-to-r from-amber-500 to-[#E00D42] rounded-full" 
                                                style={{ width: `${voucher.claimed}%` }} 
                                            />
                                        </div>
                                    </div>

                                    {/* Direct Claim Button */}
                                    <button
                                        type="button"
                                        disabled={isClaimed || isProcessing}
                                        onClick={() => handleClaimVoucher(voucher)}
                                        className={`px-3.5 py-1.5 rounded-lg text-xs font-bold transition shrink-0 ${
                                            isClaimed
                                                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 cursor-default flex items-center gap-1'
                                                : isProcessing
                                                    ? 'bg-slate-100 text-slate-400 cursor-wait'
                                                    : 'bg-[#E00D42] hover:bg-[#C20836] text-white cursor-pointer shadow-xs'
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
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* 3. DIRECT DEALS PRODUCT GRID */}
            <section className="max-w-7xl mx-auto px-3 sm:px-6 mt-6">
                <div className="bg-white rounded-xl border border-slate-200 p-4 sm:p-5 shadow-xs">
                    {/* Header & Filter Row */}
                    <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div className="flex items-center gap-2">
                            <Flame className="w-5 h-5 text-[#E00D42]" />
                            <h2 className="text-base font-black text-slate-900">Event Deals</h2>
                            <span className="text-xs text-slate-600">({filteredProducts.length} items)</span>
                        </div>

                        {/* Direct Deal Filter Pills */}
                        <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
                            <button
                                type="button"
                                onClick={() => setActiveFilter('all')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                    activeFilter === 'all'
                                        ? 'bg-[#E00D42] text-white shadow-2xs'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                }`}
                            >
                                All Deals
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveFilter('deep_discount')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                    activeFilter === 'deep_discount'
                                        ? 'bg-[#E00D42] text-white shadow-2xs'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                }`}
                            >
                                🔥 20%+ Off
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveFilter('under_500')}
                                className={`px-3 py-1.5 rounded-lg text-xs font-bold transition whitespace-nowrap ${
                                    activeFilter === 'under_500'
                                        ? 'bg-[#E00D42] text-white shadow-2xs'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                                }`}
                            >
                                ₱ Under ₱500
                            </button>
                        </div>
                    </div>

                    {/* Category Tabs */}
                    <div className="mb-5 flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none border-b border-slate-100">
                        <button
                            type="button"
                            onClick={() => setSelectedCategory('all')}
                            className={`px-3 py-1 text-xs font-bold whitespace-nowrap transition border-b-2 ${
                                selectedCategory === 'all'
                                    ? 'border-[#E00D42] text-[#E00D42]'
                                    : 'border-transparent text-slate-600 hover:text-slate-900'
                            }`}
                        >
                            All Categories
                        </button>
                        {categories.map((cat) => (
                            <button
                                key={cat.id}
                                type="button"
                                onClick={() => setSelectedCategory(cat.slug)}
                                className={`px-3 py-1 text-xs font-medium whitespace-nowrap transition border-b-2 ${
                                    selectedCategory === cat.slug
                                        ? 'border-[#E00D42] text-[#E00D42] font-bold'
                                        : 'border-transparent text-slate-600 hover:text-slate-900'
                                }`}
                            >
                                {cat.name}
                            </button>
                        ))}
                    </div>

                    {/* Products Grid */}
                    {filteredProducts.length === 0 ? (
                        <div className="text-center py-12 text-slate-600 text-sm">
                            No products found matching this filter.
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3 sm:gap-4">
                            {filteredProducts.map((product) => (
                                <Link
                                    key={product.id}
                                    href={`/products/${product.slug}`}
                                    className="group bg-white rounded-lg border border-slate-200/90 overflow-hidden hover:border-[#E00D42] hover:shadow-md transition flex flex-col justify-between"
                                >
                                    {/* Image with Direct Discount & Event Badge */}
                                    <div className="relative aspect-square w-full bg-slate-100 overflow-hidden">
                                        <img
                                            src={product.featured_image || product.cover_image || '/images/placeholder.webp'}
                                            alt={product.name}
                                            className="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />

                                        {/* Event Ribbon Badge */}
                                        <div className="absolute top-1.5 left-1.5 bg-[#E00D42] text-white font-extrabold text-[9px] uppercase tracking-wider px-1.5 py-0.5 rounded shadow-xs">
                                            {product.event_badge}
                                        </div>

                                        {/* Discount Pill */}
                                        <div className="absolute top-1.5 right-1.5 bg-amber-400 text-slate-950 font-black text-[10px] px-1 py-0.5 rounded shadow-2xs">
                                            -{product.discount_pct}%
                                        </div>

                                        {/* Stock Sold Meter */}
                                        <div className="absolute bottom-1.5 left-1.5 right-1.5 bg-black/60 backdrop-blur-2xs rounded px-1.5 py-0.5 text-[9px] text-white flex items-center justify-between">
                                            <span className="font-bold text-amber-300">🔥 {product.claimed_pct}% Claimed</span>
                                            <span className="text-white/80">Direct Deal</span>
                                        </div>
                                    </div>

                                    {/* Content Details */}
                                    <div className="p-2.5 sm:p-3 flex-1 flex flex-col justify-between space-y-2">
                                        <div>
                                            {/* Store & Location */}
                                            {product.shop && (
                                                <div className="flex items-center gap-1 text-[10px] text-slate-600 mb-1 truncate">
                                                    <Store className="w-2.5 h-2.5 text-[#E00D42] shrink-0" />
                                                    <span className="truncate">{product.shop.name}</span>
                                                    <span>•</span>
                                                    <span className="shrink-0">{product.shop.city}</span>
                                                </div>
                                            )}

                                            <h3 className="text-xs font-bold text-slate-900 line-clamp-2 leading-snug group-hover:text-[#E00D42] transition-colors">
                                                {product.name}
                                            </h3>
                                        </div>

                                        <div>
                                            {/* Price */}
                                            <div className="flex items-baseline gap-1.5">
                                                <span className="text-sm sm:text-base font-black text-[#E00D42]">
                                                    {formatPrice(product.price)}
                                                </span>
                                                <span className="text-[11px] text-slate-600 line-through">
                                                    {formatPrice(product.original_price)}
                                                </span>
                                            </div>

                                            {/* Direct Action */}
                                            <div className="mt-2 w-full py-1 rounded bg-rose-50 text-[#E00D42] group-hover:bg-[#E00D42] group-hover:text-white text-[11px] font-bold transition text-center flex items-center justify-center gap-1">
                                                <ShoppingBag className="w-3 h-3" />
                                                <span>Buy Now</span>
                                            </div>
                                        </div>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}

CampaignPage.layout = (page: React.ReactNode) => <BuyerLayout>{page}</BuyerLayout>;
