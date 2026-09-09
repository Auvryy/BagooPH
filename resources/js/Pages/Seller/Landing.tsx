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

    // Interactive Economics Spline Graph State (Linear-Style Cursor Tracking)
    const [graphProgress, setGraphProgress] = useState(0.62); // Initial resting point (approx 62% along the curve)
    const [isGraphHovered, setIsGraphHovered] = useState(false);

    // Cubic Bezier interpolation: B(t) for P0, P1, P2, P3
    const getBezierPoint = (t: number, p0: number, p1: number, p2: number, p3: number) => {
        const oneMinusT = 1 - t;
        return (
            Math.pow(oneMinusT, 3) * p0 +
            3 * Math.pow(oneMinusT, 2) * t * p1 +
            3 * oneMinusT * Math.pow(t, 2) * p2 +
            Math.pow(t, 3) * p3
        );
    };

    // Active calculations based on dynamic graph scrubber position
    const activeOrders = Math.round(15 + graphProgress * 285); // 15 to 300 orders
    const activeItemPrice = 500;
    const activeGross = activeOrders * activeItemPrice;
    const activePlatformFee = Math.round(activeGross * 0.10); // Official 10% Flat Platform Commission
    const activeNet = activeGross - activePlatformFee; // Sellers retain 90%

    // SVG Coordinate Points at t = graphProgress (viewBox 0 0 800 280)
    // Gross COD curve: P0(40,220), P1(260,210), P2(500,110), P3(760,35)
    // 90% Net Take-home curve: P0(40,232), P1(260,224), P2(500,135), P3(760,62)
    const curX = Math.round(getBezierPoint(graphProgress, 40, 260, 500, 760));
    const curYGross = Math.round(getBezierPoint(graphProgress, 220, 210, 110, 35));
    const curYNet = Math.round(getBezierPoint(graphProgress, 232, 224, 135, 62));

    // Handle interactive mouse / touch scrub across graph
    const handleGraphScrub = (clientX: number, rect: DOMRect) => {
        const x = Math.max(0, Math.min(rect.width, clientX - rect.left));
        const pct = Math.max(0.04, Math.min(0.96, x / rect.width));
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
                <section className="pt-16 pb-12 sm:pt-24 sm:pb-16 px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto text-center space-y-8">
                    
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

                    {/* Fast Onboarding Reassurance Row */}
                    <div 
                        className={`inline-flex flex-wrap items-center justify-center gap-2.5 font-mono text-[11px] text-white/45 pt-1 transition-all duration-700 delay-500 ease-out transform ${
                            heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'
                        }`}
                    >
                        <span className="text-emerald-400 font-semibold">1 Valid ID Only</span>
                        <span className="text-white/20">•</span>
                        <span>1-Click Admin Approval</span>
                        <span className="text-white/20">•</span>
                        <span>Zero DTI / BIR Hurdles</span>
                    </div>

                </section>

                {/* 3. SECTION 2: PEEKING DESKTOP MERCHANT COCKPIT & CORE CAPABILITIES */}
                <section id="showcase" ref={showcaseRef} className="relative pb-24 pt-2 -mt-4 sm:-mt-8 lg:-mt-12">
                    
                    {/* Ambient Radial Underglow behind Desktop Window */}
                    <div className="pointer-events-none absolute left-1/2 -top-12 -translate-x-1/2 w-full max-w-5xl h-72 bg-gradient-to-b from-[#E00D42]/15 via-red-950/10 to-transparent blur-3xl -z-10" />

                    {/* 2.5D Perspective Container */}
                    <div className="max-w-5xl mx-auto px-3 sm:px-6 lg:px-8 [perspective:1200px] [perspective-origin:center_top]">

                        {/* Desktop Application Chassis Window Frame */}
                        <div 
                            className={`relative rounded-2xl p-[1px] bg-gradient-to-b from-white/20 via-white/8 to-white/0 shadow-[0_30px_70px_-15px_rgba(0,0,0,0.95),0_0_0_1px_rgba(255,255,255,0.06)] transition-all duration-1000 ease-out transform [transform-style:preserve-3d] ${
                                showcaseInView 
                                    ? 'opacity-100 [transform:rotateX(0deg)_scale(1)]' 
                                    : 'opacity-90 [transform:rotateX(12deg)_scale(0.97)]'
                            }`}
                        >
                            {/* Inner Chassis Container */}
                            <div className="relative overflow-hidden rounded-[15px] bg-[#0A0D14] shadow-[inset_0_1px_0_0_rgba(255,255,255,0.12)]">
                                
                                {/* 1. macOS / Studio Title Bar */}
                                <div className="flex h-10 sm:h-11 items-center justify-between border-b border-white/[0.08] bg-[#0E1118]/90 px-4 backdrop-blur-md select-none">
                                    {/* Traffic Light Dots */}
                                    <div className="flex items-center gap-2">
                                        <span className="h-2.5 w-2.5 rounded-full bg-[#FF5F56] border border-[#E0443E]/40" />
                                        <span className="h-2.5 w-2.5 rounded-full bg-[#FFBD2E] border border-[#DEA123]/40" />
                                        <span className="h-2.5 w-2.5 rounded-full bg-[#27C93F] border border-[#1AAB29]/40" />
                                    </div>

                                    {/* Center Environment Metadata Pill */}
                                    <div className="flex items-center gap-2 rounded-full border border-white/[0.08] bg-white/[0.03] px-3.5 py-1 text-xs text-slate-300 font-mono">
                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 shadow-[0_0_6px_#34D399] animate-pulse" />
                                        <span>bagoo.shop/seller/cockpit</span>
                                    </div>

                                    {/* Right Utility Pills */}
                                    <div className="flex items-center gap-2 font-mono text-[11px]">
                                        <span className="hidden sm:inline-flex px-2 py-0.5 rounded bg-[#E00D42]/20 border border-[#E00D42]/40 text-[#E00D42] font-semibold">
                                            10% FLAT COMMISSION
                                        </span>
                                        <span className="px-2 py-0.5 rounded bg-white/[0.04] border border-white/10 text-white/50">
                                            MNL-01 LIVE
                                        </span>
                                    </div>
                                </div>

                                {/* 2. Sleek Minimalist Cockpit Surface */}
                                <div className="p-6 sm:p-8 space-y-6 bg-[#07090E]">
                                    
                                    {/* Top Minimal Metrics Bar */}
                                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-white/[0.06]">
                                        <div>
                                            <span className="font-mono text-[10px] text-white/40 uppercase tracking-widest block">
                                                NET CASH REMITTANCE (90% TAKE-HOME)
                                            </span>
                                            <div className="flex items-baseline gap-3 mt-1">
                                                <span className="text-3xl sm:text-4xl font-extrabold text-white tracking-tight font-mono">
                                                    ₱44,028.00
                                                </span>
                                                <span className="text-xs font-mono text-emerald-400 font-semibold px-2 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20">
                                                    +16.4% COD Growth
                                                </span>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-3 font-mono text-xs">
                                            <div className="px-3 py-1.5 rounded-lg bg-white/[0.03] border border-white/[0.08] text-white/70 flex items-center gap-2">
                                                <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse" />
                                                <span>6 Parcels Awaiting Rider</span>
                                            </div>
                                            <div className="px-3.5 py-1.5 rounded-lg bg-[#E00D42] text-white font-bold flex items-center gap-1.5 shadow-sm">
                                                <Printer className="w-3.5 h-3.5" />
                                                <span>Print Waybills</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Sleek Visual Pipeline Cards (3 High-Impact Order Snapshots) */}
                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 font-mono">
                                        
                                        {/* Card 1: Ready for Pickup */}
                                        <div className="p-4 rounded-xl bg-[#0C0D0E] border border-white/[0.08] space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-white font-bold">BGO-4821</span>
                                                <span className="px-2 py-0.5 rounded-full bg-amber-500/10 text-amber-300 border border-amber-500/20 text-[10px] flex items-center gap-1">
                                                    <span className="w-1 h-1 rounded-full bg-amber-400 animate-pulse" />
                                                    Rider Assigned
                                                </span>
                                            </div>
                                            <div>
                                                <p className="text-sm font-sans font-medium text-white truncate">Heavyweight Drop Tee (L)</p>
                                                <p className="text-[11px] text-white/40 font-sans">Quezon City • Doorstep COD</p>
                                            </div>
                                            <div className="pt-2 border-t border-white/[0.06] flex items-center justify-between text-xs">
                                                <span className="text-white/40">Gross COD</span>
                                                <span className="text-white font-bold">₱1,250.00</span>
                                            </div>
                                        </div>

                                        {/* Card 2: Out for Delivery */}
                                        <div className="p-4 rounded-xl bg-[#0C0D0E] border border-white/[0.08] space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-white font-bold">BGO-4822</span>
                                                <span className="px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-300 border border-blue-500/20 text-[10px] flex items-center gap-1">
                                                    <span className="w-1 h-1 rounded-full bg-blue-400" />
                                                    Out for Delivery
                                                </span>
                                            </div>
                                            <div>
                                                <p className="text-sm font-sans font-medium text-white truncate">Ceramic Pour-over Mug</p>
                                                <p className="text-[11px] text-white/40 font-sans">Cebu City • Doorstep COD</p>
                                            </div>
                                            <div className="pt-2 border-t border-white/[0.06] flex items-center justify-between text-xs">
                                                <span className="text-white/40">Gross COD</span>
                                                <span className="text-white font-bold">₱890.00</span>
                                            </div>
                                        </div>

                                        {/* Card 3: Delivered & Remitted */}
                                        <div className="p-4 rounded-xl bg-[#0C0D0E] border border-white/[0.08] space-y-3">
                                            <div className="flex items-center justify-between">
                                                <span className="text-xs text-white font-bold">BGO-4824</span>
                                                <span className="px-2 py-0.5 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] flex items-center gap-1">
                                                    <span className="w-1 h-1 rounded-full bg-emerald-400" />
                                                    Cash Remitted
                                                </span>
                                            </div>
                                            <div>
                                                <p className="text-sm font-sans font-medium text-white truncate">Canvas Messenger Bag</p>
                                                <p className="text-[11px] text-white/40 font-sans">Manila • 10% Fee Settled</p>
                                            </div>
                                            <div className="pt-2 border-t border-white/[0.06] flex items-center justify-between text-xs">
                                                <span className="text-white/40">Net to Ledger</span>
                                                <span className="text-emerald-400 font-bold">₱1,890.00</span>
                                            </div>
                                        </div>

                                    </div>

                                </div>
                            </div>
                        </div>

                        {/* 3. Feature Explanations Bento Grid Directly Connected Below Mockup */}
                        <div className="mt-14 grid grid-cols-1 md:grid-cols-3 gap-6 font-mono">
                            
                            <div className="p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-2.5 hover:border-white/20 transition">
                                <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400 font-bold text-xs">
                                    01
                                </div>
                                <h3 className="text-base font-bold text-white pt-1">Thermal Waybills</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Print standard A6 barcodes in one click. Zero manual handwriting.
                                </p>
                            </div>

                            <div className="p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-2.5 hover:border-white/20 transition">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 border border-[#E00D42]/20 flex items-center justify-center text-[#E00D42] font-bold text-xs">
                                    02
                                </div>
                                <h3 className="text-base font-bold text-white pt-1">Direct COD Ledger</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Cash collected at doorstep. 90% remitted straight to your balance.
                                </p>
                            </div>

                            <div className="p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-2.5 hover:border-white/20 transition">
                                <div className="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-400 font-bold text-xs">
                                    03
                                </div>
                                <h3 className="text-base font-bold text-white pt-1">Doorstep Pickup</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Dedicated riders collect from your door. Never queue at branches.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 4. PANEL FIG 0.1: INTERACTIVE SPLINE GRAPH (LINEAR-STYLE CURSOR TRACKING & 90% RETENTION) */}
                <section id="economics" ref={economicsRef} className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-white/[0.06]">
                    <div className="space-y-10">
                        
                        <div 
                            className={`flex flex-col sm:flex-row sm:items-end justify-between gap-4 transition-all duration-700 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <div>
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block font-semibold">
                                    FIG 0.1 — TRANSPARENT ECONOMICS
                                </span>
                                <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white mt-1">
                                    Keep 90% of your gross sales.
                                </h2>
                            </div>
                            <div className="font-mono text-xs text-white/40">
                                10% Flat Fee • Zero Hidden Deductions
                            </div>
                        </div>

                        {/* Linear-Style Interactive Graph Card Container */}
                        <div 
                            className={`bg-[#0C0D0E] border border-white/[0.08] rounded-2xl p-6 sm:p-8 space-y-6 shadow-2xl relative overflow-hidden transition-all duration-1000 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                            }`}
                        >
                            {/* Top Telemetry HUD Strip — Minimized to 1 Focal Metric */}
                            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-6 border-b border-white/[0.08]">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2 font-mono text-[11px] text-white/40 uppercase tracking-wider">
                                        <span className="w-1.5 h-1.5 rounded-full bg-[#E00D42]" />
                                        <span>Estimated Payout for {activeOrders} Orders</span>
                                    </div>
                                    <div className="flex items-baseline gap-3">
                                        <span className="text-4xl sm:text-5xl font-extrabold text-white tracking-tight font-mono">
                                            {formatCurrency(activeNet)}
                                        </span>
                                        <span className="text-xs font-mono font-semibold px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                                            90% Net Take-Home
                                        </span>
                                    </div>
                                    <div className="font-mono text-xs text-white/40 pt-0.5">
                                        {formatCurrency(activeGross)} Gross COD <span className="text-white/20">•</span> 10% Platform Fee (-{formatCurrency(activePlatformFee)})
                                    </div>
                                </div>
                            </div>

                            {/* Interactive SVG Spline Canvas */}
                            <div className="relative select-none">
                                
                                <svg
                                    viewBox="0 0 800 280"
                                    className="w-full h-56 sm:h-72 cursor-crosshair overflow-visible"
                                    onMouseMove={(e) => handleGraphScrub(e.clientX, e.currentTarget.getBoundingClientRect())}
                                    onMouseLeave={() => setIsGraphHovered(false)}
                                    onTouchMove={(e) => {
                                        if (e.touches[0]) handleGraphScrub(e.touches[0].clientX, e.currentTarget.getBoundingClientRect());
                                    }}
                                    onTouchEnd={() => setIsGraphHovered(false)}
                                >
                                    <defs>
                                        {/* Area glow gradient for 90% curve */}
                                        <linearGradient id="activeAreaGlow" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stopColor="#E00D42" stopOpacity="0.32" />
                                            <stop offset="60%" stopColor="#E00D42" stopOpacity="0.08" />
                                            <stop offset="100%" stopColor="#E00D42" stopOpacity="0" />
                                        </linearGradient>

                                        {/* Stroke gradient for illuminated curve */}
                                        <linearGradient id="activeStrokeGrad" x1="0" y1="0" x2="1" y2="0">
                                            <stop offset="0%" stopColor="#FF6B8B" />
                                            <stop offset="100%" stopColor="#E00D42" />
                                        </linearGradient>

                                        {/* Clip path revealing illuminated curve strictly up to curX */}
                                        <clipPath id="graphProgressClip">
                                            <rect x="0" y="0" width={curX} height="280" />
                                        </clipPath>
                                    </defs>

                                    {/* Horizontal Guidelines */}
                                    <line x1="40" y1="60" x2="760" y2="60" stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />
                                    <line x1="40" y1="135" x2="760" y2="135" stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />
                                    <line x1="40" y1="210" x2="760" y2="210" stroke="rgba(255,255,255,0.05)" strokeDasharray="3 3" />

                                    {/* Muted Background Tracks (Full width) */}
                                    <path
                                        d="M 40,220 C 260,210 500,110 760,35"
                                        fill="none"
                                        stroke="rgba(255,255,255,0.18)"
                                        strokeWidth="2"
                                        strokeDasharray="4 4"
                                    />
                                    <path
                                        d="M 40,232 C 260,224 500,135 760,62"
                                        fill="none"
                                        stroke="rgba(255,255,255,0.12)"
                                        strokeWidth="2.5"
                                    />

                                    {/* Illuminated Active Segment (Clipped to curX) */}
                                    <g clipPath="url(#graphProgressClip)">
                                        <path
                                            d="M 40,232 C 260,224 500,135 760,62 L 760,270 L 40,270 Z"
                                            fill="url(#activeAreaGlow)"
                                        />
                                        <path
                                            d="M 40,232 C 260,224 500,135 760,62"
                                            fill="none"
                                            stroke="url(#activeStrokeGrad)"
                                            strokeWidth="3.5"
                                            strokeLinecap="round"
                                        />
                                        <path
                                            d="M 40,220 C 260,210 500,110 760,35"
                                            fill="none"
                                            stroke="rgba(255,255,255,0.55)"
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                        />
                                    </g>

                                    {/* Vertical Scrubber Guide Line */}
                                    <line
                                        x1={curX}
                                        y1="20"
                                        x2={curX}
                                        y2="265"
                                        stroke="rgba(255,255,255,0.25)"
                                        strokeWidth="1.5"
                                        strokeDasharray="3 3"
                                    />

                                    {/* Cursor Indicator Dots */}
                                    <circle
                                        cx={curX}
                                        cy={curYGross}
                                        r="4"
                                        fill="#0A0D14"
                                        stroke="rgba(255,255,255,0.9)"
                                        strokeWidth="2"
                                    />
                                    <circle
                                        cx={curX}
                                        cy={curYNet}
                                        r="12"
                                        fill="#E00D42"
                                        opacity="0.3"
                                    />
                                    <circle
                                        cx={curX}
                                        cy={curYNet}
                                        r="6"
                                        fill="#E00D42"
                                        stroke="#FFFFFF"
                                        strokeWidth="2.5"
                                    />
                                </svg>

                                {/* Clean Legend — Zero Bottom Random Words */}
                                <div className="flex items-center gap-6 pt-3 border-t border-white/[0.06] font-mono text-[11px] text-white/50">
                                    <div className="flex items-center gap-2">
                                        <span className="w-2.5 h-2.5 rounded-full bg-[#E00D42]" />
                                        <span className="text-white font-medium">90% Net Cash Remittance</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="w-2 h-0.5 bg-white/40" />
                                        <span>Gross COD Volume</span>
                                    </div>
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
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block font-semibold">
                                    FIG 0.2 — FAST ONBOARDING & EXECUTION
                                </span>
                                <span className="text-white/20">•</span>
                                <span className="font-mono text-[11px] text-emerald-400 uppercase tracking-wider font-semibold">
                                    1 Valid ID • 1-Click Admin Approval
                                </span>
                            </div>
                            <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight text-white mt-1">
                                From link to cash in three beats.
                            </h2>
                        </div>

                        {/* Cascading 3 Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 font-mono">
                            
                            {/* Step 01 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-3 hover:border-white/20 transition-all duration-700 delay-100 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <span className="text-4xl font-extrabold text-white/20 block">01</span>
                                    <span className="px-2 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20 text-[10px] text-emerald-400 font-mono font-bold">
                                        1 VALID ID ONLY
                                    </span>
                                </div>
                                <h3 className="text-base font-bold text-white">Upload 1 Valid ID</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Upload 1 valid ID (Student or Govt ID) and pickup address. 1-click admin approval with zero DTI paperwork.
                                </p>
                            </div>

                            {/* Step 02 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-3 hover:border-white/20 transition-all duration-700 delay-300 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <span className="text-4xl font-extrabold text-white/20 block">02</span>
                                <h3 className="text-base font-bold text-white">Handover to Rider</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Print 1-click thermal waybill. Couriers collect directly from your door.
                                </p>
                            </div>

                            {/* Step 03 */}
                            <div 
                                className={`p-6 rounded-2xl bg-[#0C0D0E] border border-white/[0.08] space-y-3 hover:border-white/20 transition-all duration-700 delay-500 ease-out transform ${
                                    workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                                }`}
                            >
                                <span className="text-4xl font-extrabold text-emerald-400/40 block">03</span>
                                <h3 className="text-base font-bold text-white">Collect Cash</h3>
                                <p className="text-xs text-white/50 font-sans leading-relaxed">
                                    Courier delivers parcel, collects COD, and remits 90% direct to your ledger.
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
