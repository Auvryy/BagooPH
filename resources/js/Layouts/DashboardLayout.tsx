import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
import { 
    ShoppingBag, 
    LayoutDashboard, 
    Package, 
    ShoppingCart, 
    Truck, 
    Users, 
    Settings, 
    LogOut, 
    Store, 
    ShieldCheck, 
    Menu, 
    X, 
    ArrowLeft,
    Box,
    CheckCircle2
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: string;
    actions?: React.ReactNode;
}

export default function DashboardLayout({ children, title, subtitle, actions }: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);

    const user = auth.user;
    const role = user?.role || 'buyer';

    const getNavItems = () => {
        if (role === 'admin') {
            return [
                { name: 'Platform Overview', href: route('admin.dashboard'), icon: LayoutDashboard, current: route().current('admin.dashboard') },
                { name: 'User & Role Control', href: route('admin.users'), icon: Users, current: route().current('admin.users*') },
                { name: 'Catalog Moderation', href: route('admin.products'), icon: Package, current: route().current('admin.products*') },
            ];
        }

        if (role === 'seller') {
            return [
                { name: 'Seller Overview', href: route('seller.dashboard'), icon: LayoutDashboard, current: route().current('seller.dashboard') },
                { name: 'My Products', href: route('seller.products.index'), icon: Package, current: route().current('seller.products.*') },
                { name: 'Incoming Orders', href: route('seller.orders.index'), icon: ShoppingCart, current: route().current('seller.orders.*') },
                ...(user?.shop ? [{ name: 'Public Storefront', href: route('shop.show', user.shop.slug), icon: Store, current: false }] : []),
                { name: 'Account Settings', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
            ];
        }

        if (role === 'courier' || role === 'logistics') {
            return [
                { name: 'Delivery Pool', href: route('courier.deliveries'), icon: Truck, current: route().current('courier.deliveries') },
                { name: 'Account Settings', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
            ];
        }

        return [
            { name: 'Buyer Dashboard', href: route('buyer.dashboard'), icon: LayoutDashboard, current: route().current('buyer.dashboard') },
            { name: 'Explore Marketplace', href: route('products.index'), icon: ShoppingBag, current: route().current('products.*') },
            { name: 'My Cart & Bag', href: route('cart.index'), icon: ShoppingCart, current: route().current('cart.index') },
            { name: 'My Orders & Shipments', href: route('orders.index'), icon: Package, current: route().current('orders.*') },
            { name: 'Account Settings', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
        ];
    };

    const navItems = getNavItems();

    const getRoleBadge = () => {
        switch (role) {
            case 'admin':
                return { label: 'Platform Admin', color: 'bg-rose-500/10 text-rose-400 border-rose-500/20' };
            case 'seller':
                return { label: 'Verified Seller', color: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' };
            case 'courier':
            case 'logistics':
                return { label: 'Active Courier', color: 'bg-amber-500/10 text-amber-400 border-amber-500/20' };
            default:
                return { label: 'Valued Buyer', color: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20' };
        }
    };

    const roleBadge = getRoleBadge();

    return (
        <div className="min-h-screen bg-slate-950 flex flex-col lg:flex-row text-slate-100 font-sans">
            {/* Mobile Header Bar */}
            <div className="lg:hidden h-16 bg-slate-900 border-b border-slate-800 flex items-center justify-between px-4 sticky top-0 z-40">
                <Link href={route('marketplace')} className="flex items-center gap-2">
                    <BagooLogo className="w-8 h-8" rounded="rounded-lg" />
                    <span className="text-xl font-black tracking-tight text-white">Bagoo<span className="text-[#E00D42]">PH</span></span>
                </Link>
                <button 
                    onClick={() => setSidebarOpen(!sidebarOpen)}
                    className="p-2 rounded-lg bg-slate-800 text-slate-300 hover:text-white"
                >
                    {sidebarOpen ? <X className="w-6 h-6" /> : <Menu className="w-6 h-6" />}
                </button>
            </div>

            {/* Backdrop for mobile */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 bg-black/60 backdrop-blur-xs z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar Navigation */}
            <aside className={`fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-slate-300 flex flex-col transition-transform duration-200 lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} lg:static lg:z-auto`}>
                {/* Brand Header */}
                <div className="h-20 flex items-center justify-between px-6 border-b border-slate-800">
                    <Link href={route('marketplace')} className="flex items-center gap-2.5">
                        <BagooLogo className="w-9 h-9 shadow-md shadow-[#E00D42]/20" rounded="rounded-xl" />
                        <div>
                            <span className="text-xl font-black tracking-tight text-white">Bagoo<span className="text-[#E00D42]">PH</span></span>
                            <span className="block text-[9px] uppercase font-bold tracking-widest text-[#E00D42] -mt-1">
                                Enterprise Portal
                            </span>
                        </div>
                    </Link>
                    <button 
                        onClick={() => setSidebarOpen(false)}
                        className="lg:hidden p-1 text-slate-400 hover:text-white"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* User Role Tag */}
                <div className="p-4 mx-4 mt-4 bg-slate-800/80 rounded-2xl border border-slate-750">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-[#E00D42] flex items-center justify-center text-white font-bold text-sm">
                            {user?.name.charAt(0)}
                        </div>
                        <div className="overflow-hidden">
                            <p className="text-xs font-semibold text-white truncate">{user?.name}</p>
                            <span className={`inline-block text-[10px] font-bold uppercase px-2 py-0.5 rounded-md border mt-1 ${roleBadge.color}`}>
                                {role}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Navigation Links */}
                <nav className="flex-1 px-4 py-6 space-y-1.5 overflow-y-auto">
                    <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">
                        Portal Navigation
                    </p>
                    {navItems.map((item) => (
                        <Link
                            key={item.name}
                            href={item.href}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold transition ${
                                item.current 
                                    ? 'bg-[#E00D42] text-white shadow-sm shadow-[#E00D42]/30' 
                                    : 'text-slate-400 hover:text-white hover:bg-slate-800'
                            }`}
                        >
                            <item.icon className="w-4 h-4 shrink-0" />
                            <span>{item.name}</span>
                        </Link>
                    ))}

                    <div className="pt-6">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2">
                            Quick Links
                        </p>
                        <Link
                            href={route('marketplace')}
                            className="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition"
                        >
                            <ShoppingBag className="w-4 h-4 text-slate-400" />
                            <span>Customer Marketplace</span>
                        </Link>
                        <Link
                            href={route('profile.edit')}
                            className="flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white hover:bg-slate-800 transition"
                        >
                            <Settings className="w-4 h-4 text-slate-400" />
                            <span>Profile & Security</span>
                        </Link>
                    </div>
                </nav>

                {/* Logout button in sidebar bottom */}
                <div className="p-4 border-t border-slate-800">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-semibold text-rose-400 hover:bg-rose-950/40 hover:text-rose-300 transition"
                    >
                        <LogOut className="w-4 h-4" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Area */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                {/* Dashboard Topbar */}
                <header className="h-20 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div>
                            <h1 className="text-xl font-bold text-slate-900 tracking-tight">{title}</h1>
                            {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        {actions}
                        <Link
                            href={route('marketplace')}
                            className="hidden sm:flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition"
                        >
                            <ArrowLeft className="w-3.5 h-3.5" />
                            <span>Exit to Store</span>
                        </Link>
                    </div>
                </header>

                {/* Flash Messages */}
                {flash.success && (
                    <div className="bg-emerald-500 text-white py-2 px-6 text-xs font-semibold shadow-xs flex items-center gap-2">
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="bg-rose-500 text-white py-2 px-6 text-xs font-semibold shadow-xs">
                        {flash.error}
                    </div>
                )}

                {/* Body Content */}
                <main className="flex-1 p-4 sm:p-8 overflow-y-auto">
                    {children}
                </main>
            </div>
        </div>
    );
}
