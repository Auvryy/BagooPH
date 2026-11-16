import React, { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { PageProps } from '@/types';
import { isMousePointer, openMenuOnFirstTap } from '@/utils/touchMenu';
import BagooLogo from '@/Components/BagooLogo';
import BagooLogisticsLogo from '@/Components/BagooLogisticsLogo';
import NotificationLink from '@/Components/NotificationLink';
import PortalLink from '@/Components/PortalLink';
import CatalogueNavigationNotice from '@/Components/CatalogueNavigationNotice';
import AdminSidebarNavigation from '@/Components/AdminSidebarNavigation';
import AdminSectionTabs, { adminAreaTabs } from '@/Components/AdminSectionTabs';
import LogisticsSidebarNavigation from '@/Components/Logistics/SidebarNavigation';
import LogisticsNavigationDrawer from '@/Components/Logistics/NavigationDrawer';
import '../../css/logistics-workspace.css';
import { HubPageHeader } from '@/Components/Logistics/Workspace';
import NavigationAttentionDot from '@/Components/NavigationAttentionDot';
import { getDomainUrl } from '@/utils/domain';
import useBodyScrollLock from '@/hooks/useBodyScrollLock';
import { 
    ShoppingBag, 
    LayoutDashboard, Layers,
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
    ImagePlus,
    MessageSquare,
    Star,
    ShieldAlert,
    TrendingUp,
    CheckCircle2,
    Check,
    User as UserIcon,
    ScanLine,
    Building2,
    Banknote,
    AlertTriangle, RotateCcw,
    Hash,
    type LucideIcon,
    CalendarClock,
} from 'lucide-react';

interface Props {
    children: React.ReactNode;
    title: string;
    subtitle?: React.ReactNode;
    actions?: React.ReactNode;
}

interface NavigationItem {
    name: string;
    href: string;
    icon: LucideIcon;
    current: boolean;
    attention?: boolean;
    attentionLabel?: string;
    children?: { name: string; href: string; current: boolean }[];
}

export default function DashboardLayout({ children, title, subtitle, actions }: Props) {
    const { auth, flash, sidebarAttention = {}, unreadMessagesCount = 0 } = usePage<PageProps>().props;
    const { url, component } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const [hubSwitcherOpen, setHubSwitcherOpen] = useState(false);
    const [switchingHub, setSwitchingHub] = useState<number | null>(null);
    const hubSwitchPending = React.useRef(false);
    const userMenuTimeoutRef = React.useRef<NodeJS.Timeout | null>(null);

    const user = auth.user;
    const financialReturn = ['Returns/Liability', 'Returns/Liabilities', 'Finance/ShipmentReview', 'Finance/ShipmentOutcome'].includes(component);
    const role = user?.role || 'buyer';
    const adminArea = role === 'admin' ? adminAreaTabs(component, { returnLiabilities: Boolean(user?.canManageReturnLiabilities), financialReturn }) : null;
    useBodyScrollLock(sidebarOpen && role !== 'logistics');
    const currentShop = user?.shop;
    const activeHub = (user as any)?.activeHub;
    const allHubs = (user as any)?.allHubs || [];
    const logisticsCompany = (user as any)?.logisticsCompany;
    const canSwitchHubs = Boolean((user as any)?.canSwitchHubs);
    const canScanParcels = user?.canScanParcels === true;
    const lx = role === 'logistics';
    const sharedHubHeading = role === 'logistics' && (component.startsWith('Governance/') || component.startsWith('Handover/') || (component.startsWith('Finance/') && component !== 'Finance/DeliveryEarnings'));
    const hubPageIcon = component.startsWith('Finance/') ? Banknote : component.startsWith('Handover/') || component.startsWith('Governance/Exception') ? AlertTriangle : component.startsWith('Governance/History') ? ShieldCheck : Settings;

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

    React.useEffect(() => {
        if (!sidebarOpen) return;
        const closeOnEscape = (event: KeyboardEvent) => { if (event.key === 'Escape') setSidebarOpen(false); };
        window.addEventListener('keydown', closeOnEscape);
        return () => window.removeEventListener('keydown', closeOnEscape);
    }, [sidebarOpen]);

    const getNavItems = (): NavigationItem[] => {
        if (role === 'admin') {
            return [
                { name: 'Dashboard', href: route('admin.dashboard'), icon: LayoutDashboard, current: component === 'Admin/Dashboard' || route().current('admin.dashboard') || url === '/admin/dashboard' || url === '/admin' },
                { name: 'Shop reviews', href: route('admin.shops.index'), icon: Store, current: component === 'Admin/ShopReviews' },
                { name: 'KYC Queue', href: route('admin.kyc.index'), icon: ShieldCheck, current: component.startsWith('Admin/Kyc') || route().current('admin.kyc.*') || url.startsWith('/admin/kyc') },
                { name: 'Users', href: route('admin.users'), icon: Users, current: component.startsWith('Admin/Users') || component === 'Admin/AccountContext' || route().current('admin.users*') || url.startsWith('/admin/users') },
                { name: 'Review requests', href: route('admin.account-reviews.index'), icon: ShieldCheck, current: component.startsWith('Admin/AccountReview') },
                { name: 'Products', href: route('admin.products'), icon: Package, current: component.startsWith('Admin/Products') || route().current('admin.products*') || url.startsWith('/admin/products') },
                { name: 'Sale events', href: route('admin.sale-events.index'), icon: CalendarClock, current: component.startsWith('Admin/SaleEvents/') },
                { name: 'Voucher campaigns', href: route('admin.vouchers.index'), icon: Tag, current: component.startsWith('Admin/Voucher') },
                { name: 'Advertisements', href: route('admin.advertisements.index'), icon: ImagePlus, current: component.startsWith('Admin/Advertisements/') },
                { name: 'Logistics', href: route('admin.logistics'), icon: Truck, current: adminArea?.label === 'Logistics' || route().current('admin.logistics*') || url.startsWith('/admin/logistics') },
                { name: 'SLA monitoring', href: '/governance/monitoring', icon: AlertTriangle, current: component === 'Governance/Monitoring', attention: Boolean(sidebarAttention?.monitoring), attentionLabel: 'Open SLA or missing-parcel monitoring alerts' },
                { name: 'Moderation & penalties', href: '/governance/moderation', icon: ShieldCheck, current: component === 'Governance/Moderation', attention: Boolean(sidebarAttention?.moderation), attentionLabel: 'Open sanctions, appeals, or review moderation queue' },
                // Finance is one destination; its pages are tabs inside the Finance area.
                { name: 'Finance', href: '/financial-oversight', icon: Banknote, current: adminArea?.label === 'Finance' },
                { name: 'Governance history', href: '/governance-history', icon: ShieldCheck, current: component === 'Governance/History' || component === 'Governance/HistoryDetail' },
                { name: 'Delivery exceptions', href: '/exceptions', icon: ShieldAlert, current: component === 'Governance/Exceptions' || component === 'Governance/ExceptionDetail' },
                { name: 'Buyer returns', href: '/buyer-returns', icon: ShieldAlert, current: !financialReturn && component.startsWith('Returns/') },
                { name: 'Handover issues', href: '/handover-issues', icon: AlertTriangle, current: component.startsWith('Handover/') },
                ...(user?.canManageResources && user.resourceGovernanceUrl ? [{ name: 'Resource activity', href: user.resourceGovernanceUrl, icon: ShieldAlert, current: component === 'Governance/Resources' || component === 'Governance/ResourceRestriction' }] : []),
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
                { name: 'Product tags', href: route('seller.store-tags.index'), icon: Hash, current: component === 'Seller/StoreTags' || route().current('seller.store-tags.*') || url.startsWith('/seller/store-tags') || url.startsWith('/store-tags') },
                { 
                    name: 'Orders', 
                    href: route('seller.orders.index'), 
                    icon: ShoppingCart, 
                    current: component.startsWith('Seller/Order') || route().current('seller.orders.*') || url.startsWith('/seller/orders') 
                },
                ...(user?.canManageReturnLiabilities ? [{ name: 'Return liabilities', href: '/return-liabilities', icon: Banknote, current: financialReturn }] : []),
                { name: 'Handover issues', href: '/handover-issues', icon: AlertTriangle, current: component.startsWith('Handover/') },
                { 
                    name: 'Vouchers', 
                    href: route('seller.vouchers.index'), 
                    icon: Tag, 
                    current: component.startsWith('Seller/Voucher') || route().current('seller.vouchers.*') || url.startsWith('/seller/vouchers') 
                },
                { name: 'Sale events', href: route('seller.sale-events.index'), icon: CalendarClock, current: component.startsWith('Seller/SaleEvents/') },
                { name: 'Promotions', href: route('seller.promotions.index'), icon: ImagePlus, current: component.startsWith('Seller/Promotions/') },
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
                    current: !financialReturn && component.startsWith('Returns/') || component.startsWith('Seller/Dispute') || route().current('seller.disputes.*') || url.startsWith('/seller/disputes')
                },
                { 
                    name: 'Finances', 
                    href: '/financial-oversight',
                    icon: Banknote,
                    current: !financialReturn && component.startsWith('Finance/') || component === 'Seller/Reports',
                    children: [
                        { name: 'Overview', href: '/financial-oversight', current: component === 'Finance/Overview' || component === 'Finance/Evidence' },
                        { name: 'Payouts', href: '/seller-settlements', current: component === 'Finance/Settlements' || component === 'Finance/SettlementDetail' },
                        { name: 'Wallet sales', href: '/wallet/proceeds', current: component === 'Finance/WalletProceeds' },
                        { name: 'Sales report', href: route('seller.reports'), current: component === 'Seller/Reports' },
                    ],
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
                ...(canScanParcels ? [{
                    name: 'Scan Station', 
                    href: route('hub.scan.station'), 
                    icon: ScanLine, 
                    current: component === 'Hub/ScanStation' || route().current('hub.scan.station') || url.startsWith('/hub/scan') 
                }] : []),
                { 
                    name: 'Facility Network',
                    href: route('hub.network'), 
                    icon: Building2, 
                    current: component === 'Hub/Network' || route().current('hub.network') || url.startsWith('/hub/network') 
                },
                ...((user as any)?.canManageTeam ? [{ name: 'Team', href: route('hub.team'), icon: Users, current: component === 'Hub/Team' }] : []),
                ...(user?.canSetupCompany ? [{ name: 'Company setup', href: route('hub.company-setup.index'), icon: Settings, current: component === 'Hub/CompanySetup' }] : []),
                { name: 'Mother Bags', href: route('hub.mother-bags.index'), icon: Package, current: component === 'Hub/MotherBags' },
                ...(user?.canManageResources ? [{ name: 'Delivery earnings', href: '/delivery-earnings', icon: Banknote, current: component === 'Finance/DeliveryEarnings' }] : []),
                { name: 'Manifests', href: route('hub.manifests.index'), icon: Truck, current: component === 'Hub/Manifests' },
                { name: 'Buyer returns', href: '/buyer-returns', icon: Package, current: !financialReturn && component.startsWith('Returns/') },
                { name: 'Parcel recovery', href: route('hub.parcel-recovery.index'), icon: AlertTriangle, current: component === 'Hub/ParcelRecovery' },
                ...(user?.canManageReturnLiabilities ? [{ name: 'Return liabilities', href: '/return-liabilities', icon: Banknote, current: financialReturn }] : []),
                { name: 'Handover issues', href: '/handover-issues', icon: AlertTriangle, current: component.startsWith('Handover/') },
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
                { name: 'COD cash', href: route('hub.cod.index'), icon: Banknote, current: component === 'Finance/Cod' || component === 'Finance/CodDetail' },
                ...(user?.canViewFinancialOversight ? [{ name: 'Financial oversight', href: '/financial-oversight', icon: Banknote, current: component === 'Finance/Overview' || component === 'Finance/Evidence' }] : []),
            ];
        }

        return [];
    };

    const attentionKeys: Record<string, keyof typeof sidebarAttention> = {
        'Shop reviews': 'shop_reviews', 'KYC Queue': 'kyc', 'Review requests': 'review_requests',
        Advertisements: 'advertisements', 'Wallet credits': 'wallet_credits',
        Messages: 'messages', Orders: 'orders',
        'SLA monitoring': 'monitoring', 'Moderation & penalties': 'moderation',
    };
    const navItems = getNavItems().map(item => ({ ...item,
        attention: Boolean(sidebarAttention[attentionKeys[item.name]] || (item.name === 'Messages' && unreadMessagesCount > 0)),
        attentionLabel: item.name === 'Messages' ? 'Unread messages' : item.name === 'Orders'
            ? 'Orders ready for your next step' : item.name === 'SLA monitoring'
                ? 'Open SLA or missing-parcel monitoring alerts' : item.name === 'Moderation & penalties'
                    ? 'Open sanctions, appeals, or review moderation queue' : 'Awaiting your review',
    }));
    const WorkspaceLink = ['seller', 'logistics', 'admin'].includes(role) ? PortalLink : Link;

    return (
        <div className={`h-screen w-full flex overflow-hidden bg-[#F8FAFC] text-slate-900 font-sans antialiased selection:bg-[#E00D42] selection:text-white ${role === 'logistics' ? 'logistics-workspace' : ''}`}>
            
            {['seller', 'logistics', 'admin'].includes(role) && <CatalogueNavigationNotice />}
            {/* Backdrop for mobile drawer */}
            {sidebarOpen && role !== 'logistics' && (
                <div 
                    className="fixed inset-0 bg-black/50 backdrop-blur-xs z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar (Permanently Fixed on Desktop) */}
            <LogisticsNavigationDrawer enabled={role === 'logistics'} open={sidebarOpen} onClose={() => setSidebarOpen(false)}>
            <aside id="portal-navigation" className={`
                fixed inset-y-0 left-0 z-50 w-64 overscroll-contain ${lx ? 'bg-white text-ink-900 border-r border-line-200' : 'bg-[#121316] text-zinc-200 border-r border-[#1F2128]'}
                flex flex-col transition-transform duration-200 ease-in-out
                ${sidebarOpen ? 'visible translate-x-0' : 'invisible -translate-x-full'}
                lg:visible lg:static lg:translate-x-0 lg:h-full lg:shrink-0 lg:z-30
            `}>
                
                {/* Brand Header */}
                <div className={`p-4 shrink-0 ${lx ? 'bg-white' : 'border-b border-[#1F2128] bg-[#121316]'}`}>
                    <div className="flex items-center justify-between">
                        <Link href={role === 'seller' ? route('seller.dashboard') : role === 'admin' ? route('admin.dashboard') : role === 'logistics' ? route('hub.dashboard') : '/'} className="flex items-center gap-2.5">
                            {role === 'logistics' ? (
                                <BagooLogisticsLogo className="w-8 h-8 shadow-xs" rounded="rounded-lg" />
                            ) : (
                                <BagooLogo className="w-8 h-8 shadow-xs" rounded="rounded-xl" />
                            )}
                            <div>
                                <span className={`text-base font-black tracking-tight ${lx ? 'text-ink-900' : 'text-white'}`}>Bagoo<span className="text-[#E00D42]">PH</span></span>
                                <span className={`block -mt-0.5 font-sans ${lx ? 'text-xs font-medium text-ink-500' : 'text-[9px] uppercase font-bold tracking-widest text-zinc-400'}`}>
                                    {role === 'seller' ? 'Seller Centre' : role === 'admin' ? 'Admin Portal' : role === 'logistics' ? 'Logistics Hub' : 'Portal'}
                                </span>
                            </div>
                        </Link>
                        <button 
                            type="button"
                            aria-label="Close navigation"
                            data-autofocus={role === 'logistics' ? true : undefined}
                            onClick={() => setSidebarOpen(false)}
                            className={`lg:hidden p-1 cursor-pointer ${lx ? 'flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink-500 hover:bg-surface-100 hover:text-ink-900' : 'text-zinc-400 hover:text-white'}`}
                        >
                            <X className="w-5 h-5" />
                        </button>
                    </div>
                </div>

                {role === 'seller' && currentShop && (
                    <div className="shrink-0 border-b border-[#1F2128] bg-[#181A20] p-3">
                        <Link href={route('seller.settings')} className="flex items-start gap-2 rounded-xl border border-[#2B2D37] bg-[#121316] p-3 transition hover:border-[#E00D42] focus:ring-2 focus:ring-[#E00D42]">
                            <Store className="mt-0.5 h-4 w-4 shrink-0 text-[#E00D42]" />
                            <div className="min-w-0">
                                <p className="truncate text-xs font-bold text-zinc-100">{currentShop.name}</p>
                                <p className="mt-1 text-xs text-zinc-300">{currentShop.root_category?.name ?? 'Category needs review'}</p>
                                <p className="mt-1 text-xs text-zinc-400">Shop settings</p>
                            </div>
                        </Link>
                    </div>
                )}

                {/* Active Hub Facility & Switcher for Logistics */}
                {role === 'logistics' && (
                    <div className="px-3 pb-3 shrink-0">
                        <div className="flex items-center justify-between mb-1.5 px-1">
                            <span className="text-xs font-medium text-ink-500 font-sans flex items-center gap-1.5">
                                <Building2 className="w-3.5 h-3.5" aria-hidden="true" /> {canSwitchHubs ? 'Viewing facility' : 'Assigned facility'}
                            </span>
                            <span className="text-xs font-medium px-1.5 py-0.5 rounded-chip bg-brand-50 text-brand-600 font-sans">
                                {activeHub ? activeHub.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub' : 'No facility'}
                            </span>
                        </div>

                        {canSwitchHubs && !canScanParcels && <p className="mb-2 px-1 text-xs leading-4 text-ink-600">View only · scanning needs this hub's operator</p>}

                        {/* Facility Switcher Dropdown Button */}
                        <div className="relative">
                            <button
                                type="button"
                                onClick={() => canSwitchHubs && setHubSwitcherOpen(!hubSwitcherOpen)}
                                disabled={!canSwitchHubs || switchingHub !== null}
                                className={`w-full flex items-center justify-between p-2.5 rounded-control bg-surface-100 text-left transition-colors group ${
                                    canSwitchHubs ? 'hover:bg-line-200 cursor-pointer' : 'cursor-default'
                                }`}
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-ink-900 truncate">
                                        {switchingHub !== null ? 'Changing facility…' : activeHub?.name || logisticsCompany?.name || 'No facility assigned'}
                                    </p>
                                    <p className="text-xs text-ink-600 font-sans truncate">
                                        {activeHub?.code || 'Not assigned'} · {activeHub?.city_municipality || 'Location not provided'}
                                    </p>
                                </div>
                                {canSwitchHubs && (
                                    <ChevronDown className={`w-4 h-4 text-ink-500 shrink-0 ml-1.5 transition-transform ${hubSwitcherOpen ? 'rotate-180' : ''}`} />
                                )}
                            </button>

                            {canSwitchHubs && hubSwitcherOpen && allHubs && allHubs.length > 0 && (
                                <div className="absolute left-0 right-0 top-full mt-1 bg-white rounded-control shadow-float border border-line-200 py-1.5 z-50 text-ink-900">
                                    <div className="px-3 py-1 text-xs font-medium text-ink-500 font-sans">
                                        Switch hub
                                    </div>
                                    <div className="max-h-48 overflow-y-auto">
                                        {allHubs.map((h: any) => (
                                             <button
                                                 key={h.id}
                                                 type="button"
                                                 onClick={() => {
                                                     setHubSwitcherOpen(false);
                                                     if (h.id !== activeHub?.id && !hubSwitchPending.current) {
                                                         hubSwitchPending.current = true;
                                                         setSwitchingHub(h.id);
                                                         router.post(route('hub.switchHub'), { hub_id: h.id }, { preserveScroll: true, onFinish: () => { hubSwitchPending.current = false; setSwitchingHub(null); } });
                                                     }
                                                 }}
                                                 className={`w-full text-left px-3 py-2 text-sm flex items-center justify-between transition-colors cursor-pointer hover:bg-surface-100 ${
                                                     h.id === activeHub?.id ? 'bg-brand-50 font-semibold text-brand-600' : 'text-ink-900'
                                                 }`}
                                             >
                                                 <div className="min-w-0 flex-1 pr-2">
                                                     <p className="truncate">{h.name}</p>
                                                     <p className="text-xs font-normal text-ink-600 font-sans truncate">
                                                         {h.code} · {h.tier === 'regional_mother_hub' ? 'Mother Hub' : 'Bayan Hub'}
                                                     </p>
                                                 </div>
                                                 {h.id === activeHub?.id && (
                                                     <Check className="w-4 h-4 text-brand-500 shrink-0" aria-hidden="true" />
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
                    {role === 'admin' && <AdminSidebarNavigation items={navItems} />}
                    {role !== 'logistics' && role !== 'admin' && (
                        <div className="space-y-0.5">
                            <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-1.5 font-sans">
                                Menu
                            </p>
                            {navItems.map((item) => (
                                <div key={item.name}>
                                    <WorkspaceLink
                                        href={item.href}
                                        aria-expanded={item.children ? item.current : undefined}
                                        aria-current={item.current && !item.children ? 'page' : undefined}
                                        className={`flex items-center justify-between px-3 py-2 rounded-xl text-xs transition group ${
                                            item.current
                                                ? 'bg-[#E00D42] text-white shadow-sm font-bold'
                                                : 'text-stone-300 hover:text-white hover:bg-[#2C2824] font-medium'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <item.icon className={`w-4 h-4 shrink-0 ${item.current ? 'text-white' : 'text-stone-400 group-hover:text-stone-200'}`} />
                                            <span>{item.name}</span>
                                        </div>
                                        {item.children ? (
                                            <ChevronDown aria-hidden="true" className={`h-3.5 w-3.5 shrink-0 transition-transform ${item.current ? '' : '-rotate-90'}`} />
                                        ) : item.current && !item.attention && (
                                            <span className="w-1.5 h-1.5 rounded-full bg-white/90 shrink-0" />
                                        )}
                                        {item.attention && <NavigationAttentionDot label={item.attentionLabel} />}
                                    </WorkspaceLink>
                                    {item.children && item.current && <div role="group" aria-label={`${item.name} pages`} className="ml-5 mt-1 space-y-0.5 border-l border-stone-700 pl-3">
                                        {item.children.map(child => <Link key={child.name} href={child.href} aria-current={child.current ? 'page' : undefined}
                                            className={`block rounded-lg px-3 py-2 text-xs transition ${child.current ? 'bg-rose-900/40 font-semibold text-rose-300' : 'text-stone-300 hover:bg-[#2C2824] hover:text-white'}`}>
                                            {child.name}
                                        </Link>)}
                                    </div>}
                                </div>
                            ))}
                        </div>
                    )}

                    {role === 'logistics' && <LogisticsSidebarNavigation items={[
                        ...navItems,
                        { name: 'Delivery recovery', href: route('hub.recovery.index'), icon: RotateCcw, current: component === 'Hub/Recovery' || component === 'Hub/DeliveryRecovery' },
                        ...(user?.canManageResources ? [
                            ...(user.resourceGovernanceUrl ? [{ name: 'Resource activity', href: user.resourceGovernanceUrl, icon: ShieldAlert, current: component.startsWith('Governance/Resource') }] : []),
                            { name: 'Governance history', href: '/governance-history', icon: ShieldCheck, current: component.startsWith('Governance/History') },
                            { name: 'Delivery exceptions', href: '/exceptions', icon: AlertTriangle, current: component.startsWith('Governance/Exception') },
                        ] : []),
                        { name: 'Account & security', href: route('profile.edit'), icon: Settings, current: component.startsWith('Profile/') },
                    ]} />}

                    {/* Quick Links (Storefront & Settings) */}
                    {(user?.shop || role === 'seller') && (
                        <div className="pt-2 border-t border-stone-800 space-y-0.5">
                            <p className="px-3 text-[10px] font-bold uppercase tracking-wider text-stone-400 mb-1.5 font-sans">
                                Quick Links
                            </p>
                            {user?.shop && (
                                <a
                                    href={getDomainUrl('buyer', `/shop/${user.shop.slug}`)}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center justify-between px-3 py-2 rounded-xl text-xs font-semibold text-stone-300 hover:text-white hover:bg-[#2C2824] transition group"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <ShoppingBag className="w-4 h-4 text-stone-400 group-hover:text-[#E00D42] transition" />
                                        <span>View Storefront</span>
                                    </div>
                                    <ExternalLink className="w-3.5 h-3.5 text-stone-400" />
                                </a>
                            )}
                            {(() => {
                                const isSettingsActive = component.startsWith('Seller/Settings') || component.startsWith('Seller/Profile') || route().current('seller.settings*') || route().current('seller.profile*') || url.startsWith('/seller/settings') || url.startsWith('/seller/profile');
                                return (
                                    <Link
                                        href={route('seller.settings')}
                                        className={`flex items-center justify-between px-3 py-2 rounded-xl text-xs transition group ${
                                            isSettingsActive
                                                ? 'bg-[#E00D42] text-white shadow-sm font-bold'
                                                : 'text-stone-300 hover:text-white hover:bg-[#2C2824] font-medium'
                                        }`}
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <Settings className={`w-4 h-4 shrink-0 ${
                                                isSettingsActive
                                                    ? 'text-white'
                                                    : 'text-stone-400 group-hover:text-white'
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
                <div className={`p-3 shrink-0 mt-auto ${lx ? 'border-t border-line-200 bg-white' : 'border-t border-[#1F2128] bg-[#121316]'}`}>
                    <Link
                        href={route('logout')}
                        method="post"
                        as="button"
                        className={lx
                            ? 'w-full flex items-center gap-2 px-3 py-2 rounded-control text-sm font-medium text-ink-700 hover:bg-surface-100 hover:text-ink-900 transition-colors cursor-pointer font-sans'
                            : 'w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl text-xs font-bold text-zinc-300 hover:text-white hover:bg-[#181A20] transition border border-[#2B2D37] hover:border-zinc-600 uppercase tracking-wider shadow-sm cursor-pointer font-sans'}
                    >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>Sign Out</span>
                    </Link>
                </div>
            </aside>
            </LogisticsNavigationDrawer>

            {/* Main Content Column */}
            <div className="flex-1 flex flex-col h-full min-w-0 overflow-hidden">
                
                {/* Topbar (Clean Fixed Header) */}
                <header className={`h-15 border-b px-4 sm:px-8 flex items-center justify-between shrink-0 z-20 ${lx ? 'bg-white border-line-200' : 'bg-white/95 backdrop-blur-md border-zinc-200/80'}`}>
                    <div className="flex items-center gap-3 min-w-0 flex-1 mr-3">
                        <button
                            type="button"
                            aria-label="Open navigation"
                            aria-controls="portal-navigation"
                            aria-expanded={sidebarOpen}
                            onClick={() => setSidebarOpen(true)}
                            className={`lg:hidden p-2 cursor-pointer shrink-0 ${lx ? 'rounded-control text-ink-700 hover:bg-surface-100 flex min-h-11 min-w-11 items-center justify-center' : 'text-zinc-600 hover:bg-zinc-100 rounded-xl'}`}
                        >
                            <Menu className="w-5 h-5" />
                        </button>
                        <div className="min-w-0">
                            {role === 'logistics' ? (
                                <div className="flex items-center gap-2 min-w-0">
                                    <span className="hidden sm:inline-flex items-center gap-1.5 text-sm text-ink-600 truncate">
                                        <span>{activeHub?.name || logisticsCompany?.name || 'Logistics'}</span>
                                    </span>
                                    <span className="hidden sm:inline text-ink-400" aria-hidden="true">/</span>
                                    <p className="truncate text-sm font-semibold text-ink-900">{title}</p>
                                </div>
                            ) : (
                                <>
                                    <h1 className="text-base font-black text-[#121316] tracking-tight truncate">{title}</h1>
                                    {subtitle && <div className="text-[11px] text-slate-500 font-medium flex items-center gap-2 mt-0.5 truncate">{subtitle}</div>}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Topbar Actions & User Avatar */}
                    <div className="flex items-center gap-2.5 shrink-0">
                        {actions}
                        <NotificationLink />

                        {/* Merchant / Admin User Avatar Interactive Dropdown */}
                        <div 
                            className="relative"
                            onPointerEnter={event => { if (isMousePointer(event)) handleUserMenuEnter(); }}
                            onPointerLeave={event => { if (isMousePointer(event)) handleUserMenuLeave(); }}
                        >
                            <Link
                                href={role === 'seller' ? route('seller.profile') : route('profile.edit')}
                                onClick={event => openMenuOnFirstTap(event, userMenuOpen, setUserMenuOpen)}
                                aria-haspopup="menu" aria-expanded={userMenuOpen}
                                className="flex items-center gap-2.5 px-2 py-1.5 rounded-xl hover:bg-zinc-100/80 transition group focus:outline-hidden border border-transparent hover:border-zinc-200/80"
                            >
                                {user?.avatar ? (
                                    <img
                                        src={user.avatar}
                                        alt={user.name}
                                        className="w-8 h-8 rounded-lg object-cover border border-zinc-200 shrink-0"
                                    />
                                ) : (
                                    <div className={`w-8 h-8 bg-[#E00D42] text-white font-bold text-xs flex items-center justify-center group-hover:bg-[#C20836] transition font-sans ${lx ? 'rounded-full' : 'rounded-lg shadow-xs'}`}>
                                        {user?.name.charAt(0).toUpperCase()}
                                    </div>
                                )}
                                <div className="hidden sm:block text-left font-sans">
                                    <div className="flex items-center gap-1">
                                        <p className="text-xs font-semibold text-[#121316] leading-tight group-hover:text-[#E00D42] transition">{user?.name}</p>
                                        <ChevronDown className={`w-3 h-3 text-zinc-400 transition-transform ${userMenuOpen ? 'rotate-180' : ''}`} />
                                    </div>
                                    <span className="text-[10px] text-zinc-500 font-medium">
                                        {role === 'seller' ? 'Verified Merchant' : role === 'admin' ? 'Super Admin' : role === 'logistics' ? (canSwitchHubs ? 'Company admin' : 'Hub operator') : 'Authorized User'}
                                    </span>
                                </div>
                            </Link>

                            {/* Dropdown Menu */}
                            {userMenuOpen && (
                                <div 
                                    className="absolute right-0 top-full -mt-0.5 pt-1 w-56 z-50 animate-scale-in"
                                    onPointerEnter={event => { if (isMousePointer(event)) handleUserMenuEnter(); }}
                                    onPointerLeave={event => { if (isMousePointer(event)) handleUserMenuLeave(); }}
                                >
                                    <div className="bg-white rounded-xl shadow-xl border border-zinc-200/90 py-1.5 text-zinc-800 font-sans">
                                        <div className="px-4 py-2.5 border-b border-zinc-100 font-sans text-xs flex items-center gap-2.5">
                                            {user?.avatar ? (
                                                <img
                                                    src={user.avatar}
                                                    alt={user.name}
                                                    className="w-8 h-8 rounded-lg object-cover border border-zinc-200 shrink-0"
                                                />
                                            ) : (
                                                <div className="w-8 h-8 rounded-lg bg-[#E00D42] text-white font-bold text-xs flex items-center justify-center font-sans shrink-0">
                                                    {user?.name.charAt(0).toUpperCase()}
                                                </div>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold text-[#121316] truncate">{user?.name}</p>
                                                <p className="text-[10px] text-zinc-500 truncate">{user?.email}</p>
                                            </div>
                                        </div>

                                        <Link href={route('account.status')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition">
                                            <ShieldCheck className="w-4 h-4 text-slate-400" /><span>Account status</span>
                                        </Link>

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
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <LayoutDashboard className="w-4 h-4 text-slate-400" />
                                                    <span>Hub Overview</span>
                                                </Link>
                                                <Link
                                                    href={route('hub.scan.station')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <ScanLine className="w-4 h-4 text-slate-400" />
                                                    <span>Floor Scanner (PWA)</span>
                                                </Link>
                                                <Link
                                                    href={route('hub.network')}
                                                    className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 hover:text-[#E00D42] transition"
                                                >
                                                    <Building2 className="w-4 h-4 text-slate-400" />
                                                    <span>Facility Network</span>
                                                </Link>
                                                <Link href={route('hub.mother-bags.index')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-[#E00D42]"><Package className="h-4 w-4" /><span>Mother Bags</span></Link>
                                                <Link href={route('hub.manifests.index')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 transition hover:bg-slate-50 hover:text-[#E00D42]">
                                                    <Truck className="h-4 w-4" />
                                                    <span>Manifests</span>
                                                </Link>
                                                <Link href={route('hub.recovery.index')} className="flex items-center gap-2.5 px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50">Delivery recovery</Link>
                                            </>
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
                    <div className={`py-2.5 px-6 font-sans flex items-center gap-2 shrink-0 ${lx ? 'bg-success-soft text-success border-b border-success/20 text-sm font-medium' : 'bg-emerald-600 text-white text-xs font-bold shadow-xs'}`}>
                        <CheckCircle2 className="w-4 h-4" />
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className={`py-2.5 px-6 font-sans shrink-0 ${lx ? 'bg-brand-50 text-brand-700 border-b border-brand-100 text-sm font-medium' : 'bg-[#E00D42] text-white text-xs font-bold shadow-xs'}`}>
                        {flash.error}
                    </div>
                )}

                {/* Body Content */}
                <main className={`flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8 ${lx ? 'bg-surface-50' : 'bg-[#F8FAFC]'}`} id="portal-main">
                    <div className="max-w-7xl mx-auto space-y-6">
                        {adminArea && <AdminSectionTabs label={adminArea.label} tabs={adminArea.tabs} />}
                        {sharedHubHeading && <HubPageHeader title={title} description={subtitle} icon={hubPageIcon} />}
                        {children}
                    </div>
                </main>
            </div>

        </div>
    );
}
