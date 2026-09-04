import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';
import { 
    Store, 
    ArrowRight, 
    CheckCircle2, 
    TrendingUp, 
    ShieldCheck, 
    Printer, 
    Truck, 
    DollarSign, 
    PackageCheck, 
    Sparkles, 
    ChevronDown, 
    ChevronUp, 
    Calculator, 
    Lock, 
    Zap, 
    BarChart3, 
    Boxes, 
    Clock, 
    Headphones, 
    Check, 
    ShoppingBag,
    Star,
    ExternalLink
} from 'lucide-react';

export default function SellerLanding() {
    const [calcPrice, setCalcPrice] = useState(1500);
    const [calcUnits, setCalcUnits] = useState(100);
    const [openFaqIndex, setOpenFaqIndex] = useState<number | null>(0);

    const monthlyRevenue = calcPrice * calcUnits;
    const bagooFee = monthlyRevenue * 0.10;
    const bagooNet = monthlyRevenue - bagooFee;

    const competitorFee = monthlyRevenue * 0.22; // Competitors typical 18-25% aggregate fees
    const competitorNet = monthlyRevenue - competitorFee;
    const extraProfit = bagooNet - competitorNet;

    const faqs = [
        {
            q: "What is BagooPH's commission structure?",
            a: "BagooPH charges a simple, transparent 10% flat platform commission on completed sales. There are zero monthly listing fees, zero setup costs, zero hidden transaction surcharges, and zero mandatory ad-spend lock-ins."
        },
        {
            q: "What documents are required for Merchant KYC approval?",
            a: "To ensure a 100% verified merchant marketplace, we require one valid government-issued ID (Passport, UMID, Driver's License) and a verified business document (DTI Certificate, SEC Registration, or Mayor's Business Permit). Our automated verification team reviews submissions within 12 to 24 hours."
        },
        {
            q: "How does 45-minute express dispatch and waybill printing work?",
            a: "When an order arrives in your Seller Cockpit, click 'Pack Order' to instantly generate a standardized thermal barcode waybill (compatible with 100x150mm and A4 printers). Once marked 'Ready for Pickup', nearby Bagoo Express couriers claim the parcel on their mobile dispatch board and pick it up directly from your doorstep."
        },
        {
            q: "When and how do merchants receive payout settlements?",
            a: "Funds are automatically credited to your Merchant Ledger the instant the courier confirms doorstep delivery with photographic proof. You can withdraw your earnings seamlessly to GCash, Maya, or any Philippine bank account with automated settlement logs."
        },
        {
            q: "How are customer returns and disputes managed?",
            a: "Every transaction is backed by our Two-Party Escrow system. If a buyer files a dispute, our admin governance team reviews tracking checkpoints, courier handover scans, and delivery photos before releasing funds, protecting honest merchants from fraudulent chargebacks."
        }
    ];

    const formatCurrency = (amount: number) => {
        return new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
            maximumFractionDigits: 0,
        }).format(amount);
    };

    return (
        <div className="min-h-screen bg-[#F4F3EF] text-[#111111] font-sans selection:bg-[#E00D42] selection:text-white">
            <Head title="Seller Centre — Grow Your Brand on BagooPH" />

            {/* 1. TOP ANNOUNCEMENT BAR */}
            <div className="bg-[#111319] text-white/90 text-xs font-mono py-2 px-4 border-b border-white/10 text-center flex items-center justify-center gap-2">
                <span className="px-2 py-0.5 rounded bg-[#E00D42] text-white font-bold text-[10px] uppercase">
                    PROMO 2026
                </span>
                <span>Open your store today and enjoy 0% withdrawal fees on your first 100 orders.</span>
            </div>

            {/* 2. NAVIGATION HEADER */}
            <header className="sticky top-0 z-50 bg-[#F4F3EF]/90 backdrop-blur-md border-b border-black/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-20">
                        
                        {/* Logo */}
                        <div className="flex items-center gap-3">
                            <Link href="/" className="flex items-center gap-2.5 group">
                                <BagooLogo className="w-9 h-9 group-hover:scale-105 transition-transform" rounded="rounded-lg" />
                                <div className="flex flex-col">
                                    <span className="text-xl font-black tracking-tight text-slate-900 leading-none">
                                        Bagoo<span className="text-[#E00D42]">PH</span>
                                    </span>
                                    <span className="text-[9px] font-mono tracking-widest text-slate-500 uppercase font-bold">
                                        SELLER CENTRE
                                    </span>
                                </div>
                            </Link>
                        </div>

                        {/* Nav Anchors */}
                        <nav className="hidden md:flex items-center gap-8 font-mono text-xs font-bold text-slate-700 uppercase">
                            <a href="#why-bagoo" className="hover:text-[#E00D42] transition">Why Bagoo</a>
                            <a href="#profit-calculator" className="hover:text-[#E00D42] transition">Profit Calculator</a>
                            <a href="#features" className="hover:text-[#E00D42] transition">Capabilities</a>
                            <a href="#onboarding" className="hover:text-[#E00D42] transition">How to Start</a>
                            <a href="#faq" className="hover:text-[#E00D42] transition">FAQ</a>
                        </nav>

                        {/* CTA Actions */}
                        <div className="flex items-center gap-3 font-mono text-xs">
                            <a
                                href={getDomainUrl('seller', '/login')}
                                className="px-4 py-2.5 rounded-lg border border-black/20 hover:bg-black/5 text-slate-900 font-bold uppercase transition tracking-wider"
                            >
                                Sign In
                            </a>
                            <a
                                href={getDomainUrl('seller', '/register')}
                                className="px-5 py-2.5 rounded-lg bg-[#E00D42] hover:bg-[#C20836] text-white font-bold uppercase transition tracking-wider shadow-sm flex items-center gap-2 group"
                            >
                                <span>Open Store</span>
                                <ArrowRight className="w-4 h-4 group-hover:translate-x-0.5 transition-transform" />
                            </a>
                        </div>

                    </div>
                </div>
            </header>

            {/* 3. HERO SECTION */}
            <section className="relative overflow-hidden pt-12 pb-20 sm:pt-20 sm:pb-28 border-b border-black/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
                        
                        {/* Hero Text */}
                        <div className="lg:col-span-7 space-y-6">
                            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-black/5 border border-black/10 font-mono text-[11px] font-bold text-slate-800 uppercase tracking-wider">
                                <Sparkles className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>Enterprise Commerce Infrastructure</span>
                            </div>

                            <h1 className="text-4xl sm:text-6xl font-black tracking-tight text-slate-950 leading-[1.08]">
                                Scale your brand with <span className="text-[#E00D42]">10% flat fees</span> & automated fulfillment.
                            </h1>

                            <p className="text-base sm:text-lg text-slate-600 font-sans max-w-xl leading-relaxed">
                                Join verified Philippine merchants on BagooPH. Gain instant doorstep courier dispatch, 1-click thermal barcode waybills, and direct escrow payouts without hidden marketplace markups.
                            </p>

                            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-4 pt-2">
                                <a
                                    href={getDomainUrl('seller', '/register')}
                                    className="px-8 py-4 rounded-xl bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-mono font-bold text-sm uppercase tracking-wider shadow-lg shadow-rose-900/20 flex items-center justify-center gap-2.5 transition"
                                >
                                    <span>Start Selling on Bagoo</span>
                                    <ArrowRight className="w-4 h-4" />
                                </a>

                                <a
                                    href={getDomainUrl('seller', '/login')}
                                    className="px-8 py-4 rounded-xl bg-white hover:bg-slate-50 active:scale-[0.98] border border-slate-300 text-slate-900 font-mono font-bold text-sm uppercase tracking-wider shadow-xs flex items-center justify-center gap-2 transition"
                                >
                                    <span>Merchant Sign In</span>
                                </a>
                            </div>

                            {/* Trust Metric Badges */}
                            <div className="grid grid-cols-3 gap-4 pt-6 border-t border-black/10 font-mono">
                                <div>
                                    <p className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">10%</p>
                                    <p className="text-[11px] text-slate-500 uppercase font-bold">Flat Commission</p>
                                </div>
                                <div>
                                    <p className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">45 Min</p>
                                    <p className="text-[11px] text-slate-500 uppercase font-bold">Express Pickup</p>
                                </div>
                                <div>
                                    <p className="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">100%</p>
                                    <p className="text-[11px] text-slate-500 uppercase font-bold">Escrow Verified</p>
                                </div>
                            </div>
                        </div>

                        {/* Hero Interactive Bento Preview Card */}
                        <div className="lg:col-span-5">
                            <div className="bg-white rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-200 relative overflow-hidden space-y-6">
                                <div className="flex items-center justify-between border-b border-slate-100 pb-4">
                                    <div className="flex items-center gap-3">
                                        <div className="w-10 h-10 rounded-xl bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center font-black">
                                            <Store className="w-5 h-5" />
                                        </div>
                                        <div>
                                            <h4 className="font-bold text-slate-900 text-sm">Merchant Cockpit Preview</h4>
                                            <p className="text-[10px] text-emerald-600 font-mono font-bold uppercase">● LIVE COCKPIT STATUS</p>
                                        </div>
                                    </div>
                                    <span className="px-2.5 py-1 rounded bg-slate-100 font-mono text-[10px] font-bold text-slate-700 uppercase">
                                        VERIFIED STORE
                                    </span>
                                </div>

                                {/* Mini Metrics Grid */}
                                <div className="grid grid-cols-2 gap-3 font-mono">
                                    <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                                        <span className="text-[10px] text-slate-500 uppercase block font-bold">Gross Settlement</span>
                                        <span className="text-lg font-black text-slate-900">₱148,250.00</span>
                                        <span className="text-[9px] text-emerald-600 font-bold block mt-0.5">↑ +24.8% vs last week</span>
                                    </div>
                                    <div className="p-3.5 rounded-xl bg-slate-50 border border-slate-100">
                                        <span className="text-[10px] text-slate-500 uppercase block font-bold">Pending Orders</span>
                                        <span className="text-lg font-black text-[#E00D42]">18 Ready</span>
                                        <span className="text-[9px] text-slate-500 block mt-0.5">Auto-Waybill Ready</span>
                                    </div>
                                </div>

                                {/* Waybill Simulation Snippet */}
                                <div className="p-4 rounded-xl bg-slate-900 text-white font-mono text-xs space-y-2">
                                    <div className="flex items-center justify-between text-[10px] text-slate-400">
                                        <span>WAYBILL GENERATOR</span>
                                        <span className="text-emerald-400">READY TO PRINT</span>
                                    </div>
                                    <div className="flex items-center justify-between py-1 border-y border-slate-800">
                                        <span className="font-bold">BGPH-2026-X8849</span>
                                        <span className="text-[10px] bg-white/10 px-2 py-0.5 rounded">100x150mm THERMAL</span>
                                    </div>
                                    <div className="flex items-center gap-2 text-[10px] text-slate-400 pt-1">
                                        <Printer className="w-3.5 h-3.5 text-[#E00D42]" />
                                        <span>1-Click Bulk PDF generation for warehouse packing</span>
                                    </div>
                                </div>

                                <Link
                                    href={route('seller.register')}
                                    className="w-full py-3 bg-slate-900 hover:bg-black text-white font-mono text-xs font-bold uppercase rounded-xl transition flex items-center justify-center gap-2"
                                >
                                    <span>Create Your Merchant Account</span>
                                    <ArrowRight className="w-4 h-4 text-[#E00D42]" />
                                </Link>
                            </div>
                        </div>

                    </div>
                </div>
            </section>

            {/* 4. PROFIT CALCULATOR SECTION */}
            <section id="profit-calculator" className="py-20 bg-white border-b border-black/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
                    <div className="text-center max-w-2xl mx-auto space-y-3">
                        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E00D42]/10 text-[#E00D42] font-mono text-[11px] font-bold uppercase">
                            <Calculator className="w-3.5 h-3.5" />
                            <span>Transparent Economics</span>
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-slate-900">
                            Calculate How Much More You Keep on BagooPH
                        </h2>
                        <p className="text-sm text-slate-600 font-sans">
                            Competitors silently stack commission, payment processing, transaction, and free-shipping fees up to 22-25%. BagooPH keeps it honest at 10% flat.
                        </p>
                    </div>

                    {/* Interactive Calculator Bento */}
                    <div className="max-w-4xl mx-auto bg-[#F4F3EF] rounded-3xl p-6 sm:p-10 border border-slate-200 shadow-lg">
                        <div className="grid grid-cols-1 md:grid-cols-12 gap-8 items-center">
                            
                            {/* Sliders Form */}
                            <div className="md:col-span-6 space-y-6 font-mono">
                                <div>
                                    <div className="flex justify-between text-xs font-bold uppercase mb-2">
                                        <span className="text-slate-700">Average Item Price</span>
                                        <span className="text-[#E00D42] text-sm">{formatCurrency(calcPrice)}</span>
                                    </div>
                                    <input 
                                        type="range" 
                                        min="200" 
                                        max="10000" 
                                        step="100" 
                                        value={calcPrice} 
                                        onChange={(e) => setCalcPrice(Number(e.target.value))}
                                        className="w-full h-2 bg-slate-300 rounded-lg appearance-none cursor-pointer accent-[#E00D42]"
                                    />
                                    <div className="flex justify-between text-[10px] text-slate-400 mt-1">
                                        <span>₱200</span>
                                        <span>₱10,000</span>
                                    </div>
                                </div>

                                <div>
                                    <div className="flex justify-between text-xs font-bold uppercase mb-2">
                                        <span className="text-slate-700">Monthly Sold Units</span>
                                        <span className="text-[#E00D42] text-sm">{calcUnits} units</span>
                                    </div>
                                    <input 
                                        type="range" 
                                        min="10" 
                                        max="1000" 
                                        step="10" 
                                        value={calcUnits} 
                                        onChange={(e) => setCalcUnits(Number(e.target.value))}
                                        className="w-full h-2 bg-slate-300 rounded-lg appearance-none cursor-pointer accent-[#E00D42]"
                                    />
                                    <div className="flex justify-between text-[10px] text-slate-400 mt-1">
                                        <span>10 units</span>
                                        <span>1,000 units</span>
                                    </div>
                                </div>

                                <div className="p-4 rounded-xl bg-white border border-slate-200 space-y-1">
                                    <span className="text-[10px] font-bold text-slate-500 uppercase">Estimated Monthly Revenue</span>
                                    <p className="text-2xl font-black text-slate-900">{formatCurrency(monthlyRevenue)}</p>
                                </div>
                            </div>

                            {/* Comparison Outcome Cards */}
                            <div className="md:col-span-6 space-y-4">
                                <div className="p-5 rounded-2xl bg-white border-2 border-emerald-500 shadow-md space-y-3 font-mono relative overflow-hidden">
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs font-bold text-emerald-700 uppercase flex items-center gap-1.5">
                                            <CheckCircle2 className="w-4 h-4 text-emerald-600" /> BagooPH Net Take-Home
                                        </span>
                                        <span className="text-[10px] px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold uppercase">
                                            10% FLAT
                                        </span>
                                    </div>
                                    <p className="text-3xl font-black text-slate-900 tracking-tight">{formatCurrency(bagooNet)}</p>
                                    <p className="text-[11px] text-slate-500 font-sans">
                                        Total platform fee: <strong className="text-slate-800">{formatCurrency(bagooFee)}</strong>. No extra transaction or gateway deductions.
                                    </p>
                                </div>

                                <div className="p-4 rounded-2xl bg-slate-200/80 border border-slate-300 space-y-2 font-mono text-xs">
                                    <div className="flex items-center justify-between text-slate-600">
                                        <span>Other Marketplaces (~22% Aggregated)</span>
                                        <span>{formatCurrency(competitorNet)}</span>
                                    </div>
                                </div>

                                <div className="p-4 rounded-2xl bg-slate-900 text-white font-mono text-xs flex items-center justify-between">
                                    <div>
                                        <span className="text-[10px] text-slate-400 uppercase font-bold block">Extra Profit Retained</span>
                                        <span className="text-lg font-black text-emerald-400">+{formatCurrency(extraProfit)} / month</span>
                                    </div>
                                    <Link
                                        href={route('seller.register')}
                                        className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] text-white font-bold rounded-lg uppercase text-[11px] transition"
                                    >
                                        Claim Savings
                                    </Link>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </section>

            {/* 5. 6 CORE CAPABILITIES (BENTO GRID) */}
            <section id="features" className="py-20 border-b border-black/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
                    <div className="text-center max-w-2xl mx-auto space-y-3">
                        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-black/5 text-slate-800 font-mono text-[11px] font-bold uppercase">
                            <Boxes className="w-3.5 h-3.5 text-[#E00D42]" />
                            <span>Merchant Operating System</span>
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-slate-900">
                            Engineered for High-Velocity Merchant Scaling
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                        
                        {/* 1 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-[#E00D42]/10 text-[#E00D42] flex items-center justify-center">
                                <Printer className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">Instant Thermal Waybills</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Never write handwriting waybills again. Generate standardized barcode shipping labels in 1 click for 100x150mm thermal printers or standard paper.
                            </p>
                        </div>

                        {/* 2 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                                <Truck className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">45-Min Express Dispatch</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Our integrated courier dispatch network connects your warehouse directly to Metro Manila dispatch hubs with real-time GPS tracking.
                            </p>
                        </div>

                        {/* 3 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center">
                                <ShieldCheck className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">100% Verified Merchant Badge</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Every merchant undergoes DTI/Mayor's permit verification. This eliminates low-quality spam sellers and boosts buyer trust for legitimate stores.
                            </p>
                        </div>

                        {/* 4 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-amber-500/10 text-amber-600 flex items-center justify-center">
                                <DollarSign className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">Automated Escrow Settlements</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Payouts are credited to your merchant wallet upon delivery completion with zero remittance deductions. Withdraw directly to GCash or Bank.
                            </p>
                        </div>

                        {/* 5 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-rose-500/10 text-[#E00D42] flex items-center justify-center">
                                <BarChart3 className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">Multi-Variant Inventory Control</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Manage sizes, colors, SKU matrixes, and low-stock alerts with precision. Seamlessly edit product catalogs and create store discount vouchers.
                            </p>
                        </div>

                        {/* 6 */}
                        <div className="bg-white rounded-3xl p-8 border border-slate-200 shadow-xs space-y-4 hover:shadow-md transition">
                            <div className="w-12 h-12 rounded-2xl bg-cyan-500/10 text-cyan-600 flex items-center justify-center">
                                <Headphones className="w-6 h-6" />
                            </div>
                            <h3 className="text-lg font-bold text-slate-900">Live Customer Care & Chat</h3>
                            <p className="text-xs text-slate-600 font-sans leading-relaxed">
                                Chat directly with prospective buyers, answer queries in real-time, and manage dispute resolutions through our fair 2-party dispute arbitration.
                            </p>
                        </div>

                    </div>
                </div>
            </section>

            {/* 6. 4-STEP ONBOARDING ROADMAP */}
            <section id="onboarding" className="py-20 bg-white border-b border-black/10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
                    <div className="text-center max-w-2xl mx-auto space-y-3">
                        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#E00D42]/10 text-[#E00D42] font-mono text-[11px] font-bold uppercase">
                            <Zap className="w-3.5 h-3.5" />
                            <span>Quick Start</span>
                        </div>
                        <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-slate-900">
                            Launch Your Verified Shop in 4 Steps
                        </h2>
                    </div>

                    <div className="grid grid-cols-1 md:grid-cols-4 gap-6 font-mono">
                        
                        <div className="p-6 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 relative">
                            <span className="text-3xl font-black text-[#E00D42]">01</span>
                            <h4 className="font-bold text-slate-900 text-sm">Register Profile</h4>
                            <p className="text-xs text-slate-500 font-sans">
                                Fill in your store name, merchant email, and primary warehouse pickup address.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 relative">
                            <span className="text-3xl font-black text-[#E00D42]">02</span>
                            <h4 className="font-bold text-slate-900 text-sm">Upload KYC</h4>
                            <p className="text-xs text-slate-500 font-sans">
                                Submit your DTI/SEC registration or Mayor's permit along with 1 government ID.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 relative">
                            <span className="text-3xl font-black text-[#E00D42]">03</span>
                            <h4 className="font-bold text-slate-900 text-sm">Fast-Track Review</h4>
                            <p className="text-xs text-slate-500 font-sans">
                                Our verification team validates credentials within 12 to 24 hours.
                            </p>
                        </div>

                        <div className="p-6 rounded-2xl bg-slate-900 text-white space-y-3 relative shadow-md">
                            <span className="text-3xl font-black text-emerald-400">04</span>
                            <h4 className="font-bold text-white text-sm">Publish & Fulfill</h4>
                            <p className="text-xs text-slate-300 font-sans">
                                List products across 14 departments and receive immediate buyer orders.
                            </p>
                        </div>

                    </div>
                </div>
            </section>

            {/* 7. FAQ ACCORDION */}
            <section id="faq" className="py-20 border-b border-black/10">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">
                    <div className="text-center space-y-3">
                        <h2 className="text-3xl sm:text-4xl font-black tracking-tight text-slate-900">
                            Frequently Asked Questions
                        </h2>
                        <p className="text-sm text-slate-600 font-sans">
                            Everything you need to know about selling on BagooPH.
                        </p>
                    </div>

                    <div className="space-y-3 font-sans">
                        {faqs.map((faq, index) => {
                            const isOpen = openFaqIndex === index;
                            return (
                                <div key={index} className="bg-white rounded-2xl border border-slate-200 overflow-hidden transition shadow-xs">
                                    <button
                                        type="button"
                                        onClick={() => setOpenFaqIndex(isOpen ? null : index)}
                                        className="w-full p-5 sm:p-6 text-left flex items-center justify-between gap-4 focus:outline-hidden"
                                    >
                                        <span className="font-bold text-slate-900 text-sm sm:text-base">{faq.q}</span>
                                        {isOpen ? <ChevronUp className="w-5 h-5 text-[#E00D42] shrink-0" /> : <ChevronDown className="w-5 h-5 text-slate-400 shrink-0" />}
                                    </button>
                                    {isOpen && (
                                        <div className="px-5 sm:px-6 pb-6 text-xs sm:text-sm text-slate-600 leading-relaxed border-t border-slate-100 pt-4">
                                            {faq.a}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* 8. CLOSING HIGH-IMPACT CALL TO ACTION */}
            <section className="py-20 bg-slate-950 text-white relative overflow-hidden">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-8 relative z-10">
                    <div className="max-w-2xl mx-auto space-y-4">
                        <h2 className="text-3xl sm:text-5xl font-black tracking-tight">
                            Ready to take control of your store's profit?
                        </h2>
                        <p className="text-sm sm:text-base text-slate-400 font-sans">
                            Open your verified shop today with 10% flat platform economics and automated express logistics.
                        </p>
                    </div>

                    <div className="flex flex-col sm:flex-row items-center justify-center gap-4 font-mono">
                        <a
                            href={getDomainUrl('seller', '/register')}
                            className="w-full sm:w-auto px-8 py-4 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white font-bold text-xs uppercase tracking-wider rounded-xl shadow-lg transition"
                        >
                            Open Verified Shop Now
                        </a>
                        <a
                            href={getDomainUrl('seller', '/login')}
                            className="w-full sm:w-auto px-8 py-4 bg-white/10 hover:bg-white/20 active:scale-[0.98] text-white font-bold text-xs uppercase tracking-wider rounded-xl transition border border-white/10"
                        >
                            Sign In to Seller Centre
                        </a>
                    </div>
                </div>
            </section>

            {/* 9. MINIMAL FOOTER */}
            <footer className="bg-[#111111] text-white py-12 px-4 sm:px-6 lg:px-8 border-t border-black font-mono text-xs">
                <div className="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-6 text-white/50">
                    <div className="flex items-center gap-3">
                        <BagooLogo className="w-7 h-7" rounded="rounded-md" />
                        <span className="font-bold text-white tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                        <span>•</span>
                        <span>Seller Centre & Merchant Operations</span>
                    </div>

                    <div className="flex items-center gap-6">
                        <a href={getDomainUrl('buyer', '/')} className="hover:text-white transition">Buyer Marketplace</a>
                        <a href={getDomainUrl('courier', '/')} className="hover:text-white transition">Courier Fleet</a>
                        <a href={getDomainUrl('admin', '/')} className="hover:text-white transition">Admin Console</a>
                        <span>&copy; {new Date().getFullYear()} BagooPH.</span>
                    </div>
                </div>
            </footer>

        </div>
    );
}
