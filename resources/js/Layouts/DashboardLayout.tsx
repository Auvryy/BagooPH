import React, { useState, useEffect } from 'react';
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
    HelpCircle,
    Clock,
    Copy,
    Check,
    Radio,
    Terminal,
    Sparkles,
    Tag,
    MessageSquare,
    Star,
    ShieldAlert
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
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const userMenuTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);
    const [copiedStoreLink, setCopiedStoreLink] = useState(false);
    const [currentTime, setCurrentTime] = useState('');

    const user = auth.user;
    const role = user?.role || 'buyer';

    const handleUserMenuEnter = () => {
        if (userMenuTimeoutRef.current) clearTimeout(userMenuTimeoutRef.current);
        setUserMenuOpen(true);
    };

    const handleUserMenuLeave = () => {
        userMenuTimeoutRef.current = setTimeout(() => {
            setUserMenuOpen(false);
        }, 250);
    };

    useEffect(() => {
        const updateClock = () => {
            const now = new Date();
            setCurrentTime(now.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true }));
        };
        updateClock();
        const timer = setInterval(updateClock, 1000);
        return () => {
            clearInterval(timer);
            if (userMenuTimeoutRef.current) clearTimeout(userMenuTimeoutRef.current);
        };
    }, []);

    const copyStoreUrl = () => {
        if (user?.shop) {
            const url = `${window.location.origin}/shop/${user.shop.slug}`;
            navigator.clipboard.writeText(url);
            setCopiedStoreLink(true);
            setTimeout(() => setCopiedStoreLink(false), 2000);
        }
    };

    const getNavItems = () => {
        if (role === 'admin') {
            return [
                { name: 'Platform Overview', href: route('admin.dashboard'), icon: LayoutDashboard, current: route().current('admin.dashboard') },
                { name: 'KYC Verification Queue', href: route('admin.kyc.index'), icon: ShieldCheck, current: route().current('admin.kyc.*') },
                { name: 'User & Role Control', href: route('admin.users'), icon: Users, current: route().current('admin.users*') },
                { name: 'Catalog Moderation', href: route('admin.products'), icon: Package, current: route().current('admin.products*') },
                { name: 'Logistics Hub & Fleet', href: route('admin.logistics'), icon: Truck, current: route().current('admin.logistics*') },
                { name: 'Security & Settings', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
            ];
        }

        if (role === 'seller') {
            return [
                { name: 'Merchant Cockpit', href: route('seller.dashboard'), icon: LayoutDashboard, current: route().current('seller.dashboard'), tag: 'LIVE' },
                { name: 'Inventory & Catalog', href: route('seller.products.index'), icon: Package, current: route().current('seller.products.*') },
                { name: 'Fulfillment & Waybills', href: route('seller.orders.index'), icon: ShoppingCart, current: route().current('seller.orders.*') },
                { name: 'Vouchers & Promos', href: route('seller.vouchers.index'), icon: Tag, current: route().current('seller.vouchers.*') },
                { name: 'Customer Messages', href: route('seller.messages.index'), icon: MessageSquare, current: route().current('seller.messages.*') },
                { name: 'Customer Reviews', href: route('seller.reviews.index'), icon: Star, current: route().current('seller.reviews.*') },
                { name: 'Disputes & Returns', href: route('seller.disputes.index'), icon: ShieldAlert, current: route().current('seller.disputes.*') },
                { name: 'Financial Statements', href: route('seller.reports'), icon: TrendingUp, current: route().current('seller.reports') },
                { name: 'Storefront & Logistics', href: route('seller.settings'), icon: Store, current: route().current('seller.settings') },
            ];
        }

        if (role === 'courier' || role === 'logistics') {
            return [
                { name: 'Delivery Pool', href: route('courier.deliveries'), icon: Truck, current: route().current('courier.deliveries') },
                { name: 'Account Settings', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
            ];
        }

        return [
            { name: 'Buyer Home', href: route('buyer.index'), icon: ShoppingBag, current: route().current('buyer.index') },
            { name: 'My Orders & Tracking', href: route('buyer.orders.index'), icon: Package, current: route().current('buyer.orders.*') },
            { name: 'Shopping Bag', href: route('buyer.cart'), icon: ShoppingCart, current: route().current('buyer.cart') },
            { name: 'Profile & Security', href: route('profile.edit'), icon: Settings, current: route().current('profile.edit') },
        ];
    };

    const navItems = getNavItems();

    return (
        <div className="h-screen w-full flex overflow-hidden bg-[#F8FAFC] text-slate-900 font-sans antialiased selection:bg-[#E00D42] selection:text-white">
            
            {/* Backdrop for mobile drawer */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 bg-black/50 backdrop-blur-xs z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Bespoke Swiss-Style Merchant Workstation Sidebar (Permanently Fixed on Desktop) */}
            <aside className={`
                fixed inset-y-0 left-0 z-50 w-72 bg-white text-slate-700 border-r border-slate-200 
                flex flex-col transition-transform duration-200 ease-in-out
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} 
                lg:static lg:translate-x-0 lg:h-full lg:shrink-0 lg:z-30
            `}>
                
                {/* Brand & Terminal Moniker Header */}
                <div className="p-4 border-b border-slate-100 space-y-2.5 shrink-0 bg-white">
                    <div className="flex items-center justify-between">
                        <Link href={route('seller.dashboard')} className="flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            <div>
                                <span className="text-base font-black tracking-tight text-slate-900">Bagoo<span className="text-[#E00D42]">PH</span></span>
                                <span className="block text-[9px] uppercase font-bold tracking-widest text-[#E00D42] -mt-0.5 font-mono">
                                    Merchant Workstation
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

                    {/* Merchant Store Identifier Card */}
                    <div className="p-2.5 rounded-xl bg-slate-50 border border-slate-200/80 space-y-1.5">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2 min-w-0">
                                <span className="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span>
                                <p className="text-xs font-bold text-slate-900 truncate">{user?.shop?.name || user?.name + "'s Store"}</p>
                            </div>
                            <span className="px-1.5 py-0.2 rounded bg-emerald-100 text-emerald-800 text-[9px] font-mono font-bold uppercase">MALL</span>
                        </div>

                        {user?.shop && (
                            <div className="flex items-center justify-between pt-1 border-t border-slate-200/60 font-mono text-[10px] text-slate-500">
                                <span className="truncate">/shop/{user.shop.slug}</span>
                                <button
                                    onClick={copyStoreUrl}
                                    className="p-0.5 hover:text-[#E00D42] text-slate-400 transition"
                                    title="Copy Store Link"
                                >
                                    {copiedStoreLink ? <Check className="w-3 h-3 text-emerald-600" /> : <Copy className="w-3 h-3" />}
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Navigation Sections (Independent Scrollable Nav) */}
                <nav className="flex-1 px-3 py-3 space-y-4 overflow-y-auto font-sans scrollbar-thin">
                    
                    {/* Operations Group */}
                    <div className="space-y-1">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-mono flex items-center justify-between">
                            <span>Operations & Workflows</span>
                            <span className="text-[9px] text-slate-400 font-normal">PHT</span>
                        </p>
                        {navItems.map((item) => (
                            <Link
                                key={item.name}
                                href={item.href}
                                className={`flex items-center justify-between px-3 py-2 rounded-lg text-xs font-semibold transition group ${
                                    item.current 
                                        ? 'bg-slate-900 text-white shadow-xs' 
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                <div className="flex items-center gap-2.5">
                                    <item.icon className={`w-4 h-4 shrink-0 ${item.current ? 'text-[#E00D42]' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                    <span>{item.name}</span>
                                </div>
                                {item.tag && (
                                    <span className={`text-[9px] font-mono px-1.5 py-0.2 rounded font-bold ${
                                        item.current ? 'bg-[#E00D42] text-white' : 'bg-emerald-50 text-emerald-700'
                                    }`}>
                                        {item.tag}
                                    </span>
                                )}
                            </Link>
                        ))}
                    </div>

                    {/* Shortcuts & Network */}
                    <div className="pt-2.5 border-t border-slate-100 space-y-1">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-mono">
                            Network Shortcuts
                        </p>
                        {user?.shop && (
                            <Link
                                href={route('shop.show', user.shop.slug)}
                                target="_blank"
                                className="flex items-center justify-between px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                            >
                                <div className="flex items-center gap-2.5">
                                    <ShoppingBag className="w-4 h-4 text-[#E00D42]" />
                                    <span>Live Public Storefront</span>
                                </div>
                                <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                            </Link>
                        )}
                        {role !== 'admin' && (
                            <Link
                                href={route('buyer.index')}
                                className="flex items-center gap-2.5 px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                            >
                                <ArrowLeft className="w-4 h-4 text-slate-400" />
                                <span>Switch to Buyer Mode</span>
                            </Link>
                        )}
                        <Link
                            href={route('profile.edit')}
                            className="flex items-center gap-2.5 px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition"
                        >
                            <Settings className="w-4 h-4 text-slate-400" />
                            <span>Security & Account</span>
                        </Link>
                    </div>
                </nav>

                {/* Sidebar Bottom: Anchored Clock & Permanent Visible Sign Out Button */}
                <div className="p-3.5 border-t border-slate-100 bg-slate-50/70 shrink-0 font-mono mt-auto space-y-2">
                    <div className="flex items-center justify-between text-[10px] text-slate-500">
                        <div className="flex items-center gap-1.5">
                            <Clock className="w-3 h-3 text-slate-400" />
                            <span>{currentTime || '12:00:00 PM'}</span>
                        </div>
                        <span className="text-[9px] text-emerald-600 font-bold">18ms Latency</span>
                    </div>

                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-rose-600 hover:bg-rose-50 transition border border-rose-200 uppercase tracking-wider shadow-2xs"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Column (Full Viewport Height, Isolated Scrolling Body) */}
            <div className="flex-1 flex flex-col h-full min-w-0 overflow-hidden">
                
                {/* Cockpit Topbar (Locked Fixed Header) */}
                <header className="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shrink-0 shadow-2xs z-20">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-base font-black text-slate-900 tracking-tight">{title}</h1>
                                <span className="hidden sm:inline-block text-[10px] font-mono px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold uppercase">PRO</span>
                            </div>
                            {subtitle && <p className="text-[11px] text-slate-500 font-medium">{subtitle}</p>}
                        </div>
                    </div>

                    {/* Topbar Actions & User Avatar */}
                    <div className="flex items-center gap-3">
                        {actions}

                        {/* Merchant / Admin User Avatar Interactive Dropdown with Seamless Hover Bridge */}
                        <div 
                            className="relative"
                            onMouseEnter={handleUserMenuEnter}
                            onMouseLeave={handleUserMenuLeave}
                        >
                            <button
                                type="button"
                                onClick={() => setUserMenuOpen(!userMenuOpen)}
                                className="flex items-center gap-2.5 p-1 rounded-xs hover:bg-slate-100 transition group focus:outline-hidden border border-transparent hover:border-slate-300"
                            >
                                <div className="w-8 h-8 rounded-xs bg-slate-950 text-white font-bold text-xs flex items-center justify-center shadow-xs group-hover:bg-[#E00D42] transition font-mono">
                                    {user?.name.charAt(0)}
                                </div>
                                <div className="hidden sm:block text-left font-mono">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-bold text-slate-800 leading-tight group-hover:text-[#E00D42] transition">{user?.name}</p>
                                        <ChevronDown className={`w-3 h-3 text-slate-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                                    </div>
                                    <span className="text-[10px] text-emerald-600 font-bold uppercase">
                                        {role === 'seller' ? 'Verified Merchant' : role === 'admin' ? 'Super Admin' : 'Authorized User'}
                                    </span>
                                </div>
                            </button>

                            {/* Dropdown Menu with Instant Seamless Overlap */}
                            {userMenuOpen && (
                                <div 
                                    className="absolute right-0 top-full -mt-0.5 pt-1 w-60 z-50 animate-scale-in"
                                    onMouseEnter={handleUserMenuEnter}
                                    onMouseLeave={handleUserMenuLeave}
                                >
                                    <div className="bg-white rounded-md shadow-2xl border border-slate-300 py-1.5 text-slate-800 font-sans">
                                        <div className="px-4 py-2 border-b border-slate-200 font-mono text-xs">
                                            <p className="font-bold text-slate-900 truncate">{user?.name}</p>
                                            <p className="text-[10px] text-slate-500 truncate">{user?.email}</p>
                                        </div>

                                        {role === 'seller' && user?.shop && (
                                            <Link
                                                href={route('shop.show', user.shop.slug)}
                                                target="_blank"
                                                className="flex items-center justify-between px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <div className="flex items-center gap-2.5">
                                                    <Store className="w-4 h-4 text-[#E00D42]" />
                                                    <span>View Public Storefront</span>
                                                </div>
                                                <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                                            </Link>
                                        )}

                                        {role === 'seller' && (
                                            <Link
                                                href={route('seller.settings')}
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <Settings className="w-4 h-4 text-slate-400" />
                                                <span>Store Settings</span>
                                            </Link>
                                        )}

                                        <Link
                                            href={route('profile.edit')}
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                        >
                                            <ShieldCheck className="w-4 h-4 text-emerald-500" />
                                            <span>Account & Security</span>
                                        </Link>

                                        {role !== 'admin' && (
                                            <Link
                                                href={route('buyer.index')}
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <ArrowLeft className="w-4 h-4 text-indigo-500" />
                                                <span>Switch to Buyer Mode</span>
                                            </Link>
                                        )}

                                        <div className="border-t border-slate-200 mt-1 pt-1">
                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50 transition"
                                            >
                                                <LogOut className="w-4 h-4" />
                                                <span>Sign Out</span>
                                            </Link>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </header>

                {/* Flash Alerts (Shrink 0) */}
                {flash.success && (
                    <div className="bg-emerald-600 text-white py-2.5 px-6 text-xs font-bold font-mono shadow-xs flex items-center gap-2 shrink-0">
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="bg-[#E00D42] text-white py-2.5 px-6 text-xs font-bold font-mono shadow-xs shrink-0">
                        {flash.error}
                    </div>
                )}

                {/* Body Content (The ONLY Scrolling Viewport) */}
                <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-[#F8FAFC]">
                    <div className="max-w-7xl mx-auto space-y-6">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
