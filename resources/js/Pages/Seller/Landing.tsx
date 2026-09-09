import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';
import { 
    ArrowRight, 
    Check, 
    Printer, 
    Truck, 
    Boxes, 
    ChevronDown, 
    ChevronUp, 
    Sliders, 
    Sparkles, 
    Package, 
    Clock, 
    ShoppingBag,
    Coins,
    MapPin,
    Layers,
    Tag,
    ArrowUpRight,
    Flame
} from 'lucide-react';

interface MovingCard {
    id: string;
    index: string;
    category: string;
    headline: string;
    highlightTag: string;
    specs: string[];
    image: string;
}

const MOVING_CARDS: MovingCard[] = [
    {
        id: 'streetwear',
        index: '01 // STREETWEAR',
        category: 'LOCAL APPAREL DROPS',
        headline: 'From your bedroom to nationwide doorsteps.',
        highlightTag: '10% FLAT COMMISSION',
        specs: ['Zero Listing Deposit', 'Instant Store URL'],
        image: 'https://images.unsplash.com/photo-1523381294911-8d3cead13475?q=80&w=1000&auto=format&fit=crop'
    },
    {
        id: 'artisan',
        index: '02 // ARTISAN CRAFTS',
        category: 'CAMPUS BAKES & POTTERY',
        headline: 'Handmade with care. Delivered with cash.',
        highlightTag: 'DOORSTEP COD',
        specs: ['Direct Cash Remittance', 'Zero Payment Hold'],
        image: 'https://images.unsplash.com/photo-1556742049-0a67e557b447?q=80&w=1000&auto=format&fit=crop'
    },
    {
        id: 'fleet',
        index: '03 // FLEET DISPATCH',
        category: 'DOORSTEP COURIER LOGISTICS',
        headline: 'Never queue at a courier branch again.',
        highlightTag: '1-CLICK WAYBILL',
        specs: ['Rider Pickup at Gate', 'Live Transit Checkpoints'],
        image: 'https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?q=80&w=1000&auto=format&fit=crop'
    },
    {
        id: 'vintage',
        index: '04 // VINTAGE THRIFT',
        category: 'CURATED PRELOVED FINDS',
        headline: 'Turn thrifted gems into daily cash revenue.',
        highlightTag: 'ZERO OVERHEAD',
        specs: ['Simple Photo Uploads', 'Manage SKU Inventory'],
        image: 'https://images.unsplash.com/photo-1489987707025-afc232f7ea0f?q=80&w=1000&auto=format&fit=crop'
    },
    {
        id: 'stationery',
        index: '05 // STUDIO PRINTS',
        category: 'INDIE ART & CUSTOM STICKERS',
        headline: 'Print on demand, pack, and hand to courier.',
        highlightTag: 'DIRECT TO BUYER',
        specs: ['100% Retained Rights', 'Instant Label Printing'],
        image: 'https://images.unsplash.com/photo-1513542789411-b6a5d4f31634?q=80&w=1000&auto=format&fit=crop'
    }
];

export default function SellerLanding() {
    // Section-by-section unique scroll entrance triggers
    const [heroLoaded, setHeroLoaded] = useState(false);
    const [showcaseInView, setShowcaseInView] = useState(false);
    const [economicsInView, setEconomicsInView] = useState(false);
    const [workflowInView, setWorkflowInView] = useState(false);
    const [faqInView, setFaqInView] = useState(false);
    const [closingInView, setClosingInView] = useState(false);

    // Section Refs
    const showcaseRef = useRef<HTMLDivElement>(null);
    const economicsRef = useRef<HTMLDivElement>(null);
    const workflowRef = useRef<HTMLDivElement>(null);
    const faqRef = useRef<HTMLDivElement>(null);
    const closingRef = useRef<HTMLDivElement>(null);

    // Trigger hero entrance immediately on mount
    useEffect(() => {
        const timer = setTimeout(() => setHeroLoaded(true), 50);
        return () => clearTimeout(timer);
    }, []);

    // IntersectionObserver to orchestrate unique scroll animations per section
    useEffect(() => {
        const createObserver = (ref: React.RefObject<HTMLDivElement | null>, setter: (val: boolean) => void, threshold = 0.15) => {
            if (!ref.current) return null;
            const observer = new IntersectionObserver(([entry]) => {
                if (entry.isIntersecting) {
                    setter(true);
                }
            }, { threshold });
            observer.observe(ref.current);
            return observer;
        };

        const obsShowcase = createObserver(showcaseRef, setShowcaseInView, 0.1);
        const obsEconomics = createObserver(economicsRef, setEconomicsInView, 0.2);
        const obsWorkflow = createObserver(workflowRef, setWorkflowInView, 0.2);
        const obsFaq = createObserver(faqRef, setFaqInView, 0.15);
        const obsClosing = createObserver(closingRef, setClosingInView, 0.2);

        return () => {
            obsShowcase?.disconnect();
            obsEconomics?.disconnect();
            obsWorkflow?.disconnect();
            obsFaq?.disconnect();
            obsClosing?.disconnect();
        };
    }, []);

    // Interactive Economics Slider (Official 10% flat platform commission criteria)
    const [salesVolume, setSalesVolume] = useState(80);
    const [itemPrice, setItemPrice] = useState(500);

    // FAQ Accordion
    const [openFaq, setOpenFaq] = useState<number | null>(0);

    // Official Criteria: 10% Flat Platform Commission
    const grossTotal = salesVolume * itemPrice;
    const platformFee = Math.round(grossTotal * 0.10); // Exactly 10% official platform commission
    const netTakeHome = grossTotal - platformFee; // Sellers retain 90%
    const competitorLoss = Math.round(grossTotal * 0.20); // Competitors 18-22% (~20%)

    const formatCurrency = (val: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        }).format(val);
    };

    const faqs = [
        {
            q: "Can I sell without a DTI registration or business permit?",
            a: "Yes. BagooPH is built for student creators, campus artisans, and micro-merchants. You only need basic store details, your contact number, and a pickup address to start publishing products."
        },
        {
            q: "What is BagooPH's official platform commission fee?",
            a: "BagooPH charges a transparent 10% flat platform commission strictly on completed Cash on Delivery orders. There are zero listing fees, zero setup costs, and zero monthly subscriptions."
        },
        {
            q: "How does Cash on Delivery settlement work?",
            a: "When your customer orders, your assigned courier collects full physical cash at their doorstep upon delivery. The payment is recorded directly in your seller ledger for straightforward cash withdrawal."
        },
        {
            q: "How do couriers handle doorstep pickup?",
            a: "Once you pack your parcel and tap 'Ready for Pickup', the nearest courier is dispatched directly to your home, dorm, or workspace to collect the package."
        }
    ];

    return (
        <div className="min-h-screen bg-[#08090A] text-[#F7F8F8] font-sans selection:bg-[#E00D42] selection:text-white relative overflow-hidden">
            <Head title="BagooPH — Seller Standard | Minimalist Cash on Delivery Commerce" />

            {/* Subtle Ambient Radial Lighting */}
            <div 
                className="fixed top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[500px] pointer-events-none opacity-20 z-0"
                style={{
                    background: 'radial-gradient(circle at 50% 0%, rgba(224, 13, 66, 0.25) 0%, rgba(8, 9, 10, 0) 70%)'
                }}
            />

            {/* Subtle Geometric Wireframe Grid Lines */}
            <div 
                className="fixed inset-0 pointer-events-none opacity-[0.025] z-0"
                style={{
                    backgroundImage: 'linear-gradient(to right, #FFFFFF 1px, transparent 1px), linear-gradient(to bottom, #FFFFFF 1px, transparent 1px)',
                    backgroundSize: '48px 48px'
                }}
            />

            <div className="relative z-10 flex flex-col min-h-screen">

                {/* 1. FLOATING MINIMALIST PILL NAVIGATION */}
                <div className="sticky top-5 z-50 max-w-5xl mx-auto px-4 w-full">
                    <header className="bg-[#0E1012]/80 backdrop-blur-xl border border-white/[0.08] rounded-full px-5 py-3 shadow-2xl flex items-center justify-between">
                        
                        {/* Brand Signature */}
                        <Link href="/" className="flex items-center gap-2.5 group">
                            <BagooLogo className="w-7 h-7 group-hover:scale-105 transition-transform duration-200" rounded="rounded-lg" />
                            <div className="flex items-center gap-1.5 font-mono text-sm tracking-tight">
                                <span className="font-bold text-white">Bagoo</span>
                                <span className="text-[#E00D42] font-black">PH</span>
                                <span className="text-[10px] text-white/30 uppercase tracking-widest pl-1 font-semibold">
                                    SELLER
                                </span>
                            </div>
                        </Link>

                        {/* Minimal Navigation Anchors */}
                        <nav className="hidden md:flex items-center gap-7 font-mono text-xs text-white/50 tracking-wider uppercase">
                            <a href="#showcase" className="hover:text-white transition-colors">Showcase</a>
                            <a href="#economics" className="hover:text-white transition-colors">Economics</a>
                            <a href="#workflow" className="hover:text-white transition-colors">Flow</a>
                            <a href="#faq" className="hover:text-white transition-colors">FAQ</a>
                        </nav>

                        {/* Discrete Actions */}
                        <div className="flex items-center gap-3">
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className="text-white/60 hover:text-white font-mono text-xs uppercase tracking-wider transition px-3 py-1.5"
                            >
                                Sign In
                            </a>
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="px-4 py-2 rounded-full bg-white hover:bg-slate-100 text-black font-mono text-xs font-bold uppercase tracking-wider transition-all duration-200 shadow-sm active:scale-95 flex items-center gap-1.5"
                            >
                                <span>Open Store</span>
                                <ArrowRight className="w-3 h-3 text-black" />
                            </a>
                        </div>

                    </header>
                </div>

                {/* 2. HERO SECTION — STAGGERED UNIQUE SCROLL / LOAD ANIMATIONS */}
                <section className="pt-20 pb-20 sm:pt-28 sm:pb-32 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto text-center space-y-8">
                    
                    {/* Precision Status Dot (Scale-in Animation) */}
                    <div 
                        className={`inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/[0.03] border border-white/[0.08] transition-all duration-700 ease-out transform ${
                            heroLoaded ? 'opacity-100 scale-100' : 'opacity-0 scale-90'
                        }`}
                    >
                        <span className="w-2 h-2 rounded-full bg-[#E00D42] shadow-[0_0_8px_#E00D42]" />
                        <span className="font-mono text-[11px] text-white/70 uppercase tracking-widest">
                            MERCHANT STANDARD 2026
                        </span>
                    </div>

                    {/* Bold Editorial Headline (Slide-up Animation) */}
                    <h1 
                        className={`text-5xl sm:text-7xl lg:text-8xl font-extrabold tracking-[-0.04em] text-white leading-[0.98] max-w-4xl mx-auto transition-all duration-700 delay-150 ease-out transform ${
                            heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                        }`}
                    >
                        Commerce, stripped <br />
                        <span className="text-white/40">down to essentials.</span>
                    </h1>

                    {/* Single Minimal Line of Copy (Slide-up Animation) */}
                    <p 
                        className={`text-base sm:text-lg text-white/60 font-sans max-w-xl mx-auto leading-relaxed transition-all duration-700 delay-300 ease-out transform ${
                            heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                        }`}
                    >
                        10% flat commission. Direct Cash on Delivery remittance. Doorstep courier pickup for independent Philippine creators.
                    </p>

                    {/* Minimalist Dual CTAs (Slide-up Animation) */}
                    <div 
                        className={`flex flex-col sm:flex-row items-center justify-center gap-3.5 pt-2 transition-all duration-700 delay-450 ease-out transform ${
                            heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'
                        }`}
                    >
                        <a
                            href={getDomainUrl('seller', '/register')}
                            className="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white hover:bg-slate-100 text-black font-mono text-xs font-bold uppercase tracking-wider transition-all active:scale-95 shadow-lg flex items-center justify-center gap-2"
                        >
                            <span>Launch Your Store — Free</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                        </a>
                        <a
                            href={getDomainUrl('seller', '/login')}
                            className="w-full sm:w-auto px-7 py-3.5 rounded-full bg-white/[0.03] hover:bg-white/[0.07] border border-white/[0.12] text-white/80 hover:text-white font-mono text-xs uppercase tracking-wider transition flex items-center justify-center gap-2"
                        >
                            <span>Merchant Sign In</span>
                        </a>
                    </div>

                    {/* Micro-Telemetry Ticker Line (Fade-in Animation) */}
                    <div 
                        className={`pt-8 flex flex-wrap items-center justify-center gap-8 font-mono text-xs text-white/40 border-t border-white/[0.06] max-w-xl mx-auto transition-all duration-700 delay-600 ease-out ${
                            heroLoaded ? 'opacity-100' : 'opacity-0'
                        }`}
                    >
                        <div className="flex items-center gap-2">
                            <span className="text-white font-bold">10%</span>
                            <span>Flat Commission</span>
                        </div>
                        <span className="text-white/20">•</span>
                        <div className="flex items-center gap-2">
                            <span className="text-white font-bold">1-Click</span>
                            <span>Standard Waybill</span>
                        </div>
                        <span className="text-white/20">•</span>
                        <div className="flex items-center gap-2">
                            <span className="text-white font-bold">100%</span>
                            <span>Doorstep COD</span>
                        </div>
                    </div>

                </section>

                {/* 3. SECTION 2: INFINITE MOVING VISUAL CARDS TRACK WITH SCROLL ENTRANCE */}
                <section id="showcase" ref={showcaseRef} className="pb-28 pt-4 overflow-hidden">
                    
                    {/* Section Header (Smooth Slide-up on Scroll) */}
                    <div 
                        className={`max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 mb-10 space-y-2 transition-all duration-700 ease-out transform ${
                            showcaseInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                        }`}
                    >
                        <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block">
                            ARCHIVAL SHOWCASE // LIVE TRACK
                        </span>
                        <h2 className="text-3xl sm:text-5xl font-extrabold tracking-tight text-white leading-tight">
                            Built for local brands that move fast.
                        </h2>
                    </div>

                    {/* Edge-to-Edge Masked Infinite Moving Visual Carousel Track */}
                    <div 
                        className={`relative w-full overflow-hidden transition-all duration-1000 delay-150 ease-out transform ${
                            showcaseInView ? 'opacity-100 scale-100' : 'opacity-0 scale-95'
                        }`}
                        style={{
                            maskImage: 'linear-gradient(to right, transparent, black 8%, black 92%, transparent)',
                            WebkitMaskImage: 'linear-gradient(to right, transparent, black 8%, black 92%, transparent)'
                        }}
                    >
                        <div className="flex gap-6 animate-marquee-slow hover:[animation-play-state:paused] w-max py-2">
                            {MOVING_CARDS.concat(MOVING_CARDS).map((card, idx) => (
                                <div
                                    key={`${card.id}-${idx}`}
                                    className="w-[340px] sm:w-[380px] h-[460px] shrink-0 group bg-[#0C0D0E] border border-white/[0.08] hover:border-white/30 rounded-3xl overflow-hidden flex flex-col justify-between shadow-2xl relative transition-all duration-500 hover:-translate-y-1.5 cursor-pointer"
                                >
                                    {/* Full-Bleed Photographic Background with Gradient Scrim */}
                                    <div className="absolute inset-0 z-0">
                                        <img 
                                            src={card.image} 
                                            alt={card.headline} 
                                            className="w-full h-full object-cover object-center opacity-40 group-hover:scale-105 group-hover:opacity-55 transition-all duration-700 ease-out" 
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-[#0C0D0E] via-[#0C0D0E]/60 to-[#0C0D0E]/20" />
                                    </div>

                                    {/* Card Top Pill Metadata */}
                                    <div className="relative z-10 p-6 flex items-center justify-between">
                                        <span className="px-3 py-1 rounded-full bg-black/60 backdrop-blur-md border border-white/10 font-mono text-[10px] text-white/80 uppercase tracking-widest">
                                            {card.index}
                                        </span>
                                        <span className="px-2.5 py-1 rounded-full bg-[#E00D42]/20 border border-[#E00D42]/40 text-[#E00D42] font-mono text-[9px] font-bold uppercase tracking-wider backdrop-blur-md">
                                            {card.highlightTag}
                                        </span>
                                    </div>

                                    {/* Card Bottom Content (Ultra-Minimal: Zero Fluff, 1 Punchy Line + Specs) */}
                                    <div className="relative z-10 p-6 space-y-4">
                                        <span className="font-mono text-[10px] text-white/40 uppercase tracking-widest block">
                                            {card.category}
                                        </span>
                                        <h3 className="text-xl sm:text-2xl font-bold text-white tracking-tight leading-snug">
                                            {card.headline}
                                        </h3>
                                        
                                        {/* Micro Spec Badges */}
                                        <div className="pt-3 border-t border-white/[0.08] flex items-center gap-2 font-mono text-[10px] text-white/60">
                                            {card.specs.map((s, sIdx) => (
                                                <span key={sIdx} className="px-2 py-0.5 rounded bg-white/[0.04] border border-white/[0.06]">
                                                    {s}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                </section>

                {/* 4. PANEL FIG 0.1: MINIMALIST TRANSPARENT ECONOMICS (SIDE-BY-SIDE SLIDE-IN SCROLL ANIMATION) */}
                <section id="economics" ref={economicsRef} className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-white/[0.06]">
                    <div className="space-y-12">
                        
                        <div 
                            className={`flex flex-col sm:flex-row sm:items-end justify-between gap-4 transition-all duration-700 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <div>
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block">
                                    FIG 0.1 — TRANSPARENT ECONOMICS
                                </span>
                                <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white mt-1">
                                    Keep 90% of your gross sales.
                                </h2>
                            </div>
                            <p className="text-white/50 text-xs sm:text-sm font-sans max-w-xs">
                                Transparent 10% flat platform commission on delivered orders. Zero listing fees, zero setup surcharges.
                            </p>
                        </div>

                        {/* Minimalist Dual Column with Opposing Slide-In Animations */}
                        <div className="grid grid-cols-1 md:grid-cols-12 gap-8 items-center bg-[#0C0D0E] p-6 sm:p-10 rounded-2xl border border-white/[0.08] overflow-hidden">
                            
                            {/* Sliders Column (Slide in from Left) */}
                            <div 
                                className={`md:col-span-6 space-y-6 font-mono transition-all duration-800 delay-150 ease-out transform ${
                                    economicsInView ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-12'
                                }`}
                            >
                                <div>
                                    <div className="flex justify-between text-xs text-white/50 mb-2">
                                        <span>AVERAGE ITEM PRICE</span>
                                        <span className="text-white font-bold">{formatCurrency(itemPrice)}</span>
                                    </div>
                                    <input 
                                        type="range"
                                        min="150"
                                        max="3000"
                                        step="50"
                                        value={itemPrice}
                                        onChange={e => setItemPrice(Number(e.target.value))}
                                        className="w-full h-1 bg-white/10 rounded-lg appearance-none cursor-pointer accent-[#E00D42]"
                                    />
                                </div>

                                <div>
                                    <div className="flex justify-between text-xs text-white/50 mb-2">
                                        <span>MONTHLY COMPLETED ORDERS</span>
                                        <span className="text-white font-bold">{salesVolume} orders</span>
                                    </div>
                                    <input 
                                        type="range"
                                        min="10"
                                        max="400"
                                        step="10"
                                        value={salesVolume}
                                        onChange={e => setSalesVolume(Number(e.target.value))}
                                        className="w-full h-1 bg-white/10 rounded-lg appearance-none cursor-pointer accent-[#E00D42]"
                                    />
                                </div>
                            </div>

                            {/* Telemetry Output Column (Slide in from Right) */}
                            <div 
                                className={`md:col-span-6 font-mono space-y-4 p-6 rounded-xl bg-white/[0.02] border border-white/[0.06] transition-all duration-800 delay-300 ease-out transform ${
                                    economicsInView ? 'opacity-100 translate-x-0' : 'opacity-0 translate-x-12'
                                }`}
                            >
                                <div>
                                    <span className="text-[10px] text-white/40 uppercase tracking-wider block">
                                        Net Cash in Your Pocket (90%)
                                    </span>
                                    <span className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight mt-1 block">
                                        {formatCurrency(netTakeHome)}
                                    </span>
                                </div>

                                <div className="space-y-1.5 text-xs text-white/50 border-t border-white/10 pt-3">
                                    <div className="flex justify-between">
                                        <span>Gross Doorstep COD:</span>
                                        <span className="text-white">{formatCurrency(grossTotal)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>10% Platform Commission:</span>
                                        <span className="text-[#E00D42]">-{formatCurrency(platformFee)}</span>
                                    </div>
                                </div>

                                <div className="p-2.5 rounded bg-white/[0.03] border border-white/[0.08] text-[11px] text-white/60">
                                    Standard 18-22% competitor platforms deduct: <strong className="text-emerald-400">+{formatCurrency(competitorLoss - platformFee)} more</strong>
                                </div>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 5. PANEL FIG 0.2: THE 3-BEAT EXECUTION ARC (CASCADING SEQUENTIAL DROP ANIMATION) */}
                <section id="workflow" ref={workflowRef} className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-white/[0.06]">
                    <div className="space-y-12">
                        
                        <div 
                            className={`transition-all duration-700 ease-out transform ${
                                workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block">
                                FIG 0.2 — EXECUTION PROTOCOL
                            </span>
                            <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white mt-1">
                                From link to cash in three beats.
                            </h2>
                        </div>

                        {/* Cascading 3 Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 font-mono">
                            
                            {/* Step 01 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-4 hover:border-white/20 transition-all duration-700 delay-100 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <span className="text-4xl font-extrabold text-white/20 block">01</span>
                                <h3 className="text-base font-bold text-white">Create Shop Link</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Register in 60 seconds with your brand name and pickup address. No upfront capital or paperwork hurdles.
                                </p>
                            </div>

                            {/* Step 02 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-4 hover:border-white/20 transition-all duration-700 delay-300 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <span className="text-4xl font-extrabold text-white/20 block">02</span>
                                <h3 className="text-base font-bold text-white">Handover to Rider</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Attach the 1-click thermal waybill. Local couriers collect parcels right from your door or campus.
                                </p>
                            </div>

                            {/* Step 03 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-4 hover:border-white/20 transition-all duration-700 delay-500 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <span className="text-4xl font-extrabold text-emerald-400/40 block">03</span>
                                <h3 className="text-base font-bold text-white">Collect Cash</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Courier delivers to the customer, collects Cash on Delivery, and remits funds straight to your ledger.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 6. MINIMALIST FAQ ACCORDION (RIPPLE FADE-IN ANIMATION) */}
                <section id="faq" ref={faqRef} className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-white/[0.06] w-full">
                    <div className="space-y-8">
                        
                        <div 
                            className={`text-center space-y-2 transition-all duration-700 ease-out transform ${
                                faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-6'
                            }`}
                        >
                            <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest">
                                FIG 0.3 — INQUIRIES
                            </span>
                            <h2 className="text-3xl font-extrabold tracking-tight text-white">
                                Questions answered.
                            </h2>
                        </div>

                        <div className="space-y-3 font-sans">
                            {faqs.map((faq, index) => {
                                const isOpen = openFaq === index;
                                const delays = ['delay-100', 'delay-200', 'delay-300', 'delay-400'];
                                return (
                                    <div 
                                        key={index}
                                        className={`bg-[#0C0D0E] border border-white/[0.08] rounded-xl overflow-hidden transition-all duration-700 ease-out transform ${
                                            faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'
                                        } ${delays[index]}`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setOpenFaq(isOpen ? null : index)}
                                            className="w-full p-5 text-left flex items-center justify-between gap-4 focus:outline-hidden"
                                        >
                                            <span className="font-bold text-sm sm:text-base text-white/90">
                                                {faq.q}
                                            </span>
                                            {isOpen ? (
                                                <ChevronUp className="w-4 h-4 text-white/40 shrink-0" />
                                            ) : (
                                                <ChevronDown className="w-4 h-4 text-white/40 shrink-0" />
                                            )}
                                        </button>
                                        {isOpen && (
                                            <div className="px-5 pb-5 text-xs sm:text-sm text-white/50 leading-relaxed border-t border-white/[0.04] pt-3">
                                                {faq.a}
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                    </div>
                </section>

                {/* 7. CLOSING STATEMENT CTA (EXPANDING RADIAL GLOW & SCALE ANIMATION) */}
                <section id="closing" ref={closingRef} className="py-28 border-t border-white/[0.06] relative overflow-hidden text-center px-4">
                    {/* Radial Ambient Glow (Expands on Scroll) */}
                    <div 
                        className={`absolute inset-0 pointer-events-none transition-all duration-1000 ease-out transform ${
                            closingInView ? 'opacity-25 scale-100' : 'opacity-0 scale-75'
                        }`}
                        style={{
                            background: 'radial-gradient(circle at 50% 50%, rgba(224, 13, 66, 0.3) 0%, transparent 60%)'
                        }}
                    />

                    <div 
                        className={`max-w-xl mx-auto space-y-6 relative z-10 transition-all duration-700 delay-150 ease-out transform ${
                            closingInView ? 'opacity-100 scale-100' : 'opacity-0 scale-95'
                        }`}
                    >
                        <h2 className="text-4xl sm:text-5xl font-extrabold tracking-[-0.03em] text-white">
                            Build your brand. <br />
                            <span className="text-white/40">Get paid in cash.</span>
                        </h2>

                        <p className="text-sm text-white/60 max-w-sm mx-auto font-sans">
                            Start listing your catalog today on BagooPH with 10% flat commission and reliable doorstep fulfillment.
                        </p>

                        <div className="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="w-full sm:w-auto px-8 py-3.5 rounded-full bg-white hover:bg-slate-200 text-black font-mono text-xs font-bold uppercase tracking-wider transition active:scale-95"
                            >
                                Open Store Free
                            </a>
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className="w-full sm:w-auto px-7 py-3.5 rounded-full bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 text-white font-mono text-xs uppercase tracking-wider transition"
                            >
                                Merchant Sign In
                            </a>
                        </div>
                    </div>
                </section>

                {/* 8. MONOCHROME STUDIO FOOTER */}
                <footer className="bg-[#050607] border-t border-white/[0.06] py-10 px-4 sm:px-6 lg:px-8 font-mono text-xs text-white/40">
                    <div className="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                        <div className="flex items-center gap-2.5">
                            <BagooLogo className="w-6 h-6" rounded="rounded-md" />
                            <span className="font-bold text-white tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                            <span>•</span>
                            <span>Seller Operations</span>
                        </div>

                        <div className="flex flex-wrap items-center gap-6">
                            <a href={getDomainUrl('buyer', '/')} className="hover:text-white transition">Marketplace</a>
                            <a href={getDomainUrl('courier', '/')} className="hover:text-white transition">Courier</a>
                            <a href={getDomainUrl('admin', '/')} className="hover:text-white transition">Admin</a>
                            <span>&copy; {new Date().getFullYear()}</span>
                        </div>
                    </div>
                </footer>

            </div>
        </div>
    );
}
