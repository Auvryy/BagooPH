import React, { useState, useEffect } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Category } from '@/types';
import GrainOverlay from '@/Components/GrainOverlay';
import ThreeShoppingBag from '@/Components/ThreeShoppingBag';
import BagooLoadingScreen from '@/Components/BagooLoadingScreen';
import { 
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
    MapPin
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
    const [activeSectionId, setActiveSectionId] = useState<string>('hero');
    const [isLoaded, setIsLoaded] = useState<boolean>(false);

    useEffect(() => {
        // Trigger left-to-right entrance animation right after loading sequence
        const timer = setTimeout(() => {
            setIsLoaded(true);
        }, 1100);

        const updateTime = () => {
            const now = new Date();
            setCurrentTime(now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        };
        updateTime();
        const clockInterval = setInterval(updateTime, 1000);

        // Natural smooth scroll spy for dynamic adaptive navbar theme
        const handleScroll = () => {
            const scrollPos = window.scrollY + 200;
            for (let i = SECTIONS.length - 1; i >= 0; i--) {
                const el = document.getElementById(SECTIONS[i].id);
                if (el && el.offsetTop <= scrollPos) {
                    setActiveSectionId(SECTIONS[i].id);
                    break;
                }
            }
        };

        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll();

        return () => {
            clearTimeout(timer);
            clearInterval(clockInterval);
            window.removeEventListener('scroll', handleScroll);
        };
    }, []);

    const scrollToSection = (id: string) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth' });
    };

    const currentSection = SECTIONS.find(s => s.id === activeSectionId) || SECTIONS[0];
    const isDarkHeader = currentSection.theme === 'dark';

    return (
        <MarketplaceLayout headerTheme={isDarkHeader ? 'dark' : 'light'}>
            <Head title="BagooPH — A New Standard in Multi-Role E-Commerce" />

            {/* Jumping Letter Bagoo Intro Loading Screen */}
            <BagooLoadingScreen onComplete={() => setIsLoaded(true)} />

            {/* Grain & Noise Film Overlay */}
            <GrainOverlay />

            {/* MAIN SECTIONS CONTAINER */}
            <div className="relative font-sans selection:bg-[#E00D42] selection:text-white">
                
                {/* SECTION 01: HERO (LIGHT MODE WITH LEFT-TO-RIGHT ENTRANCE ANIMATION) */}
                <section 
                    id="hero" 
                    className="relative min-h-[calc(100vh-64px)] sm:min-h-[calc(100vh-80px)] flex flex-col justify-between p-4 sm:p-8 lg:p-12 border-b border-black/15 bg-[#ECEAE5] text-[#111111] overflow-hidden transition-colors duration-700"
                >
                    {/* Precision Crosshairs */}
                    <span className="absolute top-4 left-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 right-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute bottom-4 left-4 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 right-4 text-black/40 font-mono text-xs select-none z-30">+</span>

                    {/* Top Info Line */}
                    <div className="flex flex-col sm:flex-row justify-between items-center z-30 font-mono text-[11px] uppercase tracking-wider text-black/70 gap-2">
                        <div className="flex items-center gap-3">
                            <span className="font-black text-black">BAGOO-PH</span>
                            <span className="text-black/30">/</span>
                            <span>MULTI-ROLE PLATFORM</span>
                        </div>

                        <div className="hidden sm:flex items-center gap-6 text-black/70 font-medium">
                            <button onClick={() => scrollToSection('buyer')} className="hover:text-[#E00D42] transition">BUYER</button>
                            <button onClick={() => scrollToSection('seller')} className="hover:text-[#E00D42] transition">SELLER</button>
                            <button onClick={() => scrollToSection('courier')} className="hover:text-[#E00D42] transition">COURIER</button>
                            <button onClick={() => scrollToSection('admin')} className="hover:text-[#E00D42] transition">ADMIN</button>
                            <button onClick={() => scrollToSection('departments')} className="hover:text-[#E00D42] transition">DEPARTMENTS</button>
                        </div>
                    </div>

                    {/* BAGOO + SHOP stacked (Centered below xl when cards wrap below, Left-aligned on xl+ desktop) */}
                    <div className="relative z-20 my-auto py-4 sm:py-6 xl:py-0 flex flex-col items-center xl:items-start text-center xl:text-left w-full space-y-1 sm:space-y-2 xl:space-y-0">
                        {/* BAGOO (Left-to-Right Entrance) */}
                        <div 
                            className={`select-none pointer-events-none transition-all duration-1000 ease-[cubic-bezier(0.16,1,0.3,1)] ${
                                isLoaded ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-32'
                            }`}
                        >
                            <h1 className="text-[16vw] sm:text-[13vw] xl:text-[12.5vw] leading-[0.84] font-black tracking-tighter text-black flex items-center justify-center xl:justify-start drop-shadow-xs">
                                <span>BA</span>
                                <span className="text-[#E00D42]">GO</span>
                                <span>O</span>
                            </h1>
                        </div>

                        {/* SHOP (Same font size as BAGOO with staggered Left-to-Right Entrance) */}
                        <div 
                            className={`select-none pointer-events-none transition-all duration-1000 delay-200 ease-[cubic-bezier(0.16,1,0.3,1)] ${
                                isLoaded ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-32'
                            }`}
                        >
                            <h2 className="text-[16vw] sm:text-[13vw] xl:text-[12.5vw] leading-[0.84] font-black tracking-tighter text-black flex items-center justify-center xl:justify-start drop-shadow-xs">
                                SHOP
                            </h2>
                        </div>
                    </div>

                    {/* STACKED & LEANING VIDEO CARDS (Centered below xl, Expanded Cinematic Size on xl+ side-by-side) */}
                    <div className="relative xl:absolute xl:top-[20%] 2xl:top-[18%] xl:right-10 2xl:right-20 z-30 my-8 sm:my-10 xl:my-0 flex justify-center xl:block pointer-events-auto">
                        <div className="relative w-56 sm:w-64 xl:w-80 2xl:w-96 h-68 sm:h-80 xl:h-[420px] 2xl:h-[480px] group cursor-pointer">
                            
                            {/* Card 1: Back Leaning / Peeking Card (store-shopping-1 peeking out prominently to the left) */}
                            <div className="absolute inset-0 rounded-3xl bg-slate-900 border-2 border-black shadow-2xl p-2.5 sm:p-3 xl:p-4 flex flex-col justify-between overflow-hidden transform -rotate-12 -translate-x-10 sm:-translate-x-14 xl:-translate-x-20 2xl:-translate-x-24 -translate-y-4 sm:-translate-y-6 xl:-translate-y-10 2xl:-translate-y-12 group-hover:-rotate-16 group-hover:-translate-x-28 group-hover:-translate-y-14 transition-all duration-500 ease-out z-10">
                                <div className="flex items-center justify-between font-mono text-[8px] sm:text-[9px] xl:text-[11px] text-slate-300 px-1 pb-1 z-10">
                                    <span className="font-bold text-white">BAGOO // REEL 01</span>
                                    <span className="w-1.5 xl:w-2 h-1.5 xl:h-2 rounded-full bg-emerald-400 animate-ping"></span>
                                </div>
                                <div className="relative flex-1 rounded-2xl overflow-hidden bg-black">
                                    <video
                                        autoPlay
                                        loop
                                        muted
                                        playsInline
                                        className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                                    >
                                        <source src="/videos/store-shopping-1.webm" type="video/webm" />
                                    </video>
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent"></div>
                                    <div className="absolute bottom-2 xl:bottom-3 left-2 xl:left-3 right-2 xl:right-3 font-mono text-[8px] sm:text-[9px] xl:text-[11px] text-white flex justify-between items-center">
                                        <span className="px-1.5 xl:px-2 py-0.5 rounded bg-[#E00D42] font-black text-[7px] sm:text-[8px] xl:text-[10px] uppercase">DISPATCH REEL</span>
                                        <span className="text-emerald-400 text-[7px] sm:text-[8px] xl:text-[10px] font-bold">● ACTIVE</span>
                                    </div>
                                </div>
                            </div>

                            {/* Card 2: Front Elevated Card (store-shopping-2 on top) */}
                            <div className="absolute inset-0 rounded-3xl bg-white border-2 border-black shadow-2xl p-2.5 sm:p-3 xl:p-4 flex flex-col justify-between overflow-hidden transform rotate-3 translate-x-2 translate-y-2 xl:translate-x-3 xl:translate-y-3 group-hover:rotate-0 group-hover:scale-105 transition-all duration-500 ease-out z-20">
                                <div className="flex items-center justify-between font-mono text-[8px] sm:text-[9px] xl:text-[11px] text-slate-600 px-1 pb-1 z-10">
                                    <span className="font-black text-slate-900 flex items-center gap-1.5">
                                        <span className="w-1.5 sm:w-2 xl:w-2.5 h-1.5 sm:h-2 xl:h-2.5 rounded-full bg-[#E00D42]"></span>
                                        BAGOO MARKETPLACE
                                    </span>
                                    <span className="px-1.5 xl:px-2 py-0.2 rounded bg-slate-100 font-bold text-slate-700">4K LIVE</span>
                                </div>
                                <div className="relative flex-1 rounded-2xl overflow-hidden bg-slate-900">
                                    <video
                                        autoPlay
                                        loop
                                        muted
                                        playsInline
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                                    >
                                        <source src="/videos/store-shopping-2.webm" type="video/webm" />
                                    </video>
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent"></div>
                                    <div className="absolute bottom-2.5 xl:bottom-3.5 left-2.5 xl:left-3.5 right-2.5 xl:right-3.5 flex items-center justify-between text-white font-mono text-[8px] sm:text-[9px] xl:text-[11px]">
                                        <span className="font-bold tracking-wider">ECOSYSTEM REEL</span>
                                        <span className="text-emerald-400 font-bold">● LIVE</span>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>

                    {/* METADATA BAR (below COMMERCE, pinned to bottom) */}
                    <div className="relative z-20 pt-6">
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
                    </div>
                </section>

                {/* SECTION 02: BUYER PORTAL (DARK MODE WITH CENTERED 3D SHOPPING BAG SHOWCASE) */}
                <section 
                    id="buyer" 
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#0A0D14] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_01 // BUYER]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Buyer Experience & 3D Interactive Telemetry
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">PSGC CASCADING & TELEMETRY</span>
                        </div>

                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">
                            
                            {/* 3D Interactive Shopping Bag Showcase (Centered & Container-Hover Only) */}
                            <div className="lg:col-span-6 space-y-4">
                                <div className="relative rounded-2xl overflow-hidden border border-white/20 aspect-[4/3] bg-gradient-to-b from-[#141824] to-[#0A0D14] shadow-2xl flex items-center justify-center group">
                                    
                                    {/* 3D Shopping Bag Canvas (Centered vertically & horizontally) */}
                                    <div className="w-full h-full flex items-center justify-center p-2">
                                        <div className="w-[320px] h-[320px] sm:w-[400px] sm:h-[400px] lg:w-[460px] lg:h-[460px]">
                                            <ThreeShoppingBag />
                                        </div>
                                    </div>

                                    {/* Floating Live Telemetry Overlay Card */}
                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2.5 shadow-2xl pointer-events-none">
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

                                <div className="pt-2 font-mono flex flex-wrap items-center gap-3">
                                    <Link
                                        href={route('products.index')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider"
                                    >
                                        <span>Shop 14 Departments</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 px-5 py-3 bg-white/10 hover:bg-white/20 text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase tracking-wider"
                                    >
                                        <span>Create Buyer Account</span>
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {/* SECTION 03: SELLER CENTER (LIGHT MODE) */}
                <section 
                    id="seller" 
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#F3F0EA] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
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
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#070A10] text-white border-b border-white/10 transition-colors duration-700 font-mono"
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
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#05070B] text-white border-b border-white/10 transition-colors duration-700 font-mono"
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
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#ECEAE5] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
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

                        {/* 14 Department Cards Grid */}
                        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
                            {categories.map((cat, idx) => {
                                const num = String(idx + 1).padStart(2, '0');
                                return (
                                    <div
                                        key={cat.id}
                                        className="p-4 bg-white rounded-xl border border-black/10 flex flex-col justify-between h-28 hover:border-black transition shadow-xs"
                                    >
                                        <div className="flex items-center justify-between text-[10px]">
                                            <span className="font-bold text-[#E00D42]">[{num}/14]</span>
                                            <span className="text-black/40 uppercase text-[9px]">VERIFIED</span>
                                        </div>
                                        <div>
                                            <h5 className="font-black text-xs text-black font-sans uppercase line-clamp-2">
                                                {cat.name}
                                            </h5>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        {/* Bottom Action Footer Bar */}
                        <div className="bg-black text-white p-6 rounded-xl flex flex-col sm:flex-row items-center justify-between gap-6 font-mono">
                            <div className="flex items-center gap-3 text-xs">
                                <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                                <span className="font-bold text-white uppercase">READY TO EXPERIENCE MULTI-ROLE COMMERCE?</span>
                            </div>

                            <div className="flex items-center gap-3">
                                <Link
                                    href={route('register')}
                                    className="px-5 py-2.5 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase"
                                >
                                    Register Now
                                </Link>
                                <Link
                                    href={route('login')}
                                    className="px-5 py-2.5 bg-white/10 hover:bg-white hover:text-black text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase"
                                >
                                    Sign In
                                </Link>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </MarketplaceLayout>
    );
}
