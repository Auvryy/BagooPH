import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import BagooLogo from '@/Components/BagooLogo';
import { getDomainUrl } from '@/utils/domain';
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
    ExternalLink,
    ChevronDown,
    Tag,
    MessageSquare,
    Star,
    ShieldAlert,
    TrendingUp,
    CheckCircle2,
    User as UserIcon
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: React.ReactNode;
    actions?: React.ReactNode;
}

export default function DashboardLayout({ children, title, subtitle, actions }: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const userMenuTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

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

    React.useEffect(() => {
        return () => {
            if (userMenuTimeoutRef.current) clearTimeout(userMenuTimeoutRef.current);
        };
    }, []);

    const getNavItems = () => {
        if (role === 'admin') {
            return [
                { name: 'Dashboard', href: route('admin.dashboard'), icon: LayoutDashboard, current: route().current('admin.dashboard') },
                { name: 'KYC Queue', href: route('admin.kyc.index'), icon: ShieldCheck, current: route().current('admin.kyc.*') },
                { name: 'Users', href: route('admin.users'), icon: Users, current: route().current('admin.users*') },
                { name: 'Products', href: route('admin.products'), icon: Package, current: route().current('admin.products*') },
                { name: 'Logistics', href: route('admin.logistics'), icon: Truck, current: route().current('admin.logistics*') },
            ];
        }

        if (role === 'seller') {
            return [
                { name: 'Dashboard', href: route('seller.dashboard'), icon: LayoutDashboard, current: route().current('seller.dashboard') },
                { name: 'Products', href: route('seller.products.index'), icon: Package, current: route().current('seller.products.*') },
                { name: 'Orders', href: route('seller.orders.index'), icon: ShoppingCart, current: route().current('seller.orders.*') },
                { name: 'Vouchers', href: route('seller.vouchers.index'), icon: Tag, current: route().current('seller.vouchers.*') },
                { name: 'Messages', href: route('seller.messages.index'), icon: MessageSquare, current: route().current('seller.messages.*') },
                { name: 'Reviews', href: route('seller.reviews.index'), icon: Star, current: route().current('seller.reviews.*') },
                { name: 'Disputes & Returns', href: route('seller.disputes.index'), icon: ShieldAlert, current: route().current('seller.disputes.*') },
                { name: 'Finances', href: route('seller.reports'), icon: TrendingUp, current: route().current('seller.reports') },
            ];
        }

        if (role === 'courier' || role === 'logistics') {
            return [
                { name: 'Deliveries', href: route('courier.deliveries'), icon: Truck, current: route().current('courier.deliveries') },
            ];
        }

        return [];
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

            {/* Sidebar (Permanently Fixed on Desktop) */}
            <aside className={`
                fixed inset-y-0 left-0 z-50 w-64 bg-white text-slate-700 border-r border-slate-200 
                flex flex-col transition-transform duration-200 ease-in-out
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} 
                lg:static lg:translate-x-0 lg:h-full lg:shrink-0 lg:z-30
            `}>
                
                {/* Brand Header */}
                <div className="p-4 border-b border-slate-100 shrink-0 bg-white">
                    <div className="flex items-center justify-between">
                        <Link href={role === 'seller' ? route('seller.dashboard') : role === 'admin' ? route('admin.dashboard') : '/'} className="flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            <div>
                                <span className="text-base font-black tracking-tight text-slate-900">Bagoo<span className="text-[#E00D42]">PH</span></span>
                                <span className="block text-[9px] uppercase font-bold tracking-widest text-slate-500 -mt-0.5 font-mono">
                                    {role === 'seller' ? 'Seller Centre' : role === 'admin' ? 'Admin Portal' : 'Portal'}
                                </span>
                            </div>
                        </Link>
                        <button 
                            onClick={() => setSidebarOpen(false)}
                            className="lg:hidden p-1 text-slate-400 hover:text-slate-700 cursor-pointer"
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {/* Navigation Sections */}
                <nav className="flex-1 px-3 py-3 space-y-4 overflow-y-auto font-sans scrollbar-thin">
                    
                    {/* Main Navigation */}
                    <div className="space-y-0.5">
                        <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-mono">
                            Menu
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
                            </Link>
                        ))}
                    </div>

                    {/* Quick Links (Storefront & Settings) */}
                    {(user?.shop || role === 'seller') && (
                        <div className="pt-2 border-t border-slate-100 space-y-0.5">
                            <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-mono">
                                Quick Links
                            </p>
                            {user?.shop && (
                                <a
                                    href={getDomainUrl('buyer', `/shop/${user.shop.slug}`)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition group"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <ShoppingBag className="w-4 h-4 text-slate-400 group-hover:text-[#E00D42] transition" />
                                        <span>View Storefront</span>
                                    </div>
                                    <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                                </a>
                            )}
                            <Link
                                href={route('seller.settings')}
                                className={`flex items-center justify-between px-3 py-2 rounded-lg text-xs font-semibold transition group ${
                                    route().current('seller.settings*') || route().current('seller.profile*')
                                        ? 'bg-slate-900 text-white shadow-xs'
                                        : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
                                }`}
                            >
                                <div className="flex items-center gap-2.5">
                                    <Settings className={`w-4 h-4 shrink-0 ${
                                        route().current('seller.settings*') || route().current('seller.profile*')
                                            ? 'text-[#E00D42]'
                                            : 'text-slate-400 group-hover:text-slate-900'
                                    }`} />
                                    <span>Settings</span>
                                </div>
                            </Link>
                        </div>
                    )}
                </nav>

                {/* Sidebar Bottom: Sign Out Button */}
                <div className="p-3 border-t border-slate-100 bg-white shrink-0 mt-auto">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-slate-700 hover:text-rose-600 hover:bg-rose-50 transition border border-slate-200 hover:border-rose-200 uppercase tracking-wider shadow-2xs cursor-pointer font-mono"
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>

            {/* Main Content Column */}
            <div className="flex-1 flex flex-col h-full min-w-0 overflow-hidden">
                
                {/* Topbar (Clean Fixed Header) */}
                <header className="h-16 bg-white border-b border-slate-200 px-4 sm:px-8 flex items-center justify-between shrink-0 shadow-2xs z-20">
                    <div className="flex items-center gap-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div>
                            <h1 className="text-base font-black text-slate-900 tracking-tight">{title}</h1>
                            {subtitle && <div className="text-[11px] text-slate-500 font-medium flex items-center gap-2 mt-0.5">{subtitle}</div>}
                        </div>
                    </div>

                    {/* Topbar Actions & User Avatar */}
                    <div className="flex items-center gap-3">
                        {actions}

                        {/* Merchant / Admin User Avatar Interactive Dropdown */}
                        <div 
                            className="relative"
                            onMouseEnter={handleUserMenuEnter}
                            onMouseLeave={handleUserMenuLeave}
                        >
                            <Link
                                href={role === 'seller' ? route('seller.profile') : route('profile.edit')}
                                className="flex items-center gap-2.5 p-1 rounded-xs hover:bg-slate-100 transition group focus:outline-hidden border border-transparent hover:border-slate-300"
                            >
                                {user?.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="w-8 h-8 rounded-xs object-cover border border-slate-200 shrink-0"
                                    />
                                ) : (
                                    <div className="w-8 h-8 rounded-xs bg-slate-950 text-white font-bold text-xs flex items-center justify-center shadow-xs group-hover:bg-[#E00D42] transition font-mono">
                                        {user?.name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <div className="hidden sm:block text-left font-mono">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-bold text-slate-800 leading-tight group-hover:text-[#E00D42] transition">{user?.name}</p>
                                        <ChevronDown className={`w-3 h-3 text-slate-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                                    </div>
                                    <span className="text-[10px] text-slate-500 font-bold uppercase">
                                        {role === 'seller' ? 'Verified Merchant' : role === 'admin' ? 'Super Admin' : 'Authorized User'}
                                    </span>
                                </div>
                            </Link>

                            {/* Dropdown Menu */}
                            {userMenuOpen && (
                                <div 
                                    className="absolute right-0 top-full -mt-0.5 pt-1 w-56 z-50 animate-scale-in"
                                    onMouseEnter={handleUserMenuEnter}
                                    onMouseLeave={handleUserMenuLeave}
                                >
                                    <div className="bg-white rounded-md shadow-2xl border border-slate-300 py-1.5 text-slate-800 font-sans">
                                        <div className="px-4 py-2.5 border-b border-slate-200 font-mono text-xs flex items-center gap-2.5">
                                            {user?.avatar ? (
                                                <img
                                                    src={user.avatar}
                                                    alt={user.name}
                                                    className="w-8 h-8 rounded-xs object-cover border border-slate-200 shrink-0"
                                                />
                                            ) : (
                                                <div className="w-8 h-8 rounded-xs bg-slate-900 text-white font-bold text-xs flex items-center justify-center font-mono shrink-0">
                                                    {user?.name.charAt(0).toUpperCase()}
                                                </div>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="font-bold text-slate-900 truncate">{user?.name}</p>
                                                <p className="text-[10px] text-slate-500 truncate">{user?.email}</p>
                                            </div>
                                        </div>

                                        {role === 'seller' && (
                                            <Link
                                                href={route('seller.profile')}
                                                className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <UserIcon className="w-4 h-4 text-[#E00D42]" />
                                                <span>Profile</span>
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

                                        {role === 'seller' && user?.shop && (
                                            <a
                                                href={route('seller.preview')}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center justify-between px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                            >
                                                <div className="flex items-center gap-2.5">
                                                    <Store className="w-4 h-4 text-[#E00D42]" />
                                                    <span>View Storefront</span>
                                                </div>
                                                <ExternalLink className="w-3.5 h-3.5 text-slate-400" />
                                            </a>
                                        )}

                                        <Link
                                            href={role === 'seller' ? route('seller.profile') : route('profile.edit')}
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                        >
                                            <ShieldCheck className="w-4 h-4 text-slate-400" />
                                            <span>Security</span>
                                        </Link>

                                        <div className="border-t border-slate-200 mt-1 pt-1">
                                            <Link
                                                href={route('logout')}
                                                method="post"
                                                as="button"
                                                className="w-full text-left flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-rose-600 hover:bg-rose-50 transition cursor-pointer"
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

                {/* Flash Alerts */}
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

                {/* Body Content */}
                <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 bg-[#F8FAFC]">
                    <div className="max-w-7xl mx-auto space-y-6">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
