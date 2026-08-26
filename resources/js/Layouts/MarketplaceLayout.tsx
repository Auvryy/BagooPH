import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import BagooLogo from '@/Components/BagooLogo';
import { 
    ShoppingBag, 
    Store,
    Menu,
    X,
    User,
    ArrowRight
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title?: string;
    headerTheme?: 'light' | 'dark';
}

export default function MarketplaceLayout({ children, title, headerTheme = 'light' }: Props) {
    const isDark = headerTheme === 'dark';
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    return (
        <div className="min-h-screen bg-[#ECEAE5] text-slate-900 flex flex-col font-sans relative selection:bg-[#E00D42] selection:text-white">
            {/* Minimal Dynamic Responsive Header: Logo on Left, Nav on Right */}
            <header className={`sticky top-0 z-50 transition-colors duration-500 backdrop-blur-md ${
                isDark 
                    ? 'bg-[#0A0D14]/90 border-b border-white/10 text-white' 
                    : 'bg-[#ECEAE5]/90 border-b border-black/10 text-black'
            }`}>
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-16 sm:h-20">
                        
                        {/* Left: Brand Icon + Website Name */}
                        <Link href={route('marketplace')} className="flex items-center gap-3 group shrink-0">
                            <BagooLogo className="w-8 h-8 sm:w-10 sm:h-10 shadow-xs group-hover:scale-105 transition-transform" rounded="rounded-lg" />
                            <div className="flex flex-col">
                                <span className={`text-lg sm:text-2xl font-black tracking-tight transition-colors duration-300 ${
                                    isDark ? 'text-white' : 'text-black'
                                }`}>
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className={`text-[8px] sm:text-[9px] uppercase font-mono font-bold tracking-widest -mt-1 transition-colors duration-300 ${
                                    isDark ? 'text-white/50' : 'text-black/50'
                                }`}>
                                    ECOSYSTEM
                                </span>
                            </div>
                        </Link>

                        {/* Desktop Actions */}
                        <div className="hidden md:flex items-center gap-2 sm:gap-3 font-mono text-xs">
                            <Link
                                href={route('buyer.index')}
                                className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-bold rounded-lg border transition tracking-wider uppercase ${
                                    isDark
                                        ? 'border-white/20 text-white/90 hover:bg-white/10'
                                        : 'border-black/20 text-black/90 hover:bg-black/5'
                                }`}
                            >
                                <ShoppingBag className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>Shop Marketplace</span>
                            </Link>
                            <Link
                                href={route('seller.register')}
                                className={`inline-flex items-center gap-1.5 px-3 py-1.5 text-[11px] font-bold rounded-lg border transition tracking-wider uppercase ${
                                    isDark
                                        ? 'border-white/20 text-white/90 hover:bg-white/10'
                                        : 'border-black/20 text-black/90 hover:bg-black/5'
                                }`}
                            >
                                <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>Seller Centre</span>
                            </Link>
                            <Link
                                href={route('login')}
                                className={`px-3 sm:px-4 py-2 text-xs font-bold rounded-lg transition tracking-wider uppercase ${
                                    isDark 
                                        ? 'text-white/80 hover:text-white hover:bg-white/10' 
                                        : 'text-black/80 hover:text-black hover:bg-black/5'
                                }`}
                            >
                                Sign In
                            </Link>
                            <Link
                                href={route('register')}
                                className="px-3 sm:px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold rounded-lg shadow-xs transition tracking-wider uppercase"
                            >
                                Register
                            </Link>
                        </div>

                        {/* Mobile Actions: Register CTA + Hamburger Toggle */}
                        <div className="flex md:hidden items-center gap-2 font-mono text-xs">
                            <Link
                                href={route('register')}
                                className="px-3 py-1.5 bg-[#E00D42] hover:bg-[#C20836] text-white text-[11px] font-bold rounded-lg shadow-xs transition tracking-wider uppercase"
                            >
                                Register
                            </Link>
                            <button
                                type="button"
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                className={`p-2 rounded-lg border transition ${
                                    isDark 
                                        ? 'border-white/15 text-white hover:bg-white/10' 
                                        : 'border-black/15 text-black hover:bg-black/5'
                                }`}
                                aria-label="Toggle Menu"
                            >
                                {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
                            </button>
                        </div>

                    </div>
                </div>

                {/* Mobile Dropdown Menu Drawer */}
                {mobileMenuOpen && (
                    <div className={`md:hidden border-t px-4 py-4 space-y-3 font-mono text-xs transition-all ${
                        isDark 
                            ? 'bg-[#0E121D] border-white/10 text-white' 
                            : 'bg-[#ECEAE5] border-black/10 text-black'
                    }`}>
                        <div className="grid grid-cols-1 gap-2 pt-1">
                            <Link
                                href={route('buyer.index')}
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center justify-between p-3 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 font-bold uppercase tracking-wider"
                            >
                                <div className="flex items-center gap-2.5">
                                    <ShoppingBag className="w-4 h-4 text-[#E00D42]" />
                                    <span>Shop Marketplace</span>
                                </div>
                                <ArrowRight className="w-4 h-4 text-slate-400" />
                            </Link>

                            <Link
                                href={route('seller.register')}
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center justify-between p-3 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 font-bold uppercase tracking-wider"
                            >
                                <div className="flex items-center gap-2.5">
                                    <Store className="w-4 h-4 text-[#E00D42]" />
                                    <span>Seller Centre</span>
                                </div>
                                <ArrowRight className="w-4 h-4 text-slate-400" />
                            </Link>

                            <Link
                                href={route('login')}
                                onClick={() => setMobileMenuOpen(false)}
                                className="flex items-center justify-between p-3 rounded-xl bg-black/5 dark:bg-white/5 border border-black/10 dark:border-white/10 font-bold uppercase tracking-wider"
                            >
                                <div className="flex items-center gap-2.5">
                                    <User className="w-4 h-4 text-[#E00D42]" />
                                    <span>Sign In</span>
                                </div>
                                <ArrowRight className="w-4 h-4 text-slate-400" />
                            </Link>
                        </div>
                    </div>
                )}
            </header>

            {/* Page Body */}
            <main className="flex-1">
                {children}
            </main>

            {/* Clean Minimalist Footer */}
            <footer className="bg-[#111111] text-white py-12 px-4 sm:px-6 lg:px-8 border-t border-black font-mono">
                <div className="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-6 text-xs text-white/50">
                    <div className="flex items-center gap-3">
                        <BagooLogo className="w-7 h-7" rounded="rounded-md" />
                        <span className="font-bold text-white tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                        <span>•</span>
                        <span>Next-Gen Multi-Role Commerce Ecosystem</span>
                    </div>

                    <div className="flex items-center gap-6">
                        <Link href={route('seller.register')} className="hover:text-[#E00D42] transition">Seller Centre</Link>
                        <Link href={route('login')} className="hover:text-white transition">Sign In</Link>
                        <Link href={route('register')} className="hover:text-white transition">Register</Link>
                        <span>&copy; {new Date().getFullYear()} BagooPH. All rights reserved.</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
