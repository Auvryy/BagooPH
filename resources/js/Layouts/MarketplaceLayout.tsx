import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import CursorSpotlight from '@/Components/CursorSpotlight';
import { 
    ShoppingBag, 
    User as UserIcon, 
    Truck, 
    Store, 
    ShieldCheck, 
    PackageCheck,
    ChevronDown,
    LogOut,
    Menu,
    X
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title?: string;
}

export default function MarketplaceLayout({ children, title }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    const getRoleDashboardLink = () => {
        if (!auth.user) return null;
        switch (auth.user.role) {
            case 'admin':
                return { name: 'Admin Dashboard', route: route('admin.dashboard'), icon: ShieldCheck };
            case 'seller':
                return { name: 'Seller Center', route: route('seller.dashboard'), icon: Store };
            case 'courier':
            case 'logistics':
                return { name: 'Courier Dispatch', route: route('courier.deliveries'), icon: Truck };
            default:
                return { name: 'My Orders', route: route('orders.index'), icon: PackageCheck };
        }
    };

    const dashboard = getRoleDashboardLink();

    return (
        <div className="min-h-screen bg-[#ECEAE5] text-slate-900 flex flex-col font-sans relative selection:bg-[#E00D42] selection:text-white">
            {/* Custom Interactive Cursor Spotlight */}
            <CursorSpotlight />

            {/* Minimal Header: Logo on Left, Sign In & Register on Right */}
            <header className="sticky top-0 z-40 bg-[#ECEAE5]/90 backdrop-blur-md border-b border-black/10 transition-colors">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-16 sm:h-20">
                        
                        {/* Left: Brand Icon + Website Name */}
                        <Link href={route('marketplace')} className="flex items-center gap-3 group shrink-0">
                            <div className="w-9 h-9 sm:w-10 sm:h-10 rounded-lg bg-[#E00D42] flex items-center justify-center text-white shadow-xs group-hover:bg-[#C20836] transition">
                                <ShoppingBag className="w-5 h-5" />
                            </div>
                            <div className="flex flex-col">
                                <span className="text-xl sm:text-2xl font-black tracking-tight text-black">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className="text-[9px] uppercase font-mono font-bold tracking-widest text-black/50 -mt-1">
                                    ECOSYSTEM
                                </span>
                            </div>
                        </Link>

                        {/* Right: Sign In and Register Buttons */}
                        <div className="flex items-center gap-3 font-mono text-xs">
                            {auth.user ? (
                                <div className="relative">
                                    <button
                                        onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                        className="flex items-center gap-2.5 px-3 py-1.5 bg-black/5 hover:bg-black/10 rounded-lg border border-black/15 transition"
                                    >
                                        <div className="w-7 h-7 rounded-md bg-[#E00D42] text-white text-xs font-bold flex items-center justify-center">
                                            {auth.user.name.charAt(0).toUpperCase()}
                                        </div>
                                        <div className="text-left hidden sm:block">
                                            <p className="text-xs font-bold text-black leading-none">
                                                {auth.user.name.split(' ')[0]}
                                            </p>
                                            <p className="text-[10px] uppercase text-[#E00D42] font-semibold">
                                                {auth.user.role}
                                            </p>
                                        </div>
                                        <ChevronDown className="w-3.5 h-3.5 text-black/50" />
                                    </button>

                                    {/* User Dropdown Menu */}
                                    {userDropdownOpen && (
                                        <div 
                                            className="absolute right-0 mt-2 w-52 bg-white rounded-xl shadow-xl border border-black/10 py-1.5 z-50 animate-scale-in"
                                            onMouseLeave={() => setUserDropdownOpen(false)}
                                        >
                                            <div className="px-4 py-2 border-b border-slate-100">
                                                <p className="text-xs font-bold text-slate-900 truncate">{auth.user.name}</p>
                                                <p className="text-[11px] text-slate-500 truncate">{auth.user.email}</p>
                                            </div>

                                            {dashboard && (
                                                <Link
                                                    href={dashboard.route}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <dashboard.icon className="w-4 h-4 text-[#E00D42]" />
                                                    <span>{dashboard.name}</span>
                                                </Link>
                                            )}

                                            <Link
                                                href={route('profile.edit')}
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <UserIcon className="w-4 h-4 text-slate-400" />
                                                <span>Account Profile</span>
                                            </Link>

                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50 transition border-t border-slate-100 mt-1"
                                            >
                                                <LogOut className="w-4 h-4" />
                                                <span>Sign Out</span>
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex items-center gap-3">
                                    <Link
                                        href={route('login')}
                                        className="px-4 py-2 text-xs font-bold text-black/80 hover:text-black hover:bg-black/5 rounded-lg transition tracking-wider uppercase"
                                    >
                                        Sign In
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="px-4 py-2 bg-[#E00D42] hover:bg-[#C20836] active:scale-[0.98] text-white text-xs font-bold rounded-lg shadow-xs transition tracking-wider uppercase"
                                    >
                                        Register
                                    </Link>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </header>

            {/* Page Body */}
            <main className="flex-1">
                {children}
            </main>

            {/* Clean Minimalist Footer (Without sensitive demo info) */}
            <footer className="bg-[#111111] text-white py-12 px-4 sm:px-6 lg:px-8 border-t border-black font-mono">
                <div className="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-6 text-xs text-white/50">
                    <div className="flex items-center gap-3">
                        <div className="w-7 h-7 rounded-md bg-[#E00D42] flex items-center justify-center text-white">
                            <ShoppingBag className="w-4 h-4" />
                        </div>
                        <span className="font-bold text-white tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                        <span>•</span>
                        <span>Next-Gen Multi-Role Commerce Ecosystem</span>
                    </div>

                    <div className="flex items-center gap-6">
                        <Link href={route('login')} className="hover:text-white transition">Sign In</Link>
                        <Link href={route('register')} className="hover:text-white transition">Register</Link>
                        <span>&copy; {new Date().getFullYear()} BagooPH. All rights reserved.</span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
