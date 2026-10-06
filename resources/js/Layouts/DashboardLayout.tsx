import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
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
    Check,
    Plus,
    User as UserIcon,
    ScanLine,
    Building2,
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: React.ReactNode;
    actions?: React.ReactNode;
}

export default function DashboardLayout({ children, title, subtitle, actions }: Props) {
    const { auth, flash } = usePage<PageProps>().props;
    const { url, component } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [shopSwitcherOpen, setShopSwitcherOpen] = useState(false);
    const [hubSwitcherOpen, setHubSwitcherOpen] = useState(false);
    const userMenuTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

    const user = auth.user;
    const role = user?.role || 'buyer';
    const currentShop = user?.shop;
    const shops = user?.sellerShops ?? (currentShop ? [currentShop] : []);
    const activeHub = (user as any)?.activeHub;
    const allHubs = (user as any)?.allHubs || [];
    const logisticsCompany = (user as any)?.logisticsCompany;
    const canSwitchHubs = Boolean((user as any)?.canSwitchHubs);

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
                { name: 'Dashboard', href: route('admin.dashboard'), icon: LayoutDashboard, current: component === 'Admin/Dashboard' || route().current('admin.dashboard') || url === '/admin/dashboard' || url === '/admin' },
                { name: 'Shop reviews', href: route('admin.shops.index'), icon: Store, current: component === 'Admin/ShopReviews' },
                { name: 'KYC Queue', href: route('admin.kyc.index'), icon: ShieldCheck, current: component.startsWith('Admin/Kyc') || route().current('admin.kyc.*') || url.startsWith('/admin/kyc') },
                { name: 'Users', href: route('admin.users'), icon: Users, current: component.startsWith('Admin/Users') || component === 'Admin/AccountContext' || route().current('admin.users*') || url.startsWith('/admin/users') },
                { name: 'Products', href: route('admin.products'), icon: Package, current: component.startsWith('Admin/Products') || route().current('admin.products*') || url.startsWith('/admin/products') },
                { name: 'Logistics', href: route('admin.logistics'), icon: Truck, current: component.startsWith('Admin/Logistics') || route().current('admin.logistics*') || url.startsWith('/admin/logistics') },
                { name: 'Governance history', href: '/governance-history', icon: ShieldCheck, current: component === 'Governance/History' || component === 'Governance/HistoryDetail' },
                ...(user?.canManageResources && user.resourceGovernanceUrl ? [{ name: 'Resource activity', href: user.resourceGovernanceUrl, icon: ShieldAlert, current: component === 'Governance/Resources' || component === 'Governance/ResourceRestriction' }] : []),
            ];
        }

        if (role === 'seller') {
            return [
                { name: 'Shops', href: route('seller.shops.index'), icon: Store, current: component === 'Seller/Shops' },
                { 
                    name: 'Dashboard', 
                    href: route('seller.dashboard'), 
                    icon: LayoutDashboard, 
                    current: component === 'Seller/Dashboard' || route().current('seller.dashboard') || route().current('dashboard') || url === '/seller/dashboard' || url === '/dashboard' || url === '/seller'
                },
                { 
                    name: 'Products', 
                    href: route('seller.products.index'), 
                    icon: Package, 
                    current: component.startsWith('Seller/Product') || route().current('seller.products.*') || url.startsWith('/seller/products') 
                },
                { 
                    name: 'Orders', 
                    href: route('seller.orders.index'), 
                    icon: ShoppingCart, 
                    current: component.startsWith('Seller/Order') || route().current('seller.orders.*') || url.startsWith('/seller/orders') 
                },
                { 
                    name: 'Vouchers', 
                    href: route('seller.vouchers.index'), 
                    icon: Tag, 
                    current: component.startsWith('Seller/Voucher') || route().current('seller.vouchers.*') || url.startsWith('/seller/vouchers') 
                },
                { 
                    name: 'Messages', 
                    href: route('seller.messages.index'), 
                    icon: MessageSquare, 
                    current: component.startsWith('Seller/Messages') || route().current('seller.messages.*') || url.startsWith('/seller/messages') 
                },
                { 
                    name: 'Reviews', 
                    href: route('seller.reviews.index'), 
                    icon: Star, 
                    current: component.startsWith('Seller/Reviews') || route().current('seller.reviews.*') || url.startsWith('/seller/reviews') 
                },
                { 
                    name: 'Disputes & Returns', 
                    href: route('seller.disputes.index'), 
                    icon: ShieldAlert, 
                    current: component.startsWith('Seller/Dispute') || route().current('seller.disputes.*') || url.startsWith('/seller/disputes') 
                },
                { 
                    name: 'Finances', 
                    href: route('seller.reports'), 
                    icon: TrendingUp, 
                    current: component.startsWith('Seller/Reports') || route().current('seller.reports') || url.startsWith('/seller/reports') 
                },
            ];
        }

        if (role === 'courier') {
            return [
                { name: 'Deliveries', href: route('courier.deliveries'), icon: Truck, current: component.startsWith('Courier/Deliveries') || route().current('courier.deliveries') || url.startsWith('/courier/deliveries') },
            ];
        }

        if (role === 'logistics') {
            return [
                { 
                    name: 'Overview', 
                    href: route('hub.dashboard'), 
                    icon: LayoutDashboard, 
                    current: component === 'Hub/Dashboard' || route().current('hub.dashboard') || (route().current('hub.index') && !url.includes('/scan')) || url === '/hub' || url === '/hub/dashboard'
                },
                { 
                    name: 'Scan Station', 
                    href: route('hub.scan.station'), 
                    icon: ScanLine, 
                    current: component === 'Hub/ScanStation' || route().current('hub.scan.station') || url.startsWith('/hub/scan') 
                },
                { 
                    name: 'Facility Network', 
                    href: route('hub.network'), 
                    icon: Building2, 
                    current: component === 'Hub/Network' || route().current('hub.network') || url.startsWith('/hub/network') 
                },
                { 
                    name: 'Fleet Management', 
                    href: route('hub.fleet'), 
                    icon: Truck, 
                    current: component === 'Hub/Fleet' || route().current('hub.fleet') || url.startsWith('/hub/fleet') 
                },
                { 
                    name: 'Parcels & Waybills', 
                    href: route('hub.deliveries'), 
                    icon: Package, 
                    current: component === 'Hub/Deliveries' || route().current('hub.deliveries') || url.startsWith('/hub/deliveries') 
                },
                { 
                    name: 'Counter Pickup', 
                    href: route('hub.counter'), 
                    icon: Store, 
                    current: component === 'Hub/CounterPickup' || route().current('hub.counter') || url.startsWith('/hub/counter') 
                },
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
                        <Link href={role === 'seller' ? route('seller.dashboard') : role === 'admin' ? route('admin.dashboard') : role === 'logistics' ? route('hub.dashboard') : '/'} className="flex items-center gap-2.5">
                            <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            <div>
                                <span className="text-base font-black tracking-tight text-slate-900">Bagoo<span className="text-[#E00D42]">PH</span></span>
                                <span className="block text-[9px] uppercase font-bold tracking-widest text-slate-500 -mt-0.5 font-sans">
                                    {role === 'seller' ? 'Seller Centre' : role === 'admin' ? 'Admin Portal' : role === 'logistics' ? 'Logistics Hub' : 'Portal'}
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

                {/* Active Store Profile & Switcher for Merchants */}
                {role === 'seller' && currentShop && (
                    <div className="p-3 border-b border-slate-100 bg-slate-50/50 shrink-0">
                        <div className="flex items-center justify-between mb-1.5">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-sans flex items-center gap-1">
                                <Store className="w-3 h-3 text-[#E00D42]" /> Store Profile
                            </span>
                            {currentShop.root_category && (
                                <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-200">
                                    {currentShop.root_category.name}
                                </span>
                            )}
                        </div>

                        {/* Switcher Dropdown Button */}
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => setShopSwitcherOpen(!shopSwitcherOpen)}
                                className="w-full flex items-center justify-between p-2 rounded-lg bg-white border border-slate-200 hover:border-slate-300 text-left transition shadow-2xs group cursor-pointer"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-bold text-slate-800 truncate group-hover:text-[#E00D42] transition">{currentShop.name}</p>
                                    <p className="text-[10px] text-slate-500 font-sans truncate">{shops.length} profile{shops.length === 1 ? '' : 's'} managed</p>
                                </div>
                                <ChevronDown className={`w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5 transition-transform ${shopSwitcherOpen ? 'rotate-180' : ''}`} />
                            </button>

                            {shopSwitcherOpen && (
                                <div className="absolute left-0 right-0 top-full mt-1 bg-white rounded-lg shadow-xl border border-slate-200 py-1.5 z-50 text-slate-800">
                                    <div className="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 font-sans">
                                        Switch Store Profile
                                    </div>
                                    <div className="max-h-48 overflow-y-auto divide-y divide-slate-100">
                                        {shops.map((s) => (
                                            <button
                                                key={s.id}
                                                disabled={!s.eligible}
                                                type="button"
                                                onClick={() => {
                                                    setShopSwitcherOpen(false);
                                                    if (s.id !== currentShop.id) {
                                                        router.post(route('seller.shops.switch'), { shop_id: s.id }, { preserveScroll: true });
                                                    }
                                                }}
                                                className={`w-full text-left px-3 py-2 text-xs flex items-center justify-between transition cursor-pointer hover:bg-slate-50 ${
                                                    s.id === currentShop.id ? 'bg-slate-50 font-bold text-[#E00D42]' : 'text-slate-700'
                                                }`}
                                            >
                                                <div className="min-w-0 flex-1 pr-2">
                                                    <p className="truncate">{s.name}</p>
                                                    <p className="text-[10px] text-slate-400 font-sans truncate">
                                                        {s.root_category?.name || 'Missing category'} · {s.review_status?.replaceAll('_', ' ') || 'Review required'} · {s.status}
                                                    </p>
                                                </div>
                                                {s.id === currentShop.id && (
                                                    <Check className="w-3.5 h-3.5 text-[#E00D42] shrink-0" />
                                                )}
                                            </button>
                                        ))}
                                    </div>

                                    <div className="p-2 border-t border-slate-100">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setShopSwitcherOpen(false);
                                                router.visit(route('seller.shops.index'));
                                            }}
                                            className="w-full flex items-center justify-center gap-1.5 py-1.5 px-2.5 rounded bg-slate-900 text-white hover:bg-slate-800 text-[11px] font-semibold transition cursor-pointer"
                                        >
                                            <Plus className="w-3 h-3" />
                                            <span>Manage shops and approvals</span>
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Active Hub Facility & Switcher for Logistics */}
                {role === 'logistics' && (
                    <div className="p-3 border-b border-slate-100 bg-slate-50/50 shrink-0">
                        <div className="flex items-center justify-between mb-1.5">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-sans flex items-center gap-1">
                                <Building2 className="w-3 h-3 text-[#E00D42]" /> {canSwitchHubs ? 'Working Facility' : 'Assigned Facility'}
                            </span>
                            <span className="text-[9px] font-semibold px-1.5 py-0.5 rounded-xs bg-slate-50 text-slate-700 border border-slate-300 uppercase font-sans">
                                {activeHub?.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                            </span>
                        </div>

                        {/* Facility Switcher Dropdown Button */}
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => canSwitchHubs && setHubSwitcherOpen(!hubSwitcherOpen)}
                                disabled={!canSwitchHubs}
                                className={`w-full flex items-center justify-between p-2 rounded-xs bg-white border border-slate-300 text-left transition shadow-2xs group ${
                                    canSwitchHubs ? 'hover:border-slate-400 cursor-pointer' : 'cursor-default'
                                }`}
                            >
                                <div className="min-w-0 flex-1">
                                    <p className={`text-xs font-bold text-slate-800 truncate transition ${canSwitchHubs ? 'group-hover:text-[#E00D42]' : ''}`}>
                                        {activeHub?.name || logisticsCompany?.name || 'Logistics Terminal'}
                                    </p>
                                    <p className="text-[10px] text-slate-500 font-sans truncate">
                                        Station: {activeHub?.code || 'STATION-01'} • {activeHub?.city_municipality || 'Laguna'}
                                    </p>
                                </div>
                                {canSwitchHubs && (
                                    <ChevronDown className={`w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5 transition-transform ${hubSwitcherOpen ? 'rotate-180' : ''}`} />
                                )}
                            </button>

                            {canSwitchHubs && hubSwitcherOpen && allHubs && allHubs.length > 0 && (
                                <div className="absolute left-0 right-0 top-full mt-1 bg-white rounded-md shadow-xl border border-slate-300 py-1.5 z-50 text-slate-800">
                                    <div className="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 font-sans">
                                        Switch Operating Hub
                                    </div>
                                    <div className="max-h-48 overflow-y-auto divide-y divide-slate-100">
                                        {allHubs.map((h: any) => (
                                            <button
                                                key={h.id}
                                                type="button"
                                                onClick={() => {
                                                    setHubSwitcherOpen(false);
                                                    if (h.id !== activeHub?.id) {
                                                        router.post(route('hub.switchHub'), { hub_id: h.id }, { preserveScroll: true });
                                                    }
                                                }}
                                                className={`w-full text-left px-3 py-2 text-xs flex items-center justify-between transition cursor-pointer hover:bg-slate-50 ${
                                                    h.id === activeHub?.id ? 'bg-slate-50 font-bold text-[#E00D42]' : 'text-slate-700'
                                                }`}
                                            >
                                                <div className="min-w-0 flex-1 pr-2">
                                                    <p className="truncate">{h.name}</p>
                                                    <p className="text-[10px] text-slate-400 font-sans truncate">
                                                        {h.code} • {h.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                                    </p>
                                                </div>
                                                {h.id === activeHub?.id && (
                                                    <Check className="w-3.5 h-3.5 text-[#E00D42] shrink-0" />
                                                )}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Navigation Sections */}
                <nav className="flex-1 px-3 py-3 space-y-4 overflow-y-auto font-sans scrollbar-thin">
                    
                    {/* Non-Logistics Main Navigation (Admin / Seller / Courier) */}
                    {role !== 'logistics' && (
                        <div className="space-y-0.5">
                            <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-sans">
                                Menu
                            </p>
                            {navItems.map((item) => (
                                <Link
                                    key={item.name}
                                    href={item.href}
                                    className={`flex items-center justify-between px-3 py-2 rounded-lg text-xs transition group ${
                                        item.current 
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold' 
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <item.icon className={`w-4 h-4 shrink-0 ${item.current ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                        <span>{item.name}</span>
                                    </div>
                                    {item.current && (
                                        <span className="w-1.5 h-1.5 rounded-full bg-white/90 shrink-0" />
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}

                    {/* Logistics Multi-Tier Navigation Hierarchy */}
                    {role === 'logistics' && (
                        <div className="space-y-4">
                            {/* Company management */}
                            <div className="space-y-0.5">
                                <p className="mb-1 px-3 text-[11px] font-semibold text-slate-500 font-sans">
                                    Company
                                </p>
                                {user?.canManageResources && user.resourceGovernanceUrl && <Link
                                    href={user.resourceGovernanceUrl}
                                    className={`flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-xs font-semibold ${component === 'Governance/Resources' || component === 'Governance/ResourceRestriction' ? 'bg-[#E00D42] text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                                ><ShieldAlert className="h-4 w-4 shrink-0" /><span>Resource activity</span></Link>}
                                {user?.canManageResources && <Link href="/governance-history" className={`flex items-center gap-2.5 rounded-lg px-3 py-1.5 text-xs font-semibold ${component === 'Governance/History' || component === 'Governance/HistoryDetail' ? 'bg-[#E00D42] text-white' : 'text-slate-600 hover:bg-slate-100'}`}><ShieldCheck className="h-4 w-4 shrink-0" /><span>Governance history</span></Link>}
                                <Link
                                    href={route('hub.dashboard')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/Dashboard' || route().current('hub.dashboard') || (route().current('hub.index') && !url.includes('/scan')) || url === '/hub' || url === '/hub/dashboard'
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <LayoutDashboard className={`w-4 h-4 shrink-0 ${(component === 'Hub/Dashboard' || url === '/hub' || url === '/hub/dashboard') ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                        <span>Overview</span>
                                    </div>
                                </Link>
                                <Link
                                    href={route('hub.network')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/Network' || route().current('hub.network') || url.startsWith('/hub/network')
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Building2 className={`w-4 h-4 shrink-0 ${(component === 'Hub/Network' || url.startsWith('/hub/network')) ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                        <span>Facility Network</span>
                                    </div>
                                </Link>
                                <Link
                                    href={route('hub.fleet')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/Fleet' || route().current('hub.fleet') || url.startsWith('/hub/fleet')
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Truck className={`w-4 h-4 shrink-0 ${(component === 'Hub/Fleet' || url.startsWith('/hub/fleet')) ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                        <span>Fleet Management</span>
                                    </div>
                                </Link>
                            </div>

                            {/* Parcel operations */}
                            <div className="pt-2 border-t border-slate-100 space-y-0.5">
                                <p className="mb-1 px-3 text-[11px] font-semibold text-slate-500 font-sans">
                                    Operations
                                </p>
                                <Link
                                    href={route('hub.deliveries')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/Deliveries' || route().current('hub.deliveries') || url.startsWith('/hub/deliveries')
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Package className={`w-4 h-4 shrink-0 ${(component === 'Hub/Deliveries' || url.startsWith('/hub/deliveries')) ? 'text-white' : 'text-slate-400 group-hover:text-slate-900'}`} />
                                        <span>Parcels & Waybills</span>
                                    </div>
                                </Link>
                            </div>

                            {/* Facility tools */}
                            <div className="pt-2 border-t border-slate-100 space-y-0.5">
                                <p className="mb-1 px-3 text-[11px] font-semibold text-slate-500 font-sans">
                                    Facility tools
                                </p>
                                <Link
                                    href={route('hub.scan.station')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/ScanStation' || route().current('hub.scan.station') || url.startsWith('/hub/scan')
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <ScanLine className={`w-4 h-4 shrink-0 ${(component === 'Hub/ScanStation' || url.startsWith('/hub/scan')) ? 'text-white' : 'text-emerald-600 group-hover:text-emerald-700'}`} />
                                        <span>Scan Station</span>
                                    </div>
                                </Link>
                                <Link
                                    href={route('hub.counter')}
                                    className={`flex items-center justify-between px-3 py-1.5 rounded-lg text-xs transition group ${
                                        component === 'Hub/CounterPickup' || route().current('hub.counter') || url.startsWith('/hub/counter')
                                            ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                            : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                    }`}
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Store className={`w-4 h-4 shrink-0 ${(component === 'Hub/CounterPickup' || url.startsWith('/hub/counter')) ? 'text-white' : 'text-blue-600 group-hover:text-blue-700'}`} />
                                        <span>Counter Self-Pickup</span>
                                    </div>
                                </Link>
                            </div>
                        </div>
                    )}

                    {/* Quick Links (Storefront & Settings) */}
                    {(user?.shop || role === 'seller') && (
                        <div className="pt-2 border-t border-slate-100 space-y-0.5">
                            <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1.5 font-sans">
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
                            {(() => {
                                const isSettingsActive = component.startsWith('Seller/Settings') || component.startsWith('Seller/Profile') || route().current('seller.settings*') || route().current('seller.profile*') || url.startsWith('/seller/settings') || url.startsWith('/seller/profile');
                                return (
                                    <Link
                                        href={route('seller.settings')}
                                        className={`flex items-center justify-between px-3 py-2 rounded-lg text-xs transition group ${
                                            isSettingsActive
                                                ? 'bg-[#E00D42] text-white shadow-xs font-bold'
                                                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100 font-medium'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <Settings className={`w-4 h-4 shrink-0 ${
                                                isSettingsActive
                                                    ? 'text-white'
                                                    : 'text-slate-400 group-hover:text-slate-900'
                                            }`} />
                                            <span>Settings</span>
                                        </div>
                                        {isSettingsActive && (
                                            <span className="w-1.5 h-1.5 rounded-full bg-white/90 shrink-0" />
                                        )}
                                    </Link>
                                );
                            })()}
                        </div>
                    )}
                </nav>

                {/* Sidebar Bottom: Sign Out Button */}
                <div className="p-3 border-t border-slate-100 bg-white shrink-0 mt-auto">
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-lg text-xs font-bold text-slate-700 hover:text-rose-600 hover:bg-rose-50 transition border border-slate-200 hover:border-rose-200 uppercase tracking-wider shadow-2xs cursor-pointer font-sans"
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
                    <div className="flex items-center gap-3 min-w-0 flex-1 mr-3">
                        <button
                            onClick={() => setSidebarOpen(true)}
                            className="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-xl cursor-pointer shrink-0"
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div className="min-w-0">
                            <h1 className="text-base font-black text-slate-900 tracking-tight truncate">{title}</h1>
                            {subtitle && <div className="text-[11px] text-slate-500 font-medium flex items-center gap-2 mt-0.5 truncate">{subtitle}</div>}
                        </div>
                    </div>

                    {/* Topbar Actions & User Avatar */}
                    <div className="flex items-center gap-3 shrink-0">
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
                                    <div className="w-8 h-8 rounded-xs bg-slate-950 text-white font-bold text-xs flex items-center justify-center shadow-xs group-hover:bg-[#E00D42] transition font-sans">
                                        {user?.name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <div className="hidden sm:block text-left font-sans">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-bold text-slate-800 leading-tight group-hover:text-[#E00D42] transition">{user?.name}</p>
                                        <ChevronDown className={`w-3 h-3 text-slate-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                                    </div>
                                    <span className="text-[10px] text-slate-500 font-bold uppercase">
                                        {role === 'seller' ? 'Verified Merchant' : role === 'admin' ? 'Super Admin' : role === 'logistics' ? 'Logistics Operator' : 'Authorized User'}
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
                                        <div className="px-4 py-2.5 border-b border-slate-200 font-sans text-xs flex items-center gap-2.5">
                                            {user?.avatar ? (
                                                <img
                                                    src={user.avatar}
                                                    alt={user.name}
                                                    className="w-8 h-8 rounded-xs object-cover border border-slate-200 shrink-0"
                                                />
                                            ) : (
                                                <div className="w-8 h-8 rounded-xs bg-slate-900 text-white font-bold text-xs flex items-center justify-center font-sans shrink-0">
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

                                        {role === 'logistics' && (
                                            <>
                                                <Link
                                                    href={route('hub.dashboard')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition"
                                                >
                                                    <LayoutDashboard className="w-4 h-4 text-blue-600" />
                                                    <span>Hub Overview</span>
                                                </Link>
                                                <Link
                                                    href={route('hub.scan.station')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition"
                                                >
                                                    <ScanLine className="w-4 h-4 text-emerald-600" />
                                                    <span>Floor Scanner (PWA)</span>
                                                </Link>
                                                <Link
                                                    href={route('hub.network')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-blue-600 transition"
                                                >
                                                    <Building2 className="w-4 h-4 text-slate-400" />
                                                    <span>Facility Network</span>
                                                </Link>
                                            </>
                                        )}

                                        <Link
                                            href={role === 'seller' ? route('seller.profile') : route('profile.edit')}
                                            className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                        >
                                            <ShieldCheck className="w-4 h-4 text-slate-400" />
                                            <span>Security</span>
                                        </Link>

                                        {(role === 'admin' || ['approved', 'verified'].includes(auth.user?.kyc_status ?? '')) && <Link href="/account/identity-corrections" className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42]">Identity corrections</Link>}

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
                    <div className="bg-emerald-600 text-white py-2.5 px-6 text-xs font-bold font-sans shadow-xs flex items-center gap-2 shrink-0">
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="bg-[#E00D42] text-white py-2.5 px-6 text-xs font-bold font-sans shadow-xs shrink-0">
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
