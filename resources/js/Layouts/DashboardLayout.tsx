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
    CheckCircle2,
    TrendingUp,
    Bell,
    Search,
    ExternalLink,
    ChevronDown,
    BarChart3,
    FileText,
    HelpCircle
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
                { name: 'Catalog & Inventory', href: route('seller.products.index'), icon: Package, current: route().current('seller.products.*') },
                { name: 'Order Fulfillment', href: route('seller.orders.index'), icon: ShoppingCart, current: route().current('seller.orders.*') },
                { name: 'Financial Reports', href: route('seller.reports'), icon: TrendingUp, current: route().current('seller.reports') },
                { name: 'Storefront Settings', href: route('seller.settings'), icon: Store, current: route().current('seller.settings') },
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
                return { label: 'Platform Admin', color: 'bg-rose-50 text-[#E00D42] border-rose-200' };
            case 'seller':
                return { label: 'Verified Seller', color: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
            case 'courier':
            case 'logistics':
                return { label: 'Active Courier', color: 'bg-amber-50 text-amber-700 border-amber-200' };
            default:
                return { label: 'Valued Buyer', color: 'bg-indigo-50 text-indigo-700 border-indigo-200' };
        }
    };

    const roleBadge = getRoleBadge();

    return (
        <div className="min-h-screen bg-slate-50 flex flex-col lg:flex-row text-slate-900 font-sans antialiased selection:bg-[#E00D42] selection:text-white">
            
            {/* Mobile Header Bar */}
            <div className="lg:hidden h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 sticky top-0 z-40 shadow-xs">
                <Link href={route('marketplace')} className="flex items-center gap-2">
                    <BagooLogo className="w-8 h-8" rounded="rounded-lg" />
                    <span className="text-lg font-black tracking-tight text-slate-900">Bagoo<span className="text-[#E00D42]">PH</span> <span className="text-xs font-mono font-bold text-slate-400">SELLER</span></span>
                </Link>
                <button 
                    onClick={() => setSidebarOpen(!sidebarOpen)}
                    className="p-2 rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200"
                >
                    {sidebarOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
                </button>
            </div>

            {/* Backdrop for mobile */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 bg-black/40 backdrop-blur-xs z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Clean Light Sidebar Navigation */}
            <aside className={`fixed inset-y-0 left-0 z-50 w-64 bg-white text-slate-700 border-r border-slate-200 flex flex-col transition-transform duration-200 lg:translate-x-0 ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} lg:static lg:z-auto`}>
                
                {/* Brand Header */}
                <div className="h-16 flex items-center justify-between px-6 border-b border-slate-100">
                    <Link href={route('seller.dashboard')} className="flex items-center gap-2.5">
                        <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                        <div>
                            <span className="text-lg font-black tracking-tight text-slate-900">Bagoo<span className="text-[#E00D42]">PH</span></span>
                            <span className="block text-[9px] uppercase font-bold tracking-wider text-slate-400 -mt-1 font-mono">
                                Seller Centre
                            </span>
                        </div>
                    </Link>
                    <button 
                        onClick={() => setSidebarOpen(false)}
                        className="lg:hidden p-1 text-slate-400 hover:text-slate-700"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Verified Store Banner Tag */}
                <div className="p-3.5 mx-4 mt-4 bg-slate-50 rounded-2xl border border-slate-200/80 flex items-center gap-3">
                    <div className="w-9 h-9 rounded-xl bg-emerald-100 text-emerald-700 flex items-center justify-center font-bold text-xs shrink-0">
                        <Store className="w-4 h-4" />
                    </div>
                    <div className="overflow-hidden min-w-0">
                        <p className="text-xs font-bold text-slate-900 truncate">{user?.shop?.name || user?.name + "'s Store"}</p>
                        <div className="flex items-center gap-1 mt-0.5">
                            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span className="text-[10px] font-mono text-slate-500 font-bold uppercase">Store Online</span>
                        </div>
                    </div>
                </div>

                {/* Navigation Links */}
                <nav className="flex-1 px-4 py-4 space-y-1 overflow-y-auto">
                    <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 font-mono">
                        Management
                    </p>
                    {navItems.map((item) => (
                        <Link
                            key={item.name}
                            href={item.href}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded-xl text-xs font-bold transition ${
                                item.current 
                                    ? 'bg-[#E00D42] text-white shadow-xs' 
                                    : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                            }`}
                        >
                            <item.icon className="w-4 h-4 shrink-0" />
                            <span>{item.name}</span>
                        </Link>
                    ))}

                    <div className="pt-4 mt-2 border-t border-slate-100">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-2 font-mono">
                            Shortcuts
                        </p>
                        {user?.shop && (
                            <Link
                                href={route('shop.show', user.shop.slug)}
                                target="_blank"
                                className="flex items-center justify-between px-3.5 py-2 rounded-xl text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                            >
                                <div className="flex items-center gap-2.5">
                                    <ShoppingBag className="w-4 h-4 text-[#E00D42]" />
                                    <span>Live Storefront</span>
                                </div>
                                <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                            </Link>
                        )}
                        <Link
                            href={route('buyer.index')}
                            className="flex items-center gap-2.5 px-3.5 py-2 rounded-xl text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                        >
                            <ArrowLeft className="w-4 h-4 text-slate-400" />
                            <span>Buyer Marketplace</span>
                        </Link>
                        <Link
                            href={route('profile.edit')}
                            className="flex items-center gap-2.5 px-3.5 py-2 rounded-xl text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                        >
                            <Settings className="w-4 h-4 text-slate-400" />
                            <span>Account & Password</span>
                        </Link>
                    </div>
                </nav>

                {/* Sidebar Bottom: Sign Out */}
                <div className="p-4 border-t border-slate-100 bg-slate-50/50">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold text-rose-600 hover:bg-rose-50 transition border border-rose-200 font-mono uppercase"
                    >
                        <LogOut className="w-4 h-4" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Area */}
            <div className="flex-1 flex flex-col min-w-0 overflow-hidden">
                
                {/* Clean Light Topbar */}
                <header className="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shadow-2xs">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div>
                            <h1 className="text-lg font-black text-slate-900 tracking-tight">{title}</h1>
                            {subtitle && <p className="text-xs text-slate-500 font-medium">{subtitle}</p>}
                        </div>
                    </div>

                    {/* Topbar Actions & Profile */}
                    <div className="flex items-center gap-3">
                        {actions}

                        {user?.shop && (
                            <Link
                                href={route('shop.show', user.shop.slug)}
                                target="_blank"
                                className="hidden md:flex items-center gap-1 px-3 py-1.5 text-xs font-bold font-mono text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl transition border border-slate-200"
                            >
                                <Store className="w-3.5 h-3.5 text-[#E00D42]" />
                                <span>Preview Store</span>
                                <ExternalLink className="w-3 h-3 text-slate-400 ml-0.5" />
                            </Link>
                        )}

                        <div className="w-px h-6 bg-slate-200 mx-1 hidden sm:block"></div>

                        {/* User Avatar Pill */}
                        <div className="flex items-center gap-2.5 pl-1">
                            <div className="w-8 h-8 rounded-full bg-slate-900 text-white font-bold text-xs flex items-center justify-center shadow-xs">
                                {user?.name.charAt(0)}
                            </div>
                            <div className="hidden sm:block text-left font-mono">
                                <p className="text-xs font-bold text-slate-800 leading-tight">{user?.name}</p>
                                <span className="text-[10px] text-emerald-600 font-bold uppercase">Seller Verified</span>
                            </div>
                        </div>
                    </div>
                </header>

                {/* Flash Messages */}
                {flash.success && (
                    <div className="bg-emerald-600 text-white py-2.5 px-6 text-xs font-bold font-mono shadow-xs flex items-center gap-2">
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="bg-[#E00D42] text-white py-2.5 px-6 text-xs font-bold font-mono shadow-xs">
                        {flash.error}
                    </div>
                )}

                {/* Body Content */}
                <main className="flex-1 p-4 sm:p-6 lg:p-8 overflow-y-auto bg-slate-50/70">
                    <div className="max-w-7xl mx-auto">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
