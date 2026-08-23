import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Category } from '@/types';
import GrainOverlay from '@/Components/GrainOverlay';
import ThreeShoppingBag from '@/Components/ThreeShoppingBag';
import { 
    ShoppingBag, 
    ArrowRight, 
    Store, 
    ShieldCheck, 
    Truck, 
    Check, 
    FileText, 
    TrendingUp, 
    Zap, 
    UserCheck, 
    DollarSign,
    MapPin, 
    ChevronRight,
    Menu
} from 'lucide-react';

interface Props {
    categories: Category[];
}

const SECTIONS = [
    { id: 'hero', name: 'OVERVIEW', num: '01', theme: 'light' },
    { id: 'buyer', name: 'BUYER PORTAL', num: '02', theme: 'dark' },
    { id: 'seller', name: 'SELLER STUDIO', num: '03', theme: 'light' },
    { id: 'courier', name: 'COURIER DISPATCH', num: '04', theme: 'dark' },
    { id: 'admin', name: 'ADMIN GOVERNANCE', num: '05', theme: 'dark' },
    { id: 'departments', name: '14 DEPARTMENTS', num: '06', theme: 'light' },
] as const;

export default function MarketplaceIndex({ categories }: Props) {
    const [currentTime, setCurrentTime] = useState('12:00 PM');
    const [activeSectionIndex, setActiveSectionIndex] = useState<number>(0);
    const [isNavHovered, setIsNavHovered] = useState<boolean>(false);
    const isScrollingRef = useRef<boolean>(false);
    const activeIndexRef = useRef<number>(0);

    // Keep activeIndexRef synced with state
    useEffect(() => {
        activeIndexRef.current = activeSectionIndex;
    }, [activeSectionIndex]);

    const scrollToSectionIndex = (index: number) => {
        if (index < 0 || index >= SECTIONS.length) return;
        const targetSection = SECTIONS[index];
        const el = document.getElementById(targetSection.id);
        if (!el) return;

        isScrollingRef.current = true;
        setActiveSectionIndex(index);

        el.scrollIntoView({ behavior: 'smooth', block: 'start' });

        setTimeout(() => {
            isScrollingRef.current = false;
        }, 750);
    };

    useEffect(() => {
        // Clock timer
        const updateTime = () => {
            const now = new Date();
            setCurrentTime(now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        };
        updateTime();
        const timer = setInterval(updateTime, 1000);

        // 1. MAGNETIC SECTION WHEEL CONTROLLER
        const handleWheel = (e: WheelEvent) => {
            // Ignore trivial trackpad vibrations
            if (Math.abs(e.deltaY) < 25) return;
            if (isScrollingRef.current) {
                e.preventDefault();
                return;
            }

            e.preventDefault();
            const current = activeIndexRef.current;

            if (e.deltaY > 0 && current < SECTIONS.length - 1) {
                scrollToSectionIndex(current + 1);
            } else if (e.deltaY < 0 && current > 0) {
                scrollToSectionIndex(current - 1);
            }
        };

        // 2. KEYBOARD NAVIGATION (Arrow keys, Spacebar, PageDown/PageUp)
        const handleKeyDown = (e: KeyboardEvent) => {
            if (['ArrowDown', 'PageDown', ' '].includes(e.key)) {
                e.preventDefault();
                const current = activeIndexRef.current;
                if (current < SECTIONS.length - 1) scrollToSectionIndex(current + 1);
            } else if (['ArrowUp', 'PageUp'].includes(e.key)) {
                e.preventDefault();
                const current = activeIndexRef.current;
                if (current > 0) scrollToSectionIndex(current - 1);
            }
        };

        // 3. TOUCH SWIPE CONTROLLER FOR MOBILE / TABLET
        let touchStartY = 0;
        const handleTouchStart = (e: TouchEvent) => {
            touchStartY = e.touches[0].clientY;
        };

        const handleTouchEnd = (e: TouchEvent) => {
            if (isScrollingRef.current) return;
            const touchEndY = e.changedTouches[0].clientY;
            const diff = touchStartY - touchEndY;
            const current = activeIndexRef.current;

            if (diff > 45 && current < SECTIONS.length - 1) {
                scrollToSectionIndex(current + 1);
            } else if (diff < -45 && current > 0) {
                scrollToSectionIndex(current - 1);
            }
        };

        window.addEventListener('wheel', handleWheel, { passive: false });
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('touchstart', handleTouchStart, { passive: true });
        window.addEventListener('touchend', handleTouchEnd, { passive: true });

        return () => {
            clearInterval(timer);
            window.removeEventListener('wheel', handleWheel);
            window.removeEventListener('keydown', handleKeyDown);
            window.removeEventListener('touchstart', handleTouchStart);
            window.removeEventListener('touchend', handleTouchEnd);
        };
    }, []);

    const currentSection = SECTIONS[activeSectionIndex] || SECTIONS[0];
    const isDarkHeader = currentSection.theme === 'dark';

    return (
        <MarketplaceLayout headerTheme={isDarkHeader ? 'dark' : 'light'}>
            <Head title="BagooPH — A New Standard in Multi-Role E-Commerce" />

            {/* Grain & Noise Film Overlay */}
            <GrainOverlay />

            {/* HOVER-REVEAL RIGHT-SIDE SECTION NAVIGATION WITH INDICATOR */}
            <div 
                className="fixed right-0 top-1/2 -translate-y-1/2 z-50 pointer-events-auto hidden md:flex items-center"
                onMouseEnter={() => setIsNavHovered(true)}
                onMouseLeave={() => setIsNavHovered(false)}
            >
                {/* 1. Minimal Glowing Edge Indicator Tab (Always visible on right edge) */}
                <div 
                    className={`cursor-pointer transition-all duration-300 py-3.5 px-2 rounded-l-xl flex flex-col items-center gap-2.5 font-mono shadow-2xl backdrop-blur-xl border-l border-y ${
                        isDarkHeader
                            ? 'bg-black/60 border-white/20 text-white'
                            : 'bg-black/80 border-black/30 text-white'
                    } ${isNavHovered ? 'opacity-0 translate-x-4 pointer-events-none' : 'opacity-100 translate-x-0'}`}
                >
                    <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                    <span className="text-[10px] font-bold text-[#E00D42]">{currentSection.num}</span>
                    <div className="w-px h-6 bg-white/20"></div>
                    <span className="[writing-mode:vertical-rl] text-[9px] uppercase tracking-widest text-white/70 font-semibold">
                        NAV
                    </span>
                    <ChevronRight className="w-3 h-3 text-white/50" />
                </div>

                {/* 2. Full Glassmorphic Navigation Card (Slides in on hover) */}
                <div 
                    className={`transition-all duration-300 ease-out pr-6 ${
                        isNavHovered 
                            ? 'opacity-100 translate-x-0 pointer-events-auto' 
                            : 'opacity-0 translate-x-12 pointer-events-none'
                    }`}
                >
                    <div className="bg-black/65 backdrop-blur-2xl text-white border border-white/20 rounded-2xl p-4 shadow-2xl font-mono text-xs w-52 space-y-3">
                        <div className="flex items-center justify-between border-b border-white/20 pb-2 text-[10px] text-white/70 tracking-wider">
                            <span className="flex items-center gap-1.5 font-bold">
                                <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                                MAGNETIC NAV
                            </span>
                            <span className="text-[#E00D42] font-bold">{currentSection.num} / 06</span>
                        </div>

                        {/* Section Stack */}
                        <div className="space-y-1">
                            {SECTIONS.map((sec, idx) => {
                                const isCurrent = activeSectionIndex === idx;
                                return (
                                    <button
                                        key={sec.id}
                                        onClick={() => scrollToSectionIndex(idx)}
                                        className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-left transition-all duration-200 ${
                                            isCurrent 
                                                ? 'bg-[#E00D42] text-white font-bold shadow-lg shadow-[#E00D42]/30 scale-[1.02]' 
                                                : 'text-white/75 hover:text-white hover:bg-white/15'
                                        }`}
                                    >
                                        <span className="truncate text-[11px] uppercase tracking-wide">
                                            {sec.name}
                                        </span>
                                        <span className={`text-[9px] ${isCurrent ? 'text-white' : 'text-white/50'}`}>
                                            [{sec.num}]
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        <div className="pt-1.5 text-[9px] text-center text-white/50 border-t border-white/15 uppercase">
                            SCROLL WHEEL SNAPS
                        </div>
                    </div>
                </div>
            </div>

            {/* SECTIONS WRAPPER */}
            <div className="relative font-sans selection:bg-[#E00D42] selection:text-white">
                
                {/* SECTION 01: HERO (LIGHT MODE) */}
                <section 
                    id="hero" 
                    className="relative min-h-[92vh] flex flex-col justify-between p-4 sm:p-8 lg:p-12 border-b border-black/15 bg-[#ECEAE5] text-[#111111] overflow-hidden transition-colors duration-700"
                >
                    {/* Crosshairs */}
                    <span className="absolute top-4 left-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 right-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute bottom-4 left-4 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 right-4 text-black/40 font-mono text-xs select-none z-30">+</span>

                    {/* Top Info Line */}
                    <div className="flex justify-between items-center z-30 font-mono text-[11px] uppercase tracking-wider text-black/70">
                        <div className="flex items-center gap-3">
                            <span className="font-black text-black">BAGOO-PH</span>
                            <span className="text-black/30">/</span>
                            <span>MULTI-ROLE PLATFORM</span>
                        </div>

                        <div className="hidden sm:flex items-center gap-6 text-black/70 font-medium">
                            <button onClick={() => scrollToSectionIndex(1)} className="hover:text-[#E00D42] transition">BUYER</button>
                            <button onClick={() => scrollToSectionIndex(2)} className="hover:text-[#E00D42] transition">SELLER</button>
                            <button onClick={() => scrollToSectionIndex(3)} className="hover:text-[#E00D42] transition">COURIER</button>
                            <button onClick={() => scrollToSectionIndex(4)} className="hover:text-[#E00D42] transition">ADMIN</button>
                        </div>
                    </div>

                    {/* 3D BAG AT THE BACK OF BRAND NAME (z-0) */}
                    <div className="absolute inset-0 flex items-center justify-center z-0 pointer-events-auto">
                        <div className="w-[340px] h-[340px] sm:w-[520px] sm:h-[520px] lg:w-[680px] lg:h-[680px]">
                            <ThreeShoppingBag />
                        </div>
                    </div>

                    {/* Massive Monumental Top Typography: BAGOO (z-20, sits above 3D Bag) */}
                    <div className="relative z-20 select-none pointer-events-none mt-2 sm:mt-4">
                        <h1 className="text-[17vw] leading-[0.8] font-black tracking-tighter text-black flex items-center drop-shadow-sm">
                            <span>B</span>
                            <span className="relative inline-flex items-center justify-center">
                                A
                                <span className="absolute top-[32%] left-[48%] -translate-x-1/2 -translate-y-1/2 w-[3.2vw] h-[3.2vw] rounded-full bg-[#E00D42] shadow-sm animate-pulse"></span>
                            </span>
                            <span>GOO</span>
                        </h1>
                    </div>

                    {/* Mid Right Action Tag */}
                    <div className="hidden lg:flex absolute right-12 top-1/2 -translate-y-1/2 z-30 flex-col items-end gap-3 font-mono text-xs">
                        <button 
                            onClick={() => scrollToSectionIndex(1)}
                            className="group flex items-center gap-3 px-4 py-2.5 bg-black/5 hover:bg-black hover:text-white rounded-lg border border-black/15 backdrop-blur-md transition duration-200"
                        >
                            <span className="w-0 h-0 border-y-4 border-y-transparent border-l-6 border-l-[#E00D42] group-hover:border-l-white"></span>
                            <span className="tracking-widest uppercase font-bold text-[11px]">SCROLL TO SNAP SECTIONS</span>
                        </button>
                        <span className="text-[10px] text-black/50 tracking-wider uppercase">HOVER RIGHT EDGE FOR NAV</span>
                    </div>

                    {/* Bottom Metadata & Lower Typography */}
                    <div className="relative z-20 space-y-4 pt-6">
                        <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-end font-mono text-xs">
                            <div className="md:col-span-4 space-y-1 text-black/70">
                                <div className="text-[10px] text-black/40 font-bold uppercase tracking-widest">ABOUT</div>
                                <div className="flex items-center gap-6 font-bold text-black text-[11px]">
                                    <span>LOCATION: PHILIPPINES</span>
                                    <span>TIME: {currentTime}</span>
                                </div>
                            </div>

                            <div className="md:col-span-8">
                                <p className="text-black/80 font-mono text-xs leading-relaxed max-w-2xl uppercase">
                                    BUILDING THE NEW STANDARD OF MULTI-ROLE COMMERCE. 14 VERIFIED PRODUCT DEPARTMENTS, FIRST-COME COURIER DISPATCH, AND 10% PLATFORM COMMISSION LEDGER.
                                </p>
                            </div>
                        </div>

                        <div className="select-none pointer-events-none">
                            <h2 className="text-[17vw] leading-[0.8] font-black tracking-tighter text-black">
                                COMMERCE
                            </h2>
                        </div>
                    </div>
                </section>

                {/* SECTION 02: BUYER PORTAL (DARK MODE) */}
                <section 
                    id="buyer" 
                    className="relative min-h-[92vh] flex flex-col justify-center py-20 px-4 sm:px-8 lg:px-12 bg-[#0A0D14] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_01 // BUYER]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Buyer Experience & Doorstep Tracking
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">PSGC CASCADING & TELEMETRY</span>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                            {/* Imagery & Telemetry Mockup */}
                            <div className="lg:col-span-6 space-y-4">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Buyer Shopping Experience" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/30 to-transparent"></div>

                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2.5 shadow-2xl">
                                        <div className="flex justify-between items-center border-b border-white/15 pb-2">
                                            <span className="text-white/60">PARCEL #BGO-98124</span>
                                            <span className="text-emerald-400 font-bold">IN TRANSIT</span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-lg bg-[#E00D42] flex items-center justify-center text-white shrink-0">
                                                <Truck className="w-4 h-4" />
                                            </div>
                                            <div>
                                                <p className="text-white font-bold font-sans text-xs">Courier En Route to Recipient</p>
                                                <p className="text-[10px] text-white/50">Estimated Doorstep Drop-off: 2:45 PM</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Feature Grid */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    ZERO-FRICTION BUYER JOURNEY
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
                                    Authentic Stores. Precision Logistics.
                                </h4>
                                <p className="text-xs sm:text-sm text-white/70 font-mono leading-relaxed uppercase">
                                    BUYERS BENEFIT FROM 14 MASTER CATEGORIES, MULTI-TIER VARIATION SELECTION (COLOR/SIZE), AUTOMATED VOUCHERS, AND LIVE DISPATCH TELEMETRY.
                                </p>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-sans">
                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Cascading PSGC Address</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Province ➔ Municipality ➔ Barangay ➔ Street automated selector.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Flexible Multi-Payment</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Cash on Delivery (COD), Card checkout, and Direct Bank Transfer.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Variation Selector</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Attributes selector for apparel sizes, colorways, and specifications.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Verified Reviews</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Post-delivery star ratings, text reviews, and direct merchant chat.</p>
                                    </div>
                                </div>

                                <div className="pt-2 font-mono">
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider"
                                    >
                                        <span>Create Buyer Account</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* SECTION 03: SELLER CENTER (LIGHT MODE) */}
                <section 
                    id="seller" 
                    className="relative min-h-[92vh] flex flex-col justify-center py-20 px-4 sm:px-8 lg:px-12 bg-[#F3F0EA] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-black/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_02 // MERCHANT]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-black mt-1 font-sans">
                                    Seller Studio & Waybill Printing
                                </h3>
                            </div>
                            <span className="text-xs text-black/50 uppercase">INVENTORY, LABELS & PROFIT REPORTS</span>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                            {/* Feature Details */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    MERCHANT POWER TOOLS
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-black tracking-tight">
                                    Scale Your Store with Complete Clarity
                                </h4>
                                <p className="text-xs sm:text-sm text-black/70 font-mono leading-relaxed uppercase">
                                    VERIFIED SELLERS MANAGE PRODUCT INVENTORIES, PRINT OFFICIAL WAYBILLS WITH ONE CLICK, AND ANALYZE STORE FINANCIALS WITH CUSTOM DATE RANGE FILTERS.
                                </p>

                                <div className="space-y-3 font-sans">
                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3 shadow-xs">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <FileText className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">Printable Shipping Waybills</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Automated package label with scannable barcode ready for courier pickup.</p>
                                        </div>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3 shadow-xs">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <TrendingUp className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">Date-Filtered Profit Reports</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Filter from date to date with gross sales, unit volume, and exact 10% platform commission breakdown.</p>
                                        </div>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3 shadow-xs">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <Store className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">KYC Business Verification</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Admin-reviewed business permits and ID verification granting verified store badge status.</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="pt-2 font-mono">
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-black hover:bg-[#E00D42] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider"
                                    >
                                        <span>Open Seller Store</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>

                            {/* Imagery & Mockup */}
                            <div className="lg:col-span-6 space-y-4">
                                <div className="relative rounded-2xl overflow-hidden border border-black/15 aspect-[4/3] group shadow-xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Merchant Packaging Studio" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent"></div>

                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-white text-black border border-black/15 shadow-2xl font-mono text-xs space-y-2">
                                        <div className="flex justify-between items-center border-b border-black/10 pb-1.5 text-[10px]">
                                            <span className="font-bold text-[#E00D42]">BAGOO-EXPRESS WAYBILL</span>
                                            <span className="text-black/50">TRACK: BGO-892401</span>
                                        </div>
                                        <div className="flex justify-between items-center text-[11px] font-sans">
                                            <div>
                                                <span className="block font-bold text-black">Packaged & Ready for Dispatch</span>
                                                <span className="text-[10px] text-black/50 font-mono">10% COMMISSION DEDUCTED: -$24.50</span>
                                            </div>
                                            <span className="px-2.5 py-1 bg-black text-white text-[10px] font-bold rounded-md font-mono">
                                                PRINT READY
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* SECTION 04: COURIER DISPATCH (DARK MODE) */}
                <section 
                    id="courier" 
                    className="relative min-h-[92vh] flex flex-col justify-center py-20 px-4 sm:px-8 lg:px-12 bg-[#070A10] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_03 // COURIER]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Courier Logistics & First-Come Dispatch
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">FIRST-COME DISPATCH POOL</span>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                            {/* Imagery & Route Mockup */}
                            <div className="lg:col-span-6 space-y-4">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1616401784845-180882ba9ba8?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Urban Courier Dispatch Rider" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/20 to-transparent"></div>

                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2 shadow-2xl">
                                        <div className="flex justify-between items-center border-b border-white/15 pb-1.5">
                                            <span className="text-[#E00D42] font-bold">DISPATCH JOB AVAILABLE</span>
                                            <span className="text-emerald-400 font-bold">+$18.50 FARE</span>
                                        </div>
                                        <div className="space-y-1 text-[11px] text-white/80 font-sans">
                                            <p className="flex items-center gap-1.5">
                                                <span className="w-2 h-2 rounded-full bg-[#E00D42]"></span>
                                                <span>Pickup: Store #89 (Central Hub)</span>
                                            </p>
                                            <p className="flex items-center gap-1.5">
                                                <span className="w-2 h-2 rounded-full bg-emerald-400"></span>
                                                <span>Dropoff: 142 Rizal Avenue (Doorstep)</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Features */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    ALGORITHMIC FLEET DISPATCH
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
                                    First-Come First-Served Parcel Pool
                                </h4>
                                <p className="text-xs sm:text-sm text-white/70 font-mono leading-relaxed uppercase">
                                    AVAILABLE COURIERS RECEIVE LIVE PICKUP BROADCASTS, COMPLETE STRUCTURED TRANSIT CHECKPOINTS, AND CAPTURE DIGITAL RECIPIENT SIGNATURES ON DELIVERY.
                                </p>

                                <div className="space-y-3 font-sans">
                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <Zap className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Instant First-Come Job Claim</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">No dispatch favoritism. All available approved riders see the task pool simultaneously.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <MapPin className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Turn-by-Turn Waypoint Routing</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">Automated seller store pickup guidance and buyer doorstep drop-off coordinates.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <DollarSign className="w-3.5 h-3.5 text-emerald-400" />
                                            <span>Daily Trip Earnings Tracker</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">Transparent daily statement recording every completed delivery with automated settlement.</p>
                                    </div>
                                </div>

                                <div className="pt-2 font-mono">
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider"
                                    >
                                        <span>Register as Courier</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* SECTION 05: ADMIN GOVERNANCE (DEEP MIDNIGHT DARK MODE) */}
                <section 
                    id="admin" 
                    className="relative min-h-[92vh] flex flex-col justify-center py-20 px-4 sm:px-8 lg:px-12 bg-[#05070B] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_04 // GOVERNANCE]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Admin Governance & 10% Engine
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">KYC QUEUE & MEDIATION DESK</span>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                            {/* Features */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    PLATFORM INTEGRITY
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
                                    Multi-Tier Platform Oversight
                                </h4>
                                <p className="text-xs sm:text-sm text-white/70 font-mono leading-relaxed uppercase">
                                    OUR CENTRALIZED ADMIN SYSTEM CONTROLS KYC VERIFICATIONS, ENSURES STRICT 14 CATEGORY COMPLIANCE, AND AUTOMATES THE 10% PLATFORM COMMISSION LEDGER.
                                </p>

                                <div className="space-y-3 font-sans">
                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <UserCheck className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Document Approval Queue</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">Modal document inspection for Government IDs, Business Permits, and Drivers' Licenses.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <TrendingUp className="w-3.5 h-3.5 text-emerald-400" />
                                            <span>Automated 10% Commission Engine</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">Every completed order automatically deducts a 10% platform fee recorded in financial reports.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1">
                                        <h5 className="text-xs font-bold text-white flex items-center gap-2">
                                            <ShieldCheck className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Tripartite Dispute Mediation</span>
                                        </h5>
                                        <p className="text-[11px] text-white/60">Dedicated mediation resolution between buyers, sellers, and logistics partners.</p>
                                    </div>
                                </div>

                                <div className="pt-2 font-mono">
                                    <Link
                                        href={route('login')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-white/10 hover:bg-white hover:text-black active:scale-[0.98] text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase tracking-wider"
                                    >
                                        <span>Sign In to Admin Portal</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>

                            {/* Imagery & KYC Mockup */}
                            <div className="lg:col-span-6 space-y-4">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Admin Data Dashboard" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/20 to-transparent"></div>

                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2 shadow-2xl">
                                        <div className="flex justify-between items-center border-b border-white/15 pb-1.5">
                                            <span className="text-white/60">KYC VERIFICATION QUEUE</span>
                                            <span className="text-[#E00D42] font-bold">100% AUDITED</span>
                                        </div>
                                        <div className="flex justify-between items-center text-[11px] text-white/80 font-sans">
                                            <div>
                                                <span className="block font-bold text-white">Apex Athletics Co. (Seller)</span>
                                                <span className="text-[10px] text-emerald-400 font-mono">BUSINESS PERMIT: APPROVED ✓</span>
                                            </div>
                                            <span className="px-2.5 py-1 bg-[#E00D42] text-white text-[10px] font-bold rounded-md font-mono">
                                                VERIFIED
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* SECTION 06: 14 MASTER DEPARTMENTS (LIGHT MODE) */}
                <section 
                    id="departments" 
                    className="relative min-h-[92vh] flex flex-col justify-center py-20 px-4 sm:px-8 lg:px-12 bg-[#ECEAE5] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-black/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[TAXONOMY // 05]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-black mt-1 font-sans">
                                    14 Master Product Departments
                                </h3>
                            </div>
                            <span className="text-xs text-black/50 uppercase">OFFICIAL CURRICULUM TAXONOMY</span>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            {categories.map((cat, idx) => {
                                const num = String(idx + 1).padStart(2, '0');
                                return (
                                    <div
                                        key={cat.id}
                                        className="p-5 bg-white rounded-xl border border-black/10 flex flex-col justify-between h-32 hover:border-black transition shadow-xs"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-bold text-[#E00D42]">[{num}/14]</span>
                                            <span className="text-[10px] text-black/40 uppercase">VERIFIED</span>
                                        </div>
                                        <div>
                                            <h5 className="font-black text-sm text-black font-sans uppercase">
                                                {cat.name}
                                            </h5>
                                            <span className="text-[11px] text-black/50 block font-sans mt-0.5">
                                                {cat.description || 'Verified platform department'}
                                            </span>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </section>

                {/* CALL TO ACTION BOTTOM BANNER */}
                <section className="bg-black text-white py-16 px-4 sm:px-8 font-mono border-t border-black">
                    <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-8">
                        <div className="space-y-2 text-center md:text-left font-sans">
                            <span className="text-xs font-mono uppercase tracking-widest text-[#E00D42]">
                                ✦ JOIN THE BAGOO-PH NETWORK
                            </span>
                            <h4 className="text-2xl sm:text-3xl font-black tracking-tight">
                                Ready to Experience Multi-Role Commerce?
                            </h4>
                            <p className="text-xs sm:text-sm text-white/70 max-w-lg leading-relaxed font-mono uppercase">
                                REGISTER TODAY AS A BUYER, MERCHANT STORE, OR COURIER LOGISTICS PARTNER.
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-3 shrink-0">
                            <Link
                                href={route('register')}
                                className="px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider font-mono"
                            >
                                Register Now
                            </Link>
                            <Link
                                href={route('login')}
                                className="px-6 py-3 bg-white/10 hover:bg-white hover:text-black text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase tracking-wider font-mono"
                            >
                                Sign In
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </MarketplaceLayout>
    );
}
