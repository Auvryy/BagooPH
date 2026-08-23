import React, { useState, useEffect } from 'react';
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
    CreditCard, 
    Layers, 
    FileText, 
    TrendingUp, 
    Zap, 
    Lock, 
    UserCheck, 
    Package, 
    MapPin, 
    MessageSquare, 
    Star, 
    Clock,
    ChevronDown,
    ExternalLink,
    CheckCircle2,
    DollarSign,
    QrCode,
    SlidersHorizontal,
    Box
} from 'lucide-react';

interface Props {
    categories: Category[];
}

const SECTIONS = [
    { id: 'hero', name: 'OVERVIEW', num: '01' },
    { id: 'buyer', name: 'BUYER PORTAL', num: '02' },
    { id: 'seller', name: 'SELLER STUDIO', num: '03' },
    { id: 'courier', name: 'COURIER DISPATCH', num: '04' },
    { id: 'admin', name: 'ADMIN GOVERNANCE', num: '05' },
    { id: 'departments', name: '14 DEPARTMENTS', num: '06' },
];

export default function MarketplaceIndex({ categories }: Props) {
    const [scrollY, setScrollY] = useState(0);
    const [currentTime, setCurrentTime] = useState('12:00 PM');
    const [activeSection, setActiveSection] = useState('hero');

    useEffect(() => {
        const updateTime = () => {
            const now = new Date();
            setCurrentTime(now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        };
        updateTime();
        const timer = setInterval(updateTime, 1000);

        const handleScroll = () => {
            setScrollY(window.scrollY);

            // Scroll-spy to determine active section for the right-side elevator card
            const sectionOffsets = SECTIONS.map(s => {
                const el = document.getElementById(s.id);
                if (!el) return { id: s.id, top: 0, bottom: 0 };
                const rect = el.getBoundingClientRect();
                return {
                    id: s.id,
                    top: rect.top,
                    bottom: rect.bottom
                };
            });

            const current = sectionOffsets.find(s => s.top <= window.innerHeight * 0.45 && s.bottom >= window.innerHeight * 0.25);
            if (current) {
                setActiveSection(current.id);
            }
        };

        window.addEventListener('scroll', handleScroll, { passive: true });

        return () => {
            clearInterval(timer);
            window.removeEventListener('scroll', handleScroll);
        };
    }, []);

    const scrollToSection = (id: string) => {
        const el = document.getElementById(id);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth' });
        }
    };

    // Parallax scroll calculations for floating typography
    const topTextOffset = scrollY * 0.32;
    const bottomTextOffset = scrollY * -0.20;

    const currentSectionObj = SECTIONS.find(s => s.id === activeSection) || SECTIONS[0];

    return (
        <MarketplaceLayout>
            <Head title="BagooPH — A New Standard in Multi-Role E-Commerce" />

            {/* Grain & Noise Film Overlay */}
            <GrainOverlay />

            {/* FLOATING RIGHT-SIDE SECTION ELEVATOR CARD */}
            <div className="fixed right-4 sm:right-8 top-1/2 -translate-y-1/2 z-50 pointer-events-auto hidden md:block">
                <div className="bg-black/90 text-white backdrop-blur-xl border border-white/20 rounded-2xl p-3.5 shadow-2xl font-mono text-xs w-48 space-y-3">
                    <div className="flex items-center justify-between border-b border-white/15 pb-2 text-[10px] text-white/50 tracking-wider">
                        <span>NAV_ELEVATOR</span>
                        <span className="text-[#E00D42] font-bold">{currentSectionObj.num} / 06</span>
                    </div>

                    {/* Section Item Stack */}
                    <div className="space-y-1">
                        {SECTIONS.map((sec) => {
                            const isCurrent = activeSection === sec.id;
                            return (
                                <button
                                    key={sec.id}
                                    onClick={() => scrollToSection(sec.id)}
                                    className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded-lg text-left transition-all duration-200 ${
                                        isCurrent 
                                            ? 'bg-[#E00D42] text-white font-bold shadow-md scale-[1.02]' 
                                            : 'text-white/60 hover:text-white hover:bg-white/10'
                                    }`}
                                >
                                    <span className="truncate text-[11px] uppercase tracking-wide">
                                        {sec.name}
                                    </span>
                                    <span className={`text-[9px] ${isCurrent ? 'text-white' : 'text-white/40'}`}>
                                        [{sec.num}]
                                    </span>
                                </button>
                            );
                        })}
                    </div>

                    <div className="pt-1 text-[9px] text-center text-white/40 border-t border-white/10">
                        SCROLL TO NAVIGATE
                    </div>
                </div>
            </div>

            <div className="relative min-h-screen overflow-x-hidden font-sans selection:bg-[#E00D42] selection:text-white">
                
                {/* 1. SECTION: HERO (LIGHT MODE) */}
                <section 
                    id="hero" 
                    className="relative min-h-[92vh] flex flex-col justify-between p-4 sm:p-8 lg:p-12 border-b border-black/15 bg-[#ECEAE5] text-[#111111] overflow-hidden"
                >
                    {/* Crosshair Grid Markers */}
                    <span className="absolute top-4 left-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute top-4 right-4 text-black/40 font-mono text-xs select-none">+</span>
                    <span className="absolute bottom-4 left-4 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 left-1/2 -translate-x-1/2 text-black/40 font-mono text-xs select-none z-30">+</span>
                    <span className="absolute bottom-4 right-4 text-black/40 font-mono text-xs select-none z-30">+</span>

                    {/* Top Minimal Info Line */}
                    <div className="flex justify-between items-center z-30 font-mono text-[11px] uppercase tracking-wider text-black/70">
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
                        </div>
                    </div>

                    {/* 3D BAG AT THE BACK OF BAGOO NAME (z-0 / z-5) */}
                    <div className="absolute inset-0 flex items-center justify-center z-0 pointer-events-auto">
                        <div className="w-[340px] h-[340px] sm:w-[520px] sm:h-[520px] lg:w-[680px] lg:h-[680px]">
                            <ThreeShoppingBag />
                        </div>
                    </div>

                    {/* Massive Monumental Top Typography: BAGOO (z-20, sits above the 3D Bag) */}
                    <div 
                        className="relative z-20 select-none pointer-events-none mt-2 sm:mt-4 transition-transform duration-75 ease-out"
                        style={{ transform: `translateY(-${topTextOffset}px)` }}
                    >
                        <h1 className="text-[17vw] leading-[0.8] font-black tracking-tighter text-black flex items-center drop-shadow-sm">
                            <span>B</span>
                            <span className="relative inline-flex items-center justify-center">
                                A
                                {/* Accent Crimson Dot inside character */}
                                <span className="absolute top-[32%] left-[48%] -translate-x-1/2 -translate-y-1/2 w-[3.2vw] h-[3.2vw] rounded-full bg-[#E00D42] shadow-sm animate-pulse"></span>
                            </span>
                            <span>GOO</span>
                        </h1>
                    </div>

                    {/* Mid Right Action Tag */}
                    <div className="hidden lg:flex absolute right-12 top-1/2 -translate-y-1/2 z-30 flex-col items-end gap-3 font-mono text-xs">
                        <button 
                            onClick={() => scrollToSection('buyer')}
                            className="group flex items-center gap-3 px-4 py-2.5 bg-black/5 hover:bg-black hover:text-white rounded-lg border border-black/15 backdrop-blur-md transition duration-200"
                        >
                            <span className="w-0 h-0 border-y-4 border-y-transparent border-l-6 border-l-[#E00D42] group-hover:border-l-white"></span>
                            <span className="tracking-widest uppercase font-bold text-[11px]">SCROLL FOR ROLE FEATURES</span>
                        </button>
                        <span className="text-[10px] text-black/50 tracking-wider uppercase">DRAG 3D MODEL BEHIND BRAND</span>
                    </div>

                    {/* Bottom Metadata & Lower Massive Typography */}
                    <div className="relative z-20 space-y-4 pt-6">
                        {/* Meta Data Block */}
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

                        {/* Massive Monumental Bottom Typography: COMMERCE */}
                        <div 
                            className="select-none pointer-events-none transition-transform duration-75 ease-out"
                            style={{ transform: `translateY(${bottomTextOffset}px)` }}
                        >
                            <h2 className="text-[17vw] leading-[0.8] font-black tracking-tighter text-black">
                                COMMERCE
                            </h2>
                        </div>
                    </div>
                </section>

                {/* 2. SECTION: BUYER EXPERIENCE (DARK MODE) */}
                <section 
                    id="buyer" 
                    className="relative py-24 px-4 sm:px-8 lg:px-12 bg-[#0A0D14] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto space-y-16">
                        {/* Section Header */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_01 // BUYER]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Buyer Portal & Commerce Flow
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">PSGC CASCADING ADDRESS & DISPATCH</span>
                        </div>

                        {/* Content Grid with Visual Imagery & UI Mocks */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                            {/* Left: Imagery & Live UI Mockup */}
                            <div className="lg:col-span-6 space-y-6">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1544816155-12df9643f363?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Modern Buyer Shopping Experience" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/30 to-transparent"></div>

                                    {/* Floating Buyer UI Telemetry Card */}
                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/80 backdrop-blur-md border border-white/20 text-xs font-mono space-y-3">
                                        <div className="flex justify-between items-center border-b border-white/15 pb-2">
                                            <span className="text-white/60">ORDER #BGO-98124</span>
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

                            {/* Right: Feature Bullet List */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    ZERO-FRICTION BUYER JOURNEY
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-white tracking-tight">
                                    Authentic Stores. Precision Logistics.
                                </h4>
                                <p className="text-xs sm:text-sm text-white/70 font-mono leading-relaxed uppercase">
                                    EVERY BUYER BENEFITS FROM VERIFIED SELLER AUTHENTICITY, 14 MASTER CATEGORIES, MULTI-TIER VARIATION SELECTION (COLOR/SIZE), AND LIVE DISPATCH TELEMETRY.
                                </p>

                                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 font-sans">
                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1.5">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Cascading PSGC Address</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Province ➔ Municipality ➔ Barangay ➔ Street automated address selector.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1.5">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Flexible Multi-Payment</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Cash on Delivery (COD), Card checkout, and Direct Bank Transfer.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1.5">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Variation Selector</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Seamless attributes selection for apparel sizes, colorways, and specs.</p>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white/5 border border-white/10 space-y-1.5">
                                        <div className="flex items-center gap-2 text-xs font-bold text-white">
                                            <Check className="w-3.5 h-3.5 text-[#E00D42]" />
                                            <span>Verified Reviews</span>
                                        </div>
                                        <p className="text-[11px] text-white/60">Post-delivery star ratings, text reviews, and direct merchant chat.</p>
                                    </div>
                                </div>

                                <div className="pt-4 font-mono">
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

                {/* 3. SECTION: SELLER CENTER (LIGHT MODE) */}
                <section 
                    id="seller" 
                    className="relative py-24 px-4 sm:px-8 lg:px-12 bg-[#F3F0EA] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto space-y-16">
                        {/* Section Header */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-black/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_02 // MERCHANT]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-black mt-1 font-sans">
                                    Seller Center & Merchant Studio
                                </h3>
                            </div>
                            <span className="text-xs text-black/50 uppercase">INVENTORY, WAYBILLS & PROFIT LEDGER</span>
                        </div>

                        {/* Content Grid */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                            {/* Left: Feature Details */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <span className="text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-[#E00D42]/15 text-[#E00D42] border border-[#E00D42]/30">
                                    MERCHANT POWER TOOLS
                                </span>
                                <h4 className="text-2xl sm:text-3xl font-black text-black tracking-tight">
                                    Scale Your Store with Complete Clarity
                                </h4>
                                <p className="text-xs sm:text-sm text-black/70 font-mono leading-relaxed uppercase">
                                    VERIFIED SELLERS MANAGE MULTI-IMAGE PRODUCT CATALOGS, PRINT BARCODED WAYBILLS WITH ONE CLICK, AND ANALYZE STORE FINANCIALS WITH CUSTOM DATE RANGE FILTERS.
                                </p>

                                <div className="space-y-3 font-sans">
                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <FileText className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">Printable Shipping Waybills</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Automated standard package label with scannable barcode ready for courier pickup.</p>
                                        </div>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <TrendingUp className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">Date-Filtered Profit Reports</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Filter from date to date with gross sales, unit volume, and exact 10% platform commission breakdown.</p>
                                        </div>
                                    </div>

                                    <div className="p-4 rounded-xl bg-white border border-black/10 flex items-start gap-3">
                                        <div className="w-8 h-8 rounded-lg bg-[#E00D42]/15 text-[#E00D42] flex items-center justify-center shrink-0">
                                            <Store className="w-4 h-4" />
                                        </div>
                                        <div>
                                            <h5 className="text-xs font-bold text-black">KYC Business Verification</h5>
                                            <p className="text-xs text-black/60 mt-0.5">Admin-reviewed business permits and ID verification granting verified badge status.</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="pt-4 font-mono">
                                    <Link
                                        href={route('register')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-black hover:bg-[#E00D42] active:scale-[0.98] text-white font-bold text-xs rounded-lg shadow-sm transition uppercase tracking-wider"
                                    >
                                        <span>Open Seller Store</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>

                            {/* Right: Imagery & Financial Waybill UI Mockup */}
                            <div className="lg:col-span-6 space-y-6">
                                <div className="relative rounded-2xl overflow-hidden border border-black/15 aspect-[4/3] group shadow-xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Merchant Studio & Logistics Warehouse" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/20 to-transparent"></div>

                                    {/* Mock Printable Waybill Card Overlay */}
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

                {/* 4. SECTION: COURIER DISPATCH (DARK MODE) */}
                <section 
                    id="courier" 
                    className="relative py-24 px-4 sm:px-8 lg:px-12 bg-[#070A10] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto space-y-16">
                        {/* Section Header */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_03 // COURIER]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Courier Logistics & First-Come Dispatch
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">FIRST-COME DISPATCH ENGINE</span>
                        </div>

                        {/* Content Grid */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                            {/* Left: Imagery & Dispatch Route Mockup */}
                            <div className="lg:col-span-6 space-y-6">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1616401784845-180882ba9ba8?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Urban Courier Dispatch Rider" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/20 to-transparent"></div>

                                    {/* Real-time Dispatch Job Card */}
                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2">
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

                            {/* Right: Feature Bullet List */}
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

                                <div className="pt-4 font-mono">
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

                {/* 5. SECTION: ADMIN GOVERNANCE (DEEP MIDNIGHT DARK MODE) */}
                <section 
                    id="admin" 
                    className="relative py-24 px-4 sm:px-8 lg:px-12 bg-[#05070B] text-white border-b border-white/10 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto space-y-16">
                        {/* Section Header */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[MODULE_04 // GOVERNANCE]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    Admin Governance & 10% Platform Engine
                                </h3>
                            </div>
                            <span className="text-xs text-white/40 uppercase">KYC QUEUE & MEDIATION DESK</span>
                        </div>

                        {/* Content Grid */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                            {/* Left: Feature Details */}
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

                                <div className="pt-4 font-mono">
                                    <Link
                                        href={route('login')}
                                        className="inline-flex items-center gap-2 px-6 py-3 bg-white/10 hover:bg-white hover:text-black active:scale-[0.98] text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase tracking-wider"
                                    >
                                        <span>Sign In to Admin Portal</span>
                                        <ArrowRight className="w-4 h-4" />
                                    </Link>
                                </div>
                            </div>

                            {/* Right: Analytics Dashboard Imagery & KYC Queue Mockup */}
                            <div className="lg:col-span-6 space-y-6">
                                <div className="relative rounded-2xl overflow-hidden border border-white/15 aspect-[4/3] group shadow-2xl">
                                    <img 
                                        src="https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80" 
                                        alt="Admin Data & Governance Dashboard" 
                                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700 brightness-90"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/20 to-transparent"></div>

                                    {/* Mock KYC Queue Overlay */}
                                    <div className="absolute bottom-6 left-6 right-6 p-4 rounded-xl bg-black/85 backdrop-blur-md border border-white/20 text-xs font-mono space-y-2">
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

                {/* 6. SECTION: 14 MASTER DEPARTMENTS (LIGHT MODE) */}
                <section 
                    id="departments" 
                    className="relative py-24 px-4 sm:px-8 lg:px-12 bg-[#ECEAE5] text-[#111111] border-b border-black/15 transition-colors duration-700 font-mono"
                >
                    <div className="max-w-7xl mx-auto space-y-14">
                        {/* Section Header */}
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

                {/* 7. CALL TO ACTION FOOTER BANNER */}
                <section className="bg-black text-white py-20 px-4 sm:px-8 font-mono border-t border-black">
                    <div className="max-w-7xl mx-auto flex flex-col md:flex-row items-center justify-between gap-8">
                        <div className="space-y-3 text-center md:text-left font-sans">
                            <span className="text-xs font-mono uppercase tracking-widest text-[#E00D42]">
                                ✦ JOIN THE BAGOO-PH NETWORK
                            </span>
                            <h4 className="text-3xl sm:text-4xl font-black tracking-tight">
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
