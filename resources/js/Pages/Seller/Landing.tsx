import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';
import { 
    ArrowRight, 
    Check, 
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
    Flame,
    Sun,
    Moon
} from 'lucide-react';

export default function SellerLanding() {
    // Theme Switcher State: White Mode (Light) vs Dark Mode
    const [isLight, setIsLight] = useState<boolean>(() => {
        if (typeof window !== 'undefined') {
            const saved = localStorage.getItem('bagooph_seller_theme');
            if (saved !== null) return saved === 'light';
        }
        return true; // Initialize in White Mode (Light Mode) for user evaluation
    });

    const toggleTheme = () => {
        const next = !isLight;
        setIsLight(next);
        if (typeof window !== 'undefined') {
            localStorage.setItem('bagooph_seller_theme', next ? 'light' : 'dark');
        }
    };

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

    // Interactive Economics Spline Graph State (Linear-Style Cursor Tracking)
    const [graphProgress, setGraphProgress] = useState(0.60); // Initial resting point (~180 orders)
    const [isGraphHovered, setIsGraphHovered] = useState(false);
    const itemPrice = 500; // Benchmark selling price in PHP (standard retail item)

    // Active calculations based on dynamic graph scrubber position (0 to 300 orders)
    const activeOrders = Math.max(5, Math.round(graphProgress * 300));
    const activeGross = activeOrders * itemPrice;
    const activePlatformFee = Math.round(activeGross * 0.10); // Official 10% Flat Platform Commission
    const activeNet = activeGross - activePlatformFee; // Sellers retain 90%
    const maxGross = 300 * itemPrice;

    // SVG Coordinate Geometry (viewBox: 0 0 800 320)
    // Plot bounds: Left=85, Right=755 (width=670), Top=45, Baseline=250 (height=205)
    const curX = Math.round(85 + (activeOrders / 300) * 670);
    const curYGross = Math.round(250 - (activeGross / maxGross) * 205);
    const curYNet = Math.round(250 - (activeNet / maxGross) * 205);

    // Handle interactive mouse / touch scrub across graph
    const handleGraphScrub = (clientX: number, rect: DOMRect) => {
        const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
        const svgX = (x / rect.width) * 800;
        const clampedSvgX = Math.max(85, Math.min(755, svgX));
        const pct = (clampedSvgX - 85) / 670;
        setGraphProgress(pct);
        setIsGraphHovered(true);
    };

    // FAQ Accordion
    const [openFaq, setOpenFaq] = useState<number | null>(0);

    const formatCurrency = (val: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        }).format(val);
    };

    const formatShortCurrency = (val: number) => {
        if (val >= 1000000) return `₱${(val / 1000000).toFixed(1)}M`;
        if (val >= 1000) return `₱${Math.round(val / 1000)}k`;
        return `₱${val}`;
    };

    const faqs = [
        {
            q: "What do I need to apply as a seller? Is approval difficult?",
            a: "Applying is fast and frictionless. You only need 1 valid ID (a Student ID for campus creators or any government-issued ID), basic contact info, and your pickup address. Zero DTI, SEC, or BIR paperwork is required. Once submitted, our admin reviews and approves your merchant store in 1 click."
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
        <div className={`min-h-screen ${isLight ? 'bg-[#FAFAFA] text-[#0F172A]' : 'bg-[#08090A] text-[#F7F8F8]'} font-sans selection:bg-[#E00D42] selection:text-white relative overflow-hidden transition-colors duration-300`}>
            <Head title="BagooPH — Seller Standard | Minimalist Cash on Delivery Commerce" />

            {/* Subtle Ambient Radial Lighting */}
            <div 
                className={`fixed top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[500px] pointer-events-none z-0 transition-opacity duration-500 ${
                    isLight ? 'opacity-40' : 'opacity-20'
                }`}
                style={{
                    background: isLight 
                        ? 'radial-gradient(circle at 50% 0%, rgba(224, 13, 66, 0.08) 0%, rgba(250, 250, 250, 0) 70%)'
                        : 'radial-gradient(circle at 50% 0%, rgba(224, 13, 66, 0.25) 0%, rgba(8, 9, 10, 0) 70%)'
                }}
            />

            {/* Subtle Geometric Wireframe Grid Lines */}
            <div 
                className={`fixed inset-0 pointer-events-none z-0 ${isLight ? 'opacity-[0.035]' : 'opacity-[0.025]'}`}
                style={{
                    backgroundImage: isLight
                        ? 'linear-gradient(to right, #000000 1px, transparent 1px), linear-gradient(to bottom, #000000 1px, transparent 1px)'
                        : 'linear-gradient(to right, #FFFFFF 1px, transparent 1px), linear-gradient(to bottom, #FFFFFF 1px, transparent 1px)',
                    backgroundSize: '48px 48px'
                }}
            />

            <div className="relative z-10 flex flex-col min-h-screen">

                {/* 1. FLOATING MINIMALIST PILL NAVIGATION */}
                <div className="sticky top-5 z-50 max-w-5xl mx-auto px-4 w-full">
                    <header className={`backdrop-blur-xl border rounded-full px-5 py-3 shadow-2xl flex items-center justify-between transition-colors duration-300 ${
                        isLight 
                            ? 'bg-white/85 border-slate-200 shadow-slate-200/50' 
                            : 'bg-[#0E1012]/80 border-white/[0.08]'
                    }`}>
                        
                        {/* Brand Signature */}
                        <Link href="/" className="flex items-center gap-2.5 group">
                            <BagooLogo className="w-7 h-7 group-hover:scale-105 transition-transform duration-200" rounded="rounded-lg" />
                            <div className="flex items-center gap-1.5 font-mono text-sm tracking-tight">
                                <span className={`font-bold transition-colors ${isLight ? 'text-slate-900' : 'text-white'}`}>Bagoo</span>
                                <span className="text-[#E00D42] font-black">PH</span>
                                <span className={`text-[10px] uppercase tracking-widest pl-1 font-semibold transition-colors ${isLight ? 'text-slate-500' : 'text-white/30'}`}>
                                    SELLER
                                </span>
                            </div>
                        </Link>

                        {/* Minimal Navigation Anchors */}
                        <nav className={`hidden md:flex items-center gap-7 font-mono text-xs tracking-wider uppercase transition-colors ${
                            isLight ? 'text-slate-600 font-medium' : 'text-white/50'
                        }`}>
                            <a href="#showcase" className={`transition-colors ${isLight ? 'hover:text-slate-900' : 'hover:text-white'}`}>Showcase</a>
                            <a href="#economics" className={`transition-colors ${isLight ? 'hover:text-slate-900' : 'hover:text-white'}`}>Economics</a>
                            <a href="#workflow" className={`transition-colors ${isLight ? 'hover:text-slate-900' : 'hover:text-white'}`}>Flow</a>
                            <a href="#faq" className={`transition-colors ${isLight ? 'hover:text-slate-900' : 'hover:text-white'}`}>FAQ</a>
                        </nav>

                        {/* Discrete Actions */}
                        <div className="flex items-center gap-2.5">
                            {/* Theme Toggle Button */}
                            <button
                                type="button"
                                onClick={toggleTheme}
                                className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full font-mono text-xs uppercase tracking-wider transition ${
                                    isLight 
                                        ? 'bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-300/80' 
                                        : 'bg-white/[0.05] hover:bg-white/[0.1] text-white/70 hover:text-white border border-white/[0.1]'
                                }`}
                                title="Toggle White Mode / Dark Mode"
                            >
                                {isLight ? (
                                    <>
                                        <Moon className="w-3.5 h-3.5 text-slate-700" />
                                        <span>Dark</span>
                                    </>
                                ) : (
                                    <>
                                        <Sun className="w-3.5 h-3.5 text-amber-400" />
                                        <span>White</span>
                                    </>
                                )}
                            </button>

                            <a
                                href={getDomainUrl('seller', '/login')}
                                className={`font-mono text-xs uppercase tracking-wider transition px-3 py-1.5 ${
                                    isLight ? 'text-slate-700 hover:text-slate-900 font-medium' : 'text-white/60 hover:text-white'
                                }`}
                            >
                                Sign In
                            </a>
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className={`px-4 py-2 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition-all duration-200 shadow-sm active:scale-95 flex items-center gap-1.5 ${
                                    isLight 
                                        ? 'bg-[#0F172A] hover:bg-black text-white shadow-slate-300' 
                                        : 'bg-white hover:bg-slate-100 text-black'
                                }`}
                            >
                                <span>Open Store</span>
                                <ArrowRight className="w-3 h-3" />
                            </a>
                        </div>

                    </header>
                </div>

                {/* 2. HERO SECTION — STAGGERED UNIQUE SCROLL / LOAD ANIMATIONS */}
                <section className="pt-16 pb-12 sm:pt-24 sm:pb-16 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto text-center space-y-8">
                    
                    {/* Precision Status Dot */}
                    <div 
                        className={`inline-flex items-center gap-2 px-3 py-1 rounded-full border transition-all duration-700 ease-out transform ${
                            isLight 
                                ? 'bg-slate-100/90 border-slate-300/80 text-slate-800 font-medium' 
                                : 'bg-white/[0.03] border-white/[0.08] text-white/70'
                        } ${heroLoaded ? 'opacity-100 scale-100' : 'opacity-0 scale-90'}`}
                    >
                        <span className="w-2 h-2 rounded-full bg-[#E00D42] shadow-[0_0_8px_#E00D42]" />
                        <span className="font-mono text-[11px] uppercase tracking-widest">
                            MERCHANT STANDARD 2026
                        </span>
                    </div>

                    {/* Bold Editorial Headline */}
                    <h1 
                        className={`text-5xl sm:text-7xl lg:text-8xl font-extrabold tracking-[-0.04em] leading-[0.98] max-w-4xl mx-auto transition-all duration-700 delay-150 ease-out transform ${
                            isLight ? 'text-[#0F172A]' : 'text-white'
                        } ${heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'}`}
                    >
                        Commerce, stripped <br />
                        <span className={isLight ? 'text-slate-600' : 'text-white/40'}>down to essentials.</span>
                    </h1>

                    {/* Single Minimal Line of Copy */}
                    <p 
                        className={`text-base sm:text-lg font-sans max-w-xl mx-auto leading-relaxed transition-all duration-700 delay-300 ease-out transform ${
                            isLight ? 'text-slate-700 font-normal' : 'text-white/60'
                        } ${heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'}`}
                    >
                        10% flat commission. Direct Cash on Delivery remittance. Doorstep courier pickup for independent Philippine creators.
                    </p>

                    {/* Minimalist Dual CTAs */}
                    <div 
                        className={`flex flex-col sm:flex-row items-center justify-center gap-3.5 pt-2 transition-all duration-700 delay-450 ease-out transform ${
                            heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'
                        }`}
                    >
                        <a
                            href={getDomainUrl('seller', '/register')}
                            className={`w-full sm:w-auto px-8 py-3.5 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition-all active:scale-95 shadow-lg flex items-center justify-center gap-2 ${
                                isLight 
                                    ? 'bg-[#0F172A] hover:bg-black text-white shadow-slate-300' 
                                    : 'bg-white hover:bg-slate-100 text-black'
                            }`}
                        >
                            <span>Launch Your Store — Free</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                        </a>
                        <a
                            href={getDomainUrl('seller', '/login')}
                            className={`w-full sm:w-auto px-7 py-3.5 rounded-full border font-mono text-xs uppercase tracking-wider transition flex items-center justify-center gap-2 ${
                                isLight 
                                    ? 'bg-white hover:bg-slate-50 border-slate-300 text-slate-900 font-semibold shadow-xs' 
                                    : 'bg-white/[0.03] hover:bg-white/[0.07] border-white/[0.12] text-white/80 hover:text-white'
                            }`}
                        >
                            <span>Merchant Sign In</span>
                        </a>
                    </div>

                </section>

                {/* 3. SECTION 2: PEEKING DESKTOP MERCHANT COCKPIT & CORE CAPABILITIES */}
                <section id="showcase" ref={showcaseRef} className="relative pb-24 pt-2 -mt-4 sm:-mt-8 lg:-mt-12">
                    
                    {/* Ambient Radial Underglow behind Desktop Window */}
                    <div className="pointer-events-none absolute left-1/2 -top-12 -translate-x-1/2 w-full max-w-5xl h-72 bg-gradient-to-b from-[#E00D42]/12 via-red-950/5 to-transparent blur-3xl -z-10" />

                    {/* 2.5D Perspective Container */}
                    <div className="max-w-5xl mx-auto px-3 sm:px-6 lg:px-8 [perspective:1200px] [perspective-origin:center_top]">

                        {/* Desktop Application Chassis Window Frame */}
                        <div 
                            className={`relative rounded-2xl p-[1px] transition-all duration-1000 ease-out transform ${
                                isLight 
                                    ? 'bg-gradient-to-b from-slate-300 via-slate-200 to-slate-300 shadow-[0_25px_60px_-15px_rgba(0,0,0,0.12),0_0_0_1px_rgba(0,0,0,0.06)]' 
                                    : 'bg-gradient-to-b from-white/20 via-white/8 to-white/0 shadow-[0_30px_70px_-15px_rgba(0,0,0,0.95),0_0_0_1px_rgba(255,255,255,0.06)]'
                            } ${
                                showcaseInView 
                                    ? 'opacity-100 translate-y-0 scale-100' 
                                    : 'opacity-90 translate-y-2 scale-[0.99]'
                            }`}
                        >
                            <div className={`relative rounded-[15px] overflow-hidden ${
                                isLight ? 'bg-slate-100' : 'bg-[#08090A]'
                            }`}>
                                
                                {/* Window Titlebar */}
                                <div className={`flex items-center justify-between px-4 py-3 border-b ${
                                    isLight 
                                        ? 'border-slate-200 bg-slate-100/95 text-slate-700' 
                                        : 'border-white/[0.08] bg-[#0A0D14]/90 text-white/70'
                                }`}>
                                    <div className="flex items-center gap-2">
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#FF5F56]" />
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#FFBD2E]" />
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#27C93F]" />
                                    </div>
                                    
                                    {/* URL Pill Indicator */}
                                    <div className={`flex items-center gap-2 px-3 py-0.5 rounded-full border font-mono text-[11px] ${
                                        isLight 
                                            ? 'border-slate-300/80 bg-white text-slate-700' 
                                            : 'border-white/[0.08] bg-white/[0.03] text-slate-300'
                                    }`}>
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                                        <span>bagoo.shop/seller/cockpit</span>
                                    </div>

                                    {/* Live Status Indicators */}
                                    <div className="flex items-center gap-2 font-mono text-[10px]">
                                        <span className="hidden sm:inline px-2 py-0.5 rounded bg-[#E00D42]/10 border border-[#E00D42]/20 text-[#E00D42] font-bold">
                                            10% FLAT COMMISSION
                                        </span>
                                        <span className={`px-2 py-0.5 rounded border uppercase font-mono ${
                                            isLight 
                                                ? 'bg-white border-slate-200 text-slate-500' 
                                                : 'bg-white/[0.04] border-white/10 text-white/40'
                                        }`}>
                                            MNL-01 LIVE
                                        </span>
                                    </div>
                                </div>

                                {/* Actual Dashboard Screenshot */}
                                <div className="relative aspect-[16/10] w-full overflow-hidden bg-slate-900">
                                    <img 
                                        src="/images/seller-cockpit-preview.png" 
                                        alt="BagooPH Merchant Command Cockpit Dashboard" 
                                        className="w-full h-full object-cover object-top select-none"
                                        loading="eager"
                                    />
                                    {/* Hairline Inner Highlight Rim */}
                                    <div className="pointer-events-none absolute inset-0 ring-1 ring-inset ring-black/5" />
                                </div>

                            </div>
                        </div>

                        {/* Three Minimalist Feature Cards Beneath Peeking Window */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 pt-8">
                            
                            <div className={`p-6 rounded-2xl border space-y-2.5 transition ${
                                isLight 
                                    ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                    : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                            }`}>
                                <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500 font-bold text-xs">
                                    01
                                </div>
                                <h3 className={`text-base font-bold pt-1 ${isLight ? 'text-slate-900' : 'text-white'}`}>Thermal Waybills</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Print standard A6 barcodes in one click. Zero manual handwriting.
                                </p>
                            </div>

                            <div className={`p-6 rounded-2xl border space-y-2.5 transition ${
                                isLight 
                                    ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                    : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                            }`}>
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 border border-[#E00D42]/20 flex items-center justify-center text-[#E00D42] font-bold text-xs">
                                    02
                                </div>
                                <h3 className={`text-base font-bold pt-1 ${isLight ? 'text-slate-900' : 'text-white'}`}>Direct COD Ledger</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Cash collected at doorstep. 90% remitted straight to your balance.
                                </p>
                            </div>

                            <div className={`p-6 rounded-2xl border space-y-2.5 transition ${
                                isLight 
                                    ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                    : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                            }`}>
                                <div className="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-500 font-bold text-xs">
                                    03
                                </div>
                                <h3 className={`text-base font-bold pt-1 ${isLight ? 'text-slate-900' : 'text-white'}`}>Doorstep Pickup</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Dedicated riders collect from your door. Never queue at branches.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 4. PANEL FIG 0.1: WHY US & INTERACTIVE SPLINE GRAPH (2-COLUMN EDITORIAL LAYOUT) */}
                <section id="economics" ref={economicsRef} className={`max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t ${
                    isLight ? 'border-slate-200' : 'border-white/[0.06]'
                }`}>
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-center">
                        
                        {/* LEFT COLUMN: WHY US - EDITORIAL VALUE PROPOSITION */}
                        <div 
                            className={`lg:col-span-5 space-y-6 transition-all duration-700 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <div className="space-y-2">
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block font-bold">
                                    FIG 0.1 — WHY US
                                </span>
                                <h2 className={`text-3xl sm:text-4xl font-extrabold tracking-tight ${
                                    isLight ? 'text-slate-900' : 'text-white'
                                }`}>
                                    Keep 90% of your gross sales.
                                </h2>
                            </div>

                            <p className={`text-sm sm:text-base font-sans leading-relaxed ${
                                isLight ? 'text-slate-600' : 'text-white/60'
                            }`}>
                                Traditional e-commerce platforms quietly strip 25% to 35% of your earnings through payment gateway fees, listing surcharges, and voucher deductions. Bagoo operates on a single transparent rule: a flat 10% platform fee.
                            </p>

                            {/* 3 Why Us Value Cards */}
                            <div className="space-y-3 pt-1">
                                <div className={`p-4 rounded-xl border transition ${
                                    isLight 
                                        ? 'bg-white border-slate-200/80 shadow-sm' 
                                        : 'bg-white/[0.03] border-white/[0.08]'
                                }`}>
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded-md bg-[#E00D42]/10 flex items-center justify-center text-[#E00D42] font-mono font-bold text-xs shrink-0 mt-0.5">
                                            01
                                        </div>
                                        <div>
                                            <h4 className={`text-sm font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>
                                                10% Flat Fee • Zero Hidden Surcharges
                                            </h4>
                                            <p className={`text-xs mt-1 leading-relaxed ${isLight ? 'text-slate-500' : 'text-white/50'}`}>
                                                No payment processing cuts, no listing costs, no mandatory voucher taxes.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div className={`p-4 rounded-xl border transition ${
                                    isLight 
                                        ? 'bg-white border-slate-200/80 shadow-sm' 
                                        : 'bg-white/[0.03] border-white/[0.08]'
                                }`}>
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded-md bg-emerald-500/10 flex items-center justify-center text-emerald-500 font-mono font-bold text-xs shrink-0 mt-0.5">
                                            02
                                        </div>
                                        <div>
                                            <h4 className={`text-sm font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>
                                                Direct Cash on Delivery Remittance
                                            </h4>
                                            <p className={`text-xs mt-1 leading-relaxed ${isLight ? 'text-slate-500' : 'text-white/50'}`}>
                                                Cash collected at the buyer's doorstep by our rider fleet is credited straight to your balance.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div className={`p-4 rounded-xl border transition ${
                                    isLight 
                                        ? 'bg-white border-slate-200/80 shadow-sm' 
                                        : 'bg-white/[0.03] border-white/[0.08]'
                                }`}>
                                    <div className="flex items-start gap-3">
                                        <div className="w-6 h-6 rounded-md bg-blue-500/10 flex items-center justify-center text-blue-500 font-mono font-bold text-xs shrink-0 mt-0.5">
                                            03
                                        </div>
                                        <div>
                                            <h4 className={`text-sm font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>
                                                Transparent Unit Economics
                                            </h4>
                                            <p className={`text-xs mt-1 leading-relaxed ${isLight ? 'text-slate-500' : 'text-white/50'}`}>
                                                A ₱500 item puts exactly ₱450 net cash in your pocket. Bagoo retains only ₱50 (10%).
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="pt-1">
                                <a 
                                    href={getDomainUrl('seller', '/register')}
                                    className="inline-flex items-center gap-2 font-mono text-xs font-bold text-[#E00D42] hover:underline"
                                >
                                    <span>Open your merchant store today</span>
                                    <ArrowRight className="w-3.5 h-3.5" />
                                </a>
                            </div>
                        </div>

                        {/* RIGHT COLUMN: INTERACTIVE VISUAL GRAPH CARD */}
                        <div 
                            className={`lg:col-span-7 border rounded-2xl p-5 sm:p-7 space-y-5 relative overflow-hidden transition-all duration-1000 ease-out transform ${
                                isLight 
                                    ? 'bg-white border-slate-200 shadow-xl' 
                                    : 'bg-[#0C0D0E] border-white/[0.08] shadow-2xl'
                            } ${economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                        >
                            {/* Top Telemetry Header */}
                            <div className={`flex flex-col sm:flex-row sm:items-start justify-between gap-4 pb-4 border-b ${
                                isLight ? 'border-slate-100' : 'border-white/[0.08]'
                            }`}>
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2 font-mono text-[11px] uppercase tracking-wider text-[#E00D42] font-semibold">
                                        <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42] animate-pulse" />
                                        <span>Interactive Payout Simulator (@ ₱500 / item)</span>
                                    </div>
                                    <div className="flex items-baseline gap-3">
                                        <span className={`text-3xl sm:text-4xl font-extrabold tracking-tight font-mono ${
                                            isLight ? 'text-slate-900' : 'text-white'
                                        }`}>
                                            {formatCurrency(activeNet)}
                                        </span>
                                        <span className={`text-[11px] font-mono font-semibold px-2.5 py-0.5 rounded-full ${
                                            isLight 
                                                ? 'bg-emerald-50 border border-emerald-200 text-emerald-700 font-medium' 
                                                : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400'
                                        }`}>
                                            90% Net Payout
                                        </span>
                                    </div>
                                    <p className={`font-mono text-xs ${isLight ? 'text-slate-500' : 'text-white/40'}`}>
                                        {activeOrders} units sold • {formatCurrency(activeGross)} Gross COD • -{formatCurrency(activePlatformFee)} (10% fee)
                                    </p>
                                </div>

                                {/* Graph Legend */}
                                <div className={`flex flex-col sm:items-end gap-1.5 font-mono text-[10px] shrink-0 ${
                                    isLight ? 'text-slate-500' : 'text-white/40'
                                }`}>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-3 h-1 rounded-full bg-[#E00D42]" />
                                        <span>Solid Red: <strong>90% Net Take-Home</strong></span>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <span className="w-3 h-0.5 border-b border-dashed border-slate-400" />
                                        <span>Dashed: <strong>100% Gross COD</strong></span>
                                    </div>
                                </div>
                            </div>

                            {/* Interactive SVG Coordinate Canvas */}
                            <div className="relative select-none pt-1">
                                <svg
                                    viewBox="0 0 800 320"
                                    className="w-full h-64 sm:h-72 cursor-crosshair overflow-visible"
                                    onMouseMove={(e) => handleGraphScrub(e.clientX, e.currentTarget.getBoundingClientRect())}
                                    onMouseLeave={() => setIsGraphHovered(false)}
                                    onTouchMove={(e) => {
                                        if (e.touches[0]) handleGraphScrub(e.touches[0].clientX, e.currentTarget.getBoundingClientRect());
                                    }}
                                    onTouchEnd={() => setIsGraphHovered(false)}
                                >
                                    <defs>
                                        {/* Area glow gradient for 90% Net curve */}
                                        <linearGradient id="activeAreaGlow" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor="#E00D42" stopOpacity={isLight ? "0.2" : "0.32"} />
                                            <stop offset="70%" stopColor="#E00D42" stopOpacity={isLight ? "0.04" : "0.08"} />
                                            <stop offset="100%" stopColor="#E00D42" stopOpacity="0" />
                                        </linearGradient>

                                        {/* Stroke gradient for illuminated curve */}
                                        <linearGradient id="activeStrokeGrad" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0%" stopColor="#FF6B8B" />
                                            <stop offset="100%" stopColor="#E00D42" />
                                        </linearGradient>

                                        {/* Clip path revealing illuminated lines strictly up to curX */}
                                        <clipPath id="graphProgressClip">
                                            <rect x="0" y="0" width={curX} height="320" />
                                        </clipPath>
                                    </defs>

                                    {/* Y-Axis Label */}
                                    <text 
                                        x="85" 
                                        y="24" 
                                        textAnchor="start" 
                                        className="font-mono text-[10px] font-bold tracking-wider fill-[#E00D42]"
                                    >
                                        Y AXIS: REVENUE & TAKE-HOME (₱)
                                    </text>

                                    {/* Y-Axis Vertical Line */}
                                    <line 
                                        x1="85" 
                                        y1="35" 
                                        x2="85" 
                                        y2="250" 
                                        stroke={isLight ? "rgba(0,0,0,0.18)" : "rgba(255,255,255,0.2)"} 
                                        strokeWidth="1.5" 
                                    />

                                    {/* Horizontal Guidelines & Y-Axis Graduations */}
                                    {/* Line 1: Peak Gross at 300 orders */}
                                    <line x1="85" y1="45" x2="755" y2="45" stroke={isLight ? "rgba(0,0,0,0.06)" : "rgba(255,255,255,0.06)"} strokeDasharray="3 3" />
                                    <text x="76" y="49" textAnchor="end" className="font-mono text-[10px] fill-slate-500 font-medium">
                                        {formatShortCurrency(maxGross)}
                                    </text>

                                    {/* Line 2: 2/3 Gross */}
                                    <line x1="85" y1="113" x2="755" y2="113" stroke={isLight ? "rgba(0,0,0,0.06)" : "rgba(255,255,255,0.06)"} strokeDasharray="3 3" />
                                    <text x="76" y="117" textAnchor="end" className="font-mono text-[10px] fill-slate-500 font-medium">
                                        {formatShortCurrency(Math.round(maxGross * 2 / 3))}
                                    </text>

                                    {/* Line 3: 1/3 Gross */}
                                    <line x1="85" y1="182" x2="755" y2="182" stroke={isLight ? "rgba(0,0,0,0.06)" : "rgba(255,255,255,0.06)"} strokeDasharray="3 3" />
                                    <text x="76" y="186" textAnchor="end" className="font-mono text-[10px] fill-slate-500 font-medium">
                                        {formatShortCurrency(Math.round(maxGross * 1 / 3))}
                                    </text>

                                    {/* Line 4: Baseline ₱0 */}
                                    <text x="76" y="254" textAnchor="end" className="font-mono text-[10px] fill-slate-500 font-medium">
                                        ₱0
                                    </text>

                                    {/* X-Axis Baseline Horizontal Line */}
                                    <line 
                                        x1="85" 
                                        y1="250" 
                                        x2="755" 
                                        y2="250" 
                                        stroke={isLight ? "rgba(0,0,0,0.22)" : "rgba(255,255,255,0.22)"} 
                                        strokeWidth="1.5" 
                                    />

                                    {/* X-Axis Graduation Ticks and Numbers */}
                                    {[0, 50, 100, 150, 200, 250, 300].map((val) => {
                                        const tickX = Math.round(85 + (val / 300) * 670);
                                        return (
                                            <g key={val}>
                                                <line 
                                                    x1={tickX} 
                                                    y1="250" 
                                                    x2={tickX} 
                                                    y2="256" 
                                                    stroke={isLight ? "rgba(0,0,0,0.25)" : "rgba(255,255,255,0.25)"} 
                                                    strokeWidth="1.5" 
                                                />
                                                <text 
                                                    x={tickX} 
                                                    y="270" 
                                                    textAnchor="middle" 
                                                    className="font-mono text-[10px] fill-slate-500 font-medium"
                                                >
                                                    {val}
                                                </text>
                                            </g>
                                        );
                                    })}

                                    {/* X-Axis Label */}
                                    <text 
                                        x="420" 
                                        y="298" 
                                        textAnchor="middle" 
                                        className="font-mono text-[10px] font-bold tracking-wider fill-slate-500 uppercase"
                                    >
                                        X AXIS: MONTHLY ORDERS / UNITS SOLD (@ ₱500/ITEM)
                                    </text>

                                    {/* 100% Gross COD Line (Muted Full Track) */}
                                    <line
                                        x1="85"
                                        y1="250"
                                        x2="755"
                                        y2="45"
                                        stroke={isLight ? "rgba(15,23,42,0.18)" : "rgba(255,255,255,0.2)"}
                                        strokeWidth="2"
                                        strokeDasharray="4 4"
                                    />
                                    <text 
                                        x="755" 
                                        y="38" 
                                        textAnchor="end" 
                                        className="font-mono text-[9px] fill-slate-400 font-medium"
                                    >
                                        100% Gross COD ({formatShortCurrency(maxGross)})
                                    </text>

                                    {/* 90% Net Take-Home Line (Muted Full Track) */}
                                    <line
                                        x1="85"
                                        y1="250"
                                        x2="755"
                                        y2="65"
                                        stroke={isLight ? "rgba(224,13,66,0.18)" : "rgba(224,13,66,0.25)"}
                                        strokeWidth="2.5"
                                    />
                                    <text 
                                        x="755" 
                                        y="80" 
                                        textAnchor="end" 
                                        className="font-mono text-[9px] fill-[#E00D42] font-bold"
                                    >
                                        90% Net Payout ({formatShortCurrency(maxGross * 0.9)})
                                    </text>

                                    {/* Milestone Reference Dots along 90% Net Line */}
                                    {[50, 100, 150, 200, 250].map((m) => {
                                        const mx = Math.round(85 + (m / 300) * 670);
                                        const my = Math.round(250 - ((m * itemPrice * 0.9) / maxGross) * 205);
                                        return (
                                            <circle
                                                key={m}
                                                cx={mx}
                                                cy={my}
                                                r="3"
                                                fill={isLight ? "#FFFFFF" : "#0C0D0E"}
                                                stroke="#E00D42"
                                                strokeWidth="1.5"
                                                opacity="0.7"
                                            />
                                        );
                                    })}

                                    {/* Active Illuminated Segment (Clipped to curX) */}
                                    <g clipPath="url(#graphProgressClip)">
                                        {/* Area Fill beneath 90% Net line */}
                                        <polygon
                                            points={`85,250 85,250 755,65 755,250`}
                                            fill="url(#activeAreaGlow)"
                                        />
                                        {/* Glowing 90% Net Stroke */}
                                        <line
                                            x1="85"
                                            y1="250"
                                            x2="755"
                                            y2="65"
                                            stroke="url(#activeStrokeGrad)"
                                            strokeWidth="3.5"
                                            strokeLinecap="round"
                                        />
                                        {/* Highlighted 100% Gross Stroke */}
                                        <line
                                            x1="85"
                                            y1="250"
                                            x2="755"
                                            y2="45"
                                            stroke={isLight ? "rgba(15,23,42,0.55)" : "rgba(255,255,255,0.65)"}
                                            strokeWidth="2"
                                            strokeDasharray="4 4"
                                            strokeLinecap="round"
                                        />
                                    </g>

                                    {/* Vertical Scrubber Guide Line */}
                                    <line
                                        x1={curX}
                                        y1="35"
                                        x2={curX}
                                        y2="250"
                                        stroke={isLight ? "rgba(0,0,0,0.22)" : "rgba(255,255,255,0.3)"}
                                        strokeWidth="1.5"
                                        strokeDasharray="3 3"
                                    />

                                    {/* Cursor Indicator Dots */}
                                    <circle
                                        cx={curX}
                                        cy={curYGross}
                                        r="4"
                                        fill={isLight ? "#FFFFFF" : "#0A0D14"}
                                        stroke={isLight ? "rgba(15,23,42,0.8)" : "rgba(255,255,255,0.9)"}
                                        strokeWidth="2"
                                    />
                                    <circle
                                        cx={curX}
                                        cy={curYNet}
                                        r="12"
                                        fill="#E00D42"
                                        opacity="0.25"
                                    />
                                    <circle
                                        cx={curX}
                                        cy={curYNet}
                                        r="6"
                                        fill="#E00D42"
                                        stroke="#FFFFFF"
                                        strokeWidth="2.5"
                                    />

                                    {/* Dynamic Cursor Tooltip Badge */}
                                    {(() => {
                                        const tooltipX = Math.max(130, Math.min(670, curX));
                                        const tooltipY = Math.max(30, curYNet - 38);
                                        return (
                                            <g transform={`translate(${tooltipX}, ${tooltipY})`} className="pointer-events-none">
                                                <rect
                                                    x="-105"
                                                    y="-22"
                                                    width="210"
                                                    height="40"
                                                    rx="8"
                                                    fill={isLight ? "#0F172A" : "#18181B"}
                                                    stroke={isLight ? "rgba(0,0,0,0.12)" : "rgba(255,255,255,0.2)"}
                                                    strokeWidth="1"
                                                    filter="drop-shadow(0 4px 8px rgba(0,0,0,0.25))"
                                                />
                                                <text
                                                    x="0"
                                                    y="-6"
                                                    textAnchor="middle"
                                                    fill="#FFFFFF"
                                                    className="font-mono text-[10px] font-bold"
                                                >
                                                    {activeOrders} units @ ₱{itemPrice} = {formatCurrency(activeNet)}
                                                </text>
                                                <text
                                                    x="0"
                                                    y="10"
                                                    textAnchor="middle"
                                                    fill="#94A3B8"
                                                    className="font-mono text-[9px]"
                                                >
                                                    Gross: {formatCurrency(activeGross)} • 10% fee: -{formatCurrency(activePlatformFee)}
                                                </text>
                                            </g>
                                        );
                                    })()}
                                </svg>
                            </div>

                            {/* Bottom Scrubber Instruction Hint */}
                            <div className={`flex items-center justify-between text-[11px] font-mono pt-1 ${
                                isLight ? 'text-slate-400' : 'text-white/30'
                            }`}>
                                <span>Hover or drag across graph to simulate sales volume</span>
                                <span className="text-[#E00D42] font-semibold">{activeOrders} / 300 units</span>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 5. PANEL FIG 0.2: THE 3-BEAT EXECUTION ARC (CASCADING SEQUENTIAL DROP ANIMATION) */}
                <section id="workflow" ref={workflowRef} className={`max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t ${
                    isLight ? 'border-slate-200' : 'border-white/[0.06]'
                }`}>
                    <div className="space-y-12">
                        
                        <div 
                            className={`transition-all duration-700 ease-out transform ${
                                workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block font-semibold">
                                    FIG 0.2 — FAST ONBOARDING & EXECUTION
                                </span>
                                <span className={isLight ? 'text-slate-400' : 'text-white/20'}>•</span>
                                <span className={`font-mono text-[11px] uppercase tracking-wider font-semibold ${
                                    isLight ? 'text-emerald-700' : 'text-emerald-400'
                                }`}>
                                    1 Valid ID • 1-Click Admin Approval
                                </span>
                            </div>
                            <h2 className={`text-3xl sm:text-4xl font-extrabold tracking-tight mt-1 ${
                                isLight ? 'text-slate-900' : 'text-white'
                            }`}>
                                From link to cash in three beats.
                            </h2>
                        </div>

                        {/* Cascading 3 Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 font-mono">
                            
                            {/* Step 01 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-100 ease-out transform ${
                                    isLight 
                                        ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                        : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                                } ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <div className="flex items-center justify-between">
                                    <span className={`text-4xl font-extrabold block ${
                                        isLight ? 'text-slate-300' : 'text-white/20'
                                    }`}>01</span>
                                    <span className={`px-2 py-0.5 rounded border text-[10px] font-mono font-bold ${
                                        isLight 
                                            ? 'bg-emerald-50 border-emerald-200 text-emerald-700' 
                                            : 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400'
                                    }`}>
                                        1 VALID ID ONLY
                                    </span>
                                </div>
                                <h3 className={`text-base font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>Upload 1 Valid ID</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Upload 1 valid ID (Student or Govt ID) and pickup address. 1-click admin approval with zero DTI paperwork.
                                </p>
                            </div>

                            {/* Step 02 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-300 ease-out transform ${
                                    isLight 
                                        ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                        : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                                } ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <span className={`text-4xl font-extrabold block ${
                                    isLight ? 'text-slate-300' : 'text-white/20'
                                }`}>02</span>
                                <h3 className={`text-base font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>Handover to Rider</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Print 1-click thermal waybill. Couriers collect directly from your door.
                                </p>
                            </div>

                            {/* Step 03 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-500 ease-out transform ${
                                    isLight 
                                        ? 'bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md' 
                                        : 'bg-[#0C0D0E] border-white/[0.08] hover:border-white/20'
                                } ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <span className={`text-4xl font-extrabold block ${
                                    isLight ? 'text-emerald-600/40' : 'text-emerald-400/40'
                                }`}>03</span>
                                <h3 className={`text-base font-bold ${isLight ? 'text-slate-900' : 'text-white'}`}>Collect Cash</h3>
                                <p className={`text-xs font-sans leading-relaxed ${isLight ? 'text-slate-600' : 'text-white/50'}`}>
                                    Courier delivers parcel, collects COD, and remits 90% direct to your ledger.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 6. MINIMALIST FAQ ACCORDION (RIPPLE FADE-IN ANIMATION) */}
                <section id="faq" ref={faqRef} className={`max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t w-full ${
                    isLight ? 'border-slate-200' : 'border-white/[0.06]'
                }`}>
                    <div className="space-y-8">
                        
                        <div 
                            className={`text-center space-y-2 transition-all duration-700 ease-out transform ${
                                faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-6'
                            }`}
                        >
                            <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest">
                                FIG 0.3 — INQUIRIES
                            </span>
                            <h2 className={`text-3xl font-extrabold tracking-tight ${
                                isLight ? 'text-slate-900' : 'text-white'
                            }`}>
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
                                        className={`border rounded-xl overflow-hidden transition-all duration-700 ease-out transform ${
                                            isLight 
                                                ? 'bg-white border-slate-200 shadow-sm' 
                                                : 'bg-[#0C0D0E] border-white/[0.08]'
                                        } ${faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'} ${delays[index]}`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setOpenFaq(isOpen ? null : index)}
                                            className="w-full p-5 text-left flex items-center justify-between gap-4 focus:outline-hidden"
                                        >
                                            <span className={`font-bold text-sm sm:text-base ${
                                                isLight ? 'text-slate-900' : 'text-white/90'
                                            }`}>
                                                {faq.q}
                                            </span>
                                            {isOpen ? (
                                                <ChevronUp className={`w-4 h-4 shrink-0 ${isLight ? 'text-slate-600' : 'text-white/40'}`} />
                                            ) : (
                                                <ChevronDown className={`w-4 h-4 shrink-0 ${isLight ? 'text-slate-600' : 'text-white/40'}`} />
                                            )}
                                        </button>
                                        {isOpen && (
                                            <div className={`px-5 pb-5 text-xs sm:text-sm leading-relaxed border-t pt-3 ${
                                                isLight 
                                                    ? 'text-slate-700 border-slate-100' 
                                                    : 'text-white/50 border-white/[0.04]'
                                            }`}>
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
                <section id="closing" ref={closingRef} className={`py-28 border-t relative overflow-hidden text-center px-4 ${
                    isLight ? 'border-slate-200' : 'border-white/[0.06]'
                }`}>
                    {/* Radial Ambient Glow (Expands on Scroll) */}
                    <div 
                        className={`absolute inset-0 pointer-events-none transition-all duration-1000 ease-out transform ${
                            closingInView ? 'opacity-25 scale-100' : 'opacity-0 scale-75'
                        }`}
                        style={{
                            background: isLight 
                                ? 'radial-gradient(circle at 50% 50%, rgba(224, 13, 66, 0.15) 0%, transparent 65%)' 
                                : 'radial-gradient(circle at 50% 50%, rgba(224, 13, 66, 0.3) 0%, transparent 60%)'
                        }}
                    />

                    <div 
                        className={`max-w-xl mx-auto space-y-6 relative z-10 transition-all duration-700 delay-150 ease-out transform ${
                            closingInView ? 'opacity-100 scale-100' : 'opacity-0 scale-95'
                        }`}
                    >
                        <h2 className={`text-4xl sm:text-5xl font-extrabold tracking-[-0.03em] ${
                            isLight ? 'text-slate-900' : 'text-white'
                        }`}>
                            Build your brand. <br />
                            <span className={isLight ? 'text-slate-600' : 'text-white/40'}>Get paid in cash.</span>
                        </h2>

                        <p className={`text-sm max-w-sm mx-auto font-sans ${
                            isLight ? 'text-slate-700 font-normal' : 'text-white/60'
                        }`}>
                            Start listing your catalog today on BagooPH with 10% flat commission and reliable doorstep fulfillment.
                        </p>

                        <div className="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className={`w-full sm:w-auto px-8 py-3.5 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition active:scale-95 ${
                                    isLight
                                        ? 'bg-[#E00D42] hover:bg-[#C00A38] text-white shadow-lg shadow-rose-500/20'
                                        : 'bg-white hover:bg-slate-200 text-black'
                                }`}
                            >
                                Open Store Free
                            </a>
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className={`w-full sm:w-auto px-7 py-3.5 rounded-full font-mono text-xs uppercase tracking-wider transition ${
                                    isLight
                                        ? 'bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-900 font-semibold'
                                        : 'bg-white/[0.04] hover:bg-white/[0.08] border border-white/10 text-white'
                                }`}
                            >
                                Merchant Sign In
                            </a>
                        </div>
                    </div>
                </section>

                {/* 8. MONOCHROME STUDIO FOOTER */}
                <footer className={`border-t py-10 px-4 sm:px-6 lg:px-8 font-mono text-xs ${
                    isLight 
                        ? 'bg-slate-100 border-slate-200 text-slate-600' 
                        : 'bg-[#050607] border-white/[0.06] text-white/40'
                }`}>
                    <div className="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                        <div className="flex items-center gap-2.5">
                            <BagooLogo className="w-6 h-6" rounded="rounded-md" />
                            <span className={`font-bold tracking-tight ${isLight ? 'text-slate-900' : 'text-white'}`}>
                                Bagoo<span className="text-[#E00D42]">PH</span>
                            </span>
                            <span>•</span>
                            <span>Seller Operations</span>
                        </div>

                        <div className="flex flex-wrap items-center gap-6">
                            <a href={getDomainUrl('buyer', '/')} className={`transition ${isLight ? 'text-slate-600 hover:text-slate-900 font-medium' : 'hover:text-white'}`}>Marketplace</a>
                            <a href={getDomainUrl('courier', '/')} className={`transition ${isLight ? 'text-slate-600 hover:text-slate-900 font-medium' : 'hover:text-white'}`}>Courier</a>
                            <a href={getDomainUrl('admin', '/')} className={`transition ${isLight ? 'text-slate-600 hover:text-slate-900 font-medium' : 'hover:text-white'}`}>Admin</a>
                            <span>&copy; {new Date().getFullYear()}</span>
                        </div>
                    </div>
                </footer>

            </div>
        </div>
    );
}
