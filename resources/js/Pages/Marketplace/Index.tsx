import React, { useState, useEffect, useRef } from 'react';
import { Head, Link } from '@inertiajs/react';
import MarketplaceLayout from '@/Layouts/MarketplaceLayout';
import { Category, Product, Shop } from '@/types';
import GrainOverlay from '@/Components/GrainOverlay';
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
    MapPin,
    ShoppingBag,
    Package,
    Sparkles,
    Lock,
    Award,
    Star,
    Layers,
    Clock,
    ChevronRight
} from 'lucide-react';

interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

interface Props {
    categories: Category[];
    products?: PaginatedData<Product>;
    featuredShops?: Shop[];
}

const SECTIONS = [
    { id: 'hero', name: 'OVERVIEW', num: '01', theme: 'light' },
    { id: 'value-props', name: 'CORE PILLARS', num: '02', theme: 'light' },
    { id: 'ecosystem', name: '4-STAGE ECOSYSTEM', num: '03', theme: 'dark' },
    { id: 'catalog', name: 'CATEGORY SHOWCASE', num: '04', theme: 'light' },
    { id: 'onboarding', name: 'ROLE ACCESS', num: '05', theme: 'dark' },
] as const;

// Curated Showcase Products (Fallback / Initial Showcase for Category Tabs)
const CURATED_SHOWCASE = [
    {
        id: 101,
        name: 'Bagoo Urban Nomad Tactical Duffel 45L',
        category: 'Tactical & Bags',
        category_slug: 'tactical-bags',
        price: '₱2,850',
        original_price: '₱3,450',
        rating: '4.9',
        sales: '1,420',
        shop: 'Bagoo Tactical Labs',
        image: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?auto=format&fit=crop&w=800&q=80',
        badge: 'BESTSELLER'
    },
    {
        id: 102,
        name: 'Bagoo AeroPro Wireless ANC Headphones',
        category: 'Electronics',
        category_slug: 'electronics',
        price: '₱4,290',
        original_price: '₱5,200',
        rating: '4.8',
        sales: '980',
        shop: 'Bagoo Sound Studio',
        image: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?auto=format&fit=crop&w=800&q=80',
        badge: 'NEW RELEASE'
    },
    {
        id: 103,
        name: 'Bagoo HyperStrides Performance Runners',
        category: 'Footwear',
        category_slug: 'footwear',
        price: '₱3,190',
        original_price: '₱3,890',
        rating: '4.9',
        sales: '2,150',
        shop: 'Aero Athletics Store',
        image: 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=800&q=80',
        badge: 'HOT DEAL'
    },
    {
        id: 104,
        name: 'Bagoo Precision Chronograph Watch',
        category: 'Accessories',
        category_slug: 'accessories',
        price: '₱5,450',
        original_price: '₱6,800',
        rating: '5.0',
        sales: '640',
        shop: 'Vanguard Horology',
        image: 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?auto=format&fit=crop&w=800&q=80',
        badge: 'FEATURED'
    },
    {
        id: 105,
        name: 'Bagoo Heavyweight Oversized Cotton Tee',
        category: 'Apparel',
        category_slug: 'apparel',
        price: '₱890',
        original_price: '₱1,150',
        rating: '4.7',
        sales: '3,800',
        shop: 'Kuro Studio Manila',
        image: 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=800&q=80',
        badge: 'POPULAR'
    },
    {
        id: 106,
        name: 'Bagoo Matte Ceramic Daily Travel Tumbler 750ml',
        category: 'Home & Living',
        category_slug: 'home-living',
        price: '₱1,150',
        original_price: '₱1,400',
        rating: '4.9',
        sales: '1,920',
        shop: 'Nordic Living PH',
        image: 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?auto=format&fit=crop&w=800&q=80',
        badge: 'VERIFIED'
    }
];

export default function MarketplaceIndex({ categories = [], products, featuredShops = [] }: Props) {
    const [currentTime, setCurrentTime] = useState('12:00 PM');
    const [activeSectionId, setActiveSectionId] = useState<string>('hero');
    const [isLoaded, setIsLoaded] = useState<boolean>(false);
    
    // Interactive 4-Stage Ecosystem State
    const [activeStep, setActiveStep] = useState<number>(0);
    const [isAutoPlaying, setIsAutoPlaying] = useState<boolean>(true);

    // Interactive Category Tabs State
    const [selectedCategoryTab, setSelectedCategoryTab] = useState<string>('all');

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

    // Ecosystem Auto-cycle timer
    useEffect(() => {
        if (!isAutoPlaying) return;
        const interval = setInterval(() => {
            setActiveStep((prev) => (prev + 1) % 4);
        }, 4000);
        return () => clearInterval(interval);
    }, [isAutoPlaying]);

    const scrollToSection = (id: string) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth' });
    };

    const currentSection = SECTIONS.find(s => s.id === activeSectionId) || SECTIONS[0];
    const isDarkHeader = currentSection.theme === 'dark';

    // Ecosystem Stages Definition
    const ECOSYSTEM_STEPS = [
        {
            num: '01',
            role: 'BUYER',
            title: 'Frictionless Order Placement',
            summary: 'Buyers select variations (color/size), apply platform vouchers, and enter addresses via cascading PSGC dropdowns with zero guesswork.',
            badge: 'PSGC CASCADING & COD',
            color: '#E00D42',
            icon: ShoppingBag,
            metric: '14 Verified Departments',
            details: [
                'Automatic province ➔ municipality ➔ barangay address selector',
                'Multi-tier size/color variations with real-time stock locks',
                'Flexible checkout: Cash on Delivery (COD), Card, or Wallet'
            ],
            telemetry: {
                title: 'LIVE ORDER CHECKOUT',
                status: 'ORDER_PLACED',
                timestamp: 'JUST NOW',
                value: '₱3,190.00'
            }
        },
        {
            num: '02',
            role: 'SELLER',
            title: 'Merchant Packing & 1-Click Waybills',
            summary: 'Approved merchants receive instant order alerts in their Bento Cockpit, pack items, and generate printable thermal waybills with barcodes.',
            badge: 'THERMAL LABELS & INVENTORY',
            color: '#111111',
            icon: Store,
            metric: 'Under 45-Min Packing',
            details: [
                'Bento Matrix command center with inventory tracking',
                '1-Click thermal shipping label & barcode generator',
                'Instant status broadcast: "Ready for Courier Pickup"'
            ],
            telemetry: {
                title: 'WAYBILL GENERATED',
                status: 'READY_FOR_PICKUP',
                timestamp: '1 MIN AGO',
                value: 'TRACK: BGO-892401'
            }
        },
        {
            num: '03',
            role: 'COURIER',
            title: 'First-Come Dispatch & Fast Delivery',
            summary: 'Available Bagoo Express riders claim parcels in the open pool (FCFS), navigate turn-by-turn routes, and capture doorstep dropoff proof.',
            badge: 'BAGOO EXPRESS FLEET',
            color: '#059669',
            icon: Truck,
            metric: '₱50 / ₱80 Guaranteed Fare',
            details: [
                'First-Come, First-Served unassigned job board',
                'Sorting hub check-in & milestone tracking (Shipped ➔ Out for Delivery)',
                'Digital proof-of-delivery upload & automatic fare settlement'
            ],
            telemetry: {
                title: 'DISPATCH TRANSIT',
                status: 'OUT_FOR_DELIVERY',
                timestamp: 'ACTIVE ROUTE',
                value: 'ETA: 22 MINS'
            }
        },
        {
            num: '04',
            role: 'ADMIN',
            title: 'Platform Governance & 10% Ledger',
            summary: 'Supervisors audit merchant KYC permits, oversee sorting center parcel flows, resolve disputes, and maintain the 10% commission ledger.',
            badge: 'KYC AUDIT & 10% LEDGER',
            color: '#2563EB',
            icon: ShieldCheck,
            metric: '100% Audited Accounts',
            details: [
                'Admin document inspection queue for IDs and business permits',
                'Automated 10% platform commission accounting calculation',
                'Tripartite mediation desk for buyer-seller dispute arbitration'
            ],
            telemetry: {
                title: 'COMMISSION SETTLEMENT',
                status: 'SETTLED_AUDITED',
                timestamp: 'COMPLETED',
                value: '10% PLATFORM FEE'
            }
        }
    ];

    // Filter showcase products based on active category tab
    const filteredShowcase = selectedCategoryTab === 'all' 
        ? CURATED_SHOWCASE 
        : CURATED_SHOWCASE.filter(p => p.category_slug === selectedCategoryTab);

    return (
        <MarketplaceLayout headerTheme={isDarkHeader ? 'dark' : 'light'}>
            <Head title="BagooPH — A New Standard in Multi-Role E-Commerce" />

            {/* Jumping Letter Bagoo Intro Loading Screen */}
            <BagooLoadingScreen onComplete={() => setIsLoaded(true)} />

            {/* Grain & Noise Film Overlay */}
            <GrainOverlay />

            {/* MAIN CONTAINER */}
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
                            <span>MULTI-ROLE ECOSYSTEM</span>
                        </div>

                        <div className="hidden sm:flex items-center gap-6 text-black/70 font-medium">
                            <button onClick={() => scrollToSection('value-props')} className="hover:text-[#E00D42] transition">VALUE PILLARS</button>
                            <button onClick={() => scrollToSection('ecosystem')} className="hover:text-[#E00D42] transition">4-STAGE FLOW</button>
                            <button onClick={() => scrollToSection('catalog')} className="hover:text-[#E00D42] transition">SHOWCASE</button>
                            <button onClick={() => scrollToSection('onboarding')} className="hover:text-[#E00D42] transition">ROLES</button>
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

                    {/* STACKED & LEANING VIDEO CARDS */}
                    <div className="relative xl:absolute xl:top-[20%] 2xl:top-[18%] xl:right-10 2xl:right-20 z-30 my-6 sm:my-8 xl:my-0 w-full xl:w-auto flex justify-center items-center shrink-0 pointer-events-auto">
                        <div className="relative w-[260px] sm:w-[320px] xl:w-80 2xl:w-96 h-[340px] sm:h-[400px] xl:h-[420px] 2xl:h-[480px] shrink-0 group cursor-pointer">
                            
                            {/* Card 1: Back Leaning Card */}
                            <div className="absolute inset-0 rounded-3xl bg-slate-900 border-2 border-black shadow-2xl p-3 sm:p-3.5 xl:p-4 flex flex-col justify-between overflow-hidden transform -rotate-12 -translate-x-6 sm:-translate-x-12 xl:-translate-x-20 2xl:-translate-x-24 -translate-y-3 sm:-translate-y-6 xl:-translate-y-10 2xl:-translate-y-12 group-hover:-rotate-16 group-hover:-translate-x-10 sm:group-hover:-translate-x-28 group-hover:-translate-y-6 sm:group-hover:-translate-y-14 transition-all duration-500 ease-out z-10">
                                <div className="flex items-center justify-between font-mono text-[9px] sm:text-[10px] xl:text-[11px] text-slate-300 px-1 pb-1 z-10">
                                    <span className="font-bold text-white">BAGOO // REEL 01</span>
                                    <span className="w-1.5 xl:w-2 h-1.5 xl:h-2 rounded-full bg-emerald-400 animate-ping"></span>
                                </div>
                                <div className="relative flex-1 min-h-[220px] sm:min-h-[260px] xl:min-h-[290px] rounded-2xl overflow-hidden bg-black">
                                    <video
                                        autoPlay
                                        loop
                                        muted
                                        playsInline
                                        preload="auto"
                                        className="w-full h-full object-cover block group-hover:scale-105 transition duration-500"
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

                            {/* Card 2: Front Elevated Card */}
                            <div className="absolute inset-0 rounded-3xl bg-white border-2 border-black shadow-2xl p-3 sm:p-3.5 xl:p-4 flex flex-col justify-between overflow-hidden transform rotate-3 translate-x-1 sm:translate-x-2 translate-y-1 sm:translate-y-2 xl:translate-x-3 xl:translate-y-3 group-hover:rotate-0 group-hover:scale-105 transition-all duration-500 ease-out z-20">
                                <div className="flex items-center justify-between font-mono text-[9px] sm:text-[10px] xl:text-[11px] text-slate-600 px-1 pb-1 z-10">
                                    <span className="font-black text-slate-900 flex items-center gap-1.5">
                                        <span className="w-1.5 sm:w-2 xl:w-2.5 h-1.5 sm:h-2 xl:h-2.5 rounded-full bg-[#E00D42]"></span>
                                        BAGOO MARKETPLACE
                                    </span>
                                    <span className="px-1.5 xl:px-2 py-0.2 rounded bg-slate-100 font-bold text-slate-700">4K LIVE</span>
                                </div>
                                <div className="relative flex-1 min-h-[220px] sm:min-h-[260px] xl:min-h-[290px] rounded-2xl overflow-hidden bg-slate-900">
                                    <video
                                        autoPlay
                                        loop
                                        muted
                                        playsInline
                                        preload="auto"
                                        className="w-full h-full object-cover block group-hover:scale-105 transition-transform duration-700"
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

                    {/* METADATA BAR (pinned to bottom) */}
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
                                    A HIGH-PERFORMANCE MULTI-ROLE COMMERCE PLATFORM. 14 VERIFIED PRODUCT DEPARTMENTS, FIRST-COME COURIER DISPATCH, AND FAIR 10% COMMISSION ACCOUNTING.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>


                {/* SECTION 02: CORE VALUE PROPOSITION & TRUST PILLARS (FULL-WIDTH SPLIT SECTIONS) */}
                <section 
                    id="value-props" 
                    className="relative bg-[#F3F0EA] text-[#111111] border-b border-black/15 transition-colors duration-700"
                >
                    {/* Value Proposition Header */}
                    <div className="max-w-7xl mx-auto px-4 sm:px-8 lg:px-12 pt-20 pb-12 border-b border-black/10">
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest font-mono">[PILLARS // 02]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-black mt-1 font-sans">
                                    Built for Speed, Trust & Reliability
                                </h3>
                            </div>
                            <p className="text-xs text-black/60 font-mono uppercase max-w-md">
                                ENGINEERED TO SOLVE FRICTION ACROSS BUYERS, MERCHANTS, AND DISPATCH RIDERS WITH ZERO FLUFF.
                            </p>
                        </div>
                    </div>

                    {/* Split Block A: Bagoo Express Fast Logistics */}
                    <div className="border-b border-black/10">
                        <div className="max-w-7xl mx-auto px-4 sm:px-8 lg:px-12 py-16 sm:py-24">
                            <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                                {/* Left Image + Floating Telemetry Badge */}
                                <div className="lg:col-span-6 relative">
                                    <div className="relative rounded-2xl overflow-hidden border border-black/15 shadow-xl aspect-[16/10] group">
                                        <img 
                                            src="https://images.unsplash.com/photo-1586528116311-ad8dd3c8310d?auto=format&fit=crop&w=1200&q=80" 
                                            alt="Bagoo Express Courier Fast Logistics" 
                                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                                        
                                        {/* Floating Live Badge */}
                                        <div className="absolute top-4 left-4 px-3 py-1.5 rounded-lg bg-[#E00D42] text-white font-mono text-[10px] font-bold uppercase tracking-wider shadow-md flex items-center gap-2">
                                            <span className="w-2 h-2 rounded-full bg-white animate-pulse"></span>
                                            BAGOO EXPRESS // LIVE DISPATCH
                                        </div>

                                        {/* Bottom Telemetry Card */}
                                        <div className="absolute bottom-4 left-4 right-4 p-4 rounded-xl bg-white/95 backdrop-blur-md border border-black/10 text-black shadow-lg font-mono text-xs">
                                            <div className="flex justify-between items-center pb-1.5 border-b border-black/10">
                                                <span className="font-bold text-[#E00D42]">FCFS DISPATCH POOL</span>
                                                <span className="text-emerald-600 font-bold">● ACTIVE FLEET</span>
                                            </div>
                                            <div className="flex justify-between items-center text-[11px] pt-1.5 font-sans">
                                                <span className="text-black/70">Average Store-to-Doorstep Handover:</span>
                                                <span className="font-bold text-black font-mono">UNDER 45 MINS</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Right Content */}
                                <div className="lg:col-span-6 space-y-6">
                                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-[#E00D42]/10 text-[#E00D42] font-mono text-[11px] font-bold uppercase tracking-wider border border-[#E00D42]/20">
                                        <Truck className="w-3.5 h-3.5" />
                                        <span>PRECISION LOGISTICS</span>
                                    </div>
                                    <h4 className="text-2xl sm:text-4xl font-black text-black tracking-tight font-sans leading-tight">
                                        First-Come Dispatch. Live Route Waypoints.
                                    </h4>
                                    <p className="text-xs sm:text-sm text-black/70 font-mono leading-relaxed uppercase">
                                        PARCELS ARE BROADCASTED DIRECTLY TO THE NEAREST ACTIVE RIDERS WITHOUT CENTRALIZED FAVORITISM. REAL-TIME TELEMETRY TRACKS EVERY MILESTONE FROM STORE PICKUP TO DOORSTEP SIGNATURE.
                                    </p>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-sans pt-2">
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <Check className="w-4 h-4 text-[#E00D42]" />
                                                <span>Zero-Delay Dispatch</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">Instant atomic locking prevents duplicate pickups and speeds up transit.</p>
                                        </div>
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <Check className="w-4 h-4 text-[#E00D42]" />
                                                <span>Proof-of-Dropoff Photo</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">Digital recipient verification and photographic dropoff records for zero disputes.</p>
                                        </div>
                                    </div>

                                    <div className="pt-2">
                                        <Link
                                            href={route('courier.register')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-black hover:bg-[#E00D42] text-white font-bold text-xs rounded-lg transition uppercase tracking-wider font-mono shadow-sm"
                                        >
                                            <span>Join Delivery Fleet</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Split Block B: 100% Verified Stores & Strict KYC */}
                    <div className="border-b border-black/10 bg-[#ECEAE5]">
                        <div className="max-w-7xl mx-auto px-4 sm:px-8 lg:px-12 py-16 sm:py-24">
                            <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                                {/* Left Content */}
                                <div className="lg:col-span-6 space-y-6 order-2 lg:order-1">
                                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-emerald-600/10 text-emerald-700 font-mono text-[11px] font-bold uppercase tracking-wider border border-emerald-600/20">
                                        <ShieldCheck className="w-3.5 h-3.5" />
                                        <span>100% AUTHENTIC STORES</span>
                                    </div>
                                    <h4 className="text-2xl sm:text-4xl font-black text-black tracking-tight font-sans leading-tight">
                                        Vetted Merchants. Strict Business Compliance.
                                    </h4>
                                    <p className="text-xs sm:text-sm text-black/70 font-mono leading-relaxed uppercase">
                                        EVERY SELLER UNDERGOES RIGOROUS ADMIN DOCUMENT VERIFICATION (GOVERNMENT ID, DTI/BIR PERMIT) BEFORE THEIR FIRST PRODUCT IS LISTED. ZERO COUNTERFEITS, ZERO FLY-BY-NIGHT SHOPS.
                                    </p>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-sans pt-2">
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <Award className="w-4 h-4 text-emerald-600" />
                                                <span>Admin KYC Review</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">Manual review of business documentation ensures authentic merchant credibility.</p>
                                        </div>
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <FileText className="w-4 h-4 text-emerald-600" />
                                                <span>1-Click Thermal Labels</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">Direct printable waybills with scannable barcodes ready for pickup.</p>
                                        </div>
                                    </div>

                                    <div className="pt-2">
                                        <Link
                                            href={route('seller.register')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold text-xs rounded-lg transition uppercase tracking-wider font-mono shadow-sm"
                                        >
                                            <span>Open Verified Store</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    </div>
                                </div>

                                {/* Right Image + Verified Stamp Card */}
                                <div className="lg:col-span-6 relative order-1 lg:order-2">
                                    <div className="relative rounded-2xl overflow-hidden border border-black/15 shadow-xl aspect-[16/10] group">
                                        <img 
                                            src="https://images.unsplash.com/photo-1556742049-0a67c5574f73?auto=format&fit=crop&w=1200&q=80" 
                                            alt="Verified Store Studio & Quality Standards" 
                                            className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent"></div>
                                        
                                        {/* Verified Store Stamp Badge */}
                                        <div className="absolute top-4 right-4 px-3.5 py-1.5 rounded-lg bg-emerald-600 text-white font-mono text-[10px] font-black uppercase tracking-widest shadow-md flex items-center gap-2">
                                            <Check className="w-3.5 h-3.5" />
                                            VERIFIED MERCHANT BADGE
                                        </div>

                                        {/* Bottom Telemetry Card */}
                                        <div className="absolute bottom-4 left-4 right-4 p-4 rounded-xl bg-white/95 backdrop-blur-md border border-black/10 text-black shadow-lg font-mono text-xs">
                                            <div className="flex justify-between items-center pb-1.5 border-b border-black/10">
                                                <span className="font-bold text-slate-900">KYC VERIFICATION DESK</span>
                                                <span className="text-emerald-600 font-bold">100% AUDITED ✓</span>
                                            </div>
                                            <div className="flex justify-between items-center text-[11px] pt-1.5 font-sans">
                                                <span className="text-black/70">Category Compliance & Prohibited Item Filter:</span>
                                                <span className="font-bold text-[#E00D42] font-mono">ACTIVE</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Split Block C: Fair & Transparent 10% Economics */}
                    <div>
                        <div className="max-w-7xl mx-auto px-4 sm:px-8 lg:px-12 py-16 sm:py-24">
                            <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                                {/* Left Mockup / Financial Card */}
                                <div className="lg:col-span-6 relative">
                                    <div className="p-6 sm:p-8 rounded-2xl bg-white border border-black/15 shadow-xl space-y-6 font-mono">
                                        <div className="flex justify-between items-center border-b border-black/10 pb-4">
                                            <div>
                                                <span className="text-[10px] text-black/50 font-bold uppercase tracking-widest">TRANSPARENT LEDGER</span>
                                                <h5 className="text-base font-black text-black font-sans mt-0.5">Order Financial Statement</h5>
                                            </div>
                                            <span className="px-2.5 py-1 bg-emerald-100 text-emerald-800 text-[10px] font-black rounded-md">
                                                AUTOMATED
                                            </span>
                                        </div>

                                        <div className="space-y-3 text-xs">
                                            <div className="flex justify-between items-center py-1.5 border-b border-dashed border-black/10">
                                                <span className="text-black/70">Gross Product Sales (COD / Card):</span>
                                                <span className="font-bold text-black">₱3,500.00</span>
                                            </div>
                                            <div className="flex justify-between items-center py-1.5 border-b border-dashed border-black/10 text-emerald-700 font-bold">
                                                <span>Courier Delivery Payout (Guaranteed):</span>
                                                <span>₱80.00</span>
                                            </div>
                                            <div className="flex justify-between items-center py-1.5 border-b border-dashed border-black/10 text-[#E00D42] font-bold">
                                                <span>Platform Commission (Exact 10%):</span>
                                                <span>-₱350.00</span>
                                            </div>
                                            <div className="flex justify-between items-center pt-2 text-sm font-black font-sans">
                                                <span className="text-black">Seller Net Disbursed Profit:</span>
                                                <span className="text-black font-mono">₱3,150.00</span>
                                            </div>
                                        </div>

                                        <div className="p-3.5 rounded-xl bg-[#ECEAE5] border border-black/10 text-[11px] text-black/70 space-y-1 font-sans">
                                            <p className="font-bold text-black flex items-center gap-1.5">
                                                <TrendingUp className="w-3.5 h-3.5 text-[#E00D42]" />
                                                <span>Automated Date-Filtered Profit Reports</span>
                                            </p>
                                            <p className="text-[10px] text-black/60 font-mono">Filter transactions by date range with real-time settlement calculation.</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Right Content */}
                                <div className="lg:col-span-6 space-y-6">
                                    <div className="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-[#E00D42]/10 text-[#E00D42] font-mono text-[11px] font-bold uppercase tracking-wider border border-[#E00D42]/20">
                                        <DollarSign className="w-3.5 h-3.5" />
                                        <span>FAIR & TRANSPARENT</span>
                                    </div>
                                    <h4 className="text-2xl sm:text-4xl font-black text-black tracking-tight font-sans leading-tight">
                                        Predictable Economics. Zero Hidden Surcharges.
                                    </h4>
                                    <p className="text-xs sm:text-sm text-black/70 font-mono leading-relaxed uppercase">
                                        WE KEEP COMMERCE SUSTAINABLE AND TRANSPARENT. MERCHANTS ENJOY AN UNCOMPLICATED 10% FLAT PLATFORM COMMISSION WITH INSTANT REAL-TIME PROFIT BREAKDOWNS, WHILE COURIER RIDERS RECEIVE FULL GUARANTEED TRIP EARNINGS.
                                    </p>

                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 font-sans pt-2">
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <Check className="w-4 h-4 text-[#E00D42]" />
                                                <span>Flat 10% Platform Fee</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">No confusing tiers, listing fees, or surprise deductions on store payout.</p>
                                        </div>
                                        <div className="p-4 rounded-xl bg-white border border-black/10 shadow-xs space-y-1">
                                            <div className="flex items-center gap-2 font-bold text-xs text-black">
                                                <Check className="w-4 h-4 text-[#E00D42]" />
                                                <span>Instant Remittance Audit</span>
                                            </div>
                                            <p className="text-[11px] text-black/60">COD cash collection and bank payouts reconcile automatically in admin console.</p>
                                        </div>
                                    </div>

                                    <div className="pt-2">
                                        <Link
                                            href={route('buyer.index')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-black hover:bg-[#E00D42] text-white font-bold text-xs rounded-lg transition uppercase tracking-wider font-mono shadow-sm"
                                        >
                                            <span>Explore Marketplace</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>


                {/* SECTION 03: INTERACTIVE 4-STAGE ECOSYSTEM SEQUENCER (DARK MODE) */}
                <section 
                    id="ecosystem" 
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#0A0D14] text-white border-b border-white/10 transition-colors duration-700 font-mono overflow-hidden"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-14">
                        {/* Section Title */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-white/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[INTERCONNECTED PIPELINE // 03]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white mt-1 font-sans">
                                    The 4-Stage Ecosystem Sequencer
                                </h3>
                            </div>
                            <div className="flex items-center gap-3">
                                <button 
                                    onClick={() => setIsAutoPlaying(!isAutoPlaying)}
                                    className={`px-3 py-1 text-[10px] font-bold rounded-md border uppercase transition ${
                                        isAutoPlaying 
                                            ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' 
                                            : 'bg-white/10 text-white/60 border-white/20'
                                    }`}
                                >
                                    {isAutoPlaying ? '● AUTO-PLAYING (4S)' : 'PAUSED'}
                                </button>
                            </div>
                        </div>

                        {/* Interactive Step Navigator Tabs */}
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 relative z-20">
                            {ECOSYSTEM_STEPS.map((step, idx) => {
                                const isActive = activeStep === idx;
                                const StepIcon = step.icon;

                                return (
                                    <button
                                        key={step.num}
                                        onClick={() => {
                                            setActiveStep(idx);
                                            setIsAutoPlaying(false);
                                        }}
                                        className={`p-4 sm:p-5 rounded-xl border text-left transition-all duration-300 relative overflow-hidden group ${
                                            isActive
                                                ? 'bg-white/15 border-white/40 shadow-xl scale-[1.02]'
                                                : 'bg-white/5 border-white/10 hover:bg-white/10 hover:border-white/20'
                                        }`}
                                    >
                                        {/* Top Line: Step number and indicator */}
                                        <div className="flex justify-between items-center text-xs pb-3">
                                            <span className={`font-black font-mono ${isActive ? 'text-[#E00D42]' : 'text-white/40'}`}>
                                                STAGE {step.num}
                                            </span>
                                            <div className={`w-2 h-2 rounded-full transition-all ${
                                                isActive ? 'bg-[#E00D42] animate-ping' : 'bg-white/20'
                                            }`} />
                                        </div>

                                        {/* Role Icon & Title */}
                                        <div className="flex items-center gap-2.5 my-1">
                                            <div className={`p-1.5 rounded-lg ${
                                                isActive ? 'bg-[#E00D42] text-white' : 'bg-white/10 text-white/60'
                                            }`}>
                                                <StepIcon className="w-4 h-4" />
                                            </div>
                                            <span className="font-bold text-xs sm:text-sm text-white font-sans">
                                                {step.role}
                                            </span>
                                        </div>

                                        <p className="text-[10px] sm:text-[11px] text-white/60 font-mono mt-1 line-clamp-1">
                                            {step.metric}
                                        </p>

                                        {/* Active Bottom Glow Indicator */}
                                        {isActive && (
                                            <div className="absolute bottom-0 left-0 right-0 h-1 bg-[#E00D42]" />
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Active Step Showcase Body */}
                        <div className="grid grid-cols-1 lg:grid-cols-12 gap-10 items-center pt-4">
                            {/* Left: Deep Step Overview & Feature List */}
                            <div className="lg:col-span-6 space-y-6 font-sans">
                                <div className="inline-flex items-center gap-2 px-3 py-1 rounded-md bg-[#E00D42]/20 text-[#E00D42] font-mono text-[11px] font-bold uppercase tracking-wider border border-[#E00D42]/30">
                                    <span>STAGE {ECOSYSTEM_STEPS[activeStep].num} // {ECOSYSTEM_STEPS[activeStep].badge}</span>
                                </div>

                                <h4 className="text-2xl sm:text-4xl font-black text-white tracking-tight leading-tight">
                                    {ECOSYSTEM_STEPS[activeStep].title}
                                </h4>

                                <p className="text-xs sm:text-sm text-white/70 font-mono leading-relaxed uppercase">
                                    {ECOSYSTEM_STEPS[activeStep].summary}
                                </p>

                                <div className="space-y-3 pt-2">
                                    {ECOSYSTEM_STEPS[activeStep].details.map((detail, dIdx) => (
                                        <div key={dIdx} className="p-3.5 rounded-xl bg-white/5 border border-white/10 flex items-start gap-3">
                                            <div className="w-5 h-5 rounded-full bg-[#E00D42]/20 text-[#E00D42] flex items-center justify-center shrink-0 mt-0.5">
                                                <Check className="w-3 h-3" />
                                            </div>
                                            <p className="text-xs text-white/80 font-sans">
                                                {detail}
                                            </p>
                                        </div>
                                    ))}
                                </div>

                                <div className="pt-2 font-mono flex items-center gap-3">
                                    {activeStep === 0 && (
                                        <Link
                                            href={route('buyer.index')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold text-xs rounded-lg transition uppercase tracking-wider shadow-sm"
                                        >
                                            <span>Shop as Buyer</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    )}
                                    {activeStep === 1 && (
                                        <Link
                                            href={route('seller.register')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-white hover:bg-white/90 text-black font-bold text-xs rounded-lg transition uppercase tracking-wider shadow-sm"
                                        >
                                            <span>Register Merchant Store</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    )}
                                    {activeStep === 2 && (
                                        <Link
                                            href={route('courier.register')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold text-xs rounded-lg transition uppercase tracking-wider shadow-sm"
                                        >
                                            <span>Become Courier Rider</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    )}
                                    {activeStep === 3 && (
                                        <Link
                                            href={route('login')}
                                            className="inline-flex items-center gap-2 px-6 py-3 bg-white/10 hover:bg-white hover:text-black text-white font-bold text-xs rounded-lg border border-white/20 transition uppercase tracking-wider shadow-sm"
                                        >
                                            <span>Admin Operations</span>
                                            <ArrowRight className="w-4 h-4" />
                                        </Link>
                                    )}
                                </div>
                            </div>

                            {/* Right: Dynamic Interactive Telemetry Preview Card */}
                            <div className="lg:col-span-6">
                                <div className="p-6 sm:p-8 rounded-2xl bg-gradient-to-br from-[#121622] to-[#0A0D14] border border-white/20 shadow-2xl space-y-6 relative overflow-hidden group">
                                    <div className="absolute top-0 right-0 w-48 h-48 bg-[#E00D42]/10 rounded-full blur-3xl pointer-events-none" />

                                    <div className="flex justify-between items-center border-b border-white/10 pb-4">
                                        <div className="flex items-center gap-2.5">
                                            <span className="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse" />
                                            <span className="text-xs font-bold text-white tracking-wider font-mono">
                                                {ECOSYSTEM_STEPS[activeStep].telemetry.title}
                                            </span>
                                        </div>
                                        <span className="px-2.5 py-1 rounded bg-white/10 text-[10px] font-bold text-white/80 font-mono">
                                            {ECOSYSTEM_STEPS[activeStep].telemetry.timestamp}
                                        </span>
                                    </div>

                                    {/* Simulated Live Role HUD */}
                                    <div className="space-y-4 font-mono text-xs">
                                        <div className="p-4 rounded-xl bg-black/60 border border-white/10 flex justify-between items-center">
                                            <span className="text-white/60">PIPELINE STATUS:</span>
                                            <span className="font-bold text-[#E00D42]">
                                                {ECOSYSTEM_STEPS[activeStep].telemetry.status}
                                            </span>
                                        </div>

                                        <div className="p-4 rounded-xl bg-black/60 border border-white/10 flex justify-between items-center">
                                            <span className="text-white/60">KEY TELEMETRY VALUE:</span>
                                            <span className="font-bold text-white">
                                                {ECOSYSTEM_STEPS[activeStep].telemetry.value}
                                            </span>
                                        </div>

                                        <div className="p-4 rounded-xl bg-white/5 border border-white/10 text-white/80 space-y-2 text-[11px] font-sans">
                                            <div className="flex items-center justify-between text-white/60 font-mono text-[10px]">
                                                <span>DISPATCH RELIABILITY</span>
                                                <span className="text-emerald-400 font-bold">99.98% SUCCESS</span>
                                            </div>
                                            <div className="w-full h-1.5 rounded-full bg-white/10 overflow-hidden">
                                                <div className="h-full bg-gradient-to-r from-[#E00D42] to-emerald-400 w-[94%]" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>


                {/* SECTION 04: CATEGORY SHOWCASE TABS & RESPONSIVE IMAGE ZOOM (LIGHT MODE) */}
                <section 
                    id="catalog" 
                    className="relative py-24 sm:py-32 px-4 sm:px-8 lg:px-12 bg-[#ECEAE5] text-[#111111] border-b border-black/15 transition-colors duration-700"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        {/* Header */}
                        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-4 pb-6 border-b border-black/15">
                            <div>
                                <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest font-mono">[CATALOG SHOWCASE // 04]</span>
                                <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-black mt-1 font-sans">
                                    Explore Curated Originals
                                </h3>
                            </div>
                            <Link
                                href={route('products.index')}
                                className="inline-flex items-center gap-2 text-xs font-bold text-black hover:text-[#E00D42] font-mono uppercase tracking-wider transition"
                            >
                                <span>Browse All 14 Departments</span>
                                <ArrowRight className="w-4 h-4" />
                            </Link>
                        </div>

                        {/* Interactive Filter Tabs */}
                        <div className="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none font-mono text-xs">
                            <button
                                onClick={() => setSelectedCategoryTab('all')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'all'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                All Originals ({CURATED_SHOWCASE.length})
                            </button>
                            <button
                                onClick={() => setSelectedCategoryTab('tactical-bags')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'tactical-bags'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                Tactical & Bags
                            </button>
                            <button
                                onClick={() => setSelectedCategoryTab('electronics')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'electronics'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                Electronics
                            </button>
                            <button
                                onClick={() => setSelectedCategoryTab('footwear')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'footwear'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                Footwear
                            </button>
                            <button
                                onClick={() => setSelectedCategoryTab('apparel')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'apparel'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                Apparel
                            </button>
                            <button
                                onClick={() => setSelectedCategoryTab('accessories')}
                                className={`px-4 py-2 rounded-lg font-bold transition uppercase tracking-wider shrink-0 ${
                                    selectedCategoryTab === 'accessories'
                                        ? 'bg-black text-white shadow-xs'
                                        : 'bg-white/80 text-black/70 hover:bg-white hover:text-black border border-black/10'
                                }`}
                            >
                                Accessories
                            </button>
                        </div>

                        {/* Responsive Product Showcase Grid with Image Hover Zoom */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                            {filteredShowcase.map((product) => (
                                <Link
                                    key={product.id}
                                    href={route('products.index')}
                                    className="group rounded-2xl bg-white border border-black/10 hover:border-black transition-all duration-300 p-3 shadow-xs hover:shadow-xl flex flex-col justify-between overflow-hidden"
                                >
                                    {/* Image Container with Smooth Zoom */}
                                    <div className="relative rounded-xl overflow-hidden aspect-[4/3] bg-slate-100">
                                        <img 
                                            src={product.image} 
                                            alt={product.name} 
                                            className="w-full h-full object-cover group-hover:scale-108 transition-transform duration-500 ease-out"
                                        />
                                        <div className="absolute top-3 left-3 px-2.5 py-1 rounded-md bg-black/80 backdrop-blur-md text-white font-mono text-[9px] font-black uppercase tracking-wider">
                                            {product.badge}
                                        </div>
                                        <div className="absolute bottom-3 right-3 px-2.5 py-1 rounded-md bg-white/90 backdrop-blur-md text-black font-mono text-[10px] font-bold shadow-sm">
                                            ★ {product.rating} ({product.sales})
                                        </div>
                                    </div>

                                    {/* Product Details */}
                                    <div className="p-3 space-y-2 font-sans">
                                        <div className="flex items-center justify-between text-[11px] font-mono text-black/50">
                                            <span>{product.category}</span>
                                            <span className="text-[#E00D42] font-bold">{product.shop}</span>
                                        </div>

                                        <h5 className="font-bold text-sm text-black group-hover:text-[#E00D42] transition-colors line-clamp-1">
                                            {product.name}
                                        </h5>

                                        <div className="flex items-center justify-between pt-1">
                                            <div className="flex items-baseline gap-2 font-mono">
                                                <span className="font-black text-base text-black">{product.price}</span>
                                                <span className="text-xs text-black/40 line-through">{product.original_price}</span>
                                            </div>
                                            <span className="px-3 py-1.5 bg-[#ECEAE5] group-hover:bg-[#E00D42] group-hover:text-white rounded-lg text-[10px] font-mono font-bold uppercase transition">
                                                View Specs
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            ))}
                        </div>

                        {/* 14 Departments Grid Pills */}
                        <div className="pt-8 border-t border-black/10">
                            <div className="flex items-center justify-between mb-4">
                                <span className="font-mono text-xs font-bold text-black uppercase tracking-wider">
                                    Explore All 14 Official Departments:
                                </span>
                            </div>
                            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2.5">
                                {categories.map((cat, idx) => {
                                    const num = String(idx + 1).padStart(2, '0');
                                    return (
                                        <Link
                                            key={cat.id}
                                            href={route('products.index', { category: cat.slug })}
                                            className="p-3 bg-white hover:bg-black hover:text-white rounded-xl border border-black/10 transition group shadow-xs flex flex-col justify-between h-20"
                                        >
                                            <span className="font-mono text-[9px] text-[#E00D42] group-hover:text-white font-bold">
                                                [{num}/14]
                                            </span>
                                            <span className="font-sans font-bold text-xs line-clamp-1">
                                                {cat.name}
                                            </span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                </section>


                {/* SECTION 05: HIGH-IMPACT MULTI-ROLE ONBOARDING ACTION BAR (DARK MODE) */}
                <section 
                    id="onboarding" 
                    className="relative py-20 sm:py-28 px-4 sm:px-8 lg:px-12 bg-[#05070B] text-white border-b border-white/10 font-mono"
                >
                    <div className="max-w-7xl mx-auto w-full space-y-12">
                        <div className="text-center max-w-2xl mx-auto space-y-3">
                            <span className="text-xs font-bold text-[#E00D42] uppercase tracking-widest">[GET STARTED // 05]</span>
                            <h3 className="text-3xl sm:text-5xl font-black tracking-tight text-white font-sans">
                                Choose Your Gateway to BagooPH
                            </h3>
                            <p className="text-xs text-white/60 uppercase">
                                SELECT YOUR ROLE TO ACCESS YOUR DESIGNATED ECOSYSTEM PORTAL.
                            </p>
                        </div>

                        {/* 4 Role Gateways Grid */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            {/* Role 1: Buyer */}
                            <div className="p-6 rounded-2xl bg-white/5 border border-white/10 hover:border-white/30 transition flex flex-col justify-between space-y-6">
                                <div className="space-y-3">
                                    <div className="w-10 h-10 rounded-xl bg-[#E00D42]/20 text-[#E00D42] flex items-center justify-center">
                                        <ShoppingBag className="w-5 h-5" />
                                    </div>
                                    <h5 className="text-lg font-black text-white font-sans">Buyer Marketplace</h5>
                                    <p className="text-xs text-white/60 font-sans leading-relaxed">
                                        Shop 14 departments, track orders in real-time, and enjoy protected COD payment options.
                                    </p>
                                </div>
                                <Link
                                    href={route('register')}
                                    className="w-full py-2.5 bg-[#E00D42] hover:bg-[#C20836] text-white text-center text-xs font-bold rounded-lg transition uppercase tracking-wider"
                                >
                                    Register as Buyer
                                </Link>
                            </div>

                            {/* Role 2: Seller */}
                            <div className="p-6 rounded-2xl bg-white/5 border border-white/10 hover:border-white/30 transition flex flex-col justify-between space-y-6">
                                <div className="space-y-3">
                                    <div className="w-10 h-10 rounded-xl bg-white/10 text-white flex items-center justify-center">
                                        <Store className="w-5 h-5" />
                                    </div>
                                    <h5 className="text-lg font-black text-white font-sans">Seller Studio</h5>
                                    <p className="text-xs text-white/60 font-sans leading-relaxed">
                                        Manage product inventories, print waybills, and view clear 10% commission profit statements.
                                    </p>
                                </div>
                                <Link
                                    href={route('seller.register')}
                                    className="w-full py-2.5 bg-white text-black hover:bg-white/90 text-center text-xs font-bold rounded-lg transition uppercase tracking-wider"
                                >
                                    Open Seller Store
                                </Link>
                            </div>

                            {/* Role 3: Courier */}
                            <div className="p-6 rounded-2xl bg-white/5 border border-white/10 hover:border-white/30 transition flex flex-col justify-between space-y-6">
                                <div className="space-y-3">
                                    <div className="w-10 h-10 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center">
                                        <Truck className="w-5 h-5" />
                                    </div>
                                    <h5 className="text-lg font-black text-white font-sans">Courier Fleet</h5>
                                    <p className="text-xs text-white/60 font-sans leading-relaxed">
                                        Claim delivery jobs in the first-come dispatch pool with guaranteed ₱50/₱80 fare payouts.
                                    </p>
                                </div>
                                <Link
                                    href={route('courier.register')}
                                    className="w-full py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-center text-xs font-bold rounded-lg transition uppercase tracking-wider"
                                >
                                    Join Courier Fleet
                                </Link>
                            </div>

                            {/* Role 4: Admin */}
                            <div className="p-6 rounded-2xl bg-white/5 border border-white/10 hover:border-white/30 transition flex flex-col justify-between space-y-6">
                                <div className="space-y-3">
                                    <div className="w-10 h-10 rounded-xl bg-blue-500/20 text-blue-400 flex items-center justify-center">
                                        <ShieldCheck className="w-5 h-5" />
                                    </div>
                                    <h5 className="text-lg font-black text-white font-sans">Admin Console</h5>
                                    <p className="text-xs text-white/60 font-sans leading-relaxed">
                                        Audit KYC registrations, oversee logistics hubs, and govern 10% platform commission ledger.
                                    </p>
                                </div>
                                <Link
                                    href={route('login')}
                                    className="w-full py-2.5 bg-white/10 hover:bg-white hover:text-black text-white text-center text-xs font-bold rounded-lg border border-white/20 transition uppercase tracking-wider"
                                >
                                    Admin Sign In
                                </Link>
                            </div>
                        </div>

                        {/* Bottom Integrity Strip */}
                        <div className="pt-6 border-t border-white/10 flex flex-col sm:flex-row items-center justify-between text-xs text-white/50 gap-4">
                            <div className="flex items-center gap-2">
                                <Lock className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>SSL Encrypted & PSGC Verified Philippine Platform</span>
                            </div>
                            <span>© {new Date().getFullYear()} BagooPH Inc. All rights reserved.</span>
                        </div>
                    </div>
                </section>

            </div>
        </MarketplaceLayout>
    );
}
