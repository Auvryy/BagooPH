import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';
import { 
    ArrowRight, 
    ChevronDown, 
    ChevronUp
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

    // Section 3 Product Carousel State & Unique Local Merchant Products (Auto-swaps every 1.6s)
    const CAROUSEL_PRODUCTS = [
        {
            id: 'leather-bag',
            name: 'Handcrafted Artisan Leather Bag',
            category: 'Leathercraft',
            price: '₱2,450',
            payout: '₱2,205 (90%)',
            image: '/images/carousel/leather-bag.jpg',
        },
        {
            id: 'craft-coffee',
            name: 'Cordillera Single-Origin Roast',
            category: 'Specialty Coffee',
            price: '₱580',
            payout: '₱522 (90%)',
            image: '/images/carousel/craft-coffee.jpg',
        },
        {
            id: 'ceramic-mug',
            name: 'Speckled Stoneware Ceramic Mug',
            category: 'Ceramics & Studio',
            price: '₱650',
            payout: '₱585 (90%)',
            image: '/images/carousel/ceramic-mug.jpg',
        },
        {
            id: 'crossbody-sling',
            name: 'Technical Everyday Carry Sling',
            category: 'Bags & EDC',
            price: '₱1,450',
            payout: '₱1,305 (90%)',
            image: '/images/carousel/crossbody-sling.jpg',
        },
        {
            id: 'botanical-candle',
            name: 'Botanical Soy Wax Amber Candle',
            category: 'Apothecary',
            price: '₱480',
            payout: '₱432 (90%)',
            image: '/images/carousel/botanical-candle.jpg',
        },
        {
            id: 'streetwear-tee',
            name: 'Heavyweight Washed Boxy Tee',
            category: 'Streetwear',
            price: '₱850',
            payout: '₱765 (90%)',
            image: '/images/carousel/streetwear-tee.jpg',
        },
    ];

    const [activeCarouselIdx, setActiveCarouselIdx] = useState(0);

    // Auto-swap every 1.6 seconds as requested
    useEffect(() => {
        const timer = setInterval(() => {
            setActiveCarouselIdx((prev) => (prev + 1) % CAROUSEL_PRODUCTS.length);
        }, 1600);
        return () => clearInterval(timer);
    }, [CAROUSEL_PRODUCTS.length]);

    // FAQ Accordion State
    const [openFaq, setOpenFaq] = useState<number | null>(0);

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
        <div className="min-h-screen bg-[#FAFAFA] text-[#0F172A] font-sans selection:bg-[#E00D42] selection:text-white relative overflow-hidden">
            <Head title="BagooPH — Seller Standard | Minimalist Cash on Delivery Commerce" />

            {/* Subtle Ambient Radial Lighting */}
            <div 
                className="fixed top-0 left-1/2 -translate-x-1/2 w-[1000px] h-[500px] pointer-events-none z-0 opacity-40"
                style={{
                    background: 'radial-gradient(circle at 50% 0%, rgba(224, 13, 66, 0.08) 0%, rgba(250, 250, 250, 0) 70%)'
                }}
            />

            {/* Subtle Geometric Wireframe Grid Lines */}
            <div 
                className="fixed inset-0 pointer-events-none z-0 opacity-[0.035]"
                style={{
                    backgroundImage: 'linear-gradient(to right, #000000 1px, transparent 1px), linear-gradient(to bottom, #000000 1px, transparent 1px)',
                    backgroundSize: '48px 48px'
                }}
            />

            <div className="relative z-10 flex flex-col min-h-screen">

                {/* 1. FLOATING MINIMALIST PILL NAVIGATION */}
                <div className="sticky top-5 z-50 max-w-5xl mx-auto px-4 w-full">
                    <header className="backdrop-blur-xl border rounded-full px-5 py-3 shadow-2xl flex items-center justify-between bg-white/85 border-slate-200 shadow-slate-200/50">
                        
                        {/* Brand Signature */}
                        <Link href="/" className="flex items-center gap-2.5 group">
                            <BagooLogo className="w-7 h-7 group-hover:scale-105 transition-transform duration-200" rounded="rounded-lg" />
                            <div className="flex items-center gap-1.5 font-mono text-sm tracking-tight">
                                <span className="font-bold text-slate-900">Bagoo</span>
                                <span className="text-[#E00D42] font-black">PH</span>
                                <span className="text-[10px] uppercase tracking-widest pl-1 font-semibold text-slate-500">
                                    SELLER
                                </span>
                            </div>
                        </Link>

                        {/* Minimal Navigation Anchors */}
                        <nav className="hidden md:flex items-center gap-7 font-mono text-xs tracking-wider uppercase text-slate-600 font-medium">
                            <a href="#showcase" className="hover:text-slate-900 transition-colors">Showcase</a>
                            <a href="#economics" className="hover:text-slate-900 transition-colors">Economics</a>
                            <a href="#workflow" className="hover:text-slate-900 transition-colors">Flow</a>
                            <a href="#faq" className="hover:text-slate-900 transition-colors">FAQ</a>
                        </nav>

                        {/* Discrete Actions */}
                        <div className="flex items-center gap-2.5">
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className="font-mono text-xs uppercase tracking-wider transition px-3 py-1.5 text-slate-700 hover:text-slate-900 font-medium"
                            >
                                Sign In
                            </a>
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="px-4 py-2 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition-all duration-200 shadow-sm active:scale-95 flex items-center gap-1.5 bg-[#0F172A] hover:bg-black text-white shadow-slate-300"
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
                        className={`inline-flex items-center gap-2 px-3 py-1 rounded-full border transition-all duration-700 ease-out transform bg-slate-100/90 border-slate-300/80 text-slate-800 font-medium ${heroLoaded ? 'opacity-100 scale-100' : 'opacity-0 scale-90'}`}
                    >
                        <span className="w-2 h-2 rounded-full bg-[#E00D42] shadow-[0_0_8px_#E00D42]" />
                        <span className="font-mono text-[11px] uppercase tracking-widest">
                            MERCHANT STANDARD 2026
                        </span>
                    </div>

                    {/* Bold Editorial Headline */}
                    <h1 
                        className={`text-5xl sm:text-7xl lg:text-8xl font-extrabold tracking-[-0.04em] leading-[0.98] max-w-4xl mx-auto transition-all duration-700 delay-150 ease-out transform text-[#0F172A] ${heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'}`}
                    >
                        Commerce, stripped <br />
                        <span className="text-slate-600">down to essentials.</span>
                    </h1>

                    {/* Single Minimal Line of Copy */}
                    <p 
                        className={`text-base sm:text-lg font-sans max-w-xl mx-auto leading-relaxed transition-all duration-700 delay-300 ease-out transform text-slate-700 font-normal ${heroLoaded ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'}`}
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
                            className="w-full sm:w-auto px-8 py-3.5 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition-all active:scale-95 shadow-lg flex items-center justify-center gap-2 bg-[#0F172A] hover:bg-black text-white shadow-slate-300"
                        >
                            <span>Launch Your Store — Free</span>
                            <ArrowRight className="w-3.5 h-3.5" />
                        </a>
                        <a
                            href={getDomainUrl('seller', '/login')}
                            className="w-full sm:w-auto px-7 py-3.5 rounded-full border font-mono text-xs uppercase tracking-wider transition flex items-center justify-center gap-2 bg-white hover:bg-slate-50 border-slate-300 text-slate-900 font-semibold shadow-xs"
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
                            className={`relative rounded-2xl p-[1px] transition-all duration-1000 ease-out transform bg-gradient-to-b from-slate-300 via-slate-200 to-slate-300 shadow-[0_25px_60px_-15px_rgba(0,0,0,0.12),0_0_0_1px_rgba(0,0,0,0.06)] ${
                                showcaseInView 
                                    ? 'opacity-100 translate-y-0 scale-100' 
                                    : 'opacity-90 translate-y-2 scale-[0.99]'
                            }`}
                        >
                            <div className="relative rounded-[15px] overflow-hidden bg-slate-100">
                                
                                {/* Window Titlebar */}
                                <div className="flex items-center justify-between px-4 py-3 border-b border-slate-200 bg-slate-100/95 text-slate-700">
                                    <div className="flex items-center gap-2">
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#FF5F56]" />
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#FFBD2E]" />
                                        <div className="w-2.5 h-2.5 rounded-full bg-[#27C93F]" />
                                    </div>
                                    
                                    {/* URL Pill Indicator */}
                                    <div className="flex items-center gap-2 px-3 py-0.5 rounded-full border font-mono text-[11px] border-slate-300/80 bg-white text-slate-700">
                                        <span className="w-1.5 h-1.5 rounded-full bg-emerald-400" />
                                        <span>bagoo.shop/seller/cockpit</span>
                                    </div>

                                    {/* Live Status Indicators */}
                                    <div className="flex items-center gap-2 font-mono text-[10px]">
                                        <span className="hidden sm:inline px-2 py-0.5 rounded bg-[#E00D42]/10 border border-[#E00D42]/20 text-[#E00D42] font-bold">
                                            10% FLAT COMMISSION
                                        </span>
                                        <span className="px-2 py-0.5 rounded border uppercase font-mono bg-white border-slate-200 text-slate-500">
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
                            
                            <div className="p-6 rounded-2xl border space-y-2.5 transition bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md">
                                <div className="w-8 h-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-500 font-bold text-xs">
                                    01
                                </div>
                                <h3 className="text-base font-bold pt-1 text-slate-900">Thermal Waybills</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Print standard A6 barcodes in one click. Zero manual handwriting.
                                </p>
                            </div>

                            <div className="p-6 rounded-2xl border space-y-2.5 transition bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42]/10 border border-[#E00D42]/20 flex items-center justify-center text-[#E00D42] font-bold text-xs">
                                    02
                                </div>
                                <h3 className="text-base font-bold pt-1 text-slate-900">Direct COD Ledger</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Cash collected at doorstep. 90% remitted straight to your balance.
                                </p>
                            </div>

                            <div className="p-6 rounded-2xl border space-y-2.5 transition bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md">
                                <div className="w-8 h-8 rounded-lg bg-blue-500/10 border border-blue-500/20 flex items-center justify-center text-blue-500 font-bold text-xs">
                                    03
                                </div>
                                <h3 className="text-base font-bold pt-1 text-slate-900">Doorstep Pickup</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Dedicated riders collect from your door. Never queue at branches.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 4. PANEL FIG 0.1: WHY US & PRODUCT IMAGE CAROUSEL (LITERALLY MINIMAL) */}
                <section id="economics" ref={economicsRef} className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-slate-200">
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 lg:gap-8 items-center">
                        
                        {/* LEFT COLUMN: WHY US - LITERALLY MINIMAL */}
                        <div 
                            className={`lg:col-span-5 space-y-6 transition-all duration-700 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'
                            }`}
                        >
                            <div className="space-y-3">
                                <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest block font-bold">
                                    FIG 0.1 — WHY US
                                </span>
                                <h2 className="text-4xl sm:text-5xl font-extrabold tracking-tight text-slate-900">
                                    Keep 90%.<br />
                                    No cuts.
                                </h2>
                                <p className="text-sm sm:text-base font-sans text-slate-600">
                                    Legacy platforms take 25% to 35%. Bagoo takes a flat 10%. You keep the rest.
                                </p>
                            </div>

                            {/* 3 Ultra-minimal bullets */}
                            <div className="space-y-3 pt-2">
                                <div className="flex items-center gap-3 font-mono text-xs">
                                    <span className="w-2 h-2 rounded-full bg-[#E00D42]" />
                                    <span className="text-slate-900 font-semibold">
                                        10% Flat Fee • Zero Hidden Surcharges
                                    </span>
                                </div>
                                <div className="flex items-center gap-3 font-mono text-xs">
                                    <span className="w-2 h-2 rounded-full bg-emerald-500" />
                                    <span className="text-slate-900 font-semibold">
                                        Direct Doorstep COD Remittance
                                    </span>
                                </div>
                                <div className="flex items-center gap-3 font-mono text-xs">
                                    <span className="w-2 h-2 rounded-full bg-blue-500" />
                                    <span className="text-slate-900 font-semibold">
                                        Sell ₱500 • Keep ₱450 Cash
                                    </span>
                                </div>
                            </div>

                            <div className="pt-2">
                                <a 
                                    href={getDomainUrl('seller', '/register')}
                                    className="inline-flex items-center gap-2 font-mono text-xs font-bold text-[#E00D42] hover:underline"
                                >
                                    <span>Start selling today</span>
                                    <ArrowRight className="w-3.5 h-3.5" />
                                </a>
                            </div>
                        </div>

                        {/* RIGHT COLUMN: 3D AUTO-SWAPPING PRODUCT IMAGE CAROUSEL (1.6s) */}
                        <div 
                            className={`lg:col-span-7 relative h-[380px] sm:h-[420px] overflow-hidden flex items-center justify-center transition-all duration-1000 ease-out transform ${
                                economicsInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'
                            }`}
                        >
                            {/* Ambient subtle radial glow */}
                            <div className="pointer-events-none absolute inset-0 bg-radial from-[#E00D42]/6 via-transparent to-transparent blur-3xl" />

                            {/* Seamless Edge Dissolve into Page Canvas */}
                            <div className="pointer-events-none absolute inset-y-0 left-0 w-12 bg-gradient-to-r z-30 from-[#FAFAFA] to-transparent" />
                            <div className="pointer-events-none absolute inset-y-0 right-0 w-12 bg-gradient-to-l z-30 from-[#FAFAFA] to-transparent" />

                            {/* Carousel Slides Track */}
                            <div className="relative w-full h-full flex items-center justify-center">
                                {CAROUSEL_PRODUCTS.map((prod, idx) => {
                                    const n = CAROUSEL_PRODUCTS.length;
                                    let diff = ((idx - activeCarouselIdx) % n + n) % n;
                                    if (diff > n / 2) diff -= n;

                                    const isCenter = diff === 0;
                                    const isLeft = diff === -1;
                                    const isRight = diff === 1;

                                    let transformStyle = '';
                                    let opacityClass = 'opacity-0 pointer-events-none';
                                    let zIndex = 0;

                                    if (isCenter) {
                                        transformStyle = 'translateX(0%) scale(1)';
                                        opacityClass = 'opacity-100 shadow-2xl';
                                        zIndex = 20;
                                    } else if (isLeft) {
                                        transformStyle = 'translateX(-60%) scale(0.8)';
                                        opacityClass = 'opacity-25 hover:opacity-40 shadow-lg';
                                        zIndex = 10;
                                    } else if (isRight) {
                                        transformStyle = 'translateX(60%) scale(0.8)';
                                        opacityClass = 'opacity-25 hover:opacity-40 shadow-lg';
                                        zIndex = 10;
                                    } else {
                                        transformStyle = `translateX(${diff > 0 ? '120%' : '-120%'}) scale(0.5)`;
                                        opacityClass = 'opacity-0 pointer-events-none';
                                        zIndex = 0;
                                    }

                                    return (
                                        <div
                                            key={prod.id}
                                            onClick={() => setActiveCarouselIdx(idx)}
                                            style={{
                                                transform: transformStyle,
                                                zIndex,
                                            }}
                                            className={`absolute w-[220px] sm:w-[260px] aspect-[4/5] rounded-2xl overflow-hidden transition-all duration-700 ease-in-out cursor-pointer select-none ${opacityClass}`}
                                        >
                                            <img
                                                src={prod.image}
                                                alt={prod.name}
                                                className="w-full h-full object-cover"
                                                loading="eager"
                                            />
                                            
                                            {/* Vignette Overlay & Minimal Labels - No white borders */}
                                            <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-transparent flex flex-col justify-between p-4">
                                                <div className="flex justify-between items-center">
                                                    <span className="px-2 py-0.5 rounded-full bg-black/60 backdrop-blur-md font-mono text-[9px] text-white/90 font-semibold uppercase tracking-wider">
                                                        {prod.category}
                                                    </span>
                                                    <span className="px-2 py-0.5 rounded-full bg-[#E00D42] font-mono text-[9px] text-white font-bold tracking-wider">
                                                        90% PAYOUT
                                                    </span>
                                                </div>

                                                <div className="space-y-1">
                                                    <h4 className="text-white font-bold text-sm leading-snug drop-shadow-sm">
                                                        {prod.name}
                                                    </h4>
                                                    <div className="flex items-center justify-between font-mono text-xs pt-0.5">
                                                        <span className="text-white/80 font-semibold">{prod.price}</span>
                                                        <span className="text-emerald-400 font-bold text-[11px]">{prod.payout}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            {/* Bottom Pagination Dots */}
                            <div className="absolute bottom-2 flex items-center gap-1.5 z-30">
                                {CAROUSEL_PRODUCTS.map((_, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        onClick={() => setActiveCarouselIdx(i)}
                                        className={`h-1 rounded-full transition-all duration-300 ${
                                            i === activeCarouselIdx 
                                                ? 'w-5 bg-[#E00D42]' 
                                                : 'w-1.5 bg-slate-300 hover:bg-slate-400'
                                        }`}
                                        aria-label={`Product slide ${i + 1}`}
                                    />
                                ))}
                            </div>

                        </div>

                    </div>
                </section>

                {/* 5. PANEL FIG 0.2: THE 3-BEAT EXECUTION ARC (CASCADING SEQUENTIAL DROP ANIMATION) */}
                <section id="workflow" ref={workflowRef} className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-slate-200">
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
                                <span className="text-slate-400">•</span>
                                <span className="font-mono text-[11px] uppercase tracking-wider font-semibold text-emerald-700">
                                    1 Valid ID • 1-Click Admin Approval
                                </span>
                            </div>
                            <h2 className="text-3xl sm:text-4xl font-extrabold tracking-tight mt-1 text-slate-900">
                                From link to cash in three beats.
                            </h2>
                        </div>

                        {/* Cascading 3 Cards */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 font-mono">
                            
                            {/* Step 01 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-100 ease-out transform bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <div className="flex items-center justify-between">
                                    <span className="text-4xl font-extrabold block text-slate-300">01</span>
                                    <span className="px-2 py-0.5 rounded border text-[10px] font-mono font-bold bg-emerald-50 border-emerald-200 text-emerald-700">
                                        1 VALID ID ONLY
                                    </span>
                                </div>
                                <h3 className="text-base font-bold text-slate-900">Upload 1 Valid ID</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Upload 1 valid ID (Student or Govt ID) and pickup address. 1-click admin approval with zero DTI paperwork.
                                </p>
                            </div>

                            {/* Step 02 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-300 ease-out transform bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <span className="text-4xl font-extrabold block text-slate-300">02</span>
                                <h3 className="text-base font-bold text-slate-900">Handover to Rider</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Print 1-click thermal waybill. Couriers collect directly from your door.
                                </p>
                            </div>

                            {/* Step 03 */}
                            <div 
                                className={`p-6 rounded-2xl border space-y-3 transition-all duration-700 delay-500 ease-out transform bg-white border-slate-200 shadow-sm hover:border-slate-300 hover:shadow-md ${workflowInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-12'}`}
                            >
                                <span className="text-4xl font-extrabold block text-emerald-600/40">03</span>
                                <h3 className="text-base font-bold text-slate-900">Collect Cash</h3>
                                <p className="text-xs font-sans leading-relaxed text-slate-600">
                                    Courier delivers parcel, collects COD, and remits 90% direct to your ledger.
                                </p>
                            </div>

                        </div>

                    </div>
                </section>

                {/* 6. MINIMALIST FAQ ACCORDION (RIPPLE FADE-IN ANIMATION) */}
                <section id="faq" ref={faqRef} className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20 border-t border-slate-200 w-full">
                    <div className="space-y-8">
                        
                        <div 
                            className={`text-center space-y-2 transition-all duration-700 ease-out transform ${
                                faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 -translate-y-6'
                            }`}
                        >
                            <span className="font-mono text-xs text-[#E00D42] uppercase tracking-widest">
                                FIG 0.3 — INQUIRIES
                            </span>
                            <h2 className="text-3xl font-extrabold tracking-tight text-slate-900">
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
                                        className={`border rounded-xl overflow-hidden transition-all duration-700 ease-out transform bg-white border-slate-200 shadow-sm ${faqInView ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'} ${delays[index]}`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setOpenFaq(isOpen ? null : index)}
                                            className="w-full p-5 text-left flex items-center justify-between gap-4 focus:outline-hidden"
                                        >
                                            <span className="font-bold text-sm sm:text-base text-slate-900">
                                                {faq.q}
                                            </span>
                                            {isOpen ? (
                                                <ChevronUp className="w-4 h-4 shrink-0 text-slate-600" />
                                            ) : (
                                                <ChevronDown className="w-4 h-4 shrink-0 text-slate-600" />
                                            )}
                                        </button>
                                        {isOpen && (
                                            <div className="px-5 pb-5 text-xs sm:text-sm leading-relaxed border-t border-slate-100 pt-3 text-slate-700">
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
                <section id="closing" ref={closingRef} className="py-28 border-t border-slate-200 relative overflow-hidden text-center px-4">
                    {/* Radial Ambient Glow (Expands on Scroll) */}
                    <div 
                        className={`absolute inset-0 pointer-events-none transition-all duration-1000 ease-out transform ${
                            closingInView ? 'opacity-25 scale-100' : 'opacity-0 scale-75'
                        }`}
                        style={{
                            background: 'radial-gradient(circle at 50% 50%, rgba(224, 13, 66, 0.15) 0%, transparent 65%)'
                        }}
                    />

                    <div 
                        className={`max-w-xl mx-auto space-y-6 relative z-10 transition-all duration-700 delay-150 ease-out transform ${
                            closingInView ? 'opacity-100 scale-100' : 'opacity-0 scale-95'
                        }`}
                    >
                        <h2 className="text-4xl sm:text-5xl font-extrabold tracking-[-0.03em] text-slate-900">
                            Build your brand. <br />
                            <span className="text-slate-600">Get paid in cash.</span>
                        </h2>

                        <p className="text-sm max-w-sm mx-auto font-sans text-slate-700 font-normal">
                            Start listing your catalog today on BagooPH with 10% flat commission and reliable doorstep fulfillment.
                        </p>

                        <div className="pt-2 flex flex-col sm:flex-row items-center justify-center gap-3">
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="w-full sm:w-auto px-8 py-3.5 rounded-full font-mono text-xs font-bold uppercase tracking-wider transition active:scale-95 bg-[#E00D42] hover:bg-[#C00A38] text-white shadow-lg shadow-rose-500/20"
                            >
                                Open Store Free
                            </a>
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className="w-full sm:w-auto px-7 py-3.5 rounded-full font-mono text-xs uppercase tracking-wider transition bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-900 font-semibold"
                            >
                                Merchant Sign In
                            </a>
                        </div>
                    </div>
                </section>

                {/* 8. MONOCHROME STUDIO FOOTER */}
                <footer className="border-t border-slate-200 bg-slate-100 text-slate-600 py-10 px-4 sm:px-6 lg:px-8 font-mono text-xs">
                    <div className="max-w-5xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-6">
                        <div className="flex items-center gap-2.5">
                            <BagooLogo className="w-6 h-6" rounded="rounded-md" />
                            <span className="font-bold tracking-tight text-slate-900">
                                Bagoo<span className="text-[#E00D42]">PH</span>
                            </span>
                            <span>•</span>
                            <span>Seller Operations</span>
                        </div>

                        <div className="flex flex-wrap items-center gap-6">
                            <a href={getDomainUrl('buyer', '/')} className="transition text-slate-600 hover:text-slate-900 font-medium">Marketplace</a>
                            <a href={getDomainUrl('courier', '/')} className="transition text-slate-600 hover:text-slate-900 font-medium">Courier</a>
                            <a href={getDomainUrl('admin', '/')} className="transition text-slate-600 hover:text-slate-900 font-medium">Admin</a>
                            <span>&copy; {new Date().getFullYear()}</span>
                        </div>
                    </div>
                </footer>

            </div>
        </div>
    );
}
