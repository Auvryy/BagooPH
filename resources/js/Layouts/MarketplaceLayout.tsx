import React, { useState } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import { 
    ShoppingBag, 
    ShoppingCart, 
    Search, 
    User as UserIcon, 
    Menu, 
    X, 
    Truck, 
    Store, 
    ShieldCheck, 
    PackageCheck,
    ChevronDown,
    LogOut,
    SlidersHorizontal,
    Box
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title?: string;
}

export default function MarketplaceLayout({ children, title }: Props) {
    const { auth, cartCount, flash } = usePage<PageProps>().props;
    const [searchQuery, setSearchQuery] = useState('');
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [userDropdownOpen, setUserDropdownOpen] = useState(false);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('marketplace'), { search: searchQuery }, { preserveState: true });
    };

    const getRoleDashboardLink = () => {
        if (!auth.user) return null;
        switch (auth.user.role) {
            case 'admin':
                return { name: 'Admin Panel', route: route('admin.dashboard'), icon: ShieldCheck };
            case 'seller':
                return { name: 'Seller Center', route: route('seller.dashboard'), icon: Store };
            case 'courier':
            case 'logistics':
                return { name: 'Courier Tasks', route: route('courier.deliveries'), icon: Truck };
            default:
                return { name: 'My Orders', route: route('orders.index'), icon: PackageCheck };
        }
    };

    const dashboard = getRoleDashboardLink();

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col font-sans">
            {/* Top Bar Banner */}
            <div className="bg-slate-900 text-slate-300 text-xs py-2 px-4 border-b border-slate-800">
                <div className="max-w-7xl mx-auto flex justify-between items-center">
                    <div className="flex items-center gap-3">
                        <span className="flex items-center gap-1.5 text-[#E00D42] font-semibold">
                            <span className="w-2 h-2 rounded-full bg-[#E00D42] animate-pulse"></span>
                            Multi-Role E-Commerce Platform
                        </span>
                        <span className="hidden sm:inline text-slate-500">•</span>
                        <span className="hidden sm:inline">Buyer • Seller • Admin • Courier</span>
                    </div>
                    <div className="flex items-center gap-4 text-xs">
                        <span className="text-slate-400">Free shipping on orders over $100</span>
                    </div>
                </div>
            </div>

            {/* Main Navbar */}
            <header className="sticky top-0 z-40 bg-white/95 backdrop-blur-md border-b border-slate-200/80 shadow-xs">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between h-20 gap-4">
                        {/* Logo */}
                        <Link href={route('marketplace')} className="flex items-center gap-3 group shrink-0">
                            <div className="w-10 h-10 rounded-lg bg-[#E00D42] flex items-center justify-center text-white shadow-xs group-hover:bg-[#C20836] transition">
                                <ShoppingBag className="w-5 h-5" />
                            </div>
                            <div>
                                <span className="text-xl font-black tracking-tight text-slate-900">
                                    Bagoo<span className="text-[#E00D42]">PH</span>
                                </span>
                                <span className="block text-[9px] uppercase font-bold tracking-wider text-[#E00D42] -mt-1">
                                    Marketplace
                                </span>
                            </div>
                        </Link>

                        {/* Search Bar */}
                        <form onSubmit={handleSearch} className="hidden md:flex flex-1 max-w-xl mx-4">
                            <div className="relative w-full">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search products across 14 categories..."
                                    className="w-full pl-9 pr-24 py-2 bg-slate-100/80 hover:bg-slate-100 focus:bg-white text-xs rounded-lg border border-slate-200 focus:border-[#E00D42] focus:ring-1 focus:ring-[#E00D42] transition"
                                />
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1/2 -translate-y-1/2 px-3 py-1 bg-[#E00D42] hover:bg-[#C20836] text-white text-xs font-semibold rounded-md shadow-xs transition"
                                >
                                    Search
                                </button>
                            </div>
                        </form>

                        {/* Right Action Icons */}
                        <div className="flex items-center gap-3">
                            {/* Role Portal Quick Button if logged in */}
                            {dashboard && (
                                <Link
                                    href={dashboard.route}
                                    className="hidden lg:flex items-center gap-2 px-3.5 py-1.5 rounded-lg text-xs font-semibold text-white bg-[#E00D42] hover:bg-[#C20836] shadow-xs transition active:scale-[0.98]"
                                >
                                    <dashboard.icon className="w-3.5 h-3.5" />
                                    <span>{dashboard.name}</span>
                                </Link>
                            )}

                            {/* Cart Icon */}
                            <Link
                                href={route('cart.index')}
                                className="relative p-2 text-slate-700 hover:text-[#E00D42] hover:bg-slate-100 rounded-lg transition"
                                title="Shopping Cart"
                            >
                                <ShoppingCart className="w-5 h-5" />
                                {cartCount > 0 && (
                                    <span className="absolute -top-0.5 -right-0.5 w-4 h-4 bg-[#E00D42] text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-xs">
                                        {cartCount}
                                    </span>
                                )}
                            </Link>

                            {/* User Account / Auth */}
                            {auth.user ? (
                                <div className="relative">
                                    <button
                                        onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                                        className="flex items-center gap-2 p-1.5 pl-2 pr-3 bg-slate-100 hover:bg-slate-200/80 rounded-full border border-slate-200 transition"
                                    >
                                        <div className="w-7 h-7 rounded-full bg-indigo-600 text-white text-xs font-bold flex items-center justify-center">
                                            {auth.user.name.charAt(0)}
                                        </div>
                                        <div className="text-left hidden sm:block">
                                            <p className="text-xs font-semibold text-slate-800 leading-none truncate max-w-[90px]">
                                                {auth.user.name.split(' ')[0]}
                                            </p>
                                            <p className="text-[10px] uppercase font-bold text-slate-500 tracking-wider">
                                                {auth.user.role}
                                            </p>
                                        </div>
                                        <ChevronDown className="w-3.5 h-3.5 text-slate-500" />
                                    </button>

                                    {userDropdownOpen && (
                                        <div 
                                            className="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl border border-slate-200 py-2 z-50 animate-in fade-in zoom-in-95 duration-100"
                                            onClick={() => setUserDropdownOpen(false)}
                                        >
                                            <div className="px-4 py-2 border-b border-slate-100">
                                                <p className="text-xs text-slate-500">Signed in as</p>
                                                <p className="text-sm font-bold text-slate-900 truncate">{auth.user.email}</p>
                                                <span className="inline-block mt-1 px-2 py-0.5 text-[10px] font-bold uppercase rounded-md bg-indigo-50 text-indigo-700">
                                                    Role: {auth.user.role}
                                                </span>
                                            </div>

                                            {dashboard && (
                                                <Link
                                                    href={dashboard.route}
                                                    className="flex items-center gap-2.5 px-4 py-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 transition"
                                                >
                                                    <dashboard.icon className="w-4 h-4 text-slate-400" />
                                                    {dashboard.name}
                                                </Link>
                                            )}

                                            <Link
                                                href={route('orders.index')}
                                                className="flex items-center gap-2.5 px-4 py-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 transition"
                                            >
                                                <PackageCheck className="w-4 h-4 text-slate-400" />
                                                My Order History
                                            </Link>

                                            <Link
                                                href={route('profile.edit')}
                                                className="flex items-center gap-2.5 px-4 py-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-indigo-600 transition"
                                            >
                                                <UserIcon className="w-4 h-4 text-slate-400" />
                                                Profile Settings
                                            </Link>

                                            <div className="border-t border-slate-100 my-1"></div>

                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full flex items-center gap-2.5 px-4 py-2.5 text-xs font-medium text-rose-600 hover:bg-rose-50 transition"
                                            >
                                                <LogOut className="w-4 h-4" />
                                                Log Out
                                            </Link>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <Link
                                        href={route('login')}
                                        className="px-3.5 py-2 text-xs font-semibold text-slate-700 hover:text-indigo-600 hover:bg-slate-100 rounded-xl transition"
                                    >
                                        Sign In
                                    </Link>
                                    <Link
                                        href={route('register')}
                                        className="px-4 py-2 text-xs font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl shadow-xs transition"
                                    >
                                        Register
                                    </Link>
                                </div>
                            )}

                            {/* Mobile menu toggle */}
                            <button
                                onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
                                className="md:hidden p-2 text-slate-700 hover:bg-slate-100 rounded-xl"
                            >
                                {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
                            </button>
                        </div>
                    </div>

                    {/* Mobile Search & Menu */}
                    {mobileMenuOpen && (
                        <div className="md:hidden py-4 border-t border-slate-200 space-y-3 animate-in slide-in-from-top-2">
                            <form onSubmit={handleSearch} className="flex">
                                <input
                                    type="text"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    placeholder="Search products..."
                                    className="w-full px-4 py-2 text-sm bg-slate-100 rounded-l-xl border-none focus:ring-2 focus:ring-indigo-500"
                                />
                                <button type="submit" className="px-4 bg-indigo-600 text-white text-xs font-bold rounded-r-xl">
                                    Search
                                </button>
                            </form>
                            <div className="grid grid-cols-2 gap-2 pt-2">
                                <Link
                                    href={route('marketplace')}
                                    className="px-3 py-2 text-center text-xs font-semibold bg-slate-100 rounded-lg text-slate-700"
                                >
                                    Browse Catalog
                                </Link>
                                <Link
                                    href={route('cart.index')}
                                    className="px-3 py-2 text-center text-xs font-semibold bg-indigo-50 text-indigo-700 rounded-lg"
                                >
                                    Cart ({cartCount})
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </header>

            {/* Flash Messages */}
            {flash.success && (
                <div className="bg-emerald-500 text-white py-2.5 px-4 text-center text-xs font-semibold shadow-sm">
                    {flash.success}
                </div>
            )}
            {flash.error && (
                <div className="bg-rose-500 text-white py-2.5 px-4 text-center text-xs font-semibold shadow-sm">
                    {flash.error}
                </div>
            )}

            {/* Main Content Area */}
            <main className="flex-1">
                {children}
            </main>

            {/* Global Footer */}
            <footer className="bg-slate-900 text-slate-400 mt-20 border-t border-slate-800">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-8">
                        <div className="space-y-3">
                            <div className="flex items-center gap-2">
                                <div className="w-8 h-8 rounded-lg bg-[#E00D42] flex items-center justify-center text-white font-bold">
                                    <ShoppingBag className="w-4 h-4" />
                                </div>
                                <span className="text-xl font-black text-white tracking-tight">Bagoo<span className="text-[#E00D42]">PH</span></span>
                            </div>
                            <p className="text-xs leading-relaxed text-slate-400">
                                Next-generation multi-role e-commerce platform bridging buyers, sellers, admins, and intelligent courier dispatch.
                            </p>
                        </div>

                        <div>
                            <h4 className="text-xs uppercase font-bold text-slate-200 tracking-wider mb-3">Role Portals</h4>
                            <ul className="space-y-2 text-xs">
                                <li><Link href={route('marketplace')} className="hover:text-white transition">Buyer Marketplace</Link></li>
                                <li><Link href={route('seller.dashboard')} className="hover:text-white transition">Seller Center</Link></li>
                                <li><Link href={route('courier.deliveries')} className="hover:text-white transition">Courier Dispatch</Link></li>
                                <li><Link href={route('admin.dashboard')} className="hover:text-white transition">Admin Dashboard</Link></li>
                            </ul>
                        </div>

                        <div>
                            <h4 className="text-xs uppercase font-bold text-slate-200 tracking-wider mb-3">Tech Stack</h4>
                            <ul className="space-y-2 text-xs">
                                <li>Laravel 11+ & Inertia.js</li>
                                <li>React 18 & TypeScript</li>
                                <li>Tailwind CSS & Lucide Icons</li>
                                <li>PostgreSQL 16 & Docker Compose</li>
                            </ul>
                        </div>

                        <div>
                            <h4 className="text-xs uppercase font-bold text-slate-200 tracking-wider mb-3">Demo Accounts</h4>
                            <p className="text-[11px] text-slate-400 mb-2">Password for all test roles is: <code className="text-indigo-400 bg-slate-800 px-1 py-0.5 rounded">password</code></p>
                            <div className="space-y-1 text-xs font-mono text-slate-300">
                                <div><span className="text-rose-400">Admin:</span> admin@bagoo.test</div>
                                <div><span className="text-emerald-400">Seller:</span> seller@bagoo.test</div>
                                <div><span className="text-indigo-400">Buyer:</span> buyer@bagoo.test</div>
                                <div><span className="text-amber-400">Courier:</span> courier@bagoo.test</div>
                            </div>
                        </div>
                    </div>

                    <div className="mt-10 pt-6 border-t border-slate-800 text-center text-xs text-slate-500">
                        &copy; {new Date().getFullYear()} Bagoo E-Commerce. All rights reserved.
                    </div>
                </div>
            </footer>
        </div>
    );
}
