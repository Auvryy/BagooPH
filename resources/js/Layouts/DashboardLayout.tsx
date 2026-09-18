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
    User as UserIcon
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: React.ReactNode;
    actions?: React.ReactNode;
}

export default function DashboardLayout({ children, title, subtitle, actions }: Props) {
    const { auth, flash, sellerShops, categories } = usePage<PageProps>().props;
    const { url, component } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [shopSwitcherOpen, setShopSwitcherOpen] = useState(false);
    const [createShopModalOpen, setCreateShopModalOpen] = useState(false);
    const [newShopName, setNewShopName] = useState('');
    const [newShopCategoryId, setNewShopCategoryId] = useState('');
    const [newShopDescription, setNewShopDescription] = useState('');
    const userMenuTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

    const user = auth.user;
    const role = user?.role || 'buyer';
    const currentShop = user?.shop;
    const shops = sellerShops && sellerShops.length > 0 ? sellerShops : (currentShop ? [currentShop] : []);

    const handleCreateShop = (e: React.FormEvent) => {
        e.preventDefault();
        if (!newShopName.trim() || !newShopCategoryId) return;

        router.post(route('seller.shops.create'), {
            name: newShopName.trim(),
            root_category_id: newShopCategoryId,
            description: newShopDescription.trim() || undefined,
        }, {
            onSuccess: () => {
                setCreateShopModalOpen(false);
                setNewShopName('');
                setNewShopCategoryId('');
                setNewShopDescription('');
            }
        });
    };

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
                { name: 'KYC Queue', href: route('admin.kyc.index'), icon: ShieldCheck, current: component.startsWith('Admin/Kyc') || route().current('admin.kyc.*') || url.startsWith('/admin/kyc') },
                { name: 'Users', href: route('admin.users'), icon: Users, current: component.startsWith('Admin/Users') || route().current('admin.users*') || url.startsWith('/admin/users') },
                { name: 'Products', href: route('admin.products'), icon: Package, current: component.startsWith('Admin/Products') || route().current('admin.products*') || url.startsWith('/admin/products') },
                { name: 'Logistics', href: route('admin.logistics'), icon: Truck, current: component.startsWith('Admin/Logistics') || route().current('admin.logistics*') || url.startsWith('/admin/logistics') },
            ];
        }

        if (role === 'seller') {
            return [
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

        if (role === 'courier' || role === 'logistics') {
            return [
                { name: 'Deliveries', href: route('courier.deliveries'), icon: Truck, current: component.startsWith('Courier/Deliveries') || route().current('courier.deliveries') || url.startsWith('/courier/deliveries') },
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

                {/* Active Store Profile & Switcher for Merchants */}
                {role === 'seller' && currentShop && (
                    <div className="p-3 border-b border-slate-100 bg-slate-50/50 shrink-0">
                        <div className="flex items-center justify-between mb-1.5">
                            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono flex items-center gap-1">
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
                                    <p className="text-[10px] text-slate-500 font-mono truncate">{shops.length} profile{shops.length === 1 ? '' : 's'} managed</p>
                                </div>
                                <ChevronDown className={`w-3.5 h-3.5 text-slate-400 shrink-0 ml-1.5 transition-transform ${shopSwitcherOpen ? 'rotate-180' : ''}`} />
                            </button>

                            {shopSwitcherOpen && (
                                <div className="absolute left-0 right-0 top-full mt-1 bg-white rounded-lg shadow-xl border border-slate-200 py-1.5 z-50 text-slate-800">
                                    <div className="px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400 font-mono">
                                        Switch Store Profile
                                    </div>
                                    <div className="max-h-48 overflow-y-auto divide-y divide-slate-100">
                                        {shops.map((s) => (
                                            <button
                                                key={s.id}
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
                                                    <p className="text-[10px] text-slate-400 font-mono truncate">
                                                        Enclosure: {s.root_category?.name || 'General'}
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
                                                setCreateShopModalOpen(true);
                                            }}
                                            className="w-full flex items-center justify-center gap-1.5 py-1.5 px-2.5 rounded bg-slate-900 text-white hover:bg-slate-800 text-[11px] font-semibold transition cursor-pointer"
                                        >
                                            <Plus className="w-3 h-3" />
                                            <span>New Specialty Store</span>
                                        </button>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

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

            {/* Create Store Profile Modal */}
            {createShopModalOpen && (
                <div className="fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
                    <div className="bg-white rounded-xl shadow-2xl border border-slate-200 w-full max-w-md p-6">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-100">
                            <div className="flex items-center gap-2.5">
                                <div className="p-2 rounded-lg bg-red-50 text-[#E00D42]">
                                    <Store className="w-5 h-5" />
                                </div>
                                <div>
                                    <h2 className="text-sm font-bold text-slate-900">Create Storefront Profile</h2>
                                    <p className="text-[11px] text-slate-500">Add a dedicated category enclosure under this merchant account</p>
                                </div>
                            </div>
                            <button 
                                type="button"
                                onClick={() => setCreateShopModalOpen(false)}
                                className="p-1 text-slate-400 hover:text-slate-700 rounded-lg cursor-pointer"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>

                        <form onSubmit={handleCreateShop} className="mt-4 space-y-4 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Store Name *</label>
                                <input
                                    type="text"
                                    required
                                    value={newShopName}
                                    onChange={(e) => setNewShopName(e.target.value)}
                                    placeholder="e.g. Bagoo Urban EDC"
                                    className="w-full px-3 py-2 rounded-lg border border-slate-300 focus:outline-hidden focus:border-[#E00D42] text-xs"
                                />
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Root Category Enclosure *</label>
                                <select
                                    required
                                    value={newShopCategoryId}
                                    onChange={(e) => setNewShopCategoryId(e.target.value)}
                                    className="w-full px-3 py-2 rounded-lg border border-slate-300 focus:outline-hidden focus:border-[#E00D42] text-xs bg-white"
                                >
                                    <option value="">Select Root Category Enclosure...</option>
                                    {(categories || []).map((cat) => (
                                        <option key={cat.id} value={cat.id}>{cat.name}</option>
                                    ))}
                                </select>
                                <p className="text-[10px] text-slate-400 mt-1">
                                    All products listed in this store will be strictly bounded to this root category enclosure.
                                </p>
                            </div>

                            <div>
                                <label className="block font-bold text-slate-700 mb-1">Description</label>
                                <textarea
                                    rows={2}
                                    value={newShopDescription}
                                    onChange={(e) => setNewShopDescription(e.target.value)}
                                    placeholder="Brief storefront specialty summary..."
                                    className="w-full px-3 py-2 rounded-lg border border-slate-300 focus:outline-hidden focus:border-[#E00D42] text-xs"
                                />
                            </div>

                            <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100">
                                <button
                                    type="button"
                                    onClick={() => setCreateShopModalOpen(false)}
                                    className="px-4 py-2 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 font-semibold cursor-pointer"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="px-4 py-2 rounded-lg bg-[#E00D42] hover:bg-[#b50a35] text-white font-bold transition shadow-xs cursor-pointer"
                                >
                                    Create Storefront
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
